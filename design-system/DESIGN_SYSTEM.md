# نظام تصميم أبعاد — Abaad Design System

> مرجعٌ مستخرَجٌ من مشروع `abaad.Saas` كما هو. لا إعادة تصميم، ولا تحسين، ولا قيمة مقدَّرة:
> كل رقمٍ ولونٍ هنا منقولٌ من الشيفرة، وحيث اختلفت الشاشات وُثِّق الاختلاف وحُدِّد الأكثر
> استعمالًا بالعدّ لا بالرأي.

**الملفّات المرافقة:** [`design-tokens.json`](./design-tokens.json) · [`COMPONENTS.md`](./COMPONENTS.md) · [`IMPLEMENTATION_GUIDE.md`](./IMPLEMENTATION_GUIDE.md)

---

## ٠· الهويّة في سطر

نظامٌ **أحاديّ اللون، مستوحًى من Apple**: خلفيةٌ رماديّة فاتحة، أسطحٌ بيضاء، حدودٌ رفيعة
بلونٍ واحد، ظلالٌ تكاد لا تُرى، وحبرٌ أسود (`#111111`) هو اللكنة. اللون لا يُستعمل للزينة —
يُستعمل للحالة وحدها.

**اتّجاه المستند RTL افتراضًا**، والتخطيط كلّه بخصائصٍ منطقيّة (`start`/`end`, `ms`/`me`,
`ps`/`pe`, `border-e`) فينقلب مع اللغة بلا شيفرةٍ إضافيّة.

### القرار الحاكم
في `@theme` مقياسان كاملان لـ`primary` (بنفسجي) و`secondary` (وردي)، **لكنّ اللكنة الفعليّة
في الواجهة سوداء**. صُرِّح بذلك في `app.css`:

```css
/* اللكنة سوداء كما في التصميم الحالي، لا بنفسجية */
--primary: #111111;
```

البنفسجي يظهر في ثلاثة مواضع فقط: شارة `primary`، ونغمة `StatCard`، وخطّ المخطّط الزمني.

---

## ١· Design Tokens

القيم كاملةً في [`design-tokens.json`](./design-tokens.json). هذا ملخّصها.

### ١٫١ الألوان الأساسية

| الرمز | القيمة | الاستعمال |
|---|---|---|
| `ink` | `#111111` | النصّ الأساسي · اللكنة · خلفية الزرّ الأساسي — **٣٧١ موضعًا** |
| `black` | `#000000` | `:active` للزرّ الأساسي وحده |
| `white` | `#ffffff` | البطاقة · النافذة · الشريط الجانبي · الترويسة |

### ١٫٢ الخلفيات

| الرمز | القيمة | الاستعمال |
|---|---|---|
| `app` | `#f7f8f9` | خلفية اللوحة (`--ui-bg`) |
| `bodyGlobal` | `#f4f5f7` | `<body>` خارج `.admin-ui` — **اختلاف موثَّق** |
| `surface` | `#ffffff` | كل سطحٍ مرتفع |
| `surfaceMuted` | `#fafafa` | تحويم صفّ الجدول · قدم البطاقة · الحقل المعطَّل |
| `surfaceSubtle` | `#f2f2f0` | الشريحة · الشارة المحايدة · تحويم القائمة · الرابط النشط |
| `surfaceSubtleHover` | `#e9e9e6` | تحويم زرّ `subtle` |

### ١٫٣ النصوص

| الرمز | القيمة | الاستعمال | التكرار |
|---|---|---|---|
| `primary` | `#111111` | العناوين والقيم | ٣٧١ |
| `secondary` | `#374151` | تحويم التبويب | ٢١ |
| `body` | `#4b4b4b` | التسميات · نصّ `ghost` · الشارة المحايدة | ٩٤ |
| `muted` | `#6b7280` | العنوان الفرعي · رأس الجدول | ١٩٩ |
| `faint` | `#9ca3af` | التلميح · placeholder · الحالة الفارغة | **٢٥٢** |

### ١٫٤ الحدود

| الرمز | القيمة | الاستعمال |
|---|---|---|
| `default` | `#e8e8e8` | الحدّ القياسي — البطاقة والحقل والجدول والفواصل |
| `strong` | `#dcdcdc` | زرّ `outline` |
| `focus` | `#d1d5db` | الحدّ عند التركيز · مسار مفتاح التبديل المطفأ |

قاعدةٌ عامّة في `@layer base`: `* { border-color: var(--border) }`.

### ١٫٥ ألوان الحالات

