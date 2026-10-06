# الإعداد

ثبّت PHP 8.1 وComposer وMySQL وOpenSearch. نفذ `composer install` واستورد مخططات الحسابات والوظائف والدعم بالترتيب المذكور بعد فحص الاعتماديات. اضبط قاعدة البيانات ومفتاح JWT والفهرس portfolio_records ومسار مجموعة مولدة فقط. Redis اختياري. افحص خيارات المفهرس قبل الإدخال ولا تستخدم مسارات إنتاج تاريخية.

## التفاصيل والأوامر

Install PHP 8.1+, Composer, MySQL and OpenSearch. Run `composer install`, import `database.sql`, `database_search_jobs.sql` and `database_support_tickets.sql` in that order after reviewing their schema dependencies. Export database variables, a new JWT secret, `OPENSEARCH_HOST`, `OPENSEARCH_PORT`, `OPENSEARCH_INDEX=portfolio_records` and `LEAKED_DATA_PATH` pointing only to the generated corpus. Configure optional Redis separately. Inspect indexer options before invoking ingestion; do not use historical production paths.

## متغيرات تقرأها الشيفرة

| Variable | Source consumer | Configuration rule |
|---|---|---|
| `CORS_ORIGIN` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `DB_HOST` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `DB_NAME` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `DB_PASS` | `api/config.php` | Supply privately when enabling its integration; no secret default. |
| `DB_USER` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `DEBUG_MODE` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `JWT_SECRET` | `api/config.php` | Supply privately when enabling its integration; no secret default. |
| `LEAKED_DATA_PATH` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `OPENSEARCH_HOST` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `OPENSEARCH_INDEX` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `OPENSEARCH_PORT` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `RECAPTCHA_SECRET_KEY` | `api/config.php` | Supply privately when enabling its integration; no secret default. |
| `RECAPTCHA_SITE_KEY` | `api/config.php` | Use the local example/source default; adapt to your disposable environment. |
| `UNLIMITED_USER_EMAILS` | `api/search/index.php` | Use the local example/source default; adapt to your disposable environment. |

لا تُحمَّل ملفات الأمثلة تلقائياً. تستخدم وحدات dotenv الملف حيث تكون مهيأة، ويستخدم PHP بيئة العملية أو الاستضافة. افصل المزودين عن العرض وأنشئ أسراراً جديدة واحفظها خارج المستودع.

## أوامر المكونات
