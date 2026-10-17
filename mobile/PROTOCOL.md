# عقد جسر الطابعة — `window.AbaadPrinter` (الإصدار 1)

هذا العقد بين نظام أبعاد (الويب) والتطبيقين الأصليّين (Android وiOS). كلُّ ما
هنا ملزِم للطرفين. والمتّجهاتُ في `shared/fixtures/printing-vectors.json` تُثبته
بايتًا بايتًا: كلُّ تطبيقٍ يمرّ عليها كلِّها في اختباراته.

```
نقطة البيع (React)
   └─ resources/js/lib/printing/printer-service.ts   ← الواجهة الوحيدة في الويب
        └─ resources/js/lib/printing/bridge.ts        ← يجد window.AbaadPrinter ويطبّع ردوده
             └─ mobile/shared/js/abaad-printer-shim.js ← يحقنه التطبيق عند بدء المستند
                  └─ Kotlin (Android) / Swift (iOS)    ← يتحقّق من كلّ شيء من جديد
                       └─ TCP خام → طابعة ESC/POS على الشبكة المحلية
```

## 1. ما يحقنه التطبيق

يحقن التطبيقُ سطرًا يضع `window.__abaadPrinterConfig`، ثم ملفَّ
`shared/js/abaad-printer-shim.js` كما هو. ويجري ذلك عند بدء المستند، وفي الإطار
الرئيسيّ وحده.

| المفتاح | المعنى |
|---|---|
| `platform` | `'android'` أو `'ios'` |
| `trustedOrigins` | قائمة الأصول الموثوقة من **إعداد البناء**، مرمَّزة JSON بأمان. لا من الصفحة، ولا من الخادم |
| `post(json)` | يرسل نصّ الطلب إلى الطرف الأصليّ |
| `listen(receive)` | يسلّم كلَّ ردٍّ أصليّ (نصّ JSON) إلى `receive` |

- **Android:** `WebViewCompat.addWebMessageListener(webView, "AbaadPrinterNative", trustedOriginRules, listener)` و`WebViewCompat.addDocumentStartJavaScript(webView, prelude + shim, trustedOriginRules)`.
  - `post = s => AbaadPrinterNative.postMessage(s)`.
  - `listen = r => { AbaadPrinterNative.onmessage = e => r(e.data) }`.
  - الردّ عبر `JavaScriptReplyProxy.postMessage(json)` **على خيط الواجهة**.
- **iOS:** `WKUserScript(source: prelude + shim, injectionTime: .atDocumentStart, forMainFrameOnly: true)` و`WKScriptMessageHandler` باسم `abaadPrinter`.
  - `post = s => window.webkit.messageHandlers.abaadPrinter.postMessage(s)`.
  - `listen = r => { window.__abaadPrinterReceive = r }`.
  - الردّ عبر `callAsyncJavaScript("window.__abaadPrinterReceive(m)", arguments: ["m": json], in: nil, in: .page)`، ولا يُبنى نصُّ JavaScript بلصق القيم.

## 2. الواجهة كما تراها الصفحة

```ts
window.AbaadPrinter: {
  version: 1;
  platform: 'android' | 'ios';
  isAvailable(): boolean;                                   // متزامنة: الأصل موثوق والإطار رئيسيّ
  printReceipt(job: ReceiptJob, onProgress?: (stage) => void): Promise<BridgeResult>;
  testPrinter(job: TestJob, onProgress?: (stage) => void): Promise<BridgeResult>;
  openReceipt(job: OpenJob): Promise<BridgeResult>;         // حوار الطباعة/المعاينة في النظام
}
```

لا توجد دالّةٌ تقبل بايتات أو أوامر ESC/POS أو عنوانًا خامًا لمقبس. الطلباتُ
مُهيكلة وحدها.

## 3. الرسائل

**من الصفحة إلى الطرف الأصليّ** (نصّ JSON):

```json
{ "v": 1, "id": "j…-1", "method": "printReceipt" | "testPrinter" | "openReceipt", "payload": { … } }
```

**من الطرف الأصليّ إلى الصفحة** (نصّ JSON):

```json
{ "id": "j…-1", "type": "progress", "stage": "downloading_receipt" | "rendering" | "connecting" | "sending" }
{ "id": "j…-1", "type": "result", "result": BridgeResult }
```

- يُرفض `v` ≠ 1، و`id` غير نصّيّ أو أطول من 64 حرفًا، و`method` مجهول.
  - الردّ `INVALID_JOB` إن أمكن الردّ، وإلّا تُهمل الرسالة.
- لكلّ `id` ردُّ `result` واحد بالضبط.

### `ReceiptJob` (لـ `printReceipt`)