| الحالة | الخلفية | النصّ | المصمت | النقطة |
|---|---|---|---|---|
| **Success** | `#ecfdf5` | `#047857` | `#059669` | `#059669` |
| **Warning** | `#fffbeb` | `#d97706` / `#b45309` | `#f59e0b` | `#d97706` |
| **Error** | `#fef2f2` | `#b91c1c` | `#dc2626` | `#dc2626` |
| **Info** | `#eff6ff` | `#2563eb` | `#3b82f6` | `#2563eb` |
| **Neutral** | `#f2f2f0` | `#4b4b4b` | — | `#9ca3af` |

> **قاعدة النقطة**: نقطة الحالة تأخذ **نغمة النصّ** لا الخلفية. الخلفية (`#ecfdf5`) فاتحةٌ
> جدًّا، ونقطةٌ بقياس ٦ بكسل بها لا تكاد تُرى على أبيض. مصدرها `statusDot()` في `ui/badge.tsx`.

### ١٫٦ Typography

**الخطّان:**
```
عام:   'IBM Plex Sans Arabic', 'Tajawal', 'Cairo', ui-sans-serif, system-ui, sans-serif
اللوحة: 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, 'SF Pro Text',
        'SF Pro Display', 'Segoe UI', system-ui, sans-serif
```

**تباعد الأحرف** — جزءٌ أساسيّ من الهويّة:
- الجسم: `-0.011em`
- العناوين `h1,h2,h3`: `-0.021em`
- نقطة البيع: `-0.01em`

**المقاسات** — النظام يقيس **بالبكسل الصريح** لا بمقياس Tailwind الاسمي:

| المقاس | الاستعمال | التكرار |
|---|---|---|
| `10px` | تسميات محور المخطّط | — |
| `11px` | عنوان مجموعة الشريط الجانبي | ٣٣ |
| `12px` | التلميح · الخطأ · رأس الجدول · الشارة · الترقيم | **٢١٣** |
| `13px` | التسمية · العنوان الفرعي · وصف البطاقة · الشريحة | **١٥٤** |
| `14px` (`text-sm`) | نصّ الجسم | ١٨٢ |
| `15px` | `CardTitle` · زرّ `lg` | ١٥ |
| `17px` | `DialogTitle` | ١٠ |
| `18px` | عناوين أقسام كبيرة | ١١ |
| `20px` | قيمة `StatCard` | ١٣ |
| `22px` | عنوان الصفحة (`PageHeader h1`) | ٩ |
| `28px` | أرقام بارزة نادرة | ٤ |

> **قاعدة اللمس**: `@media (hover: none) and (pointer: coarse)` يرفع كلّ `input`/`select`/
> `textarea` إلى **16px** بـ`!important`. السبب ليس القياس بل الموضع: سفاري على iOS يُكبّر
> الصفحة عند حقلٍ خطُّه أدنى من ١٦، ولا يعود لمقاسه بعد إغلاق لوحة المفاتيح.

**الأوزان:** `500` (١٨٤) · `700` (١٦١) · `600` (٨٠) · `400` (٤). وفي CSS القديم وزنان
غير قياسيّين: `550` في `.ui-btn` و`.ui-chip`، و`650` في `.ui-nav-link.is-active`.

**Line height:** لا مقياس مخصَّص — افتراضي Tailwind، إلا `leading-[14px]` في محور المخطّط
و`line-height: 1` في `.ui-btn`. والأدوات المستعملة: `leading-relaxed`, `leading-snug`.

### ١٫٧ Spacing

مقياس Tailwind الافتراضي (`0.25rem` = 4px). التكرار الفعليّ:

`gap-2` (٢١٠) › `gap-3` (١٢٢) › `gap-4` (١٠٣) › `gap-1` (٤٥) › `gap-1.5` (٤٣) › `gap-6` (١٧)

**الحشو حسب السياق:**

| الموضع | القيمة |
|---|---|
| حاوية الصفحة | `p-4 lg:p-6` |
| بطاقة مؤشّر | `p-4` |
| `CardHeader`/`Content`/`Footer` | `p-5` (والمحتوى `pt-0`) |
| بطاقة نموذج | `p-6` |
| ترويسة بطاقة بحدّ | `px-5 py-4` |
| شريط أدوات الجدول | `px-4 py-2` |
| خليّة الجدول | `px-4 py-3` · رأسه `h-11 px-4` |
| بند القائمة المنسدلة | `px-3 py-2` (الحاوية `p-1.5`) |
| رابط الشريط الجانبي | `8px 12px` |
| الترويسة | `px-4 lg:px-6` |

**الإيقاع الرأسيّ:** `PageHeader` → `mb-5` · `SectionTabs` → `mb-6` · داخل `Field` →
`space-y-1.5` · بين أقسام النموذج → `space-y-4` أو `space-y-6`.

