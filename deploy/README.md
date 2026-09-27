# تهيئة الحذف النهائي للشركات على الخادم

هذا المجلّد يحمل ما يُنسخ إلى الخادم بيدِ من يملكه. ولا شيء هنا يعمل من
نفسه: الحذف النهائي يبقى مغلقًا (`BUSINESS_PURGE_ENABLED=false`) حتى تُنجز
الخطوات كلّها وتُعتمد المراجعة القانونية والمحاسبية.

الفحص بعد كل خطوة:

```bash
php artisan purge:check
```

يقول ما تمّ وما بقي، ويخرج بـ`1` عند أي عائق. ولا يطبع مفتاحًا ولا كلمة سرّ
ولا اسم دلو ولا نقطة وصول — فمخرجه يُلصق في محادثة بلا خطر.

---

## ٠) أين تُحفظ النسخة؟ — طريقان

| | تخزين مستقل (Spaces) | مجلّد على هذا الخادم |
|---|---|---|
| الكلفة | 5 دولار/شهر | صفر |
| عطب القرص أو إعادة بناء الخادم | الأرشيف سالم | **الشركة وأرشيفها يذهبان معًا** |
| الإعداد | §1 أدناه | §1-ب أدناه |

**والفرق ليس نظريًا:** الأرشيف هو الشيء الوحيد الباقي بعد محو الشركة. فإن
ذهب معها لم يبقَ ما يُرجع دفاترها — لا للتاجر، ولا لمفتّش ضريبي بعد سنوات.
ولهذا كان الأصل تخزينًا مستقلًا.

ومن اختار الخادم يكتب ذلك صراحةً (`BUSINESS_PURGE_ALLOW_LOCAL=true`)، فتُقال
الحال بعدها في كل فحص وفي شاشة الأرشيفات. والممنوع أن تقع صامتة.

---

## ١-ب) الطريق الثاني: مجلّد على هذا الخادم

```bash
sudo mkdir -p /var/abaad-purge-copies
sudo chown www-data:www-data /var/abaad-purge-copies
sudo chmod 700 /var/abaad-purge-copies
```

وفي `.env`:

```
BUSINESS_PURGE_OFFSITE_DISK=purge-copy
BUSINESS_PURGE_ALLOW_LOCAL=true
BUSINESS_PURGE_LOCAL_ROOT=/var/abaad-purge-copies
BUSINESS_PURGE_OFFSITE_PREFIX=business-purges
```

ثم `php artisan config:clear && php artisan purge:check`.

- **خارج `/var/www/abaad`** عن قصد: نشرٌ أو `git clean` لا يمسّه.
- ولو كان لك **قرص آخر موصول بالخادم** (Volume) فوجّه `BUSINESS_PURGE_LOCAL_ROOT`
  إليه: ليس استقلالًا، لكنه يسلم من امتلاء قرصٍ أو حذف مجلّد.
- ويُرفض أي مجلّد يحتوي مجلّد الأرشيف أو يقع فيه — تلك نسخة واحدة كُتبت مرتين.
- **وأضِفه إلى نسختك الاحتياطية اليومية** إن أمكن، وإلا فالنسخة والأصل على قرصٍ واحد.

---

## ١) الطريق الأول: مساحة التخزين المستقلة (DigitalOcean Spaces)

**الكلفة:** 5 دولار شهريًا — 250 GiB تخزينًا و1 TiB صادرًا، وما زاد
0.02 دولار/GiB. أرشيف متجر عندنا بالميغابايتات، فالفاتورة 5 دولار عمليًا.

### في لوحة DigitalOcean

1. **Spaces Object Storage → Create Spaces Bucket**
   - المنطقة: `fra1` (فرانكفورت — الأقرب إلى عُمان بين مناطقها الأوروبية).
   - الاسم: أي اسم غير مستعمل، مثل `abaad-purge-archive`.
   - **File Listing: Restrict** — لا Public.
   - **مساحة جديدة خالصة لهذا الغرض.** لا تستعمل مساحة فيها ملفات تجار أو
     نسخ احتياطية أخرى: مفتاح وصول واحد يُقرأ به كل ما في المساحة، وخطأ في
     تنظيف يأخذ الاثنين. و`purge:check` يحذّر إن وجد فيها غير أرشيفنا.
2. **Settings → Versioning: Enable** — يمنع أن يمحو خطأٌ أو اختراقٌ الأرشيف نفسه.
3. **API → Spaces Keys → Generate New Key**
   - اسم مميّز، مثل `abaad-purge`.
   - إن أتاحت اللوحة تحديد المساحة للمفتاح فحدّدها بهذه المساحة وحدها.
   - **يُعرض السرّ مرة واحدة.** انسخه إلى مدير كلمات المرور مباشرة.

### في `.env` على الخادم

```
BUSINESS_PURGE_OFFSITE_DISK=spaces
BUSINESS_PURGE_OFFSITE_PREFIX=business-purges
BUSINESS_PURGE_SPACES_REGION=fra1
BUSINESS_PURGE_SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com
BUSINESS_PURGE_SPACES_BUCKET=<اسم المساحة>
BUSINESS_PURGE_SPACES_KEY=<المفتاح>
BUSINESS_PURGE_SPACES_SECRET=<السرّ>
```

ثم:

