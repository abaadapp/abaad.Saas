import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';

/**
 * «منتجاتُ البوتيك في هذه الباقة» — في تسعير المنتج، لمن فُتح له السجلّ.
 *
 * أربعُ خطوات: صنفٌ من بوتيك، مشمولٌ أم اختياريّ، الكمّيّة، حفظ. والأرقامُ
 * تحتها تتغيّر مع سعر البيع وهو يُكتب: ما للبوتيك، ونسبتُه من البيع، وما
 * يبقى لصاحب المتجر قبل تكاليفه. وهي الحسبةُ التي يكتبها البيع
 * (`BoutiqueDues`): ثمنُ الصنف في كمّيّته — بلا عمولة ولا خصم.
 *
 * والأرقامُ هنا للعرض: الخادمُ يسعّر عند البيع من القاعدة ويثبّت ما فُحص.
 */

export type BoutiquePart = {
    id: number;
    kind: 'included' | 'optional';
    product_id: number;
    name: string;
    boutique_id: number;
    boutique: string | null;
    unit_price: number;
    quantity: number;
    addon_price?: number;
    pos_enabled?: boolean;
    web_enabled?: boolean;
};

export type BoutiquePartOption = {
    value: number;
    label: string;
    boutique_id: number;
    boutique: string | null;
    price: number;
    quantity: number;
    blocked: string | null;
};

export type BoutiquePartsData = {
    taxed: boolean;
    own_boutique: boolean;
    options: BoutiquePartOption[];
    parts: BoutiquePart[];
};

/** بالألف صحيحًا: الريالُ العمانيّ ثلاثُ خانات، والكسورُ العائمة تُخطئ في الثالثة */
const mills = (n: number) => Math.round(n * 1000);
const fromMills = (m: number) => m / 1000;

export type Split = {
    price: number;
    boutiques: { id: number; name: string | null; due: number; share: number }[];
    included: number;
    remainder: number;
    optional: { id: number; name: string; customer: number; due: number; kept: number }[];
};

/**
 * سعرُ الإضافة المقترح للعميل: ثمنُ القطعة في كمّيّتها — قطعتان بسبعة = ١٤.
 * ولصاحب المتجر أن يكتب غيره، ومستحقُّ البوتيك لا يتبعه.
 */
export function suggestedCharge(unitPrice: number, quantity: number): number {
    return fromMills(mills(unitPrice) * Math.max(0, Math.floor(quantity || 0)));
}

/**
 * الأجزاءُ كما ستُحفظ لو ضُغط «أضف»: المشمولُ المكرَّر يحلّ محلّ صفّه في الوصفة
 * (الخادمُ يحدّث كمّيّته ولا يكتب ثانيًا) — فلا يُعدّ مرّتين. والاختياريُّ
 * إضافةٌ جديدةٌ تُكتب بجانب ما قبلها.
 */
export function withDraft(parts: BoutiquePart[], next: BoutiquePart | null): BoutiquePart[] {
    if (!next) return parts;
    if (next.kind === 'included' && parts.some((x) => x.kind === 'included' && x.product_id === next.product_id)) {
        return parts.map((x) => (x.kind === 'included' && x.product_id === next.product_id ? { ...x, quantity: next.quantity } : x));
    }
    return [...parts, next];
}

/** ما للبوتيكات وما يبقى من سعر الباقة — والإضافةُ الاختياريّة وحدها */
export function boutiqueSplit(price: number, parts: BoutiquePart[]): Split {
    const p = mills(price || 0);
    const by = new Map<number, { id: number; name: string | null; due: number }>();
    let included = 0;

    for (const part of parts.filter((x) => x.kind === 'included')) {
        const due = mills(part.unit_price) * part.quantity;
        included += due;
        const row = by.get(part.boutique_id) ?? { id: part.boutique_id, name: part.boutique, due: 0 };
        row.due += due;
        by.set(part.boutique_id, row);
    }

    return {
        price: fromMills(p),
        boutiques: [...by.values()].map((b) => ({
            id: b.id, name: b.name, due: fromMills(b.due),
            share: p > 0 ? Math.round((b.due / p) * 1000) / 10 : 0,
        })),
        included: fromMills(included),
        remainder: fromMills(p - included),
        optional: parts.filter((x) => x.kind === 'optional').map((x) => {
            const due = mills(x.unit_price) * x.quantity;
            const charge = mills(x.addon_price ?? 0);
            return { id: x.id, name: x.name, customer: fromMills(p + charge), due: fromMills(due), kept: fromMills(charge - due) };
        }),
    };
}

interface Props {
    productId: number;
    price: string;
    currencyLabel: string;
    data: BoutiquePartsData;
}

