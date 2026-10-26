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
 * مرتجعاتُ البوتيك بعد أوراقها — إشعارٌ لكلّ مرتجع، والورقةُ كما صدرت.
 *
 * ثلاثةُ أسئلةٍ لا يُغني أحدُها عن غيره: أعادت القطعة؟ (`condition`)، ومن
 * يتحمّل حصّتها؟ (`status`)، وهل استُردّ ما دُفع؟ (`remaining`). والشاشةُ
 * تسأل وتعرض؛ الحسمُ والقيدُ في الخادم (`BoutiqueReturns`).
 */

export interface ReturnNote {
    id: number;
    order: string | null;
    sheet: string | null;
    name: string;
    quantity: number;
    amount: number;
    condition: 'pending' | 'sellable' | 'damaged';
    status: 'awaiting' | 'review' | 'shop' | 'reduced' | 'claim' | 'recovered';
    basis: string | null;
    terms_ref: string | null;
    decision_note: string | null;
    sold_at: string | null;
    returned_at: string | null;
    booked_at: string | null;
    remaining: number;
}

export interface BoutiqueReturnsPart {
    notes: ReturnNote[];
    /** ما للمتجر على البوتيك من مطالباتٍ لم تُستردّ */
    open: number;
    review: number;
    /** أوراقٌ لم تُسدَّد قُيّدت يومَ صدورها — منها وحدها يُخصم */
    targets: { id: number; number: string; payable: number }[];
    can_decide: boolean;
}

const METHODS = ['نقدي', 'تحويل', 'بطاقة', 'شيك'];

const STATUS: Record<ReturnNote['status'], [string, 'warning' | 'neutral' | 'success' | 'danger']> = {
    awaiting: ['بانتظار استلام القطعة', 'warning'],
    review: ['بانتظار قرار محاسبي', 'warning'],
    shop: ['على المتجر', 'neutral'],
    reduced: ['خُفّض به مستحقّ الورقة', 'success'],
    claim: ['مطالبة على البوتيك', 'danger'],
    recovered: ['استُردّ', 'success'],
};

const CONDITION: Record<ReturnNote['condition'], string> = {
    pending: 'لم تُستلم بعد',
    sellable: 'عادت سليمة',
    damaged: 'تالفة أو لم تعد',
};

