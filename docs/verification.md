# Current release verification

Recorded on 2026-10-06 using disposable local data. Historical deployment is a separate owner-provided fact.

## Passed locally

PHP syntax, compatible Composer installation and fresh MariaDB synthetic bootstrap passed. Source review repaired query parameter binding, moved job ownership validation before processing, and added index/cursor values to Redis cache keys. OpenSearch ingestion/pagination/cache checks are provided but have not executed successfully in the current environment.

## Checks and commands

```sh
composer install
php tools/bootstrap.php
php tools/ingest-demo.php
php tools/check-opensearch.php
php -S 127.0.0.1:8087 router.php
python tools/check-syntax.py
```

## CI status

The configured GitHub Actions workflows are registered, but the initial runs ended with startup_failure before any jobs or check annotations were created. Local results above are independent of CI. No passing CI badge is shown; the service supplied no further diagnostic message through the available API.

## Remaining platform and coverage limits

No indexed performance figures are published until the supplied OpenSearch checks and generated-corpus benchmark actually run. Redis and OpenSearch failure behavior, pagination and indexed/file result consistency remain release limitations. Only authorized synthetic records may be used.

PHP checks used PHP 8.4.26; Node builds used Node 24.19; Python checks used Python 3.12.10 where applicable. This record does not claim production hardening, paid provider verification or tests on every platform.
