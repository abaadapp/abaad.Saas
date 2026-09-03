# دليل التطبيق — بناء مشروع React بهويّة أبعاد

> موجَّهٌ إلى مطوّرٍ أو نموذج ذكاء اصطناعيّ. اتّبعه حرفيًّا فيخرج المشروع الجديد وكأنّه جزءٌ
> من النظام نفسه. **لا تُحسّن، ولا تُحدّث، ولا تستبدل قيمةً بأقرب منها في مقياسٍ قياسيّ.**

اقرأ معه: [`DESIGN_SYSTEM.md`](./DESIGN_SYSTEM.md) · [`design-tokens.json`](./design-tokens.json) · [`COMPONENTS.md`](./COMPONENTS.md)

---

## ٠· القواعد السبع التي لا تُخالَف

1. **اللكنة سوداء `#111111`** — لا بنفسجيّة ولا زرقاء، ولو وجدت مقاييس `primary` بنفسجيّة في الرموز.
2. **اللون للحالة لا للزينة.** الأخضر والأصفر والأحمر والأزرق لا تظهر إلا في شارةٍ أو تنبيهٍ أو نقطة حالة.
3. **المقاسات بالبكسل الصريح** — `text-[13px]` لا `text-sm` حيث كان المصدر كذلك.
4. **الخصائص المنطقيّة دائمًا** — `start`/`end` · `ms`/`me` · `ps`/`pe` · `border-e`. لا `left`/`right` إطلاقًا.
5. **لا سلسلة صفحات (breadcrumbs).** نُزعت عمدًا من النظام كلّه.
6. **لا وضعٍ داكن.** النظام فاتحٌ فقط.
7. **الظلّ يكاد لا يُرى.** أقوى ظلٍّ في النظام هو ظلّ النافذة `0 20px 60px rgba(0,0,0,0.15)`.

---

## ١· الاعتماديات

```bash
npm i react react-dom clsx tailwind-merge class-variance-authority \
      lucide-react framer-motion sonner \
      @radix-ui/react-dialog @radix-ui/react-dropdown-menu @radix-ui/react-select \
      @radix-ui/react-label @radix-ui/react-avatar @radix-ui/react-slot

npm i -D tailwindcss @tailwindcss/vite tailwindcss-animate typescript vite
```

> **لا تُثبّت** `@radix-ui/react-{checkbox,popover,separator,switch,tabs,tooltip}` — مثبّتةٌ في
> المصدر لكنّها **غير مستعملة إطلاقًا**. التبويبات ومفتاح التبديل مبنيّان يدويًّا.

**لا تستعمل:** CSS Modules · Styled Components · SCSS · Emotion · أي مكتبة UI جاهزة
(MUI, Chakra, Ant, Mantine). النظام **Tailwind v4 + Radix + shadcn يدويًّا**.

---

## ٢· ملفّ الأنماط

`src/styles/app.css` — انسخه كما هو. **بلا `tailwind.config.js`**؛ Tailwind v4 يُهيَّأ في CSS.

