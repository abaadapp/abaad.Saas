import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select } from '@/Components/Field';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

/**
 * مكوّناتُ الباقات وإضافاتُها في كشف البوتيك — بطريقتها لا بطريقة المنفرد.
 *
 * المنفردُ فوقها: إجماليٌّ ونسبةُ المتجر وصافٍ، ويُسدَّد مصروفًا كما كان.
 * وهذه ثمنُ قطعٍ دخلت باقاتٍ بلا عمولة، تُسدَّد من «مستحقّات البوتيكات».
 * فلا يُجمع إيرادٌ إلى مصروف: كلُّ رقمٍ هنا مالٌ لأحد الطرفين على الآخر.
 *
 * والشاشةُ تقول الاتّجاهين بلفظهما: «للبوتيك على المتجر» و«للمتجر على
 * البوتيك» — لا رصيدًا سالبًا يُفهم بالعكس.
 */

export interface ComponentsPart {
    lines: { product_id: number; name: string; source: string; unit_price: number; quantity: number; amount: number }[];
    due: number;
    credit: number;
    credit_available: number;
    net: number;
    count: number;
}

export interface BoutiqueLedger {
    owed_unsettled: number;
    owed_issued: number;
    owed: number;
    standalone_unpaid: number;
    credit: number;
    movements: {
        at: string | null; type: 'reversal' | 'credit' | 'kept' | 'offset' | 'refund';
        state: string | null; product: string | null; quantity: number | null;
        amount: number; order: string | null; reason: string | null;
    }[];
}

export interface SettlementComponents {
    id: number;
    number: string;
    kind?: string;
    components_due?: number;
    components_credit?: number;
    components_net?: number;
    components_paid?: boolean;
}

const METHODS = ['نقدي', 'تحويل', 'بطاقة', 'شيك'];

const MOVE_LABEL: Record<BoutiqueLedger['movements'][number]['type'], string> = {
    reversal: 'عكسُ مستحقٍّ قبل تسويته',
    credit: 'رصيدٌ للمتجر — أُلغي بعد التسوية',
    kept: 'قطعةٌ لم تعد — باقيةٌ للبوتيك',
    offset: 'مقاصّةٌ من رصيد المتجر',
    refund: 'استردادٌ نقديّ من البوتيك',
};

