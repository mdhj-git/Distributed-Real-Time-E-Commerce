# خطة تنفيذ المتطلب الخامس: التوسع وتوزيع الأحمال

> ملف مُعدّ للمشاركة مع أعضاء الفريق. يشرح **ماذا نفّذنا، ولماذا، وبأي ترتيب، وكيف نتأكد أنه صحيح**.

---

## 1. الهدف (من شريحة العرض)

**تشغيل النظام على أكثر من نسخة في نفس الوقت، دون أن يلاحظ المستخدم أي فرق.**

الشكل النهائي:

```text
                        ┌────────── app-1 ──────────┐
 المتصفح :8080 ──nginx──┼────────── app-2 ──────────┼──▶ MySQL / Redis / RabbitMQ / Reverb / inventory
                        └────────── app-3 ──────────┘
                                       │
                          RabbitMQ "orders" ──▶ worker-1 / worker-2 / worker-3
```

---

## 2. النطاق

| البند | المطلوب | القرار |
|---|---|---|
| نسخ التطبيق | 3 نسخ من `app` | `deploy.replicas: 3` في `docker-compose.yml` |
| العمّال | 3 عمّال على نفس الطابور | `deploy.replicas: 3` لخدمة `worker` على `orders` |
| موزّع الحمل | nginx واحد على المنفذ 8080 | `upstream app_pool` + `resolve` + retry |
| استراتيجية التوزيع | الأقل انشغالًا (Least Connections) | `least_conn;` داخل الـ upstream |
| الحالة المشتركة | كاش/جلسات/معدل/بريد | Redis وMySQL مشتركان أصلاً |
| الملفات المرفوعة/السجلات | `storage/` | كل النسخ تربط مجلد المشروع نفسه، فتشاركها فعلياً |
| الإعداد | composer/migrate/key | خدمة `migrate` واحدة فقط |
| البريد | منع التكرار مع عدة عمّال | قفل Redis ذري في `SendOrderEmailJob` |

---

## 3. المتطلبات قبل البدء

- Docker + Docker Compose v2 (تم الاختبار على v5.7.0).
- ملف `.env` موجود وفيه `CACHE_STORE=redis`, `QUEUE_CONNECTION=rabbitmq`, `BROADCAST_CONNECTION=reverb`.
- نسخة احتياطية من `mysql_data` و`storage/` قبل أول تشغيل متعدد النسخ.
- معرفة أن خدمة `reverb` و`inventory` تعملان كما في المتطلب الرابع.

---

## 4. مراحل التنفيذ (بالترتيب)

### المرحلة 1 — تدقيق الحالة المحلية

| ما نفحصه | النتيجة | القرار |
|---|---|---|
| الكاش وتحديد المعدل و JWT blacklist | `CACHE_STORE=redis` | مشترك تلقائياً، لا تغيير |
| الجلسات | JWT + `sessionStorage` | بلا حالة على القرص، لا تغيير |
| قاعدة البيانات | MySQL واحدة + نسخة قراءة R3 | مشتركة، لا تغيير |
| البث اللحظي | `ShouldBroadcastNow` → Reverb | لا يعتمد على النسخة، لا تغيير |
| الصور والسجلات | `storage/app/public` و`storage/logs` | كل النسخ تربط مجلد المشروع نفسه → مشترك فعلياً |
| عنوان العميل الحقيقي | nginx يتحدث FastCGI ويمرّر `REMOTE_ADDR` | لا يحتاج `TrustProxies` |
| المهاجرات | داخل `entrypoint.sh` لكل حاوية | **يحتاج خدمة واحدة** |

> لو بُني الكود داخل الصورة (image) بدل ربط مجلد المشروع، فسيحتاج `storage/app/public` إلى تخزين مشترك (حجم مسمّى أو object storage). في إعدادنا الحالي الربط موجود، لذلك لا حجم إضافي.

### المرحلة 2 — منع سباق الإعداد

1. نقل `composer install` و`key:generate` و`migrate` و`storage:link` داخل شرط:

   ```sh
   if [ "${RUN_SETUP:-1}" = "1" ]; then ... fi
   ```

2. إضافة خدمة `migrate` one-shot في `docker-compose.yml` مع `RUN_SETUP=1` و`command: ["true"]`.
3. كل نسخ `app` تبدأ بـ `RUN_SETUP=0` وتنتظر:

   ```yaml
   depends_on:
     migrate:
       condition: service_completed_successfully
   ```

