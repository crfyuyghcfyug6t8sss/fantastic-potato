# FB & Instagram Manager — v3 (Instagram Update)

## ✅ الميزات الجديدة

### 1. ربط توكن إنستاغرام
- في لوحة الأدمن → **التوكنات**: خانتان منفصلتان — فيسبوك وإنستاغرام
- يعرض حالة كل توكن (مفعّل / غير مفعّل + تاريخ آخر تحديث)
- التوكن المطلوب: Facebook User Access Token مع صلاحية `instagram_basic` و `instagram_content_publish`

### 2. جلب الصفحات مفصولة
- في لوحة الأدمن → **الصفحات**: زر "جلب الكل" + زر منفصل لفيسبوك وآخر لإنستاغرام
- الصفحات تُعرض في جدولين: صفحات فيسبوك | حسابات إنستاغرام
- حسابات إنستاغرام تتطلب أن تكون مرتبطة بصفحة فيسبوك (Business/Creator)

### 3. تعيين الصلاحيات مفصول
- في لوحة الأدمن → **الصلاحيات**: قائمتان منفصلتان للتعيين — فيسبوك وإنستاغرام
- الصفحات المُعيّنة تُعرض مجمّعة حسب المنصة مع ألوان مميزة

### 4. لوحة المستخدم مفصولة بمنصتين
**الشريط الجانبي:**
- 📘 ترويج فيسبوك → منشورات فيسبوك + حملات فيسبوك
- 📷 ترويج إنستاغرام → منشورات إنستاغرام + حملات إنستاغرام
- صفحاتي → يعرض الكل مقسّم بأقسام

## 🗄️ قاعدة البيانات — Migration للتحديث
إذا كنت تحدّث نسخة موجودة، نفّذ هذه الأوامر:

```sql
ALTER TABLE admin_tokens ADD COLUMN platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook' AFTER id;
ALTER TABLE admin_tokens ADD UNIQUE KEY uq_platform (platform);
ALTER TABLE pages ADD COLUMN platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook' AFTER access_token;
ALTER TABLE pages ADD COLUMN instagram_id VARCHAR(50) NULL AFTER platform;
ALTER TABLE page_link_requests ADD COLUMN platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook' AFTER whatsapp;
```

## 🆕 تثبيت جديد
استخدم ملف `database.sql` المُدرج كاملاً.
