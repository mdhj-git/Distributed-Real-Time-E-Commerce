# المتطلب الخامس: التوسع وتوزيع الأحمال

الهدف (من الشريحة 3): **تشغيل النظام على أكثر من نسخة في نفس الوقت، دون أن يلاحظ المستخدم أي فرق.**

الشكل النهائي: **موزّع حمل واحد (nginx)، وثلاث نسخ من التطبيق (`app`)، وثلاثة عمّال (`worker`) على نفس الطابور، وخدمات مشتركة هي MySQL وRedis وRabbitMQ وReverb وخدمة المخزون.**

```text
                        ┌────────── app-1 ──────────┐
 المتصفح :8080 ──nginx──┼────────── app-2 ──────────┼──▶ MySQL / Redis / RabbitMQ / Reverb / inventory
                        └────────── app-3 ──────────┘
                                       │
                          RabbitMQ "orders" ──▶ worker-1 / worker-2 / worker-3
```

---

## 1) ما كان يعمل على نسخة واحدة وتعطّل على ثلاث

| # | المشكلة قبل الإصلاح | لماذا تظهر مع ٣ نسخ | الإصلاح | الملفات |
|---|---|---|---|---|
| 1 | `php artisan migrate` و`composer install` و`key:generate` كانا داخل `entrypoint.sh` لكل حاوية | ثلاث حاويات تنفّذ نفس المهاجرة في نفس اللحظة → سباق على جدول `migrations` وإنشاء الجداول | خدمة **`migrate`** واحدة تُنفّذ الإعداد مرة، وكل النسخ تنتظر `service_completed_successfully` ثم تبدأ بـ `RUN_SETUP=0`. خدمة `migrate` تستخدم صورة `app` نفسها | `docker-compose.yml`, `docker/app/entrypoint.sh` |
| 2 | الصور تُحفظ في `storage/app/public` داخل قرص الحاوية | صورة تُرفع عبر النسخة ١ لا تظهر عند طلب النسخة ٢ | حجم مشترك **`uploads_data`** مُركَّب في النسخ الثلاث وفي nginx (الذي يخدم `/storage`) | `docker-compose.yml` |
| 3 | nginx كان يمرّر إلى `app` واحداً ولا يعيد المحاولة | توقف نسخة = 502 لكل الطلبات التي تصلها | توزيع على كل عناوين `app` + `fastcgi_next_upstream` + `error_page 502 = @php_retry` + ترويسة `X-Served-By` (شرح التفاصيل أدناه) | `docker/nginx/default.conf` |
| 4 | تسليم الرسائل من RabbitMQ "مرة واحدة على الأقل" | ثلاثة عمّال يزيدون احتمال وصول نسختين من نفس الرسالة → بريد تأكيد مكرّر | قفل ذري في Redis عبر `Cache::add` بمفتاح عمل ثابت (`order.{id}.placed`) يُحرَّر عند الفشل، ويُبقى ٢٤ ساعة بعد النجاح | `SendOrderEmailJob.php`, المستمعان |

### تفاصيل موزّع الحمل (`docker/nginx/default.conf`)

- **استراتيجية التوزيع: الأقل انشغالاً (`least_conn;`)** — كل طلب يذهب إلى النسخة التي لديها أقل عدد من الاتصالات النشطة، بدل الدوران الافتراضي (Round Robin).
- `upstream app_pool` فيه `zone app_pool 64k;` لأن إصدار nginx الحالي يطلب shared-memory zone عند استخدام `resolve`.
- ثلاثة أسطر `server app:9000 resolve max_fails=0;`؛ Docker DNS يعيد الأعمدة بالدوران، فتكرار الاسم يجعل nginx يجمع عناوين النسخ الثلاث حتى لو كانت إجابة DNS لحظياً ناقصة.
- `fastcgi_next_upstream error timeout http_500 http_503` يعيد المحاولة على نسخة أخرى عند فشل الاتصال.
- `error_page 502 = @php_retry` يمنح الطلب محاولة إضافية خلال اللحظة التي يعيد فيها Docker DNS تحديث سجلاته بعد إيقاف حاوية؛ بهذا لا يصل 502 إلى المتصفح.
- `add_header X-Served-By $upstream_addr always;` ترويسة للعرض فقط، تُظهر عنوان النسخة التي خدمت الطلب.

### ما لم يحتج إصلاحاً (وماذا نقوله للمراجع)

- **الكاش وتحديد المعدل والـ JWT blacklist:** `CACHE_STORE=redis` أصلاً → الحالة مشتركة بين النسخ تلقائياً.
- **الجلسات:** التطبيق API بـ JWT بلا حالة، والواجهة تحفظ الرمز في `sessionStorage` لكل لسان → لا حالة على القرص.
- **قاعدة البيانات:** MySQL واحدة مشتركة (ونسخة القراءة في المتطلب ٣ تعمل كما هي).
- **البث اللحظي:** `ShouldBroadcastNow` يرسل من أي نسخة إلى Reverb عبر اسم الخدمة `reverb`، والعميل يصل إليه عبر nginx على المنفذ 8080 → لا يعتمد على النسخة التي استقبلت الطلب.

