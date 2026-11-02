# قواعد المشروع

يقرأ كل عضو هذا الملف قبل أن يبدأ فرعه. القواعد ملزمة بأي أداة كُتب بها الكود.

## 1. حدود الوحدات

الوحدات: `Shared` و`Users` و`Products` و`Orders` و`Notifications`.

### البنية الداخلية

```
Modules/Orders/
  Contracts/            الواجهات العامة الوحيدة المسموح للوحدات الأخرى باستخدامها
    DTOs/
    Events/
  Http/Controllers/     رقيقة، تستقبل الطلب فقط
  Http/Requests/        التحقق من المدخلات
  Http/Resources/       تشكيل المخرجات
  Services/             منطق الأعمال
  Models/
  Policies/
  Listeners/
  Database/Migrations/
  Database/Seeders/
  Providers/
  routes/api.php
  composer.json
  module.json
```

### القواعد

- لا تستورد وحدة نموذجاً أو `Service` من وحدة أخرى أبداً. المسموح هو ما في `Contracts/` فقط.
- أي وحدة يجوز لها استخدام ما في `Shared`. و`Shared` لا يستورد شيئاً من أي وحدة أخرى.
- لا مفاتيح أجنبية ولا `JOIN` عبر حدود الوحدات. المفاتيح الأجنبية داخل الوحدة الواحدة مسموحة.
- جدول `orders` يخزّن `user_id` رقماً فقط.
- جدول `order_items` يخزّن `product_id` رقماً مع لقطة من اسم المنتج وسعره لحظة الطلب.
- ما تعيده الـ Contract كائنات `DTO` لا نماذج `Eloquent`.
- المستخدم الحالي يُؤخذ عبر `auth()` فقط، دون استيراد نموذج `User`.

### Contract أم Event

- `Contract`: حين يتوقف عملي على النتيجة، أو يجب أن يفشل عملي إن فشل الطرف الآخر. مثال: حجز المخزون.
- `Event`: حين أعلن أن شيئاً حدث ولا أحتاج نتيجة. مثال: إنشاء الإشعار.
- المستمعون متزامنون ما لم يُجعلوا `ShouldQueue`. الفرق هنا فصل المعرفة لا التوقيت.

### الملفات المجمّدة

لا تُعدَّل هذه الملفات في أي فرع ميزة:

- `composer.json`
- `composer.lock`
- `bootstrap/app.php`
- `config/auth.php`
- `database/seeders/DatabaseSeeder.php`
- وحدة `Shared` كاملة.

السبب: تعديلها من فروع متوازية يولّد تعارضات دمج. وأي تغيير ضروري عليها يكون في PR مستقل.

كل وحدة تسجّل مساراتها وسياساتها ومستمعيها في `ServiceProvider` الخاص بها.

### `composer.json` داخل الوحدة

- يحتوي `name` و`autoload` فقط.
- لا `require` ولا `require-dev`. السبب: الحزم كلها تُثبَّت في الجذر.

### المسارات

- البادئة `api/v1` ومجموعة `api` مضبوطتان في `Providers/RouteServiceProvider.php` لكل وحدة.
- لا تكتب `prefix('v1')` في `routes/api.php`، وإلا صار المسار `api/v1/v1`.
- لا `Closure` في ملفات المسارات، بل `Controller`. السبب: يبقى `route:cache` ممكناً.

## 2. صيغة الاستجابة والأخطاء

### النجاح

استخدم `Modules\Shared\Helpers\ApiResponse`.

- عنصر واحد: `{ "data": ... }` بالرمز 200.
- إنشاء: 201 مع ترويسة `Location` اختيارية.
- قائمة مقسّمة: `{ "data": [...], "meta": {...} }`.
- `meta` أربعة حقول فقط: `current_page` و`per_page` و`total` و`last_page`.
- بلا محتوى: 204 بلا جسم.

### الخطأ

```json
{ "error": { "code": "...", "message": "...", "details": {}, "request_id": "..." } }
```

- `details` كائن `{}` حين لا تفاصيل، لا `null` ولا `[]`.
- لا `Stack Trace` في أي استجابة، حتى مع `APP_DEBUG=true`.
- رسائل الأخطاء بالإنجليزية.
- رسائل أخطاء Laravel والحزم ثابتة لكل `code`. السبب: رسالة 404 من ربط النموذج تحمل اسم الصنف.
- وحدهما `ApiException` و`ValidationException` تحتفظان برسالتيهما.

