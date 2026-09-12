import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Contact,
    GitBranch,
    Inbox,
    TrendingUp,
    UserX,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Metrics {
    total: number;
    active: number;
    new: number;
    interested: number;
    qualified: number;
    trial: number;
    quotation: number;
    won: number;
    lost: number;
    /** نسبةُ المحسوم لا نسبةُ الدفتر — وفارغةٌ حين لا شيء حُسم بعد */
    conversionRate: number | null;
    overdueFollowUps: number;
    overdueTasks: number;
    unassigned: number;
}

interface Props {
    metrics: Metrics;
    mine: { leads: number; tasks: number; overdue: number };
}

export default function CrmDashboard() {
    const { metrics, mine } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * البطاقاتُ أرقامٌ محسوبةٌ من الدفتر — ولا سهمَ صعودٍ ولا «+12%».
     *
     * اتّجاهٌ مرسومٌ بلا مقارنةٍ حقيقيّة يُقرأ على أنّه قياس، ومن يقرأ «+12%»
     * يبني عليه قرارًا. وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب.
     */
    const cards = [
        { label: 'عملاء محتملون نشطون', value: metrics.active, icon: Contact, tone: 'text-[#111]' },
        { label: 'جديد', value: metrics.new, icon: Inbox, tone: 'text-[#111]' },
        { label: 'مهتم', value: metrics.interested, icon: TrendingUp, tone: 'text-[#111]' },
        { label: 'مؤهل', value: metrics.qualified, icon: CheckCircle2, tone: 'text-[#111]' },
        { label: 'تجربة', value: metrics.trial, icon: GitBranch, tone: 'text-[#111]' },
        { label: 'عرض سعر', value: metrics.quotation, icon: ClipboardList, tone: 'text-[#111]' },
        { label: 'تم الاشتراك', value: metrics.won, icon: CheckCircle2, tone: 'text-[#16a34a]' },
        { label: 'مفقود', value: metrics.lost, icon: UserX, tone: 'text-[#b91c1c]' },
    ];

    const alerts = [
        { label: 'متابعات متأخرة', routeName: 'super-admin.crm.leads.index', value: metrics.overdueFollowUps, href: route('super-admin.crm.leads.index', { assignment: 'overdue' }) },
        { label: 'مهام متأخرة', routeName: 'super-admin.crm.tasks', value: metrics.overdueTasks, href: route('super-admin.crm.tasks', { scope: 'overdue' }) },
        { label: 'غير معيّن', routeName: 'super-admin.crm.leads.index', value: metrics.unassigned, href: route('super-admin.crm.leads.index', { assignment: 'unassigned' }) },
    ].filter((a) => a.value > 0);

    return (
        <PlatformLayout title={t('لوحة CRM')}>
            <PageHeader
                title="لوحة CRM"
                subtitle={t('مبيعات أبعاد — العملاء المحتملون ومسارهم')}
                actions={
                    <>
                        <SmartLink routeName="super-admin.crm.pipeline" href={route('super-admin.crm.pipeline')}>
                            <Button variant="outline">{t('مسار البيع')}</Button>
                        </SmartLink>
                        <SmartLink routeName="super-admin.crm.leads.index" href={route('super-admin.crm.leads.index')}>
                            <Button>{t('العملاء المحتملون')}</Button>
                        </SmartLink>
                    </>
                }
            />

            {/* ما يحتاج نظرًا اليوم — ولا يُعرض حين لا شيء منه */}
            {alerts.length > 0 && (
                <div className="mb-5 grid gap-3 sm:grid-cols-3">
                    {alerts.map((a) => (
                        <SmartLink
                            key={a.label}
                            routeName={a.routeName}
                            href={a.href}
                            className="flex items-center gap-3 rounded-xl border border-[#fde68a] bg-[#fffbeb] p-4 transition hover:bg-[#fef3c7]"
                        >
                            <AlertTriangle className="size-5 shrink-0 text-[#b45309]" />
                            <div className="min-w-0">
                                <div className="text-[20px] font-bold text-[#92400e]">{a.value}</div>
                                <div className="truncate text-[12px] text-[#92400e]">{t(a.label)}</div>
                            </div>
                        </SmartLink>
                    ))}
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {cards.map((c) => (
                    <div key={c.label} className="rounded-xl border border-[#e8e8e8] bg-white p-4">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[12px] text-[#6b7280]">{t(c.label)}</span>
                            <c.icon className="size-4 text-[#9ca3af]" />
                        </div>
                        <div className={`text-[26px] font-bold ${c.tone}`}>{c.value}</div>
                    </div>
                ))}
            </div>

            <div className="mt-5 grid gap-4 lg:grid-cols-2">
                <div className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                    <h2 className="mb-1 font-bold text-[#111]">{t('نسبة التحويل')}</h2>
                    {/*
                        ولا رقمَ يُعرض قبل أن يُحسم شيء.
                        «0%» عن دفترٍ لم يُغلق فيه صفٌّ بعد تقريرُ حالٍ كاذب:
                        يقرؤه صاحبه أنّنا نخسر الجميع.
                    */}
                    {metrics.conversionRate === null ? (
                        <p className="text-[13px] text-[#6b7280]">
                            {t('لم يُحسم عميلٌ محتمَل بعد — لا اشتراكَ ولا خسارة. فلا نسبةَ تُقاس.')}
                        </p>
                    ) : (
                        <>
                            <div className="text-[32px] font-bold text-[#111]">{metrics.conversionRate}%</div>
                            <p className="mt-1 text-[13px] text-[#6b7280]">
                                {t(':won مشتركًا من :closed محسومًا', {
                                    won: String(metrics.won),
                                    closed: String(metrics.won + metrics.lost),
                                })}
                            </p>
                        </>
                    )}
                </div>

                <div className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                    <h2 className="mb-3 font-bold text-[#111]">{t('عملي')}</h2>
                    <dl className="space-y-2 text-[13px]">
                        <div className="flex items-center justify-between">
                            <dt className="text-[#6b7280]">{t('عملاء محتملون مسندون إليّ')}</dt>
                            <dd className="font-bold text-[#111]">{mine.leads}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                            <dt className="text-[#6b7280]">{t('مهام مفتوحة')}</dt>
                            <dd className="font-bold text-[#111]">{mine.tasks}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                            <dt className="text-[#6b7280]">{t('منها متأخرة')}</dt>
                            <dd className={`font-bold ${mine.overdue > 0 ? 'text-[#b91c1c]' : 'text-[#111]'}`}>
                                {mine.overdue}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            {metrics.total === 0 && (
                <p className="mt-5 rounded-xl border border-[#e8e8e8] bg-[#fafafa] p-5 text-[13px] text-[#6b7280]">
                    {t('الدفتر فارغ. أضف أوّل عميلٍ محتمَل من شاشة «العملاء المحتملون».')}
                </p>
            )}
        </PlatformLayout>
    );
}
