# مكوّنات نظام أبعاد — Components Reference

> كل مكوّنٍ هنا موجودٌ في المشروع فعلًا. الأصناف منقولةٌ حرفيًّا، والمسارات تشير إلى موضعه.
> المكوّنات المفقودة موثَّقةٌ في آخر الملفّ تحت **«أنماطٌ بلا مكوّن»**.

**الأساس المشترك:**
```ts
// lib/utils.ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';
export function cn(...inputs: ClassValue[]) { return twMerge(clsx(inputs)); }
```

---

## Button

**الموضع:** `resources/js/Components/ui/button.tsx`
**الغرض:** كل فعلٍ يُضغط. مبنيٌّ على `cva` مع `Slot` من Radix لدعم `asChild`.

**الأساس:**
```
relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[10px]
font-medium transition-colors disabled:pointer-events-none disabled:opacity-50
focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)]
[&_svg]:pointer-events-none [&_svg]:shrink-0
```
> `transition-colors` لا `transition-all`: الثانية تُحرّك خصائص التخطيط فيبدو الزرّ مهتزًّا.

**Variants** (الافتراضي `primary`):

| Variant | الأصناف |
|---|---|
| `primary` | `bg-[#111] text-white hover:bg-[#2a2a2a] active:bg-[#000]` |
| `outline` | `border border-[var(--border-strong,#dcdcdc)] bg-white text-[#111] hover:bg-[#fafafa]` |
| `ghost` | `text-[#4b4b4b] hover:bg-[rgba(17,17,17,0.045)] hover:text-[#111]` |
| `danger` | `bg-[#dc2626] text-white hover:bg-[#b91c1c]` |
| `success` | `bg-[#059669] text-white hover:bg-[#047857]` |
| `subtle` | `bg-[#f2f2f0] text-[#111] hover:bg-[#e9e9e6]` |
| `link` | `text-[#111] underline-offset-4 hover:underline` |

**Sizes** (الافتراضي `md`):

| Size | الأصناف |
|---|---|
| `sm` | `h-8 px-3 text-[13px] [&_svg]:size-4` |
| `md` | `h-10 px-4 text-sm [&_svg]:size-[18px]` |
| `lg` | `h-12 px-6 text-[15px] [&_svg]:size-5` |
| `icon` | `h-10 w-10 [&_svg]:size-[18px]` |
| `icon-sm` | `h-8 w-8 [&_svg]:size-4` |

**Props:** `variant` · `size` · `asChild` · `loading` · كل خصائص `<button>`

**States:**
- *Hover* — لكل variant لونه أعلاه
- *Focus* — `ring-2 ring-[var(--ring)]` مع `outline-none`
- *Active* — `primary` وحده يعتم إلى `#000`
- *Disabled* — `pointer-events-none opacity-50`
- *Loading* — **المحتوى يبقى في مكانه ويُخفى بـ`opacity-0`**، ويُركَّب `Loader2 animate-spin`
  بالإحداثيات المطلقة. فلا يتغيّر عرض الزرّ ولا يقفز ما حوله. ويُعطَّل الزرّ فيمنع الإرسال مرّتين.

```tsx
<Button loading={form.processing}><Save />حفظ التغييرات</Button>
<Button variant="outline" size="sm" asChild><Link href="…"><Pencil />تعديل</Link></Button>
<Button variant="ghost" size="icon" aria-label="القائمة"><Menu /></Button>
```

---

## Input · Textarea

**الموضع:** `resources/js/Components/ui/input.tsx`

**Input:**
```
flex h-10 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2
text-sm text-[#111] placeholder:text-[#9ca3af]
transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none
focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]
disabled:cursor-not-allowed disabled:bg-[#fafafa] disabled:opacity-60
file:border-0 file:bg-transparent file:text-sm file:font-medium
```

**Textarea:** نفسه مع `min-h-20` بدل `h-10` وبلا قواعد `file:`.

**سلوكٌ خاصّ — حقول التاريخ/الوقت:**
الأنواع `date`, `datetime-local`, `time`, `month`, `week` تُعرض `dir="ltr"` تلقائيًّا (خاناتها
تُقرأ يسارًا-يمينًا حتى في واجهةٍ عربية)، وتأخذ:
```
min-w-0
[&::-webkit-calendar-picker-indicator]:cursor-pointer
[&::-webkit-calendar-picker-indicator]:opacity-70
```

**States:** *Focus* حدٌّ `#d1d5db` + حلقة `0 0 0 3px rgba(0,0,0,0.05)` · *Disabled* خلفية
`#fafafa` وشفافية `0.6` · *Error* الحقل لا يتغيّر، والخطأ يظهر في `Field` تحته.

