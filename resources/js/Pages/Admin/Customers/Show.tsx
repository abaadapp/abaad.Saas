import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Award, Mail, MapPin, MoreVertical, Pencil, Phone, Plus, Save, Receipt, Star, Trash2,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import Tabs from '@/Components/Tabs';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import Field, { Select } from '@/Components/Field';

import LanguageChoice from '@/Components/LanguageChoice';
import Toggle from '@/Components/Toggle';
import { Input, Textarea } from '@/Components/ui/input';
import {
    Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useConfirm } from '@/Components/ConfirmDialog';
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
    customer: Customer & { address?: string | null; notes?: string | null; branch_id?: number | null; language?: string | null };
    branches?: { id: number; name: string }[];
    orders: Order[];
    addresses: Address[];
    /** ما أذنت به «الإعدادات ← العملاء» — المُطفأ يُخفى ولا يُمحى */
    customerFlags: { alerts: boolean; blocking: boolean; birthdays: boolean };
}

const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

/** الميلادُ كما يُقرأ: «١٢ مارس» — والسنةُ إن عُرفت، ولا سنةَ تُخترع */
export function birthdayLabel(c: { birth_day?: number | null; birth_month?: number | null; birth_year?: number | null }, t: (k: string) => string): string | null {
    if (!c.birth_day || !c.birth_month) return null;
    const base = `${c.birth_day} ${t(MONTHS[c.birth_month - 1])}`;

    return c.birth_year ? `${base} ${c.birth_year}` : base;
}

const BLANK = { address_id: '', label: '', city: '', area: '', street: '' };

