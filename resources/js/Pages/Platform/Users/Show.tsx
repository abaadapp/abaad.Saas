import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    Check,
    Contact,
    Info,
    Lock,
    Mail,
    MoreVertical,
    Pencil,
    Trash2,
    Unlock,
    X,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import ActivityFeed, { type ActivityItem } from '@/Components/ActivityFeed';
import Tabs from '@/Components/Tabs';
import Field, { Select, type SelectOption } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
    business_id: number | null;
    name: string;
    email: string;
    phone: string | null;
    business: string;
    business_plan: string | null;
    role: string;
    role_key: string | null;
    status: string;
    last_login: string;
    created: string;
}

interface Props {
    user: PlatformUser;
    activities: ActivityItem[];
    roles: SelectOption[];
    businesses: SelectOption[];
    permissions: { label: string; granted: boolean }[];
    /** صلاحياته مخصَّصة يدويًّا — فلا تتبع دورَه */
    permissions_manual: boolean;
}

const TABS = [
    { key: 'activities', label: 'النشاطات' },
    { key: 'permissions', label: 'الصلاحيات' },
];

export default function UserShow() {
    const { user, activities, roles, businesses, permissions, permissions_manual: manual } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('activities');
    const [editing, setEditing] = useState(false);
    const active = user.status === 'نشط';

    /* ما يمنع هذا الحساب من العمل — لا ما تقوله شارتُه */
    const blocked = !user.email
        ? t('هذا الحساب بلا بريد إلكتروني — لا يستطيع تسجيل الدخول. أضِف بريدًا من زرّ «تعديل».')
        : user.role_key !== 'super_admin' && !user.business_id
          ? t('هذا الحساب غير مربوط بنشاط تجاري — يدخل إلى نظام فارغ. اربطه من زرّ «تعديل».')
          : null;

    const contact = [
        { label: 'البريد الإلكتروني', value: user.email },
        { label: 'الهاتف', value: user.phone || '—' },
    ];

    const account = [
        { label: 'آخر تسجيل دخول', value: user.last_login },
        { label: 'تاريخ الإنشاء', value: user.created },
        { label: 'رقم المستخدم', value: `#${user.id}` },
    ];

    return (
        <PlatformLayout title={user.name}>
            <PageHeader
                title="ملف المستخدم"
                subtitle={t('عرض تفاصيل المستخدم وصلاحياته ونشاطه')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="super-admin.users.index" href={route('super-admin.users.index')}>
                                <ArrowRight />
                                {t('رجوع')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setEditing(true)}>
                            <Pencil />
                            {t('تعديل')}
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label={t('خيارات')}>
                                    <MoreVertical />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                {/* حذفٌ ناعم: يُستعاد من مرشّح «المحذوفون» في قائمة المستخدمين */}
                                <DropdownMenuItem
                                    destructive
                                    onSelect={() => {
                                        if (!confirm(t('حذف هذا المستخدم؟ يمكن استعادته من مرشّح «المحذوفون».')))
                                            return;
                                        router.delete(route('super-admin.users.destroy', user.id));
                                    }}
                                >
                                    <Trash2 />
                                    {t('حذف المستخدم')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                }
            />

            {/*
                شارةٌ خضراء فوق حسابٍ لا يستطيع الدخول تطمئن بلا وجه حقّ.
                فيُقال السببُ صراحةً، ويُقال ما يُصلحه.
            */}
            {blocked && (
                <div className="mb-6 flex items-start gap-3 rounded-[12px] border border-[#fcd34d] bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>{blocked}</span>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-1">
                    <Card className="p-6 text-center">
                        <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f2f2f0] text-[24px] font-bold text-[#4b4b4b]">
                            {initials(user.name)}
                        </span>
                        <h2 className="mt-4 text-[18px] font-bold text-[#111]">{user.name}</h2>
                        <div className="mt-2 flex items-center justify-center gap-2">
                            <Badge variant="info">{t(user.role)}</Badge>
                            <Badge status={user.status} />
                        </div>
                        <div className="mt-5 flex items-center justify-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href={`mailto:${user.email}`}>
                                    <Mail />
                                    {t('مراسلة')}
                                </a>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(route('super-admin.users.toggle', user.id), {}, { preserveScroll: true })
                                }
                            >
                                {active ? <Lock /> : <Unlock />}
                                {t(active ? 'تعطيل' : 'تفعيل')}
                            </Button>
                        </div>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-[#111]">
                            <Contact className="size-4 text-[#6d28d9]" />
                            {t('بيانات الاتصال')}
                        </h3>
                        <ul className="space-y-3 text-sm">
                            {contact.map((c) => (
                                <li key={c.label} className="flex items-center justify-between gap-3">
                                    <span className="text-[#6b7280]">{t(c.label)}</span>
                                    <span dir="ltr" className="truncate text-[#111]">
                                        {c.value}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-[#111]">
                            <Building2 className="size-4 text-[#6d28d9]" />
                            {t('الشركة المرتبطة')}
                        </h3>
                        <div className="flex items-center gap-3">
                            <span className="flex size-10 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                                <Building2 className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <p className="truncate font-medium text-[#111]">{user.business}</p>
                                {/* باقة الشركة الفعلية — كانت «الباقة الاحترافية» نصًّا ثابتًا */}
                                <p className="text-[12px] text-[#9ca3af]">
                                    {user.business_plan ? `${t('باقة')} ${t(user.business_plan)}` : t('بلا باقة')}
                                </p>
                            </div>
                        </div>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-[#111]">
                            <Info className="size-4 text-[#6d28d9]" />
                            {t('معلومات الحساب')}
                        </h3>
                        <ul className="space-y-3 text-sm">
                            {account.map((a) => (
                                <li key={a.label} className="flex items-center justify-between gap-3">
                                    <span className="text-[#6b7280]">{t(a.label)}</span>
                                    <span dir="ltr" className="text-[#111]">
                                        {a.value}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>

                <div className="lg:col-span-2">
                    <Card className="overflow-hidden">
                        {/* داخل بطاقة: الحشو يُبعد التبويبات عن حدّها */}
                        <Tabs tabs={TABS} current={tab} onChange={setTab} className="px-4" />

                        {tab === 'activities' && (
                            <div className="p-6">
                                <ActivityFeed items={activities} empty="لا يوجد نشاط لهذا المستخدم بعد" />
                            </div>
                        )}

                        {tab === 'permissions' && (
                            <div className="p-6">
                                {/* للعرض فقط، ومن `User::allows()` نفسها لا من الدور:
                                    الصلاحيات المخصَّصة يدويًّا تُلغي خريطة الدور، فكانت
                                    الشاشة تعرض عكس واقع صاحبها في كل خانة. */}
                                <p className="mb-4 text-sm text-[#6b7280]">
                                    {t(
                                        manual
                                            ? 'صلاحيات هذا المستخدم مخصَّصة يدويًّا من شاشة موظفي النشاط — فهي لا تتبع دوره. وهذه هي المفروضة فعلًا.'
                                            : 'الصلاحيات تتبع دور المستخدم. لتغييرها غيّر الدور من زر «تعديل».',
                                    )}
                                </p>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {permissions.map((p) => (
                                        <div
                                            key={p.label}
                                            className="flex items-center justify-between rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3"
                                        >
                                            <span className="text-sm font-medium text-[#374151]">{p.label}</span>
                                            {p.granted ? (
                                                <span className="flex items-center gap-1 text-[12px] font-medium text-[#047857]">
                                                    <Check className="size-4" />
                                                    {t('مسموح')}
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1 text-[12px] font-medium text-[#9ca3af]">
                                                    <X className="size-4" />
                                                    {t('ممنوع')}
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            {editing && (
                <EditUserDialog
                    user={user}
                    roles={roles}
                    businesses={businesses}
                    onClose={() => setEditing(false)}
                />
            )}
        </PlatformLayout>
    );
}

function EditUserDialog({
    user,
    roles,
    businesses,
    onClose,
}: {
    user: PlatformUser;
    roles: SelectOption[];
    businesses: SelectOption[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const form = useForm({
        name: user.name,
        email: user.email,
        phone: user.phone ?? '',
        role: user.role_key ?? '',
        business_id: user.business_id ? String(user.business_id) : '',
        password: '',
    });
    const isPlatform = form.data.role === 'super_admin';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('super-admin.users.update', user.id), { onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('تعديل بيانات المستخدم')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field label="الاسم" required error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="البريد" required error={form.errors.email}>
                            <Input
                                type="email"
                                dir="ltr"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
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

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="الدور" required error={form.errors.role}>
                            {/* القيمة مفتاح الدور لا تسميته المعروضة — القالب كان يمرّر التسمية أحيانًا */}
                            <Select
                                value={form.data.role}
                                onChange={(e) => form.setData('role', e.target.value)}
                                options={roles}
                                placeholder="اختر الدور…"
                                required
                            />
                        </Field>
                        {/*
                            ربطُ الحساب بمتجره: لم يكن في هذه النافذة أصلًا.
                            فمن أُدخل تحت المتجر الخطأ — أو بلا متجر — لا يُصلح
                            من الشاشة مهما فُتحت.
                        */}
                        <Field label="النشاط التجاري" required={!isPlatform} error={form.errors.business_id}>
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

                    {/* كلمة المرور: الفارغ يعني «لا تغيّرها». ولولا الحقل لما
                        كان لمن فقد كلمته مخرجٌ من هذه الشاشة أصلًا. */}
                    <Field
                        label="كلمة مرور جديدة"
                        hint="اتركها فارغة لإبقاء كلمته كما هي — ثمانية أحرف على الأقل"
                        error={form.errors.password}
                    >
                        <Input
                            type="text"
                            dir="ltr"
                            autoComplete="new-password"
                            placeholder="••••••••"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </Field>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('حفظ')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