```bash
php artisan config:clear && php artisan purge:check
```

الحزمة (`league/flysystem-aws-s3-v3`) منشورة بالفعل — لا تثبيت لاحق.

---

## ٢) مفتاح تشفير الأرشيف

**يُنشأ على جهازك، لا على الخادم**، ولا يُلصق في محادثة:

```bash
openssl rand -base64 32
```

ثم في `.env` على الخادم:

```
BUSINESS_PURGE_ARCHIVE_KEY=<الناتج>
```

- **مستقلّ عن `APP_KEY` إلزامًا.** والكود يرفض تساويهما: `APP_KEY` يُدوَّر
  عند الحاجة وحاضرٌ في كل نسخة من `.env`، وأرشيف عشر سنين يُفكّ بمفتاح
  يجب أن يبقى عشرًا. ولو كانا واحدًا لسقطت النسختان معًا — وتلك هي الحالة
  التي جاء الأرشيف البعيد ليمنعها.
- **نسخة احتياطية خارج الخادم:** مدير كلمات مرور + نسخة ورقية في مكان آمن.
  **فقدانه = فقدان كل أرشيف.** لا باب خلفي، ولا استعادة.
- **لا تحفظه مع مفاتيح Spaces في المكان نفسه.** من يملك الاثنين يقرأ كل شيء.
- للتأكد أن النسخة المحفوظة هي نفسها التي على الخادم: `purge:check` يطبع
  **بصمة** المفتاح (12 خانة من sha256) — تُقارَن بلا أن يُكشف المفتاح.

---

## ٣) عامل الطابور دائمًا

```bash
sudo cp /var/www/abaad/deploy/abaad-queue.service /etc/systemd/system/
sudo cp /var/www/abaad/deploy/abaad-queue-alert@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now abaad-queue
systemctl status abaad-queue
```

ما تضمنه الوحدتان:

| الحاجة | كيف |
|---|---|
| يعود بعد إعادة تشغيل الخادم | `enable` + `WantedBy=multi-user.target` |
| يعود بعد أي سقوط | `Restart=always` مع `RestartSec=5` |
| لا يُقتل في منتصف محو | `KillSignal=SIGTERM` و`TimeoutStopSec=3720` |
| لا يُقتل قبل أن تنتهي مهمّته | `--timeout=3700` أوسع من مهلة المهمّة (3600) |
| لا إعادة تلقائية لمهمّة تمحو | `--tries=1` |
| لا يحتفظ بكود قديم بعد النشر | `--max-time=3600` ثم يعيده systemd |
| تنبيه عند سقوط متكرّر | `OnFailure=` → بريد إلى مديري المنصّة |

تجربة التنبيه بلا انتظار سقوط:

```bash
sudo systemctl start 'abaad-queue-alert@abaad-queue.service'
```

وبعد كل نشر يُعاد العامل ليأخذ الكود الجديد:

```bash
sudo systemctl restart abaad-queue
```

> **أضِف هذا السطر إلى `scripts/deploy.sh` بعد `migrate`** إن أردته تلقائيًا
> — لم أضفه لأن الملف يعمل على خادم الإنتاج وتعديله يحتاج موافقتك.

---

## ٤) اختبار الاستعادة الحقيقي

**قبل فتح الميزة، وفي بيئة معزولة تمامًا — لا قاعدة الإنتاج ولا ملفات تجار.**

1. مساحة Spaces **ثانية للاختبار** بمفتاح آخر. لا مساحة الإنتاج.
2. قاعدة اختبارية وشركة وهمية فيها فواتير واشتراكات ومعاملات ومستخدمون وملفات.
3. شغّل حذفًا كاملًا عليها، ثم:

```bash
# يقرأ ويتحقّق من بصمة كل دفتر ولا يكتب شيئًا
php artisan purge:restore <رقم العملية>

# ويُعيد الدفاتر فعلًا إلى قاعدة فارغة ثم يقارن العدد
php artisan migrate --database=restore_test
php artisan purge:restore <رقم العملية> --into=restore_test
```

`purge:restore` يرفض الكتابة إذا كان `APP_ENV=production`، أو كان الاتصال هو
اتصال الافتراض، أو كان يشير إلى قاعدة الافتراض باسم آخر، أو كان فيه صف واحد.

4. **وفكّ التشفير بالمفتاح من نسختك الورقية لا من الخادم** — هذا وحده يثبت
   أن نسختك الاحتياطية من المفتاح سليمة:

```bash
php artisan purge:restore --archive=/path/الملفّ.zip.enc --keep
```

5. وجرّب الفشل قصدًا: اقطع الشبكة أثناء الرفع، وأتلف بايتًا في الملف البعيد،
   واقتل العامل أثناء الحذف — وتأكّد بعينك أن لا صفًّا مُحي في أيّها.

---

## ٥) ثم التفعيل — وهو قرار لا خطوة

بعد كل ما سبق، **وبعد المراجعة القانونية والمحاسبية** (انظر
`docs/PURGE_RETENTION.md`)، يُفتح الباب بسطر واحد:

```
BUSINESS_PURGE_ENABLED=true
```

```bash
php artisan config:clear
```

وحتى ذلك الحين لا زرّ يُعرض، ولا مسار يُستجاب له، ولو عرف أحدٌ عنوانه.