### ١٫٨ Border Radius

| الاسم | القيمة | الاستعمال | التكرار |
|---|---|---|---|
| `sm` | `8px` | بند القائمة · زرّ الترقيم · إغلاق النافذة | ٢٦ |
| `md` | `10px` | **الزرّ · الحقل · Textarea · SelectTrigger** | ٤٦ |
| `lg` | `12px` | محتوى القائمة · التنبيه المضمّن · أيقونة StatCard | **٧٥** |
| `xl` | `14px` | بطاقة الشعار | ٩ |
| `2xl` | `16px` | **البطاقة والنافذة** (`--ui-radius`) | ١١ |
| `full` | `9999px` | الشارة · الشريحة · المفتاح · الصورة الرمزية | **٨٤** |

متغيّرات: `--ui-radius: 16px` · `--radius-ui: 16px` · `--radius: 12px`.

### ١٫٩ Shadows

ظلالٌ **بلون الحبر لا بالأسود الصافي**، وخافتةٌ جدًّا:

```css
card         0 1px 2px rgba(0,0,0,0.04)          /* مكوّن Card */
cardCss      0 1px 2px rgba(17,17,17,0.035)      /* .ui-card */
cardHover    0 8px 30px -12px rgba(17,17,17,0.14)
medium       0 6px 24px -8px rgba(17,17,17,0.10)
large        0 20px 48px -16px rgba(17,17,17,0.16)
dropdown     0 8px 30px rgba(0,0,0,0.10)
dialog       0 20px 60px rgba(0,0,0,0.15)
focusField   0 0 0 3px rgba(0,0,0,0.05)
```

### ١٫١٠ Opacity

`disabled` على الأزرار → `0.5` · على الحقول → `0.6` · على `Label` → `0.7`
طبقة النافذة → `bg-black/20` مع `backdrop-blur-sm`
الترويسة → `bg-white/95` وتنزل إلى `bg-white/80` حين يُدعم `backdrop-filter`

### ١٫١١ Focus Ring

حلقتان — عامّة وخاصّة:

```css
/* @layer base — لكل عنصر تفاعلي لا يعرّف حلقته */
outline: 2px solid var(--ring, rgba(17,17,17,0.35));
outline-offset: 2px;

/* --ring داخل النطاق */
--ring: rgba(17, 17, 17, 0.12);
```

- **الزرّ**: `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)]`
- **الحقل**: `focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none`

> `outline` لا `border`: تُرسم خارج الصندوق فلا تُزيح شيئًا، بخلاف `border` الذي يغيّر الأبعاد.

### ١٫١٢ Breakpoints

قيم Tailwind v4 الافتراضية **بلا تخصيص**. الاستعمال الفعليّ بالعدّ:

| النقطة | القيمة | التكرار | الدور |
|---|---|---|---|
| `sm` | `640px` | **١٥٢** | جوال → لوحي: صفٌّ بدل عمود، عمودان في الشبكة |
| `md` | `768px` | ٣٠ | شبكات النماذج |
| `lg` | `1024px` | ٧١ | **الحدّ الفاصل للشريط الجانبي** |
| `xl` | `1280px` | ٦ | نادرة |
| `2xl` | `1536px` | **٠** | غير مستعملة إطلاقًا |

و`max-lg` مرّتين، لإزاحة الشريط الجانبي على الجوال وحده.

### ١٫١٣ Container Widths

| الاسم | القيمة | الموضع |
|---|---|---|
| سقف المحتوى | `1600px` | `<main>` — سقفٌ واحد للّوحة كلّها |
| الشريط الجانبي | `256px` (`w-64`) | والهامش `lg:ms-64` |
| الترويسة | `64px` (`h-16`) | وكذلك رأس الشريط الجانبي |
| نموذج إنشاء/تعديل | `896px` (`max-w-4xl`) | |
| نموذج إعدادات | `768px` (`max-w-3xl`) | |
| نافذة قياسيّة | `512px` (`max-w-lg`) | والتأكيد `max-w-sm` |
| حقل البحث في الجدول | `sm:max-w-[18rem]` | |

> **قاعدة**: السقف في الحاوية لا في الصفحة. «كانت الإعدادات وحدها تضع سقفًا لنفسها وبقيّة
> الصفحات تمتدّ بلا حدّ، فيشعر المستخدم أن النظام ينكمش حين ينتقل إليها.»

---

## ٢· Layout System

### ٢٫١ الهيكل العام