export default function CustomerShow() {
    const { customer, orders, addresses, context, branches = [], customerFlags } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);
    const [redeeming, setRedeeming] = useState(false);
    const [editingProfile, setEditingProfile] = useState(false);

    /*
     * التعديل والحذف — لم يكونا موجودين إطلاقًا.
     *
     * رقمٌ فيه خطأٌ واحد كان يبقى خطأً أبدًا. وهو أشدّ ممّا يبدو: نقاط
     * الولاء تتبع الهاتف، فالخطأ يعني عميلًا لا يجد نقاطه، ولا سبيل إلى
     * إصلاحه إلا بعميلٍ ثانٍ فيصير في القائمة اسمان لشخصٍ واحد.
     */
    const edit = useForm({
        name: customer.name ?? '',
        name_en: customer.name_en ?? '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        language: customer.language ?? '',
        branch_id: customer.branch_id ? String(customer.branch_id) : '',
    });
    const [tab, setTab] = useState<'orders' | 'addresses'>('orders');

    const notes = useForm({ notes: customer.notes ?? '' });

    /*
     * المعلوماتُ الداخليّة — الميلادُ والتنبيه.
     *
     * نموذجٌ واحد ومسارٌ واحد (`customers.internal`): لا يُزاحم نافذةَ
     * الصندوق السريعة ولا نموذجَ تعديل البيانات. والتنبيهُ يُطفأ ويبقى سببُه
     * مكتوبًا في الحقل لمن يعيده.
     */
    const internal = useForm({
        birth_day: customer.birth_day ? String(customer.birth_day) : '',
        birth_month: customer.birth_month ? String(customer.birth_month) : '',
        birth_year: customer.birth_year ? String(customer.birth_year) : '',
        alert_enabled: !!customer.alert_type,
        alert_type: customer.alert_type ?? 'warning',
        alert_reason: customer.alert_reason ?? '',
    });
    const saveInternal = (e: React.FormEvent) => {
        e.preventDefault();
        internal.post(route('admin.customers.internal', customer.id), { preserveScroll: true });
    };
    const showInternal = customerFlags.alerts || customerFlags.birthdays;

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
            <BackLink
                routeName="admin.customers.index"
                href={route('admin.customers.index')}
                label="العملاء"
            />
            <PageHeader
                title="ملف العميل"
                subtitle={t('سجل مشتريات العميل ونقاط ولائه')}
                actions={
                    <>
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
                        {/*
                            التعديل والحذف تحت النقاط الثلاث كما في المورّدين.
                            خمسة أزرارٍ في الرأس تجعل «كشف حساب» — وهو ما
                            يُطلب كل يوم — واحدًا في زحام. وما يُفعل مرّةً
                            يسكن القائمة، وما يُفعل دائمًا يبقى ظاهرًا.
                        */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon" aria-label={t('المزيد')}>
                                    <MoreVertical />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuItem onSelect={() => setEditingProfile(true)}>
                                    <Pencil className="text-[#6b7280]" />
                                    {t('تعديل بيانات العميل')}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                {/*
                                    حذفٌ ناعم إلى «المحذوفات»: فواتيره تشير إليه،
                                    ومحوُه نهائيًّا يتركها تشير إلى رقمٍ لا وجود له.
                                */}
                                <DropdownMenuItem
                                    className="text-[#b91c1c]"
                                    onSelect={async () => {
                                        if (! await ask({ message: 'حذف هذا العميل؟ يمكن استعادته من المحذوفات.', danger: true, action: 'حذف' })) return;
                                        router.delete(route('admin.customers.destroy', customer.id));
                                    }}
                                >
                                    <Trash2 className="text-[#b91c1c]" />
                                    {t('حذف العميل')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => <StatCard key={s.label} stat={s} index={i} />)}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6">
                    <Card className="p-6 text-center">
                        {/* الحرف الأول — ولا عمود صورةٍ للعملاء، انظر `CustomerController::index` */}
                        <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f5f3ff] text-[28px] font-bold text-[#6d28d9]">
                            {(customer.label || customer.name).slice(0, 1)}
                        </span>
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

                    {showInternal && (
                        <Card className="p-6">
                            <h3 className="mb-1 text-sm font-bold text-[#111]">{t('معلومات داخلية')}</h3>
                            <p className="mb-4 text-[12px] text-[#9ca3af]">{t('لا تظهر في فاتورة ولا إيصال ولا رسالة — للموظّفين وحدهم.')}</p>
                            <form onSubmit={saveInternal} className="space-y-4">
                                {customerFlags.birthdays && (
                                    <Field label="تاريخ الميلاد" hint="اليوم والشهر يكفيان — والسنة إن عُرفت" error={internal.errors.birth_day || internal.errors.birth_month || internal.errors.birth_year}>
                                        <div className="grid grid-cols-3 gap-2">
                                            <Input
                                                type="number" min={1} max={31} placeholder={t('اليوم')} aria-label={t('اليوم')}
                                                value={internal.data.birth_day}
                                                onChange={(e) => internal.setData('birth_day', e.target.value)}
                                            />
                                            <Select
                                                aria-label={t('الشهر')}
                                                value={internal.data.birth_month}
                                                onChange={(e) => internal.setData('birth_month', e.target.value)}
                                                placeholder="الشهر"
                                                options={MONTHS.map((m, i) => ({ label: m, value: String(i + 1) }))}
                                            />
                                            <Input
                                                type="number" min={1900} max={new Date().getFullYear()} placeholder={t('السنة')} aria-label={t('السنة')}
                                                value={internal.data.birth_year}
                                                onChange={(e) => internal.setData('birth_year', e.target.value)}
                                            />
                                        </div>
                                        {birthdayLabel(customer, t) && (
                                            <p className="mt-1.5 text-[12px] text-[#6b7280]">🎂 {birthdayLabel(customer, t)}</p>
                                        )}
                                    </Field>
                                )}

                                {customerFlags.alerts && (
                                    <div className="space-y-3 rounded-xl border border-[var(--ui-border,#e8e8e8)] p-3">
                                        <Toggle
                                            on={internal.data.alert_enabled}
                                            onChange={(v) => internal.setData('alert_enabled', v)}
                                            label="تفعيل التنبيه لهذا العميل"
                                            hint="يُقال للكاشير عند اختياره في نقطة البيع"
                                        />
                                        {internal.data.alert_enabled && (
                                            <>
                                                <Field label="نوع التنبيه" error={internal.errors.alert_type}>
                                                    <div className="grid grid-cols-2 gap-2" role="radiogroup">
                                                        {([
                                                            { value: 'warning', label: 'تحذير' },
                                                            ...(customerFlags.blocking ? [{ value: 'block', label: 'حظر البيع' }] : []),
                                                        ] as { value: 'warning' | 'block'; label: string }[]).map((o) => (
                                                            <button
                                                                key={o.value}
                                                                type="button"
                                                                role="radio"
                                                                aria-checked={internal.data.alert_type === o.value}
                                                                onClick={() => internal.setData('alert_type', o.value)}
                                                                className={
                                                                    internal.data.alert_type === o.value
                                                                        ? o.value === 'block'
                                                                            ? 'h-10 rounded-md border border-[#dc2626] bg-[#fef2f2] text-sm font-medium text-[#b91c1c]'
                                                                            : 'h-10 rounded-md border border-[#d97706] bg-[#fffbeb] text-sm font-medium text-[#92400e]'
                                                                        : 'h-10 rounded-md border border-[#e5e7eb] bg-white text-sm font-medium text-[#4b4b4b] hover:bg-[#f9fafb]'
                                                                }
                                                            >
                                                                {t(o.label)}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </Field>
                                                <Field label="سبب التنبيه" error={internal.errors.alert_reason}>
                                                    <Textarea
                                                        rows={3}
                                                        value={internal.data.alert_reason}
                                                        onChange={(e) => internal.setData('alert_reason', e.target.value)}
                                                        placeholder={t('مثال: سبق عدم استلام طلبات متعددة. تأكد من الدفع قبل التجهيز.')}
                                                    />
                                                </Field>
                                            </>
                                        )}
                                    </div>
                                )}

                                <Button type="submit" size="sm" loading={internal.processing}>
                                    <Save />{t('حفظ المعلومات الداخلية')}
                                </Button>
                            </form>
                        </Card>
                    )}

                    <Card className="p-6">
                        <h3 className="mb-3 text-sm font-bold text-[#111]">{t('ملاحظة داخلية')}</h3>
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
                                                onClick={async () => {
                                                    if (! await ask({ message: 'حذف هذا العنوان؟', danger: true, action: 'حذف' })) return;
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

            <Dialog open={editingProfile} onOpenChange={setEditingProfile}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('تعديل بيانات العميل')}</DialogTitle>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            edit.put(route('admin.customers.update', customer.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingProfile(false),
                            });
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <Field label="اسم العميل" required error={edit.errors.name}>
                            <Input value={edit.data.name} onChange={(e) => edit.setData('name', e.target.value)} required />
                        </Field>
                        {/* يُكتب بيدٍ حين لا يُشتقّ: الاسم اللاتينيّ يُنقل إلى
                            العربية تلقائيًّا، أمّا العربيّ فلا صورة له تُخمَّن */}
                        <Field
                            label="الاسم بالإنجليزية (اختياري)"
                            hint="يظهر عند تشغيل الواجهة بالإنجليزية"
                            error={edit.errors.name_en}
                        >
                            <Input
                                dir="ltr"
                                value={edit.data.name_en}
                                onChange={(e) => edit.setData('name_en', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="رقم الهاتف"
                            hint="نقاط الولاء تتبع الرقم — تغييره ينقلها معه"
                            error={edit.errors.phone}
                        >
                            <Input dir="ltr" value={edit.data.phone} onChange={(e) => edit.setData('phone', e.target.value)} />
                        </Field>
                        <Field label="البريد الإلكتروني" error={edit.errors.email}>
                            <Input type="email" dir="ltr" value={edit.data.email} onChange={(e) => edit.setData('email', e.target.value)} />
                        </Field>
                        <Field label="لغة رسائل واتساب" required hint="بأي لغة تصله إشعارات طلباته وفواتيره" error={edit.errors.language}>
                            <LanguageChoice value={edit.data.language} onChange={(v) => edit.setData('language', v)} />
                        </Field>
                        {branches.length > 0 && (
                            <Field label="الفرع" error={edit.errors.branch_id}>
                                <Select
                                    value={edit.data.branch_id}
                                    onChange={(e) => edit.setData('branch_id', e.target.value)}
                                    options={branches.map((b) => ({ label: b.name, value: String(b.id) }))}
                                    placeholder="بدون فرع"
                                />
                            </Field>
                        )}
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setEditingProfile(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={edit.processing}>{t('حفظ')}</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </AdminLayout>
    );
}