### جدول الأخطاء

- `ValidationException`: 422 `VALIDATION_FAILED`، و`details` فيه الحقول وأخطاؤها.
- `AuthenticationException` و`JWTException`: 401 `UNAUTHENTICATED`.
- `AuthorizationException` و`AccessDeniedHttpException`: 403 `FORBIDDEN`.
- `ModelNotFoundException` و`NotFoundHttpException`: 404 `NOT_FOUND`.
- `MethodNotAllowedHttpException`: 405 `METHOD_NOT_ALLOWED`.
- `PostTooLargeException`: 413 `PAYLOAD_TOO_LARGE`.
- `ThrottleRequestsException`: 429 `TOO_MANY_ATTEMPTS` مع ترويسة `Retry-After`.
- مخزون غير كافٍ، أو تعديل يجعل المخزون سالباً: 409 `INSUFFICIENT_STOCK`.
- انتقال حالة غير صالح: 422 `INVALID_STATUS_TRANSITION`.
- أي `HttpException` أخرى: رمزها الأصلي و`HTTP_ERROR`، والرسالة النص القياسي للرمز.
- `SecretMissingException`: 500 `SERVER_ERROR`، لأنها خطأ إعداد لا خطأ مصادقة.
- أي استثناء آخر: 500 `SERVER_ERROR` برسالة عامة، والتفاصيل في السجل فقط.

### استثناءات الأعمال

- الوحدات لا تبني استجابات خطأ يدوياً.
- كل استثناء أعمال يرث `Modules\Shared\Exceptions\ApiException`.
- `ApiException` تحمل `status` و`errorCode` و`details`. السبب: `Exception::$code` محجوز بنوع `int`.
- بذلك لا يحتاج المعالج المركزي إلى معرفة أي وحدة.
- الاستثناء الذي يعبر حدود وحدة مكانه `Contracts/`، مثل `InsufficientStockException`.

### التشخيص

500 برمز `SERVER_ERROR` من مسارات المصادقة أو المسارات الخاضعة للمحدِّد قبل تشغيل `jwt:secret` يعني غياب `JWT_SECRET`. والسبب يظهر في `storage/logs/laravel.log` مع `request_id`.

## 3. منطق الأعمال الحساس

- السعر والإجمالي يُحسبان في الخادم من بيانات المنتج حصراً. العميل لا يرسلهما.
- عناصر الطلب تُجمع كمياتها ثم تُرتَّب تصاعدياً حسب `product_id` قبل الحجز. السبب: منع الجمود.
- حجز المخزون تحديث شرطي مع فحص عدد الصفوف المتأثرة:

```sql
UPDATE stock_levels SET stock = stock - ? WHERE product_id = ? AND stock >= ?
```

- تعديل المخزون تحديث شرطي بالفرق:

```sql
UPDATE stock_levels SET stock = stock + ? WHERE product_id = ? AND stock + ? >= 0
```

- `InventoryService` يُستدعى داخل `Transaction` المستدعي، ويعتمد عليها للتراجع الكامل.
- تغيير حالة الطلب داخل `Transaction` مع `lockForUpdate()`. السبب: منع تحرير المخزون مرتين.

## 4. تحذيرات الانحدار

- رفع الملفات بطلبات `POST` فقط. طلبات multipart عبر `PUT` أو `PATCH` تفشل بصمت.
- حوّل حقول `Decimal` إلى رقم في الـ `Resource`.
- احسب المال بالسنتات عبر `Modules\Shared\Helpers\Money`، ولا تستخدم `float`. السبب: `(int)(19.99 * 100)` يعطي `1998`.
- عالج `NotFoundHttpException` مع `ModelNotFoundException`، لأن Laravel يحوّل الثانية إلى الأولى.
- أبقِ `.gitattributes` كما هو. بدونه تنكسر سكربتات shell داخل Linux بسبب CRLF.
- لا تضع `role` في `$fillable`.
- لا تخزّن أي حالة على قرص الحاوية أو في ذاكرتها خارج المخزن المشترك وقاعدة البيانات.
- لا تصل إلى الملفات إلا عبر `Storage` باسم `Disk` مأخوذ من الإعداد.
- لا تطلق أي حدث من داخل `Transaction` قبل نجاحها. الحدثان ينفّذان `ShouldDispatchAfterCommit`.
- لا `Stack Trace` في أي استجابة.
- صلاحية `0666` على سجل القناة `single` خاصة ببيئة التطوير في Docker، ولا تُنسخ إلى الإنتاج.

