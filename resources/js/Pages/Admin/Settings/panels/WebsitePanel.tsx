import { useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import {
    Check,
    ExternalLink,
    Eye,
    Image as ImageIcon,
    LayoutGrid,
    Link2,
    Monitor,
    Package,
    Palette,
    RefreshCw,
    Rows3,
    Save,
    Share2,
    Smartphone,
    Trash2,
    Upload,
} from 'lucide-react';
import Field from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface StoreProduct {
    id: number;
    name: string;
    /** null حين لم يرفع التاجر صورة — انظر Storefront::image */
    image: string | null;
    active: boolean;
    published: boolean;
}

export interface WebsiteData {
    storeUrl: string;
    storeProducts: StoreProduct[];
}

interface Props {
    site: Record<string, string>;
    business: { name: string; phone: string | null; address: string | null; logo: string | null };
    website: WebsiteData;
    /* الشعار مسارُه مسار «بيانات النشاط» — يُدار في الصفحة الأمّ ويُعار هنا */
    logo: {
        preview: string | null;
        file: File | null;
        busy: boolean;
        pick: (file: File | null) => void;
        send: (payload: { logo: File } | { remove: true }) => void;
    };
}

/**
 * لوحةٌ من عشرين لونًا بدل منتقٍ حرّ.
 *
 * المنتقي الحرّ يُخرج أصفرَ فاقعًا على أبيض فلا يُقرأ نصٌّ ولا زرّ. وهذه
 * كلّها داكنةٌ بما يكفي لأن يقف عليها نصٌّ أبيض — والاختيار يبقى للتاجر.
 */
const PALETTE = [
    '#111827', '#1f2937', '#374151', '#7c3aed', '#6d28d9',
    '#4f46e5', '#2563eb', '#0284c7', '#0891b2', '#0d9488',
    '#059669', '#16a34a', '#65a30d', '#ca8a04', '#d97706',
    '#ea580c', '#dc2626', '#be123c', '#db2777', '#a21caf',
];

type SiteTab = 'design' | 'identity' | 'contact' | 'products';

export default function WebsitePanel({ site, business, website, logo }: Props) {
    const t = useTranslate();

    const form = useForm({
        site_published: site.site_published === '1',
        site_tagline: site.site_tagline ?? '',
        site_about: site.site_about ?? '',
        site_whatsapp: site.site_whatsapp ?? '',
        site_instagram: site.site_instagram ?? '',
        site_show_prices: site.site_show_prices === '1',
        site_allow_orders: site.site_allow_orders === '1',
        site_theme: site.site_theme || '#111827',
        site_hero_title: site.site_hero_title ?? '',
        site_hero_note: site.site_hero_note ?? '',
        site_layout: site.site_layout || 'grid',
        site_show_about: site.site_show_about === '1',
        site_show_categories: site.site_show_categories === '1',
    });

    const [tab, setTab] = useState<SiteTab>('design');
    const [wide, setWide] = useState(true);

    /*
     * المعاينة إطارٌ يفتح المتجر نفسه — لا رسمًا يشبهه.
     *
     * ورسمٌ يشبهه هو ما يفترق عنه: حقلٌ يُضاف في القالب ولا يُضاف في الرسم
     * فيرى التاجر غير ما يرى زبونه. والمفتاح يُبدَّل بعد كل حفظةٍ ليُعاد
     * تحميل الإطار — وإلّا بقي يعرض ما قبل الحفظ.
     */
    const [previewKey, setPreviewKey] = useState(0);
    const reloadPreview = () => setPreviewKey((n) => n + 1);

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.website.save'), {
            preserveScroll: true,
            onSuccess: reloadPreview,
        });
    };

    /* مفتاح النشر يُحفظ وحده فور تبديله: لا معنى لأن يُنشر متجرٌ بضغطتين */
    const togglePublished = (on: boolean) => {
        form.setData('site_published', on);
        router.post(
            route('admin.marketing.website.save'),
            { ...form.data, site_published: on },
            { preserveScroll: true, onSuccess: reloadPreview },
        );
    };

    const [coverFile, setCoverFile] = useState<File | null>(null);
    const [coverBusy, setCoverBusy] = useState(false);

    const sendCover = (payload: { cover: File } | { remove: true }) => {
        setCoverBusy(true);
        router.post(route('admin.marketing.website.cover'), payload, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setCoverBusy(false);
                setCoverFile(null);
                reloadPreview();
            },
        });
    };

    /* ------------------------------ المنتجات ------------------------------ */

    const [query, setQuery] = useState('');

    const products = useMemo(() => {
        const q = query.trim().toLowerCase();

        return q === ''
            ? website.storeProducts
            : website.storeProducts.filter((p) => p.name.toLowerCase().includes(q));
    }, [website.storeProducts, query]);

    const shownCount = website.storeProducts.filter((p) => p.published && p.active).length;

    const setPublished = (ids: number[], published: boolean) => {
        router.post(
            route('admin.marketing.website.products'),
            { ids, published },
            { preserveScroll: true, onSuccess: reloadPreview },
        );
    };

    const setAll = (published: boolean) => {
        router.post(
            route('admin.marketing.website.products'),
            { all: true, published },
            { preserveScroll: true, onSuccess: reloadPreview },
        );
    };

    const copyLink = () => navigator.clipboard?.writeText(website.storeUrl);

    return (
        <div className="space-y-6">
            {/* ------------------------- حال المتجر ------------------------- */}
            <Card className="overflow-hidden">
                <div className="flex flex-wrap items-center gap-4 p-5">
                    <span
                        className={cn(
                            'flex size-11 shrink-0 items-center justify-center rounded-2xl',
                            form.data.site_published ? 'bg-[#ecfdf5] text-[#059669]' : 'bg-[#f3f4f6] text-[#9ca3af]',
                        )}
                    >
                        {form.data.site_published ? <Check className="size-5" /> : <Eye className="size-5" />}
                    </span>

                    <div className="min-w-0 flex-1">
                        <h3 className="font-bold text-[#111]">
                            {form.data.site_published ? t('متجرك منشور') : t('متجرك غير منشور')}
                        </h3>
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">
                            {form.data.site_published
                                ? t(':n صنفًا معروضًا على زوّارك', { n: shownCount })
                                : t('أنت وحدك من يراه الآن — انشره ليفتحه زبائنك.')}
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" onClick={copyLink} type="button">
                            <Link2 />
                            {t('نسخ الرابط')}
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={website.storeUrl} target="_blank" rel="noopener noreferrer">
                                <ExternalLink />
                                {t('فتح المتجر')}
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-5 py-1">
                    <Toggle
                        label="نشر المتجر"
                        hint="مطفأً لا يفتح الصفحة أحد سواك — وهذا يشمل محرّكات البحث."
                        on={form.data.site_published}
                        onChange={togglePublished}
                    />
                </div>
            </Card>

            <div className="grid gap-6 xl:grid-cols-5">
                {/* --------------------------- النموذج --------------------------- */}
                <div className="space-y-6 xl:col-span-3">
                    <Tabs
                        current={tab}
                        onChange={(k) => setTab(k as SiteTab)}
                        tabs={[
                            { key: 'design', label: 'التصميم', icon: Palette },
                            { key: 'identity', label: 'التعريف', icon: ImageIcon },
                            { key: 'contact', label: 'التواصل', icon: Share2 },
                            { key: 'products', label: 'المنتجات', icon: Package, count: shownCount },
                        ]}
                    />

                    {tab === 'products' ? (
                        <Card className="overflow-hidden">
                            <div className="flex flex-wrap items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                <h3 className="font-bold text-[#111]">{t('ما يظهر في متجرك')}</h3>
                                <div className="ms-auto flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" size="sm" onClick={() => setAll(true)}>
                                        {t('اعرض كل الأصناف النشطة')}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setAll(false)}>
                                        {t('أخفِ الكل')}
                                    </Button>
                                </div>
                            </div>

                            <div className="border-b border-[var(--ui-border,#e8e8e8)] p-4">
                                <Input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder={t('ابحث في الأصناف')}
                                />
                            </div>

                            {products.length === 0 ? (
                                <p className="px-5 py-12 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا أصناف مطابقة')}
                                </p>
                            ) : (
                                <ul className="max-h-[520px] divide-y divide-[var(--ui-border,#e8e8e8)] overflow-y-auto">
                                    {products.map((p) => (
                                        <li key={p.id} className="flex items-center gap-3 px-5 py-3">
                                            {/* اللوح نفسه الذي يراه الزبون — لا صورة هنا وأخرى هناك */}
                                            {p.image ? (
                                                <img
                                                    src={p.image}
                                                    alt=""
                                                    className="size-10 shrink-0 rounded-lg object-cover"
                                                />
                                            ) : (
                                                <span
                                                    className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#f3f4f6] text-[13px] font-bold text-[#9ca3af]"
                                                >
                                                    {p.name.slice(0, 1)}
                                                </span>
                                            )}
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-[#111]">{p.name}</span>
                                                {!p.active && (
                                                    /* غير النشِط لا يُعرض ولو رُفع مفتاحه — يُقال هنا لا في المتجر */
                                                    <span className="text-[12px] text-[#b45309]">
                                                        {t('غير نشِط — لا يظهر في المتجر')}
                                                    </span>
                                                )}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => setPublished([p.id], !p.published)}
                                                className={cn(
                                                    'shrink-0 rounded-full px-3 py-1.5 text-[12px] font-medium transition',
                                                    p.published
                                                        ? 'bg-[#ecfdf5] text-[#059669] hover:bg-[#d1fae5]'
                                                        : 'bg-[#f3f4f6] text-[#6b7280] hover:bg-[#e5e7eb]',
                                                )}
                                            >
                                                {p.published ? t('معروض') : t('مخفيّ')}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    ) : (
                        <form onSubmit={save} className="space-y-6">
                            {tab === 'design' && (
                                <>
                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <Palette className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('لون المتجر')}</h3>
                                        </div>
                                        <div className="p-5">
                                            <p className="mb-4 text-[13px] text-[#6b7280]">
                                                {t('منه تُشتقّ الأزرار والأسعار والعناوين في صفحتك.')}
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {PALETTE.map((color) => (
                                                    <button
                                                        key={color}
                                                        type="button"
                                                        onClick={() => form.setData('site_theme', color)}
                                                        style={{ background: color }}
                                                        aria-label={color}
                                                        className={cn(
                                                            'size-9 rounded-full ring-offset-2 transition',
                                                            form.data.site_theme === color
                                                                ? 'ring-2 ring-[#111]'
                                                                : 'hover:scale-110',
                                                        )}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    </Card>

                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <ImageIcon className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('الغلاف')}</h3>
                                        </div>
                                        <div className="p-5">
                                            <p className="mb-4 text-[13px] text-[#6b7280]">
                                                {t('صورةٌ عريضة أعلى صفحتك — وبدونها يُملأ المكان بلون متجرك.')}
                                            </p>
                                            <Input
                                                type="file"
                                                accept="image/png,image/jpeg,image/webp"
                                                onChange={(e) => setCoverFile(e.target.files?.[0] ?? null)}
                                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                            />
                                            <p className="mt-2 text-[12px] text-[#9ca3af]">
                                                {t('أفضل مقاس: 1600×600 بكسل · حتّى ٤ ميغابايت')}
                                            </p>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={!coverFile}
                                                    loading={coverBusy && !!coverFile}
                                                    onClick={() => coverFile && sendCover({ cover: coverFile })}
                                                >
                                                    <Upload />
                                                    {t('رفع الغلاف')}
                                                </Button>
                                                {site.site_cover && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        loading={coverBusy && !coverFile}
                                                        onClick={() => sendCover({ remove: true })}
                                                    >
                                                        <Trash2 />
                                                        {t('حذف الغلاف')}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    </Card>

                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <LayoutGrid className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('الصفحة الأولى')}</h3>
                                        </div>
                                        <div className="space-y-4 p-5">
                                            <Field
                                                label="العنوان الكبير"
                                                hint="أوّل سطرٍ يقرؤه الزائر — وبلا كتابته يُعرض اسم متجرك"
                                                error={form.errors.site_hero_title}
                                            >
                                                <Input
                                                    value={form.data.site_hero_title}
                                                    onChange={(e) => form.setData('site_hero_title', e.target.value)}
                                                    placeholder={business.name}
                                                />
                                            </Field>

                                            <Field
                                                label="السطر تحته"
                                                hint="جملةٌ قصيرة — وبلا كتابتها تُعرض الجملة التعريفية"
                                                error={form.errors.site_hero_note}
                                            >
                                                <Input
                                                    value={form.data.site_hero_note}
                                                    onChange={(e) => form.setData('site_hero_note', e.target.value)}
                                                />
                                            </Field>

                                            <div>
                                                <p className="mb-2 text-sm font-medium text-[#111]">{t('شكل عرض الأصناف')}</p>
                                                <div className="grid grid-cols-2 gap-3">
                                                    {([
                                                        { key: 'grid', label: 'شبكة صور', icon: LayoutGrid },
                                                        { key: 'list', label: 'قائمة', icon: Rows3 },
                                                    ] as const).map((option) => (
                                                        <button
                                                            key={option.key}
                                                            type="button"
                                                            onClick={() => form.setData('site_layout', option.key)}
                                                            className={cn(
                                                                'flex items-center gap-2 rounded-[12px] border px-4 py-3 text-sm transition',
                                                                form.data.site_layout === option.key
                                                                    ? 'border-[#111] bg-[#fafafa] font-medium text-[#111]'
                                                                    : 'border-[var(--ui-border,#e8e8e8)] text-[#6b7280] hover:border-[#d1d5db]',
                                                            )}
                                                        >
                                                            <option.icon className="size-4" />
                                                            {t(option.label)}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="divide-y divide-[var(--ui-border,#e8e8e8)] pt-1">
                                                <Toggle
                                                    label="شريط الأقسام"
                                                    hint="يظهر حين يكون في متجرك أكثر من قسمٍ معروض."
                                                    on={form.data.site_show_categories}
                                                    onChange={(v) => form.setData('site_show_categories', v)}
                                                />
                                                <Toggle
                                                    label="قسم «من نحن»"
                                                    hint="يعرض نبذتك أسفل الصفحة."
                                                    on={form.data.site_show_about}
                                                    onChange={(v) => form.setData('site_show_about', v)}
                                                />
                                            </div>
                                        </div>
                                        {saveBar(form.processing, t)}
                                    </Card>
                                </>
                            )}

                            {tab === 'identity' && (
                                <>
                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <ImageIcon className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('الشعار')}</h3>
                                        </div>
                                        <div className="p-5">
                                            <p className="mb-4 text-[13px] text-[#6b7280]">
                                                {t('يظهر في رأس متجرك وفي الفواتير والإيصالات.')}
                                            </p>
                                            <div className="flex flex-wrap items-center gap-5">
                                                <span className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                                                    {logo.preview || business.logo ? (
                                                        <img
                                                            src={logo.preview ?? business.logo ?? ''}
                                                            alt=""
                                                            className="size-full object-contain"
                                                        />
                                                    ) : (
                                                        <ImageIcon className="size-8 text-[#d1d5db]" />
                                                    )}
                                                </span>

                                                <div className="min-w-0 flex-1">
                                                    <Input
                                                        type="file"
                                                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                                        onChange={(e) => logo.pick(e.target.files?.[0] ?? null)}
                                                        className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                                    />
                                                    <p className="mt-2 text-[12px] text-[#9ca3af]">
                                                        {t('أفضل مقاس: 400×100 بكسل · PNG بخلفيّة شفّافة · حتّى ٢ ميغابايت')}
                                                    </p>
                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            disabled={!logo.file}
                                                            loading={logo.busy && !!logo.file}
                                                            onClick={() => logo.file && logo.send({ logo: logo.file })}
                                                        >
                                                            <Upload />
                                                            {t('رفع الشعار')}
                                                        </Button>
                                                        {business.logo && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                loading={logo.busy && !logo.file}
                                                                onClick={() => logo.send({ remove: true })}
                                                            >
                                                                <Trash2 />
                                                                {t('حذف الشعار')}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </Card>

                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <Eye className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('تعريف المتجر')}</h3>
                                        </div>
                                        <div className="space-y-4 p-5">
                                            <Field
                                                label="الجملة التعريفية"
                                                hint="سطرٌ واحد تحت اسم متجرك — أوّل ما يُقرأ"
                                                error={form.errors.site_tagline}
                                            >
                                                <Input
                                                    value={form.data.site_tagline}
                                                    onChange={(e) => form.setData('site_tagline', e.target.value)}
                                                    placeholder={t('أجود المنتجات بأفضل الأسعار')}
                                                />
                                            </Field>

                                            <Field
                                                label="نبذة عن المتجر"
                                                hint="تظهر في قسم «من نحن» وتقرؤها محرّكات البحث"
                                                error={form.errors.site_about}
                                            >
                                                <Textarea
                                                    rows={5}
                                                    value={form.data.site_about}
                                                    onChange={(e) => form.setData('site_about', e.target.value)}
                                                />
                                            </Field>

                                            {/* الاسم مصدرُه «بيانات النشاط» — يُعرض ولا يُكرَّر إدخالًا */}
                                            <p className="text-[12px] text-[#9ca3af]">
                                                {t('اسم المتجر يُقرأ من «بيانات النشاط»')}: {business.name || '—'}
                                            </p>
                                        </div>
                                        {saveBar(form.processing, t)}
                                    </Card>
                                </>
                            )}

                            {tab === 'contact' && (
                                <>
                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <Share2 className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('التواصل')}</h3>
                                        </div>
                                        <div className="p-5">
                                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                <Field
                                                    label="واتساب"
                                                    hint="بصيغة دولية بلا + — مثل: 96890000000"
                                                    error={form.errors.site_whatsapp}
                                                >
                                                    <Input
                                                        dir="ltr"
                                                        value={form.data.site_whatsapp}
                                                        onChange={(e) => form.setData('site_whatsapp', e.target.value)}
                                                        placeholder="96890000000"
                                                    />
                                                </Field>
                                                <Field
                                                    label="إنستغرام"
                                                    hint="اسم الحساب وحده بلا @"
                                                    error={form.errors.site_instagram}
                                                >
                                                    <Input
                                                        dir="ltr"
                                                        value={form.data.site_instagram}
                                                        onChange={(e) => form.setData('site_instagram', e.target.value)}
                                                        placeholder="mystore"
                                                    />
                                                </Field>
                                            </div>

                                            {/* بيانات المتجر مصدرها «بيانات النشاط» — تُعرض ولا تُكرَّر إدخالًا */}
                                            <div className="mt-5 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[12px] leading-relaxed text-[#6b7280]">
                                                <p className="font-medium text-[#374151]">
                                                    {t('يظهر في تذييل متجرك، ومصدره «بيانات النشاط»')}
                                                </p>
                                                <p className="mt-1">
                                                    {business.name || '—'}
                                                    {business.phone ? ` · ${business.phone}` : ''}
                                                    {business.address ? ` · ${business.address}` : ''}
                                                </p>
                                            </div>
                                        </div>
                                        {saveBar(form.processing, t)}
                                    </Card>

                                    <Card className="overflow-hidden">
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                            <Eye className="size-4 shrink-0 text-[#6b7280]" />
                                            <h3 className="font-bold text-[#111]">{t('ما يراه الزائر')}</h3>
                                        </div>
                                        <div className="p-5">
                                            <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                                                <Toggle
                                                    label="عرض الأسعار"
                                                    hint="بإطفائه يُعرض الصنف بلا سعر ويُطلب السعر بالتواصل."
                                                    on={form.data.site_show_prices}
                                                    onChange={(v) => form.setData('site_show_prices', v)}
                                                />
                                                <Toggle
                                                    label="زرّ الطلب عبر واتساب"
                                                    hint="يفتح محادثةً باسم الصنف ورابطه — ويحتاج رقم واتساب."
                                                    on={form.data.site_allow_orders}
                                                    onChange={(v) => form.setData('site_allow_orders', v)}
                                                />
                                            </div>

                                            {/* زرٌّ بلا رقمٍ يفتح صفحة خطأ من واتساب — يُقال قبل الحفظ */}
                                            {form.data.site_allow_orders && form.data.site_whatsapp.trim() === '' && (
                                                <p className="mt-4 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                                                    {t('زرّ الطلب لا يظهر حتى تكتب رقم واتساب.')}
                                                </p>
                                            )}
                                        </div>
                                        {saveBar(form.processing, t)}
                                    </Card>
                                </>
                            )}
                        </form>
                    )}
                </div>

                {/* -------------------------- المعاينة -------------------------- */}
                <div className="xl:col-span-2">
                    <div className="xl:sticky xl:top-4">
                        <div className="mb-3 flex items-center gap-2">
                            <h3 className="font-bold text-[#111]">{t('المعاينة')}</h3>
                            <div className="ms-auto flex items-center gap-1">
                                <button
                                    type="button"
                                    onClick={() => setWide(true)}
                                    aria-label={t('عرض على الحاسوب')}
                                    className={cn('rounded-lg p-2', wide ? 'bg-[#111] text-white' : 'text-[#9ca3af]')}
                                >
                                    <Monitor className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setWide(false)}
                                    aria-label={t('عرض على الجوال')}
                                    className={cn('rounded-lg p-2', wide ? 'text-[#9ca3af]' : 'bg-[#111] text-white')}
                                >
                                    <Smartphone className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={reloadPreview}
                                    aria-label={t('تحديث المعاينة')}
                                    className="rounded-lg p-2 text-[#9ca3af] hover:text-[#111]"
                                >
                                    <RefreshCw className="size-4" />
                                </button>
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3">
                            <iframe
                                key={previewKey}
                                src={website.storeUrl}
                                title={t('معاينة المتجر')}
                                className={cn(
                                    'h-[640px] rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white transition-all',
                                    wide ? 'w-full' : 'mx-auto w-[390px] max-w-full',
                                )}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

/** شريط الحفظ — واحدٌ لكلّ بطاقة، فلا يبحث التاجر عن زرٍّ في آخر الصفحة */
function saveBar(processing: boolean, t: (key: string) => string) {
    return (
        <div className="flex justify-end border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-5 py-3">
            <Button type="submit" loading={processing}>
                <Save />
                {t('حفظ التغييرات')}
            </Button>
        </div>
    );
}
