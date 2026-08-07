import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { KeyRound, Layers, LogIn, Pencil, Phone, User } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import DeleteButton from '@/Components/DeleteButton';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import Tabs from '@/Components/Tabs';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AccountCard from './partials/AccountCard';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface Business {
    id: number;
    name: string;
    type: string;
    owner: string | null;
    phone: string | null;
    email: string | null;
    plan: string;
    status: string;
    registered: string;
    branches: number;
    city: string | null;
    country: string | null;
    logo: string | null;
    owner_email: string | null;
}

interface Subscription {
    plan: string;
    start: string;
    end: string;
    amount: number;
    payment: string;
}

interface BranchRow {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    employees: number;
}

interface OrderRow {
    id: string;
    customer: string;
    items_count: number;
    total: number;
    payment: string;
    status: string;
    date: string;
}

interface UsageRow {
    key: string;
    label: string;
    used: number;
    /** null = لا سقف: متجرٌ بلا باقة لا يُقيَّد */
    cap: number | null;
}

interface Props {
    business: Business;
    subscription: Subscription | null;
    usage: UsageRow[];
    renewal: { monthly: number; yearly: number; endsAt: string | null };
    stats: Stat[];
    overview: { sales: string; orders: number; average: string };
    branches: BranchRow[];
    orders: OrderRow[];
    currency: Currency;
}

const TABS = [
    { key: 'overview', label: 'نظرة عامة' },
    { key: 'branches', label: 'الفروع' },
    { key: 'orders', label: 'آخر الطلبات' },
];

