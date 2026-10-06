# عرض اصطناعي

إدخال سجلات اصطناعية مصرح بها ← بحث مفهرس بنتائج مقسمة؛ ينشئ المسار غير المتزامن وظيفة ويمسح مجموعتها ويعرض التقدم ونتائج تخص صاحبها.

## الوظائف المراد عرضها

- مسار بحث OpenSearch فعال
- أدوات إدخال وفهرسة المستندات
- ترقيم صفحات ومعالجة search_after
- تخزين Redis اختياري وإبطاله
- مسح ملفات تاريخي ووظائف وتقدم ونتائج
- اشتراكات وإدارة حسابات وحدود استخدام

## خطوات التنفيذ التفصيلية

1. Generate harmless synthetic event logs.
2. Index the records and compare first/next search pages.
3. Run the retained asynchronous path and inspect progress and ownership.
4. Disable OpenSearch intentionally; record the indexed-route error separately from the file path.

## توثيق الدليل

التقط الصور من التطبيق الفعلي بحسابات اصطناعية وسجل المكون وحجم الشاشة والإعداد. نص خطوات العرض ليس فيديو مسجلاً. قِس الأداء مع حجم المدخلات والجهاز والوقت وحالة التخزين المؤقت.

لا ندعي سرعة أو حجم تيرابايت أو أعداد مستندات أو امتثالاً قانونياً عاماً. تتطلب الفحوص المتكاملة خدمات اختبار OpenSearch وMySQL. لا تُنشر مجموعات خاصة.