```css
@import 'tailwindcss';

@theme {
    --font-sans: 'IBM Plex Sans Arabic', 'Tajawal', 'Cairo', ui-sans-serif, system-ui, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji';

    --color-primary-50: #f5f3ff;  --color-primary-100: #ede9fe; --color-primary-200: #ddd6fe;
    --color-primary-300: #c4b5fd; --color-primary-400: #a78bfa; --color-primary-500: #8b5cf6;
    --color-primary-600: #7c3aed; --color-primary-700: #6d28d9; --color-primary-800: #5b21b6;
    --color-primary-900: #4c1d95; --color-primary-950: #2e1065;

    --color-secondary-50: #fdf2f8;  --color-secondary-100: #fce7f3; --color-secondary-200: #fbcfe8;
    --color-secondary-300: #f9a8d4; --color-secondary-400: #f472b6; --color-secondary-500: #ec4899;
    --color-secondary-600: #db2777; --color-secondary-700: #be185d; --color-secondary-800: #9d174d;
    --color-secondary-900: #831843;

    --color-success-50: #ecfdf5; --color-success-500: #10b981;
    --color-success-600: #059669; --color-success-700: #047857;
    --color-warning-50: #fffbeb; --color-warning-500: #f59e0b; --color-warning-600: #d97706;
    --color-danger-50: #fef2f2;  --color-danger-500: #ef4444;
    --color-danger-600: #dc2626; --color-danger-700: #b91c1c;
    --color-info-50: #eff6ff;    --color-info-500: #3b82f6;  --color-info-600: #2563eb;

    --radius-ui: 16px;
}

@layer base {
    /* الاتّجاه من سمة dir على <html>؛ وبلا سمة يكون RTL */
    html:not([dir]) { direction: rtl; }

    html {
        scrollbar-gutter: stable;      /* لا انزلاق أفقيّ بين صفحةٍ طويلة وقصيرة */
        overscroll-behavior-y: none;   /* لا ارتداد مطّاطيّ يُسقط التثبيت على iPad */
    }

    body {
        font-family: var(--font-sans);
        background-color: #f4f5f7;
        color: #1f2937;
        -webkit-font-smoothing: antialiased;
        /* clip لا hidden: hidden يجعل الجسم حاويةَ تمرير فيسقط sticky في سفاري */
        overflow-x: clip;
    }

    button:focus-visible, a:focus-visible,
    [role='button']:focus-visible, [tabindex]:focus-visible {
        outline: 2px solid var(--ring, rgba(17, 17, 17, 0.35));
        outline-offset: 2px;
    }

    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    * { border-color: var(--border); }
}

/* أيقونات lucide زخرفيّة — النقر يمرّ إلى الزرّ الحاوي */
svg.lucide { pointer-events: none; }

.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* ===== نطاق اللوحة ===== */
.admin-ui {
    --ui-bg: #f7f8f9;
    --ui-surface: #ffffff;
    --ui-border: #e8e8e8;
    --ui-border-strong: #dcdcdc;
    --ui-text: #111111;
    --ui-muted: #6b7280;
    --ui-muted-2: #9ca3af;
    --ui-accent: #111111;
    --ui-radius: 16px;

    background-color: var(--ui-bg);
    color: var(--ui-text);
    font-family: 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, 'SF Pro Text',
        'SF Pro Display', 'Segoe UI', system-ui, sans-serif;
    letter-spacing: -0.011em;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}
.admin-ui h1, .admin-ui h2, .admin-ui h3 { letter-spacing: -0.021em; }
.admin-ui ::-webkit-scrollbar-thumb { background: #d8d8d4; }

/* رابط الشريط الجانبي */
.ui-nav-link {
    position: relative; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.5rem 0.75rem; border-radius: 10px;
    font-size: 0.875rem; font-weight: 500; color: #4b4b4b;
    transition: background-color .15s ease, color .15s ease;
}
.ui-nav-link:hover { background: rgba(17,17,17,0.045); color: #111; }
.ui-nav-link.is-active { background: #f2f2f0; color: #111; font-weight: 650; }
.ui-nav-link.is-active::before {
    content: ''; position: absolute; inset-inline-start: -0.75rem; top: 50%;
    transform: translateY(-50%); width: 3px; height: 18px;
    border-radius: 9999px; background: #111;
}

/* رموز shadcn — مربوطة باللوحة */
:root, .admin-ui {
    --background: #f7f8f9;  --foreground: #111111;
    --card: #ffffff;        --card-foreground: #111111;
    --popover: #ffffff;     --popover-foreground: #111111;
    --primary: #111111;     --primary-foreground: #ffffff;
    --secondary: #f2f2f0;   --secondary-foreground: #111111;
    --muted: #f2f2f0;       --muted-foreground: #6b7280;
    --accent: #f2f2f0;      --accent-foreground: #111111;
    --destructive: #dc2626; --destructive-foreground: #ffffff;
    --border: #e8e8e8;      --input: #e8e8e8;
    --ring: rgba(17, 17, 17, 0.12);
    --radius: 12px;
}

/* ١٦ بكسل في حقول اللمس — سفاري iOS يُكبّر عند حقلٍ أدنى ولا يعود */
@media (hover: none) and (pointer: coarse) {
    input:not([type='checkbox']):not([type='radio']):not([type='range']),
    select, textarea { font-size: 16px !important; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
```

