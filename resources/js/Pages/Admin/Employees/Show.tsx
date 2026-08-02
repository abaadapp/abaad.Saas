import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Contact,
    KeyRound,
    Lock,
    LockOpen,
    Mail,
    Pencil,
    ScanBarcode,
    X,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import SmartLink from '@/Components/SmartLink';
import ActivityFeed, { type ActivityItem } from '@/Components/ActivityFeed';
import AreaChart from '@/Components/charts/AreaChart';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Employee } from '@/types/models';

interface Props {
    employee: Employee;
    orderCount: number;
    salesSeries: { labels: string[]; data: number[] };
    permissions: Record<string, boolean>;
    activities: ActivityItem[];
}

export default function EmployeeShow() {
    const { employee, orderCount, salesSeries, permissions, activities, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [tab, setTab] = useState<'sales' | 'activity' | 'permissions'>('sales');
    const [resetting, setResetting] = useState(false);

    const active = employee.status === 'نشط';

    const stats = [
        { label: t('إجمالي المبيعات'), value: m(employee.sales), icon: 'trending-up', color: 'success' },
        { label: t('عدد الطلبات'), value: String(orderCount), icon: 'shopping-bag', color: 'primary' },
        {
            label: t('متوسط الطلب'),
            value: m(orderCount > 0 ? employee.sales / orderCount : 0),
            icon: 'calculator',
            color: 'info',
        },
        { label: t('الفرع'), value: employee.branch || '—', icon: 'store', color: 'warning' },
    ];

    const jobFacts = [
        { label: 'الدور', value: t(employee.role) },
        { label: 'الفرع', value: employee.branch || '—' },
        { label: 'تاريخ الالتحاق', value: employee.joined },
        { label: 'رقم الموظف', value: `#${employee.id}` },
    ];

    return (
        <AdminLayout title={employee.name}>
            <PageHeader
                title="ملف الموظف"
                subtitle={t('عرض بيانات الموظف وأدائه وصلاحياته')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الموظفون', href: route('admin.employees.index') },
                    { label: employee.name },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.employees.index" href={route('admin.employees.index')}>
                                <ArrowRight />
                                {t('رجوع')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink routeName="admin.employees.edit" href={route('admin.employees.edit', employee.id)}>
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-1">
                    <Card className="p-6 text-center">
                        {employee.avatar ? (
                            <img
                                src={employee.avatar}
                                alt={employee.name}
                                className="mx-auto size-24 rounded-full object-cover ring-4 ring-[#f5f3ff]"
                            />
                        ) : (
                            <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f5f3ff] text-[28px] font-bold text-[#6d28d9]">
                                {employee.name.slice(0, 1)}
                            </span>
                        )}
                        <h2 className="mt-4 text-[17px] font-bold text-[#111]">{employee.name}</h2>

                        <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
                            <Badge variant="primary">{t(employee.role)}</Badge>
                            <Badge status={employee.status}>{t(employee.status)}</Badge>
                            {employee.has_pin && (
                                <span
                                    title={t('يدخل نقطة البيع برمز سريع')}
                                    className="inline-flex items-center gap-1 rounded-full bg-[#ecfdf5] px-2.5 py-1 text-[12px] font-medium text-[#047857]"
                                >
                                    <ScanBarcode className="size-3.5" />
                                    {t('رمز دخول سريع مفعّل')}
                                </span>
                            )}
                        </div>

                        <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href={`mailto:${employee.email}`}>
                                    <Mail />
                                    {t('مراسلة')}
                                </a>
                            </Button>

                            <Button
                                variant={active ? 'outline' : 'success'}
                                size="sm"
                                onClick={() =>
                                    router.post(route('admin.employees.toggle', employee.id), {}, { preserveScroll: true })
                                }
                            >
                                {active ? <Lock /> : <LockOpen />}
                                {active ? t('تعطيل الحساب') : t('تفعيل الحساب')}
                            </Button>

                            <Button variant="outline" size="sm" onClick={() => setResetting(true)}>
                                <KeyRound />
                                {t('إعادة تعيين كلمة المرور')}
                            </Button>
                        </div>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-[#111]">
                            <Contact className="size-4 text-[#6d28d9]" />
                            {t('بيانات الاتصال')}
                        </h3>
                        <ul className="space-y-3 text-sm">
                            <li className="flex items-center justify-between gap-3">
                                <span className="text-[#6b7280]">{t('البريد الإلكتروني')}</span>
                                <span dir="ltr" className="truncate text-[#111]">{employee.email}</span>
                            </li>
                            <li className="flex items-center justify-between gap-3">
                                <span className="text-[#6b7280]">{t('الهاتف')}</span>
                                <span dir="ltr" className="text-[#111]">{employee.phone || '—'}</span>
                            </li>
                        </ul>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 text-sm font-bold text-[#111]">{t('معلومات الوظيفة')}</h3>
                        <ul className="space-y-3 text-sm">
                            {jobFacts.map((f) => (
                                <li key={f.label} className="flex items-center justify-between gap-3">
                                    <span className="text-[#6b7280]">{t(f.label)}</span>
                                    <span className="text-[#111]">{f.value}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {stats.map((s, i) => (
                            <StatCard key={s.label} stat={s} index={i} />
                        ))}
                    </div>

                    <Card className="overflow-hidden">
                        <div className="flex items-center gap-1 border-b border-[var(--ui-border,#e8e8e8)] px-5">
                            {([
                                { key: 'sales', label: 'المبيعات' },
                                { key: 'activity', label: 'سجل النشاط' },
                                { key: 'permissions', label: 'الصلاحيات' },
                            ] as const).map(({ key, label }) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    className={cn(
                                        '-mb-px border-b-2 px-4 py-3 text-sm font-medium transition-colors',
                                        tab === key
                                            ? 'border-[#111] text-[#111]'
                                            : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                                    )}
                                >
                                    {t(label)}
                                </button>
                            ))}
                        </div>

                        {tab === 'sales' && (
                            <div className="p-6">
                                <h4 className="mb-4 font-bold text-[#111]">
                                    {t('مبيعات الموظف خلال آخر 12 شهرًا')}
                                </h4>
                                <AreaChart labels={salesSeries.labels} data={salesSeries.data} format={m} />
                            </div>
                        )}

                        {tab === 'activity' && (
                            <div className="p-6">
                                <ActivityFeed items={activities} empty="لا يوجد نشاط لهذا الموظف بعد" />
                            </div>
                        )}

                        {/*
                            للقراءة فقط عن قصد. كانت هنا مربّعات اختيار تُؤشَّر
                            ولا تُحفظ ولا تُفرض — فيظن التاجر أنه منع الكاشير من
                            إلغاء الطلبات وهو لم يمنعه. أمان زائف حول المال أسوأ
                            من لا شيء. ما يُفرض فعلًا هو الدور عبر middleware،
                            فنعرضه كما هو ونوجّه التغيير إلى مكانه الحقيقي.
                        */}
                        {tab === 'permissions' && (
                            <div className="p-6">
                                <p className="mb-4 text-sm text-[#6b7280]">
                                    {t('ما يسمح به دور «:role» — يُفرض على الخادم.', { role: t(employee.role) })}
                                </p>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {Object.entries(permissions).map(([name, granted]) => (
                                        <div
                                            key={name}
                                            className="flex items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3"
                                        >
                                            <span className="text-sm font-medium text-[#4b4b4b]">{t(name)}</span>
                                            {granted ? (
                                                <span className="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-[#047857]">
                                                    <Check className="size-4" />
                                                    {t('مسموح')}
                                                </span>
                                            ) : (
                                                <span className="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-[#9ca3af]">
                                                    <X className="size-4" />
                                                    {t('ممنوع')}
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                                <p className="mt-5 text-[12px] text-[#9ca3af]">
                                    {t('لتغيير الصلاحيات، غيّر دور الموظف من صفحة التعديل.')}
                                </p>
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            <Dialog open={resetting} onOpenChange={setResetting}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('إعادة تعيين كلمة المرور')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">{t('توليد كلمة مرور مؤقتة جديدة؟')}</p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setResetting(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                onClick={() =>
                                    router.post(
                                        route('admin.employees.resetPassword', employee.id),
                                        {},
                                        { onFinish: () => setResetting(false) },
                                    )
                                }
                            >
                                {t('توليد')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
