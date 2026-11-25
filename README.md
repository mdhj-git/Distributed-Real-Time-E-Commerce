# Distributed-Real-Time-E-Commerce

منصة تجارة إلكترونية لمشروع مادة تطبيقات الإنترنت (جامعة دمشق، 2026).
مبنية بـ Laravel 13 على PHP 8.3، ببنية Modular Monolith، وتعمل كاملة داخل Docker.

## التحقق المسبق

```bash
docker compose version
docker run hello-world
```

إن فشل أي منهما فاتبع قسم "ملاحظة لمن لا يعمل Docker عنده".

## التشغيل أول مرة

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan jwt:secret
```

- قد يستغرق `up` حتى 5 دقائق.
- ثم افتح `http://localhost:8080/api/v1/health`.

### لمن عنده `.env` قديم من المرحلة 0أ

1. أضف المتغيرات الجديدة من `.env.example` (`JWT_*`).
2. شغّل `docker compose exec app php artisan jwt:secret`.

### سجل قديم مملوك لـ `root`

العَرَض: 500 على `health`.
والسبب: `php-fpm` يعمل بالمستخدم `www-data`، ولا يستطيع الكتابة في سجل أنشأه `root`.

العلاج:

```bash
docker compose exec app chmod 666 storage/logs/laravel.log
```

## الاستخدام اليومي

```bash
docker compose stop
docker compose up -d
```

**تحذير:** لا تستخدم `docker compose down -v`، فهو يمسح قاعدة البيانات.

## أوامر شائعة داخل الحاوية

```bash
docker compose exec app php artisan route:list
docker compose exec app composer dump-autoload
docker compose exec app php artisan module:make-model Product Products
docker compose exec app php artisan db:seed
```

- `db:seed` لا يعمل تلقائياً، فشغّله حين يلزم.
- لا تشغّل `php` ولا `composer` على جهازك مباشرة.

## الصورة الأساسية لـ gRPC وتوليد الكود

امتداد `grpc` يُترجم مرة واحدة (نحو 30 دقيقة) ويُنشر صورةً أساسية، فلا يترجمه أي عضو محلياً. ملف `docker/app/Dockerfile` يبدأ منها بسطر `FROM`.

### بناء الصورة ونشرها

- الوصفة في `docker/php-grpc/Dockerfile`.
- الملف `.github/workflows/php-grpc-image.yml` يبنيها عند أي دفع يغيّر الوصفة أو الـ workflow نفسه، ويرفعها إلى `ghcr.io/mdhj-git/php-grpc:8.3-fpm-grpc1.85.0`.
- الحارس: قبل البناء يفحص الـ workflow وجود الوسم في السجل. إن وُجد تخطّى البناء ولم يكتب فوقه، كما يحدث عند دمج الفرع في `main`. وإن تعذّر الجزم بوجوده فشلت المهمة بدل المخاطرة بالكتابة فوقه.
- لإعادة بناء وسم موجود عمداً: شغّل الـ workflow يدوياً من تبويب Actions مع `force` = `true`.
- يجب أن تكون الحزمة (package) عامة (public) كي يسحبها بقية الأعضاء.

### تغيير إصدار gRPC

عدّل هذه المواضع الأربعة معاً، واستخدم وسماً جديداً ولا تكتب فوق الوسم القديم:

1. الوصفة: غيّر الإصدار في `RUN pecl install grpc-X.Y.Z` داخل `docker/php-grpc/Dockerfile`.
2. الوسم: المتغير `IMAGE_TAG` في `.github/workflows/php-grpc-image.yml`.
3. السطر `FROM` في `docker/app/Dockerfile`.
4. قيد `grpc/grpc` في `composer.json`، ليبقى مطابقاً لإصدار الامتداد. الملف مجمّد، فيكون التغيير في PR مستقل.

### توليد أصناف protobuf