**الخطّ:** حمّل `IBM Plex Sans Arabic` (الأوزان 300/400/500/600/700) واجعله أوّل العائلة.
بلا هذا الخطّ يتغيّر شكل النظام كلّه.

---

## ٣· ترتيب البناء

انسخ المكوّنات **بهذا الترتيب** — كلٌّ يعتمد على ما قبله:

```
1. lib/utils.ts        cn() = twMerge(clsx(...))
2. lib/format.ts       money · number · percent · initials
3. ui/button.tsx       cva بسبعة variants وخمسة مقاسات
4. ui/input.tsx        Input + Textarea (مع معالجة التاريخ)
5. ui/label.tsx        Radix Label + النجمة الحمراء
6. ui/card.tsx         Card + Header/Title/Description/Content/Footer
7. ui/badge.tsx        cva + STATUS_VARIANT + statusDot()
8. ui/table.tsx        Table…TableEmpty
9. ui/dialog.tsx       Radix Dialog
10. ui/dropdown-menu.tsx  Radix DropdownMenu
11. ui/select.tsx      Radix Select
12. ui/avatar.tsx      Radix Avatar
13. Components/Field.tsx     الغلاف + Select السهل
14. Components/Toggle.tsx    مفتاح يدويّ
15. Components/Tabs.tsx      خطٌّ سفليّ
16. Components/PageHeader.tsx
17. Components/StatCard.tsx  + خريطة ICONS صريحة
18. Components/DataTable.tsx  الأكبر — ابنِه أخيرًا
19. Layouts/AppLayout.tsx    Sidebar + Topbar + main
```

**كلّها منقولةٌ حرفيًّا من [`COMPONENTS.md`](./COMPONENTS.md).** انسخ سلاسل الأصناف كما هي.

---

## ٤· القشرة

```tsx
export default function AppLayout({ title, children }: Props) {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="admin-ui min-h-dvh">
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="lg:ms-64">
        <Topbar onMenuClick={() => setSidebarOpen(true)} />

        <motion.main
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
          className="mx-auto w-full max-w-[1600px] p-4 lg:p-6"
        >
          {children}
        </motion.main>
      </div>

      <Toaster position="bottom-center" richColors dir="rtl" />
    </div>
  );
}
```

**نقاطٌ لا تُغيَّر:**
- `min-h-dvh` لا `min-h-screen`
- `max-w-[1600px]` في الحاوية — **لا سقفَ في الصفحات نفسها**
- `p-4 lg:p-6`
- `lg` هو الحدّ الفاصل للشريط الجانبي: `w-64` · `lg:ms-64` · `lg:translate-x-0`

---

## ٥· قوالبُ صفحاتٍ جاهزة

### قائمة
```tsx
<AppLayout title="المنتجات">
  <PageHeader title="المنتجات" subtitle="…"
              actions={<><ExportMenu … /><Button><Plus />إضافة</Button></>} />
  <Card className="overflow-hidden">
    <DataTable rows={rows} columns={columns} rowKey={r => r.id}
               filters={[{label:'كل الحالات', param:'status', asTabs:true, options}]}
               searchPlaceholder="ابحث…" empty={…} />
  </Card>
</AppLayout>
```