export default function ReturnsPanel({ boutiqueId, terms, termsRef, part, currency }: {
    boutiqueId: number;
    terms: string | null;
    termsRef: string | null;
    part: BoutiqueReturnsPart;
    currency: Currency;
}) {
    const t = useTranslate();
    const { errors } = usePage<PageProps<Record<string, unknown>>>().props;
    const m = (n: number) => money(n, currency);
    const [why, setWhy] = useState<Record<number, string>>({});
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState(METHODS[1]);
    const [reference, setReference] = useState('');
    const [target, setTarget] = useState<string>(part.targets[0] ? String(part.targets[0].id) : '');

    if (part.notes.length === 0 && !terms) return null;

    const decide = (note: ReturnNote, bearer: 'boutique' | 'shop') => router.post(
        route('admin.boutiques.returns.review', boutiqueId),
        { note: note.id, bearer, why: why[note.id] ?? '' },
        { preserveScroll: true },
    );
    const receipt = () => router.post(
        route('admin.boutiques.returns.receipt', boutiqueId),
        { amount: Number(amount), method, reference },
        { preserveScroll: true, onSuccess: () => { setAmount(''); setReference(''); } },
    );
    const offset = () => target && router.post(
        route('admin.boutiques.returns.offset', boutiqueId), { settlement: Number(target) }, { preserveScroll: true },
    );

    return (
        <Card className="mb-5 overflow-hidden" data-testid="boutique-returns">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <span className="text-sm font-semibold text-[#111]">{t('مرتجعات بعد ورقة التسوية')}</span>
                <span className="text-[12px] text-[#6b7280]" data-testid="boutique-returns-terms-read">
                    {terms
                        ? t(terms === 'all' ? 'الاتفاقية: البوتيك يتحمّل كلّ مرتجع — :ref' : 'الاتفاقية: البوتيك يتحمّل مرتجع القطعة السليمة — :ref', { ref: termsRef ?? '' })
                        : t('شرط المرتجع غير موثّق — كلّ مرتجع يُعرض للمراجعة')}
                </span>
            </div>

            {part.notes.length > 0 && (
                <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] text-sm">
                    {part.notes.map((n) => (
                        <li key={n.id} className="px-4 py-3" data-testid="boutique-return">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    {n.name} × {number(n.quantity)}
                                    <span className="text-[#6b7280]"> — {t('ورقة :sheet، طلب :order', { sheet: n.sheet ?? '—', order: n.order ?? '—' })}</span>
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="tabular-nums">{m(n.amount)}</span>
                                    <Badge variant={STATUS[n.status][1]}>{t(STATUS[n.status][0])}</Badge>
                                </span>
                            </div>
                            <p className="mt-1 text-[12px] text-[#6b7280]">
                                {t(CONDITION[n.condition])}
                                {' — '}{t('بيعت :sold، رُدّت :returned', { sold: n.sold_at ?? '—', returned: n.returned_at ?? '—' })}
                                {n.booked_at && <>{' — '}{t('دخل الدفتر :date', { date: n.booked_at })}</>}
                                {n.status === 'claim' && <>{' — '}{t('الباقي :amount', { amount: m(n.remaining) })}</>}
                            </p>
                            {(n.terms_ref || n.decision_note) && (
                                <p className="mt-1 text-[12px] text-[#6b7280]">{n.decision_note ?? n.terms_ref}</p>
                            )}
                            {n.status === 'review' && part.can_decide && (
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <Input className="min-w-56 flex-1" value={why[n.id] ?? ''} aria-label={t('سبب القرار')}
                                        placeholder={t('سبب القرار ومستنده')}
                                        onChange={(e) => setWhy((w) => ({ ...w, [n.id]: e.target.value }))} />
                                    <Button size="sm" variant="outline" onClick={() => decide(n, 'boutique')} data-testid="return-on-boutique">
                                        {t('على البوتيك')}
                                    </Button>
                                    <Button size="sm" variant="ghost" onClick={() => decide(n, 'shop')} data-testid="return-on-shop">
                                        {t('على المتجر')}
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {part.open > 0 && part.can_decide && (
                <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                    <p className="text-[12px] text-[#6b7280]">{t('للمتجر على البوتيك عن مرتجعات بعد السداد')}</p>
                    <p className="text-[20px] font-bold tabular-nums text-[#047857]" data-testid="boutique-returns-open">{m(part.open)}</p>
                    <div className="mt-3 flex flex-wrap items-end gap-3">
                        <Input className="w-32" type="number" min="0" step="0.001" dir="ltr" value={amount}
                            onChange={(e) => setAmount(e.target.value)} placeholder={String(part.open)} aria-label={t('المبلغ المستلم')} />
                        <div className="w-36">
                            <Select value={method} onChange={(e) => setMethod(e.target.value)} aria-label={t('طريقة الدفع')}
                                options={METHODS.map((x) => ({ label: x, value: x }))} />
                        </div>
                        <Input className="w-48" value={reference} onChange={(e) => setReference(e.target.value)}
                            placeholder={t('مرجع التحويل أو السند')} aria-label={t('مرجع التحويل أو السند')} />
                        <Button type="button" variant="outline" onClick={receipt} data-testid="return-receipt">
                            {t('سجّل استلامًا من البوتيك')}
                        </Button>
                    </div>
                    {part.targets.length > 0 && (
                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <div className="w-56">
                                <Select value={target} onChange={(e) => setTarget(e.target.value)} aria-label={t('الورقة')}
                                    options={part.targets.map((s) => ({ label: `${s.number} — ${m(s.payable)}`, value: String(s.id) }))} />
                            </div>
                            <Button type="button" variant="outline" onClick={offset} data-testid="return-offset">
                                {t('اخصمها من ورقة لم تُسدَّد')}
                            </Button>
                        </div>
                    )}
                </div>
            )}
            {errors?.returns && <p className="px-4 pb-3 text-[12px] text-[#b91c1c]">{String(errors.returns)}</p>}
        </Card>
    );
}
