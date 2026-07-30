import { usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeft, Layers, MapPin, Plus, User } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface Shop {
    id: number;
    name: string;
    logo: string | null;
    owner: string | null;
    city: string | null;
    branches: number;
    employees: number;
    products: number;
    orders: number;
    status: string;
    plan: string;
    sales: number;
}

interface Props {
    shops: Shop[];
    cities: string[];
    currency: Currency;
}

export default function FlowerShopsIndex() {
    const { shops, cities, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    // الأعمدة تُغذّي البحث والترتيب؛ العرض نفسه بطاقات عبر renderBody
    const columns: Column<Shop>[] = [
        { key: 'name', header: 'المحل', value: (s) => s.name },
        { key: 'city', header: 'المدينة', value: (s) => s.city ?? '' },
        { key: 'sales', header: 'المبيعات', value: (s) => s.sales },
    ];

    const filters: Filter<Shop>[] = [
        {
            label: 'كل المدن',
            options: cities.map((c) => ({ label: c, value: c })),
            match: (s, v) => s.city === v,
        },
        {
            label: 'كل الحالات',
            options: [
                { label: 'نشط', value: 'نشط' },
                { label: 'منتهي', value: 'منتهي' },
                { label: 'معطل', value: 'معطل' },
            ],
            match: (s, v) => s.status === v,
        },
    ];

    return (
        <PlatformLayout title="محلات الورود">
            <PageHeader
                title="محلات الورود"
                subtitle={t('إدارة محلات الورود المسجلة في المنصة')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'محلات الورود' },
                ]}
                actions={
                    <Button asChild>
                        <SmartLink
                            routeName="super-admin.flower-shops.create"
                            href={route('super-admin.flower-shops.create')}
                        >
                            <Plus />
                            {t('إضافة محل ورود')}
                        </SmartLink>
                    </Button>
                }
            />

            <DataTable
                rows={shops}
                columns={columns}
                rowKey={(s) => s.id}
                filters={filters}
                searchPlaceholder="ابحث عن محل ورود…"
                searchable={(s) => `${s.name} ${s.owner ?? ''} ${s.city ?? ''}`}
                empty={t('لا توجد محلات ورود مسجلة بعد')}
                renderBody={(rows) => (
                    <div className="grid grid-cols-1 gap-5 p-4 md:grid-cols-2 xl:grid-cols-3">
                        {rows.map((shop, i) => (
                            <motion.div
                                key={shop.id}
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.25, delay: Math.min(i * 0.03, 0.2) }}
                            >
                                <Card className="flex h-full flex-col overflow-hidden">
                                    <div className="relative h-32 bg-[#f2f2f0]">
                                        {shop.logo && (
                                            <img
                                                src={shop.logo}
                                                alt=""
                                                loading="lazy"
                                                className="size-full object-cover"
                                            />
                                        )}
                                        <span className="absolute end-3 top-3">
                                            <Badge status={shop.status} />
                                        </span>
                                    </div>

                                    <div className="flex flex-1 flex-col p-5">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <h3 className="truncate font-bold text-[#111]">{shop.name}</h3>
                                                <p className="mt-0.5 flex items-center gap-1 text-sm text-[#6b7280]">
                                                    <MapPin className="size-3.5" />
                                                    {shop.city || '—'}
                                                </p>
                                            </div>
                                            <span className="inline-flex shrink-0 items-center gap-1 rounded-[8px] bg-[#f2f2f0] px-2 py-1 text-[12px] font-medium text-[#4b4b4b]">
                                                <Layers className="size-3.5" />
                                                {t(shop.plan)}
                                            </span>
                                        </div>

                                        <p className="mt-2 flex items-center gap-1.5 text-sm text-[#6b7280]">
                                            <User className="size-4" />
                                            {shop.owner || '—'}
                                        </p>

                                        <div className="mt-4 grid grid-cols-4 gap-2 text-center">
                                            {[
                                                { v: shop.branches, l: 'فروع' },
                                                { v: shop.employees, l: 'موظفين' },
                                                { v: shop.products, l: 'منتجات' },
                                                { v: shop.orders, l: 'طلبات' },
                                            ].map((cell) => (
                                                <div key={cell.l} className="rounded-[12px] bg-[#fafafa] py-2">
                                                    <p className="text-sm font-bold tabular-nums text-[#111]">
                                                        {number(cell.v)}
                                                    </p>
                                                    <p className="text-[11px] text-[#9ca3af]">{t(cell.l)}</p>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="mt-4 flex items-center justify-between border-t border-[#f5f5f4] pt-4 text-sm text-[#6b7280]">
                                            {t('المبيعات:')}
                                            <span className="font-bold tabular-nums text-[#111]">
                                                {money(shop.sales, currency)}
                                            </span>
                                        </div>

                                        <Button variant="outline" className="mt-4 w-full" asChild>
                                            <SmartLink
                                                routeName="super-admin.flower-shops.show"
                                                href={route('super-admin.flower-shops.show', shop.id)}
                                            >
                                                <ArrowLeft />
                                                {t('عرض التفاصيل')}
                                            </SmartLink>
                                        </Button>
                                    </div>
                                </Card>
                            </motion.div>
                        ))}
                    </div>
                )}
            />
        </PlatformLayout>
    );
}
