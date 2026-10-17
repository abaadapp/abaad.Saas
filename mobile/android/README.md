# أبعاد — تطبيق Android

صدفةٌ أصليّة حول نظام أبعاد (WebView واحدة)، ومعها جسرُ الطباعة المباشرة على
طابعات ESC/POS في الشبكة المحليّة. العقدُ كاملًا في [`../PROTOCOL.md`](../PROTOCOL.md).

## البناء

لا يوجد `gradlew` هنا: افتح `mobile/android` في Android Studio، أو استعمل Gradle 8.11+
مع JDK 17 وAndroid SDK 35:

```sh
gradle -p mobile/android assembleDebug -Pabaad.baseUrl=https://<your-abaad-host>
gradle -p mobile/android testDebugUnitTest
```

| الخاصيّة | المعنى |
|---|---|
| `abaad.baseUrl` | الصفحةُ الأولى. بلا قيمةٍ يبني التطبيق ويعرض «لم يُضبط عنوان أبعاد في إعداد البناء» |
| `abaad.trustedOrigins` | أصولٌ موثوقة مفصولة بفواصل. فارغة ← أصلُ `abaad.baseUrl` وحده |

تُقرأ من `-P…` أوّلًا، ثم من `mobile/android/local.properties` (انظر
`local.properties.example`). لا نطاقَ ولا عنوانَ طابعةٍ مكتوبٌ في الكود.

## الهويّة

| | |
|---|---|
| `applicationId` | `om.abaad.pos` |
| الاسم تحت الأيقونة | «Abaad POS»، و«أبعاد POS» على الأجهزة العربيّة (`res/values-ar`) |
| الأذونات | `INTERNET` و`ACCESS_NETWORK_STATE` وحدهما |
| أدنى إصدار | Android 8.0 (API 26)، مع WebView محدَّث |

لا يحتاج TCP إلى الشبكة المحليّة إذنًا خاصًّا على `targetSdk 35`.

## APK للتجربة (debug)

**الطريق الأسهل، بلا أدوات على جهازك:**

1. في GitHub: Settings ← Secrets and variables ← Actions ← Variables.
2. أضف المتغيّر `ABAAD_POS_BASE_URL`، مثلًا `https://app.abaadapp.om`.
   - هذا ليس سرًّا: هو العنوان الذي يفتحه المتصفّح.
3. أعد تشغيل وظيفة **Mobile** على الطلب.
4. نزّل الملحق `abaad-pos-debug-apk` من صفحة التشغيل، وفيه `app-debug.apk`.

**أو محلّيًّا:**
- **المتطلّبات:** Android Studio (يفتح `mobile/android` مباشرةً)، أو JDK 17 وAndroid SDK 35 وGradle 8.11+.
- **الأمر:**

```sh
gradle -p mobile/android assembleDebug -Pabaad.baseUrl=https://app.abaadapp.om
# الناتج: mobile/android/app/build/outputs/apk/debug/app-debug.apk
```

**التثبيت على الجهاز اللوحيّ:**
- `adb install -r app-debug.apk` بعد تفعيل «تصحيح USB».
- أو انسخ الملفّ إلى الجهاز وافتحه بعد السماح بـ«تثبيت تطبيقات غير معروفة» لمدير الملفّات.
- النسخة الـdebug موقّعة بمفتاح التطوير تلقائيًّا، ولا تصلح للنشر.

## توقيع الإصدار (release)

لا مفتاح ولا كلمة سرّ في المستودع. يتجاهل `.gitignore` ملفّات `*.jks` و`*.keystore` و`keystore.properties`.

1. **أنشئ المفتاح مرّة واحدة، خارج المستودع، واحفظه ونسخةً احتياطيّة منه:**
   - فقدانه يمنع تحديث التطبيق المنشور.

```sh
keytool -genkeypair -v -keystore ~/keys/abaad-pos-release.jks -alias abaad-pos \
  -keyalg RSA -keysize 4096 -validity 10000
```

2. **ضع القيم في `~/.gradle/gradle.properties`** (خارج المستودع)، أو مرّرها بـ`-P…`:

```properties
abaad.signing.storeFile=/Users/<you>/keys/abaad-pos-release.jks
abaad.signing.storePassword=…
abaad.signing.keyAlias=abaad-pos
abaad.signing.keyPassword=…
```

3. **ابنِ الإصدار:**

```sh
gradle -p mobile/android assembleRelease -Pabaad.baseUrl=https://app.abaadapp.om   # APK
gradle -p mobile/android bundleRelease  -Pabaad.baseUrl=https://app.abaadapp.om   # AAB لـGoogle Play
```

- بلا `abaad.signing.storeFile` يُبنى الإصدار غير موقّع.
- ارفع `versionCode` في `app/build.gradle.kts` مع كلّ نسخة تُنشر.

## ما فيه

- `MainActivity` — الـWebView وقواعدُ التنقّل (PROTOCOL §10): الموثوقُ داخلها، وما سواه
  في المتصفّح، و`window.open` إلى أصلٍ موثوق يُنزَّل بالجلسة ويُفتح PDF في حوار الطباعة.
- `printing/AbaadPrinterBridge` — يحقن `mobile/shared/js/abaad-printer-shim.js` (يُشحن
  asset كما هو) ويستقبل الطلبات. لا يُثبَّت إلّا إن دعمت الـWebView
  `WEB_MESSAGE_LISTENER` و`DOCUMENT_START_SCRIPT`؛ وإلّا تعود الصفحة إلى طباعة المتصفّح.
- `printing/*` الباقي — الفحص (`JobValidator`، `PrinterTarget`، `TrustedOrigins`)،
  والرسم (`PdfReceiptRenderer`، `TestReceiptRenderer`، `MonochromeRaster`)،
  والترميز (`EscPosEncoder`)، والإرسال (`NetworkPrinterDriver`)، والطابور (`PrintJobQueue`).

## الاختبارات

اختباراتُ JVM في `app/src/test` تمرّ على كلّ متّجهٍ في
`mobile/shared/fixtures/printing-vectors.json` — وهي نفسُها التي يمرّ عليها iOS.
