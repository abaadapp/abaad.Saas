import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, ImagePlus } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Tabs from '@/Components/Tabs';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Category, Product } from '@/types/models';

interface Props {
    categories: Category[];
    /** موجود = تعديل، غائب = إنشاء */
    product?: Product;
    description?: string;
    currencyLabel: string;
}

const NAV = [
    { key: 'basic', label: 'المعلومات الأساسية' },
    { key: 'pricing', label: 'التسعير' },
    { key: 'stock', label: 'المخزون' },
    { key: 'media', label: 'صور المنتج والإضافات' },
] as const;

type TabKey = (typeof NAV)[number]['key'];

/**
 * القسم الذي يقع فيه كل حقل — يخدم غرضين:
 * إبراز الأقسام التي فيها أخطاء، والقفز إلى أوّلها بعد ردّ الخادم.
 *
 * بلا هذه الخريطة يبقى الخطأ في قسم مطويّ فلا يراه المستخدم، ويظنّ أن الحفظ
 * لم يستجب أصلًا.
 */
const FIELD_SECTION: Record<string, TabKey> = {
    name: 'basic',
    name_en: 'basic',
    description: 'basic',
    category_id: 'basic',
    sku: 'basic',
    barcode: 'basic',
    active: 'basic',
    price: 'pricing',
    cost: 'pricing',
    tax: 'pricing',
    discount: 'pricing',
    quantity: 'stock',
    alert_qty: 'stock',
    image: 'media',
};

/**
 * نموذج المنتج — يخدم الإنشاء والتعديل معًا.
 *
 * القالبان في Blade كانا نسختين شبه متطابقتين، فأي تعديل على أحدهما كان
 * يُنسى في الآخر. هنا حقل واحد لكل معنى.
 *
 * الأقسام تُعرض بشريط تبويبات علوي بدل تكديس أربع بطاقات في صفحة طويلة:
 * المستخدم يرى أين هو وكم بقي. والحقول المخفيّة تبقى قيمها محفوظة في حالة
 * النموذج، فالتنقّل بين الأقسام لا يفقد شيئًا.
 */
