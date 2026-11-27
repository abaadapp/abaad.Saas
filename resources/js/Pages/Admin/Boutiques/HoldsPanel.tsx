import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select } from '@/Components/Field';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

/**
 * تحصيلٌ أُلغي بعد صدور ورقة البوتيك — ما عُلّق سدادُه وما سُدّد قبل تحصيله.
 *
 * الورقةُ كما صدرت في كلّ حال. والمعلَّقُ لا يُسدَّد حتّى يُعاد تحصيلُ فاتورته أو يُفرج
 * عنه بسببٍ مكتوب. والحسابُ والحسمُ في الخادم (`BoutiqueHolds`).
 */
export interface SheetHold {
    id: number;
    sheet: string | null;
    invoice_id: number;
    invoice: string | null;
    name: string | null;
    amount: number;
    /** الموقوفُ سدادُه الآن */
    held: number;
    why: string;
    at: string | null;
    state: 'held' | 'withheld' | 'paid_before' | 'payable' | 'paid' | 'recollected' | 'returned' | 'released' | 'reviewed';
    /** ما يُسدَّد من بابه الآن */
    owed: number;
    closed_as: string | null;
    closed_at: string | null;
    note: string | null;
    paid_amount: number | null;
    paid_at: string | null;
}

const METHODS = ['نقدي', 'تحويل', 'بطاقة', 'شيك'];

const STATE: Record<SheetHold['state'], [string, 'warning' | 'neutral' | 'success' | 'danger']> = {
    held: ['معلّق — لا يُسدَّد مع الورقة', 'warning'],
    withheld: ['سُدّدت الورقة دونه — ما زال معلّقًا', 'warning'],
    paid_before: ['سُدّد قبل تحصيله — للمراجعة', 'danger'],
    payable: ['فُكّ تعليقه — ينتظر السداد', 'warning'],
    paid: ['سُدّد بعد فكّه', 'success'],
    recollected: ['أُعيد تحصيله', 'success'],
    returned: ['رُدّت القطعة', 'neutral'],
    released: ['أُفرج عنه بقرار', 'neutral'],
    reviewed: ['رُوجع', 'neutral'],
};

export default function HoldsPanel({ boutiqueId, holds, currency, canPay = true }: { boutiqueId: number; holds: SheetHold[]; currency: Currency; canPay?: boolean }) {
    const t = useTranslate();
    const { errors } = usePage<PageProps>().props as unknown as { errors?: Record<string, string> };
    const [why, setWhy] = useState<Record<number, string>>({});
    const [method, setMethod] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState(false);
    const m = (v: number) => money(v, currency);

    if (holds.length === 0) return null;

    const send = (name: string, id: number, data: Record<string, string>) => {
        setBusy(true);
        router.post(route(name, [boutiqueId, id]), data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <Card className="mb-5 overflow-hidden" data-testid="boutique-holds">
            <div className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <h2 className="text-sm font-semibold text-[#111]">{t('تحصيل أُلغي بعد صدور ورقة التسوية')}</h2>
                <p className="mt-0.5 text-[12px] leading-5 text-[#6b7280]">
                    {t('الورقة كما صدرت ولا تُعدَّل. ما أُلغي تحصيله قبل سدادها يوقف سداده وحده حتى يُعاد التحصيل أو يُفرج عنه بسبب. وما سُدّد قبل الإلغاء يُعرض للمراجعة بلا قيد.')}
                </p>
                {errors?.holds && <p className="mt-2 text-[13px] text-[#b91c1c]" role="alert">{errors.holds}</p>}
            </div>
            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                {holds.map((h) => {
                    const [label, tone] = STATE[h.state];
                    const open = h.closed_at === null;
                    const reason = (why[h.id] ?? '').trim();

                    return (
                        <li key={h.id} className="space-y-2 px-4 py-3 text-[13px]" data-testid={`hold-${h.id}`}>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span className="font-medium text-[#111]">{h.name ?? '—'}</span>
                                <span className="tabular-nums font-semibold">{m(h.amount)}</span>
                                <Badge variant={tone}>{t(label)}</Badge>
                            </div>
                            <p className="text-[12px] leading-5 text-[#6b7280]">
                                {t('الورقة :sheet · الفاتورة :invoice · :why · :at', { sheet: h.sheet ?? '—', invoice: h.invoice ?? '—', why: h.why, at: h.at ?? '' })}
                            </p>
                            {h.state === 'held' && h.held !== h.amount && (
                                <p className="text-[12px] text-[#92400e]">{t('الموقوف الآن :held من :amount', { held: m(h.held), amount: m(h.amount) })}</p>
                            )}
                            {h.note && <p className="text-[12px] text-[#4b4b4b]">{t('السبب: :note', { note: h.note })}</p>}
                            {h.paid_amount !== null && <p className="text-[12px] text-[#047857]">{t('سُدّد :amount في :at', { amount: m(h.paid_amount), at: h.paid_at ?? '' })}</p>}

                            {open && (h.state === 'paid_before' || canPay) && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Input
                                        value={why[h.id] ?? ''}
                                        onChange={(e) => setWhy({ ...why, [h.id]: e.target.value })}
                                        placeholder={t('سبب القرار')}
                                        aria-label={t('سبب القرار')}
                                        maxLength={255}
                                        className="w-full sm:w-72"
                                    />
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy || reason.length < 3}
                                        onClick={() => send(h.state === 'paid_before' ? 'admin.boutiques.holds.review' : 'admin.boutiques.holds.release', h.id, { why: reason })}
                                    >
                                        {h.state === 'paid_before' ? t('تمت المراجعة') : t('أفرج عن السداد')}
                                    </Button>
                                </div>
                            )}
                            {h.state === 'payable' && canPay && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select
                                        value={method[h.id] ?? METHODS[0]}
                                        onChange={(e) => setMethod({ ...method, [h.id]: e.target.value })}
                                        options={METHODS.map((x) => ({ value: x, label: t(x) }))}
                                        aria-label={t('طريقة السداد')}
                                    />
                                    <Button size="sm" disabled={busy} onClick={() => send('admin.boutiques.holds.pay', h.id, { method: method[h.id] ?? METHODS[0] })}>
                                        {t('سدّد :amount', { amount: m(h.owed) })}
                                    </Button>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}
