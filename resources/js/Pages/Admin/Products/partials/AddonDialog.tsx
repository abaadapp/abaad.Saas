import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
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
    addon, productId, drafting, stockItems, products, onClose, onSaved,
}: Props) {
    const t = useTranslate();

    const [name, setName] = useState(addon?.label ?? '');
    const [price, setPrice] = useState(addon ? String(addon.price) : '');
    const [stock, setStock] = useState<boolean>(!!addon?.inventory_product_id);
    const [stockId, setStockId] = useState<string>(
        addon?.inventory_product_id ? String(addon.inventory_product_id) : '',
    );
    const [each, setEach] = useState<string>(
        addon?.inventory_quantity != null ? String(addon.inventory_quantity) : '1',
    );
    const [scope, setScope] = useState<Scope>(
        (addon?.scope as Scope) ?? (productId ? 'product' : 'all'),
    );
    const [picked, setPicked] = useState<number[]>(addon?.product_ids ?? []);
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

    /** بطاقةُ خيارٍ بجملةٍ تشرحه — نفس شكل مبدّل الجرد */
    const choice = (active: boolean, title: string, hint: string, onClick: () => void) => (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex-1 rounded-[12px] border p-3 text-start transition-colors',
                active
                    ? 'border-[#111] bg-[#111] text-white'
                    : 'border-[#e8e8e8] bg-white text-[#4b4b4b] hover:bg-[#f7f7f5]',
            )}
        >
            <span className="block text-[14px] font-semibold">{t(title)}</span>
            {hint && <span className="mt-0.5 block text-[12px] opacity-80">{t(hint)}</span>}
        </button>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>{t(addon ? 'تعديل إضافة' : 'إضافة جديدة')}</DialogTitle>
                </DialogHeader>

                <div className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="الاسم" required error={error ?? undefined}>
                            <Input
                                autoFocus
                                value={name}
                                placeholder={t('شوكولاتة')}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </Field>
                        <Field label="السعر">
                            <Input
                                inputMode="decimal"
                                dir="ltr"
                                value={price}
                                placeholder="0.000"
                                onChange={(e) => setPrice(e.target.value)}
                            />
                        </Field>
                    </div>

                    {/* ------------------------- المخزون ------------------------- */}
                    <div>
                        <span className="mb-2 block text-[13px] font-medium text-[#111]">
                            {t('هل تخصم هذه الإضافة من المخزون؟')}
                        </span>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            {choice(!stock, 'لا، خدمة أو رسوم فقط', 'مثل كتابة اسم أو تغليف فاخر', () => setStock(false))}
                            {choice(stock, 'نعم، مرتبطة بصنف في المخزون', 'مثل دبّ أو شوكولاتة أو ورد', () => setStock(true))}
                        </div>
                    </div>

                    {stock && (
                        <div className="grid gap-4 sm:grid-cols-2">
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
                            <Field
                                label="الكمية المستهلكة عند كل إضافة"
                                hint="مثال: إذا كانت الإضافة «زيادة 3 وردات» اختر صنف الورد واكتب الكمية 3."
                            >
                                <Input
                                    inputMode="decimal"
                                    dir="ltr"
                                    value={each}
                                    onChange={(e) => setEach(e.target.value)}
                                />
                            </Field>
                        </div>
                    )}

                    {/* -------------------------- المدى -------------------------- */}
                    <div>
                        <span className="mb-2 block text-[13px] font-medium text-[#111]">{t('تظهر مع')}</span>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            {choice(scope === 'all', 'جميع المنتجات', '', () => setScope('all'))}
                            {choice(scope === 'selected', 'منتجات محددة', '', () => setScope('selected'))}
                            {productId != null || drafting
                                ? choice(scope === 'product', 'هذا المنتج فقط', '', () => setScope('product'))
                                : null}
                        </div>
                    </div>

                    {scope === 'selected' && (
                        <div className="rounded-[12px] border border-[#e8e8e8]">
                            <div className="flex items-center gap-2 border-b border-[#e8e8e8] px-3 py-2">
                                <Search className="size-4 text-[#9ca3af]" />
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder={t('ابحث عن منتج…')}
                                    className="w-full bg-transparent text-[13px] outline-none"
                                />
                            </div>
                            <div className="max-h-52 overflow-y-auto p-1">
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

                    <div className="flex gap-2 pt-1">
                        <Button type="button" className="flex-1" loading={saving} onClick={() => void save()}>
                            {t('حفظ')}
                        </Button>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