export default function BusinessShow() {
    const { business, subscription, usage, renewal, stats, overview, branches, orders, currency } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('overview');
    const facts = [
        { label: 'الاسم', value: business.name },
        { label: 'النوع', value: t(business.type) },
        { label: 'المدينة', value: business.city },
        { label: 'الدولة', value: business.country },
        { label: 'تاريخ التسجيل', value: business.registered, ltr: true },
    ];

    const owner = [
        { label: 'الاسم', value: business.owner },
        { label: 'الهاتف', value: business.phone, ltr: true },
        { label: 'البريد', value: business.email, ltr: true },
        // بريد الدخول لا بريد التواصل — أوّل ما يُسأل عنه حين يتصل التاجر
        { label: 'حساب الدخول', value: business.owner_email, ltr: true },
    ];

    return (
        <PlatformLayout title="ملف الشركة">
            <PageHeader
                title="ملف الشركة"
                subtitle={business.name}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'الشركات', href: route('super-admin.businesses.index') },
                    { label: business.name },
                ]}
                actions={
                    <>
                        {/* الدعم يحتاج أن يرى الشاشة التي يشكو منها التاجر لا وصفها */}
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(route('super-admin.businesses.impersonate', business.id))
                            }
                        >
                            <LogIn />
                            {t('دخول كتاجر')}
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.businesses.edit"
                                href={route('super-admin.businesses.edit', business.id)}
                            >
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                        <DeleteButton
                            url={route('super-admin.businesses.destroy', business.id)}
                            label="تعطيل"
                            message="سيُنقل هذا النشاط إلى حالة «معطل». هل تريد المتابعة؟"
                        />
                    </>
                }
            />

            <Card className="mb-6 p-6">
                <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                    {business.logo ? (
                        <img
                            src={business.logo}
                            alt=""
                            className="size-24 shrink-0 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                        />
                    ) : (
                        <span className="size-24 shrink-0 rounded-[16px] bg-[#f2f2f0]" />
                    )}
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-[20px] font-bold text-[#111]">{business.name}</h2>
                            <Badge status={business.status} />
                        </div>
                        <p className="mt-1 text-sm text-[#6b7280]">
                            {t(business.type)}
                            {business.city && ` — ${business.city}${business.country ? `، ${business.country}` : ''}`}
                        </p>
                        <div className="mt-3 flex flex-wrap items-center gap-4 text-sm text-[#6b7280]">
                            <span className="flex items-center gap-1.5">
                                <User className="size-4" />
                                {business.owner || '—'}
                            </span>
                            <span dir="ltr" className="flex items-center gap-1.5">
                                <Phone className="size-4" />
                                {business.phone || '—'}
                            </span>
                            <span className="flex items-center gap-1.5">
                                <Layers className="size-4" />
                                {t('باقة')} {t(business.plan)}
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            {business.owner_email ? (
                <AccountCard businessId={business.id} ownerEmail={business.owner_email} />
            ) : (
                /* شركةٌ بلا حساب: لا يفتحها أحد — والإنشاء من صفحة التعديل حيث يُلزَم */
                <Card className="mb-6 flex flex-wrap items-center justify-between gap-3 border-[#fed7aa] bg-[#fff7ed] p-5">
                    <p className="text-[13px] text-[#9a3412]">
                        {t('هذه الشركة بلا حساب دخول — لا يستطيع صاحبها فتح لوحته.')}
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <SmartLink
                            routeName="super-admin.businesses.edit"
                            href={route('super-admin.businesses.edit', business.id)}
                        >
                            <KeyRound />
                            {t('إنشاء حساب')}
                        </SmartLink>
                    </Button>
                </Card>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('بيانات الشركة')}</h3>
                    <dl className="space-y-3 text-sm">
                        {facts.map((f) => (
                            <div key={f.label} className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t(f.label)}</dt>
                                <dd dir={f.ltr ? 'ltr' : undefined} className="truncate font-medium text-[#111]">
                                    {f.value || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('بيانات المالك')}</h3>
                    <dl className="space-y-3 text-sm">
                        {owner.map((f) => (
                            <div key={f.label} className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t(f.label)}</dt>
                                <dd dir={f.ltr ? 'ltr' : undefined} className="truncate font-medium text-[#111]">
                                    {f.value || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('الاشتراك الحالي')}</h3>
                    {subscription ? (
                        <dl className="space-y-3 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('الباقة')}</dt>
                                <dd className="font-medium text-[#111]">{t(subscription.plan)}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('تاريخ البداية')}</dt>
                                <dd dir="ltr" className="font-medium text-[#111]">
                                    {subscription.start}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('تاريخ الانتهاء')}</dt>
                                <dd dir="ltr" className="font-medium text-[#111]">
                                    {subscription.end}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('المبلغ')}</dt>
                                <dd className="font-medium tabular-nums text-[#111]">
                                    {money(subscription.amount, currency)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('حالة الدفع')}</dt>
                                <dd>
                                    <Badge status={subscription.payment} />
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="py-6 text-center text-sm text-[#9ca3af]">{t('لا يوجد اشتراك')}</p>
                    )}
                </Card>
            </div>

            <Card className="overflow-hidden">
                <Tabs tabs={TABS} current={tab} onChange={setTab} />

                {tab === 'overview' && (
                    <div className="p-5">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {[
                                { label: 'إجمالي المبيعات', value: overview.sales },
                                { label: 'عدد الطلبات', value: number(overview.orders) },
                                { label: 'متوسط قيمة الطلب', value: overview.average },
                            ].map((s) => (
                                <div key={s.label} className="rounded-[12px] bg-[#fafafa] p-4">
                                    <p className="text-sm text-[#6b7280]">{t(s.label)}</p>
                                    <p className="mt-1 text-[18px] font-bold tabular-nums text-[#111]">{s.value}</p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-4 text-sm leading-relaxed text-[#6b7280]">
                            {t('مسجّلة منذ')} {business.registered} · {t(business.type)}
                            {business.city && ` · ${business.city}`} · {number(business.branches)} {t('فرعًا')} ·{' '}
                            {t('باقة')} {t(business.plan)}
                        </p>

                        {/*
                            الاستهلاك مقابل السقف — من بلغ حدّه هو المرشَّح
                            للترقية، ولا سبيل لمعرفته إن لم يُعرض.
                        */}
                        <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {usage.map((u) => {
                                const full = u.cap !== null && u.used >= u.cap;
                                const pct = u.cap ? Math.min(100, (u.used / u.cap) * 100) : 0;

                                return (
                                    <div
                                        key={u.key}
                                        className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                                    >
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="text-sm text-[#6b7280]">{u.label}</span>
                                            <span
                                                className={
                                                    'tabular-nums text-sm font-semibold ' +
                                                    (full ? 'text-[#b91c1c]' : 'text-[#111]')
                                                }
                                            >
                                                {number(u.used)}
                                                {u.cap !== null && ` / ${number(u.cap)}`}
                                            </span>
                                        </div>
                                        {u.cap !== null ? (
                                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-[#f2f2f0]">
                                                <div
                                                    className={
                                                        'h-full rounded-full ' +
                                                        (full ? 'bg-[#b91c1c]' : 'bg-[#111]')
                                                    }
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                        ) : (
                                            <p className="mt-2 text-[12px] text-[#9ca3af]">{t('بلا سقف')}</p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        {/* التجديد: دورةٌ كاملة وفاتورةٌ تُصدَر معها */}
                        <div className="mt-6 flex flex-wrap items-center gap-3 rounded-[12px] bg-[#fafafa] p-4">
                            <span className="text-sm text-[#4b4b4b]">
                                {renewal.endsAt
                                    ? `${t('ينتهي في')} ${renewal.endsAt}`
                                    : t('لا تاريخ انتهاء محدَّد')}
                            </span>
                            <span className="flex-1" />
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(route('super-admin.businesses.renew', business.id), {
                                        cycle: 'monthly',
                                    })
                                }
                            >
                                {t('جدّد شهرًا')} · {money(renewal.monthly, currency)}
                            </Button>
                            <Button
                                onClick={() =>
                                    router.post(route('super-admin.businesses.renew', business.id), {
                                        cycle: 'yearly',
                                    })
                                }
                            >
                                {t('جدّد سنة')} · {money(renewal.yearly, currency)}
                            </Button>
                        </div>
                    </div>
                )}

                {tab === 'branches' && (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['الفرع', 'الهاتف', 'العنوان', 'عدد الموظفين'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {branches.length === 0 ? (
                                <TableEmpty colSpan={4}>{t('لا توجد فروع مسجلة لهذه الشركة')}</TableEmpty>
                            ) : (
                                branches.map((b) => (
                                    <TableRow key={b.id}>
                                        <TableCell className="font-medium text-[#111]">{b.name}</TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {b.phone || '—'}
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{b.address || '—'}</TableCell>
                                        <TableCell className="tabular-nums text-[#4b4b4b]">
                                            {number(b.employees)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                )}

                {tab === 'orders' && (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['رقم الطلب', 'العميل', 'العناصر', 'الإجمالي', 'الدفع', 'الحالة', 'التاريخ'].map(
                                    (h) => (
                                        <TableHead key={h}>{t(h)}</TableHead>
                                    ),
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.length === 0 ? (
                                <TableEmpty colSpan={7}>{t('لا توجد طلبات لهذه الشركة بعد')}</TableEmpty>
                            ) : (
                                orders.map((o) => (
                                    <TableRow key={o.id}>
                                        <TableCell className="font-medium text-[#111]">{o.id}</TableCell>
                                        <TableCell className="text-[#4b4b4b]">{o.customer}</TableCell>
                                        <TableCell className="tabular-nums text-[#4b4b4b]">
                                            {number(o.items_count)}
                                        </TableCell>
                                        <TableCell className="font-medium tabular-nums text-[#111]">
                                            {money(o.total, currency)}
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{t(o.payment)}</TableCell>
                                        <TableCell>
                                            <Badge status={o.status} />
                                        </TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {o.date}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                )}
            </Card>

        </PlatformLayout>
    );
}
