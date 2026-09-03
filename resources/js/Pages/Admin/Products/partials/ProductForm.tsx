import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, ImagePlus, Plus, X } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Tabs from '@/Components/Tabs';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { csrfHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import AddonDialog from './AddonDialog';
import Composition, { emptyDraft, type AddonOption, type CompositionData, type CompositionDraft } from './Composition';
import Gallery, { type GalleryImage } from './Gallery';
import type { Currency } from '@/types';
import type { Category, Product } from '@/types/models';

interface Props {
    categories: Category[];
    /** موجود = تعديل، غائب = إنشاء */
    product?: Product;
    description?: string;
    currencyLabel: string;
    /** المقاسات والوصفة والإضافات — قوائمُ الاختيار في الحالتين */
    composition?: CompositionData | null;
    currency?: Currency;
    /** معرض الصور — في التعديل وحده: المعرّض يُعلَّق بمنتجٍ له معرّف */
    gallery?: GalleryImage[];
    galleryMax?: number;
    galleryLimits?: { perFile: number; batch: number };
}

const NAV = [
    { key: 'basic', label: 'المعلومات الأساسية' },
    { key: 'pricing', label: 'التسعير' },
    { key: 'stock', label: 'المخزون' },
    { key: 'media', label: 'صور المنتج' },
    /*
     * التركيب: المقاسات والوصفة والإضافات.
     *
     * يُعرض عند الإنشاء أيضًا — يبقى مسوّدةً في الشاشة ويُكتب مع المنتج في
     * طلب الحفظ نفسه. وكان يُخفى فيُجبَر التاجر على حفظ الباقة ثم العودة
     * إليها ليقول ممّ تتركّب: خطوتان لفعلٍ واحد في ذهنه.
     */
    { key: 'composition', label: 'التركيب' },
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
    published: 'basic',
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
export default function ProductForm({ categories, product, description, currencyLabel, composition, currency, gallery, galleryMax, galleryLimits }: Props) {
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
        published: product?.published ?? true,
        image: null as File | null,
        // مسوّدة التركيب — تُملأ عند الإنشاء وتُكتب في الخادم بعد إنشاء
        // المنتج مباشرة. وفي التعديل تبقى فارغةً: هناك لكلّ فعلٍ مسارُه
        composition: emptyDraft() as CompositionDraft,
        // Inertia لا يرسل PUT مع ملف؛ التزييف هو الطريق الرسمي
        ...(editing ? { _method: 'put' } : {}),
    });

    const [preview, setPreview] = useState<string>(product?.image ?? '');

    /*
     * قسمٌ يُنشأ من جانب حقله.
     *
     * لم يكن في النظام بابُ إنشاء أقسامٍ إطلاقًا — تأتي من تهيئة نوع النشاط
     * أو من استيراد ملفّ. فمن أراد قسمًا جديدًا وهو يُدخل منتجًا لم يكن
     * أمامه إلّا أن يتركه بلا قسم.
     *
     * وبـfetch لا بتنقّل: النموذج نصفُه مملوء، وإعادةُ تحميل الصفحة تمحو ما
     * كُتب ولم يُحفظ.
     */
    const [cats, setCats] = useState(categories);
    const [newCat, setNewCat] = useState<string | null>(null);
    const [catError, setCatError] = useState<string | null>(null);
    const [savingCat, setSavingCat] = useState(false);

    const addCategory = async () => {
        const name = (newCat ?? '').trim();
        if (!name) return;

        setSavingCat(true);
        setCatError(null);
        try {
            const res = await fetch(route('admin.products.categories.store'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ name }),
            });
            const body = await res.json();

            if (!res.ok) {
                setCatError(body?.errors?.name?.[0] ?? t('تعذّر إضافة القسم'));

                return;
            }

            setCats((prev) => [...prev, body.category]);
            form.setData('category_id', String(body.category.id));
            setNewCat(null);
        } catch {
            setCatError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setSavingCat(false);
        }
    };

    /*
     * إضافاتٌ تُعرض مع كلّ المنتجات — بجانب القسم لأنّها تُقرَّر معه.
     *
     * «تغليف» و«بطاقة معايدة» يريدهما المتجر على كلّ شيء يبيعه، فمكانُهما
     * الصفُّ الأول لا قسمٌ داخليّ يُفتح لكلّ منتجٍ على حدة. والخاصّة بمنتجٍ
     * بعينه تبقى في «التركيب» — هناك يُقرَّر ما يخصّه وحده.
     *
     * والقائمة يملكها النموذج لا القسمان: إضافةٌ تُنشأ هنا يجب أن تُرى هناك
     * في اللحظة نفسها، وحالتان منفصلتان تفترقان بلا سبب.
     */
    const [addonList, setAddonList] = useState<AddonOption[]>(composition?.addons ?? []);

    /** النافذة مفتوحةٌ على إضافةٍ تُعدَّل، أو على `null` لواحدةٍ جديدة */
    const [addonOpen, setAddonOpen] = useState<AddonOption | null | undefined>(undefined);

    const shopAddons = addonList.filter((a) => !a.private);

    /**
     * تُضاف أو تُستبدل — لا تُلحق دائمًا.
     *
     * تعديلُ إضافةٍ كان يُلحقها بالقائمة مرّةً ثانية، فيرى التاجر «شوكولاتة»
     * مرّتين بسعرين ولا يعرف أيّهما حُفظ.
     */
    const upsertAddon = (saved: AddonOption) =>
        setAddonList((prev) => {
            const at = prev.findIndex((a) => a.value === saved.value);

            if (at === -1) {
                return [...prev, saved];
            }

            const next = [...prev];
            next[at] = saved;

            return next;
        });

    /**
     * منتجٌ لم يُحفظ بعد: الإضافة تبقى مسوّدةً وتُنشأ معه في طلبٍ واحد.
     *
     * ومعرّفُها سالبٌ للعرض وحده — لا يُرسل إلى الخادم، انظر تمرير `addons`
     * إلى قسم التركيب أدناه. والخاصّةُ بالمنتج تُعرض هناك من `new_addons`
     * نفسها، فلا تُضاف إلى قائمة الشرائح هنا مرّةً ثانية.
     */
    const draftAddon = (saved: AddonOption) => {
        const own = saved.scope === 'product';

        form.setData('composition', {
            ...form.data.composition,
            new_addons: [...form.data.composition.new_addons, {
                name: saved.label,
                name_en: saved.name_en ?? null,
                price: String(saved.price),
                private: own,
                inventory_product_id: saved.inventory_product_id,
                inventory_quantity: saved.inventory_quantity ?? null,
            }],
        });

        if (! own) {
            setAddonList((prev) => [...prev, { ...saved, value: -(prev.length + 1) }]);
        }
    };

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

    /**
     * القسم الذي يقع فيه الخطأ — والتركيب حقولُه مركّبة.
     *
     * `composition.recipe.0.quantity` لا يوجد في الخريطة، فكان يسقط على
     * «المعلومات الأساسية» ولا حقلَ هناك يعرضه: يُردّ الحفظ ولا يُرى سببه،
     * فيبدو الزرّ كأنه لا يعمل.
     */
    const sectionOf = (field: string): TabKey =>
        field === 'composition' || field.startsWith('composition.')
            ? 'composition'
            : (FIELD_SECTION[field] ?? 'basic');

    const sectionsWithErrors = new Set(Object.keys(form.errors).map(sectionOf));

    /*
     * كلّ رسائل الخطأ في مكانٍ واحد يُرى من أيّ قسم.
     *
     * الخطأ الذي لا يعرضه حقلٌ لا وجود له في نظر المستخدم — ورسالةٌ واحدة
     * غير معروضة تكفي لأن يبدو «حفظ» ميّتًا.
     */
    const errorList = Object.values(form.errors).filter(Boolean) as string[];

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        /*
         * الحقول الإلزامية قد تكون في قسم غير معروض، فلا يستطيع المتصفّح
         * إبرازها ويبدو الزرّ كأنه لا يعمل. نكشفها بأنفسنا ونقفز إليها.
         *
         * وتُكتب الرسالة قبل القفز: كان الخروج صامتًا — يضغط «حفظ» فلا يقع
         * شيء يُرى، ولا سيّما إن كان واقفًا على القسم نفسه فلا يتبدّل شيء
         * أمامه. ومن سعّر مقاساته يترك سعر المنتج فارغًا ولا يظنّه ناقصًا.
         */
        if (!form.data.name.trim()) {
            form.setError('name', t('اكتب اسم المنتج قبل الحفظ.'));
            pick('basic');

            return;
        }

        if (!String(form.data.price).trim()) {
            form.setError('price', t('اكتب سعر البيع — ولو كان للمنتج مقاسات، يبقى سعرًا أساسًا له.'));
            pick('pricing');

            return;
        }

        form.clearErrors();

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
                if (first) pick(sectionOf(first));
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

                                <Field label="القسم" error={form.errors.category_id ?? catError ?? undefined}>
                                    {newCat === null ? (
                                        <span className="flex items-center gap-2">
                                            <Select
                                                className="flex-1"
                                                value={form.data.category_id}
                                                onChange={(e) => form.setData('category_id', e.target.value)}
                                                options={cats.map((c) => ({
                                                    label: c.name,
                                                    value: c.id,
                                                }))}
                                                placeholder="اختر القسم"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                aria-label={t('إضافة قسم')}
                                                title={t('إضافة قسم')}
                                                onClick={() => {
                                                    setNewCat('');
                                                    setCatError(null);
                                                }}
                                            >
                                                <Plus />
                                            </Button>
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-2">
                                            <Input
                                                autoFocus
                                                className="flex-1"
                                                value={newCat}
                                                placeholder={t('اسم القسم الجديد')}
                                                onChange={(e) => setNewCat(e.target.value)}
                                                /* «إدخال» يحفظ القسم ولا يُرسل المنتج: النموذج
                                                   محيطٌ بهذا الحقل، وتركُ الحدث يصعد كان يحفظ
                                                   منتجًا نصفَ مكتمل */
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        void addCategory();
                                                    }
                                                    if (e.key === 'Escape') {
                                                        setNewCat(null);
                                                        setCatError(null);
                                                    }
                                                }}
                                            />
                                            <Button
                                                type="button"
                                                size="icon"
                                                aria-label={t('حفظ القسم')}
                                                loading={savingCat}
                                                onClick={() => void addCategory()}
                                            >
                                                <Check />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                aria-label={t('إلغاء')}
                                                onClick={() => {
                                                    setNewCat(null);
                                                    setCatError(null);
                                                }}
                                            >
                                                <X />
                                            </Button>
                                        </span>
                                    )}
                                </Field>

                                {/* المكان الذي كان فارغًا بجانب القسم: الإضافات
                                    العامّة تُقرَّر مع القسم لا في قسمٍ يُفتح لكلّ
                                    منتج */}
                                <Field
                                    label="إضافات مع كلّ المنتجات"
                                    hint="اضغط إضافةً لتعديل سعرها أو مداها أو ما تخصمه من المخزون."
                                >
                                    <span className="flex items-start gap-2">
                                        <span className="flex min-h-[42px] flex-1 flex-wrap items-center gap-1.5 rounded-[10px] border border-[#e8e8e8] px-2 py-1.5">
                                            {shopAddons.length === 0 ? (
                                                <span className="px-1 text-[13px] text-[#9ca3af]">{t('لا إضافات عامّة')}</span>
                                            ) : (
                                                shopAddons.map((a) => (
                                                    /* الشريحة نفسها هي بابُ التعديل: زرٌّ ثالث بجانب
                                                       كلّ إضافةٍ يملأ الحقل بأزرارٍ لا بمعلومات */
                                                    <button
                                                        key={a.value}
                                                        type="button"
                                                        disabled={a.value < 0}
                                                        onClick={() => setAddonOpen(a)}
                                                        className="rounded-[8px] bg-[#f3f3f1] px-2 py-1 text-[12px] text-[#4b4b4b] enabled:hover:bg-[#e8e8e6]"
                                                    >
                                                        {a.label}
                                                        <span className="ms-1 tabular-nums opacity-60">
                                                            {a.price} {currencyLabel}
                                                        </span>
                                                        {a.scope === 'selected' && (
                                                            <span className="ms-1 opacity-60">· {t('منتجات محددة')}</span>
                                                        )}
                                                        {a.inventory_product_id && (
                                                            <span className="ms-1 opacity-60">
                                                                · {t('تخصم')} {a.inventory_quantity ?? 1}
                                                            </span>
                                                        )}
                                                    </button>
                                                ))
                                            )}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={t('إضافة جديدة')}
                                            title={t('إضافة جديدة')}
                                            onClick={() => setAddonOpen(null)}
                                        >
                                            <Plus />
                                        </Button>
                                    </span>
                                </Field>
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
                            {/*
                                مفتاحان لا واحد: «يُباع» غير «يُعرض على الإنترنت».

                                وكان المفتاح الأوّل يقول «في نقطة البيع والمتجر»
                                فيحكم الاثنين معًا — فمن أراد إخفاء ورق التغليف
                                عن زبائن موقعه اضطرّ إلى إيقاف بيعه عند الطاولة.
                            */}
                            <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                                <div className="flex items-center justify-between gap-3 pb-4">
                                    <div>
                                        <p className="text-sm font-medium text-[#111]">{t('تفعيل المنتج')}</p>
                                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                            {t('إظهاره في نقطة البيع والفواتير')}
                                        </p>
                                    </div>
                                    <Switch on={form.data.active} onChange={() => form.setData('active', !form.data.active)} />
                                </div>

                                <div className="flex items-center justify-between gap-3 pt-4">
                                    <div>
                                        <p className="text-sm font-medium text-[#111]">{t('عرضه في متجرك على الإنترنت')}</p>
                                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                            {form.data.active
                                                ? t('يظهر لزوّار متجرك بصورته وسعره')
                                                : t('لن يظهر ما دام غير مفعّل')}
                                        </p>
                                    </div>
                                    <Switch on={form.data.published} onChange={() => form.setData('published', !form.data.published)} />
                                </div>
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
                        {/*
                            بابان لا يجتمعان.

                            في التعديل يملك المعرضُ الصورَ كلَّها: الرئيسية
                            والإضافية، والترقية والحذف. وإبقاءُ حقل الرفع
                            الواحد بجانبه يعني بابين إلى الشيء نفسه — أحدهما
                            يمرّ بنموذج المنتج فيكتب السعر والكمية معه.

                            وفي الإنشاء لا معرض: المعرّض يُعلَّق بمنتجٍ له
                            معرّف، ولا معرّف قبل الحفظ. فيبقى الحقل الواحد،
                            وتُقال الخطوة التالية صراحةً — كما في تبويب
                            التركيب.
                        */}
                        {editing ? (
                            <Gallery
                                productId={product!.id}
                                images={gallery ?? []}
                                max={galleryMax ?? 8} limits={galleryLimits ?? { perFile: 2048, batch: 7680 }}
                            />
                        ) : (
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
                            <p className="mt-3 text-[12px] text-[#9ca3af]">
                                {t('احفظ المنتج أوّلًا لتضيف صورًا أخرى وتختار الرئيسية بينها.')}
                            </p>
                        </Card>
                        )}
                    </div>
                )}

                {tab === 'composition' &&
                    (composition && currency ? (
                        <Composition
                            productId={product ? Number(product.id) : null}
                            data={composition}
                            currency={currency}
                            draft={form.data.composition}
                            onDraft={(next) => form.setData('composition', next)}
                            /* المسوّدة تُعرض هناك من `new_addons` نفسها — ومعرّفها
                               الموجب لم يوجد بعد، فلا يُعرض مرّتين ولا يُرسَل */
                            addons={addonList.filter((a) => a.value > 0)}
                            onAddonSaved={upsertAddon}
                        />
                    ) : (
                        <Card className="p-6">
                            <p className="text-[13px] leading-relaxed text-[#6b7280]">
                                {t('احفظ المنتج أوّلًا، ثم أضف مقاساته ووصفته وإضافاته.')}
                            </p>
                        </Card>
                    ))}

                {errorList.length > 0 && (
                    <Card className="mt-6 border-[#fca5a5] bg-[#fef2f2] p-4">
                        <p className="mb-1 text-[13px] font-semibold text-[#b91c1c]">
                            {t('لم يُحفظ المنتج:')}
                        </p>
                        <ul className="space-y-0.5 ps-4 text-[13px] text-[#b91c1c]">
                            {errorList.map((message, i) => (
                                <li key={i} className="list-disc">{message}</li>
                            ))}
                        </ul>
                    </Card>
                )}

                {addonOpen !== undefined && (
                    <AddonDialog
                        addon={addonOpen}
                        productId={product ? Number(product.id) : null}
                        drafting={!product}
                        stockItems={composition?.stock_items ?? []}
                        products={composition?.products ?? []}
                        onClose={() => setAddonOpen(undefined)}
                        /* المعرّف صفرًا يعني مسوّدةً لم تُكتب في القاعدة بعد */
                        onSaved={(saved) => (saved.value === 0 ? draftAddon(saved) : upsertAddon(saved))}
                    />
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

/** مفتاح تبديل صغير — كان مكرّرًا حرفًا بحرف حين صار المفتاحان اثنين */
function Switch({ on, onChange }: { on: boolean; onChange: () => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            onClick={onChange}
            className={cn(
                'relative h-6 w-12 shrink-0 rounded-full transition-colors',
                on ? 'bg-[#111]' : 'bg-[#d1d5db]',
            )}
        >
            {/* المقبض يتحرك بالخاصية المنطقية فينعكس تلقائيًا في RTL */}
            <span
                className={cn(
                    'absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start]',
                    on ? 'start-[26px]' : 'start-0.5',
                )}
            />
        </button>
    );
}
