# Deployment and troubleshooting

## Historical status

Search engineering showcase. Public examples use generated, authorized records only; no original corpus or private search results are included.

مشروع لعرض هندسة البحث. تستخدم الأمثلة العامة سجلات مولدة ومصرحاً بها فقط؛ لا تُنشر مجموعات المصدر أو النتائج الخاصة.

## Local release environment

Use fresh configuration, a disposable database/corpus and independently installed dependencies. This release never needs retired production services. Keep credentials, uploaded files, sessions, caches and signing material outside the public source. Credential removal does not revoke a provider key.

## Troubleshooting

### Client class missing

Run composer install from the repository root.

### No indexed results

Inspect configured OPENSEARCH_INDEX, ingested document mapping and endpoint connectivity.

### Job cannot find corpus

Set LEAKED_DATA_PATH to the generated authorized corpus.

### Cache appears stale

Inspect clear_search_cache.php and distinguish indexed cache from file-scanning behavior.

## Current limits

No speed, terabyte-scale, document-count or legal-compliance claims are made. Integration checks need disposable OpenSearch/MySQL services. Publish no credential collections or private datasets.

لا ندعي سرعة أو حجم تيرابايت أو أعداد مستندات أو امتثالاً قانونياً عاماً. تتطلب الفحوص المتكاملة خدمات اختبار OpenSearch وMySQL. لا تُنشر مجموعات خاصة.