```tsx
<Input value={v} onChange={e => set(e.target.value)} placeholder="…" />
<Input type="file" accept="image/png,image/jpeg"
       className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white" />
```

---

## Field

**الموضع:** `resources/js/Components/Field.tsx`
**الغرض:** غلافُ حقل — تسمية + عنصر + تلميح **أو** خطأ.

```
<div className="space-y-1.5">
  <Label required>…</Label>
  {children}
  {error ? <p className="text-[12px] text-[#b91c1c]">{error}</p>
         : hint && <p className="text-[12px] text-[#9ca3af]">{hint}</p>}
</div>
```

**Props:** `label` · `hint` · `error` · `required` · `htmlFor` · `className`

> **قاعدة**: الخطأ يحلّ **محلّ** التلميح لا تحته — فلا يزحف التخطيط عند ظهوره.

```tsx
<Field label="اسم المتجر" required error={form.errors.shop_name}>
  <Input value={form.data.shop_name} onChange={…} />
</Field>
```

---

## Label

**الموضع:** `resources/js/Components/ui/label.tsx` — على `@radix-ui/react-label`

```
block text-[13px] font-medium text-[#4b4b4b]
peer-disabled:cursor-not-allowed peer-disabled:opacity-70
```
`required` يُلحق `<span className="text-[#dc2626]"> *</span>`.

---

## Select

**الموضع:** `ui/select.tsx` (بدائل Radix) و`Field.tsx` (الغلاف السهل)

> **لماذا لا `<select>` أصلية؟** «القائمة الأصلية يرسمها نظام التشغيل لا المتصفّح: نافذة داكنة
> ضيّقة تطفو فوق الحقل نفسه فتحجبه، ولا تحترم عرضه ولا خطّه ولا اتجاه الواجهة.»

**SelectTrigger:**
```
flex h-10 w-full items-center justify-between gap-2 rounded-[10px]
border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-start text-sm text-[#111]
transition-[border-color,box-shadow] outline-none
focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]
disabled:cursor-not-allowed disabled:opacity-60
[&>span]:min-w-0 [&>span]:truncate
data-[placeholder]:text-[#9ca3af]
```
والأيقونة `ChevronDown className="size-4 shrink-0 text-[#6b7280]"`.

**الغلاف `Select` من `Field.tsx`** — واجهته تحاكي `<select>` الأصلية:
`options: {label, value}[]` · `placeholder` · `value` · `onChange({target:{value,name}})`

> القيمة الفارغة تُمرَّر داخليًّا كـ`'__empty__'` لأن Radix يحجزها لمعنى «لا اختيار».

```tsx
<Select value={size} onChange={e => setSize(e.target.value)}
        options={sizes.map(s => ({label: s, value: s}))} />
```

---

## Checkbox · Radio

> **لا مكوّن.** `<input>` خامٌ في ١٠ مواضع. النمط الأكثر (٣ من ٦):
```tsx
<input type="checkbox" className="size-4 accent-[#111]" />
```
تنويعات موثَّقة: `size-5 rounded accent-[#111]` · `size-4 rounded border-[#d1d5db] accent-[#111]`
· `size-4 rounded-[4px] border-[#d1d5db] text-[#111] accent-[#111] focus:ring-0`

وفي `.pos-scope`: `accent-color: #111827` عبر CSS.

**Radio** يُستعمل في بطاقةٍ قابلةٍ للنقر:
```tsx
<label className={cn('flex cursor-pointer items-center justify-between rounded-[12px] border px-4 py-3.5 transition',
  picked === code ? 'border-[#111] bg-[#fafafa]' : 'border-[#e8e8e8] hover:bg-[#fafafa]')}>
  <span>…</span>
  <input type="radio" checked={…} onChange={…} className="size-5" />
</label>
```

---

## Toggle (Switch)

**الموضع:** `resources/js/Components/Toggle.tsx` — **يدويّ لا Radix**

```tsx
<div className="flex items-center justify-between gap-3 py-2">
  <div>
    <p className="text-sm font-medium text-[#111]">{label}</p>
    {hint && <p className="mt-0.5 text-[12px] text-[#9ca3af]">{hint}</p>}
  </div>
  <button type="button" role="switch" aria-checked={on}
    className={cn('relative h-6 w-12 shrink-0 rounded-full transition-colors',
                  on ? 'bg-[#111]' : 'bg-[#d1d5db]')}>
    <span className={cn('absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start]',
                        on ? 'start-[26px]' : 'start-0.5')} />
  </button>
</div>
```

