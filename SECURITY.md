# Publication boundary

This repository contains a sanitized source history and generated demonstration data. Actual environment files (including names ending in `.env`), deployment directories, VPS helpers, signing keys, service-account files, uploads, caches and session records are excluded. Placeholder-only environment examples are intentional.

The publication audit inspected the intended files and every commit reachable from the publication branch. It checked environment/deployment filenames, private signing material, credential formats, former private infrastructure values and public IPv4 candidates. Automated patterns cannot prove that every possible secret format has been discovered; the source review and explicit file manifest provide additional evidence.

Removing a credential does not revoke it. Any former deployment credential must be rotated separately. No rotation is claimed here. Use fresh local values, synthetic databases and isolated provider configuration when reproducing the examples. Do not reconnect a retired production environment.

## العربية

يتضمن المستودع تاريخ شيفرة منقحاً وعينات اصطناعية. تُستبعد ملفات البيئة الفعلية بما فيها الأسماء المنتهية بـ.env ومجلدات النشر ومساعدات VPS ومفاتيح التوقيع وحسابات الخدمة والرفع والمخبأ والجلسات. أمثلة البيئة ذات القيم النائبة مقصودة. فحص التدقيق الملفات المقصودة وكل التزامات فرع النشر، مع أسماء الملفات وصيغ الأسرار وقيم البنية الخاصة السابقة وعناوين IPv4 العامة. لا تثبت الأنماط وحدها غياب كل سر ممكن؛ تدعمها مراجعة المصدر وجرد الملفات.

إزالة بيانات الاعتماد لا تلغيها. يجب تدوير بيانات النشر القديمة بصورة مستقلة ولا ندعي حدوث ذلك. استخدم قيماً جديدة وقاعدة اصطناعية وإعداداً معزولاً للمزود، ولا تعاود الاتصال ببيئة إنتاج متقاعدة.
