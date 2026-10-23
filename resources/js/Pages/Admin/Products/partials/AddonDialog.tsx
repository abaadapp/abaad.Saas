import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { useAsciiDigits } from '@/lib/numerals';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { CHANNEL_LABEL, addonChannels, type AddonOption, type BoutiqueLinks, type StockItem } from './addons';

/** صنفٌ يُختار في النافذة — انظر `StockItem` */
export type PickerOption = StockItem;

interface Props {
    /** إضافةٌ تُعدَّل، أو غيابُها إنشاءٌ جديد */
    addon: AddonOption | null;
    stockItems: PickerOption[];
    products: { value: number; label: string }[];
    onClose: () => void;
    onSaved: (addon: AddonOption) => void;
    /** بوتيكاتُ المتجر — لخيار «منتج من بوتيك» */
    boutiques?: { value: number; label: string }[];
    /** أيُعرض خيارُ البوتيك، وأيُقبل — `AddonController::boutiqueLinks` */
    boutiqueLinks?: BoutiqueLinks;
    /** إنشاءٌ من شاشة منتج: مخصّصةٌ له مسبقًا — ويبقى تغييرُ مداها بيد التاجر */
    forProduct?: number | null;
    currencyLabel?: string;
}

/**
 * مدى الإضافة.
 *
 * وكان ثالثًا: «هذا المنتج فقط» — إضافةٌ تخصّ منتجًا بعينه لا المتجر. وزال
 * بزوال قسم التركيب: لا تُنشأ واحدةٌ بعد اليوم، لا من هنا ولا من الخادم
 * (انظر `noOwnership` في `CatalogQuickAddController`).
 *
 * أمّا المكتوبةُ قبل ذلك فتُعدَّل وتُعطَّل من هنا: هي في شاشة مالكها وحدَه،
 * ولا يُعرض لها مدًى لأنّ مداها ملكيّتُها. ومن لم يجد لها بابًا بقي يراها
 * في الكاشير ولا يملك تغيير سعرها.
 */
type Scope = 'all' | 'selected';

/** مصدرُ الإضافة — لمن يُؤوي بوتيكات: من المتجر، أو قطعةٌ من رفّ بوتيك */
type Source = 'shop' | 'boutique';

/**
 * إضافةٌ تُنشأ أو تُعدَّل — بمصدرها ومداها وقناتيها وحالتها.
 *
 * والأسئلة بالعربية لا بأسماء الأعمدة: «هل تخصم من المخزون؟» لا
 * inventory_product_id. ومن أجاب «لا» لا يرى حقول المخزون أصلًا — سؤالٌ
 * ظاهرٌ بلا معنًى يجعل الشاشة تبدو أصعب ممّا هي.
 *
 * ═══ ومنتجٌ من بوتيك ═══
 *
 * لمن يُؤوي بوتيكات وحده: يختار البوتيك ثمّ قطعةً من رفّه وعددها، ويكتب
 * سعرَ الإضافة للزبون. والمستحقُّ يُعرض بجانبه بقاعدة الخادم (ثمنُ القطعة ×
 * عددها، `BoutiqueDues::due`) — منفصلًا عن سعر الزبون، والفرقُ بينهما لا
 * يُسمّى ربحًا. ولا نسخةَ من القطعة: الإضافةُ تشير إلى صنف البوتيك نفسِه.
 * وما لا يقبله الخادم (`Boutiques::refuseNewLink`) يُعرض معطّلًا بسببه.
 *
 * ولا تصميم جديد: نفس النافذة والحقول والأزرار التي في بقيّة الشاشات.
 */