```
<div class="admin-ui min-h-dvh">
  <ImpersonationBar/>                    ← شريط علويّ شرطيّ، يضبط --chrome-top
  <Sidebar/>                             ← fixed · w-64 · start-0 · z-40
  <div class="lg:ms-64">
    <Topbar/>                            ← sticky top-[var(--chrome-top,0px)] · h-16 · z-30
    <motion.main class="mx-auto w-full max-w-[1600px] p-4 lg:p-6">
      <SubscriptionBanner/>
      {children}                         ← محتوى الصفحة
    </motion.main>
  </div>
  <Toaster position="bottom-center" richColors/>
</div>
```

- `min-h-dvh` لا `min-h-screen`: على iPad يقيس `vh` شاشةً بشريط سفاري مطويًّا فيتغيّر
  الارتفاع عند أوّل تمرير.
- `--chrome-top` متغيّرٌ يضبطه `ImpersonationBar` بارتفاعه، فتُثبَّت الترويسة تحته لا خلفه.

### ٢٫٢ Sidebar

| الخاصيّة | القيمة |
|---|---|
| العرض | `w-64` (256px) |
| الموضع | `fixed inset-y-0 start-0 z-40` |
| السطح | `bg-white` وحدٌّ `border-e border-[#e8e8e8]` |
| الرأس | `h-16` — الشعار وحده، مركزيًّا |
| التنقّل | `flex-1 overflow-y-auto px-3 pb-4` |
| على `lg` | `lg:translate-x-0` — ثابتٌ دائمًا |
| تحت `lg` | ينزلق: `max-lg:rtl:translate-x-full` / `max-lg:ltr:-translate-x-full` |
| الانتقال | `transition-transform duration-300` |
| الخلفية المعتمة | `bg-black/20 backdrop-blur-sm lg:hidden` — على الجوال وحده |

**رابط التنقّل** (`.ui-nav-link`):
```css
padding: 0.5rem 0.75rem;  border-radius: 10px;
font-size: 0.875rem;      font-weight: 500;    color: #4b4b4b;
hover:  background rgba(17,17,17,0.045);  color #111
active: background #f2f2f0;  color #111;  font-weight 650
active::before → شريطٌ 3×18px نصفُ قطره كامل بلون #111 على حافة البداية
```

**قواعد بنيويّة:**
- **الفاصل بين المجموعات خطٌّ لا عنوان** في لوحة التاجر (`border-t` + `pt-2`) — «العناوين
  تسميةٌ لا تُقرأ». ولوحة المنصّة تُبقي عناوينها (`text-[11px] font-semibold uppercase
  tracking-wide text-[#9ca3af]`).
- مجموعةٌ بـ`footer: true` تُدفع إلى الأسفل بـ`mt-auto`.
- **عنصرٌ مضيء واحد لا أكثر**: مسار العنصر يُقدَّم على ما يغطّيه، والأبناء على الآباء.
- القائمة المنسدلة تُفتح تلقائيًّا على الأداة المفتوحة.
- الإزاحة للأبناء منطقيّة: `paddingInlineStart: 12 + depth*22` بكسل.
- أيقونة المستوى الأوّل `size-[18px]`، والابن `size-4`.

### ٢٫٣ Header / Topbar

```
sticky top-[var(--chrome-top,0px)] z-30 flex h-16 shrink-0 transform-gpu
items-center gap-3 border-b border-[#e8e8e8] bg-white/95 px-4
backdrop-blur-md supports-[backdrop-filter]:bg-white/80 lg:px-6
```

ترتيب المحتوى: زرّ القائمة (`lg:hidden`) → البحث الموحّد → *ms-auto* → مبدّل الفرع →
اللغة → الإشعارات → قائمة المستخدم (Avatar).

### ٢٫٤ Content Area

- `mx-auto w-full max-w-[1600px]`
- الحشو `p-4 lg:p-6`
- دخولٌ متحرّك: `initial {opacity:0, y:8}` → `animate {opacity:1, y:0}`،
  `duration 0.25`, `ease [0.22, 1, 0.36, 1]`

### ٢٫٥ Grid System

لا نظام أعمدة مخصَّص — شبكة Tailwind مباشرةً. الأنماط المستعملة بالعدّ:

| النمط | التكرار | الاستعمال |
|---|---|---|
| `grid-cols-1 sm:grid-cols-2` | ١٢١ / ٦٥ | الأكثر — النماذج والبطاقات |
| `lg:grid-cols-3` | ٢١ | لوحة التحكم |
| `sm:grid-cols-3` | ١٦ | صفوف المؤشّرات |
| `md:grid-cols-2` | ١٣ | حقول النماذج |
| `lg:grid-cols-4` | ١٠ | شبكة المؤشّرات |
| `grid-cols-12` | ٢ | حالتان فقط |