4. خدمة `migrate` تستخدم **صورة `app` نفسها** حتى لا نبني صورة منفصلة.

الملفات: `docker/app/entrypoint.sh`, `docker-compose.yml`.

### المرحلة 3 — تعدد النسخ والعمّال

```yaml
app:
  deploy:
    replicas: 3
worker:
  deploy:
    replicas: 3
```

- الثلاث عمّال يستهلكون نفس الطابور `orders`؛ كل رسالة يأخذها عامل واحد فقط.
- `worker` يعطّل entrypoint الافتراضي ويشغّل `queue:work rabbitmq --queue=orders --tries=3 --backoff=5`.

### المرحلة 4 — توزيع الحمل والـ failover في nginx

```nginx
resolver 127.0.0.11 valid=10s ipv6=off;

upstream app_pool {
    least_conn;
    zone app_pool 64k;
    server app:9000 resolve max_fails=0;
    server app:9000 resolve max_fails=0;
    server app:9000 resolve max_fails=0;
}

location ~ \.php$ {
    fastcgi_pass app_pool;
    fastcgi_next_upstream error timeout http_500 http_503;
    error_page 502 = @php_retry;
    add_header X-Served-By $upstream_addr always;
}
```

لماذا هكذا؟

- `least_conn`: الاستراتيجية المختارة؛ الطلب يذهب للنسخة الأقل اتصالات نشطة، وهو أفضل من الدوران الثابت عندما تختلف مدة الطلبات.
- `resolve`: Docker DNS يعطي عنواناً لكل نسخة، ويتحدّث عند إيقاف/إعادة نسخة.
- `zone`: إلزامي في إصدارات nginx الحالية عند استخدام `resolve`.
- تكرار `server` ثلاث مرات: يجمع عناوين النسخ حتى لو كانت إجابة DNS لحظياً ناقصة.
- `error_page 502 = @php_retry`: محاولة إضافية تخفي اللحظة التي يعيد فيها Docker DNS تحديث سجلاته.
- `X-Served-By`: للعرض فقط، بيان عنوان النسخة التي خدمت الطلب.

**ملاحظة:** `nginx` يعتمد على `app: condition: service_started` (ليس `service_healthy`) حتى يبقى يعمل ويعيد التوجيه لو توقفت نسخة.

### المرحلة 5 — مشاركة `storage/`

كل نسخة من `app` تركّب مجلد المشروع نفسه `.:/var/www`، لذلك `storage/app/public` و`storage/logs` مشتركان بين النسخ دون حجم إضافي. nginx يركّب نفس المجلد للقراءة ليخدم `/storage`.

### المرحلة 6 — منع البريد المكرّر

- المستمعون يمرّرون مفتاحاً ثابتاً:
  - `order.{id}.placed`
  - `order.{id}.status.{to}`
- `SendOrderEmailJob` يستخدم `Cache::add($lockKey, true, now()->addDay())`.
- عند الفشل: `Cache::forget($lockKey)` حتى يبقى الـ retry طبيعياً.
- اختبار آلي: `tests/Feature/Notifications/OrderEmailIdempotencyTest.php`.

### المرحلة 7 — التوثيق

- `docs/r5-scaling-report.md`: التقرير والإثباتات.
- `README.md`: قسم مختصر + أمر سريع.
- هذا الملف: خطة التنفيذ للفريق.

---

## 5. جدول الملفات

| الملف | التعديل |
|---|---|
| `docker-compose.yml` | `migrate`, `deploy.replicas`, تبعيات، إزالة تعليقات قديمة |
| `docker/app/entrypoint.sh` | شرط `RUN_SETUP`، صلاحية تنفيذ `755` |
| `docker/nginx/default.conf` | `least_conn`, `upstream`, `resolve`, `zone`, retry, `X-Served-By` |
| `Modules/Notifications/Jobs/SendOrderEmailJob.php` | قفل Redis ذري + تحرير عند الفشل |
| `Modules/Notifications/Listeners/DispatchOrderPlacedEmail.php` | مفتاح idempotency |
| `Modules/Notifications/Listeners/DispatchOrderStatusEmail.php` | مفتاح idempotency |
| `tests/Feature/Notifications/OrderEmailIdempotencyTest.php` | اختبار التكرار + إعادة المحاولة |
| `docs/r5-scaling-report.md` | تقرير الإثبات |
| `README.md` | قسم المتطلب الخامس |
| `config/mail.php` | Mailpit ضمن failover (إعداد بريد مساعد) |
| `Modules/Orders/Providers/EventServiceProvider.php` | تنظيف تعليقات فقط |
| `stubs/grpc.php` | IDE stubs لـ gRPC |

