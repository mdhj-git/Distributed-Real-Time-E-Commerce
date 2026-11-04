# سيناريو عرض المتطلب الثاني في المقابلة

> هذا الملف ينفّذ القسم 12 من خطة المتطلب الثاني حرفياً. يُشغَّل كاملاً على Docker بعد db:seed (مع خدمة المخزون شغّالة).

## تحضير مسبق (قبل يوم العرض بوقت كافٍ)

اتصال Docker Hub من بعض الأجهزة قد ينقطع أثناء العرض. لا تعتمد على السحب أثناء المقابلة:

docker compose build
docker compose pull inventory-db mysql redis
docker compose run --rm grpcurl -version

تحقق أن كل الصور محلية فعلاً:

docker images

يجب أن تظهر fullstorydev/grpcurl:v1.9.3 و mysql:8.4 ضمن القائمة قبل العرض بيوم على الأقل.

## الخطوات

### 1. كل الحاويات تعمل

docker compose ps

المتوقع: ست حاويات: app، nginx، mysql، redis، inventory، inventory-db.

### 2. قراءة الكمية مباشرة من الخدمة عبر grpcurl

docker compose run --rm grpcurl -plaintext -proto proto/inventory/v1/inventory.proto -d '{"product_ids": [1]}' inventory:50051 inventory.v1.Inventory/GetStock

المتوقع: الكمية مقروءة مباشرة من خدمة المخزون.

### 3. عرض منتج يُظهر حقل stock القادم من الخدمة

GET /api/v1/products/1

المتوقع: 200، والمنتج يحمل حقل stock من خدمة المخزون.

### 4. المسؤول يضبط الكمية على 1

PATCH /api/v1/products/1/stock
Authorization: Bearer ADMIN_TOKEN
{ "adjustment": القيمة اللازمة لتصبح الكمية 1 }

المتوقع: 200 والكمية 1.

### 5. عشرة طلبات متزامنة على نفس المنتج

docker compose exec app php artisan orders:race-test --product=1 --count=10

المتوقع: 201 واحدة وتسع 409 INSUFFICIENT_STOCK، والكمية النهائية 0 لا سالبة.

### 6. توقف خدمة المخزون — إنشاء طلب

docker compose stop inventory

POST /api/v1/orders
Authorization: Bearer CUSTOMER_TOKEN
{ "items": [{ "product_id": 1, "quantity": 1 }] }

المتوقع: 503 INVENTORY_UNAVAILABLE خلال المهلة المحددة.

### 7. عرض المنتج والخدمة متوقفة

GET /api/v1/products/1

المتوقع: 200 والمنتج مع stock: null. الصفحة لا تفشل.

### 8. الخدمة معلّقة (Pause) لا متوقفة

docker compose pause inventory

POST /api/v1/orders
{ "items": [{ "product_id": 1, "quantity": 1 }] }

المتوقع: 503 بعد انتهاء المهلة تماماً، لا تعليق بلا نهاية.

### 9. إعادة تشغيل الخدمة

docker compose unpause inventory
docker compose start inventory

انتظر بضع ثوانٍ (قناة gRPC بـ PHP تحتاج وقت إعادة اتصال)، ثم أرسل طلباً عادياً.

المتوقع: يعمل من جديد.

### 10. إلغاء طلب مرتين

PATCH /api/v1/orders/ID/status
{ "status": "cancelled" }

ثم كرر نفس الطلب.

المتوقع: الأول 200 والكمية عادت، الثاني 422 INVALID_STATUS_TRANSITION، والكمية لم تتضاعف.

### 11. تتبّع الطلب عبر سجلّات الخدمتين

docker compose logs app | grep REQUEST_ID
docker compose logs inventory | grep REQUEST_ID

المتوقع: نفس X-Request-Id يظهر بسجلّي app و inventory — الطلب الواحد متتبَّع عبر الخدمتين.

## لماذا لا يكفي Postman Collection Runner لإثبات الـ Race Condition

Collection Runner يرسل الطلبات متتالية لا متزامنة، فينجح الأول ويُرفض الباقي حتى لو كان الكود خاطئاً. الإثبات يصير بلا قيمة — لذلك الخطوة 5 تعتمد على أمر Artisan (orders:race-test) الذي يرسل الطلبات فعلياً بنفس اللحظة عبر Http::pool.