# Clean setup

Use PHP 8.1+ with mysqli and optional Redis extension. Export env.example variables into PHP: disposable DB_* credentials, fresh JWT_SECRET, DEMO_MODE=1 and DEMO_PASSWORD. Start a dedicated OpenSearch instance; configure OPENSEARCH_HOST/PORT and OPENSEARCH_INDEX=portfolio_records. Redis is optional caching infrastructure. Set LEAKED_DATA_PATH only to generated authorized fixtures; the legacy variable name does not authorize private data. Ingestion uses fixtures/generated.jsonl.

## Commands

```sh
composer install
php tools/bootstrap.php
php tools/ingest-demo.php
php tools/check-opensearch.php
php -S 127.0.0.1:8087 router.php
python tools/check-syntax.py
```

## Complete configuration inventory

Install PHP 8.1+, Composer, MySQL and OpenSearch. Run `composer install`, import `database.sql`, `database_search_jobs.sql` and `database_support_tickets.sql` in that order after reviewing their schema dependencies. Export database variables, a new JWT secret, `OPENSEARCH_HOST`, `OPENSEARCH_PORT`, `OPENSEARCH_INDEX=portfolio_records` and `LEAKED_DATA_PATH` pointing only to the generated corpus. Configure optional Redis separately. Inspect indexer options before invoking ingestion; do not use historical production paths.

## Environment variables read by source

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

Environment examples do not load themselves. Node dotenv modules read local `.env` where configured; PHP uses its process/hosting environment. Keep provider integrations disconnected for demos. Generate a new secret with `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"` or equivalent, then store it privately.

## Declared component commands



## Source boundaries

| Component | Responsibility |
|---|---|
| `api/search/` | Indexed search and job endpoints |
| `api/utils/` | OpenSearch client/indexer and file/job workers |
| `api/admin/` | Administrative API |
| `database*.sql` | Account, support and job schema |
| `js/` | Browser API client and interface helpers |


Variables in the inventory are not all mandatory: the preceding prerequisites identify the required core values. Provider variables are required only for their enabled live integration. Tests may use DEMO_API_URL to override the local target. Never point bootstrap/reset/check scripts at a production database.