export default function BoutiqueParts({ productId, price, currencyLabel, data }: Props) {
    const t = useTranslate();
    const [parts, setParts] = useState<BoutiquePart[]>(data.parts);
    const [pick, setPick] = useState('');
    const [mode, setMode] = useState<'included' | 'optional'>('included');
    const [qty, setQty] = useState('1');
    // `null` = يتبع المقترح (ثمنُ القطعة × الكمّيّة) ما لم يكتب صاحبُ المتجر سعرًا
    const [charge, setCharge] = useState<string | null>(null);
    const [pos, setPos] = useState(true);
    const [web, setWeb] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const chosen = data.options.find((o) => String(o.value) === pick) ?? null;
    const amount = (n: number) => `${n.toFixed(3)} ${currencyLabel}`;

    const pieces = Math.floor(Number(qty));
    const suggested = chosen ? suggestedCharge(chosen.price, pieces) : 0;
    const customer = charge === null || charge === '' ? suggested : Number(charge);
    // مستحقُّ البوتيك هو المقترحُ نفسُه — ويبقى هو ولو كتب صاحبُ المتجر سعرًا آخر
    const due = suggested;
    const replaces = chosen && mode === 'included'
        ? parts.find((x) => x.kind === 'included' && x.product_id === chosen.value) ?? null
        : null;

    /* المعاينةُ قبل الحفظ: الأجزاءُ القائمة كما ستكون بعد ما يُختار الآن */
    const draft = withDraft(parts, chosen && pieces >= 1 ? {
        id: 0, kind: mode, product_id: chosen.value, name: chosen.label,
        boutique_id: chosen.boutique_id, boutique: chosen.boutique,
        unit_price: chosen.price, quantity: pieces, addon_price: customer,
    } : null);
    const split = boutiqueSplit(Number(price) || 0, draft);

    if (data.taxed || data.own_boutique) {
        return (
            <Card className="mt-4 p-6" data-testid="boutique-parts">
                <h3 className="mb-2 font-bold text-[#111]">{t('منتجات البوتيك في هذه الباقة')}</h3>
                <p className="text-sm text-[#6b7280]" data-testid="boutique-parts-closed">
                    {data.taxed
                        ? t('مستحقُّ البوتيك لا يُحسب بعد في متجرٍ يحتسب الضريبة.')
                        : t('هذا الصنف نفسُه ملكُ بوتيك — لا يحمل صنفَ بوتيكٍ آخر.')}
                </p>
            </Card>
        );
    }

    async function call(method: 'POST' | 'DELETE', url: string, body?: object) {
        setBusy(true);
        setError(null);
        try {
            const res = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: body ? JSON.stringify(body) : undefined,
            });
            const payload = await res.json();
            if (!res.ok) {
                setError(Object.values(payload.errors ?? {}).flat()[0] as string ?? payload.message ?? t('تعذّر الحفظ'));
                return;
            }
            setParts(payload.parts);
            setPick('');
            setQty('1');
            setCharge(null);
        } finally {
            setBusy(false);
        }
    }

    function save() {
        if (!chosen) return;
        call('POST', route('admin.products.boutique-parts.store', productId), {
            component_product_id: chosen.value,
            mode,
            quantity: Math.floor(Number(qty)),
            ...(mode === 'optional' ? { addon_price: customer, pos_enabled: pos, web_enabled: web } : {}),
        });
    }

    return (
        <Card className="mt-4 p-6" data-testid="boutique-parts">
            <h3 className="mb-1 font-bold text-[#111]">{t('منتجات البوتيك في هذه الباقة')}</h3>
            <p className="mb-4 text-[12px] text-[#9ca3af]">
                {t('للبوتيك ثمنُ صنفه في كمّيّته — بلا عمولة ولا خصم. والخصمُ على المتجر.')}
            </p>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Field label={t('صنف من بوتيك')}>
                    <Select
                        value={pick}
                        onChange={(e) => {
                            setPick(e.target.value);
                            setCharge(null);
                        }}
                        aria-label={t('صنف من بوتيك')}
                        options={[
                            { label: t('اختر صنفًا'), value: '' },
                            ...data.options.map((o) => ({
                                label: `${o.label} — ${o.boutique ?? ''} (${amount(o.price)})${o.blocked ? ` — ${t(o.blocked)}` : ''}`,
                                value: String(o.value),
                                disabled: o.blocked !== null,
                            })),
                        ]}
                    />
                </Field>
                <Field label={t('كيف يدخل الباقة؟')}>
                    <Select
                        value={mode}
                        onChange={(e) => setMode(e.target.value as 'included' | 'optional')}
                        aria-label={t('كيف يدخل الباقة؟')}
                        options={[
                            { label: t('مشمول في سعر الباقة'), value: 'included' },
                            { label: t('إضافة اختياريّة تزيد السعر'), value: 'optional' },
                        ]}
                    />
                </Field>
                <Field label={t('الكمّيّة (قطع كاملة)')}>
                    <Input type="number" min="1" step="1" dir="ltr" value={qty}
                        onChange={(e) => setQty(e.target.value)} data-testid="boutique-part-qty" />
                </Field>
                {mode === 'optional' && (
                    <Field label={`${t('سعرها للعميل')} (${currencyLabel})`}>
                        <Input type="number" min="0" step="0.001" dir="ltr" value={charge ?? String(suggested)}
                            onChange={(e) => setCharge(e.target.value)} data-testid="boutique-part-charge" />
                    </Field>
                )}
            </div>

            {mode === 'optional' && chosen && pieces >= 1 && (
                <div className="mt-2 text-[12px] text-[#6b7280]" data-testid="boutique-part-charge-effect">
                    <p>
                        {t('المقترح :suggested = :unit × :qty', { suggested: amount(suggested), unit: amount(chosen.price), qty: pieces })}
                        {charge !== null && Number(charge) !== suggested && (
                            <button type="button" className="ms-2 underline" onClick={() => setCharge(null)} data-testid="boutique-part-charge-reset">
                                {t('أعِد المقترح')}
                            </button>
                        )}
                    </p>
                    <p>
                        {t('للبوتيك :due ثابتًا — لا يتغيّر بسعر العميل.', { due: amount(due) })}{' '}
                        {customer >= due
                            ? t('ويبقى للمتجر من الإضافة :kept.', { kept: amount(fromMills(mills(customer) - mills(due))) })
                            : <span className="text-red-600" data-testid="boutique-part-charge-below">
                                {t('سعرُ العميل أقلّ من مستحقّ البوتيك بـ:gap — يُدفع الفرقُ من سعر الباقة.', { gap: amount(fromMills(mills(due) - mills(customer))) })}
                            </span>}
                    </p>
                </div>
            )}
            {replaces && pieces >= 1 && replaces.quantity !== pieces && (
                <p className="mt-2 text-[12px] text-[#6b7280]" data-testid="boutique-part-replaces">
                    {t('«:name» في الباقة بكمّيّة :from — يُحفظ بكمّيّة :to لا بالمجموع.', { name: replaces.name, from: replaces.quantity, to: pieces })}
                </p>
            )}

            {mode === 'optional' && (
                <div className="mt-3 flex flex-wrap gap-4 text-sm">
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={pos} onChange={(e) => setPos(e.target.checked)} data-testid="boutique-part-pos" />
                        {t('نقطة البيع (POS)')}
                    </label>
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={web} onChange={(e) => setWeb(e.target.checked)} data-testid="boutique-part-web" />
                        {t('الموقع الإلكتروني')}
                    </label>
                </div>
            )}

            <div className="mt-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4" data-testid="boutique-split">
                <p className="text-sm">{t('سعر الباقة')}: <strong dir="ltr">{amount(split.price)}</strong></p>
                {split.boutiques.map((b) => (
                    <p key={b.id} className="text-sm" data-testid="boutique-split-due">
                        {t('لـ:name', { name: b.name ?? '' })}: <strong dir="ltr">{amount(b.due)}</strong>
                        <span className="text-[#6b7280]"> — {b.share}% {t('من البيع')}</span>
                    </p>
                ))}
                <p className="mt-1 text-sm" data-testid="boutique-split-remainder">
                    {t('يبقى للمتجر قبل تكاليفه')}: <strong dir="ltr">{amount(split.remainder)}</strong>
                </p>
                {split.remainder < 0 && (
                    <p className="mt-1 text-sm text-red-600" data-testid="boutique-split-short">
                        {t('سعرُ الباقة أقلُّ ممّا للبوتيكات فيها — لن تُباع حتى يُرفع.')}
                    </p>
                )}
                {split.optional.map((o) => (
                    <p key={`${o.id}-${o.name}`} className="mt-1 text-[13px] text-[#374151]" data-testid="boutique-split-optional">
                        {t('إن اختار العميل «:name»: يدفع :customer، وللبوتيك :due، ويبقى للمتجر منها :kept', {
                            name: o.name, customer: amount(o.customer), due: amount(o.due), kept: amount(o.kept),
                        })}
                    </p>
                ))}
            </div>

            {error && <p className="mt-2 text-sm text-red-600" data-testid="boutique-part-error">{error}</p>}

            <div className="mt-3">
                <Button type="button" onClick={save} disabled={!chosen || busy || Number(qty) < 1} data-testid="boutique-part-save">
                    {t('أضف إلى الباقة')}
                </Button>
            </div>

            {parts.length > 0 && (
                <ul className="mt-4 divide-y divide-[var(--ui-border,#e8e8e8)]" data-testid="boutique-part-list">
                    {parts.map((p) => (
                        <li key={`${p.kind}-${p.id}`} className="flex items-center justify-between py-2 text-sm">
                            <span>
                                {p.name} × {p.quantity} — {p.boutique}
                                <span className="text-[#6b7280]">
                                    {' '}({p.kind === 'included'
                                    ? t('مشمول')
                                    : t('اختياريّ بـ:price — للبوتيك :due', { price: amount(p.addon_price ?? 0), due: amount(suggestedCharge(p.unit_price, p.quantity)) })})
                                </span>
                            </span>
                            <button type="button" aria-label={t('إزالة')} disabled={busy}
                                onClick={() => call('DELETE', route('admin.products.boutique-parts.destroy', [productId, p.kind, p.id]))}>
                                <Trash2 className="h-4 w-4 text-[#9ca3af]" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