**القاعدة السائدة:** ابدأ بعمودٍ واحد، وضاعِف عند `sm`، وثلّث/ربّع عند `lg`.
والفجوة `gap-4` غالبًا، و`gap-6` بين الأقسام.

### ٢٫٦ ترتيب العناصر داخل الصفحة

```
1. PageHeader        — العنوان + العنوان الفرعي + الإجراءات   (mb-5)
2. SectionTabs       — تنقّلٌ بين مسارات القسم، إن وُجد        (mb-6)
3. Tabs              — تبديل داخل الصفحة، إن وُجد
4. تنبيهٌ سياقيّ      — إن وُجد
5. المحتوى            — Card / DataTable / نموذج
6. شريط الحفظ         — في قدم النموذج
```

---

## ٣· Page Patterns

### ٣٫١ List Page

```tsx
<AdminLayout title="الشركات">
  <PageHeader title="الشركات" subtitle="…" actions={<><ExportMenu/><Button>إضافة</Button></>} />
  <DataTable rows={…} columns={…} rowKey={…} filters={…}
             searchPlaceholder="…" empty={…} server={{pagination, params, sorts}} />
</AdminLayout>
```

بنية `DataTable` من أعلى إلى أسفل:
1. **شريط الأدوات** `px-4 py-2` — بحثٌ بلا إطار (أيقونة + حقل شفّاف) · «أضف فلتر» ·
   فلاتر التاريخ مضمّنة · «ترتيب» · مبدّل العرض · إجراءات إضافيّة
2. **شرائح الفلاتر المطبَّقة** — `rounded-full bg-[#f2f2f0]` مع زرّ ×، و«مسح الكل» إذا زاد عن واحد
3. **تبويبات الحالة** — بنقاطٍ ملوّنة، «الكل» رماديّة
4. **الجدول** أو `renderBody` البديل
5. **الترقيم** — `border-t` · «من N» على اليمين وزرّا السابق/التالي على اليسار

> **قاعدة الخطّ الفاصل**: صفّ الأدوات يحمل `border-b` **إلا** إذا وُجدت شرائح، فتحمله هي.
> «خطٌّ بين البحث وشرائحه يقطع الكتلة نصفين».

### ٣٫٢ Detail Page

```tsx
<AdminLayout title="ملف الشركة">
  <PageHeader title="ملف الشركة" subtitle={business.name}
              actions={<><Button variant="outline">دخول</Button><Button variant="outline">تعديل</Button>…</>} />
  <Tabs current={tab} onChange={setTab} tabs={[…]} />
  <Card>…</Card>
</AdminLayout>
```
قوائم الحقائق تُعرض أزواجًا (تسمية خافتة + قيمة)، والقيم اللاتينية بـ`dir="ltr"`.

### ٣٫٣ Create / Edit Form

```tsx
<form className="mx-auto min-w-0 max-w-4xl scroll-mt-4">
  <div className="space-y-6">
    <Card className="p-6">
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Field label="…" required error={…}><Input/></Field>
      </div>
    </Card>
  </div>
  <Card className="mt-6 flex flex-col gap-3 p-4 sm:flex-row sm:justify-end">
    <Button variant="outline" className="sm:w-32">إلغاء</Button>
    <Button type="submit" className="sm:w-40" loading={form.processing}>حفظ</Button>
  </Card>
</form>
```

**شريط الحفظ بطاقةٌ مستقلّة** في القدم: عمودٌ على الجوال، وصفٌّ محاذٍ للنهاية من `sm`.

### ٣٫٤ Dashboard

```tsx
<StatGrid stats={…} storageKey="admin" catalog={…} />
<div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
  <Card className="lg:col-span-2"><AreaChart …/></Card>
  <Card><BarChart …/></Card>
</div>
```
نمط **٢:١** — المخطّط الزمنيّ يأخذ عمودين والتوزيع عمودًا.

### ٣٫٥ Settings Page

لوحةُ بطاقاتٍ مستطيلةٍ أفقيّة مجمَّعة، والنقرُ يفتح القسم **مكان اللوحة** لا في صفحةٍ أخرى:

