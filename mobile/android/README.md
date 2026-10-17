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