**Props:** `on` · `onChange(boolean)` · `label` · `hint`

> المقبض يتحرّك بـ`inset-inline-start` لا `left` — فينقلب مع اتجاه المستند بلا شيفرةٍ إضافية.
> ومجموعة المفاتيح تُفصل بـ`divide-y divide-[var(--ui-border,#e8e8e8)]`.

---

## Card

**الموضع:** `resources/js/Components/ui/card.tsx`

```
rounded-[var(--ui-radius,16px)] border border-[var(--ui-border,#e8e8e8)] bg-white
shadow-[0_1px_2px_rgba(0,0,0,0.04)]
```

| الجزء | الأصناف |
|---|---|
| `CardHeader` | `flex flex-col gap-1 p-5` |
| `CardTitle` | `text-[15px] font-semibold text-[#111]` (`<h3>`) |
| `CardDescription` | `text-[13px] text-[#6b7280]` |
| `CardContent` | `p-5 pt-0` |
| `CardFooter` | `flex items-center gap-2 p-5 pt-0` |

**الحشو حسب الغرض:** `p-4` بطاقة مؤشّر · `p-5` قياسيّ · `p-6` بطاقة نموذج.
و`overflow-hidden` حين تحمل ترويسةً بحدٍّ أو جدولًا.

---

## Table

**الموضع:** `resources/js/Components/ui/table.tsx`

| الجزء | الأصناف |
|---|---|
| `Table` | ملفوفٌ في `<div className="w-full overflow-x-auto">` · الجدول `w-full caption-bottom text-sm` |
| `TableHeader` | `border-b border-[var(--ui-border,#e8e8e8)]` |
| `TableBody` | `[&_tr:last-child]:border-0` |
| `TableRow` | `border-b border-[#e8e8e8] transition-colors hover:bg-[#fafafa]` |
| `TableHead` | `h-11 px-4 text-start align-middle text-[12px] font-semibold text-[#6b7280]` |
| `TableCell` | `px-4 py-3 align-middle text-[#111]` |
| `TableEmpty` | `px-4 py-14 text-center text-sm text-[#9ca3af]` (يأخذ `colSpan`) |

> التمرير الأفقيّ **داخل الحاوية** — الجدول لا يدفع الصفحة للتمدّد.

---

## DataTable

**الموضع:** `resources/js/Components/DataTable.tsx` (٦٧٧ سطرًا)
**الغرض:** جدول القوائم المشترك — بحث + تصفية + ترتيب + ترقيم، محليًّا أو خادميًّا.

**Props المهمّة:**

| Prop | النوع | الغرض |
|---|---|---|
| `rows` | `T[]` | الصفوف |
| `columns` | `Column<T>[]` | `{key, header, cell?, value?, align?, className?, sortable?}` |
| `rowKey` | `(row) => string \| number` | مفتاح React |
| `filters` | `Filter<T>[]` | `{label, type?, options?, match?, param?, initial?, asTabs?}` |
| `searchable` | `(row) => string` | نصّ البحث المحليّ |
| `searchPlaceholder` | `string` | |
| `empty` | `ReactNode` | الحالة الفارغة |
| `toolbar` | `ReactNode` | يُعرض بين البحث والجدول |
| `server` | `ServerMode` | `{pagination, params, searchParam?, sorts?}` |
| `renderBody` | `(rows) => ReactNode` | يستبدل الجدول (عرضٌ شبكيّ) مع إبقاء الأدوات |
| `views` | `{current, onChange, options}` | مبدّل شكل العرض بأزرار أيقونة |
| `pageSize` | `number` | الوضع المحليّ |
| `initialQuery` | `string` | نصٌّ يبدأ به البحث — مصدره الرابط |

**التركيب من أعلى إلى أسفل:**

1. **شريط الأدوات** — `flex flex-wrap items-center gap-1 px-4 py-2`
   يحمل `border-b` **فقط إذا لم توجد شرائح**
2. **البحث** — بلا إطار: `<Search className="size-4 shrink-0 text-[#9ca3af]" />` + حقلٌ
   `h-9 w-full min-w-0 border-0 bg-transparent text-sm placeholder:text-[#9ca3af] focus:outline-none`
   داخل `flex min-w-0 flex-1 items-center gap-2 sm:max-w-[18rem]`
3. **«أضف فلتر»** — `DropdownMenuContent align="start" className="min-w-52"` بقوائم فرعيّة
4. **فلاتر التاريخ** — **مضمّنة** لا في القائمة:
   `flex h-8 items-center gap-1.5 rounded-[8px] px-2 text-[13px] hover:bg-[rgba(17,17,17,0.045)]`
