import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Barcode, Pencil } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard, { type Stat } from '@/Components/StatCard';
import DeleteButton from '@/Components/DeleteButton';
import SmartLink from '@/Components/SmartLink';
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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Movement, Product } from '@/types/models';

interface Props {
    product: Product;
    stats: Stat[];
    movements: Movement[];
    thumbs: string[];
    description: string;
}

export default function ProductShow() {
    const { product, stats, movements, thumbs, description, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [current, setCurrent] = useState(thumbs[0] ?? product.image ?? '');

    const facts = [
        { label: 'رمز المنتج SKU', value: product.sku || '—' },
        { label: 'الباركود', value: product.barcode || '—' },
        { label: 'سعر التكلفة', value: money(product.cost, currency) },
        { label: 'حد التنبيه', value: `${number(product.alert)} ${t('قطعة')}` },
    ];

    return (
        <AdminLayout title="تفاصيل المنتج">
            <PageHeader
                title={product.label ?? product.name}
                subtitle={`${product.cat} · ${product.sku}`}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المنتجات', href: route('admin.products.index') },
                    { label: product.label ?? product.name },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="admin.products.barcodes"
                                href={`${route('admin.products.barcodes')}?copies=1`}
                            >
                                <Barcode />
                                {t('الباركود')}
                            </SmartLink>
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.products.edit" href={route('admin.products.edit', product.id)}>
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                        {/* الحذف يمرّ بنافذة تأكيد ثم يعيد التوجيه إلى القائمة */}
                        <DeleteButton
                            url={route('admin.products.destroy', product.id)}
                            message="حذف هذا المنتج نهائيًا؟"
                        />
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card className="p-5">
                    <div className="aspect-square overflow-hidden rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                        {current && <img src={current} alt={product.name} className="size-full object-cover" />}
                    </div>
                    {thumbs.length > 1 && (
                        <div className="mt-4 grid grid-cols-4 gap-3">
                            {thumbs.map((thumb) => (
                                <button
                                    key={thumb}
                                    type="button"
                                    onClick={() => setCurrent(thumb)}
                                    className={cn(
                                        'aspect-square overflow-hidden rounded-full border-2 transition',
                                        current === thumb
                                            ? 'border-[#8b5cf6] ring-2 ring-[#ddd6fe]'
                                            : 'border-[var(--ui-border,#e8e8e8)]',
                                    )}
                                >
                                    <img src={thumb} alt={t('صورة مصغرة')} className="size-full object-cover" />
                                </button>
                            ))}
                        </div>
                    )}
                </Card>

                <Card className="p-6">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <Badge status={product.stock_status}>{t(product.stock_status)}</Badge>
                        <Badge variant={product.active ? 'success' : 'neutral'}>
                            {product.active ? t('مفعّل') : t('غير مفعّل')}
                        </Badge>
                        {product.discount > 0 && (
                            <Badge variant="primary">
                                {t('خصم')} {number(product.discount)}%
                            </Badge>
                        )}
                    </div>

                    <p className="text-sm font-medium text-[#6d28d9]">{product.cat}</p>
                    <h2 className="mt-1 text-[24px] font-bold text-[#111]">{product.label ?? product.name}</h2>

                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-[30px] font-bold tabular-nums text-[#111]">
                            {money(product.price, currency)}
                        </span>
                        <span className="text-sm text-[#9ca3af]">
                            {t('شامل ضريبة')} {number(product.tax)}%
                        </span>
                    </div>

                    <p className="mt-4 text-sm leading-relaxed text-[#4b4b4b]">{description}</p>

                    <dl className="mt-6 grid grid-cols-2 gap-4 text-sm">
                        {facts.map((f) => (
                            <div key={f.label}>
                                <dt className="text-[#9ca3af]">{t(f.label)}</dt>
                                <dd className="mt-0.5 font-medium text-[#111]">{f.value}</dd>
                            </div>
                        ))}
                    </dl>
                </Card>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div>
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="font-bold text-[#111]">{t('حركة المخزون')}</h3>
                    <SmartLink
                        routeName="admin.inventory.movements"
                        href={route('admin.inventory.movements')}
                        className="text-sm font-medium text-[#6d28d9] hover:underline"
                    >
                        {t('عرض الكل')}
                    </SmartLink>
                </div>
                <Card className="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['نوع الحركة', 'الكمية', 'الموظف', 'التاريخ'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.length === 0 ? (
                                <TableEmpty colSpan={4}>{t('لا توجد حركات لهذا المنتج بعد')}</TableEmpty>
                            ) : (
                                movements.map((mv, i) => (
                                    <TableRow key={i}>
                                        <TableCell className="font-medium text-[#111]">{t(mv.type)}</TableCell>
                                        <TableCell>
                                            <span
                                                className={cn(
                                                    'font-medium tabular-nums',
                                                    String(mv.qty).startsWith('+')
                                                        ? 'text-[#047857]'
                                                        : 'text-[#b91c1c]',
                                                )}
                                            >
                                                {mv.qty}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{mv.employee}</TableCell>
                                        <TableCell className="text-[#6b7280]">{mv.date}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </AdminLayout>
    );
}