export default function ProductForm({ categories, product, description, currencyLabel }: Props) {
    const t = useTranslate();
    const editing = !!product;
    const [tab, setTab] = useState<TabKey>('basic');

    const form = useForm({
        name: product?.name ?? '',
        name_en: product?.name_en ?? '',
        description: description ?? '',
        category_id: String(categories.find((c) => c.name === product?.cat)?.id ?? ''),
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        price: product ? String(product.price) : '',
        cost: product ? String(product.cost) : '',
        // فراغٌ لا «٥»: الفراغ يعني «اتبع نسبة المتجر» فلا تُثبَّت نسبةٌ على الصنف بلا قصد
        tax: product?.tax == null ? '' : String(product.tax),
        discount: product ? String(product.discount) : '',
        quantity: product ? String(product.qty) : '',
        alert_qty: product ? String(product.alert) : '',
        active: product ? product.active : true,
        image: null as File | null,
        // Inertia لا يرسل PUT مع ملف؛ التزييف هو الطريق الرسمي
        ...(editing ? { _method: 'put' } : {}),
    });

    const [preview, setPreview] = useState<string>(product?.image ?? '');

    /**
     * الشريط العلوي قد يخرج عن الشاشة بعد التمرير داخل قسم طويل، فالقفزُ إليه
     * يُبقي التبويبات في المشهد ويُظهر أن القسم تبدّل فعلًا.
     */
    const contentRef = useRef<HTMLFormElement>(null);
    const pick = (key: TabKey) => {
        setTab(key);
        requestAnimationFrame(() =>
            contentRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
        );
    };

    const sectionsWithErrors = new Set(
        Object.keys(form.errors).map((f) => FIELD_SECTION[f] ?? 'basic'),
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        // الحقول الإلزامية قد تكون في قسم غير معروض، فلا يستطيع المتصفّح
        // إبرازها ويبدو الزرّ كأنه لا يعمل. نكشفها بأنفسنا ونقفز إليها.
        const missing = !form.data.name.trim()
            ? ('basic' as TabKey)
            : !String(form.data.price).trim()
              ? ('pricing' as TabKey)
              : null;
        if (missing) {
            pick(missing);
            return;
        }

        const url = editing
            ? route('admin.products.update', product!.id)
            : route('admin.products.store');
        form.post(url, {
            forceFormData: true,
            // التعديل يعود إلى صفحته نفسها، فبقاء موضع التمرير يمنع قفزةً
            // إلى الأعلى تُربك من كان أسفل قسمٍ طويل. والإضافة تنتقل إلى
            // القائمة فلا يعنيها هذا.
            preserveScroll: true,
            onError: (errors) => {
                const first = Object.keys(errors)[0];
                if (first) pick(FIELD_SECTION[first] ?? 'basic');
            },
        });
    };

    const pickImage = (file: File | null) => {
        form.setData('image', file);
        if (file) setPreview(URL.createObjectURL(file));
    };

    /* سقفٌ للعرض مع توسيط: سطرٌ يمتدّ عبر الشاشة كاملةً يصعب تتبّعه، وحقلان
       متباعدان بفراغٍ عريض يبدوان غير مرتبطين. نفس حدّ صفحة الإعدادات
       (max-w-4xl) فيتّسق النموذجان. */
    return (
        <form onSubmit={submit} ref={contentRef} className="mx-auto min-w-0 max-w-4xl scroll-mt-4">
            <Tabs
                tabs={NAV.map((x) => ({
                    key: x.key,
                    label: x.label,
                    alert: sectionsWithErrors.has(x.key),
                }))}
                current={tab}
                onChange={(k) => pick(k as TabKey)}
                variant="segmented"
                className="mb-6"
            />

            <div className="min-w-0">
                {tab === 'basic' && (
                    <div className="space-y-6">
                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('المعلومات الأساسية')}</h3>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-4 md:col-span-2">
                                    <Field label="اسم المنتج" required error={form.errors.name}>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            placeholder={t('مثال: باقة ورد أحمر')}
                                        />
                                    </Field>
                                    <Field
                                        label="الاسم بالإنجليزية (اختياري)"
                                        hint="يظهر تلقائيًا عند تشغيل الواجهة بالإنجليزية"
                                        error={form.errors.name_en}
                                    >
                                        <Input
                                            dir="ltr"
                                            value={form.data.name_en}
                                            onChange={(e) => form.setData('name_en', e.target.value)}
                                            placeholder="e.g. Red Rose Bouquet"
                                        />
                                    </Field>
                                    <Field label="الوصف" error={form.errors.description}>
                                        <Textarea
                                            rows={4}
                                            value={form.data.description}
                                            onChange={(e) =>
                                                form.setData('description', e.target.value)
                                            }
                                            placeholder={t('اكتب وصفًا مختصرًا للمنتج…')}
                                        />
                                    </Field>
                                </div>

                                <Field label="القسم" error={form.errors.category_id}>
                                    <Select
                                        value={form.data.category_id}
                                        onChange={(e) => form.setData('category_id', e.target.value)}
                                        options={categories.map((c) => ({
                                            label: c.name,
                                            value: c.id,
                                        }))}
                                        placeholder="اختر القسم"
                                    />
                                </Field>
                                <div />
                                <Field
                                    label="رمز المنتج SKU"
                                    hint="اتركه فارغًا ليُولَّد تلقائيًا"
                                    error={form.errors.sku}
                                >
                                    <Input
                                        value={form.data.sku}
                                        onChange={(e) => form.setData('sku', e.target.value)}
                                        placeholder={t('يُولّد تلقائيًا')}
                                    />
                                </Field>
                                <Field
                                    label="الباركود"
                                    hint="اتركه فارغًا ليُولَّد تلقائيًا"
                                    error={form.errors.barcode}
                                >
                                    <Input
                                        dir="ltr"
                                        value={form.data.barcode}
                                        onChange={(e) => form.setData('barcode', e.target.value)}
                                        placeholder={t('يُولّد تلقائيًا')}
                                    />
                                </Field>
                            </div>
                        </Card>

                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('حالة المنتج')}</h3>
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-medium text-[#111]">
                                        {t('تفعيل المنتج')}
                                    </p>
                                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                        {t('إظهار المنتج في نقطة البيع والمتجر')}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={form.data.active}
                                    onClick={() => form.setData('active', !form.data.active)}
                                    className={cn(
                                        'relative h-6 w-12 shrink-0 rounded-full transition-colors',
                                        form.data.active ? 'bg-[#111]' : 'bg-[#d1d5db]',
                                    )}
                                >
                                    {/* المقبض يتحرك بالخاصية المنطقية فينعكس تلقائيًا في RTL */}
                                    <span
                                        className={cn(
                                            'absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start]',
                                            form.data.active ? 'start-[26px]' : 'start-0.5',
                                        )}
                                    />
                                </button>
                            </div>
                        </Card>
                    </div>
                )}

                {tab === 'pricing' && (
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('التسعير')}</h3>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field
                                label={`${t('سعر البيع')} (${currencyLabel})`}
                                required
                                error={form.errors.price}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.price}
                                    onChange={(e) => form.setData('price', e.target.value)}
                                    placeholder="0.000"
                                />
                            </Field>
                            <Field
                                label={`${t('سعر التكلفة')} (${currencyLabel})`}
                                error={form.errors.cost}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.cost}
                                    onChange={(e) => form.setData('cost', e.target.value)}
                                    placeholder="0.000"
                                />
                            </Field>
                            {/* الفراغ يتبع نسبة المتجر، والصفر إعلانٌ بأن الصنف
                                معفى — والخبز والحليب والدواء صفرية في عُمان */}
                            <Field label="ضريبة الصنف" error={form.errors.tax} hint="اتركها فارغة لتتبع نسبة المتجر">
                                <Select
                                    value={form.data.tax}
                                    onChange={(e) => form.setData('tax', e.target.value)}
                                    options={[
                                        { label: 'نسبة المتجر', value: '' },
                                        { label: 'صفرية (معفى)', value: '0' },
                                        { label: '5%', value: '5' },
                                        { label: '10%', value: '10' },
                                    ]}
                                />
                            </Field>
                            <Field label="الخصم (%)" error={form.errors.discount} hint="يُخصم من سعر الصنف عند البيع">
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.discount}
                                    onChange={(e) => form.setData('discount', e.target.value)}
                                    placeholder="0"
                                />
                            </Field>
                        </div>
                    </Card>
                )}

                {tab === 'stock' && (
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('المخزون')}</h3>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="الكمية المتوفرة" error={form.errors.quantity}>
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.quantity}
                                    onChange={(e) => form.setData('quantity', e.target.value)}
                                    placeholder="0"
                                />
                            </Field>
                            <Field
                                label="حد التنبيه"
                                hint="تنبيه عند انخفاض الكمية عن هذا الحد"
                                error={form.errors.alert_qty}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.alert_qty}
                                    onChange={(e) => form.setData('alert_qty', e.target.value)}
                                    placeholder="10"
                                />
                            </Field>
                        </div>
                    </Card>
                )}

                {tab === 'media' && (
                    <div className="space-y-6">
                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('صورة المنتج')}</h3>
                            <label className="group relative block aspect-video cursor-pointer overflow-hidden rounded-[12px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] transition-colors hover:border-[#8b5cf6]">
                                {preview && (
                                    <img
                                        src={preview}
                                        alt=""
                                        className="absolute inset-0 size-full object-cover"
                                    />
                                )}
                                <div
                                    className={cn(
                                        'absolute inset-0 flex flex-col items-center justify-center gap-2 text-[#9ca3af]',
                                        preview &&
                                            'bg-black/30 text-white opacity-0 transition-opacity group-hover:opacity-100',
                                    )}
                                >
                                    <ImagePlus className="size-8" />
                                    <span className="text-[12px]">
                                        {preview
                                            ? t('تغيير الصورة')
                                            : t('اسحب صورة أو انقر للرفع (حتى 4MB)')}
                                    </span>
                                </div>
                                <input
                                    type="file"
                                    hidden
                                    accept="image/*"
                                    onChange={(e) => pickImage(e.target.files?.[0] ?? null)}
                                />
                            </label>
                            {form.errors.image && (
                                <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.image}</p>
                            )}
                        </Card>

                        <Card className="p-6">
                            <h3 className="mb-1 font-bold text-[#111]">{t('الإضافات')}</h3>
                            {/* الإضافات اليوم على مستوى النشاط لا المنتج: لا عمود
                                product_id في addons ولا جدول وسيط. فلا نعرض
                                منتقيًا لا يحفظ اختياره — نصرّح بالواقع ونحيل إلى
                                مكان إدارتها الفعلي. */}
                            <p className="text-[13px] leading-relaxed text-[#6b7280]">
                                {t(
                                    'الإضافات حاليًا على مستوى المتجر لا المنتج — تظهر في نقطة البيع لكل المنتجات. ربطها بمنتج بعينه يحتاج تطويرًا في الخادم.',
                                )}
                            </p>
                            <Button variant="outline" size="sm" className="mt-4" asChild>
                                <SmartLink
                                    routeName="admin.addons.index"
                                    href={route('admin.addons.index')}
                                >
                                    {t('إدارة الإضافات')}
                                </SmartLink>
                            </Button>
                        </Card>
                    </div>
                )}

                {/* شريط الحفظ ثابت أسفل كل قسم — فلا يضطر المستخدم للعودة
                    إلى قسم بعينه ليحفظ ما كتبه */}
                <Card className="mt-6 flex flex-col gap-3 p-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" className="sm:w-32" asChild>
                        <SmartLink
                            routeName="admin.products.index"
                            href={route('admin.products.index')}
                        >
                            {t('إلغاء')}
                        </SmartLink>
                    </Button>
                    <Button type="submit" className="sm:w-40" loading={form.processing}>
                        <Check />
                        {editing ? t('حفظ التغييرات') : t('حفظ المنتج')}
                    </Button>
                </Card>
            </div>
        </form>
    );
}