5. **«ترتيب»** — `min-w-48` مع سهم `ChevronUp/Down size-3.5`
6. **مبدّل العرض** — `ms-auto flex items-center gap-0.5` بأزرار أيقونة
7. **الشرائح المطبَّقة** — تحمل `border-b`:
   ```
   inline-flex items-center gap-1.5 rounded-full bg-[#f2f2f0] py-1 pe-1.5 ps-3 text-[13px] text-[#111]
   ```
   بتسمية `text-[#6b7280]` وزرّ × `rounded-full p-0.5 hover:bg-[rgba(17,17,17,0.08)]`
   و«مسح الكل» `text-[13px] text-[#6b7280] hover:text-[#111]` حين يزيد المطبَّق عن واحد
8. **تبويبات الحالة** — `<Tabs className="px-4" />` بنقاطٍ من `statusDot()`، و«الكل» `#9ca3af`
9. **الجسم** — الجدول أو `renderBody` في `px-4 pb-4`
10. **الترقيم** — `flex items-center justify-between gap-3 border-t px-4 py-3`

```tsx
<DataTable
  rows={businesses} columns={columns} rowKey={b => b.id}
  filters={[
    {label:'كل الأنواع', param:'type', options:types},
    {label:'كل الحالات', param:'status', asTabs:true, options:statuses},
  ]}
  searchPlaceholder="ابحث بالاسم أو المالك…"
  empty={<span className="flex flex-col items-center gap-2">
           <Ban className="size-8 text-[#d1d5db]" />لا توجد شركات مسجلة بعد
         </span>}
  server={{pagination, params: filters, sorts}}
/>
```

> **قاعدة الترتيب**: الأعمدة القابلة للترتيب في الوضع الخادميّ تأتي من `server.sorts` وحده —
> لا تُشتقّ من `column.sortable`. «الاثنان ينحرفان، فيعرض الزرُّ عمودًا لا يرتّبه الخادم.»

---

## Dialog

**الموضع:** `resources/js/Components/ui/dialog.tsx` — على `@radix-ui/react-dialog`

| الجزء | الأصناف |
|---|---|
| `DialogOverlay` | `fixed inset-0 z-50 bg-black/20 backdrop-blur-sm` + `fade-in-0`/`fade-out-0` |
| `DialogContent` | `fixed start-1/2 top-1/2 z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 rtl:translate-x-1/2` · `rounded-[16px] border border-[#e8e8e8] bg-white` · `shadow-[0_20px_60px_rgba(0,0,0,0.15)]` · `zoom-in-95`/`zoom-out-95` |
| `DialogHeader` | `flex flex-col gap-1.5 p-5 pb-3` |
| `DialogTitle` | `text-[17px] font-semibold text-[#111]` |
| `DialogDescription` | `text-[13px] text-[#6b7280]` |
| `DialogFooter` | `flex flex-row-reverse items-center gap-2 p-5 pt-3` |
| زرّ الإغلاق | `absolute end-4 top-4 rounded-[8px] p-1.5 text-[#6b7280] hover:bg-[#f2f2f0] hover:text-[#111]` — يُخفى بـ`hideClose` |

**المقاسات:** `max-w-sm` تأكيد · `max-w-lg` افتراضي · `max-w-2xl`/`max-w-3xl` للنماذج.
جسم النافذة يُحشى يدويًّا `px-5 pb-5`.

```tsx
<Dialog open={open} onOpenChange={setOpen}>
  <DialogContent className="max-w-lg">
    <DialogHeader><DialogTitle>متجر تجريبيّ جديد</DialogTitle></DialogHeader>
    <form className="space-y-4 px-5 pb-5">…
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="ghost" type="button">إلغاء</Button>
        <Button type="submit" loading={processing}>إنشاء</Button>
      </div>
    </form>
  </DialogContent>
</Dialog>
```

---

## DropdownMenu

**الموضع:** `resources/js/Components/ui/dropdown-menu.tsx` — على `@radix-ui/react-dropdown-menu`

