import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarRange, X } from 'lucide-react';
import Tabs from '@/Components/Tabs';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import type { ReportRange } from '@/Components/RangeTabs';

/**
 * منتقي الفترة — الأزرارُ السريعة كما كانت، وبجوارها «اختيار فترة».
 *
 * ═══ ولمَ في الشاشة لا في قائمة التصدير ═══
 *
 * التصديرُ يحمل ما في الرابط (`withFilters`)، فمن اختار سبتمبر ٢٠٢٥ هنا يرى
 * شاشةَ سبتمبر أوّلًا ثمّ يُنزّلها. وفترةٌ تُختار في قائمة التصدير وحدها
 * تُخرج ملفًّا غيرَ الشاشة التي فوقه.
 *
 * ═══ والرابطُ وحده يحمل الفترة ═══
 *
 * الخادمُ يقرؤها (`ReportingPeriod`) ويعيدها مسمّاةً — `label` — فلا تُحسب
 * الفترةُ هنا ولا يُكتب اسمُها مرّتين. وكلُّ اختيارٍ يمسح مفاتيحَ الفترة
 * السابقة كلَّها (`PERIOD_KEYS`): من/إلى قديمتان لا تتغلّبان على شهرٍ اختير
 * بعدهما.
 *
 * ═══ ولا `input type=month` ═══
 *
 * Safari لا يرسمه منتقيًا. فالشهرُ منتقيان: شهرٌ وسنة — والسنةُ معه دائمًا،
 * فيناير ٢٠٢٥ غيرُ يناير ٢٠٢٦. والتاريخُ `type=date`، وهو يعمل في Safari.
 */

export interface PeriodCapabilities {
    presets: string[];
    previous_month: boolean;
    month: boolean;
    month_range: boolean;
    year: boolean;
    custom: boolean;
    all: boolean;
}

export interface PeriodState {
    /** `today|week|month|year|all` لزرٍّ سريع، وإلّا نوعُ `period` */
    kind: string;
    /** الزرُّ السريع المختار — أو `null` لشهرٍ بعينه أو سنةٍ أو فترة */
    range: ReportRange | null;
    label: string;
    params: Record<string, string>;
    /** أوّلُ يومٍ وآخرُه داخل الفترة — `Y-m-d` */
    from: string | null;
    to: string | null;
    capabilities: PeriodCapabilities;
    years: number[];
}

/** كلُّ مفتاحٍ في الرابط يخصّ الفترة — يُمسح عند اختيار غيرها */
export const PERIOD_KEYS = ['range', 'period', 'month', 'month_from', 'month_to', 'year', 'from', 'to'] as const;

type Params = Record<string, string | null | undefined>;

/** المرشِّحاتُ الأخرى كما هي، بلا مفاتيح الفترة ولا رقم الصفحة — ثمّ الفترةُ الجديدة */
export function withPeriod(params: Params, next: Record<string, string>): Record<string, string> {
    const kept: Record<string, string> = {};
    for (const [key, value] of Object.entries(params)) {
        if ((PERIOD_KEYS as readonly string[]).includes(key) || key === 'page') continue;
        if (value === null || value === undefined || value === '') continue;
        kept[key] = value;
    }

    return { ...kept, ...next };
}

const PRESETS: Record<string, string> = {
    today: 'اليوم',
    week: 'الأسبوع',
    month: 'الشهر',
    year: 'السنة',
    all: 'الكل',
};

export const MONTHS = [
    'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
    'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
];

type Kind = 'previous_month' | 'month' | 'month_range' | 'year' | 'custom' | 'all';

const KIND_LABELS: Record<Kind, string> = {
    previous_month: 'الشهر السابق',
    month: 'شهر محدد',
    month_range: 'نطاق أشهر',
    year: 'سنة كاملة',
    custom: 'فترة مخصصة',
    all: 'كل الفترات',
};

const pad = (n: number) => String(n).padStart(2, '0');

/** `YYYY-MM` → [سنة، شهر] */
const ym = (value: string | null | undefined, fallback: Date): [number, number] => {
    const m = /^(\d{4})-(\d{2})/.exec(value ?? '');

    return m ? [Number(m[1]), Number(m[2])] : [fallback.getFullYear(), fallback.getMonth() + 1];
};

interface Draft {
    kind: Kind;
    month: [number, number];
    fromMonth: [number, number];
    toMonth: [number, number];
    year: number;
    from: string;
    to: string;
}

