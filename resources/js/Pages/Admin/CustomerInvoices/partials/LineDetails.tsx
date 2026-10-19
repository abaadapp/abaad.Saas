import { Plus, Trash2 } from 'lucide-react';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/**
 * ما يُختار مع بند فاتورة العميل — مقاسُه وإضافاتُه وخياراتُه وملاحظتُه.
 *
 * ═══ والمالُ يُحسب في الخادم ═══
 *
 * الشاشةُ ترسل ما اختير: معرّفَ المقاس، ومعرّفاتِ الإضافات بكميّاتها وأيُّها
 * «ضمن السعر». والخادمُ يقرأ ثمنَ الإضافة واسمَها من الكتالوج، ويردّ ما لا
 * يُباع مع الصنف (`InvoiceLineDetails::expand`). والإضافةُ المدفوعةُ سطرٌ
 * بثمنها تحت صنفها في الورقة؛ والمشمولةُ تُسمّى بلا مال.
 *
 * والبندُ اليدويُّ (بلا صنف) له خياراتٌ وملاحظةٌ فقط: لا مقاسَ ولا إضافةَ
 * بلا صنفٍ تُباع معه — ومن أراد إضافةً وحدَها أضافها بندًا مستقلًّا.
 */

export interface VariantRow {
    id: number;
    name: string;
    name_en: string | null;
    price: number;
}

export interface DetailAddonRow {
    id: number;
    name: string;
    name_en: string | null;
    price: number;
}

export interface LineAddon {
    addon_id: string;
    quantity: string;
    /** داخل ثمن الصنف: تُسمّى ولا تُكتب مالًا */
    included: boolean;
}

export interface LineOption {
    label: string;
    value: string;
}

export interface LineDetailsValue {
    variant_id: string;
    note: string;
    options: LineOption[];
    addons: LineAddon[];
}

/** أقصى ما يُكتب مع البند — قيودُ الخادم نفسُها (`InvoiceLineDetails::MAX_*`) */
export const MAX_OPTIONS = 10;
export const MAX_ADDONS = 20;

/** كم شيئًا اختير مع البند — يُعرض على زرّ التفاصيل */
export function detailsCount(v: LineDetailsValue): number {
    return (v.variant_id ? 1 : 0) + (v.note.trim() ? 1 : 0) + v.options.filter((o) => o.value.trim()).length + v.addons.length;
}

interface Props {
    value: LineDetailsValue;
    onChange: (next: Partial<LineDetailsValue>) => void;
    /** صنفُ الكتالوج — `null` للبند اليدويّ */
    variants: VariantRow[] | null;
    /** الإضافاتُ التي تُباع مع هذا الصنف — `null` للبند اليدويّ */
    addons: DetailAddonRow[] | null;
    /** اختيارُ المقاس يبدّل ثمنَ البند واسمَه — يقرّره من يملك السطر */
    onVariant: (variant: VariantRow | null) => void;
    money: (v: number) => string;
    label: (name: string, nameEn: string | null) => string;
}