```json
{
  "invoice": "INV-000123",
  "receiptUrl": "https://<الأصل>/pos/receipt/INV-000123/pdf?expected_business_id=3",
  "printer": PrinterTarget
}
```

### `OpenJob` (لـ `openReceipt`)

```json
{ "invoice": "INV-000123", "receiptUrl": "https://…" }
```

يُنزَّل الـPDF بجلسة التطبيق، ثم يُعرض في حوار الطباعة الخاصّ بالنظام:
- Android: `PrintManager` مع محوّلٍ لملف PDF.
- iOS: `UIPrintInteractionController`.

هذا بديلُ «فتح PDF» داخل التطبيق، لأنّ النوافذ المنبثقة لا تعمل فيه.

### `TestJob` (لـ `testPrinter`)

```json
{ "printer": PrinterTarget, "deviceName": "الصندوق 1", "printerName": "طابعة الكاشير" }
```

- `deviceName` و`printerName` نصّان اختياريّان، يُقصّان إلى 60 حرفًا.
- يرسم الطرفُ الأصليّ صفحةَ الاختبار بنفسه (§6).

### `PrinterTarget`

```json
{ "id": 7, "name": "…", "model": "…" | null, "connection": "network", "address": "192.168.1.50", "port": 9100 | null, "paperWidth": 80 | 58 | null }
```

## 4. التحقّق في الطرف الأصليّ (إلزاميّ — قبل أيّ تنزيلٍ أو اتّصال)

1. **الأصل:** لا يُقبل الطلب إلّا إذا تحقّق الشرطان:
   - الصفحة الحاليّة في الإطار الرئيسيّ.
   - أصلُها ضمن `trustedOrigins`.

   على Android: `isMainFrame` و`sourceOrigin` من المستمع. على iOS: `message.frameInfo.isMainFrame` و`message.frameInfo.securityOrigin`، ومعهما `webView.url`.

   وإلّا: `UNTRUSTED_ORIGIN`.
2. **`connection`:** يجب أن تكون `"network"`، وإلّا `UNSUPPORTED_CONNECTION`.
3. **`address`** بعد قصّ المسافات — انظر `address` في المتّجهات:
   - **IPv4 حرفيّ خاصّ:**
     - `10/8`، `172.16/12`، `192.168/16`، `169.254/16`.
     - أربعة أجزاء عشريّة بلا أصفار بادئة، كلٌّ منها 0–255.
   - **IPv6 حرفيّ خاصّ:** ULA `fc00::/7` أو link-local `fe80::/10`.
   - **اسم mDNS ينتهي بـ`.local`:**
     - تسميات `[a-z0-9-]`، لا تبدأ ولا تنتهي بـ`-`، ولا تسمية فارغة.
     - يُحلّ الاسم، ويجب أن يمرّ **كلُّ** عنوانٍ ناتج بالفحص الخاصّ.
     - ثم يُتّصل بالعنوان الذي فُحص، لا بالاسم من جديد.
   - **غير ذلك يُرفض بـ`INVALID_PRINTER_ADDRESS`**، ومنه: العامّ، و`127/8`، و`0.0.0.0`، والبثّ، والمتعدّد (`224/4`)، و`::1`، و`::`، والأسماء العامّة، و`localhost`، والمنفذ أو المسار داخل العنوان.
4. **`port`:**
   - `null` يعني المنفذ الافتراضيّ الموثَّق للطباعة الخام، **9100**.
   - وإلّا عددٌ صحيح من JSON بين 1 و65535.
   - النصّ والعشريّ والمنطقيّ تُرفض بـ`INVALID_PORT`.
   - المنفذ الفعليّ يُعاد في كلّ نتيجة (`effectivePort`)، حتى لا يُدّعى أنّ كلّ الطابعات على 9100.
5. **`receiptUrl`:**
   - مطلق، وبلا `user:pass@`.
   - أصلُه بعد التطبيع يساوي أصلَ الصفحة الحاليّة، وموجود في `trustedOrigins`.
     - التطبيع: المخطّط والمضيف بأحرف صغيرة، والمنفذ الافتراضيّ صريح.
   - وإلّا: `RECEIPT_URL_REJECTED`. انظر `receiptUrl` في المتّجهات.
6. **`paperWidth`:** يُحوَّل إلى عرض الطباعة بالنقاط، وتعريفه في مكانٍ واحد في كلّ منصّة (`PaperProfile`):
   - `58` → 384.
   - `80` → 576.
   - أيُّ قيمة أخرى → 576.

## 5. مسار `printReceipt`