export default function AddonDialog({
    addon, stockItems, products, onClose, onSaved, boutiques = [], boutiqueLinks, forProduct = null, currencyLabel = '',
}: Props) {
    const t = useTranslate();

    const [name, setName] = useState(addon?.label ?? '');
    const [nameEn, setNameEn] = useState(addon?.name_en ?? '');
    const [price, setPrice] = useState(addon ? String(addon.price) : '');
    const [stock, setStock] = useState<boolean>(!!addon?.inventory_product_id);
    const [stockId, setStockId] = useState<string>(
        addon?.inventory_product_id ? String(addon.inventory_product_id) : '',
    );
    const [each, setEach] = useState<string>(
        addon?.inventory_quantity != null ? String(addon.inventory_quantity) : '1',
    );
    /*
     * المصدرُ يُقرأ من الصنف المربوط: قطعةُ بوتيكٍ = «منتج من بوتيك». ولمن لا
     * يُؤوي بوتيكات لا سؤالَ عن المصدر أصلًا — سؤالُ المخزون وحده كما كان.
     */
    const hosts = boutiqueLinks?.hosts === true;
    const linked = stockItems.find((s) => s.value === addon?.inventory_product_id);
    const [source, setSource] = useState<Source>(hosts && linked?.boutique_id ? 'boutique' : 'shop');
    const [boutiqueId, setBoutiqueId] = useState<string>(linked?.boutique_id ? String(linked.boutique_id) : '');
    /*
     * والربطُ القائمُ بقطعة بوتيكٍ يبقى قابلًا للحفظ ولو أُغلق الباب بعده
     * (`Boutiques::refuseNewLink` يقبل الربطَ المحفوظ) — فلا يُعطَّل خيارُه عليها.
     */
    const boutiqueOpen = boutiqueLinks?.allowed === true || !!linked?.boutique_id;

    /**
     * مملوكةٌ لمنتج — تُعدَّل وتُعطَّل، ولا يُمسّ مداها ولا مالكُها.
     *
     * ولا حالةَ لها تُبدَّل: تُقرأ من الصفّ نفسه، ولو أُتيح تبديلُها لصار
     * في الشاشة بابٌ يحوّل الخاصّة إلى عامّة — وهو ما يردّه الخادم.
     */
    const owned = addon?.private === true;
    const [scope, setScope] = useState<Scope>(
        addon ? (addon.scope === 'selected' ? 'selected' : 'all') : forProduct ? 'selected' : 'all',
    );
    /* الحالة: نشطةٌ أو موقوفة — لكلّ إضافة. الموقوفةُ تبقى محفوظةً ولا تُحذف */
    const [active, setActive] = useState<boolean>(addon?.active !== false);
    /*
     * وأين تُباع — قناتان مستقلّتان. والجديدةُ في الصندوق وحده كما كانت كلُّ
     * إضافة، والموقعُ يُفتح لها بقرار. والقناتان مطفأتان معًا: «غير متاحة
     * للبيع حاليًا» — تبقى في القائمة ولا تُحذف.
     */
    const [atPos, setAtPos] = useState<boolean>(addon?.pos_enabled !== false);
    const [online, setOnline] = useState<boolean>(addon?.web_enabled === true);
    const [picked, setPicked] = useState<number[]>(addon?.product_ids ?? (forProduct ? [forProduct] : []));
    const searchRef = useAsciiDigits<HTMLInputElement>();
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();

        return q ? products.filter((p) => p.label.toLowerCase().includes(q)) : products;
    }, [products, search]);

    /* أصنافُ المتجر لسؤال المخزون، وقطعُ البوتيك المختار لخيار البوتيك */
    const shopItems = hosts ? stockItems.filter((s) => s.boutique_id == null) : stockItems;
    const boutiqueItems = stockItems.filter((s) => s.boutique_id != null && String(s.boutique_id) === boutiqueId);
    const piece = source === 'boutique'
        ? stockItems.find((s) => String(s.value) === stockId && s.boutique_id != null)
        : undefined;
    const pieces = Number(each) || 0;
    const due = piece ? Math.round((piece.price ?? 0) * pieces * 1000) / 1000 : null;

    const toggle = (id: number) =>
        setPicked((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const money = (v: number) => `${v.toFixed(3)}${currencyLabel ? ` ${currencyLabel}` : ''}`;

    const save = async () => {
        const clean = name.trim();
        if (!clean) {
            setError(t('اكتب اسم الإضافة'));

            return;
        }
        if (source === 'boutique') {
            if (!piece) {
                setError(t('اختر البوتيك وقطعةً من رفّه'));

                return;
            }
            if (!Number.isInteger(pieces) || pieces <= 0) {
                setError(t('قطعةُ البوتيك تُؤخذ قطعًا كاملة — اكتب عددًا صحيحًا.'));

                return;
            }
        } else if (stock && !stockId) {
            setError(t('اختر الصنف المخزني'));

            return;
        }
        if (! owned && scope === 'selected' && picked.length === 0) {
            setError(t('اختر منتجًا واحدًا على الأقل'));

            return;
        }

        const linksStock = source === 'boutique' || stock;
        const body = {
            name: clean,
            // ما كتبه التاجر أو لا شيء: الخادمُ لا يترجم اسمَ الإضافة من قاموس،
            // وما فرغ يُعرض بالعربيّة كما كُتب (`ProductName::display`)
            name_en: nameEn.trim() || null,
            price: price || 0,
            inventory_product_id: linksStock ? Number(stockId) : null,
            inventory_quantity: linksStock ? (each || 1) : null,
            pos_enabled: atPos,
            web_enabled: online,
            active,
            /*
             * المملوكةُ لا يُرسل لها مدًى ولا مالك: الخادم يردّ ذلك ردًّا
             * صريحًا (`ownershipStays`)، والشاشةُ لا تطلب ما يُردّ.
             */
            ...(owned
                ? {}
                : { scope, product_id: null, product_ids: scope === 'selected' ? picked : [] }),
        };

        setSaving(true);
        setError(null);
        try {
            const res = await fetch(
                addon && addon.value > 0
                    ? route('admin.products.addons.update', addon.value)
                    : route('admin.products.addons.store'),
                {
                    method: addon && addon.value > 0 ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                    body: JSON.stringify(body),
                },
            );
            const payload = await res.json();

            if (!res.ok) {
                const errors = payload?.errors ?? {};
                setError(
                    errors.name?.[0] ?? errors.price?.[0] ?? errors.inventory_quantity?.[0]
                    ?? errors.inventory_product_id?.[0] ?? errors.product_id?.[0]
                    ?? errors.scope?.[0] ?? errors.product_ids?.[0]
                    ?? errors.pos_enabled?.[0] ?? errors.web_enabled?.[0] ?? t('تعذّر حفظ الإضافة'),
                );

                return;
            }

            onSaved(payload.addon);
            onClose();
        } catch {
            setError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setSaving(false);
        }
    };

    /**
     * بطاقةُ خيارٍ في سطرٍ واحد — نفس شكل مبدّل الجرد بلا سطره الثاني.
     *
     * كانت كلّ بطاقةٍ سطرين، فصارت النافذة أطول من الشاشة وتُمرَّر لتُقرأ.
     * والشرح انتقل إلى تلميح الحقل الذي يخصّه.
     */
    const choice = (on: boolean, title: string, onClick: () => void, disabled = false, testId?: string) => (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            data-testid={testId}
            aria-pressed={on}
            className={cn(
                'flex-1 rounded-[10px] border px-3 py-2 text-[13px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                on
                    ? 'border-[#111] bg-[#111] text-white'
                    : 'border-[#e8e8e8] bg-white text-[#4b4b4b] enabled:hover:bg-[#f7f7f5]',
            )}
        >
            {t(title)}
        </button>
    );

    /* الخلاصةُ قبل الحفظ — ما ستفعله الإضافة بكلماتٍ لا بمفاتيح */
    const stockName = stockItems.find((s) => String(s.value) === stockId)?.label ?? '';
    const sourceLine = source === 'boutique'
        ? piece
            ? t('من بوتيك :boutique: :product × :n', { boutique: piece.boutique_name ?? '', product: piece.label, n: String(pieces || 1) })
            : t('منتج من بوتيك')
        : stock && stockId
            ? t('تخصم من المخزون: :product × :n', { product: stockName, n: each || '1' })
            : t('من المتجر — خدمة أو رسوم');
    const scopeLine = owned
        ? t('خاصة بمنتجها')
        : scope === 'all'
            ? t('عامة — مع كل المنتجات')
            : t('مخصصة — :n منتج', { n: String(picked.length) });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(addon ? 'تعديل إضافة' : 'إضافة جديدة')}</DialogTitle>
                </DialogHeader>

                {/* نفس بنية بقيّة النوافذ: رأسٌ ثمّ جسمٌ بحشوةٍ واحدة وصفُّ
                    أزرارٍ في آخره — max-w-lg وspace-y-4 وpx-5 pb-5. ويُمرَّر
                    الجسمُ وحده على الجوال: الأسئلةُ صارت أطول من الشاشة */}
                <div className="max-h-[75vh] space-y-4 overflow-y-auto px-5 pb-5">
                    {/* ------------------------- المصدر ------------------------- */}
                    {hosts && (
                        <div data-testid="addon-source">
                            <span className="mb-1.5 block text-[13px] font-medium text-[#111]">{t('مصدر الإضافة')}</span>
                            <div className="flex gap-2">
                                {choice(source === 'shop', 'إضافة خاصة بالمتجر', () => setSource('shop'), false, 'addon-source-shop')}
                                {choice(source === 'boutique', 'منتج من بوتيك', () => {
                                    setSource('boutique');
                                    setStockId('');
                                }, !boutiqueOpen, 'addon-source-boutique')}
                            </div>
                            {!boutiqueOpen && boutiqueLinks?.reason && (
                                <p className="mt-1 text-[12px] text-[#9ca3af]" data-testid="addon-source-reason">{boutiqueLinks.reason}</p>
                            )}
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="الاسم" required error={error ?? undefined}>
                            <Input
                                autoFocus
                                value={name}
                                placeholder={t('شوكولاتة')}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </Field>
                        {/* مكتوبًا بيده لا مترجَمًا: ما فرغ يبقى فارغًا ويُعرض بالعربيّة */}
                        <Field label="الاسم بالإنجليزية">
                            <Input
                                dir="ltr"
                                value={nameEn}
                                placeholder="Chocolate"
                                onChange={(e) => setNameEn(e.target.value)}
                            />
                        </Field>
                    </div>

                    {source === 'boutique' && (
                        <div className="grid grid-cols-2 gap-3" data-testid="addon-boutique-piece">
                            <Field label="البوتيك" required>
                                <Select
                                    aria-label={t('البوتيك')}
                                    value={boutiqueId}
                                    onChange={(e) => {
                                        setBoutiqueId(e.target.value);
                                        setStockId('');
                                    }}
                                    options={boutiques.map((b) => ({ label: b.label, value: b.value }))}
                                    placeholder={t('اختر البوتيك…')}
                                />
                            </Field>
                            <Field label="القطعة" required>
                                <Select
                                    aria-label={t('القطعة')}
                                    value={stockId}
                                    onChange={(e) => setStockId(e.target.value)}
                                    options={boutiqueItems.map((s) => ({
                                        label: s.quantity == null ? s.label : `${s.label} (${s.quantity})`,
                                        value: s.value,
                                    }))}
                                    placeholder={boutiqueId ? t('اختر القطعة…') : t('اختر البوتيك أولًا')}
                                />
                            </Field>
                            <Field label="عدد القطع في كل إضافة">
                                <Input inputMode="numeric" dir="ltr" value={each} onChange={(e) => setEach(e.target.value)} />
                            </Field>
                            {piece && due !== null && (
                                <p
                                    className="col-span-2 rounded-[10px] bg-[#f8fafc] px-3 py-2 text-[12px] leading-relaxed text-[#475569]"
                                    data-testid="addon-boutique-due"
                                >
                                    {t('مستحق البوتيك عن كل إضافة: :due (ثمن القطعة :price × :n). يُحسب عند البيع ويُثبَّت في الطلب.', {
                                        due: money(due), price: money(piece.price ?? 0), n: String(pieces),
                                    })}
                                    <br />
                                    {t('وسعر الإضافة للعميل تحدده أنت أدناه — والفرق بينهما ليس ربحًا صافيًا.')}
                                </p>
                            )}
                        </div>
                    )}

                    <Field label="سعر الإضافة للعميل">
                        <Input
                            inputMode="decimal"
                            dir="ltr"
                            value={price}
                            placeholder="0.000"
                            onChange={(e) => setPrice(e.target.value)}
                        />
                    </Field>

                    {/* ------------------------- القنوات ------------------------- */}
                    <div data-testid="addon-channels">
                        <span className="mb-1.5 block text-[13px] font-medium text-[#111]">
                            {t('أين تُباع الإضافة؟')}
                        </span>
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-[#111]">
                                <input
                                    type="checkbox"
                                    checked={atPos}
                                    onChange={() => setAtPos((v) => !v)}
                                    data-testid="addon-channel-pos"
                                />
                                {t('نقطة البيع (POS)')}
                            </label>
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-[#111]">
                                <input
                                    type="checkbox"
                                    checked={online}
                                    onChange={() => setOnline((v) => !v)}
                                    data-testid="addon-channel-web"
                                />
                                {t('الموقع الإلكتروني')}
                            </label>
                        </div>
                        <p className="mt-1 text-[12px] text-[#9ca3af]" data-testid="addon-channels-hint">
                            {atPos || online
                                ? t('تظهر للعميل في القناة المختارة فقط، ويرفض الخادم غيرها.')
                                : t('غير متاحة للبيع حاليًا — تبقى محفوظة ولا تُحذف.')}
                        </p>
                    </div>

                    {/* ------------------------- المخزون ------------------------- */}
                    {source === 'shop' && (
                        <div>
                            <span className="mb-1.5 block text-[13px] font-medium text-[#111]">
                                {t('هل تخصم هذه الإضافة من المخزون؟')}
                            </span>
                            <div className="flex gap-2">
                                {choice(!stock, 'لا، خدمة أو رسوم فقط', () => setStock(false))}
                                {choice(stock, 'نعم، مرتبطة بصنف', () => setStock(true))}
                            </div>
                        </div>
                    )}

                    {source === 'shop' && stock && (
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="الصنف المخزني" required>
                                {/*
                                    وصنفُ البوتيك يُعرض معطّلًا: ربطُه لا يُنشئ للبوتيك مستحقًّا
                                    بعد، والخادمُ يردّه (`Boutiques::refuseNewLink`). والربطُ
                                    القائمُ يبقى قابلًا للحفظ كما هو. ولمن يُؤوي بوتيكات
                                    قطعُها في خيار «منتج من بوتيك» لا هنا.
                                */}
                                <Select
                                    value={stockId}
                                    onChange={(e) => setStockId(e.target.value)}
                                    options={shopItems.map((s) => ({
                                        label: (s.quantity == null ? s.label : `${s.label} (${s.quantity})`)
                                            + (s.boutique ? ` — ${t('بوتيك: لا يُربط بعد')}` : ''),
                                        value: s.value,
                                        disabled: s.boutique === true && s.value !== addon?.inventory_product_id,
                                    }))}
                                    placeholder={t('اختر الصنف…')}
                                />
                            </Field>
                            <Field label="الكمية المستهلكة">
                                <Input
                                    inputMode="decimal"
                                    dir="ltr"
                                    value={each}
                                    onChange={(e) => setEach(e.target.value)}
                                />
                            </Field>
                            <p className="col-span-2 text-[12px] text-[#9ca3af]">
                                {t('مثال: إذا كانت الإضافة «زيادة 3 وردات» اختر صنف الورد واكتب الكمية 3.')}
                            </p>
                            {stockItems.some((s) => s.boutique && String(s.value) === stockId) && (
                                <p className="col-span-2 text-[12px] text-[#b45309]" data-testid="addon-boutique-warning">
                                    {t('هذه الإضافة مرتبطة بصنف بوتيك — لا يُنشأ للبوتيك مستحقٌّ عنها بعد.')}
                                </p>
                            )}
                        </div>
                    )}

                    {/* -------------------------- المدى -------------------------- */}
                    {owned ? (
                        /* مداها ملكيّتُها فلا يُعرض خيارًا — ويُقال نصًّا لئلّا
                           يُقاس غيابُ الخيار على أنّها تظهر مع الكلّ */
                        <div>
                            <span className="mb-1.5 block text-[13px] font-medium text-[#111]">{t('تظهر مع')}</span>
                            <p className="rounded-[10px] border border-[#e8e8e8] bg-[#f7f7f5] px-3 py-2 text-[13px] text-[#4b4b4b]">
                                {t('هذا المنتج وحده — ولا يُغيَّر مداها.')}
                            </p>
                        </div>
                    ) : (
                        <div>
                            <span className="mb-1.5 block text-[13px] font-medium text-[#111]">{t('تظهر مع')}</span>
                            <div className="flex gap-2">
                                {choice(scope === 'all', 'جميع المنتجات', () => setScope('all'))}
                                {choice(scope === 'selected', 'منتجات محددة', () => setScope('selected'))}
                            </div>
                        </div>
                    )}

                    {! owned && scope === 'selected' && (
                        <div className="rounded-[12px] border border-[#e8e8e8]">
                            <div className="flex items-center gap-2 border-b border-[#e8e8e8] px-3 py-2">
                                <Search className="size-4 text-[#9ca3af]" />
                                <input
                                    ref={searchRef}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder={t('ابحث عن منتج…')}
                                    className="w-full bg-transparent text-[13px] outline-none"
                                />
                            </div>
                            <div className="max-h-40 overflow-y-auto p-1">
                                {shown.length === 0 ? (
                                    <p className="p-3 text-[13px] text-[#9ca3af]">{t('لا نتائج')}</p>
                                ) : (
                                    shown.map((p) => (
                                        <label
                                            key={p.value}
                                            className="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-1.5 hover:bg-[#f7f7f5]"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={picked.includes(p.value)}
                                                onChange={() => toggle(p.value)}
                                            />
                                            <span className="text-[13px] text-[#111]">{p.label}</span>
                                        </label>
                                    ))
                                )}
                            </div>
                        </div>
                    )}

                    {/* -------------------------- الحالة -------------------------- */}
                    {/* الموقوفةُ لا تُعرض في الكاشير ولا في الموقع ولا تُباع،
                        ولا يُحذف صفٌّ ولا تُمسّ فاتورةٌ مضت */}
                    <div>
                        <span className="mb-1.5 block text-[13px] font-medium text-[#111]">
                            {t('حالة الإضافة')}
                        </span>
                        <div className="flex gap-2">
                            {choice(active, 'نشطة', () => setActive(true), false, 'addon-active-on')}
                            {choice(! active, 'موقوفة', () => setActive(false), false, 'addon-active-off')}
                        </div>
                    </div>

                    {/* -------------------------- الخلاصة -------------------------- */}
                    <div
                        className="rounded-[10px] border border-[#e8e8e8] bg-[#fafafa] px-3 py-2 text-[12px] leading-relaxed text-[#4b4b4b]"
                        data-testid="addon-summary"
                    >
                        <p className="font-medium text-[#111]">
                            {name.trim() || t('إضافة بلا اسم')} · <span dir="ltr">{money(Number(price) || 0)}</span>
                        </p>
                        <p>{sourceLine}</p>
                        <p>
                            {scopeLine} · {t(CHANNEL_LABEL[addonChannels({ pos_enabled: atPos, web_enabled: online })])} · {t(active ? 'نشطة' : 'موقوفة')}
                        </p>
                    </div>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="button" loading={saving} onClick={() => void save()}>
                            {t('حفظ')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
