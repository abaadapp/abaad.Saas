import { usePage } from '@inertiajs/react';
import { ArrowRight, Banknote, Landmark } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Props {
    range: ReportRange;
    /** رصيد الصندوق كما يقوله الدفتر */
    cash: number;
    /** مجموع أرصدة الحسابات البنكية المفعّلة */
    bank: number;
    accounts: { id: number; label: string; balance: number; active: boolean }[];
    period: {
        sales: number;
        /** تكلفة البضاعة المباعة — من `Demo::reportSummary` كما حسبها */
        cogs: number;
        /** المبيعات − ضريبة المبيعات − تكلفة البضاعة المباعة */
        gross_profit: number;
        /** المصروفات التشغيلية المدفوعة — لا سندات شراء المخزون */
        expenses: number;
        /** مجمل الربح − المصروفات التشغيلية */
        profit: number;
        tax: number;
        in: number;
        out: number;
        transfers: number;
    };
    /**
     * التحصيلُ والسدادُ في المدة — مالٌ قُبض من العملاء أو دُفع للموردين فعلًا.
     * للعلم وحده: لا يدخل `period` ولا يمسّ ربحًا ولا ذمّة (`Settlements`).
     */
    settlements: {
        collections: { amount: number; count: number };
        supplier_payments: { amount: number; count: number };
    };
    dues: {
        expenses: number;
        invoices: number;
        payroll: number;
        total: number;
        overdue: number;
        /** المتأخّر والمستحقّ خلال ٧ أيام — من المصروفات وسندات الموردين وحدها؛ الرواتب بلا تاريخ استحقاق */
        overdue_count: number;
        overdue_amount: number;
        due_soon_amount: number;
    };
    /** سندات موردين بانتظار الاعتماد — معلومةٌ بجوار الدَّين لا داخله */
    pending_invoices: { count: number; total: number };
    /** «ما لك» — ذمم العملاء، من `Receivables` نفسها التي تقرأ منها شاشة الذمم */
    receivables: {
        /** إجمالي الذمم قبل الرصيد الدائن: الفواتير المفتوحة + البيعات الآجلة غير المفوترة */
        total: number;
        uninvoiced: number;
        /** total − uninvoiced */
        invoiced: number;
        /** total − credit */
        net: number;
        overdue: number;
        due_soon: number;
        credit: number;
        invoices: number;
        customers: number;
    };
}

/**
 * الملخّص المالي — «كم عندي وكم ربحت وماذا عليّ؟» في شاشةٍ واحدة.
 *
 * وكان جوابُ الأسئلة الثلاثة مفرّقًا: الرصيد في الحسابات البنكية، والربح في
 * ملخّص المبيعات، والمستحقّ في ثلاثة جداول لا يجمعها شيء. فمن أراد أن يعرف
 * هل يستطيع الدفع اليوم كان عليه أن يفتح خمس شاشات ويجمع بالعين.
 */
/**
 * عددُ العمليات بصيغته العربيّة — مفتاحٌ لكلّ صيغة، ولكلٍّ ترجمتُه.
 *
 * والعربيّةُ لا تقول «11 عمليات»: الواحدُ والاثنان صيغتان، ومن ثلاثةٍ إلى
 * عشرةٍ جمع، وما فوقها مفردٌ منصوب. ومفتاحٌ واحدٌ لكلّ عددٍ كان سيكتب
 * أحدَها خطأً — وتُترجَم كلٌّ إلى المفرد أو الجمع الإنجليزيّ.
 */
export function countKey(n: number, forms: { one: string; two: string; few: string; many: string }): string {
    if (n === 1) return forms.one;
    if (n === 2) return forms.two;

    const r = n % 100;

    return n === 0 || (r >= 3 && r <= 10) ? forms.few : forms.many;
}

const COLLECTIONS = { one: 'عملية تحصيل واحدة', two: 'عمليتا تحصيل', few: ':n عمليات تحصيل', many: ':n عمليةَ تحصيل' };
const PAYMENTS = { one: 'عملية سداد واحدة', two: 'عمليتا سداد', few: ':n عمليات سداد', many: ':n عمليةَ سداد' };