```tsx
<button className="group flex items-center gap-4 rounded-[16px] border border-[#e8e8e8]
                   bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:bg-[#fafafa]">
  <span className="flex size-[52px] shrink-0 items-center justify-center rounded-[14px]
                   bg-[#f5f5f4] text-[#111] transition-colors
                   group-hover:bg-[#111] group-hover:text-white">
    <Icon className="size-6" />
  </span>
  <span className="min-w-0 flex-1">
    <span className="block text-[15px] font-semibold text-[#111]">{label}</span>
    <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{desc}</span>
  </span>
  <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] group-hover:text-[#6b7280]" />
</button>
```
الشبكة `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4`، والمجموعات `space-y-8` بعنوانٍ
`text-[13px] font-semibold text-[#6b7280] mb-3`.
والقسم المفتوح يعلوه زرّ رجوع، والعنوان يتبعه (`#anchor` أو `?section=`).

### ٣٫٦ Reports (فهرس)

بطاقاتٌ مصنَّفة تقود إلى الشاشات التي فيها البيانات — **لا شاشة أرقامٍ ثالثة**. وكل بندٍ
إمّا صفحةٌ قائمة (`route`) وإمّا مفتاح بياناتٍ يُعرض في نافذة (`data`)، ولا ثالث.

### ٣٫٧ Section Header

داخل البطاقة، عنوانُ قسمٍ فرعيّ يُفصل بخطّ:
```tsx
<div className="mt-8 border-t border-[#e8e8e8] pt-6">
  <h3 className="mb-4 font-bold text-[#111]">{title}</h3>
  …
</div>
```
أو ترويسة بطاقةٍ مؤيقنة:
```tsx
<div className="flex items-center gap-2 border-b border-[#e8e8e8] px-5 py-4">
  <Icon className="size-4 shrink-0 text-[#6b7280]" />
  <h3 className="font-bold text-[#111]">{title}</h3>
</div>
```

---

## ٤· Interaction Patterns

### فتح النوافذ
Radix `Dialog` — طبقةٌ `bg-black/20 backdrop-blur-sm`، ودخولٌ `fade-in-0 zoom-in-95`،
وتوسيطٌ `start-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rtl:translate-x-1/2`.
زرّ الإغلاق `absolute end-4 top-4`.

### الحذف والتأكيد
1. بند «حذف» في قائمة الصفّ بلون `#b91c1c`، يسبقه `DropdownMenuSeparator`.
2. `onSelect` يمنع الإغلاق ويفتح نافذة تأكيد `max-w-sm`.
3. العنوان: «تأكيد الحذف» — أو «تأكيد الإجراء» إن كان الفعل غير حذف.
4. الأزرار: `outline` إلغاء ثمّ `danger` تنفيذ، محاذاةً للنهاية.
5. الزرّان يُعطَّلان أثناء التنفيذ، والزرّ الأحمر يعرض `…`.

> **الفعل المعروض هو المتاح لا الاثنان**: الشركة المعطَّلة تُعرض عليها «إعادة تشغيل» وحدها،
> والعاملة «تعطيل» وحده. «وقائمةٌ فيها زرٌّ لا يفعل شيئًا تجعل المشغّل يضغطه ليعرف.»

### الحفظ
`Button loading={form.processing}` — المحتوى يبقى في مكانه ويُخفى بـ`opacity-0`، ويُركَّب
`Loader2 animate-spin` فوقه بالإحداثيات المطلقة. **فلا يتغيّر عرض الزرّ ولا يقفز ما حوله.**
والزرّ يُعطَّل فيمنع الإرسال مرّتين.

### الإشعارات
`sonner` — `<Toaster position="bottom-center" richColors dir="rtl" />`.
رسائل الجلسة تُحوَّل: `success → toast.success`, `danger → toast.error`,
`warning → toast.warning`, وما سواها `toast`.
**والحذف يحمل «تراجع»** بمهلة `12000ms` (أطول من المعتاد: «من يكتشف الخطأ يحتاج ثانيةً
ليقرأ ما حدث قبل أن يتصرّف»).

### التنقّل
`SmartLink` — كل التنقّل عبر Inertia، مع **جلبٍ مسبق عند التحويم**
(`prefetch="hover" cacheFor="30s"`). الذاكرة قصيرةٌ لأنه نظام بيع تتغيّر بياناته.

### الفلاتر
- فلتر الحالة → **شريط تبويبات** بنقاطٍ ملوّنة (أوّل ما يُسأل عنه لا يُخبَّأ خلف زرّ)
- ما عداه → قائمة «أضف فلتر» المنسدلة، وتُصبح شريحةً بعد التطبيق
- فلاتر التاريخ → **مضمّنة في الشريط** لا داخل القائمة: منتقي التاريخ الأصليّ يُرسَم في
  طبقةٍ خارج القائمة فتفقد التركيز وتُغلق قبل اختيار يوم
- شريحةٌ تُنزع بضغطة، و«مسح الكل» تظهر إذا زاد المطبَّق عن واحد

