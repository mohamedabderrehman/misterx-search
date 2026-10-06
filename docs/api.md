# API and execution paths

This index is extracted from the current source. Router-local paths require their mount prefix from the server entry point. PHP endpoint paths map directly to files unless Apache rewrites them. Controllers and auth middleware are authoritative for request bodies and permissions.

See the source entry points below; this project does not declare Express/Flask router paths.

## Source entry points

- [api/search/index.php](../api/search/index.php)
- [api/utils/OpenSearchClient.php](../api/utils/OpenSearchClient.php)
- [api/utils/opensearch_indexer.php](../api/utils/opensearch_indexer.php)
- [api/utils/fast_search.php](../api/utils/fast_search.php)
- [api/utils/process_search_job.php](../api/utils/process_search_job.php)

## الاستخدام

المسارات المذكورة محلية للموجه وتحتاج بادئة الربط في الخادم. ملفات PHP هي مرجع المسارات ما لم تُعَد كتابتها. استخدم بيانات اصطناعية وفحوص الصلاحيات الموجودة في الشيفرة.


## Representative usage

The indexed search endpoint uses the configured portfolio_records index. Search cursors/cache keys and file-based jobs are separate execution paths. Use only generated records; the retained legacy LEAKED_DATA_PATH name describes configuration history, not permission to process credentials or private data.

```sh
php tools/ingest-demo.php
php tools/check-opensearch.php
curl -X POST http://localhost:8087/api/auth/login.php -H 'Content-Type: application/json' -d '{"emailOrUsername":"client","password":"YOUR_DEMO_PASSWORD"}'
curl -X POST http://localhost:8087/api/search/ -H 'Content-Type: application/json' -H 'Authorization: Bearer YOUR_DEMO_TOKEN' -d '{"query":"alpha","limit":2}' 
```
