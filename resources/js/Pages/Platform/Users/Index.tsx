import { type FormEvent, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select, type SelectOption } from '@/Components/Field';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
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
}

interface Props {
    users: PlatformUser[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    roles: SelectOption[];
    businesses: SelectOption[];
}

export default function UsersIndex() {
    const { users, pagination, filters, roles, businesses } = usePage<PageProps<Props>>().props;
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
                    <SmartLink
                        routeName="super-admin.users.show"
                        href={route('super-admin.users.show', u.id)}
                        className="min-w-0 truncate font-medium hover:underline"
                    >
                        {u.name}
                    </SmartLink>
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
        { key: 'status', header: 'الحالة', cell: (u) => <Badge status={u.status} /> },
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
            cell: (u) => (
                <Button variant="outline" size="sm" asChild>
                    <SmartLink routeName="super-admin.users.show" href={route('super-admin.users.show', u.id)}>
                        {t('عرض')}
                    </SmartLink>
                </Button>
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
                server={{ pagination, params: filters }}
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
                        <Field label="النشاط التجاري" error={form.errors.business_id}>
                            <Select
                                value={form.data.business_id}
                                onChange={(e) => form.setData('business_id', e.target.value)}
                                options={businesses}
                                placeholder="— المنصة —"
                            />
                        </Field>
                    </div>

                    <Field label="كلمة المرور" hint="تُترك فارغة لتكون: password" error={form.errors.password}>
                        <Input
                            type="text"
                            dir="ltr"
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
