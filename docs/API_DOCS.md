# توثيق الواجهة (Scramble) ومجموعة Postman

## Scramble

- الواجهة التفاعلية: `http://localhost:8080/docs/api` (بيئة `local` فقط).
- تصدير المواصفة OpenAPI إلى `api.json` في جذر المشروع:

```bash
docker compose exec app php artisan scramble:export
```

- زر "Authorize" يقبل رمز JWT (مخطط `bearer`، مضبوط في `AppServiceProvider`).
- لا تحرّر `api.json` يدوياً. أعد توليده بعد أي تغيير في المسارات.

## Postman

الملفات في `docs/postman/`:

- `local.postman_environment.json`: البيئة (`E-Commerce Local`). تأتي مع مجموعة المنتجات.
- `requirement_1_products.postman_collection.json`: المنتجات.
- `requirement_1_orders_notifications.postman_collection.json`: الطلبات والإشعارات واختبارات الرفض.

الترتيب: Login (Admin) ثم Login (Customer) ثم Login (Customer B) ثم Orders ثم Notifications ثم Rejection tests.

- شغّل `db:seed` أولاً. الحسابات `demo.*@shop.test` من README.
- العميل B يلزم لإثبات أن عميلاً لا يصل إلى طلب غيره ولا إلى إشعاره (403). متغيراته `customer_b_email` و`customer_b_password` في المجموعة نفسها.
- ضع رقم منتج موجود في المتغير `product_id` قبل إنشاء الطلب.

## الإشعارات

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `/api/v1/notifications` | إشعاراتي، الأحدث أولاً. `?unread=1` و`per_page` (1–50) |
| PATCH | `/api/v1/notifications/{id}/read` | تعليم إشعار كمقروء (لصاحبه فقط، غيره 403) |
| POST | `/api/v1/notifications/read-all` | تعليم كل إشعاراتي كمقروءة (204) |

- تُنشأ الإشعارات تلقائياً من الحدثين `OrderPlaced` و`OrderStatusChanged`.
- المستمعون متزامنون ولا يرمون استثناءً أبداً: الحدثان يُطلقان بعد أن حُفظ الطلب وحُجز المخزون، فاستثناء هنا يحوّل طلباً ناجحاً إلى 500. فشل إنشاء الإشعار يُسجَّل في السجل فقط.

## تشغيل الاختبارات

```bash
docker compose exec app php artisan test --filter=Notifications
docker compose exec app php artisan test --filter=Rejection
```