/** المسودّةُ من الفترة الحاليّة — النافذةُ تُفتح على ما هو معروض */
function draftOf(period: PeriodState): Draft {
    const now = new Date();
    const kind = (['previous_month', 'month', 'month_range', 'year', 'custom', 'all'] as Kind[]).includes(period.kind as Kind)
        ? (period.kind as Kind)
        : 'month';
    const first = ym(period.params.month ?? period.params.month_from ?? period.from, now);

    return {
        kind,
        month: first,
        fromMonth: ym(period.params.month_from ?? period.from, now),
        toMonth: ym(period.params.month_to ?? period.to, now),
        year: Number(period.params.year ?? (period.from ?? '').slice(0, 4)) || now.getFullYear(),
        from: period.params.from ?? period.from ?? '',
        to: period.params.to ?? period.to ?? '',
    };
}

/** المسودّةُ رابطًا — أو رسالةُ خطأٍ تُقال قبل أن يُرسل شيء */
export function draftParams(d: Draft): { params: Record<string, string> } | { error: string } {
    const key = ([y, m]: [number, number]) => `${y}-${pad(m)}`;

    switch (d.kind) {
        case 'previous_month':
            return { params: { period: 'previous_month' } };
        case 'month':
            return { params: { period: 'month', month: key(d.month) } };
        case 'month_range':
            if (key(d.fromMonth) > key(d.toMonth)) return { error: 'شهر البداية بعد شهر النهاية.' };

            return { params: { period: 'month_range', month_from: key(d.fromMonth), month_to: key(d.toMonth) } };
        case 'year':
            return { params: { period: 'year', year: String(d.year) } };
        case 'custom':
            if (!/^\d{4}-\d{2}-\d{2}$/.test(d.from) || !/^\d{4}-\d{2}-\d{2}$/.test(d.to)) {
                return { error: 'اختر تاريخي البداية والنهاية.' };
            }
            if (d.from > d.to) return { error: 'تاريخ البداية بعد تاريخ النهاية.' };

            return { params: { period: 'custom', from: d.from, to: d.to } };
        default:
            return { params: { period: 'all' } };
    }
}

interface Props {
    period: PeriodState;
    /** المرشِّحاتُ الأخرى في الرابط — تبقى كما هي */
    params?: Params;
    /** ما يُعاد تحميله من الصفحة — كما في `RangeTabs` */
    only?: string[];
}

