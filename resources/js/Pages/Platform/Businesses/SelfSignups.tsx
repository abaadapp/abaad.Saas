import { usePage } from '@inertiajs/react';
import { ArrowRight, UserPlus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { selfSignupStatCards, SignupState, type SelfSignupRow, type SelfSignupStats } from './partials/SelfSignupsSection';

interface Props {
    rows: SelfSignupRow[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    stats: SelfSignupStats;
}

/** سطرٌ ثانٍ خافتٌ تحت القيمة — أو لا شيء */
function Sub({ children, ltr }: { children: string | null | undefined; ltr?: boolean }) {
    return children ? (
        <span dir={ltr ? 'ltr' : undefined} className="block truncate text-[12px] text-[#6b7280]">
            {children}
        </span>
    ) : null;
}

/**
 * التسجيلاتُ الذاتيّة كلُّها — «عرض الكل» من قسمها في صفحة الشركات.
 *
 * صفحاتٌ يقصّها الخادم (`SelfSignupsList::query`) لا صفوفٌ تُحمَّل كلُّها،
 * والاسمُ يفتح ملفَّ الشركة القائم. ولا بابَ لها في الشريط الجانبيّ.
 */
export default function SelfSignups() {
    const { rows, pagination, filters, stats } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const columns: Column<SelfSignupRow>[] = [
        {
            key: 'name',
            header: 'الشركة',
            cell: (r) => (
                <div className="min-w-0">
                    <SmartLink
                        routeName="super-admin.businesses.show"
                        href={route('super-admin.businesses.show', r.id)}
                        className="font-medium hover:underline"
                    >
                        {r.name}
                    </SmartLink>
                    <Sub>{r.owner}</Sub>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'النشاط',
            cell: (r) => (
                <div>
                    {t(r.type)}
                    <Sub>{r.teamSize ? t(r.teamSize) : null}</Sub>
                </div>
            ),
        },
        {
            key: 'contact',
            header: 'التواصل',
            cell: (r) => (
                <div className="min-w-0">
                    <span dir="ltr" className="block text-[#4b4b4b]">
                        {r.phone || '—'}
                    </span>
                    <Sub ltr>{r.contactEmail}</Sub>
                    {r.loginEmail && r.loginEmail !== r.contactEmail && <Sub ltr>{r.loginEmail}</Sub>}
                </div>
            ),
        },
        {
            key: 'place',
            header: 'العنوان',
            cell: (r) => <span className="text-[#6b7280]">{[r.city, r.address].filter(Boolean).join(' — ') || '—'}</span>,
        },
        {
            key: 'registeredAt',
            header: 'التسجيل',
            cell: (r) => (
                <span dir="ltr" className="whitespace-nowrap text-[#6b7280] tabular-nums">
                    {r.registeredAt}
                </span>
            ),
        },
        { key: 'plan', header: 'الباقة', cell: (r) => (r.plan ? t(r.plan) : '—') },
        {
            key: 'trial',
            header: 'التجربة',
            cell: (r) => (
                <span dir="ltr" className="whitespace-nowrap text-[#6b7280] tabular-nums">
                    {r.trialStarts ?? '—'} → {r.trialEnds ?? '—'}
                </span>
            ),
        },
        { key: 'state', header: 'حالة التجربة', cell: (r) => <SignupState row={r} /> },
        {
            key: 'setup',
            header: 'التهيئة',
            cell: (r) =>
                r.setupConfirmedAt ? (
                    <Badge variant="success">{t('أكملها')}</Badge>
                ) : (
                    <Badge variant="neutral">{t('لم يكملها')}</Badge>
                ),
        },
        {
            key: 'lastLogin',
            header: 'آخر دخول',
            cell: (r) => (
                <span dir="ltr" className="whitespace-nowrap text-[#6b7280] tabular-nums">
                    {r.lastLogin ?? '—'}
                </span>
            ),
        },
        {
            key: 'products',
            header: 'المنتجات',
            align: 'end',
            cell: (r) => <span className="tabular-nums">{number(r.products)}</span>,
        },
        {
            key: 'sales',
            header: 'المبيعات',
            cell: (r) =>
                r.lastSale ? (
                    <div className="whitespace-nowrap">
                        <span className="text-[#4b4b4b]">
                            {t('آخر بيعة')} <span dir="ltr">{r.lastSale}</span>
                        </span>
                        <span className="block text-[12px] text-[#6b7280]">
                            {t('أول بيعة')} <span dir="ltr">{r.firstSale}</span>
                        </span>
                    </div>
                ) : (
                    <span className="rounded-full bg-[#f3f4f6] px-2.5 py-1 text-[12px] text-[#6b7280]">{t('لم يبع بعد')}</span>
                ),
        },
        { key: 'status', header: 'حالة الشركة', cell: (r) => <Badge status={r.status} /> },
    ];

    const tableFilters: Filter<SelfSignupRow>[] = [
        {
            label: 'كل التسجيلات',
            param: 'state',
            asTabs: true,
            options: [
                { label: 'في التجربة', value: 'trialing' },
                { label: 'انتهت التجربة', value: 'expired' },
                { label: 'مشترك', value: 'subscribed' },
            ],
        },
    ];

    return (
        <PlatformLayout title="التسجيلات من الموقع">
            <PageHeader
                title="التسجيلات من الموقع"
                subtitle={t('من فتح متجره بنفسه من صفحة التسجيل — على الباقة الأساسية وتجربة مجانية')}
                actions={
                    <Button variant="outline" asChild>
                        <SmartLink routeName="super-admin.businesses.index" href={route('super-admin.businesses.index')}>
                            <ArrowRight className="ltr:rotate-180" />
                            {t('الشركات')}
                        </SmartLink>
                    </Button>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {selfSignupStatCards(stats).map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <DataTable
                rows={rows}
                columns={columns}
                rowKey={(r) => r.id}
                filters={tableFilters}
                searchPlaceholder="ابحث بالاسم أو المالك أو البريد أو الهاتف…"
                empty={
                    <span className="flex flex-col items-center gap-2">
                        <UserPlus className="size-8 text-[#d1d5db]" />
                        {t('لا توجد تسجيلات ذاتية بعد')}
                    </span>
                }
                server={{ pagination, params: filters, sorts: [] }}
            />
        </PlatformLayout>
    );
}
