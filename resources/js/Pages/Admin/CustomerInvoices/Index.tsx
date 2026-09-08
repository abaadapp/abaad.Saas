import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    /** فارغٌ للمسودّة: الرقمُ يُقطع عند الإصدار */
    number: string | null;
    customer: string;
    status: string;
    state: string;
    issued_at: string | null;
    due_at: string | null;
    total: number;
    paid: number;
    outstanding: number;
    days_overdue: number;
    po_number: string | null;
}

interface Props {
    invoices: Row[];
    filters: Record<string, string | undefined>;
    totals: { total: number; overdue: number; due_soon: number; credit: number; invoices: number; customers: number };
}

/** حالُ السداد لونًا — والمتأخّرةُ وحدها حمراء */
const tone = (state: string) =>
    state === 'متأخرة' ? 'bg-[#fef2f2] text-[#b91c1c]'
    : state === 'مدفوعة' ? 'bg-[#ecfdf5] text-[#047857]'
    : state === 'ملغاة' ? 'bg-[#f4f4f5] text-[#71717a]'
    : 'bg-[#eff6ff] text-[#1d4ed8]';

/**
 * فواتيرُ العملاء.
 *
 * والفاتورةُ ليست الطلب: الطلبُ تنفيذٌ ومخزون، وهذه التزامٌ ماليٌّ له تاريخُ
 * استحقاقٍ ويُسدَّد على دفعات.
 */
export default function CustomerInvoicesIndex({ invoices, filters, totals }: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const [q, setQ] = useState(filters.q ?? '');

    const search = (extra: Record<string, string> = {}) =>
        router.get('/admin/customer-invoices', { ...filters, q, ...extra }, { preserveState: true });

    return (
        <AdminLayout title={t('فواتير العملاء')}>
            <PageHeader
                title={t('فواتير العملاء')}
                subtitle={t('الفواتير الصادرة على الشركات والجهات، وما بقي منها.')}
                actions={
                    <Button asChild>
                        <SmartLink
                            routeName="admin.customerInvoices.create"
                            href={route('admin.customerInvoices.create')}
                        >
                            <Plus />
                            {t('إنشاء فاتورة')}
                        </SmartLink>
                    </Button>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('إجمالي الذمم'), value: m(totals.total), icon: 'wallet', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('المتأخر'), value: m(totals.overdue), icon: 'alert-triangle', color: 'danger' }} index={1} />
                <StatCard stat={{ label: t('يستحق خلال ٧ أيام'), value: m(totals.due_soon), icon: 'calendar', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('رصيد دائن للعملاء'), value: m(totals.credit), icon: 'coins', color: 'success' }} index={3} />
            </div>

            <Card className="mb-4 flex flex-wrap items-center gap-2 p-3">
                <Input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && search()}
                    placeholder={t('رقم الفاتورة أو أمر الشراء أو العميل')}
                    className="max-w-xs"
                />
                <Button variant="outline" onClick={() => search()}>
                    <Search />
                    {t('بحث')}
                </Button>
                <Button
                    variant={filters.overdue ? 'primary' : 'outline'}
                    onClick={() => search({ overdue: filters.overdue ? '' : '1' })}
                >
                    {t('المتأخرة فقط')}
                </Button>
            </Card>

            <Card className="overflow-x-auto p-0">
                <table className="w-full text-[13px]">
                    <thead className="bg-[#fafafa] text-[12px] text-[#71717a]">
                        <tr>
                            <th className="p-3 text-start">{t('الرقم')}</th>
                            <th className="p-3 text-start">{t('العميل')}</th>
                            <th className="p-3 text-start">{t('الاستحقاق')}</th>
                            <th className="p-3 text-start">{t('الإجمالي')}</th>
                            <th className="p-3 text-start">{t('الباقي')}</th>
                            <th className="p-3 text-start">{t('الحالة')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.map((i) => (
                            <tr key={i.id} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                <td className="p-3">
                                    <Link href={`/admin/customer-invoices/${i.id}`} className="font-medium text-[#1d4ed8]">
                                        {/* ومسودّةٌ بلا رقم تُعرف بمعرّفها — لا بفراغ */}
                                        {i.number ?? `${t('مسودة')} #${i.id}`}
                                    </Link>
                                    {i.po_number && <div className="text-[11px] text-[#9ca3af]">{i.po_number}</div>}
                                </td>
                                <td className="p-3">{i.customer}</td>
                                <td className="p-3" dir="ltr">
                                    {i.due_at ?? '—'}
                                    {i.days_overdue > 0 && (
                                        <span className="ms-1 text-[11px] text-[#b91c1c]">
                                            {t('+:n يوم', { n: i.days_overdue })}
                                        </span>
                                    )}
                                </td>
                                <td className="p-3">{m(i.total)}</td>
                                <td className="p-3 font-medium">{m(i.outstanding)}</td>
                                <td className="p-3">
                                    <span className={'rounded-full px-2 py-1 text-[11px] ' + tone(i.state)}>{i.state}</span>
                                </td>
                            </tr>
                        ))}
                        {invoices.length === 0 && (
                            <tr>
                                <td colSpan={6} className="p-8 text-center text-[#9ca3af]">
                                    {t('لا فواتير بعد')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