export default function PeriodControls({ period, params = {}, only }: Props) {
    const t = useTranslate();
    const caps = period.capabilities;
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<Draft>(() => draftOf(period));
    const [error, setError] = useState<string | null>(null);

    // نافذةٌ تُفتح على الفترة المعروضة الآن — لا على ما تُرك فيها آخرَ مرّة
    useEffect(() => {
        if (open) {
            setDraft(draftOf(period));
            setError(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const go = (next: Record<string, string>) =>
        router.get(window.location.pathname, withPeriod(params, next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
        });

    const presets = caps.presets.map((key) => ({ key, label: PRESETS[key] ?? key }));
    const kinds = (Object.keys(KIND_LABELS) as Kind[]).filter((k) => caps[k]);
    const years = period.years.length > 0 ? period.years : [new Date().getFullYear()];
    const monthOptions = MONTHS.map((m, i) => ({ value: String(i + 1), label: t(m) }));
    const yearOptions = years.map((y) => ({ value: String(y), label: String(y) }));
    const set = (patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch }));

    const apply = () => {
        const result = draftParams(draft);
        if ('error' in result) {
            setError(result.error);

            return;
        }
        setOpen(false);
        go(result.params);
    };

    /** شهرٌ وسنتُه — منتقيان متجاوران */
    const monthPicker = (label: string, value: [number, number], onChange: (v: [number, number]) => void, id: string) => (
        <fieldset className="flex flex-col gap-1" data-testid={id}>
            <legend className="mb-1 text-[12px] text-[#6b7280]">{t(label)}</legend>
            <div className="grid grid-cols-2 gap-2">
                <Select
                    aria-label={`${t(label)} — ${t('الشهر')}`}
                    value={String(value[1])}
                    onChange={(e) => onChange([value[0], Number(e.target.value)])}
                    options={monthOptions}
                />
                <Select
                    aria-label={`${t(label)} — ${t('السنة')}`}
                    value={String(value[0])}
                    onChange={(e) => onChange([Number(e.target.value), value[1]])}
                    options={yearOptions}
                />
            </div>
        </fieldset>
    );

    return (
        <div className="mb-4" data-testid="period-controls">
            <div className="flex flex-wrap items-center gap-2">
                {presets.length > 0 && (
                    // والزرُّ يُضاء لزرٍّ سريعٍ وحده — سبتمبرُ الماضي ليس «الشهر»
                    <Tabs className="min-w-0" current={period.range ?? ''} onChange={(key) => key !== period.range && go({ range: key })} tabs={presets} />
                )}
                <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)} data-testid="period-open">
                    <CalendarRange className="size-4" aria-hidden="true" />
                    {t('اختيار فترة')}
                </Button>
            </div>

            {/* الفترةُ المختارة تُقال بكلماتها — ولا يُوهَم أنّ «الشهر» هو المختار */}
            {period.range === null && (
                <div className="mt-2 flex flex-wrap items-center gap-2" data-testid="period-active">
                    <span className="rounded-full bg-[#eef2ff] px-3 py-1 text-[12.5px] font-medium text-[#3730a3]">
                        {t('الفترة')}: {period.label}
                    </span>
                    <button
                        type="button"
                        onClick={() => go({})}
                        className="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[12px] text-[#6b7280] hover:bg-[#f3f4f6] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#6366f1]"
                        data-testid="period-clear"
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t('مسح الفترة')}
                    </button>
                </div>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('اختيار فترة')}</DialogTitle>
                    </DialogHeader>
                    <div className="flex flex-col gap-4 px-5 pb-2">
                        <fieldset>
                            <legend className="mb-2 text-[12px] text-[#6b7280]">{t('نوع الفترة')}</legend>
                            <div className="grid grid-cols-2 gap-2" role="radiogroup">
                                {kinds.map((k) => (
                                    <label
                                        key={k}
                                        className="flex cursor-pointer items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-[13px] has-[:checked]:border-[#6366f1] has-[:checked]:bg-[#eef2ff]"
                                    >
                                        <input
                                            type="radio"
                                            name="period-kind"
                                            value={k}
                                            checked={draft.kind === k}
                                            onChange={() => {
                                                set({ kind: k });
                                                setError(null);
                                            }}
                                            className="size-4"
                                        />
                                        {t(KIND_LABELS[k])}
                                    </label>
                                ))}
                            </div>
                        </fieldset>

                        {draft.kind === 'month' && monthPicker('الشهر', draft.month, (v) => set({ month: v }), 'period-month')}

                        {draft.kind === 'month_range' && (
                            <div className="flex flex-col gap-3">
                                {monthPicker('من شهر', draft.fromMonth, (v) => set({ fromMonth: v }), 'period-month-from')}
                                {monthPicker('إلى شهر', draft.toMonth, (v) => set({ toMonth: v }), 'period-month-to')}
                            </div>
                        )}

                        {draft.kind === 'year' && (
                            <label className="flex flex-col gap-1" data-testid="period-year">
                                <span className="text-[12px] text-[#6b7280]">{t('السنة')}</span>
                                <Select
                                    aria-label={t('السنة')}
                                    value={String(draft.year)}
                                    onChange={(e) => set({ year: Number(e.target.value) })}
                                    options={yearOptions}
                                />
                            </label>
                        )}

                        {draft.kind === 'custom' && (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-testid="period-custom">
                                <label className="flex flex-col gap-1">
                                    <span className="text-[12px] text-[#6b7280]">{t('من تاريخ')}</span>
                                    <Input type="date" dir="ltr" value={draft.from} onChange={(e) => set({ from: e.target.value })} />
                                </label>
                                <label className="flex flex-col gap-1">
                                    <span className="text-[12px] text-[#6b7280]">{t('إلى تاريخ')}</span>
                                    <Input type="date" dir="ltr" value={draft.to} onChange={(e) => set({ to: e.target.value })} />
                                </label>
                            </div>
                        )}

                        {error && (
                            <p role="alert" className="text-[12.5px] text-[#b91c1c]" data-testid="period-error">
                                {t(error)}
                            </p>
                        )}
                    </div>
                    <DialogFooter className="px-5 pb-5">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="button" onClick={apply} data-testid="period-apply">
                            {t('تطبيق')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
