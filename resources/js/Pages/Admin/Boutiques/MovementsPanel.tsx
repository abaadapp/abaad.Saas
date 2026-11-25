import { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight, ScrollText } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Select } from '@/Components/Field';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';

/**
 * «حركات البوتيك» — كلُّ ما غيّر ما بين المتجر والبوتيك، بمستنده وقيده.
 *
 * الأرقامُ كلُّها من الخادم (`BoutiqueMovements`)، صفحةً صفحة، ولا يُجمع في المتصفّح شيء.
 * والأثرُ ما تغيّر ممّا على المتجر للبوتيك: موجبٌ يزيده وسالبٌ يُنقصه، وصفرٌ حركةٌ لا تغيّره
 * (ورقةٌ صدرت، إشعارٌ ينتظر، مقاصّة).
 */

export type MovementKind =
    | 'recorded' | 'changed' | 'cancelled' | 'piece' | 'piece_back' | 'kept' | 'credit' | 'sheet'
    | 'paid' | 'pieces_paid' | 'note' | 'decided' | 'offset' | 'claim_offset' | 'receipt' | 'refund';

export interface Movement {
    key: string;
    kind: MovementKind;
    state: string | null;
    at: string;
    name: string | null;
    quantity: number | null;
    amount: number;
    effect: number;
    balance: number;
    order: string | null;
    sheet: string | null;
    expense: string | null;
    entry: string | null;
    transaction: string | null;
    why: string | null;
    who: string | null;
    order_url: string | null;
    entry_url: string | null;
    transaction_url: string | null;
}

export interface MovementsPage {
    rows: Movement[];
    page: number;
    last_page: number;
    total: number;
    per_page: number;
    opening: number;
    closing: number;
    balance: {
        unsettled: number; pieces_unsettled: number; sheets_unpaid: number; pieces_unpaid: number;
        credit: number; claims: number; net: number;
    };
    groups: Record<string, { count: number; effect: number }>;
}

export const KIND: Record<MovementKind, string> = {
    recorded: 'مستحق سُجّل',
    changed: 'تصحيح كمية قبل الورقة',
    cancelled: 'إلغاء بيعة قبل الورقة',
    piece: 'مستحق قطعة في منتج المتجر',
    piece_back: 'عكس مستحق قطعة',
    kept: 'قطعة لم تعد، ومستحقها باقٍ',
    credit: 'رصيد للمتجر على البوتيك',
    sheet: 'صدور ورقة تسوية',
    paid: 'سداد ورقة',
    pieces_paid: 'سداد قطع ورقة',
    note: 'إشعار مرتجع بعد الورقة',
    decided: 'حسم مرتجع',
    offset: 'مقاصة رصيد من ورقة',
    claim_offset: 'خصم مطالبة من ورقة',
    receipt: 'تحصيل مطالبة من البوتيك',
    refund: 'استرداد رصيد من البوتيك',
};

/** من يتحمّل المرتجعَ، وحالُ القطعة */
const STATE: Record<string, string> = {
    awaiting: 'ينتظر القطعة', review: 'ينتظر قرار المحاسب', shop: 'على المتجر', reduced: 'على البوتيك',
    claim: 'مطالبة على البوتيك', recovered: 'استُردّت', lost: 'لم تعد', unverified: 'لم يُتحقق منها',
};

const GROUPS: { value: string; label: string }[] = [
    { value: 'all', label: 'كل الحركات' },
    { value: 'dues', label: 'المستحقات' },
    { value: 'sheets', label: 'أوراق التسوية' },
    { value: 'payments', label: 'السداد' },
    { value: 'returns', label: 'المرتجعات' },
    { value: 'credits', label: 'الأرصدة والمطالبات' },
];

interface Props {
    boutiqueId: number;
    period: string;
    currency: Currency;
}

async function load(url: string, signal: AbortSignal): Promise<MovementsPage> {
    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) throw new Error(String(res.status));

    return (await res.json()) as MovementsPage;
}