---

## 6. أوامر التنفيذ

```bash
cd /home/suliman/projects/Laravel/Distributed-Real-Time-E-Commerce

# أول مرة أو بعد تغيير Dockerfile
docker compose up -d --build

# محلياً إذا الصور مبنية أصلاً
docker compose up -d --no-build

docker compose ps app worker
docker compose exec -T nginx nginx -t
```

---

## 7. خطة الاختبار والقبول

| # | الإثبات | الأمر | النتيجة المقبولة |
|---|---|---|---|
| 1 | صحة الإعداد | `docker compose config --quiet` | بدون أخطاء |
| 2 | 3 نسخ/3 عمّال | `docker compose ps app worker` | 3 و3 `Up` |
| 3 | المهاجرة مرة واحدة | `docker inspect -f '{{.State.ExitCode}}' $(docker compose ps -aq migrate)` | `0` |
| 4 | التوزيع مع `least_conn` | `for i in $(seq 1 30); do curl -sI localhost:8080/api/v1/health \| grep -i x-served-by; done \| sort \| uniq -c` | 3 عناوين مختلفة |
| 5 | 500 طلب متزامن | `seq 1 500 \| xargs -P 50 -I{} curl -s -o /dev/null -w '%{http_code}\n' localhost:8080/api/v1/health \| sort \| uniq -c` | `500 200` |
| 6 | الاستمرارية | `ID=$(docker compose ps -q app \| head -1); docker stop $ID; ...; docker start $ID` | كل الطلبات 200، بدون 502 |
| 7 | منع البريد المكرّر | إرسال `OrderPlaced` مرتين لنفس order id | `Email sent.` مرة واحدة + `Duplicate order email skipped.` |
| 8 | الاختبارات | `docker compose exec -T app php artisan test` | 58 passed / 512 assertions |

---

## 8. ترتيب العمل بين الزملاء

1. **الشخص 1 — Compose:** يضيف `migrate` و`replicas` ويتحقق من `config --quiet`.
2. **الشخص 2 — nginx:** يضبط `least_conn`, `upstream`, `resolve`, `zone`, retry ويتحقق من `nginx -t` والتوزيع.
3. **الشخص 3 — الإشعارات:** يضيف idempotency والاختبار، ويعرض سجل العمّال.
4. **الجميع:** يجرّب الـ 500 طلب وإيقاف نسخة، ثم يوقع على الـ checklist أعلاه.

---

## 9. المخاطر ومعالجتها

| الخطر | المعالجة |
|---|---|
| سباق المهاجرات | خدمة `migrate` واحدة + `service_completed_successfully` |
| صورة/سجل غير مرئي من نسخة أخرى | مجلد المشروع نفسه مركّب في كل النسخ (`.:/var/www`) |
| 502 لحظي عند إيقاف نسخة | `fastcgi_next_upstream` + `error_page 502 = @php_retry` |
| بريد مكرّر من عدة عمّال | `Cache::add` ذري في Redis |
| إجابة DNS ناقصة | تكرار `server app:9000 resolve` ثلاث مرات + `zone` |
| بطء سحب صورة gRPC عند `--build` | استخدم `--no-build` محلياً إذا الصور موجودة |

---

## 10. معايير القبول (Checklist)

- [ ] `docker compose ps` يُظهر 3 `app` و3 `worker` و`migrate` بخروج `0`.
- [ ] `X-Served-By` يُظهر 3 عناوين مختلفة.
- [ ] 500 طلب → كلها 200.
- [ ] إيقاف نسخة أثناء الضغط → لا 502.
- [ ] حدث الطلب مرتين → بريد واحد فقط.
- [ ] 58 اختباراً ناجحاً.
- [ ] التقرير `docs/r5-scaling-report.md` مرفق بالـ PR.

---

## 11. الحالة الحالية لهذا المشروع

- التنفيذ الأساسي للمتطلب الخامس **تم دمجه في `main`** عبر PR #20.
- هذا الملف واستراتيجية `least_conn` يُضافان في PR جديد من `feat/r5-least-connections` إلى `main`.
- آخر تحقق محلي: 58 اختباراً ناجحاً، توزيع على 3 نسخ، 500 طلب كلها 200، failover بدون 502، واستراتيجية `least_conn` فعّالة.
