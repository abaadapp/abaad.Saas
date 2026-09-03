import { usePage } from '@inertiajs/react';
import { Banknote, Landmark } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
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
        expenses: number;
        profit: number;
        tax: number;
        in: number;
        out: number;
        transfers: number;
    };
    dues: { expenses: number; invoices: number; payroll: number; total: number; overdue: number };
}

/**
 * الملخّص المالي — «كم عندي وكم ربحت وماذا عليّ؟» في شاشةٍ واحدة.
 *
 * وكان جوابُ الأسئلة الثلاثة مفرّقًا: الرصيد في الحسابات البنكية، والربح في
 * ملخّص المبيعات، والمستحقّ في ثلاثة جداول لا يجمعها شيء. فمن أراد أن يعرف
 * هل يستطيع الدفع اليوم كان عليه أن يفتح خمس شاشات ويجمع بالعين.
 */
export default function Summary() {
    const { range, cash, bank, accounts, period, dues, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    return (
        <AdminLayout title="الملخّص المالي">
            <PageHeader
                title="الملخّص المالي"
                subtitle={t('أين المال الآن، وماذا جرى في المدة، وماذا على المتجر')}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.summary" />

            {/* أين المال الآن — حالةٌ لا حصيلةُ فترة، فلا يمسّها المبدّل تحتها */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                            {dues.overdue > 0 && (
                                <p className="mt-1 text-[12px] text-[#b91c1c]">
                                    {dues.overdue} {t('متأخّرة عن موعدها')}
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
            </div>

            {/* ما جرى في المدة — والمبدّل فوقه وحده كي لا يُقرأ رقمُ فترةٍ على أنه رقمُ أخرى */}
            <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('ما جرى في المدة')}</h2>
            <RangeTabs current={range} />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard stat={{ label: t('المبيعات'), value: m(period.sales), icon: 'shopping-cart', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('المصروفات'), value: m(period.expenses), icon: 'arrow-down-circle', color: 'danger' }} index={1} />
                <StatCard stat={{ label: t('صافي الربح'), value: m(period.profit), icon: 'trending-up', color: 'success' }} index={2} />
                <StatCard stat={{ label: t('ضريبة محصّلة'), value: m(period.tax), icon: 'receipt', color: 'warning' }} index={3} />
            </div>

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
        </AdminLayout>
    );
}
