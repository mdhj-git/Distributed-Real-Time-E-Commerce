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
| 2 | nginx كان يمرّر إلى `app` واحداً ولا يعيد المحاولة | توقف نسخة = 502 لكل الطلبات التي تصلها | توزيع على كل عناوين `app` + `fastcgi_next_upstream` + `error_page 502 = @php_retry` + ترويسة `X-Served-By` (شرح التفاصيل أدناه) | `docker/nginx/default.conf` |
| 3 | تسليم الرسائل من RabbitMQ "مرة واحدة على الأقل" | ثلاثة عمّال يزيدون احتمال وصول نسختين من نفس الرسالة → بريد تأكيد مكرّر | قفل ذري في Redis عبر `Cache::add` بمفتاح عمل ثابت (`order.{id}.placed`) يُحرَّر عند الفشل، ويُبقى ٢٤ ساعة بعد النجاح | `SendOrderEmailJob.php`, المستمعان |

### تفاصيل موزّع الحمل (`docker/nginx/default.conf`)

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
- **الصور والسجلات:** تقع في `storage/` (`storage/app/public` و`storage/logs`)، وكل النسخ تشاركها لأنها تربط مجلد المشروع نفسه، فالصورة المرفوعة عبر نسخة واحدة تخدمها كل النسخ. وإن بُني الكود داخل الصورة (image) فسيحتاج هذا المجلد إلى تخزين مشترك (حجم مسمّى أو object storage).
- **عنوان العميل الحقيقي:** ليس مشكلة خلف nginx، لأن nginx يتحدث FastCGI (وليس قفزة HTTP proxy) ويمرّر `REMOTE_ADDR` بنفسه (`fastcgi_param REMOTE_ADDR $remote_addr;` في `fastcgi_params`، و`default.conf` يضمّنه بـ `include fastcgi_params;`). لذلك يرى محدِّدا المعدل `api` و`login` عنوان العميل، ولا يلزم تعديل `TrustProxies`.

### استراتيجية التوزيع

- نستخدم Round Robin الافتراضي في nginx (بلا `least_conn` وبلا `ip_hash` في `upstream app_pool`).
- السبب: النسخ متطابقة وبلا حالة محلية (كل الحالة في MySQL وRedis وRabbitMQ)، وكلفة الطلبات متقاربة.
- لا نستخدم التوجيه الثابت (IP Hash) لأنه يخفي أخطاء الحالة المحلية، ولأن إثباتنا يأتي من جهاز واحد (IP واحد) فسيرسل الطلبات الـ 500 كلها إلى نسخة واحدة.

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

## 3) الإثبات: ٥٠٠ طلب تتوزع على النسخ الثلاث، والنظام يعمل عند إيقاف نسخة

المسار `/api/v1/health` خارج محدِّد المعدل `api`. الإثبات (PowerShell 5.1): ٢٥٠ طلباً والنسخ الثلاث تعمل، ثم إيقاف نسخة `app` واحدة، ثم ٢٥٠ طلباً أخرى، ثم تشغيل النسخة من جديد. الجدول لكل نصف يعدّ الطلبات حسب قيمة `X-Served-By` وحسب حالة HTTP:

```powershell
$url = 'http://127.0.0.1:8080/api/v1/health'
function Get-HealthSample($n) {
    1..$n | ForEach-Object {
        $h = curl.exe -s -o NUL -D - $url
        [pscustomobject]@{
            Status = (($h | Select-Object -First 1) -replace '^HTTP/\S+\s+(\d+).*', '$1')
            Served = (($h | Where-Object { $_ -like 'X-Served-By:*' }) -replace '^X-Served-By:\s*', '')
        }
    }
}
$a = Get-HealthSample 250
$id = (docker compose ps -q app)[1]
docker stop $id
$b = Get-HealthSample 250
docker start $id
'--- first 250 (3 replicas) ---'
$a | Group-Object Served | Select-Object Count, Name
$a | Group-Object Status | Select-Object Count, Name
'--- second 250 (one replica stopped) ---'
$b | Group-Object Served | Select-Object Count, Name
$b | Group-Object Status | Select-Object Count, Name
```

النتيجة المحلية الفعلية:

```text
--- first 250 (3 replicas) ---

Count Name
----- ----
   84 172.18.0.8:9000
   83 172.18.0.9:9000
   83 172.18.0.12:9000

Count Name
----- ----
  250 200

--- second 250 (one replica stopped) ---

Count Name
----- ----
  125 172.18.0.9:9000
  124 172.18.0.8:9000
    1 172.18.0.12:9000, app_pool : 172.18.0.8:9000

Count Name
----- ----
  250 200
```

- النصف الأول: ٣ عناوين مختلفة (٣ نسخ) تتقاسم الطلبات تقريباً بالتساوي، وكلها `200`.
- النصف الثاني: بعد إيقاف النسخة `172.18.0.12` لم يصل أي طلب إليها، وبقيت كل الطلبات `200` ولا `502` واحدة. الطلب الواحد الذي ظهر فيه `172.18.0.12:9000, app_pool : 172.18.0.8:9000` حاول أولاً نسخة موقوفة ثم أعاد nginx المحاولة على نسخة سليمة فأجابت `200`.

## 4) منع البريد المكرّر مع ثلاثة عمّال على طابور واحد

تسليم RabbitMQ "مرة واحدة على الأقل"، فقد تصل الرسالة نفسها إلى عاملين. قفل ذري في Redis (`Cache::add` بالمفتاح `order.{id}.placed`) يجعل الرسالة المكرّرة تُتجاهل (`Duplicate order email skipped`) فلا يُرسل البريد ثانية، ويُحرَّر القفل إذا فشل الإرسال ليتمكن إعادة المحاولة من الإرسال. الاختبار الآلي:

```powershell
docker compose exec -T app php artisan test --filter=OrderEmailIdempotencyTest
```

النتيجة المحلية الفعلية: `Tests: 2 passed (5 assertions)`.

---

## 5) الحدود المعروفة

- `deploy.replicas` ثابت في ملف Compose؛ لتغيير العدد: `docker compose up -d --scale app=5 --scale worker=5` (أو عدّل `replicas` في الملف).
- nginx يستخدم فحوصات سلبية + محاولة إضافية عند 502. لا يوجد active health check في nginx مفتوح المصدر.
- صندوق Mailpit/Gmail من المتطلب ٤ يعمل كما هو؛ الإشعارات تُرسل من أي عامل، والمستلم دائماً بريد صاحب الطلب.
- `error_page 502 = @php_retry` يعيد إرسال الطلب إلى نسخة أخرى، ومنه طلب POST ربما نفّذته PHP فعلاً حين تموت النسخة في منتصف الطلب، فقد يُنشأ طلب شراء مرتين (نادر؛ غير معالَج، ولا تعديل في الكود).