/** سطرٌ من تفصيل بطاقة: اسمٌ ومبلغ */
function Line({ label, value, tone }: { label: string; value: string; tone?: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-[12px] text-[#6b7280]">{label}</dt>
            <dd className={cn('text-[13px] font-semibold tabular-nums', tone ?? 'text-[#111]')}>{value}</dd>
        </div>
    );
}

export default function Summary() {
    const { range, cash, bank, accounts, period, settlements, dues, pending_invoices, receivables, context, auth } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    /*
     * وتفصيلُ المدة في تقرير «صافي الربح» — بالفترة نفسِها.
     *
     * والتقريرُ تحت قسم «التقارير» لا «المالية» (`Reports::ALL`): من يملك
     * الملخّصَ وحده كان سيرى زرًّا يقوده إلى 403. فلا يُعرض له.
     * ولا `branch_id`: الملخّصُ للنشاط كلِّه (`Demo::reportSummary` بلا فرع)،
     * والتقريرُ بلا فرعٍ للنشاط كلِّه كذلك.
     */
    const canOpenDetails = (auth?.abilities ?? []).includes('reports');

    return (
        <AdminLayout title="الملخّص المالي">
            <PageHeader
                title="الملخّص المالي"
                subtitle={t('أين المال الآن، وماذا جرى في المدة، وماذا على المتجر')}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />

            {/* أين المال الآن — حالةٌ لا حصيلةُ فترة، فلا يمسّها المبدّل تحتها */}
            {/* أربعُ بطاقات: صفّان متوازنان على الشاشة المتوسّطة، وصفٌّ واحد على العريضة */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card className="p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-[12px] text-[#9ca3af]">{t('الصندوق (نقدًا)')}</p>
                            <p
                                className={cn(
                                    'mt-1 text-[22px] font-bold tabular-nums tracking-tight',
                                    cash < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                )}
                            >
                                {m(cash)}
                            </p>
                        </div>
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-[12px] bg-[#ecfdf5] text-[#047857]">
                            <Banknote className="size-5" />
                        </span>
                    </div>
                </Card>

                <Card className="p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-[12px] text-[#9ca3af]">{t('البنك')}</p>
                            <p
                                className={cn(
                                    'mt-1 text-[22px] font-bold tabular-nums tracking-tight',
                                    bank < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                )}
                            >
                                {m(bank)}
                            </p>
                            {accounts.length > 1 && (
                                <p className="mt-1 truncate text-[12px] text-[#9ca3af]">
                                    {accounts.length} {t('حسابات')}
                                </p>
                            )}
                        </div>
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-[12px] bg-[#eff6ff] text-[#2563eb]">
                            <Landmark className="size-5" />
                        </span>
                    </div>
                </Card>

                <Card className="p-5">
                    <div className="flex h-full flex-col justify-between gap-3">
                        <div>
                            <p className="text-[12px] text-[#9ca3af]">{t('عليك الآن')}</p>
                            <p
                                className={cn(
                                    'mt-1 text-[22px] font-bold tabular-nums tracking-tight',
                                    dues.total > 0 ? 'text-[#b45309]' : 'text-[#111]',
                                )}
                            >
                                {m(dues.total)}
                            </p>
                            {/* والإجماليُّ مجموعُ هذه الثلاثة لا غير — انظر `dueTotals` */}
                            <dl className="mt-3 space-y-1.5" data-testid="dues-breakdown">
                                <Line label={t('فواتير الموردين')} value={m(dues.invoices)} />
                                <Line label={t('مصروفات غير مدفوعة')} value={m(dues.expenses)} />
                                <Line label={t('رواتب مستحقة')} value={m(dues.payroll)} />
                            </dl>
                            {/* ومتى: المتأخّرُ وما يستحقّ خلال أسبوع — مبلغًا لا عددًا فقط */}
                            <dl
                                className="mt-3 space-y-1.5 border-t border-[var(--ui-border,#e8e8e8)] pt-3"
                                data-testid="dues-timing"
                            >
                                <Line
                                    label={dues.overdue_count > 0 ? `${t('المتأخر')} (${dues.overdue_count})` : t('المتأخر')}
                                    value={m(dues.overdue_amount)}
                                    tone={dues.overdue_amount > 0 ? 'text-[#b91c1c]' : undefined}
                                />
                                <Line label={t('يستحق خلال 7 أيام')} value={m(dues.due_soon_amount)} />
                            </dl>
                            {/* لم تصر دَينًا بعد — تُذكر ولا تُجمع */}
                            {pending_invoices.count > 0 && (
                                <p className="mt-2 text-[12px] text-[#6b7280]" data-testid="pending-invoices">
                                    {t('فواتير بانتظار الاعتماد')}: {pending_invoices.count} · {m(pending_invoices.total)}
                                    <span className="block text-[11px] text-[#9ca3af]">{t('لا تدخل في المستحق حتى تُعتمد')}</span>
                                </p>
                            )}
                        </div>
                        <Button variant="outline" size="sm" className="self-start" asChild>
                            <SmartLink routeName="admin.finance.dues" href={route('admin.finance.dues')}>
                                {t('التفاصيل')}
                            </SmartLink>
                        </Button>
                    </div>
                </Card>

                {/*
                    و«لك الآن» بجوارها.

                    الملخّصُ كان يجيب عن نصف السؤال: كم عليّ. ومن يقرّر أيدفع
                    اليوم أم ينتظر يحتاج النصف الآخر — كم لي وكم منه تأخّر.
                */}
                <Card className="p-5">
                    <div className="flex h-full flex-col justify-between gap-3">
                        <div>
                            <p className="text-[12px] text-[#9ca3af]">{t('لك الآن')}</p>
                            <p className="mt-1 text-[22px] font-bold tabular-nums tracking-tight text-[#111]">
                                {m(receivables.total)}
                            </p>
                            {/* الرقمُ إجماليُّ الذمم قبل الرصيد الدائن — يُقال معناه تحته، ولا يُسمّى «صافيًا» */}
                            <p className="text-[11px] text-[#9ca3af]">{t('إجمالي ذمم العملاء')}</p>
                            {receivables.invoices > 0 && (
                                <p className="mt-1 text-[12px] text-[#6b7280]" data-testid="receivables-count">
                                    {t(':invoices فواتير على :customers عملاء', {
                                        invoices: receivables.invoices,
                                        customers: receivables.customers,
                                    })}
                                </p>
                            )}
                            {/* والإجماليُّ مجموعُ السطرين الأوّلين — انظر `receivableCard` */}
                            <dl className="mt-3 space-y-1.5" data-testid="receivables-breakdown">
                                <Line label={t('فواتير العملاء')} value={m(receivables.invoiced)} />
                                <Line label={t('مبيعات آجلة لم تُفوتر')} value={m(receivables.uninvoiced)} />
                            </dl>
                            <dl
                                className="mt-3 space-y-1.5 border-t border-[var(--ui-border,#e8e8e8)] pt-3"
                                data-testid="receivables-timing"
                            >
                                <Line
                                    label={t('المتأخر')}
                                    value={m(receivables.overdue)}
                                    tone={receivables.overdue > 0 ? 'text-[#b91c1c]' : undefined}
                                />
                                <Line label={t('يستحق قريبًا')} value={m(receivables.due_soon)} />
                            </dl>
                            {/* والرصيدُ الدائن يُطرح في سطرٍ مسمّى — لا داخل الإجماليّ صامتًا */}
                            {receivables.credit > 0 && (
                                <dl
                                    className="mt-3 space-y-1.5 border-t border-[var(--ui-border,#e8e8e8)] pt-3"
                                    data-testid="receivables-credit"
                                >
                                    <Line label={t('رصيد دائن للعملاء')} value={`− ${m(receivables.credit)}`} />
                                    <Line label={t('صافي لك')} value={m(receivables.net)} />
                                </dl>
                            )}
                        </div>
                        <Button variant="outline" size="sm" className="self-start" asChild>
                            <SmartLink routeName="admin.finance.receivables" href={route('admin.finance.receivables')}>
                                {t('التفاصيل')}
                            </SmartLink>
                        </Button>
                    </div>
                </Card>
            </div>

            {/* ما جرى في المدة — والمبدّل فوقه وحده كي لا يُقرأ رقمُ فترةٍ على أنه رقمُ أخرى */}
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-[15px] font-bold text-[#111]">{t('ما جرى في المدة')}</h2>
                {canOpenDetails && (
                    <Button variant="outline" size="sm" asChild>
                        <SmartLink
                            routeName="admin.reports.profit"
                            href={route('admin.reports.profit', { range })}
                            data-testid="period-details"
                        >
                            {t('عرض التفاصيل')}
                            <ArrowRight className="size-4 rtl:rotate-180" aria-hidden="true" />
                        </SmartLink>
                    </Button>
                )}
            </div>
            <RangeTabs current={range} />

            {/*
                نتيجةُ المدة خطوةً خطوة بترتيب الطرح — ستُّ بطاقات: صفّان من ثلاث.

                كانت تكلفةُ البضاعة تُطرح داخل «صافي الربح» ولا تُرى، فيقرأ
                التاجرُ مبيعاتٍ ومصروفاتٍ وربحًا لا يساوي فرقَهما. وسنداتُ شراء
                المخزون لا تُضاف إلى «المصروفات التشغيلية»: كلفتُها تصل الربحَ
                عبر تكلفة البضاعة حين تُباع — ولو أُضيفت لَحُسبت مرّتين.
            */}
            <div className="mb-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="period-result">
                <StatCard stat={{ label: t('المبيعات'), value: m(period.sales), icon: 'shopping-cart', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('ضريبة المبيعات'), value: m(period.tax), icon: 'receipt', color: 'warning' }} index={1} />
                <StatCard stat={{ label: t('تكلفة البضاعة المباعة'), value: m(period.cogs), icon: 'boxes', color: 'info' }} index={2} />
                <StatCard stat={{ label: t('مجمل الربح'), value: m(period.gross_profit), icon: 'coins', color: 'secondary' }} index={3} />
                <StatCard stat={{ label: t('المصروفات التشغيلية'), value: m(period.expenses), icon: 'arrow-down-circle', color: 'danger' }} index={4} />
                <StatCard stat={{ label: t('صافي الربح'), value: m(period.profit), icon: 'trending-up', color: 'success' }} index={5} />
            </div>
            <p className="mb-6 text-[12px] leading-relaxed text-[#9ca3af]">
                {t('مجمل الربح = المبيعات − ضريبة المبيعات − تكلفة البضاعة المباعة · صافي الربح = مجمل الربح − المصروفات التشغيلية')}
            </p>

            <Card className="p-5">
                <h3 className="mb-4 text-[14px] font-bold text-[#111]">{t('حركة المال في المدة')}</h3>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <dt className="text-[12px] text-[#9ca3af]">{t('دخل')}</dt>
                        <dd className="mt-0.5 text-[18px] font-semibold tabular-nums text-[#15803d]">{m(period.in)}</dd>
                    </div>
                    <div>
                        <dt className="text-[12px] text-[#9ca3af]">{t('خرج')}</dt>
                        <dd className="mt-0.5 text-[18px] font-semibold tabular-nums text-[#b91c1c]">{m(period.out)}</dd>
                    </div>
                    <div>
                        <dt className="text-[12px] text-[#9ca3af]">{t('تحويلات بين الصندوق والبنك')}</dt>
                        <dd className="mt-0.5 text-[18px] font-semibold tabular-nums text-[#6b7280]">
                            {m(period.transfers)}
                        </dd>
                    </div>
                </dl>
                {/*
                 * التحويل يُعرض ولا يُجمع: مالٌ انتقل من جيبٍ إلى جيب. وحذفُه
                 * من الشاشة كان يجعل التاجر يبحث عن مبلغٍ رآه في الحركة ولا
                 * يجده في أيّ مجموع.
                 */}
                <p className="mt-4 text-[12px] text-[#9ca3af]">
                    {t('التحويل بين الصندوق والبنك لا يُقرأ دخلًا ولا مصروفًا — المال انتقل ولم يدخل ولم يخرج.')}
                </p>
            </Card>

            {/*
                التحصيلُ والسدادُ في المدة — بالفترة نفسِها، ومفصولٌ عمّا فوقه.

                تسويةُ ذمّةٍ لا ربحٌ جديد: التحصيلُ ثمنُ بيعٍ عُدّ في «المبيعات» يومَ
                البيع، والسدادُ ثمنُ سندٍ دخل المخزونَ يومَ اعتُمد. فيُعرضان للعلم
                ولا يُضافان إلى شيءٍ فوقهما.
            */}
            <Card className="mt-4 p-5" data-testid="period-settlements">
                <h3 className="mb-4 text-[14px] font-bold text-[#111]">{t('التحصيل والسداد في المدة')}</h3>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div data-testid="settlement-collections">
                        <dt className="text-[12px] text-[#9ca3af]">{t('تحصيلات العملاء')}</dt>
                        <dd className="mt-0.5 text-[18px] font-semibold tabular-nums text-[#15803d]">
                            {m(settlements.collections.amount)}
                        </dd>
                        <dd className="mt-0.5 text-[12px] text-[#6b7280]">
                            {t(countKey(settlements.collections.count, COLLECTIONS), {
                                n: number(settlements.collections.count),
                            })}
                        </dd>
                    </div>
                    <div data-testid="settlement-supplier-payments">
                        <dt className="text-[12px] text-[#9ca3af]">{t('مدفوعات الموردين')}</dt>
                        <dd className="mt-0.5 text-[18px] font-semibold tabular-nums text-[#b91c1c]">
                            {m(settlements.supplier_payments.amount)}
                        </dd>
                        <dd className="mt-0.5 text-[12px] text-[#6b7280]">
                            {t(countKey(settlements.supplier_payments.count, PAYMENTS), {
                                n: number(settlements.supplier_payments.count),
                            })}
                        </dd>
                    </div>
                </dl>
                <p className="mt-4 text-[12px] text-[#9ca3af]">
                    {t('مبالغ تم تحصيلها من العملاء أو سدادها للموردين فعليًا خلال المدة. لا تدخل هذه الأرقام مرة أخرى في احتساب صافي الربح.')}
                </p>
            </Card>
        </AdminLayout>
    );
}