export default function ComponentsPanel({ boutiqueId, part, payable, ledger, settlement, currency }: {
    boutiqueId: number;
    part: ComponentsPart;
    payable: number;
    ledger: BoutiqueLedger;
    settlement: SettlementComponents | null;
    currency: Currency;
}) {
    const t = useTranslate();
    const { errors } = usePage<PageProps>().props as unknown as { errors?: Record<string, string> };
    const [method, setMethod] = useState('نقدي');
    const [refundAmount, setRefundAmount] = useState('');
    const m = (n: number) => money(n, currency);

    const pay = () => settlement && router.post(
        route('admin.boutiques.components.pay', [boutiqueId, settlement.id]), { method }, { preserveScroll: true },
    );
    const refund = () => router.post(
        route('admin.boutiques.refund', boutiqueId),
        { method, ...(refundAmount ? { amount: Number(refundAmount) } : {}) },
        { preserveScroll: true },
    );

    return (
        <>
            <Card className="mb-5 overflow-hidden" data-testid="boutique-components">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                    <p className="text-sm font-semibold text-[#111]">{t('مكوّنات الباقات وإضافاتها')}</p>
                    <p className="text-[12px] text-[#9ca3af]">{t('ثمنُ القطعة في كمّيّتها — بلا عمولة. يُسدَّد من «مستحقّات البوتيكات» لا مصروفًا.')}</p>
                </div>
                {part.lines.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مكوّنات تنتظر ورقةً في هذا الشهر')}</p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start font-medium">{t('الصنف')}</th>
                                <th className="px-4 py-2 text-start font-medium">{t('كيف دخل')}</th>
                                <th className="px-4 py-2 text-end font-medium">{t('السعر')}</th>
                                <th className="px-4 py-2 text-end font-medium">{t('الكمية')}</th>
                                <th className="px-4 py-2 text-end font-medium">{t('المستحق')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {part.lines.map((l) => (
                                <tr key={`${l.product_id}-${l.unit_price}-${l.source}`} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                    <td className="px-4 py-2">{l.name}</td>
                                    <td className="px-4 py-2 text-[#6b7280]">{l.source === 'recipe' ? t('مشمول في الباقة') : t('إضافة اختياريّة')}</td>
                                    <td className="px-4 py-2 text-end tabular-nums">{m(l.unit_price)}</td>
                                    <td className="px-4 py-2 text-end tabular-nums">{number(l.quantity)}</td>
                                    <td className="px-4 py-2 text-end tabular-nums">{m(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                <div className="grid grid-cols-1 gap-2 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm sm:grid-cols-4" data-testid="boutique-components-totals">
                    <p>{t('مستحق المكوّنات')}: <strong className="tabular-nums">{m(part.due)}</strong></p>
                    <p>{t('يُقاصّ من رصيد المتجر')}: <strong className="tabular-nums">{m(part.credit)}</strong></p>
                    <p>{t('صافي المكوّنات')}: <strong className="tabular-nums">{m(part.net)}</strong></p>
                    <p>{t('المطلوب سداده هذا الشهر')}: <strong className="tabular-nums" data-testid="boutique-payable">{m(payable)}</strong></p>
                </div>
            </Card>

            <Card className="mb-5 p-4" data-testid="boutique-balance">
                <p className="mb-3 text-sm font-semibold text-[#111]">{t('الرصيد بين المتجر والبوتيك')}</p>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <p className="text-[12px] text-[#6b7280]">{t('للبوتيك على المتجر — مكوّنات')}</p>
                        <p className="text-[20px] font-bold tabular-nums text-[#6d28d9]" data-testid="boutique-owed">{m(ledger.owed)}</p>
                        <p className="text-[11px] text-[#9ca3af]">
                            {t('لم تُسوَّ :a — في ورقاتٍ لم تُسدَّد :b', { a: m(ledger.owed_unsettled), b: m(ledger.owed_issued) })}
                        </p>
                    </div>
                    <div>
                        <p className="text-[12px] text-[#6b7280]">{t('للبوتيك على المتجر — منفرد في ورقاتٍ صدرت')}</p>
                        <p className="text-[20px] font-bold tabular-nums text-[#6d28d9]">{m(ledger.standalone_unpaid)}</p>
                        <p className="text-[11px] text-[#9ca3af]">{t('يُسدَّد من المبالغ المستحقة')}</p>
                    </div>
                    <div>
                        <p className="text-[12px] text-[#6b7280]">{t('للمتجر على البوتيك')}</p>
                        <p className="text-[20px] font-bold tabular-nums text-[#047857]" data-testid="boutique-credit">{m(ledger.credit)}</p>
                        <p className="text-[11px] text-[#9ca3af]">{t('يُقاصّ بما يُستحقّ بعدُ أو يُستردّ')}</p>
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap items-end gap-3">
                    <div className="w-40">
                        <Select value={method} onChange={(e) => setMethod(e.target.value)} aria-label={t('طريقة الدفع')}
                            options={METHODS.map((x) => ({ label: x, value: x }))} />
                    </div>
                    {settlement && settlement.kind !== 'refund' && (settlement.components_due ?? 0) > 0 && (
                        settlement.components_paid ? (
                            <Badge variant="success">{t('سُدِّد جزءُ المكوّنات في :number', { number: settlement.number })}</Badge>
                        ) : (
                            <Button type="button" onClick={pay} data-testid="boutique-pay-components">
                                {t('سدّد مكوّنات :number (:amount)', { number: settlement.number, amount: m(settlement.components_net ?? 0) })}
                            </Button>
                        )
                    )}
                    {ledger.credit > 0 && (
                        <>
                            <Input className="w-32" type="number" min="0" step="0.001" dir="ltr" value={refundAmount}
                                onChange={(e) => setRefundAmount(e.target.value)} placeholder={String(ledger.credit)}
                                aria-label={t('المبلغ المسترد')} />
                            <Button type="button" variant="outline" onClick={refund} data-testid="boutique-refund">
                                {t('سجّل استردادًا من البوتيك')}
                            </Button>
                        </>
                    )}
                </div>
                {errors?.refund && <p className="mt-2 text-[12px] text-[#b91c1c]">{errors.refund}</p>}
            </Card>

            {ledger.movements.length > 0 && (
                <Card className="mb-5 overflow-hidden" data-testid="boutique-movements">
                    <p className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm font-semibold text-[#111]">
                        {t('حركات الإلغاء والمقاصّة والاسترداد')}
                    </p>
                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] text-sm">
                        {ledger.movements.map((mv, i) => (
                            <li key={i} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                                <span>
                                    {t(MOVE_LABEL[mv.type])}
                                    {mv.product && <> — {mv.product}{mv.quantity !== null && <> × {number(Math.abs(mv.quantity))}</>}</>}
                                    {mv.order && <span className="text-[#6b7280]"> — {mv.order}</span>}
                                </span>
                                <span className="tabular-nums text-[#374151]">{mv.at} — {m(Math.abs(mv.amount))}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </>
    );
}
