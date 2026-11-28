import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowUpLeft, Building2, CircleCheck, CirclePause, Layers, LayoutDashboard, Pencil, Plus, Server } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import BackLink from '@/Components/BackLink';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Tabs from '@/Components/Tabs';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import PlanCards, { type CapabilityOption, type PlanCard } from '../Subscriptions/partials/PlanCards';
import SectorDialog, { type SectorCard } from './partials/SectorDialog';
import type { Currency, PageProps } from '@/types';

type State = 'subscribed' | 'trial' | 'awaiting' | 'expired' | 'disabled';

interface BusinessRow {
    id: number;
    name: string;
    type: string;
    plan: string | null;
    state: State;
    expires: string | null;
    daysLeft: number | null;
}

interface Props {
    sector: SectorCard;
    icons: string[];
    tab: 'plans' | 'businesses' | 'system';
    plans: PlanCard[];
    capabilityOptions: CapabilityOption[];
    businesses: BusinessRow[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    planNames: string[];
    system: { code: string; name: string; name_en: string; running: boolean; live: number };
    currency: Currency;
}

/** حالُ الاشتراك كما تُحسب في الخادم (`SectorController::state`) — بلونها */
export const STATES: Record<State, { label: string; cls: string }> = {
    subscribed: { label: 'مشترك', cls: 'bg-[#ecfdf5] text-[#047857]' },
    trial: { label: 'في التجربة', cls: 'bg-[#fffbeb] text-[#b45309]' },
    awaiting: { label: 'بانتظار السداد', cls: 'bg-[#eff6ff] text-[#1d4ed8]' },
    expired: { label: 'منتهي', cls: 'bg-[#fef2f2] text-[#b91c1c]' },
    disabled: { label: 'معطل', cls: 'bg-[#f3f4f6] text-[#6b7280]' },
};

/**
 * صفحةُ القطاع — باقاتُه، وشركاتُه واشتراكاتُها، والنظامُ الذي يعمل عليه.
 *
 * لا تكرّر شيئًا: الباقةُ تُحفظ ببابها، والشركةُ تُفتح بصفحتها، والاشتراكُ
 * يُقرأ ويُجدَّد من صفحة الشركة، و«إضافة شركة» نموذجُ الإنشاء نفسُه بالقطاع محدَّدًا.
 */
export default function SectorShow() {
    const { sector, icons, tab: initial, plans, capabilityOptions, businesses, pagination, filters, planNames, system, currency } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState<string>(initial);
    const [editing, setEditing] = useState(false);
    const [creatingPlan, setCreatingPlan] = useState(false);

    const columns: Column<BusinessRow>[] = [
        {
            key: 'name',
            header: 'الشركة',
            cell: (b) => (
                <SmartLink
                    routeName="super-admin.businesses.show"
                    href={route('super-admin.businesses.show', b.id)}
                    className="font-medium hover:underline"
                >
                    {b.name}
                </SmartLink>
            ),
        },
        { key: 'type', header: 'نوع النشاط', cell: (b) => t(b.type) },
        { key: 'plan', header: 'الباقة', cell: (b) => (b.plan ? t(b.plan) : '—') },
        {
            key: 'state',
            header: 'حالة الاشتراك',
            cell: (b) => (
                <span className={cn('rounded-full px-2.5 py-0.5 text-[12px] font-medium', STATES[b.state].cls)} data-testid="sector-business-state">
                    {t(STATES[b.state].label)}
                </span>
            ),
        },
        {
            key: 'expires',
            header: 'تاريخ الانتهاء',
            cell: (b) => (
                <span dir="ltr" className="tabular-nums text-[#6b7280]">
                    {b.expires ?? '—'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (b) => (
                <div className="flex justify-end gap-1.5">
                    <Button variant="ghost" size="sm" asChild>
                        <SmartLink routeName="super-admin.businesses.show" href={route('super-admin.businesses.show', b.id)}>
                            {t('صفحة الشركة')}
                        </SmartLink>
                    </Button>
                    {/* الاشتراكُ الحاليّ وتجديدُه وترقيتُه في صفحة الشركة — يُفتح على قسمه */}
                    <Button variant="outline" size="sm" asChild>
                        <SmartLink
                            routeName="super-admin.businesses.show"
                            href={`${route('super-admin.businesses.show', b.id)}#subscription`}
                            data-testid="sector-business-subscription"
                        >
                            {t('الاشتراك')}
                            <ArrowUpLeft />
                        </SmartLink>
                    </Button>
                </div>
            ),
        },
    ];

    const tableFilters: Filter<BusinessRow>[] = [
        {
            label: 'حالة الاشتراك',
            param: 'state',
            options: (Object.keys(STATES) as State[]).map((k) => ({ label: STATES[k].label, value: k })),
        },
        { label: 'الباقة', param: 'plan', options: planNames.map((p) => ({ label: p, value: p })) },
    ];

    return (
        <PlatformLayout title={sector.name}>
            <BackLink routeName="super-admin.sectors.index" href={route('super-admin.sectors.index')} label="القطاعات" />

            <PageHeader
                title={sector.name}
                subtitle={[sector.name_en, `${t(sector.system)} · ${sector.code}`].filter(Boolean).join(' — ')}
                actions={
                    <>
                        <Button variant="outline" onClick={() => setEditing(true)} data-testid="sector-edit">
                            <Pencil />
                            {t('تعديل القطاع')}
                        </Button>
                        {/* الإضافةُ لقطاعٍ نشطٍ وحده — والخادمُ يردّ غيرَه */}
                        {sector.active && (
                            <Button asChild>
                                <SmartLink
                                    routeName="super-admin.businesses.create"
                                    href={route('super-admin.businesses.create', { sector: sector.id })}
                                    data-testid="sector-add-business"
                                >
                                    <Plus />
                                    {t('إضافة شركة')}
                                </SmartLink>
                            </Button>
                        )}
                    </>
                }
            />

            <Tabs
                className="mb-5"
                current={tab}
                onChange={setTab}
                tabs={[
                    { key: 'plans', label: 'الباقات', icon: Layers, count: plans.length },
                    { key: 'businesses', label: 'الشركات والاشتراكات', icon: Building2, count: pagination.total },
                    { key: 'system', label: 'النظام', icon: Server },
                ]}
            />

            {tab === 'plans' && (
                <div className="space-y-4" data-testid="sector-plans">
                    <div className="flex justify-end">
                        <Button onClick={() => setCreatingPlan(true)} data-testid="sector-new-plan">
                            <Plus />
                            {t('باقة جديدة')}
                        </Button>
                    </div>
                    <PlanCards
                        plans={plans}
                        capabilityOptions={capabilityOptions}
                        currency={currency}
                        sectorId={sector.id}
                        creating={creatingPlan}
                        onCreatingChange={setCreatingPlan}
                    />
                </div>
            )}

            {tab === 'businesses' && (
                <div data-testid="sector-businesses">
                    <DataTable
                        rows={businesses}
                        columns={columns}
                        rowKey={(b) => b.id}
                        filters={tableFilters}
                        searchPlaceholder="ابحث باسم الشركة أو المالك أو البريد…"
                        // البحثُ في الخادم (`q`) — والمربّعُ لا يظهر بلا هذا
                        searchable={() => ''}
                        empty={
                            <span className="flex flex-col items-center gap-2">
                                <Building2 className="size-8 text-[#d1d5db]" />
                                {t('لا توجد شركات في هذا القطاع بعد')}
                            </span>
                        }
                        server={{ pagination, params: { ...filters, tab: 'businesses' } }}
                    />
                </div>
            )}

            {tab === 'system' && (
                <Card className="p-6" data-testid="sector-system">
                    <div className="flex items-center gap-3">
                        <span className="flex size-11 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#111]">
                            <LayoutDashboard className="size-5" />
                        </span>
                        <div>
                            <p className="font-bold text-[#111]">{t(system.name)}</p>
                            <p dir="ltr" className="text-[12px] text-[#9ca3af]">
                                {system.name_en}
                            </p>
                        </div>
                        <span
                            className={cn(
                                'ms-auto inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-medium',
                                system.running ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#f3f4f6] text-[#6b7280]',
                            )}
                            data-testid="sector-system-status"
                        >
                            {system.running ? <CircleCheck className="size-3.5" /> : <CirclePause className="size-3.5" />}
                            {t(system.running ? 'يعمل' : 'موقوف عن الإضافة الجديدة')}
                        </span>
                    </div>
                    <dl className="mt-5 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div className="rounded-[12px] bg-[#fafafa] px-4 py-3">
                            <dt className="text-[12px] text-[#9ca3af]">{t('شركات تعمل عليه الآن')}</dt>
                            <dd className="mt-1 text-[18px] font-bold tabular-nums text-[#111]">{number(system.live)}</dd>
                        </div>
                        <div className="rounded-[12px] bg-[#fafafa] px-4 py-3">
                            <dt className="text-[12px] text-[#9ca3af]">{t('لوحة التحكم')}</dt>
                            <dd className="mt-1 text-[13px] text-[#374151]">{t('لوحة أبعاد الحالية للتاجر — لا لوحة ثانية')}</dd>
                        </div>
                    </dl>
                    <p className="mt-4 text-[12px] text-[#9ca3af]">
                        {t('هذا القسم للمعلومات — يرتبط القطاع بنظام أبعاد الحالي بالكامل.')}
                    </p>
                </Card>
            )}

            {editing && <SectorDialog sector={sector} icons={icons} onClose={() => setEditing(false)} />}
        </PlatformLayout>
    );
}
