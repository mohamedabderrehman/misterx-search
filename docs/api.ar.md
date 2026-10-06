# الواجهات ومسارات التنفيذ

إدخال سجلات اصطناعية مصرح بها ← بحث مفهرس بنتائج مقسمة؛ ينشئ المسار غير المتزامن وظيفة ويمسح مجموعتها ويعرض التقدم ونتائج تخص صاحبها.

تحتاج المسارات المحلية للموجه إلى بادئة الخادم. تستخدم مسارات PHP الملفات الفعلية ما لم توجد إعادة كتابة. المتحكمات والوسطاء في الشيفرة مرجع الحقول والصلاحيات. فحوص tools/check-demo تمثل طلبات حقيقية ببيانات اصطناعية وليست مزوداً وهمياً.

## مراجع التنفيذ

- [api/search/index.php](../api/search/index.php)
- [api/utils/OpenSearchClient.php](../api/utils/OpenSearchClient.php)
- [api/utils/opensearch_indexer.php](../api/utils/opensearch_indexer.php)
- [api/utils/fast_search.php](../api/utils/fast_search.php)
- [api/utils/process_search_job.php](../api/utils/process_search_job.php)

## حدود التكامل

لا تُنشر أرقام أداء للفهرسة قبل تنفيذ فحوص OpenSearch والقياس الاصطناعي. تبقى أعطال Redis وOpenSearch والصفحات واتساق مسارات الملفات والفهرسة حدوداً للإصدار. تُستخدم بيانات اصطناعية مصرح بها فقط.


## جرد المسارات



## مثال الاستخدام

يستخدم البحث المفهرس الفهرس portfolio_records المهيأ. مؤشرات البحث ومفاتيح المخبأ ووظائف الملفات مسارات مختلفة. استخدم السجلات المولدة فقط؛ يوضح اسم LEAKED_DATA_PATH القديم تاريخ الإعداد ولا يمنح معالجة بيانات اعتماد أو بيانات خاصة.

```sh
php tools/ingest-demo.php
php tools/check-opensearch.php
curl -X POST http://localhost:8087/api/auth/login.php -H 'Content-Type: application/json' -d '{"emailOrUsername":"client","password":"YOUR_DEMO_PASSWORD"}'
curl -X POST http://localhost:8087/api/search/ -H 'Content-Type: application/json' -H 'Authorization: Bearer YOUR_DEMO_TOKEN' -d '{"query":"alpha","limit":2}' 
```
