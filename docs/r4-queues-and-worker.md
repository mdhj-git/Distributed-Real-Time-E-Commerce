# الطوابير غير المتزامنة والـ Worker (المتطلب الرابع)

يشرح هذا الملف كيف تُرسَل رسائل البريد الخاصة بالطلبات عبر RabbitMQ بدل إرسالها داخل طلب HTTP، وكيف تُعالَج الأخطاء، وكيف يُعرض ذلك.

## ما الذي يُنشر ومن يستهلكه

| العنصر | الدور |
|---|---|
| `OrderPlaced` و`OrderStatusChanged` | حدثان تنشرهما وحدة `Orders` (في `Contracts/Events`) بعد اكتمال الـ commit |
| `DispatchOrderPlacedEmail` و`DispatchOrderStatusEmail` | مستمعان **متزامنان** في وحدة `Notifications`. يضعان `SendOrderEmailJob` في الطابور فقط، ولا يرميان استثناء أبداً |
| `SendOrderEmailJob` | المهمة التي ترسل البريد. تعمل على الطابور `orders` |
| خدمة `worker` | حاوية Docker تشغّل `php artisan queue:work rabbitmq --queue=orders --tries=3 --backoff=5` |
| `EmailSender` | واجهة الإرسال. التنفيذ الحالي `LogEmailSender` يكتب سطراً في السجل ولا يرسل بريداً حقيقياً |

الإشعارات داخل التطبيق (`NotifyOrderPlaced` و`NotifyOrderStatusChanged`) تبقى كما هي، متزامنة، ولا تمر بالطابور.

## المسار

1. العميل ينشئ طلباً (`POST /api/v1/orders`).
2. بعد اكتمال الـ commit يُطلَق `OrderPlaced`.
3. `DispatchOrderPlacedEmail` يضع المهمة في الطابور `orders` ويعود، فيعود الرد `201` دون انتظار البريد.
4. الـ worker يلتقط المهمة ويستدعي `EmailSender`.
5. عند النجاح يظهر في `storage/logs/laravel.log` السطر `Email sent.`.

فائدة هذا التصميم: بطء خدمة البريد أو تعطّلها لا يؤثر على إنشاء الطلب، لأن الطلب انتهى قبل أن يبدأ الإرسال. ويبقى `request_id` الأصلي في سجلات الـ worker، فيمكن تتبّع طلب واحد من الـ API إلى المهمة.

## سياسة إعادة المحاولة

- `tries = 3`: ثلاث محاولات للمهمة الواحدة.
- `backoff = 5`: خمس ثوانٍ بين كل محاولتين.
- بعد المحاولة الثالثة الفاشلة تُستدعى `failed()` في المهمة، فتكتب في السجل `Order email failed after all attempts.` مع `user_id` والسبب.

## الطابور الميت (DLQ: Dead Letter Queue)

الإعداد في `config/queue.php` داخل الاتصال `rabbitmq`:

```php
'options' => [
    'queue' => [
        'reroute_failed' => true,
        'failed_routing_key' => '%s.failed',
    ],
],
```

كيف يعمل:

- حين تنشئ الحزمة الطابور `orders` تضيف إليه الوسيطين `x-dead-letter-exchange` (فارغ، أي الـ default exchange) و`x-dead-letter-routing-key = orders.failed`.
- عند فشل المهمة نهائياً ترفضها الحزمة، فيعيد RabbitMQ توجيهها إلى طابور اسمه `orders.failed`.
- لا يوجد exchange مخصص ولا binding. يكفي وجود طابور باسم `orders.failed`.
- المهمة الفاشلة تُسجَّل أيضاً في جدول `failed_jobs` (المشغّل `database-uuids`).

**الطابور `orders.failed` لا تنشئه الحزمة.** يُنشأ مرة واحدة يدوياً:

```bash
docker compose exec rabbitmq rabbitmqadmin -u shop -p shop_dev declare queue name=orders.failed durable=true
```

**تنبيه عند التحديث:** إن كان الطابور `orders` موجوداً من قبل بدون هذه الوسائط، فلن تعيد الحزمة تعريفه، وستُحذف الرسائل الفاشلة بصمت بدل أن تذهب إلى `orders.failed`. الحل مرة واحدة، والطابور فارغ: إيقاف الـ worker، ثم `docker compose exec rabbitmq rabbitmqctl delete_queue orders`، ثم تشغيل الـ worker. يُنشأ الطابور من جديد بالوسائط الصحيحة عند أول استعمال.

التحقق من الوسائط:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages arguments
```

## تشغيل الـ Worker

```bash
docker compose up -d worker
docker compose logs -f worker
```

- الخدمة تتجاوز entrypoint التطبيق عمداً، لأن `composer install` وmigrate يعملان مرة واحدة في خدمة `app`.
- الـ worker يحمّل الكود والإعداد عند بدئه. بعد تغيير مستمع أو مهمة أو `.env` نفّذ `docker compose restart worker`.
- الـ worker يستخدم الاستعلام الدوري (polling)، فعدد المستهلكين (Consumers) في واجهة RabbitMQ يظهر `0` وهذا طبيعي. المعيار هو معالجة المهام فعلاً.
- واجهة الإدارة: `http://localhost:15672` (المستخدم وكلمة المرور من `RABBITMQ_USER` و`RABBITMQ_PASSWORD`).

## عرض الفشل وإعادة المحاولة

1. في `.env` المحلي اجعل `NOTIFICATIONS_EMAIL_FAIL=true`، ثم `docker compose restart worker`.
2. أنشئ طلباً. يعود `201` كالمعتاد.
3. في سجل الـ worker تظهر ثلاث محاولات `FAIL` للمهمة.
4. بعدها:
   - `docker compose exec app php artisan queue:failed` يعرض المهمة.
   - واجهة RabbitMQ أو `rabbitmqctl list_queues name messages` تُظهر رسالة في `orders.failed`.
5. أعد `NOTIFICATIONS_EMAIL_FAIL=false` ثم `docker compose restart worker`.
6. `docker compose exec app php artisan queue:retry all` يعيد المهام الفاشلة إلى `orders`، فتنجح ويظهر `Email sent.`.

## حدود معروفة

- **نسخ `orders.failed` تبقى بعد `queue:retry all`.** إعادة المحاولة تعمل على جدول `failed_jobs` ولا تستهلك `orders.failed`. فالرسائل فيه سجلّ تدقيق يُفرَّغ يدوياً عند الحاجة (`rabbitmqctl purge_queue orders.failed`).
- **التسليم "مرة واحدة على الأقل".** قد تُعالَج المهمة أكثر من مرة في حالات نادرة، لذلك يجب أن يكون الإرسال آمناً عند التكرار.
- **البريد محاكى.** `LogEmailSender` يسجّل فقط. الخدمة الحقيقية تحلّ محلّه في ربط `EmailSender` داخل `NotificationsServiceProvider` وحده.
- **عنوان المستلم.** وحدة `Users` لا تقدّم Contract يعيد البريد الإلكتروني، فتحمل المهمة `user_id` فقط. عند توفّر Contract يُستبدل ذلك دون تغيير في بقية التصميم.
- **الإشعار اللحظي لا يمر بهذا الطابور.** البث للمتصفح عبر SSE وله وثيقته الخاصة.