### نموذج
```tsx
<AppLayout title="إضافة منتج">
  <PageHeader title="إضافة منتج" subtitle="…" />
  <form className="mx-auto min-w-0 max-w-4xl" onSubmit={submit}>
    <div className="space-y-6">
      <Card className="p-6">
        <h3 className="mb-4 font-bold text-[#111]">البيانات الأساسية</h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="الاسم" required error={errors.name}><Input … /></Field>
        </div>
      </Card>
    </div>
    <Card className="mt-6 flex flex-col gap-3 p-4 sm:flex-row sm:justify-end">
      <Button variant="outline" className="sm:w-32" type="button">إلغاء</Button>
      <Button type="submit" className="sm:w-40" loading={saving}>حفظ</Button>
    </Card>
  </form>
</AppLayout>
```

### لوحة تحكم
```tsx
<AppLayout title="لوحة التحكم">
  <PageHeader title="لوحة التحكم" subtitle="…" />
  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    {stats.map((s, i) => <StatCard key={s.label} stat={s} index={i} />)}
  </div>
  <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
    <Card className="p-5 lg:col-span-2">…</Card>
    <Card className="p-5">…</Card>
  </div>
</AppLayout>
```

### تفاصيل
```tsx
<AppLayout title="ملف العميل">
  <PageHeader title="ملف العميل" subtitle={customer.name}
              actions={<Button variant="outline"><Pencil />تعديل</Button>} />
  <Tabs current={tab} onChange={setTab}
        tabs={[{key:'overview',label:'نظرة عامة'},{key:'orders',label:'الطلبات',count:12}]} />
  <div className="mt-4">{tab === 'overview' ? <Card className="p-6">…</Card> : …}</div>
</AppLayout>
```

---

## ٦· قائمةُ فحصٍ قبل التسليم

**الرموز**
- [ ] لا لونٍ خارج [`design-tokens.json`](./design-tokens.json)
- [ ] اللكنة `#111111` — لا بنفسجيّ في زرٍّ أو رابطٍ أو تركيز
- [ ] الحدود كلّها `#e8e8e8` عدا زرّ `outline` (`#dcdcdc`) وحدّ التركيز (`#d1d5db`)
- [ ] المقاسات من القائمة: 10 · 11 · 12 · 13 · 14 · 15 · 17 · 18 · 20 · 22 · 28
- [ ] نصف القطر من: 8 · 10 · 12 · 14 · 16 · full

**التخطيط**
- [ ] `max-w-[1600px]` في الحاوية وحدها
- [ ] `p-4 lg:p-6`
- [ ] الشريط `w-64` وينزلق تحت `lg`
- [ ] الترويسة `h-16` مثبّتة بـ`backdrop-blur-md`
- [ ] `PageHeader` بـ`mb-5`

**المكوّنات**
- [ ] الأزرار من `Button` وحده — لا `<button>` منسَّق يدويًّا
- [ ] `loading` يُخفي المحتوى ولا يغيّر عرض الزرّ
- [ ] الحقول داخل `Field` — والخطأ **يحلّ محلّ** التلميح
- [ ] الجداول من `DataTable`
- [ ] الحذف يمرّ بنافذة تأكيد `max-w-sm` بزرّ `danger`
- [ ] الشارات من `Badge` بـ`status`، والنقاط من `statusDot()` — **مصدرٌ واحد**

**الاتّجاه والاستجابة**
- [ ] لا `left`/`right`/`ml`/`mr`/`pl`/`pr` — منطقيّةٌ فقط
- [ ] القيم اللاتينية بـ`dir="ltr"`
- [ ] الأرقام بـ`tabular-nums`
- [ ] الجداول العريضة تُمرَّر داخل حاويتها
- [ ] فُحصت الشاشة على 375 · 768 · 1024 · 1440

**السلوك**
- [ ] `sonner` أسفل الوسط بـ`richColors`
- [ ] الحذف يحمل «تراجع» بمهلة `12000ms`
- [ ] التنقّل بجلبٍ مسبق `prefetch="hover" cacheFor="30s"`
- [ ] `prefers-reduced-motion` محترَم

---

## ٧· أخطاءٌ شائعة — وسببُ منعها