| الجزء | الأصناف |
|---|---|
| `Content` | `z-50 min-w-[10rem] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 shadow-[0_8px_30px_rgba(0,0,0,0.10)]` · `sideOffset={6}` |
| `SubContent` | كما فوق بـ`min-w-[8rem]` |
| `Item` | `relative flex cursor-pointer select-none items-center gap-2.5 rounded-[8px] px-3 py-2 text-sm outline-none transition-colors focus:bg-[#f2f2f0]` · `[&_svg]:size-4 [&_svg]:shrink-0` |
| `Item destructive` | `text-[#b91c1c] focus:bg-[#fef2f2]` |
| `CheckboxItem` | `py-2 pe-8 ps-3` مع علامةٍ `absolute end-2 size-4` |
| `SubTrigger` | `focus:bg-[#f2f2f0] data-[state=open]:bg-[#f2f2f0]` + `ChevronLeft ms-auto size-4` |
| `Label` | `px-3 py-1.5 text-[12px] font-semibold text-[#9ca3af]` |
| `Separator` | `-mx-1.5 my-1.5 h-px bg-[#e8e8e8]` |

**العروض:** `w-44` قائمة الصفّ · `w-56` قائمة التصدير · `min-w-52` قائمة الفلاتر.

---

## Tabs (داخل الصفحة)

**الموضع:** `resources/js/Components/Tabs.tsx`
**الغرض:** تبديل جزءٍ من الصفحة. أزرارٌ في `role="tablist"` — **لا روابط**.

```
الشريط: flex items-center gap-1 overflow-x-auto border-b border-[#e8e8e8]
التبويب: inline-flex items-center justify-center gap-2 whitespace-nowrap text-sm font-medium
        transition-colors -mb-px border-b-2 px-4 py-3
النشط:   border-[#111] text-[#111]
الخامل:  border-transparent text-[#6b7280] hover:text-[#374151]
```

> `-mb-px` يرفع حدّ التبويب فوق حدّ الشريط فيحلّ محلّه — ولولاه لظهر خطّان متجاوران.
> والنشط يتبدّل **لونًا لا حجمًا** فلا يقفز ما تحته.
> والشريط **مُلاصقٌ للحافة** (بلا `px`) — من يضعه داخل بطاقة يمرّر `className="px-4"`.

**TabItem:** `{key, label, icon?, count?, dot?, alert?}`
- `icon` — `size-4 shrink-0`
- `dot` — `size-1.5 rounded-full` بلونٍ يُمرَّر (مصدره `statusDot()`)
- `count` — `text-[12px] text-[#9ca3af]`، **والصفر لا يُعرض**
- `alert` — `size-1.5 rounded-full bg-[#dc2626]` لخطأٍ في جزءٍ غير معروض

**Props:** `tabs` · `current` · `onChange` · `className` · `trailing` (يُدفع بـ`ms-auto ps-4`)

---

## SectionTabs (بين المسارات)

**الموضع:** `resources/js/Components/SectionTabs.tsx`
**الفرق عن `Tabs`:** هذه تنقل **بين مسارات** (روابط `SmartLink`)، وتلك تبدّل جزءًا من الصفحة.
**والشكل واحدٌ عمدًا** فلا يشعر المستخدم بفارق.

- الحاوية: `mb-6 flex items-center gap-1 overflow-x-auto border-b border-[#e8e8e8]`
- **ترشيحٌ بالصلاحية**: تبويبٌ بـ`section` لا يظهر لمن لا يملكه
- **تبويبٌ واحد ليس شريطًا** — يعود `null` إذا `tabs.length < 2`
- المطابقة الحرفيّة تُقدَّم على مطابقة العائلة، فيبقى تبويبٌ نشطٌ واحد

> **لا `variant`**: كان الشكل خيارًا بين `underline` و`segmented` فانقسم النظام شريطين —
> ١٩ موضعًا على هذا و٥ على ذاك. نُزع الخيار من جذره.

---

## RangeTabs

**الموضع:** `resources/js/Components/RangeTabs.tsx` — مبنيٌّ على `Tabs`
**الغرض:** مبدّل فترة التقرير: `today | week | month | year | all`
**Props:** `current` · `only?` (أعمدة Inertia الجزئيّة)

الفترة **في الرابط لا في الجلسة**: `router.get(pathname, {range}, {preserveState, preserveScroll, replace, only})`.

---

## Badge

**الموضع:** `resources/js/Components/ui/badge.tsx`

```
inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-medium whitespace-nowrap
```

| Variant | الأصناف |
|---|---|
| `neutral` (افتراضي) | `bg-[#f2f2f0] text-[#4b4b4b]` |
| `success` | `bg-[#ecfdf5] text-[#047857]` |
| `warning` | `bg-[#fffbeb] text-[#d97706]` |
| `danger` | `bg-[#fef2f2] text-[#b91c1c]` |
| `info` | `bg-[#eff6ff] text-[#2563eb]` |
| `primary` | `bg-[#f5f3ff] text-[#6d28d9]` |
| `outline` | `border border-[#e8e8e8] text-[#4b4b4b]` |

