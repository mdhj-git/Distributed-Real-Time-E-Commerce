# التحديثات اللحظية وواجهة الويب (المتطلب الرابع)

يشرح هذا الملف كيف تُعرض الإثباتات الثلاثة للمتطلب الرابع من المتصفح فقط: لا رسائل مفقودة، وتتبّع الطلب لحظياً، ولوحة المسؤول اللحظية، ثم البريد الحقيقي. الطوابير والبريد في [r4-queues-and-worker.md](r4-queues-and-worker.md).

## التشغيل أول مرة

بعد سحب هذا الفرع (الصورة تحتاج الامتدادين `sockets` و`pcntl`):

```bash
docker compose build app worker reverb
docker compose up -d
docker compose restart nginx
```

- `.env` القديم يلزمه التحديث من `.env.example`: `QUEUE_CONNECTION=rabbitmq` ومتغيرات `RABBITMQ_*` و`BROADCAST_CONNECTION=reverb` ومتغيرات `REVERB_*`.
- المتصفح يستخدم `http://localhost:8080` فقط. خادم Reverb داخل Docker ولا منفذ له، وnginx يمرّر المسار `/app` إليه.
- الحسابات التجريبية (`demo.admin@shop.test` و`demo.customer.a@shop.test` و`demo.customer.b@shop.test`) وكلمة مرورها في قسم "الحسابات التجريبية" من README.
- الرمز يُحفظ في `sessionStorage`: افتح المسؤول في لسان والعميل في لسان آخر من المتصفح نفسه.

## الصفحات

- `/` الرئيسية وحالة الـ API، `/register` و`/login` و`/account`.
- `/products` (تصفية بالفئة وبحث وترقيم) و`/products/{id}`.
- العميل: `/orders/new` و`/orders` و`/orders/{id}` (تتبّع لحظي).
- المسؤول: `/admin/orders` (لوحة لحظية) و`/admin/products/new` و`/admin/products/{id}/edit` (تعديل وتعديل المخزون وإضافة صور وحذف).
- `/notifications` للجميع.

كل صفحة قشرة تجلب بياناتها من `/api/v1`. كل خطأ يظهر برمز الحالة و`error.code` و`error.message`.

## الإثبات 1: لا رسائل مفقودة

1. سجّل الدخول عميلاً وأنشئ طلباً من `/orders/new`. يعود الرد فوراً (201) والبريد يُرسل لاحقاً من الـ worker.
2. أوقف الـ worker وأنشئ طلبين:

```bash
docker compose stop worker
docker compose exec rabbitmq rabbitmqctl list_queues name messages
```

3. الطابور `orders` يعرض الرسائل المنتظرة. شغّل الـ worker فتُرسل كلها:

```bash
docker compose start worker
docker compose logs -f worker
```

4. الطابور الميت: في `.env` اجعل `NOTIFICATIONS_EMAIL_FAIL=true` ثم `docker compose restart worker` وأنشئ طلباً. تظهر ثلاث محاولات `FAIL` (بينها 5 ثوانٍ) ثم تنتقل الرسالة إلى `orders.failed`:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages
docker compose exec app php artisan queue:failed
```

أعد `NOTIFICATIONS_EMAIL_FAIL=false` و`docker compose restart worker` بعدها. الطابور `orders.failed` تعلنه خدمة `worker` تلقائياً.

## الإثبات 2: تتبّع الطلب لحظياً

1. لسان للعميل: أنشئ طلباً من `/orders/new`. تنتقل الصفحة إلى `/orders/{id}` وتظهر "Live updates: subscribed".
2. لسان للمسؤول: افتح `/admin/orders` واضغط `Set confirmed` على الطلب.
3. حالة الطلب في لسان العميل تتغير فوراً دون إعادة تحميل، ويُضاف سطر إلى "Live events".
4. من يملك الطلب وحده يتابعه: سجّل عميلاً آخر وافتح `/orders/{id}` نفسه فيظهر `HTTP 403 · FORBIDDEN` من الـ API ولا اشتراك. وبلا تسجيل دخول يظهر `HTTP 401`.
5. الإثبات لا يعتمد على الـ worker: يعمل والـ worker متوقف.

## الإثبات 3: لوحة المسؤول اللحظية

1. لسان للمسؤول مفتوح على `/admin/orders`.
2. من لسان آخر سجّل عميلاً وأنشئ طلباً. يظهر الطلب في أعلى لوحة المسؤول فوراً (يُبثّ `order.placed` ثم تجلب الصفحة التفاصيل من `/api/v1/orders/{id}`).
3. عميل يفتح `/admin/orders` يرى رفض الخادم للاشتراك: `HTTP 403 · FORBIDDEN`. بلا تسجيل دخول: `HTTP 401`.

## البريد الحقيقي

انظر قسم "البريد الحقيقي" في [r4-queues-and-worker.md](r4-queues-and-worker.md): `MAIL_MAILER=smtp` وقيم الخدمة في `.env` ثم `docker compose restart worker`، ثم أنشئ طلباً بحساب بريده حقيقي.

## إظهار رموز الأخطاء من الواجهة

- 401: افتح `/orders` أو `/account` دون تسجيل دخول، أو أدخل كلمة مرور خاطئة.
- 403: عميل يفتح `/admin/products/new` ويرسل النموذج، أو يفتح طلب عميل آخر.
- 409: اطلب كمية أكبر من المخزون، أو أدخل تعديل مخزون سالباً أكبر من الموجود (مسؤول).
- 422: سجّل حساباً بكلمة مرور قصيرة، أو أرسل نموذج منتج فارغاً.
- 429: أخطئ في كلمة المرور أكثر من 5 مرات في الدقيقة، فيظهر `Retry-After`.
- 503: `docker compose stop inventory` ثم أنشئ طلباً (وتظهر قيمة المخزون "inventory unavailable")، ثم `docker compose start inventory`.

## ملاحظات

- المحدِّد العام `api` (60 طلباً في الدقيقة لكل IP) يشمل كل صفحة والتفويض اللحظي. الاستخدام العادي لا يقترب منه.
- مع نسخة القراءة قد يعود `GET /orders/{id}` بـ 404 لحظة بعد إنشاء الطلب من عميل آخر. لوحة المسؤول تعيد المحاولة بضع مرات قصيرة قبل أن تعرض الخطأ.
- `docker compose logs reverb` يعرض رسائل Reverb (الخدمة تعمل بالخيار `--debug`).
