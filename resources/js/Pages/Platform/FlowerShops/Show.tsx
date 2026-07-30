import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Layers, MapPin, Pencil, User } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import AreaChart from '@/Components/charts/AreaChart';
import DeleteButton from '@/Components/DeleteButton';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import Tabs from '@/Components/Tabs';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { initials, money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface Shop {
    id: number;
    name: string;
    owner: string | null;
    city: string | null;
    phone: string | null;
    email: string | null;
    branches: number;
    status: string;
    plan: string;
    sales: number;
    logo_url: string | null;
}

interface Props {
    shop: Shop;
    subscription: { plan: string; start: string; end: string; payment: string } | null;
    stats: Stat[];
    branches: { id: number; name: string; phone: string | null; address: string | null; employees: number }[];
    employees: { id: number; name: string; role: string; branch: string | null; phone: string | null; status: string }[];
    products: { id: number; name: string; category: string; price: number; qty: number; image: string | null }[];
    orders: {
        id: string;
        customer: string;
        items_count: number;
        total: number;
        payment: string;
        status: string;
        date: string;
    }[];
    salesSeries: { labels: string[]; data: number[] };
    currency: Currency;
}

const TABS = [
    { key: 'branches', label: 'الفروع' },
    { key: 'employees', label: 'الموظفون' },
    { key: 'products', label: 'المنتجات' },
    { key: 'sales', label: 'المبيعات' },
    { key: 'orders', label: 'آخر الطلبات' },
];

export default function FlowerShopShow() {
    const { shop, subscription, stats, branches, employees, products, orders, salesSeries, currency } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('branches');

    const info = [
        { label: 'الاسم', value: shop.name },
        { label: 'المدينة', value: shop.city },
        { label: 'عدد الفروع', value: number(shop.branches) },
        { label: 'إجمالي المبيعات', value: money(shop.sales, currency) },
    ];

    const owner = [
        { label: 'الاسم', value: shop.owner },
        { label: 'الهاتف', value: shop.phone, ltr: true },
        { label: 'البريد', value: shop.email, ltr: true },
    ];

    return (
        <PlatformLayout title="تفاصيل محل الورود">
            <PageHeader
                title="تفاصيل محل الورود"
                subtitle={shop.name}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'محلات الورود', href: route('super-admin.flower-shops.index') },
                    { label: shop.name },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.flower-shops.edit"
                                href={route('super-admin.flower-shops.edit', shop.id)}
                            >
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                        <DeleteButton
                            url={route('super-admin.businesses.destroy', shop.id)}
                            label="تعطيل"
                            message="سيُنقل هذا المحل إلى حالة «معطل». هل تريد المتابعة؟"
                        />
                    </>
                }
            />

            <Card className="mb-6 p-6">
                <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                    {shop.logo_url ? (
                        <img
                            src={shop.logo_url}
                            alt=""
                            className="size-24 shrink-0 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                        />
                    ) : (
                        <span className="size-24 shrink-0 rounded-[16px] bg-[#f2f2f0]" />
                    )}
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-[20px] font-bold text-[#111]">{shop.name}</h2>
                            <Badge status={shop.status} />
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-[#6b7280]">
                            <span className="flex items-center gap-1.5">
                                <MapPin className="size-4" />
                                {shop.city || '—'}
                            </span>
                            <span className="flex items-center gap-1.5">
                                <User className="size-4" />
                                {shop.owner || '—'}
                            </span>
                            <span className="flex items-center gap-1.5">
                                <Layers className="size-4" />
                                {t('باقة')} {t(shop.plan)}
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('معلومات المحل')}</h3>
                    <dl className="space-y-3 text-sm">
                        {info.map((f) => (
                            <div key={f.label} className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t(f.label)}</dt>
                                <dd className="truncate font-medium tabular-nums text-[#111]">{f.value || '—'}</dd>
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
                    <dl className="space-y-3 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('الباقة')}</dt>
                            <dd className="font-medium text-[#111]">{t(shop.plan)}</dd>
                        </div>
                        {subscription ? (
                            <>
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
                                    <dt className="text-[#6b7280]">{t('حالة الدفع')}</dt>
                                    <dd>
                                        <Badge status={subscription.payment} />
                                    </dd>
                                </div>
                            </>
                        ) : (
                            <p className="pt-2 text-center text-sm text-[#9ca3af]">{t('لا يوجد اشتراك')}</p>
                        )}
                    </dl>
                </Card>
            </div>

            <Card className="overflow-hidden">
                <Tabs tabs={TABS} current={tab} onChange={setTab} />

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
                                <TableEmpty colSpan={4}>{t('لا توجد فروع مسجلة لهذا المحل')}</TableEmpty>
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

                {tab === 'employees' && (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['الموظف', 'الدور', 'الفرع', 'الهاتف', 'الحالة'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {employees.length === 0 ? (
                                <TableEmpty colSpan={5}>{t('لا يوجد موظفون لهذا المحل')}</TableEmpty>
                            ) : (
                                employees.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell>
                                            <span className="flex items-center gap-3">
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#f2f2f0] text-[12px] font-medium text-[#4b4b4b]">
                                                    {initials(e.name)}
                                                </span>
                                                <span className="font-medium text-[#111]">{e.name}</span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{t(e.role)}</TableCell>
                                        <TableCell className="text-[#4b4b4b]">{e.branch || '—'}</TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {e.phone || '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge status={e.status} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                )}

                {tab === 'products' && (
                    <div className="p-5">
                        {products.length === 0 ? (
                            <p className="py-12 text-center text-sm text-[#9ca3af]">
                                {t('لا توجد منتجات لهذا المحل')}
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {products.map((p) => (
                                    <Card key={p.id} className="overflow-hidden">
                                        {p.image ? (
                                            <img
                                                src={p.image}
                                                alt=""
                                                loading="lazy"
                                                className="h-32 w-full object-cover"
                                            />
                                        ) : (
                                            <div className="h-32 w-full bg-[#f2f2f0]" />
                                        )}
                                        <div className="p-4">
                                            <p className="truncate font-medium text-[#111]">{p.name}</p>
                                            <p className="mt-0.5 text-[12px] text-[#9ca3af]">{p.category}</p>
                                            <div className="mt-2 flex items-baseline justify-between">
                                                <span className="font-bold tabular-nums text-[#111]">
                                                    {money(p.price, currency)}
                                                </span>
                                                <span className="text-[12px] tabular-nums text-[#6b7280]">
                                                    {number(p.qty)} {t('قطعة')}
                                                </span>
                                            </div>
                                        </div>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {tab === 'sales' && (
                    <div className="p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <h4 className="font-semibold text-[#111]">{t('مبيعات آخر 12 شهرًا')}</h4>
                        </div>
                        {/* من طلبات هذا المحل فعلًا — كانت سلسلة أرقام ثابتة في القالب */}
                        <AreaChart
                            labels={salesSeries.labels}
                            data={salesSeries.data}
                            format={(v) => money(v, currency)}
                            height={300}
                        />
                    </div>
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
                                <TableEmpty colSpan={7}>{t('لا توجد طلبات لهذا المحل بعد')}</TableEmpty>
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
