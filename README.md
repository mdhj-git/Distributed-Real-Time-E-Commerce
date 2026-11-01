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