- تُولَّد أصناف الرسائل فقط من `proto/inventory/v1/inventory.proto`، ولا يُولَّد عميل (stub).
- تُحفظ في `Modules/Products/Grpc/` وتُرفع إلى المستودع، فلا يحتاج أي عضو إلى `protoc`.
- هذا المجلد مولَّد ومستثنى من Pint، فلا تضع فيه كوداً مكتوباً يدوياً.
- الإصدارات: `protoc` 36.2 مع `google/protobuf` 5.36.x. يجب ألا يكون إصدار `protoc` أحدث من إصدار حزمة `google/protobuf`.
- شغّل الأمر من جذر المشروع، وعند تغيير ملف `.proto` فقط. يعمل `protoc` داخل حاوية مؤقتة (`--rm`) ولا يُثبَّت في المشروع.

Git Bash:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD:/work" -w /work debian:bookworm-slim sh -c 'set -e; apt-get update -qq; apt-get install -y -qq --no-install-recommends curl ca-certificates unzip >/dev/null; curl -fsSL -o /tmp/protoc.zip https://github.com/protocolbuffers/protobuf/releases/download/v36.2/protoc-36.2-linux-x86_64.zip; echo 121f6c7afe1d4d0e3ea6aab9432038599250134cbf4474cb1167d2c7decd4278 */tmp/protoc.zip | sha256sum -c -; unzip -q /tmp/protoc.zip -d /opt/protoc; /opt/protoc/bin/protoc -I proto --php_out=. proto/inventory/v1/inventory.proto'
```

PowerShell:

```powershell
docker run --rm -v "${PWD}:/work" -w /work debian:bookworm-slim sh -c 'set -e; apt-get update -qq; apt-get install -y -qq --no-install-recommends curl ca-certificates unzip >/dev/null; curl -fsSL -o /tmp/protoc.zip https://github.com/protocolbuffers/protobuf/releases/download/v36.2/protoc-36.2-linux-x86_64.zip; echo 121f6c7afe1d4d0e3ea6aab9432038599250134cbf4474cb1167d2c7decd4278 */tmp/protoc.zip | sha256sum -c -; unzip -q /tmp/protoc.zip -d /opt/protoc; /opt/protoc/bin/protoc -I proto --php_out=. proto/inventory/v1/inventory.proto'
```

### إعدادات gRPC في PHP-FPM

- تُركت `grpc.enable_fork_support` و`grpc.poll_strategy` على قيمها الافتراضية.
- اختيرت هذه القيم بقراءة كود الامتداد، لا بتجربة حية.
- الـ PR 2 (العميل الفعلي) يجب أن يتضمن مكالمة gRPC حقيقية تحت PHP-FPM: طلبان متتاليان يخدمهما العامل (worker) نفسه، ثم اختبار مهلة (deadline).
- إن ظهر أي سلوك غريب هناك فتُراجَع الإعدادان.

### بعد دمج هذا الـ PR

```bash
git pull
docker compose build --pull app
docker compose up -d
```

لا حاجة لأي ترجمة محلية.

## الحسابات التجريبية

للتطوير فقط. الـ Seeder لا يفحص البيئة وكلمة المرور معروفة، فلا تشغّله في `production`.

```bash
docker compose exec app php artisan db:seed
```

كلمة المرور للحسابات الثلاثة: `password`.

- `demo.admin@shop.test`: الدور `admin`.
- `demo.customer.a@shop.test`: الدور `customer`.
- `demo.customer.b@shop.test`: الدور `customer`.

- تسجيل الدخول: `POST /api/v1/auth/login` بالحقلين `email` و`password`. الاستجابة فيها `data.user` و`data.token`.
- حد المحاولات: 5 في الدقيقة لكل بريد وعنوان IP، والسادسة تعيد 429.
- قواعد التحقق من الدور في القسم 11 من [`docs/PROJECT_RULES.md`](docs/PROJECT_RULES.md).

## نقل المخزون إلى خدمة المخزون

كانت الكميات في جدول `stock_levels` داخل قاعدة التطبيق، وصارت في خدمة المخزون. الأمر `inventory:import-stock` ينقل الكميات الموجودة عبر عقد `InventoryService` (يقرأ الكمية الحالية ثم يعدّل بالفرق إلى الكمية المطلوبة)، فتشغيله مرتين يعطي النتيجة نفسها والثاني لا يعدّل شيئاً.

**الترتيب إلزامي:**

```bash
# 1) شغّلوا خدمة المخزون (وتأكدوا أن ربط InventoryService على gRPC)
docker compose up -d inventory inventory-db