export default function MovementsPanel({ boutiqueId, period, currency }: Props) {
    const t = useTranslate();
    const [group, setGroup] = useState('all');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<MovementsPage | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => setPage(1), [period, group]);

    useEffect(() => {
        const ctl = new AbortController();
        setFailed(false);
        load(route('admin.boutiques.movements', { id: boutiqueId, period, page, ...(group === 'all' ? {} : { group }) }), ctl.signal)
            .then(setData)
            .catch((e: Error) => { if (e.name !== 'AbortError') setFailed(true); });

        return () => ctl.abort();
    }, [boutiqueId, period, group, page]);

    const signed = (v: number) => (v === 0 ? '—' : `${v > 0 ? '+' : '−'}${money(Math.abs(v), currency)}`);

    return (
        <Card className="mb-5 overflow-hidden" data-testid="boutique-movements">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <div className="flex min-w-0 items-center gap-2">
                    <ScrollText className="size-4 shrink-0 text-[#6b7280]" />
                    <span className="text-sm font-semibold text-[#111]">{t('حركات البوتيك')}</span>
                    {data && <span className="text-[12px] text-[#9ca3af]">{number(data.total)}</span>}
                </div>
                <Select
                    value={group}
                    onChange={(e) => setGroup(e.target.value)}
                    options={GROUPS.map((g) => ({ value: g.value, label: t(g.label) }))}
                    aria-label={t('نوع الحركة')}
                    className="w-48"
                />
            </div>

            {failed && <p className="px-4 py-6 text-center text-[13px] text-[#b91c1c]">{t('تعذّر تحميل الحركات — أعد المحاولة.')}</p>}

            {data && !failed && (
                <>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-2 px-4 py-3 text-[12px] text-[#6b7280] sm:grid-cols-3">
                        <div>
                            <p>{t('قبل هذا الشهر')}</p>
                            <p className="text-[15px] font-semibold tabular-nums text-[#111]">{money(data.opening, currency)}</p>
                        </div>
                        <div>
                            <p>{t('آخر الشهر')}</p>
                            <p className="text-[15px] font-semibold tabular-nums text-[#111]">{money(data.closing, currency)}</p>
                        </div>
                        <div>
                            <p>{t('على المتجر للبوتيك الآن')}</p>
                            <p className="text-[15px] font-semibold tabular-nums text-[#6d28d9]">{money(data.balance.net, currency)}</p>
                        </div>
                    </div>

                    {data.rows.length === 0 ? (
                        <p className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-8 text-center text-[13px] text-[#9ca3af]">{t('لا حركات في هذا الشهر')}</p>
                    ) : (
                        <div className="overflow-x-auto border-t border-[var(--ui-border,#e8e8e8)] no-scrollbar">
                            <table className="w-full min-w-[760px] text-sm">
                                <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                                    <tr>
                                        <th className="px-4 py-2 text-start font-medium">{t('التاريخ')}</th>
                                        <th className="px-4 py-2 text-start font-medium">{t('الحركة')}</th>
                                        <th className="px-4 py-2 text-start font-medium">{t('المستند')}</th>
                                        <th className="px-4 py-2 text-end font-medium">{t('المبلغ')}</th>
                                        <th className="px-4 py-2 text-end font-medium">{t('الأثر')}</th>
                                        <th className="px-4 py-2 text-end font-medium">{t('الرصيد')}</th>
                                        <th className="px-4 py-2 text-start font-medium">{t('القيد والحركة المالية')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.rows.map((r) => (
                                        <tr key={r.key} className="border-t border-[var(--ui-border,#e8e8e8)] align-top">
                                            <td className="whitespace-nowrap px-4 py-2.5 text-[12px] tabular-nums text-[#6b7280]">{r.at}</td>
                                            <td className="px-4 py-2.5">
                                                <div className="flex flex-wrap items-center gap-1.5 text-[#111]">
                                                    {t(KIND[r.kind])}
                                                    {r.state && STATE[r.state] && <Badge variant="neutral">{t(STATE[r.state])}</Badge>}
                                                </div>
                                                {r.name && (
                                                    <p className="text-[12px] text-[#6b7280]">
                                                        {r.name}
                                                        {r.quantity !== null && ` × ${number(Math.abs(r.quantity))}`}
                                                    </p>
                                                )}
                                                {r.why && <p className="text-[12px] text-[#9ca3af]">{r.why}</p>}
                                            </td>
                                            <td className="px-4 py-2.5 text-[12px]">
                                                {r.order && (r.order_url
                                                    ? <a href={r.order_url} className="block text-[#1d4ed8] underline" dir="ltr">{r.order}</a>
                                                    : <span className="block text-[#6b7280]" dir="ltr">{r.order}</span>)}
                                                {r.sheet && <span className="block text-[#6b7280]" dir="ltr">{r.sheet}</span>}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-[#6b7280]">{money(Math.abs(r.amount), currency)}</td>
                                            <td className={cn('whitespace-nowrap px-4 py-2.5 text-end font-semibold tabular-nums',
                                                r.effect > 0 && 'text-[#6d28d9]', r.effect < 0 && 'text-[#047857]', r.effect === 0 && 'text-[#9ca3af]')} dir="ltr">
                                                {signed(r.effect)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-[#111]">{money(r.balance, currency)}</td>
                                            <td className="px-4 py-2.5 text-[12px]">
                                                {r.entry && (r.entry_url
                                                    ? <SmartLink routeName="admin.finance.journal" href={r.entry_url} className="block text-[#1d4ed8] underline">{t('قيد')} {r.entry}</SmartLink>
                                                    : <span className="block text-[#6b7280]">{t('قيد')} {r.entry}</span>)}
                                                {r.transaction && (r.transaction_url
                                                    ? <SmartLink routeName="admin.finance.transactions" href={r.transaction_url} className="block text-[#1d4ed8] underline" >{r.transaction}</SmartLink>
                                                    : <span className="block text-[#6b7280]">{r.transaction}</span>)}
                                                {!r.entry && r.kind === 'recorded' && !r.sheet && <span className="text-[#9ca3af]">{t('يُقيَّد مع ورقته')}</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {data.last_page > 1 && (
                        <div className="flex items-center justify-between gap-2 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-2.5 text-[12px] text-[#6b7280]">
                            <span>{t('صفحة :page من :last', { page: String(data.page), last: String(data.last_page) })}</span>
                            <span className="flex gap-1">
                                <Button type="button" size="sm" variant="ghost" disabled={data.page <= 1} onClick={() => setPage(data.page - 1)} aria-label={t('السابق')}><ChevronRight /></Button>
                                <Button type="button" size="sm" variant="ghost" disabled={data.page >= data.last_page} onClick={() => setPage(data.page + 1)} aria-label={t('التالي')}><ChevronLeft /></Button>
                            </span>
                        </div>
                    )}
                </>
            )}
        </Card>
    );
}
