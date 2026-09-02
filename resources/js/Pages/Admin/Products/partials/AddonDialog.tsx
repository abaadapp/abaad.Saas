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
import type { AddonOption } from './Composition';

export interface PickerOption {
    value: number;
    label: string;
    quantity?: number;
}

interface Props {
    /** إضافةٌ تُعدَّل، أو غيابُها إنشاءٌ جديد */
    addon: AddonOption | null;
    /** المنتج الذي فُتحت من شاشته — يُتيح «هذا المنتج فقط» */
    productId?: number | null;
    /** غياب المعرّف يعني منتجًا لم يُحفظ بعد: تُكتب الإضافة مسوّدةً معه */
    drafting?: boolean;
    /**
     * مدى الإضافة الجديدة قبل أن يُغيّره المستخدم.
     *
     * قسمُ «إضافات هذا المنتج» يبدأ بـ«هذا المنتج فقط»: من فتحه يريد إضافةً
     * لباقته هو. وكان يبدأ بـ«جميع المنتجات» فتُكتب إضافةُ متجرٍ من قسمٍ
     * عنوانُه غير ذلك — ولا تظهر في قائمته بعد الحفظ، فتبدو كأنها ضاعت.
     */
    defaultScope?: Scope;
    stockItems: PickerOption[];
    products: PickerOption[];
    onClose: () => void;
    onSaved: (addon: AddonOption) => void;
}

type Scope = 'all' | 'selected' | 'product';

/**
 * إضافةٌ تُنشأ أو تُعدَّل — بمداها وبما تأكله من الرفّ.
 *
 * ولم يكن للإضافة بابُ تعديلٍ إطلاقًا قبل هذا: تُكتب باسمٍ وسعرٍ ثم لا
 * تُمسّ. ولا سبيل إلى ربطها بالمخزون من أيّ شاشة — العمود موجودٌ في القاعدة
 * ونقطةُ البيع تخصم به، ولا أحد يستطيع أن يقول «الدبّ ينقص من رفّ الدباب».
 *
 * والأسئلة بالعربية لا بأسماء الأعمدة: «هل تخصم من المخزون؟» لا
 * inventory_product_id. ومن أجاب «لا» لا يرى حقول المخزون أصلًا — سؤالٌ
 * ظاهرٌ بلا معنًى يجعل الشاشة تبدو أصعب ممّا هي.
 *
 * ولا تصميم جديد: نفس النافذة والحقول والأزرار التي في بقيّة الشاشات.
 */
