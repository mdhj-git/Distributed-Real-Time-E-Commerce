# سيناريو عرض المتطلب الثاني في المقابلة

> هذا الملف ينفّذ القسم 12 من خطة المتطلب الثاني حرفياً. يُشغَّل كاملاً على Docker بعد `db:seed` (مع خدمة المخزون شغّالة).

## تحضير مسبق (قبل يوم العرض بوقت كافٍ)

اتصال Docker Hub من بعض الأجهزة قد ينقطع أثناء العرض. **لا تعتمد على السحب أثناء المقابلة**:

```bash
docker compose pull --ignore-buildable
docker compose build
```

هذا يغطي كل الصور الجاهزة (بما فيها `nginx:alpine` و`redis:7-alpine` و`mysql:8.4`) بأمر واحد، ثم يبني الصور المحلية (`app`، `inventory`).

تحقق من `grpcurl` تحديداً:

```bash
docker compose run --rm grpcurl -version
```

تحقق أن كل الصور محلية فعلاً:

```bash
docker images
```

يجب أن تظهر `fullstorydev/grpcurl:v1.9.3` و`mysql:8.4` ضمن القائمة قبل العرض بيوم على الأقل.

## الخطوات

### 1. كل الحاويات تعمل

```bash
docker compose ps
```

**المتوقع:** ست حاويات: `app`، `nginx`، `mysql`، `redis`، `inventory`، `inventory-db`.

### 2. قراءة الكمية مباشرة من الخدمة عبر grpcurl

```bash
docker compose run --rm grpcurl -plaintext -proto proto/inventory/v1/inventory.proto \
  -d '{"product_ids": [1]}' inventory:50051 inventory.v1.Inventory/GetStock
```

**المتوقع:** الكمية مقروءة مباشرة من خدمة المخزون.

### 3. عرض منتج يُظهر حقل `stock` القادم من الخدمة

```http
GET /api/v1/products/1
```

**المتوقع:** 200، والمنتج يحمل حقل `stock` من خدمة المخزون.

### 4. المسؤول يضبط الكمية على 1

المخزون الأولي بعد `db:seed` الافتراضي هو **50**، فالفرق اللازم للوصول إلى 1 هو **-49**:

```http
PATCH /api/v1/products/1/stock
Authorization: Bearer {ADMIN_TOKEN}
```

```json
{ "adjustment": -49 }
```

**المتوقع:** 200 والكمية 1.

### 5. عشرة طلبات متزامنة على نفس المنتج

```bash
docker compose exec app php artisan orders:race-test --product=1 --count=10
```

**المتوقع:** 201 واحدة وتسع 409 `INSUFFICIENT_STOCK`، والكمية النهائية 0 لا سالبة.

### 6. توقف خدمة المخزون — إنشاء طلب

```bash
docker compose stop inventory
```

```http
POST /api/v1/orders
Authorization: Bearer {CUSTOMER_TOKEN}
```

```json
{ "items": [{ "product_id": 1, "quantity": 1 }] }
```

**المتوقع:** 503 `INVENTORY_UNAVAILABLE` خلال المهلة المحددة.

### 7. عرض المنتج والخدمة متوقفة

```http
GET /api/v1/products/1
```

**المتوقع:** 200 والمنتج مع `stock: null`. الصفحة لا تفشل.

### 8. الخدمة معلّقة (Pause) لا متوقفة

```bash
docker compose pause inventory
```

```http
POST /api/v1/orders
```

```json
{ "items": [{ "product_id": 1, "quantity": 1 }] }
```

**المتوقع:** 503 بعد انتهاء المهلة تماماً، لا تعليق بلا نهاية.

### 9. إعادة تشغيل الخدمة

```bash
docker compose unpause inventory
docker compose start inventory
```

انتظر بضع ثوانٍ (قناة gRPC بـ PHP تحتاج وقت إعادة اتصال)، ثم أرسل طلباً عادياً.

**المتوقع:** يعمل من جديد.

### 10. إلغاء طلب مرتين

`{id}` هنا هو معرّف الطلب الناجح (201) من الخطوة 5.

```http
PATCH /api/v1/orders/{id}/status
```
```json
{ "status": "cancelled" }
```

ثم كرر نفس الطلب.

**المتوقع:** الأول 200 والكمية عادت، الثاني 422 `INVALID_STATUS_TRANSITION`، والكمية لم تتضاعف.

### 11. تتبّع الطلب عبر سجلّات الخدمتين

> **تنبيه:** السجل يكتب `request_id` بحروف صغيرة داخل الـ context، لذا استخدم بحثاً غير حساس لحالة الأحرف. جرّب الأمر فعلياً على سجل حقيقي قبل العرض وثبّت الصيغة التي نجحت.

```bash
docker compose logs app | grep -i request_id
docker compose logs inventory | grep -i request_id
```

**المتوقع:** نفس `X-Request-Id` يظهر بسجلّي `app` و`inventory` — الطلب الواحد متتبَّع عبر الخدمتين.

## لماذا لا يكفي Postman Collection Runner لإثبات الـ Race Condition

Collection Runner يرسل الطلبات **متتالية لا متزامنة**، فينجح الأول ويُرفض الباقي حتى لو كان الكود خاطئاً. الإثبات يصير بلا قيمة — لذلك الخطوة 5 تعتمد على أمر Artisan (`orders:race-test`) الذي يرسل الطلبات فعلياً بنفس اللحظة عبر `Http::pool`.