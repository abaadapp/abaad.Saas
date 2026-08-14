import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Award, ArrowRight, Mail, MapPin, Pencil, Phone, Plus, Save, Receipt, Star, Trash2,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import Tabs from '@/Components/Tabs';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import Field from '@/Components/Field';
import { Input, Textarea } from '@/Components/ui/input';
import {
    Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Customer, Order } from '@/types/models';

interface Address {
    id: number;
    label: string;
    city: string;
    area: string | null;
    street: string | null;
    is_default: boolean;
}

interface Props {
    customer: Customer & { address?: string | null; notes?: string | null };
    orders: Order[];
    addresses: Address[];
}

const BLANK = { address_id: '', label: '', city: '', area: '', street: '' };

export default function CustomerShow() {
    const { customer, orders, addresses, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);
    const [redeeming, setRedeeming] = useState(false);
    const [tab, setTab] = useState<'orders' | 'addresses'>('orders');

    const notes = useForm({ notes: customer.notes ?? '' });

    /** نموذج واحد للإضافة والتعديل: address_id فارغ = عنوان جديد */
    const [editing, setEditing] = useState(false);
    const address = useForm({ ...BLANK });

    const openAdd = () => { address.setData({ ...BLANK }); address.clearErrors(); setEditing(true); };
    const openEdit = (a: Address) => {
        address.setData({
            address_id: String(a.id),
            label: a.label,
            city: a.city,
            area: a.area ?? '',
            street: a.street ?? '',
        });
        address.clearErrors();
        setEditing(true);
    };
    const submitAddress = (e: React.FormEvent) => {
        e.preventDefault();
        address.post(route('admin.customers.addresses.save', customer.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const stats = [
        { label: t('عدد الطلبات'), value: String(customer.orders), icon: 'shopping-bag', color: 'primary' },
        { label: t('إجمالي المشتريات'), value: m(customer.total_spent), icon: 'wallet', color: 'success' },
        {
            label: t('متوسط الطلب'),
            value: m(customer.orders > 0 ? customer.total_spent / customer.orders : 0),
            icon: 'calculator', color: 'info',
        },
        { label: t('نقاط الولاء'), value: number(customer.points), icon: 'star', color: 'warning' },
    ];

    const contact = [
        { icon: <Phone className="size-4 text-[#9ca3af]" />, label: 'الهاتف', value: customer.phone, ltr: true },
        { icon: <Mail className="size-4 text-[#9ca3af]" />, label: 'البريد', value: customer.email, ltr: true },
        { icon: <MapPin className="size-4 text-[#9ca3af]" />, label: 'العنوان', value: customer.address ?? null },
        { icon: <Receipt className="size-4 text-[#9ca3af]" />, label: 'الرقم الضريبي', value: customer.tax_number, ltr: true },
    ];

    return (
        <AdminLayout title={customer.label || customer.name}>
            <PageHeader
                title="ملف العميل"
                subtitle={t('سجل مشتريات العميل ونقاط ولائه')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'العملاء', href: route('admin.customers.index') },
                    { label: customer.label || customer.name },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.customers.index" href={route('admin.customers.index')}>
                                <ArrowRight />{t('رجوع')}
                            </SmartLink>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={route('admin.customers.statement', customer.id)} target="_blank" rel="noreferrer">
                                <Receipt />{t('كشف حساب')}
                            </a>
                        </Button>
                        {customer.points > 0 && (
                            <Button onClick={() => setRedeeming(true)}>
                                <Award />{t('صرف النقاط')}
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => <StatCard key={s.label} stat={s} index={i} />)}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6">
                    <Card className="p-6 text-center">
                        {customer.avatar ? (
                            <img src={customer.avatar} alt="" className="mx-auto size-24 rounded-full object-cover ring-4 ring-[#f5f3ff]" />
                        ) : (
                            <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f5f3ff] text-[28px] font-bold text-[#6d28d9]">
                                {(customer.label || customer.name).slice(0, 1)}
                            </span>
                        )}
                        <h2 className="mt-4 text-[17px] font-bold text-[#111]">{customer.label || customer.name}</h2>
                        {customer.name_en && customer.label !== customer.name && (
                            <p className="text-[12px] text-[#9ca3af]">{customer.name}</p>
                        )}
                        <p className="mt-1 font-mono text-[12px] text-[#9ca3af]">#{customer.id}</p>
                        {customer.points > 0 && (
                            <Badge variant="warning" className="mt-3">
                                <Award className="size-3.5" />{number(customer.points)} {t('نقطة')}
                            </Badge>
                        )}
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 text-sm font-bold text-[#111]">{t('بيانات الاتصال')}</h3>
                        <ul className="space-y-3 text-sm">
                            {contact.map((c) => (
                                <li key={c.label} className="flex items-center justify-between gap-3">
                                    <span className="flex items-center gap-2 text-[#6b7280]">{c.icon}{t(c.label)}</span>
                                    <span dir={c.ltr ? 'ltr' : undefined} className="truncate text-[#111]">{c.value || '—'}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-3 text-sm font-bold text-[#111]">{t('ملاحظات')}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); notes.post(route('admin.customers.note', customer.id), { preserveScroll: true }); }}>
                            <Textarea
                                rows={4}
                                value={notes.data.notes}
                                onChange={(e) => notes.setData('notes', e.target.value)}
                                placeholder={t('ملاحظات داخلية عن العميل…')}
                            />
                            <Button type="submit" size="sm" className="mt-3" loading={notes.processing}>
                                <Save />{t('حفظ الملاحظة')}
                            </Button>
                        </form>
                    </Card>
                </div>

                <Card className="overflow-hidden lg:col-span-2">
                    {/* داخل بطاقة: الحشو يُبعد التبويبات عن حدّها */}
                    <Tabs
                        tabs={[
                            { key: 'orders', label: 'سجل الطلبات' },
                            { key: 'addresses', label: 'العناوين', count: addresses.length },
                        ]}
                        current={tab}
                        onChange={(k) => setTab(k as 'orders' | 'addresses')}
                        className="px-5"
                    />

                    {tab === 'addresses' ? (
                        <div className="p-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {addresses.map((a) => (
                                    <div
                                        key={a.id}
                                        className="rounded-[14px] border border-[var(--ui-border,#e8e8e8)] p-4 transition-colors hover:border-[#c4b5fd]"
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <span className="flex min-w-0 items-center gap-2 font-semibold text-[#111]">
                                                <MapPin className="size-4 shrink-0 text-[#6d28d9]" />
                                                <span className="truncate">{a.label}</span>
                                            </span>
                                            {a.is_default && <Badge variant="success">{t('افتراضي')}</Badge>}
                                        </div>
                                        <p className="text-sm text-[#4b4b4b]">
                                            {[a.city, a.area].filter(Boolean).join(' - ')}
                                        </p>
                                        {a.street && <p className="mt-1 text-[12px] text-[#9ca3af]">{a.street}</p>}

                                        <div className="mt-3 flex flex-wrap items-center gap-1">
                                            <Button variant="ghost" size="sm" onClick={() => openEdit(a)}>
                                                <Pencil />{t('تعديل')}
                                            </Button>
                                            {!a.is_default && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => router.post(
                                                        route('admin.customers.addresses.default', [customer.id, a.id]),
                                                        {}, { preserveScroll: true },
                                                    )}
                                                >
                                                    <Star />{t('تعيين افتراضي')}
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-[#b91c1c]"
                                                onClick={() => {
                                                    if (!confirm(t('حذف هذا العنوان؟'))) return;
                                                    router.delete(
                                                        route('admin.customers.addresses.delete', [customer.id, a.id]),
                                                        { preserveScroll: true },
                                                    );
                                                }}
                                            >
                                                <Trash2 />{t('حذف')}
                                            </Button>
                                        </div>
                                    </div>
                                ))}

                                <button
                                    type="button"
                                    onClick={openAdd}
                                    className="flex min-h-[120px] items-center justify-center gap-2 rounded-[14px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] text-sm text-[#6b7280] transition-colors hover:border-[#c4b5fd] hover:text-[#6d28d9]"
                                >
                                    <Plus className="size-5" />{t('إضافة عنوان جديد')}
                                </button>
                            </div>
                        </div>
                    ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['رقم الطلب', 'المنتجات', 'الإجمالي', 'الدفع', 'الحالة', 'التاريخ'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.length === 0 ? (
                                <TableEmpty colSpan={6}>{t('لا توجد طلبات لهذا العميل بعد')}</TableEmpty>
                            ) : orders.map((o) => (
                                <TableRow key={o.id}>
                                    <TableCell>
                                        <SmartLink routeName="admin.orders.show" href={route('admin.orders.show', o.id)}
                                            className="font-medium text-[#111] hover:underline">{o.id}</SmartLink>
                                    </TableCell>
                                    <TableCell className="tabular-nums text-[#4b4b4b]">{number(o.items_count)}</TableCell>
                                    <TableCell className="tabular-nums font-medium">{m(o.total)}</TableCell>
                                    <TableCell>{t(o.payment === 'بطاقة' ? 'فيزا' : o.payment)}</TableCell>
                                    <TableCell><Badge status={o.status}>{t(o.status)}</Badge></TableCell>
                                    <TableCell className="text-[#6b7280]">{o.date}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    )}
                </Card>
            </div>

            {/* إضافة/تعديل عنوان */}
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t(address.data.address_id ? 'تعديل العنوان' : 'عنوان جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitAddress} className="space-y-4 px-5 pb-5">
                        <Field label="التسمية" required hint="المنزل / العمل" error={address.errors.label}>
                            <Input
                                value={address.data.label}
                                onChange={(e) => address.setData('label', e.target.value)}
                                placeholder={t('المنزل')}
                                required
                            />
                        </Field>
                        <Field label="المدينة" required error={address.errors.city}>
                            <Input
                                value={address.data.city}
                                onChange={(e) => address.setData('city', e.target.value)}
                                placeholder={t('مسقط')}
                                required
                            />
                        </Field>
                        <Field label="المنطقة" error={address.errors.area}>
                            <Input
                                value={address.data.area}
                                onChange={(e) => address.setData('area', e.target.value)}
                                placeholder={t('الخوير')}
                            />
                        </Field>
                        <Field label="الشارع/المبنى" error={address.errors.street}>
                            <Input
                                value={address.data.street}
                                onChange={(e) => address.setData('street', e.target.value)}
                                placeholder={t('شارع 18 نوفمبر، مبنى 220')}
                            />
                        </Field>
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={address.processing}>
                                <Save />{t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={redeeming} onOpenChange={setRedeeming}>
                <DialogContent className="max-w-sm">
                    <DialogHeader><DialogTitle>{t('صرف النقاط')}</DialogTitle></DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيتم صرف')} {number(customer.points)} {t('نقطة')} ({m(customer.points / 100)}).
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setRedeeming(false)}>{t('إلغاء')}</Button>
                            <Button onClick={() => router.post(route('admin.customers.redeem', customer.id),
                                { points: customer.points }, { onFinish: () => setRedeeming(false) })}>
                                {t('صرف')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