export default function LineDetails({ value, onChange, variants, addons, onVariant, money, label }: Props) {
    const t = useTranslate();
    const picked = new Set(value.addons.map((a) => a.addon_id));
    const offer = (addons ?? []).filter((a) => !picked.has(String(a.id)));
    const addonOf = (id: string) => (addons ?? []).find((a) => String(a.id) === id);

    const setAddon = (i: number, next: Partial<LineAddon>) =>
        onChange({ addons: value.addons.map((a, k) => (k === i ? { ...a, ...next } : a)) });
    const setOption = (i: number, next: Partial<LineOption>) =>
        onChange({ options: value.options.map((o, k) => (k === i ? { ...o, ...next } : o)) });

    return (
        <div className="grid gap-4 rounded-lg bg-[#fafafa] p-3 text-[13px] md:grid-cols-2" data-testid="line-details">
            {variants !== null && variants.length > 0 && (
                <label className="block">
                    <span className="mb-1 block text-[12px] text-[#6b7280]">{t('المقاس')}</span>
                    <Select
                        value={value.variant_id}
                        onChange={(e) => {
                            const v = variants.find((x) => String(x.id) === e.target.value) ?? null;
                            onChange({ variant_id: e.target.value });
                            onVariant(v);
                        }}
                        // والفراغُ خيارٌ يُرجَع إليه — `placeholder` لا خيارٌ بقيمةٍ فارغة (Radix يرفضها)
                        placeholder="بلا مقاس"
                        options={variants.map((v) => ({ label: `${label(v.name, v.name_en)} — ${money(v.price)}`, value: String(v.id) }))}
                    />
                </label>
            )}

            <label className="block">
                <span className="mb-1 block text-[12px] text-[#6b7280]">{t('ملاحظة المنتج')}</span>
                <Input
                    value={value.note}
                    maxLength={500}
                    onChange={(e) => onChange({ note: e.target.value })}
                    placeholder={t('مثال: تغليف ذهبي')}
                />
            </label>

            {addons !== null && (
                <div className="md:col-span-2">
                    <span className="mb-1 block text-[12px] text-[#6b7280]">{t('الإضافات')}</span>
                    <ul className="flex flex-col gap-2">
                        {value.addons.map((a, i) => {
                            const row = addonOf(a.addon_id);

                            return (
                                <li key={a.addon_id} className="flex flex-wrap items-center gap-2" data-testid="line-addon">
                                    <span className="min-w-0 flex-1 truncate">
                                        + <bdi>{row ? label(row.name, row.name_en) : a.addon_id}</bdi>
                                    </span>
                                    <Input
                                        className="w-20"
                                        value={a.quantity}
                                        onChange={(e) => setAddon(i, { quantity: e.target.value })}
                                        aria-label={t('الكمية')}
                                    />
                                    <label className="flex items-center gap-1.5 text-[12px] text-[#4b4b4b]">
                                        <input
                                            type="checkbox"
                                            checked={a.included}
                                            onChange={(e) => setAddon(i, { included: e.target.checked })}
                                        />
                                        {t('ضمن السعر')}
                                    </label>
                                    {/* والثمنُ للعلم — الخادمُ يكتبه من الكتالوج */}
                                    <span className="w-24 text-end tabular-nums text-[#6b7280]">
                                        {a.included || !row ? '—' : money(row.price * (Number(a.quantity) || 0))}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="text-[#b91c1c]"
                                        title={t('حذف')}
                                        onClick={() => onChange({ addons: value.addons.filter((_, k) => k !== i) })}
                                    >
                                        <Trash2 />
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                    {offer.length > 0 && value.addons.length < MAX_ADDONS ? (
                        <div className="mt-2 max-w-sm">
                            <Select
                                value=""
                                aria-label={t('أضف إضافة')}
                                onChange={(e) =>
                                    e.target.value &&
                                    onChange({ addons: [...value.addons, { addon_id: e.target.value, quantity: '1', included: false }] })
                                }
                                placeholder="أضف إضافة…"
                                options={offer.map((a) => ({ label: `${label(a.name, a.name_en)} — ${money(a.price)}`, value: String(a.id) }))}
                            />
                        </div>
                    ) : (
                        value.addons.length === 0 && (
                            <p className="text-[12px] text-[#9ca3af]">{t('لا إضافات تُباع مع هذا الصنف.')}</p>
                        )
                    )}
                </div>
            )}

            <div className="md:col-span-2">
                <span className="mb-1 block text-[12px] text-[#6b7280]">{t('الخيارات والتخصيص')}</span>
                <ul className="flex flex-col gap-2">
                    {value.options.map((o, i) => (
                        <li key={i} className="flex flex-wrap items-center gap-2" data-testid="line-option">
                            <Input
                                className="w-40"
                                value={o.label}
                                maxLength={60}
                                onChange={(e) => setOption(i, { label: e.target.value })}
                                placeholder={t('مثال: اللون')}
                                aria-label={t('الخيار')}
                            />
                            <Input
                                className="min-w-0 flex-1"
                                value={o.value}
                                maxLength={200}
                                onChange={(e) => setOption(i, { value: e.target.value })}
                                placeholder={t('مثال: أحمر وأبيض')}
                                aria-label={t('القيمة')}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="text-[#b91c1c]"
                                title={t('حذف')}
                                onClick={() => onChange({ options: value.options.filter((_, k) => k !== i) })}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
                {value.options.length < MAX_OPTIONS && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="mt-2"
                        onClick={() => onChange({ options: [...value.options, { label: '', value: '' }] })}
                    >
                        <Plus />
                        {t('إضافة خيار')}
                    </Button>
                )}
            </div>
        </div>
    );
}