### البحث
- **محليّ**: ترشيحٌ فوريّ على الصفوف الموجودة
- **خادميّ**: معامل `q` في الرابط، مع `preserveState` و`replace`
- الحقل بلا إطار: «حقلٌ محاطٌ بحدٍّ داخل بطاقةٍ محاطةٍ بحدّ يُثقل الشريط، والأيقونة وحدها تقول ما هو»

### Pagination
`border-t` · على البداية «`from`–`to` من `total`» بـ`text-[12px] text-[#6b7280]` ·
على النهاية زرّان `h-8 rounded-[8px] border` بـ`disabled:opacity-50`.
يظهر **فقط** حين `pageCount > 1`.

### Loading
لا هياكل عظميّة (skeletons) في النظام. الحالات الثلاث:
1. **الزرّ** — `loading` بالمؤشّر الدوّار المركَّب
2. **الصفحة** — دخولٌ متحرّك من framer-motion
3. **التغذية الحيّة** — استطلاعٌ صامت كل ٢٠–٣٠ ثانية يُحدّث الأرقام بلا مؤشّر

### Validation
- الخطأ يصل من الخادم في `form.errors`
- يُعرض في `Field` بـ`text-[12px] text-[#b91c1c]` **مكان التلميح** لا تحته
- الحقل المطلوب يُعلَّم بنجمةٍ حمراء في `Label`
- خطأٌ في جزءٍ غير معروض → نقطةٌ حمراء على التبويب (`tab.alert`)

---

## ٥· Responsive Rules

| الجهاز | العرض | السلوك |
|---|---|---|
| **Mobile** | `< 640px` | شريط جانبيّ منزلق بخلفيةٍ معتمة · حشو `p-4` · عمودٌ واحد · `PageHeader` عمودًا · شريط الحفظ عمودًا · حقول بخطّ 16px |
| **Tablet** | `640–1023px` | من `sm`: عمودان في الشبكة · `PageHeader` صفًّا · شريط الحفظ صفًّا · الشريط الجانبي ما زال منزلقًا |
| **Laptop** | `1024–1279px` | من `lg`: **الشريط الجانبي ثابت** و`lg:ms-64` · حشو `lg:p-6` · ثلاثة أعمدة |
| **Desktop** | `≥ 1280px` | كما `lg` مع `xl:grid-cols-4` في مواضع معدودة · السقف `1600px` يمنع التمدّد |

**قواعد ثابتة:**
- التخطيط **منطقيّ لا يمينيّ**: `start`/`end`, `ms`/`me`, `border-e` — ينقلب مع اللغة
- الجدول يُمرَّر داخل حاويته (`overflow-x-auto`)، والصفحة لا تتمدّد أفقيًّا
- `body { overflow-x: clip }` لا `hidden` — الأخير يجعل الجسم حاويةَ تمرير فيسقط
  `sticky` في سفاري
- `html { scrollbar-gutter: stable; overscroll-behavior-y: none }`

---

## ٦· Icons

**المكتبة:** `lucide-react`

**قاعدة حاكمة:** استيرادٌ صريحٌ لكل أيقونة. *«الاستيراد الشامل من lucide يضخّ المكتبة كاملة
في الحزمة.»* وحيث يأتي اسم الأيقونة من الخادم تُستعمل **خريطةٌ صريحة** (`ICONS` في
`StatCard.tsx`).

| المقاس | الاستعمال |
|---|---|
| `size-3.5` (14px) | سهم الاتّجاه · إزالة الشريحة · سهم الترتيب |
| `size-4` (16px) | الافتراضي: القوائم · التبويبات · زرّ `sm` |
| `size-[18px]` | زرّ `md` · بند الشريط الجانبي |
| `size-5` (20px) | زرّ `lg` · أيقونة `StatCard` |
| `size-6` (24px) | بطاقة قسم الإعدادات |
| `size-8` (32px) | **الحالة الفارغة** بلون `#d1d5db` |

```css
svg.lucide { pointer-events: none; }   /* النقر يمرّ إلى الزرّ الحاوي */
```
الأزرار تفرض `[&_svg]:pointer-events-none [&_svg]:shrink-0` وتضبط المقاس بالحجم.

---

## ٧· Technical Styling

