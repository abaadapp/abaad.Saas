import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, MoreVertical, RotateCcw, Trash2, UserPlus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select, type SelectOption } from '@/Components/Field';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { initials } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface PlatformUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    business: string;
    role: string;
    status: string;
    last_login: string;
    deleted: boolean;
    /** ما يمنع الحساب من العمل فعلًا — null إن كان سليمًا */
    blocked: string | null;
}

interface Props {
    users: PlatformUser[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    /** أعمدة يرتّبها الخادم — مصدرها `Sort::keys` في المتحكّم */
    sorts: string[];
    roles: SelectOption[];
    businesses: SelectOption[];
}

export default function UsersIndex() {
    const { users, pagination, filters, sorts, roles, businesses } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [adding, setAdding] = useState(false);

    const columns: Column<PlatformUser>[] = [
        {
            key: 'name',
            header: 'المستخدم',
            cell: (u) => (
                <div className="flex items-center gap-3">
                    {/* أحرف الاسم بدل صورة من خدمة خارجية — لا طلب شبكة لكل صف */}
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#f2f2f0] text-[12px] font-medium text-[#4b4b4b]">
                        {initials(u.name)}
                    </span>
                    <div className="min-w-0">
                        <SmartLink
                            routeName="super-admin.users.show"
                            href={route('super-admin.users.show', u.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {u.name}
                        </SmartLink>
                        {/* شارةٌ خضراء فوق حسابٍ لا يستطيع الدخول تطمئن بلا وجه حقّ */}
                        {u.blocked && (
                            <span className="mt-0.5 flex items-center gap-1 text-[12px] text-[#b45309]">
                                <AlertTriangle className="size-3.5 shrink-0" />
                                {t(u.blocked)}
                            </span>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'email',
            header: 'البريد الإلكتروني',
            cell: (u) => (
                <span dir="ltr" className="text-[#4b4b4b]">
                    {u.email}
                </span>
            ),
        },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (u) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {u.phone || '—'}
                </span>
            ),
        },
        { key: 'business', header: 'الشركة', cell: (u) => u.business },
        { key: 'role', header: 'الدور', cell: (u) => <Badge variant="info">{t(u.role)}</Badge> },
        {
            key: 'status',
            header: 'الحالة',
            cell: (u) => (u.deleted ? <Badge variant="danger">{t('محذوف')}</Badge> : <Badge status={u.status} />),
        },
        {
            key: 'last_login',
            header: 'آخر تسجيل دخول',
            cell: (u) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {u.last_login}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (u) =>
                u.deleted ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(route('super-admin.users.restore', u.id), {}, { preserveScroll: true })
                        }
                    >
                        <RotateCcw />
                        {t('استعادة')}
                    </Button>
                ) : (
                    <div className="flex items-center justify-end gap-1">
                        <Button variant="outline" size="sm" asChild>
                            <SmartLink routeName="super-admin.users.show" href={route('super-admin.users.show', u.id)}>
                                {t('عرض')}
                            </SmartLink>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label={t('خيارات')}>
                                    <MoreVertical />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    destructive
                                    onSelect={() => {
                                        if (!confirm(t('حذف هذا المستخدم؟ يمكن استعادته من مرشّح «المحذوفون».'))) return;
                                        router.delete(route('super-admin.users.destroy', u.id), {
                                            preserveScroll: true,
                                        });
                                    }}
                                >
                                    <Trash2 />
                                    {t('حذف المستخدم')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                ),
        },
    ];

    const tableFilters: Filter<PlatformUser>[] = [
        { label: 'كل الأدوار', param: 'role', options: roles.map((r) => ({ label: r.label, value: String(r.value) })) },
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: [
                { label: 'نشط', value: 'نشط' },
                { label: 'موقوف', value: 'موقوف' },
                // بابُ الاسترداد: بلا هذا المرشّح يكون الحذفُ الناعم اختفاءً أبديًّا
                { label: 'محذوف', value: 'محذوف' },
            ],
        },
    ];

    return (
        <PlatformLayout title="المستخدمون">
            <PageHeader
                title="المستخدمون"
                subtitle={t('إدارة مستخدمي المنصة وأدوارهم وصلاحياتهم')}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <UserPlus />
                        {t('إضافة مستخدم')}
                    </Button>
                }
            />

            <DataTable
                rows={users}
                columns={columns}
                rowKey={(u) => u.id}
                filters={tableFilters}
                searchPlaceholder="ابحث بالاسم أو البريد…"
                empty={t('لا يوجد مستخدمون بعد')}
                server={{ pagination, params: filters, sorts }}
            />

            {adding && <AddUserDialog roles={roles} businesses={businesses} onClose={() => setAdding(false)} />}
        </PlatformLayout>
    );
}

function AddUserDialog({
    roles,
    businesses,
    onClose,
}: {
    roles: SelectOption[];
    businesses: SelectOption[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const form = useForm({ name: '', phone: '', email: '', role: '', business_id: '', password: '' });
    const isPlatform = form.data.role === 'super_admin';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.users.store'), { onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('إضافة مستخدم جديد')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="الاسم" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                        </Field>
                        <Field label="الهاتف" error={form.errors.phone}>
                            <Input
                                dir="ltr"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="البريد الإلكتروني" required error={form.errors.email}>
                        <Input
                            type="email"
                            dir="ltr"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            required
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="الدور" required error={form.errors.role}>
                            <Select
                                value={form.data.role}
                                onChange={(e) => form.setData('role', e.target.value)}
                                options={roles}
                                placeholder="اختر الدور…"
                                required
                            />
                        </Field>
                        {/* لازمٌ لكل دورٍ يعمل داخل متجر — بلا متجرٍ يدخل صاحبه إلى نظامٍ فارغ */}
                        <Field
                            label="النشاط التجاري"
                            required={!isPlatform}
                            error={form.errors.business_id}
                        >
                            <Select
                                value={isPlatform ? '' : form.data.business_id}
                                onChange={(e) => form.setData('business_id', e.target.value)}
                                options={businesses}
                                placeholder={isPlatform ? '— المنصة —' : 'اختر النشاط…'}
                                disabled={isPlatform}
                                required={!isPlatform}
                            />
                        </Field>
                    </div>

                    {/* كانت اختيارية وتُصبح `password` حرفيًّا إن تُركت — في نافذةٍ تُنشئ مدير منصّة */}
                    <Field
                        label="كلمة المرور"
                        required
                        hint="ثمانية أحرف على الأقل"
                        error={form.errors.password}
                    >
                        <Input
                            type="text"
                            dir="ltr"
                            autoComplete="new-password"
                            minLength={8}
                            required
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </Field>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('إضافة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