**Props:** `variant` · `status` — مرّر نصّ الحالة ليُختار اللون تلقائيًّا من `STATUS_VARIANT`.

**تصديرٌ مرافق:** `statusDot(status): string` — لون نقطة الحالة.
> **مصدرٌ واحد لا اثنان**: تقرؤه الشارةُ في الصفّ ونقطةُ التبويب فوق الجدول. ولولا ذلك
> لظهرت الحالة الواحدة بلونين على شاشةٍ واحدة.

```tsx
<Badge status="مكتمل" />          {/* success تلقائيًّا */}
<Badge variant="warning">جزئي</Badge>
```

---

## Avatar

**الموضع:** `resources/js/Components/ui/avatar.tsx`

- `Avatar` — `relative flex size-9 shrink-0 overflow-hidden rounded-full bg-[#f2f2f0]`
- `AvatarImage` — `aspect-square size-full object-cover`
- `AvatarFallback` — `flex size-full items-center justify-center bg-[#f2f2f0] text-[13px] font-semibold text-[#4b4b4b]`

البديل يستعمل `initials()` من `lib/format`.

---

## StatCard

**الموضع:** `resources/js/Components/StatCard.tsx`

```tsx
<Card className="p-4">
  <div className="flex items-start justify-between gap-3">
    <div className="min-w-0">
      <p className="truncate text-[13px] text-[#6b7280]">{label}</p>
      <p className="mt-1.5 text-[20px] font-bold tracking-tight text-[#111]">{value}</p>
    </div>
    <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-[12px]', TONE[color])}>
      <Icon className="size-5" />
    </span>
  </div>
  {trend && <p className={cn('mt-3 flex items-center gap-1 text-[12px] font-medium',
                             up ? 'text-[#047857]' : 'text-[#b91c1c]')}>
    {up ? <ArrowUpRight className="size-3.5"/> : <ArrowDownRight className="size-3.5"/>}{trend}
  </p>}
</Card>
```

**نغمات الأيقونة (`TONE`):**
```
primary   bg-[#f5f3ff] text-[#6d28d9]      secondary bg-[#fdf2f8] text-[#be185d]
success   bg-[#ecfdf5] text-[#047857]      warning   bg-[#fffbeb] text-[#d97706]
danger    bg-[#fef2f2] text-[#b91c1c]      info      bg-[#eff6ff] text-[#2563eb]
```

**Stat:** `{label, value, icon, color, trend?, up?}` — و`icon` **اسمٌ نصّيّ** يُحلّ عبر خريطة
`ICONS` صريحة (٣٧ أيقونة)، لا استيرادًا شاملًا.

**الحركة:** `initial {opacity:0, y:10}` مع `delay: index * 0.05`.

---

## StatGrid

**الموضع:** `resources/js/Components/StatGrid.tsx`
**الغرض:** شبكة مؤشّراتٍ **قابلة للتخصيص** — يخفي التاجر ما لا يريد ويضيف ما يريد.
**التخزين:** `localStorage` بمفتاح `abaad:stats:{storageKey}:hidden` و`:added`.
> «هذا تفضيل عرضٍ لا بيانات عمل — ولا يستحقّ طلب شبكة عند كل نقرة.» والتسمية هي المفتاح
> لأن ترتيب البطاقات قد يتغيّر بين الإصدارات بينما تسمياتها ثابتة. وكل قراءة/كتابة في
> `try/catch` (التصفّح الخاصّ قد يمنع).

---

## RowActions

**الموضع:** `resources/js/Components/RowActions.tsx`
**الغرض:** قائمة إجراءات الصفّ + تأكيد الحذف.

المُشغّل: `<Button variant="ghost" size="icon-sm"><MoreVertical /></Button>`
والقائمة `align="end" className="w-44"`.

**Props:**
- `show?: {href, routeName}` → بند «عرض» بأيقونة `Eye`
- `edit?: {href, routeName}` → بند «تعديل» بأيقونة `Pencil`
- `extra?: RowAction[]` → `{label, icon?, href?, routeName?, onSelect?, danger?}`
- `destroy?: {url, message?, label?}` → يسبقه فاصل، ولونه `#b91c1c`

**نافذة التأكيد:** `max-w-sm` · العنوان «تأكيد الحذف» أو «تأكيد الإجراء» إن كان `label` مخصَّصًا
· النصّ `text-sm text-[#4b4b4b]` · زرّان `outline` ثمّ `danger` محاذاةً للنهاية، يُعطَّلان أثناء التنفيذ.