# 2) عرض ما سيحدث دون أي تعديل
docker compose exec app php artisan inventory:import-stock --dry-run

# 3) الاستيراد
docker compose exec app php artisan inventory:import-stock

# 4) التحقق: مقارنة فقط. يجب أن ينجح (رمز الخروج 0)
docker compose exec app php artisan inventory:import-stock --verify

# 5) فقط بعد نجاح الخطوة 4: أضيفوا STOCK_LEVELS_DROP_CONFIRMED=true إلى ملف .env
#    ثم شغّلوا المهاجرة التي تحذف جدول stock_levels
docker compose exec app php artisan migrate
```

- **سبب خطوة التأكيد:** سكربت بدء الحاوية يشغّل `php artisan migrate --force` عند كل إقلاع. فمهاجرة الحذف ترفض العمل (وتتوقف الحاوية برسالة واضحة) ما دام الجدول فيه كميات، حتى لا يُحذف قبل الاستيراد.
- إن توقفت حاوية `app` بسبب هذه الرسالة فنفّذوا أوامر `inventory:import-stock` بهذه الصيغة بدل `exec` (وخدمة المخزون شغّالة): `docker compose run --rm --no-deps --entrypoint php app artisan inventory:import-stock --verify`.
- قاعدة بيانات جديدة (`migrate:fresh`) لا تتأثر، لأن الجدول فارغ.
- بعد نجاح الحذف أزيلوا `STOCK_LEVELS_DROP_CONFIRMED` من `.env`.

- الخيارات: `--dry-run` (يعرض ولا يعدّل)، `--verify` (يقارن فقط ويفشل عند أي اختلاف)، `--chunk=100` (عدد المنتجات في كل نداء `getStock`، من 1 إلى 1000). ولا يجتمع `--dry-run` مع `--verify`.
- رمز الخروج: `0` عند النجاح، و`1` عند الفشل، وعند عدم التطابق في `--verify`، وعند عدم توفر الخدمة.
- المخرجات تنتهي بملخص: عدد المنتجات، والمعدّلة، وغير المتغيّرة.
- إن توقفت الخدمة في منتصف الاستيراد يبقى ما نُقل قبل التوقف، وإعادة تشغيل الأمر نفسه تكمل من حيث توقف.
- الأمر يرفض العمل ما دام `InventoryService` مربوطاً بالتنفيذ المحلي، لأن المصدر والوجهة عندها جدول واحد ولا قيمة للنجاح.
- للاستعمال أثناء الانتقال فقط وبلا طلبات متزامنة: قراءة الكمية ثم تعديلها ليست ذرّية بالنسبة لحجز يحدث في اللحظة نفسها.
- **تحذير:** حذف `stock_levels` لا رجعة فيه للبيانات (`down()` يعيد الهيكل فقط). لا تشغّل مهاجرة الحذف قبل نجاح `--verify`.
- `db:seed` يضبط كميات المنتجات التجريبية عبر الخدمة نفسها، فيحتاج خدمة المخزون شغّالة.

## بنية الوحدات

الوحدات تحت `Modules/`: `Shared` و`Users` و`Products` و`Orders` و`Notifications`.

القواعد الملزمة في [`docs/PROJECT_RULES.md`](docs/PROJECT_RULES.md). اقرأها قبل أن تبدأ فرعك.

## سير العمل على Git

- تسمية الفروع: مثل `feature/r1-users-auth` و`chore/r1-docker`.
- رسائل الـ Commit بصيغة Conventional Commits: مثل `feat(orders): add status transition policy`.
- حدّث فرعك من `main` قبل فتح الـ PR.
- كل PR يحتاج مراجعة واحدة من عضو غير صاحبه.
- أسلوب الدمج: Merge commit.

## التوثيق

`http://localhost:8080/docs/api` (متاح في `APP_ENV=local` فقط).

