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
