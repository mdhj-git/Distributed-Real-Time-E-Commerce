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
| 1 | `php artisan migrate` و`composer install` و`key:generate` كانا داخل `entrypoint.sh` لكل حاوية | ثلاث حاويات تنفّذ نفس المهاجرة في نفس اللحظة → سباق على جدول `migrations` وإنشاء الجداول | خدمة **`migrate`** واحدة تُنفّذ الإعداد مرة، وكل النسخ تنتظر `service_completed_successfully` ثم تبدأ بـ `RUN_SETUP=0` | `docker-compose.yml`, `docker/app/entrypoint.sh` |
| 2 | الصور تُحفظ في `storage/app/public` داخل قرص الحاوية | صورة تُرفع عبر النسخة ١ لا تظهر عند طلب النسخة ٢ | حجم مشترك **`uploads_data`** مُركَّب في النسخ الثلاث وفي nginx (الذي يخدم `/storage`) | `docker-compose.yml` |
| 3 | nginx كان يمرّر إلى `app` واحداً ولا يعيد المحاولة | توقف نسخة = 502 لكل الطلبات التي تصلها | توزيع على كل عناوين `app` + `fastcgi_next_upstream` + `max_fails/fail_timeout` + ترويسة `X-Served-By` | `docker/nginx/default.conf` |
| 4 | تسليم الرسائل من RabbitMQ "مرة واحدة على الأقل" | ثلاثة عمّال يزيدون احتمال وصول نسختين من نفس الرسالة → بريد تأكيد مكرّر | قفل ذري في Redis عبر `Cache::add` بمفتاح عمل ثابت (`order.{id}.placed`) يُحرَّر عند الفشل، ويُبقى ٢٤ ساعة بعد النجاح | `SendOrderEmailJob.php`, المستمعان |

### ما لم يحتج إصلاحاً (وماذا نقوله للمراجع)

- **الكاش وتحديد المعدل والـ JWT blacklist:** `CACHE_STORE=redis` أصلاً → الحالة مشتركة بين النسخ تلقائياً.
- **الجلسات:** التطبيق API بـ JWT بلا حالة، والواجهة تحفظ الرمز في `sessionStorage` لكل لسان → لا حالة على القرص.
- **قاعدة البيانات:** MySQL واحدة مشتركة (ونسخة القراءة في المتطلب ٣ تعمل كما هي).
- **البث اللحظي:** `ShouldBroadcastNow` يرسل من أي نسخة إلى Reverb عبر اسم الخدمة `reverb`، والعميل يصل إليه عبر nginx على المنفذ 8080 → لا يعتمد على النسخة التي استقبلت الطلب.

---

## 2) التشغيل

```bash
docker compose up -d --build
docker compose ps
```

المتوقع: `migrate` انتهى (`Exited (0)`)، وثلاث حاويات `app-1/2/3` وثلاث حاويات `worker-1/2/3` كلها `Up (healthy)`، و`nginx` يعمل.

---

## 3) الإثبات ١: الطلبات تتوزع على النسخ الثلاث

```bash
for i in $(seq 1 15); do
  curl -sI http://localhost:8080/api/v1/health | grep -i '^x-served-by'
done | sort | uniq -c
```

تظهر ثلاثة عناوين مختلفة (كل عنوان = نسخة). الترويسة `X-Served-By` أُضيفت لهذا العرض فقط.

## 4) الإثبات ٢: الاستمرارية عند توقف نسخة

```bash
docker compose stop app-2
for i in $(seq 1 10); do curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/api/v1/health; done
# كلها 200
docker compose start app-2
```

`nginx` يكتشف العنوان الميت عبر `max_fails=1 fail_timeout=5s` ويعيد المحاولة على نسخة أخرى عبر `fastcgi_next_upstream`، والمستخدم لا يرى 502.

## 5) الإثبات ٣: ثلاثة عمّال على طابور واحد بلا بريد مكرّر

```bash
docker compose ps worker          # worker-1 / worker-2 / worker-3
docker compose exec -T app php artisan tinker --execute='Modules\Orders\Contracts\Events\OrderPlaced::dispatch(5, 10);'
docker compose exec -T app php artisan tinker --execute='Modules\Orders\Contracts\Events\OrderPlaced::dispatch(5, 10);'
docker compose logs worker | grep -E 'Email sent|Duplicate order email skipped'
```

النتيجة: `Email sent.` مرة واحدة و`Duplicate order email skipped.` للرسالة المكررة. والاختبار الآلي:

```bash
docker compose exec -T app php artisan test --filter=OrderEmailIdempotencyTest
```

---

## 6) الحدود المعروفة

- `deploy.replicas` ثابت في ملف Compose؛ لتغيير العدد: `docker compose up -d --scale app=5 --scale worker=5` (أو عدّل `replicas` في الملف).
- nginx يستخدم فحوصات سلبية (بعد فشل فعلي). لا يوجد active health check في nginx مفتوح المصدر.
- صندوق Mailpit/Gmail من المتطلب ٤ يعمل كما هو؛ الإشعارات تُرسل من أي عامل، والمستلم دائماً بريد صاحب الطلب.