## التشخيص

500 برمز `SERVER_ERROR` من مسارات المصادقة أو المسارات الخاضعة للمحدِّد قبل تشغيل `jwt:secret` يعني غياب `JWT_SECRET`. والسبب يظهر في `storage/logs/laravel.log` مع `request_id`.

## ملاحظة لمن لا يعمل Docker عنده

### أولاً: إصلاح Docker، وهو الحل المطلوب

على Windows يحتاج Docker Desktop إلى تفعيل الـ virtualization من BIOS، وإلى WSL2.

1. فعّل الـ virtualization.
2. نفّذ `wsl --install` من PowerShell بصلاحيات المسؤول.
3. أعد تشغيل الجهاز.
4. جرّب `docker run hello-world`.

إن ضاقت الذاكرة فحدّد لها سقفاً في الملف `.wslconfig`.

### ثانياً: مسار احتياطي مؤقت

يُستخدم إن تعذّر الإصلاح، مثل جهاز لا يدعم الـ virtualization.

- لا يُعتمد عليه للتسليم.
- يُجرَّب فرع صاحبه على Docker عند عضو آخر قبل دمجه.
- لا يصلح للمتطلب الثاني، الذي يحتاج Docker.

الخطوات:

1. **PHP 8.3.x:** تحقق بـ `php -v` من PHP الذي سيُستخدم فعلاً. إن كان PHP داخل XAMPP أقدم، فثبّت PHP 8.3 منفصلاً وشغّل التطبيق بـ `php artisan serve`، مع الإبقاء على MySQL الخاص بـ XAMPP.
2. **Composer** محدّث: `composer --version`.
3. **الامتدادات:** `pdo_mysql` و`gd` و`zip` و`mbstring` و`fileinfo` و`bcmath` وغيرها مما يطلبه Laravel 13، تُفعَّل في `php.ini`.
4. **قاعدة البيانات:** MySQL أو MariaDB من XAMPP، بإصدار يدعمه Laravel 13، وفي `.env` المحلي `DB_HOST=127.0.0.1`.
5. **بدون Redis:** في `.env` المحلي `CACHE_STORE=database`، ثم `php artisan migrate`.
6. **المفاتيح والروابط:** `php artisan key:generate` و`php artisan jwt:secret` و`php artisan storage:link`. وعلى Windows قد يتطلب `storage:link` تشغيل الطرفية بصلاحيات المسؤول.
7. **القاعدة الملزمة:** الكود لا يخاطب Redis مباشرة بل عبر واجهات Laravel فقط، وإلا فقد هذا المسار صلاحيته.
8. **حدود هذه الطريقة:** لن يظهر عندها أي خطأ يخص Redis أو MySQL 8.4 تحديداً.

## سيناريو عرض المتطلب الثاني

راجع [docs/r2-demo-scenario.md](docs/r2-demo-scenario.md) لخطوات العرض الكاملة أمام الأستاذ.

## المتطلب الثالث: الأداء والتخزين المؤقت ونسخة القراءة

الأرقام والتفسير في [`docs/r3-performance-report.md`](docs/r3-performance-report.md).

### نسخة القراءة (اختيارية)

نسخة MySQL للقراءة فقط، في الـ profile `replica` داخل `docker-compose.yml`. كل أمر يخصها يحتاج `--profile replica`، وبدونه لا يراها `docker compose stop` ولا `docker compose up -d`.

أول إعداد (مرة واحدة؛ يُوقَف `app` لأن النسخ يتم بـ `mysqldump`):

```bash
docker compose stop app
docker compose up -d mysql
docker compose --profile replica up -d mysql-replica
docker compose --profile replica exec mysql-replica bash /scripts/setup-replica.sh
docker compose up -d app
```

- إن ظهر 502 بعد `up -d app` فنفّذ `docker compose restart nginx`.

الاستخدام اليومي:

