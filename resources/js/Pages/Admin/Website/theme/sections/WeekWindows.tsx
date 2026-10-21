import { Copy, Plus, Trash2 } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { DAY_NAMES, LABEL_MAX, MAX_CLOSURES, WEEK_ORDER, crossesMidnight, type Closure } from './schedule';

/**
 * محرّرُ الأسبوع — لكلّ يومٍ فتراتُه، ويومٌ بلا فترةٍ مُغلق.
 *
 * يخدم جدولين: استقبالُ الطلبات (`OrderWindow`) وفتراتُ التسليم والاستلام
 * (`DeliverySchedule`). والفرقُ بينهما معطًى لا منسوخ: فترةُ الاستقبال قد
 * تعبر منتصف الليل (`overnight`)، وفترةُ التسليم لها اسمٌ بلغتين (`labels`).
 *
 * ولا يكتب في النموذج بنفسه — يردّ الأسبوعَ كلَّه إلى من يملك المفتاح، فيبقى
 * المفتاحُ يُكتب في قسمٍ واحد.
 */
type Row = { from: string; to: string; id?: string; ar?: string; en?: string };

export default function WeekWindows<T extends Row>({
    week,
    onChange,
    make,
    max,
    labels = false,
    overnight = false,
    testid,
}: {
    week: T[][];
    onChange: (week: T[][]) => void;
    make: () => T;
    max: number;
    labels?: boolean;
    overnight?: boolean;
    testid: string;
}) {
    const t = useTranslate();

    const setDay = (d: number, rows: T[]) => onChange(week.map((day, i) => (i === d ? rows : day)));
    const edit = (d: number, i: number, patch: Partial<T>) => setDay(d, week[d].map((r, j) => (j === i ? { ...r, ...patch } : r)));

    /*
        ونسخُ يومٍ إلى أخواته ينسخ فتراتِه بمعرّفاتها: «الصباح» في كلّ يومٍ فترةٌ
        واحدة، والمعرّفُ فريدٌ في يومه لا في أسبوعه (`DeliverySchedule::normalize`).
    */
    const copyToAll = (d: number) => onChange(week.map((day, i) => (i === d ? day : week[d].map((r) => ({ ...r })))));

    return (
        <div className="divide-y divide-[#f0f0f0] rounded-[12px] border border-[#ececec]" data-testid={testid}>
            {WEEK_ORDER.map((d) => (
                <div key={d} className="flex flex-col gap-2 px-3 py-3 md:flex-row md:items-start">
                    <div className="flex w-28 shrink-0 items-center justify-between gap-2 pt-1.5 text-[13px] font-medium text-[#111]">
                        {t(DAY_NAMES[d])}
                        {week[d].length === 0 && <span className="text-[11px] font-normal text-[#9ca3af]">{t('مغلق')}</span>}
                    </div>

                    <div className="min-w-0 flex-1 space-y-2">
                        {week[d].map((r, i) => (
                            <div key={i} className="flex flex-wrap items-center gap-2" data-testid={`${testid}-${d}-${i}`}>
                                <Input
                                    type="time"
                                    dir="ltr"
                                    className="w-[7.5rem]"
                                    value={r.from}
                                    onChange={(e) => edit(d, i, { from: e.target.value } as Partial<T>)}
                                    aria-label={t('من')}
                                />
                                <span className="text-[12px] text-[#6b7280]">{t('إلى')}</span>
                                <Input
                                    type="time"
                                    dir="ltr"
                                    className="w-[7.5rem]"
                                    value={r.to}
                                    onChange={(e) => edit(d, i, { to: e.target.value } as Partial<T>)}
                                    aria-label={t('إلى')}
                                />
                                {labels && (
                                    <>
                                        <Input
                                            className="w-36"
                                            maxLength={LABEL_MAX}
                                            value={r.ar ?? ''}
                                            onChange={(e) => edit(d, i, { ar: e.target.value } as Partial<T>)}
                                            placeholder={t('اسم الفترة — اختياري')}
                                            aria-label={t('اسم الفترة — العربية')}
                                        />
                                        <Input
                                            dir="ltr"
                                            className="w-36"
                                            maxLength={LABEL_MAX}
                                            value={r.en ?? ''}
                                            onChange={(e) => edit(d, i, { en: e.target.value } as Partial<T>)}
                                            placeholder="Name — optional"
                                            aria-label="Time window name — English"
                                        />
                                    </>
                                )}
                                {overnight && crossesMidnight(r) && (
                                    <span className="text-[11px] text-[#b45309]">{t('تمتد إلى صباح اليوم التالي')}</span>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setDay(d, week[d].filter((_, j) => j !== i))}
                                    className="rounded-[8px] p-1.5 text-[#9ca3af] hover:bg-[#fef2f2] hover:text-[#b91c1c]"
                                    aria-label={t('احذف الفترة')}
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}

                        <div className="flex flex-wrap gap-3">
                            <button
                                type="button"
                                disabled={week[d].length >= max}
                                onClick={() => setDay(d, [...week[d], make()])}
                                className="inline-flex items-center gap-1 text-[12px] font-medium text-[#111] disabled:opacity-40"
                            >
                                <Plus className="size-3.5" />
                                {t('أضف فترة')}
                            </button>
                            {week[d].length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => copyToAll(d)}
                                    className="inline-flex items-center gap-1 text-[12px] text-[#6b7280] hover:text-[#111]"
                                >
                                    <Copy className="size-3.5" />
                                    {t('انسخها إلى كل الأيام')}
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * أيّامُ الإغلاق والإجازات — كلُّ إغلاقٍ يومٌ أو مدّةٌ من يومٍ إلى يوم.
 */
export function ClosureList({
    closed,
    onChange,
    testid,
}: {
    closed: Closure[];
    onChange: (closed: Closure[]) => void;
    testid: string;
}) {
    const t = useTranslate();
    const edit = (i: number, patch: Partial<Closure>) => onChange(closed.map((c, j) => (j === i ? { ...c, ...patch } : c)));

    return (
        <div className="space-y-2" data-testid={testid}>
            {closed.length === 0 && <p className="text-[12px] text-[#9ca3af]">{t('لا أيام إغلاق — أضف إجازة أو عطلة رسمية.')}</p>}
            {closed.map((c, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                    <span className="text-[12px] text-[#6b7280]">{t('من')}</span>
                    <Input type="date" dir="ltr" className="w-40" value={c.from} onChange={(e) => edit(i, { from: e.target.value })} aria-label={t('أول يوم إغلاق')} />
                    <span className="text-[12px] text-[#6b7280]">{t('إلى')}</span>
                    <Input type="date" dir="ltr" className="w-40" value={c.to} min={c.from || undefined} onChange={(e) => edit(i, { to: e.target.value })} aria-label={t('آخر يوم إغلاق')} />
                    <button
                        type="button"
                        onClick={() => onChange(closed.filter((_, j) => j !== i))}
                        className="rounded-[8px] p-1.5 text-[#9ca3af] hover:bg-[#fef2f2] hover:text-[#b91c1c]"
                        aria-label={t('احذف الإغلاق')}
                    >
                        <Trash2 className="size-4" />
                    </button>
                </div>
            ))}
            <button
                type="button"
                disabled={closed.length >= MAX_CLOSURES}
                onClick={() => onChange([...closed, { from: '', to: '' }])}
                className="inline-flex items-center gap-1 text-[12px] font-medium text-[#111] disabled:opacity-40"
            >
                <Plus className="size-3.5" />
                {t('أضف يوم إغلاق')}
            </button>
        </div>
    );
}