> **`label` موجودٌ لأن الإجراء ليس حذفًا دائمًا**: «تعطيل شركة يغيّر حالتها ولا يمحو سجلّها.»

---

## ExportMenu

**الموضع:** `resources/js/Components/ExportMenu.tsx`
**Props:** `xlsx?` · `pdf?` · `csv?` · `label?` (افتراضي «تصدير»)
المُشغّل `<Button variant="outline"><Download />تصدير</Button>` والقائمة `align="end" className="w-56"`.
**يعود `null`** إذا لم يُمرَّر أيّ رابط — فلا يظهر زرٌّ لا يفعل شيئًا.

---

## SmartLink

**الموضع:** `resources/js/Components/SmartLink.tsx`
**الغرض:** كل تنقّلٍ داخل اللوحة — `<Link>` من Inertia بجلبٍ مسبق.
```tsx
<Link href={href} prefetch="hover" cacheFor="30s" {...props} />
```
**Props:** `routeName` (للتوثيق والبحث) · `href` · `children`
> **لا يفحص الصلاحية.** رابطٌ إلى قسمٍ لا يملكه المستخدم يقود إلى ٤٠٣ — افحص
> `auth.abilities` قبل عرضه.

---

## PageHeader

**الموضع:** `resources/js/Components/PageHeader.tsx`

```tsx
<div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
  <div className="min-w-0">
    <h1 className="truncate text-[22px] font-bold tracking-tight text-[#111]">{title}</h1>
    {subtitle && <p className="mt-0.5 text-[13px] text-[#6b7280]">{subtitle}</p>}
  </div>
  {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
</div>
```

**Props:** `title` · `subtitle?` · `actions?`
> **بلا `breadcrumbs`** — نُزعت من المكوّن نفسه. لا تُعِدها.
> و`flex-wrap` على الإجراءات: `shrink-0` وحده كان يدفع الأزرار خارج الشاشة على الجوال.

---

## Sidebar · Topbar · AdminLayout · PlatformLayout

انظر [`DESIGN_SYSTEM.md § ٢`](./DESIGN_SYSTEM.md).
**نقطةٌ بنيويّة:** `PlatformLayout` **ليس نسخة** من `AdminLayout` — هو `AdminLayout` نفسه
بقائمة تنقّلٍ أخرى. فأي تعديلٍ على القشرة يسري على اللوحتين ولا تتباعدان.

---

## AreaChart · BarChart

**الموضع:** `resources/js/Components/charts/`
**بلا مكتبة رسوم** — SVG يدويّ. التسميات كلّها HTML داخل `foreignObject` لا `<text>`.

**AreaChart** — سلسلة زمنيّة واحدة:
- الخطّ `stroke="#7c3aed"` بعرض 2px
- التعبئة متدرّجة من `opacity 0.16` إلى `0`
- الشبكة `stroke="#eeeeee"`
- خطّ التعقّب `stroke="#d1d5db"`، والنقطة `fill="#7c3aed"` (وبلا قيمة `#c4b5fd`) بحدٍّ أبيض
- الحشو `{top: 12, bottom: 26, gutter: 56, edge: 8}`
- **`null` ≠ صفر**: الأولى «لم يأتِ بعد» والثانية «لم يُبَع شيء»
- السقف يُرفع إلى أقرب خطوةٍ من عائلة `1 / 2 / 2.5 / 5 / 10` فتُقرأ أرقام المحور بلا حساب

**BarChart** — أشرطة أفقيّة:
- ستّ نغمات فئويّة `['#7c3aed','#059669','#2563eb','#d97706','#ec4899','#0891b2']`
- المسار `h-2 w-full overflow-hidden rounded-full bg-[#f2f2f0]`
- الحالة الفارغة `py-12 text-center text-sm text-[#9ca3af]`

---

# أنماطٌ بلا مكوّن

هذه تتكرّر في الصفحات **بلا مكوّنٍ مشترك**. انسخها كما هي.

## Alert / Callout مضمّن