| البند | التفصيل |
|---|---|
| **الأداة** | **Tailwind CSS v4** عبر `@tailwindcss/vite` — **بلا `tailwind.config.js`** |
| التهيئة | كلّها في `resources/css/app.css` بكتلة `@theme` |
| **لا يُستعمل** | CSS Modules · Styled Components · SCSS · Emotion — **ولا واحد منها** |
| مكتبة العناصر | **Radix UI** (بدائل غير مرئيّة) + نمط **shadcn/ui** يدويًّا في `Components/ui/` |
| الأنماط المتغيّرة | `class-variance-authority` (cva) |
| دمج الأصناف | `clsx` + `tailwind-merge` عبر `cn()` |
| الحركة | `framer-motion` |
| الإشعارات | `sonner` |
| الأيقونات | `lucide-react` |
| الحركات الإضافية | `tailwindcss-animate` (لـ`data-[state]` في Radix) |
| البناء | Vite 8 · React 19 · TypeScript 7 |

**بنية الأنماط ثلاث طبقات:**
1. `@theme` — مقاييس الألوان والخطّ (رموز Tailwind)
2. `@layer base` — إعادة ضبطٍ عامّة (الاتّجاه · شريط التمرير · حلقة التركيز)
3. `.admin-ui` — نطاقٌ يحمل متغيّرات `--ui-*` **وطبقةَ تنعيمٍ بـ`!important`** تُعيد تعريف
   `.shadow-*` و`.border-gray-*` و`.rounded-2xl` داخل اللوحة

> **اختلافٌ موثَّق**: يوجد **نظامان متوازيان** — أصنافُ CSS قديمة (`.ui-btn`, `.ui-input`,
> `.ui-card`, `.ui-table`, `.ui-chip`) ومكوّناتُ React الحديثة. **المكوّنات هي المعتمدة**؛
> أصناف CSS باقيةٌ للشريط الجانبي (`.ui-nav-link`) ولنقطة البيع. وقيمُهما تختلف قليلًا:
> `.ui-btn` ارتفاعه `40px` ونصف قطره `999px`، بينما `Button` ارتفاعه `h-10` ونصف قطره
> `10px`. **اعتمد المكوّن.**

### نطاقات ثلاثة
| النطاق | الغرض |
|---|---|
| `.admin-ui` | لوحة التاجر ولوحة المنصّة — **هما القشرة نفسها** بقائمةٍ مختلفة |
| `.pos-scope` | نقطة البيع — تحييد الألوان، `accent-color: #111827`، ظلالٌ أنعم |
| `@media print` | الإيصال الحراري `80mm` والتقرير المطبوع |

---

## ٨· اختلافاتٌ موثَّقة (لا تُصحَّح — تُعرَف)

| الموضع | الاختلاف | الأكثر استعمالًا |
|---|---|---|
| خلفية الجسم | `#f4f5f7` عامًّا · `#f7f8f9` في `.admin-ui` | **`#f7f8f9`** |
| ظلّ البطاقة | `rgba(0,0,0,0.04)` في المكوّن · `rgba(17,17,17,0.035)` في CSS | **المكوّن** |
| نصّ التحذير | `#d97706` · `#b45309` | كلاهما — الثاني أدكن لنصٍّ أطول |
| مربّع الاختيار | `size-4 accent-[#111]` (٣) · `size-5 rounded accent-[#111]` (١) · بحدٍّ (٢) | **`size-4 accent-[#111]`** |
| نصف قطر الزرّ | `10px` في المكوّن · `999px` في `.ui-btn` | **`10px`** |
| حزم Radix | **٦ مثبّتة وغير مستعملة**: checkbox · popover · separator · switch · tabs · tooltip | لا تعتمد عليها |

---

## ٩· ما لا يوجد في النظام

توثيقًا للأمانة — هذه **غير موجودة**، فلا تفترضها:

- **Breadcrumbs** — كانت موجودة و**أُزيلت عمدًا** من كل صفحة. لا تُعِدها.
- **Tooltip** — لا مكوّن (والحزمة مثبّتة غير مستعملة). البدائل: `aria-label` و`title`
  والتلميحُ النصّيّ تحت الحقل.
- **Drawer** — لا مكوّن. الشريط الجانبي على الجوال أقربُ ما إليه.
- **Checkbox / Radio / Switch كمكوّنات** — `<input type>` خامٌ بـ`accent-[#111]`.
  و`Toggle` مبنيٌّ يدويًّا بـ`role="switch"`.
- **Alert كمكوّن** — أنماطٌ مضمّنة تتكرّر (انظر COMPONENTS.md § Alert).
- **Pagination كمكوّن مستقلّ** — مدمجٌ في `DataTable`.
- **Skeleton / Spinner عامّ** — المؤشّر داخل `Button` وحده.
- **Dark mode** — لا وجود له إطلاقًا. النظام فاتحٌ فقط.
- **2xl breakpoint** — غير مستعملة.
