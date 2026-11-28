# نوع ملفّ تطبيق أبعاد في nginx (`manifest.webmanifest`)

> **الحالة:** تصحيحٌ جاهز ولم يُطبَّق. لا يمسّ الخادمَ أحدٌ قبل موافقة المالك.
> وبعد الموافقة يلصق المالك الكتلة ١ كما هي، ويحتفظ بالكتلة ٢ للرجوع.

## العَرَض

بعد #290 (v6.625) يخدم الخادم ملفّ التطبيق بنوعٍ عامّ:

```
GET https://app.abaadapp.om/brand/abaad/manifest.webmanifest
HTTP/2 200
content-type: application/octet-stream
```

والنوع الصحيح `application/manifest+json`. وقد قبله Chromium كما هو: فحص التثبيت على الإنتاج بلا أخطاء. لكنّ النوع الصحيح أسلم لكلّ متصفّح، ولأيّ تحقّقٍ أشدّ في المستقبل.

## السبب (تُحقِّق منه 2026-11-28 بقراءةٍ فقط)

- الخادم `nginx/1.24.0 (Ubuntu)`، والملفّ يخدمه nginx مباشرةً من `public/` (عليه `etag` و`last-modified`)، لا Laravel.
- `/etc/nginx/mime.types` ليس فيه `webmanifest` (`grep webmanifest` ⇦ لا شيء).
- `/etc/nginx/nginx.conf` السطر 27: `default_type application/octet-stream;`. فما لا يعرفه `mime.types` يُخدم بهذا النوع.
- والصور (`.png`) في `mime.types`، فتُخدم `image/png` صحيحة.

موقع التطبيق في `/etc/nginx/sites-available/abaad`، و`sites-enabled/abaad` رابطٌ إليه. وكتلة `app.abaadapp.om` فيه (السطور 3–38). والموقع التعريفيّ `abaadapp.om` في الملفّ نفسه، في كتلةٍ أخرى لا يمسّها التصحيح.

## التصحيح: موضعٌ واحد لملفٍّ واحد

داخل كتلة `app.abaadapp.om` وحدها، بعد سطر `robots.txt`:

```nginx
    # ملفّ تطبيق أبعاد بنوعه — mime.types في nginx 1.24 لا يعرف .webmanifest
    location = /brand/abaad/manifest.webmanifest {
        types { }
        default_type application/manifest+json;
        try_files $uri =404;
    }
```

- **`location =` تطابق هذا المسار وحده بالحرف.** لا يتغيّر نوع أيّ ملفٍّ آخر، ولا `mime.types` العامّ، ولا كتل الموقع التعريفيّ ومتاجر التجّار.
- **`types { }` تُفرغ الجدول داخل هذا الموضع وحده،** فيُخدم الملفّ بالنوع المكتوب تحتها.
- **`try_files $uri =404`** يخدم الملفّ كما هو، فإن غاب أعاد 404 كما اليوم.
- **لا تغيير في Laravel ولا في الكود،** ولا يلزم نشر.

## ١. التطبيق (بعد الموافقة)

الكتلة تأخذ نسخةً من الملفّ، وتضيف الموضع مرّةً واحدة. ثمّ تفحص الإعداد بـ`nginx -t`، فإن فشل أعادت الملفّ كما كان ولم تُعد تحميل شيء. وإن نجح أعادت تحميل nginx بلا انقطاع وطبعت النوع الجديد.

```bash
set -eu
F=/etc/nginx/sites-available/abaad
B="/root/nginx-abaad.before-manifest-type.$(date +%Y%m%d%H%M%S)"
if grep -q 'manifest.webmanifest' "$F"; then echo "مطبَّقٌ من قبل — لا شيء يُفعل"; exit 0; fi
cp -a "$F" "$B" && echo "نسخة: $B"
python3 - "$F" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding="utf-8").read()
anchor = "    location = /robots.txt  { access_log off; log_not_found off; }\n"
assert s.count(anchor) == 1, "سطر robots.txt ليس كما قُرئ — توقّف ولا تعدّل"
block = (anchor
    + "    # ملفّ تطبيق أبعاد بنوعه — mime.types في nginx 1.24 لا يعرف .webmanifest\n"
    + "    location = /brand/abaad/manifest.webmanifest {\n"
    + "        types { }\n"
    + "        default_type application/manifest+json;\n"
    + "        try_files $uri =404;\n"
    + "    }\n")
open(p, "w", encoding="utf-8").write(s.replace(anchor, block, 1))
PY
if ! nginx -t; then cp -a "$B" "$F"; echo "nginx -t فشل — أُعيد الملفّ كما كان ولم يُعد تحميل شيء"; exit 1; fi
systemctl reload nginx
curl -sI https://app.abaadapp.om/brand/abaad/manifest.webmanifest | grep -i '^content-type'
curl -s -o /dev/null -w 'login %{http_code}\n' https://app.abaadapp.om/
curl -s -o /dev/null -w 'health %{http_code}\n' https://app.abaadapp.om/health
```

**المتوقّع:**
- `content-type: application/manifest+json`
- `login 200`
- `health 200`

## ٢. الرجوع (إن لزم)

تعيد آخر نسخةٍ أُخذت في الكتلة ١، ثمّ تفحص الإعداد وتعيد تحميله.

```bash
set -eu
F=/etc/nginx/sites-available/abaad
B=$(ls -1t /root/nginx-abaad.before-manifest-type.* | head -1)
cp -a "$B" "$F" && echo "أُعيد: $B"
nginx -t && systemctl reload nginx
curl -sI https://app.abaadapp.om/brand/abaad/manifest.webmanifest | grep -i '^content-type'
```

## بعد التطبيق

- فحص التثبيت في Chromium على الإنتاج (`Page.getInstallabilityErrors`) يبقى بلا أخطاء.
- التطبيقات المثبَّتة لا تحتاج إعادة تثبيت: تغيّر النوع، والمحتوى هو هو.

## بديلٌ بلا تعديلٍ على الخادم (لم يُختر)

تسمية الملفّ `manifest.json` بدل `manifest.webmanifest`، فيخدمه nginx بنوع `application/json` الموجود في `mime.types`. والمتصفّحات تقبله. لكنّه تغييرٌ في رابط ملفّ #290 المنشور، والنوع يبقى غير المعيار نفسه. فالتصحيح أعلاه أدقّ وأضيق.