```bash
docker compose --profile replica up -d
docker compose --profile replica exec -T mysql-replica bash /scripts/check-replica.sh
docker compose --profile replica stop
```

- `check-replica.sh` يفحص الصحة، ورمز الخروج `0` يعني أن النسخة سليمة ولحقت بالأساسية.
- لتفعيل القراءة منها ضع `DB_READ_HOST=mysql-replica` في `.env`. ولإيقافها أفرغ القيمة (`DB_READ_HOST=`): يعود التطبيق إلى القاعدة الأساسية في الطلب التالي.
- نسخة بقيت متوقفة أكثر من يومين (مدة حفظ السجل الثنائي على الأساسية) قد تحتاج `setup-replica.sh --reseed` مع إيقاف `app`.

### بيانات القياس و k6

- توليد البيانات (`perf:seed`) وتشغيل k6 كلاهما موصوف في [`perf/MEASURE.md`](perf/MEASURE.md): القسم `0b` للبيانات والقسم `5` للقياس. لا يُكرَّر هنا.
- القسم 13 من الملف نفسه فيه تشغيلة واحدة قبل نسخة القراءة وأخرى بعدها.
- `PerfQueryCounterServiceProvider` للتطوير فقط: لا يعمل إلا مع `PERF_QUERY_LOG=true` (الافتراضي `false`)، ويجب أن يبقى `false` أثناء أي تشغيل k6.

### مفاتيح `.env` الخاصة بهذا المتطلب (القيم الافتراضية في `.env.example`)

- `DB_READ_HOST`: مضيف القراءة. فارغ (الافتراضي) يعني القاعدة الأساسية، ولا شيء يتغير.
- `READ_YOUR_WRITES_ENABLED`: `true` افتراضياً. بعد أن يعدّل مستخدم بياناته تُقرأ بياناته من القاعدة الأساسية مدة قصيرة.
- `READ_YOUR_WRITES_TTL_SECONDS`: `30` افتراضياً. مدة تلك القراءة من الأساسية، ويجب أن تبقى أطول من تأخر النسخة.
- `CATALOG_READS_ON_PRIMARY`: `true` افتراضياً. مسارات الكتالوج (`categories` و`products`) تُقرأ دائماً من الأساسية.
- `PRODUCTS_CACHE_LIST_TTL` و`PRODUCTS_CACHE_DETAIL_TTL` و`PRODUCTS_CACHE_CATEGORIES_TTL`: `600` و`1800` و`3600` ثانية. مدد كاش الكتالوج في Redis، والكتابة تُبطله بأرقام الإصدار.
- `ETAG_ENABLED`: `true` افتراضياً. تفعيل ETag والرد 304 على `GET /products/{id}`.

## الطوابير والـ Worker (المتطلب الرابع)

راجع [docs/r4-queues-and-worker.md](docs/r4-queues-and-worker.md) لشرح إرسال البريد عبر RabbitMQ، وسياسة إعادة المحاولة، والطابور الميت، وطريقة العرض.

التحديثات اللحظية (Laravel Reverb) وصفحات الويب وخطوات الإثباتات الثلاثة: [docs/r4-realtime-and-ui.md](docs/r4-realtime-and-ui.md).

## التوسع وتوزيع الأحمال (المتطلب الخامس)

ثلاث نسخ من `app` وثلاثة عمّال `worker` خلف nginx، مع خدمة `migrate` واحدة تمنع سباق المهاجرات. الشرح الكامل وأوامر الإثبات (التوزيع، توقف نسخة، منع البريد المكرّر): [docs/r5-scaling-report.md](docs/r5-scaling-report.md).

Linux / macOS / Git Bash:

```bash
docker compose up -d --build
for i in $(seq 1 10); do curl -sI http://127.0.0.1:8080/api/v1/health | grep -i '^x-served-by'; done
```

Windows PowerShell:

```powershell
docker compose up -d --build
1..10 | ForEach-Object { curl.exe -sI http://127.0.0.1:8080/api/v1/health | Select-String -Pattern '^X-Served-By' }
```