| # | المرحلة | ما يحدث |
|---|---|---|
| 1 | `downloading_receipt` | تنزيل `receiptUrl` (تفاصيله تحت الجدول) |
| 2 | `rendering` | رسم صفحات الـPDF كلّها وتكديسها رأسيًّا. عرض البتماب = عرض الطباعة بالنقاط، والارتفاع بالنسبة نفسها |
| 3 | (لا مرحلة) | التحويل إلى أحاديّ اللون (تفاصيله تحت الجدول) |
| 4 | `connecting` | TCP إلى العنوان المفحوص والمنفذ الفعليّ، مع مهلة اتّصال 5 ثوانٍ |
| 5 | `sending` | إرسال البايتات (§7) بمهلة كتابة 30 ثانية، ثم إغلاق نظيف |

**تفاصيل التنزيل (الخطوة 1):**
- **الكوكيز:** يحمل الطلب كوكيز جلسة الـWebView، ولا يُسجَّل منها شيء.
  - Android: `CookieManager.getCookie(url)`.
  - iOS: `WKHTTPCookieStore.getAllCookies` مصفّاة بالمضيف، ثم `HTTPCookie.requestHeaderFields`.
- **الطلب:** `Accept: application/pdf`، ومهلة 20 ثانية، وسقف 10MB، و**بلا اتّباع للتحويلات**.
- **الردود:**
  - 401 أو 419 أو 3xx → `SESSION_EXPIRED`.
  - أيّ رمزٍ آخر غير 2xx، أو جسمٌ لا يبدأ بـ`%PDF` → `RECEIPT_DOWNLOAD_FAILED`.

**تفاصيل الرسم والتحويل (الخطوتان 2 و3):**
- تُملأ الخلفيّة بالأبيض قبل الرسم، لأنّ PdfRenderer يرسم شفافًا.
- الارتفاع الكليّ أقصاه 40000 صفّ، وما زاد → `RECEIPT_RENDER_FAILED`.
- السطوع = ⌊(299R + 587G + 114B) / 1000⌋، والنقطة سوداء إن كان السطوع أقلّ من **160**. لا تنعيم ولا تشويش، فالنصّ ورمز QR أوضح بالعتبة.

**ما بعد الإرسال:**
- النجاح: `{ ok: true, jobId, printed: true, connected: true, effectivePort }`.
- **لا إعادة تلقائيّة أبدًا** بعد أن تبدأ الكتابة: قد يكون جزءٌ وصل الطابعة.
  - الفشل بعد بدء الكتابة يُعاد `SEND_FAILED` مع `mayHavePrinted: true`، ويقرّر الكاشير الإعادة بنفسه.
- طابور بمهمّة واحدة في كلّ وقت.
  - تنتظر خلفها مهمّتان على الأكثر، وما زاد → `QUEUE_FULL`.
- **مهلة بدء الاتّصال** (`ConnectDeadline`)، محسوبة من لحظة قبول الطلب:
  - 75 ثانية للإيصال، و15 لصفحة الاختبار.
  - مهمّة لم تبلغ خطوة الاتّصال قبلها لا تتّصل أبدًا، وتردّ `QUEUE_FULL`.
  - السبب: الـshim يكفّ عن الانتظار بعد 120 ثانية (60 للاختبار)، والاتّصال والإرسال قد يأخذان 35 ثانية. فلا يخرج إيصال بعد أن قيل للكاشير «لم يصل ردّ»، ولا تصير «إعادة الطباعة» إيصالًا ثانيًا.
  - يُختبر في المنصّتين أنّ المهلة مع الاتّصال والإرسال أقلّ من مهلة الـshim، والقيمة تُقرأ من ملفّ الـshim نفسه.

## 6. صفحة الاختبار (`testPrinter`)

- **الخطوات:** يُفحص الهدف (§4)، ثم `connecting`، ثم `sending`.
- **المحتوى:** يرسم الطرف الأصليّ النصّ بنفسه بخطّ النظام وباتّجاه RTL على عرض الطباعة، ثم يمرّ بالمسار نفسه (§5 الخطوتان 3 و5):

```
              أبعاد
          اختبار الطابعة
اسم الجهاز: <deviceName>
الطابعة: <printerName>
IP: <address>
Port: <effectivePort>
الورق: 80mm (576 نقطة)
التاريخ والوقت: yyyy-MM-dd HH:mm:ss   ← وقت الجهاز
إن قرأت هذا فالطابعة جاهزة.
```

- **النتيجة:**
  - النجاح: `{ ok: true, printed: true, connected: true, effectivePort }`.
  - إن تمّ الاتّصال وفشل الإرسال: `{ ok: false, code: 'SEND_FAILED', connected: true, … }`. فتعرض الواجهة «تم الاتصال بالطابعة»، ثم سببَ الفشل.

## 7. بايتات ESC/POS (والمتّجهات `escpos` تُثبتها)