---

## 2) التشغيل

```bash
# أول مرة / بعد تغيير Dockerfile
docker compose up -d --build

# محلياً إذا كانت الصور مبنية أصلاً (يتجنب إعادة سحب صورة gRPC البطيئة)
docker compose up -d --no-build

docker compose ps
```

المتوقع: `migrate` انتهى (`Exited (0)`)، وثلاث حاويات `app-1/2/3` وثلاث حاويات `worker-1/2/3` كلها `Up`، و`nginx` يعمل.

النتيجة المحلية الفعلية بعد التشغيل:

```text
distributed-real-time-e-commerce-app-1   Up (healthy)
distributed-real-time-e-commerce-app-2   Up (healthy)
distributed-real-time-e-commerce-app-3   Up (healthy)
distributed-real-time-e-commerce-worker-1 Up
distributed-real-time-e-commerce-worker-2 Up
distributed-real-time-e-commerce-worker-3 Up
migrate exit code: 0
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

---

## 3) الإثبات ١: الطلبات تتوزع على النسخ الثلاث

```bash
for i in $(seq 1 30); do
  curl -sI http://localhost:8080/api/v1/health | grep -i '^x-served-by'
done | sort | uniq -c
```

النتيجة المحلية الفعلية مع `least_conn` (٣ عناوين مختلفة = ٣ نسخ):

```text
     10 X-Served-By: 172.18.0.9:9000
     10 X-Served-By: 172.18.0.12:9000
     10 X-Served-By: 172.18.0.14:9000
```

وتحت ضغط ٥٠٠ طلب / ٥٠ متزامناً توزّعت حسب الاتصالات النشطة:

```text
    181 X-Served-By: 172.18.0.9:9000
    152 X-Served-By: 172.18.0.12:9000
    167 X-Served-By: 172.18.0.14:9000
```

كل رموز الحالة كانت `200` (٥٠٠ طلب → `500 200`).

## 4) الإثبات ٢: الاستمرارية عند توقف نسخة

`docker compose stop app-2` لا يعمل لأن `app-2` اسم حاوية وليس اسم خدمة؛ استخدم اسم الحاوية أو معرّفها:

```bash
ID=$(docker compose ps -q app | head -1)
docker stop "$ID"
for i in $(seq 1 20); do curl -s -o /dev/null -w '%{http_code} ' http://localhost:8080/api/v1/health; done; echo
docker start "$ID"
```

النتيجة المحلية الفعلية عند إيقاف `app-1` ثم `app-3` (20 طلباً لكل منهما): **كلها 200، ولا 502 واحدة**.

## 5) الإثبات ٣: ثلاثة عمّال على طابور واحد بلا بريد مكرّر

```bash
docker compose ps worker          # worker-1 / worker-2 / worker-3

UID=$(docker compose exec -T app php artisan tinker --execute='echo Modules\Users\Models\User::query()->where("email", "demo.customer.a@shop.test")->value("id");')
docker compose exec -T app php artisan tinker --execute="Illuminate\Support\Facades\Cache::forget('order-email:order.9999.placed'); Modules\Orders\Contracts\Events\OrderPlaced::dispatch(9999, $UID); Modules\Orders\Contracts\Events\OrderPlaced::dispatch(9999, $UID);"
sleep 5
for c in $(docker compose ps -q worker); do docker exec "$c" sh -c 'grep "Order #9999" /var/www/storage/logs/laravel.log 2>/dev/null | tail -5'; done
```

النتيجة المحلية الفعلية (السجل مشترك بين العمّال لأن المشروع مُركَّب من المضيف):

```text
local.INFO: Duplicate order email skipped. {"user_id":5,"subject":"Order #9999 received"}
local.INFO: Email sent.                    {"user_id":5,"subject":"Order #9999 received"}
```

أي أن الرسالة المكرّرة التي التقطها عامل آخر لم تُرسل ثانية. والاختبار الآلي:

```bash
docker compose exec -T app php artisan test --filter=OrderEmailIdempotencyTest
# Tests: 2 passed
```

---

## 6) الحدود المعروفة

- `deploy.replicas` ثابت في ملف Compose؛ لتغيير العدد: `docker compose up -d --scale app=5 --scale worker=5` (أو عدّل `replicas` في الملف).
- nginx يستخدم فحوصات سلبية + محاولة إضافية عند 502. لا يوجد active health check في nginx مفتوح المصدر.
- صندوق Mailpit/Gmail من المتطلب ٤ يعمل كما هو؛ الإشعارات تُرسل من أي عامل، والمستلم دائماً بريد صاحب الطلب.