export default function AddonDialog({
    addon, productId, drafting, defaultScope, stockItems, products, onClose, onSaved,
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
    const [scope, setScope] = useState<Scope>(
        (addon?.scope as Scope) ?? defaultScope ?? 'all',
    );
    const [picked, setPicked] = useState<number[]>(addon?.product_ids ?? []);
    const searchRef = useAsciiDigits<HTMLInputElement>();
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();

        return q ? products.filter((p) => p.label.toLowerCase().includes(q)) : products;
    }, [products, search]);

    const toggle = (id: number) =>
        setPicked((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const save = async () => {
        const clean = name.trim();
        if (!clean) {
            setError(t('اكتب اسم الإضافة'));

            return;
        }
        if (stock && !stockId) {
            setError(t('اختر الصنف المخزني'));

            return;
        }
        if (scope === 'selected' && picked.length === 0) {
            setError(t('اختر منتجًا واحدًا على الأقل'));

            return;
        }

        const body = {
            name: clean,
            // مكتوبًا لا يُترجَم تلقائيًّا: من كتب الاسم بيده أعلمُ باسم
            // بضاعته من قاموس. انظر Lexicon::fill
            name_en: nameEn.trim() || null,
            price: price || 0,
            scope,
            product_id: scope === 'product' ? productId : null,
            inventory_product_id: stock ? Number(stockId) : null,
            inventory_quantity: stock ? (each || 1) : null,
            product_ids: scope === 'selected' ? picked : [],
        };

        /*
         * المنتج الذي لم يُحفظ بعد لا معرّف له يُعلَّق به شيء.
         *
         * فالإضافة تبقى مسوّدةً في الشاشة وتُكتب مع المنتج في طلب الحفظ
         * نفسه — وإلّا صار على التاجر أن يحفظ الباقة ثم يعود إليها ليقول
         * ماذا يُضاف معها.
         */
        if (drafting && scope === 'product') {
            onSaved({
                value: 0,
                label: clean,
                name_en: nameEn.trim() || null,
                price: Number(price) || 0,
                active: true,
                private: true,
                product_id: null,
                scope: 'product',
                inventory_product_id: stock ? Number(stockId) : null,
                inventory_quantity: stock ? Number(each) || 1 : null,
                product_ids: [],
            });
            onClose();

            return;
        }

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
                    ?? errors.inventory_product_id?.[0] ?? t('تعذّر حفظ الإضافة'),
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
    const choice = (active: boolean, title: string, onClick: () => void) => (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex-1 rounded-[10px] border px-3 py-2 text-[13px] font-medium transition-colors',
                active
                    ? 'border-[#111] bg-[#111] text-white'
                    : 'border-[#e8e8e8] bg-white text-[#4b4b4b] hover:bg-[#f7f7f5]',
            )}
        >
            {t(title)}
        </button>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(addon ? 'تعديل إضافة' : 'إضافة جديدة')}</DialogTitle>
                </DialogHeader>

                {/* نفس بنية بقيّة النوافذ: رأسٌ ثمّ جسمٌ بحشوةٍ واحدة وصفُّ
                    أزرارٍ في آخره — max-w-lg وspace-y-4 وpx-5 pb-5 */}
                <div className="space-y-4 px-5 pb-5">
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="الاسم" required error={error ?? undefined}>
                            <Input
                                autoFocus
                                value={name}
                                placeholder={t('شوكولاتة')}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </Field>
                        {/* مكتوبًا بيده لا مترجَمًا: القاموس يترجم «باقة ورد
                            أحمر» ولا يترجم «شوكولاتة بلجيكية فاخرة» */}
                        <Field label="الاسم بالإنجليزية">
                            <Input
                                dir="ltr"
                                value={nameEn}
                                placeholder="Chocolate"
                                onChange={(e) => setNameEn(e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="السعر">
                        <Input
                            inputMode="decimal"
                            dir="ltr"
                            value={price}
                            placeholder="0.000"
                            onChange={(e) => setPrice(e.target.value)}
                        />
                    </Field>

                    {/* ------------------------- المخزون ------------------------- */}
                    <div>
                        <span className="mb-1.5 block text-[13px] font-medium text-[#111]">
                            {t('هل تخصم هذه الإضافة من المخزون؟')}
                        </span>
                        <div className="flex gap-2">
                            {choice(!stock, 'لا، خدمة أو رسوم فقط', () => setStock(false))}
                            {choice(stock, 'نعم، مرتبطة بصنف', () => setStock(true))}
                        </div>
                    </div>

                    {stock && (
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="الصنف المخزني" required>
                                <Select
                                    value={stockId}
                                    onChange={(e) => setStockId(e.target.value)}
                                    options={stockItems.map((s) => ({
                                        label: s.quantity == null ? s.label : `${s.label} (${s.quantity})`,
                                        value: s.value,
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
                        </div>
                    )}

                    {/* -------------------------- المدى -------------------------- */}
                    <div>
                        <span className="mb-1.5 block text-[13px] font-medium text-[#111]">{t('تظهر مع')}</span>
                        <div className="flex gap-2">
                            {choice(scope === 'all', 'جميع المنتجات', () => setScope('all'))}
                            {choice(scope === 'selected', 'منتجات محددة', () => setScope('selected'))}
                            {productId != null || drafting
                                ? choice(scope === 'product', 'هذا المنتج فقط', () => setScope('product'))
                                : null}
                        </div>
                    </div>

                    {scope === 'selected' && (
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