## 5. Redis

- التعامل معه عبر واجهات Laravel فقط: `Cache` و`RateLimiter`.
- يُمنع `Redis::` مباشرة، و`Cache::tags()` ما لم يُقرّ الفريق ذلك.

## 6. إصدار الواجهة

- التغيير الذي يكسر شكل استجابة قائمة يستلزم `/api/v2`.
- الإضافات، مثل حقل جديد، تجوز في `v1`.

## 7. إنشاء الوحدات والملفات

- كل ملف له مولّد يُنشأ بأوامر `module:make-*` لا يدوياً.
- مثال: `docker compose exec app php artisan module:make-model Product Products`.
- استثناء: ما لا مولّد له (الـ `DTOs` والاستثناءات) يُنشأ يدوياً داخل المسار المعتمد.
- مسارات المولّدات مضبوطة في `config/modules.php`. لا تنقل الملفات بعد توليدها.
- `seeder` الوحدة الرئيسي اسمه إلزامياً: `Modules\{Name}\Database\Seeders\{Name}DatabaseSeeder`.
- `DatabaseSeeder` يستدعيه تلقائياً إن وُجد، بالترتيب: Users ثم Products ثم Orders ثم Notifications.
- `db:seed` لا يعمل تلقائياً في أي مكان.

## 8. البيانات التجريبية

- حسابات الاختبار ببادئة مميزة: `demo.admin@shop.test` و`demo.customer.a@shop.test` و`demo.customer.b@shop.test`.
- الـ `Seeders` آمنة للتشغيل أكثر من مرة دون تكرار السجلات، باستخدام `updateOrCreate`.

## 9. قرارات متعمَّدة

- طلب مورد يملكه غيرك يعيد 403 لا 404. السبب: إثبات أن الـ `Policy` هي التي رفضت.
- لا مفاتيح أجنبية عابرة للوحدات. السبب: استقلال الوحدات.
- المخزون في جدول منفصل `stock_levels`. السبب: يفصله عن بيانات المنتج.
- العميل لا يلغي طلبه. السبب: تغيير الحالة للمسؤول وحده حسب نص المشروع.
- تعديل المخزون بالفرق لا بالقيمة المطلقة. السبب: القيمة المطلقة قد تمحو حجزاً متزامناً.
- حذف المنتجات منطقي. السبب: تبقى الصور ومعنى الطلبات القديمة.
- جدول الإشعارات `user_notifications`. السبب: الاسم `notifications` يخص نظام Laravel المدمج.
- الـ middleware العامة بدل ربطها بمجموعة `api`. السبب: 404 و405 لا يمران بـ middleware المجموعة.
- الترتيب `RequestId` ثم `ForceJsonResponse` ثم `SecurityHeaders`، قبل كل middleware افتراضية.
- `ForceJsonResponse` يضبط `Accept`. السبب: بدونه يتحول 401 إلى 500 بسبب مسار `login` غير الموجود.
- المحدِّد العام في `Shared`، 60 طلباً في الدقيقة، ومفتاحه عنوان IP فقط.
- السبب: ربطه بالمستخدم يربط كل مسار بالـ Guard والسر، ويحلّل الرمز مرتين.
- `health` مستثناة منه، لأن موزّع الأحمال سيستدعيها باستمرار.
- سطر `info` لكل طلب `api/*`. السبب: يثبت ظهور المعرّف في السجل، لأن Laravel لا يسجّل 404 افتراضياً.
- نموذج `User` أدنى في الأساس، و`defaults.guard` هو `api`.

## 10. قواعد العمل

- لا يُضاف نطاق لم يُطلب.
- لا تُكتب اختبارات في المراحل من 0أ إلى 4 إلا بقرار الفريق.
- لا يُعدَّل كود وحدة يملكها زميل. أي خلل يُسجَّل تعليقاً أو Issue.