```
ESC @                       1B 40                       تهيئة
GS v 0 m xL xH yL yH d…     1D 76 30 00 xL xH yL yH …   لكلّ شريطٍ من bandHeight صفًّا
ESC d n                     1B 64 n                     تغذية n أسطر (إن n > 0)
GS V 66 0                   1D 56 42 00                 تغذية ثم قصّ جزئيّ (إن cut)
```

- **الصفوف:** كلّ صفّ يُحزم في ⌈width / 8⌉ بايتًا.
  - البتّ الأعلى هو أقصى اليسار، و1 = نقطة سوداء.
  - البتّات الزائدة في آخر بايت بيضاء (0).
- **x وy:** `x` = عدد بايتات الصفّ، و`y` = عدد صفوف الشريط، كلاهما little-endian.
- **الصفوف البيضاء:** تُحذف من الآخر قبل الترميز (`trimTrailingBlank`). وإن لم يبقَ صفّ لا يُرسل أمرُ صورة أصلًا.
- **القيم الافتراضيّة للإيصال:**
  - `bandHeight = 128`.
  - `feedLines = 4`.
  - `cut = true`. الطابعة بلا قاطع تتجاهل `GS V`.
  - `trimTrailingBlank = true`.

## 8. `BridgeResult`

```ts
{ ok: true;  jobId: string; printed?: boolean; connected?: boolean; effectivePort?: number; message?: string }
{ ok: false; code: ErrorCode; message: string; jobId?: string; connected?: boolean; mayHavePrinted?: boolean; effectivePort?: number }
```

| `code` | متى | الرسالة العربيّة |
|---|---|---|
| `UNTRUSTED_ORIGIN` | الصفحة أو الإطار ليسا موثوقين | طلب طباعة مرفوض |
| `INVALID_JOB` | الرسالة أو الحمولة لا تطابق العقد | طلب طباعة مرفوض |
| `RECEIPT_URL_REJECTED` | رابط الإيصال خارج الأصل | طلب طباعة مرفوض |
| `UNSUPPORTED_CONNECTION` | الاتّصال ليس `network` | الطباعة المباشرة تدعم طابعات الشبكة فقط حاليًا |
| `INVALID_PRINTER_ADDRESS` | العنوان ليس على الشبكة المحليّة | عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية |
| `INVALID_PORT` | المنفذ مرفوض | رقم المنفذ غير صالح |
| `SESSION_EXPIRED` | 401 أو 419 أو تحويل | انتهت الجلسة — سجّل الدخول من جديد |
| `RECEIPT_DOWNLOAD_FAILED` | شبكة، أو رمز HTTP، أو ليس PDF | تعذر تنزيل الإيصال |
| `RECEIPT_RENDER_FAILED` | فشل الرسم أو تجاوز الطول | تعذر تجهيز الإيصال للطباعة |
| `PRINTER_UNREACHABLE` | EHOSTUNREACH، ENETUNREACH، فشل حلّ الاسم، أو منع الشبكة المحليّة | تعذر الوصول إلى عنوان الطابعة |
| `PORT_REFUSED` | ECONNREFUSED | المنفذ لا يستجيب |
| `CONNECT_TIMEOUT` | انقضت 5 ثوانٍ دون اتّصال | انتهت مهلة الاتصال |
| `SEND_FAILED` | خطأ أو مهلة بعد بدء الكتابة | انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة |
| `QUEUE_FULL` | أكثر من مهمّتين تنتظران | الطابعة مشغولة — حاول بعد لحظات |
| `INTERNAL` | ما سوى ذلك | تعذرت الطباعة |

ويضيف الـshim من جهته رمزين:
- `BRIDGE_TIMEOUT`: لا ردّ في المهلة، و`mayHavePrinted` = true للطباعة.
- `BRIDGE_UNAVAILABLE`: فشل `post`.

## 9. السجلّات

- **يُسجَّل:**
  - `jobId`، والطريقة، والمراحل، والمدد.
  - رمز الخطأ، و`host:port` الطابعة.
  - عدد البايتات.
  - **مسار** الرابط بلا استعلام.
- **لا يُسجَّل أبدًا:**
  - الكوكيز، ورؤوس الطلب.
  - الاستعلام (`?…`).
  - محتوى الإيصال أو بايتاته.

## 10. التنقّل في الصدفة

- **أصل موثوق:** يُحمَّل داخل الـWebView.
- **أيّ رابطٍ آخر:** يُفتح في المتصفّح الخارجيّ، ولا يُحمَّل في الـWebView أبدًا.
- **`window.open` أو `target=_blank` إلى أصلٍ موثوق:**
  - يُنزَّل بالجلسة نفسها.
  - إن كان PDF يُعرض في حوار طباعة النظام كما في `openReceipt`.
  - وإلّا يُحمَّل في الـWebView الرئيسيّة.