| الخطأ | لماذا يُمنع |
|---|---|
| استعمال `primary-600` البنفسجي زرًّا | اللكنة سوداء. البنفسجي للشارة والمخطّط فقط |
| `text-sm` حيث كان `text-[13px]` | فرقُ بكسلٍ واحد يظهر في صفٍّ من التسميات |
| `transition-all` على الزرّ | يُحرّك خصائص التخطيط فيبدو الزرّ مهتزًّا |
| `ml-auto` بدل `ms-auto` | ينكسر في LTR |
| `min-h-screen` بدل `min-h-dvh` | `vh` يتغيّر على iPad عند أوّل تمرير |
| `overflow-x: hidden` على `body` | يجعله حاويةَ تمرير فيسقط `sticky` في سفاري |
| إضافة breadcrumbs | نُزعت عمدًا من النظام كلّه |
| استيراد `* from 'lucide-react'` | يضخّ المكتبة كاملة في الحزمة |
| مؤشّر تحميلٍ يستبدل نصّ الزرّ | يغيّر عرضه فيقفز ما حوله |
| خريطتان للحالات (شارة ونقطة) | تظهر الحالة بلونين على شاشةٍ واحدة |
| `variant` على شريط التبويبات | ينقسم النظام شريطين — نُزع الخيار من جذره |
| زرٌّ لا يفعل شيئًا في القائمة | «يجعل المشغّل يضغطه ليعرف» — اعرض المتاح وحده |
| حقلٌ بخطٍّ أدنى من 16px على اللمس | سفاري iOS يُكبّر ولا يعود |

---

## ٨· إن اختلف مصدران

المصدر فيه **نظامان متوازيان**: أصنافُ CSS قديمة (`.ui-btn`, `.ui-input`, `.ui-card`,
`.ui-table`, `.ui-chip`) ومكوّناتُ React.

> **القاعدة: اعتمد المكوّن.**
> مثال: `.ui-btn` ارتفاعه `40px` ونصف قطره `999px`؛ و`Button` ارتفاعه `h-10` ونصف قطره
> `10px`. **الثاني هو المعتمد.**

الاستثناء الوحيد: **`.ui-nav-link`** — الشريط الجانبي ما زال يستعمله فعلًا، فانقله كما هو.

وحيث لا مكوّن (مربّع الاختيار · التنبيه · الحالة الفارغة) خذ **النمط الأكثر تكرارًا** الموثَّق
في [`COMPONENTS.md § أنماطٌ بلا مكوّن`](./COMPONENTS.md).

---

## ٩· مهمّةٌ جاهزة لنموذج ذكاء اصطناعيّ

> اقرأ `DESIGN_SYSTEM.md` و`design-tokens.json` و`COMPONENTS.md` في مجلّد `design-system/`.
>
> أنشئ مشروع React 19 + TypeScript + Vite + Tailwind CSS v4 يطبّق هذا النظام حرفيًّا:
>
> 1. انسخ `app.css` من القسم ٢ في `IMPLEMENTATION_GUIDE.md` كما هو، ولا تغيّر قيمة.
> 2. ابنِ المكوّنات بالترتيب في القسم ٣، بسلاسل الأصناف المكتوبة في `COMPONENTS.md` حرفيًّا.
> 3. ابنِ القشرة كما في القسم ٤ — `w-64` للشريط، `max-w-[1600px]` للحاوية، `p-4 lg:p-6`.
> 4. طبّق قوالب الصفحات من القسم ٥.
> 5. امرر على قائمة الفحص في القسم ٦ بندًا بندًا.
>
> **ممنوع:** تغيير لونٍ أو مقاسٍ أو نصف قطر · إضافة وضعٍ داكن · إضافة breadcrumbs ·
> استبدال قيمةٍ بالبكسل بأخرى من مقياس Tailwind الاسمي · إدخال مكتبة UI جاهزة ·
> إضافة تدرّجاتٍ أو ظلالٍ أقوى أو زوايا أدور ممّا هو موثَّق.
>
> عند الشكّ: **انسخ، ولا تجتهد.**
