# Inventory Service

خدمة gRPC مستقلة لإدارة المخزون، مبنية بـ Node.js، بقاعدة بيانات MySQL خاصة فيها.

## التشغيل

```bash
docker compose up -d inventory-db inventory
```

## اختبار عبر grpcurl

```bash
docker compose run --rm grpcurl -plaintext -proto proto/inventory/v1/inventory.proto \
  -d '{"product_ids": [1]}' inventory:50051 inventory.v1.Inventory/GetStock
```

(أمثلة الحجز والإرجاع والتعديل مشابهة — غيّر اسم العملية والبيانات.)

## سلوكيات مهمة

- **`released: false`**: لا يعني بالضرورة خطأ — قد يعني أن المرجع لم يُحجز أصلاً (Tombstone وقائي)، أو أنه حُرر مسبقاً (تكرار نداء آمن).
- **إعادة نفس `reference` بعناصر مختلفة لا تُقارَن**: إذا تكرر نداء `ReserveStock` بنفس المعرّف لكن بعناصر مختلفة، تُرجع الخدمة نتيجة المحاولة الأولى كما هي.
- **الـ Tombstones لا تُحذف**: سجل `reservations` بحالة `released` يبقى للأبد — يمنع حجزاً متأخراً يصل بعد المهلة.

## متغيرات البيئة المطلوبة (لا قيم افتراضية)

`INVENTORY_DB_HOST`, `INVENTORY_DB_PORT`, `INVENTORY_DB_NAME`, `INVENTORY_DB_USER`, `INVENTORY_DB_PASS`, `INVENTORY_GRPC_PORT`