```tsx
{/* تحذير */}
<p className="flex items-start gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
  <AlertTriangle className="mt-0.5 size-4 shrink-0" />
  <span>…</span>
</p>

{/* خطر */}
<div className="flex items-start gap-3 rounded-[12px] bg-[#fef2f2] p-4">
  <AlertTriangle className="mt-0.5 size-5 shrink-0 text-[#b91c1c]" />
  <p className="text-[13px] leading-relaxed text-[#7f1d1d]">…</p>
</div>

{/* نجاح — على هيئة بطاقة */}
<Card className="mb-6 flex items-start gap-3 border-[#d1fae5] bg-[#f0fdf4] p-4">
  <ShieldCheck className="mt-0.5 size-5 shrink-0 text-[#047857]" />
  <div className="min-w-0 text-[13px] leading-relaxed text-[#065f46]">…</div>
</Card>

{/* معلومة محايدة */}
<div className="rounded-[12px] bg-[#fafafa] px-4 py-3 text-[12px] leading-relaxed text-[#6b7280]">…</div>
```

**الشريط العلويّ للاشتراك** (`SubscriptionBanner`):
```
mb-4 flex flex-wrap items-center gap-2 rounded-[12px] border px-4 py-3 text-[13px]
عاجل:  border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]
تحذير: border-[#fde68a] bg-[#fffbeb] text-[#b45309]
```

## Empty State

**داخل جدول** — عبر `empty` في `DataTable` أو `TableEmpty`:
```tsx
<span className="flex flex-col items-center gap-2">
  <Ban className="size-8 text-[#d1d5db]" />
  لا توجد شركات مسجلة بعد
</span>
```

**بطاقة فارغة كاملة:**
```tsx
<Card className="p-12 text-center">
  <FlaskConical className="mx-auto size-10 text-[#d1d5db]" />
  <h3 className="mt-4 font-bold text-[#111]">لا متجر تجريبيّ بعد</h3>
  <p className="mx-auto mt-1 max-w-md text-sm text-[#6b7280]">…</p>
  <Button className="mt-5"><Plus />متجر تجريبيّ جديد</Button>
</Card>
```

> **قاعدة**: «لا نتائج» تُفرَّق عن «لا بيانات». التصفية التي تُرجع صفرًا تقول ذلك صراحةً بدل
> أن تبدو كقائمة فارغة.

## Error State

- **خطأ حقل** — في `Field`: `text-[12px] text-[#b91c1c]`
- **خطأ في تبويبٍ مطويّ** — `tab.alert` → نقطة `size-1.5 bg-[#dc2626]`
- **خطأ خادميّ** — `toast.error` عبر `sonner`
- **لا صفحة خطأٍ مخصَّصة** في الواجهة عدا `Auth/Maintenance`

## Loading State

- **الزرّ** — `loading` (انظر Button)
- **الصفحة** — دخول framer-motion، بلا هياكل عظميّة
- **التغذية الحيّة** — استطلاعٌ صامت (٢٠ ثانية للتقارير · ٣٠ للإشعارات)، بلا مؤشّر:
  «صفحة تُترك مفتوحة لا يجوز أن تتجمّد على أرقام الصباح»

## شريط الحفظ

```tsx
<Card className="mt-6 flex flex-col gap-3 p-4 sm:flex-row sm:justify-end">
  <Button variant="outline" className="sm:w-32" asChild><Link href="…">إلغاء</Link></Button>
  <Button type="submit" className="sm:w-40" loading={form.processing}>حفظ</Button>
</Card>
```
أو داخل بطاقةٍ ذات ترويسة: `flex justify-end border-t border-[#e8e8e8] bg-[#fafafa] px-5 py-3`.

## صفٌّ من الحقائق (تفاصيل)

```tsx
<div className="flex items-center gap-1.5">
  <span className="text-[#9ca3af]">{label}</span>
  <span dir="ltr" className="truncate font-mono text-[#111]">{value}</span>
</div>
```
القيم اللاتينية (البريد · الهاتف · التاريخ · المعرّف) تأخذ `dir="ltr"` دائمًا.

## شبكة عدّادات ملتصقة

```tsx
<div className="grid grid-cols-2 gap-px bg-[var(--ui-border,#e8e8e8)] sm:grid-cols-4 lg:grid-cols-8">
  <div className="bg-white px-4 py-3">
    <p className="text-[11px] text-[#9ca3af]">{label}</p>
    <p className="mt-0.5 text-[17px] font-bold tabular-nums text-[#111]">{value}</p>
  </div>
</div>
```
`gap-px` على خلفيةٍ بلون الحدّ يصنع شبكةً بخطوطٍ رفيعة بلا حدودٍ مزدوجة.

## الأرقام

`tabular-nums` على كل رقمٍ في عمودٍ أو شبكة — فتصطفّ الخانات رأسيًّا.
والتنسيق من `lib/format`: `money(value, currency)` · `number(value, decimals)` ·
`percent(value, decimals)` · `initials(name)` · `decimalsFor(code)` · `currencyLabel(currency)`
