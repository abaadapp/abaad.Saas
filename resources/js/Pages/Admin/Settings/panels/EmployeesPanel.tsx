import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Briefcase, Lock, LockOpen, Pencil, Plus } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Tabs from '@/Components/Tabs';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import Field from '@/Components/Field';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { initials, money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Employee } from '@/types/models';

export interface JobTitle {
    id: number;
    name: string;
    role: string;
    roleLabel: string;
    description: string | null;
    usage: number;
}

/**
 * مفتاحُ ترتيب عمود «تحقيق الهدف».
 *
 * ومن لا هدف له ليس عند صفر: `-1` تضعه تحت من حقّق صفرًا من هدفٍ مضبوط.
 * ولو خُلطا لَقرأ التاجرُ ترتيبًا يقول إنّ من لم يُكلَّف بهدفٍ مقصّرٌ مثلَ
 * من كُلِّف ولم يبع.
 */
export const targetSortKey = (e: Pick<Employee, 'target_pct'>): number => e.target_pct ?? -1;

const TABS = [
    { key: 'employees', label: 'الموظفون' },
    { key: 'titles', label: 'الوظائف' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

/**
 * جسم قسم «الموظفون» بلا قشرة — يعيش داخل لوحة الإعدادات وفي صفحته المستقلّة.
 *
 * وزرّ الإضافة داخله لا في ترويسة الصفحة: القسم يُفتح في الإعدادات حيث لا
 * ترويسة له، فيلزم أن يحمل أدواته معه. وهو زرّان في الحقيقة — واحدٌ للموظف
 * وآخرٌ للوظيفة — يتبدّلان بتبدّل التبويب الداخلي.
 */
export default function EmployeesPanel({ employees, jobTitles }: { employees: Employee[]; jobTitles: JobTitle[] }) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [tab, setTab] = useState<TabKey>('employees');

    /**
     * نافذة واحدة للإضافة والتعديل: `null` مغلقة، و`{}` إضافة، ووظيفةٌ تعديل.
     * نافذتان متطابقتان بنصوصٍ مختلفة تفترقان عند أوّل تعديل على إحداهما.
     */
    const [titleDialog, setTitleDialog] = useState<JobTitle | Record<string, never> | null>(null);
    const editingTitle = titleDialog && 'id' in titleDialog ? (titleDialog as JobTitle) : null;

    const titleForm = useForm({ name: '', description: '' });

    const openTitle = (x?: JobTitle) => {
        titleForm.clearErrors();
        titleForm.setData({
            name: x?.name ?? '',
            description: x?.description ?? '',
        });
        setTitleDialog(x ?? {});
    };

    const closeTitle = () => {
        setTitleDialog(null);
        titleForm.reset();
        titleForm.clearErrors();
    };

    const submitTitle = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: closeTitle };
        if (editingTitle) titleForm.put(route('admin.jobTitles.update', editingTitle.id), opts);
        else titleForm.post(route('admin.jobTitles.store'), opts);
    };

    const titleColumns: Column<JobTitle>[] = [
        { key: 'name', header: 'الوظيفة', sortable: true, value: (x) => x.name },
        { key: 'description', header: 'الوصف', cell: (x) => x.description || '—' },
        {
            key: 'usage',
            header: 'الاستخدام',
            align: 'end',
            sortable: true,
            value: (x) => x.usage,
            cell: (x) => (
                <span className="tabular-nums">
                    {number(x.usage)} {t('موظف')}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (x) => (
                <RowActions
                    extra={[
                        {
                            label: 'تعديل الوظيفة',
                            icon: <Pencil className="size-4" />,
                            onSelect: () => openTitle(x),
                        },
                    ]}
                    destroy={{
                        url: route('admin.jobTitles.destroy', x.id),
                        message: `حذف الوظيفة «${x.name}»؟`,
                    }}
                />
            ),
        },
    ];

    const columns: Column<Employee>[] = [
        {
            key: 'name',
            header: 'الموظف',
            sortable: true,
            value: (e) => e.name,
            cell: (e) => (
                <div className="flex items-center gap-3">
                    <Avatar className="size-9">
                        {e.avatar && <AvatarImage src={e.avatar} alt="" />}
                        <AvatarFallback>{initials(e.name)}</AvatarFallback>
                    </Avatar>
                    <span className="min-w-0">
                        <SmartLink routeName={'admin.employees.show'} href={route('admin.employees.show', e.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {e.name}
                        </SmartLink>
                        <span className="block text-[11px] text-[#9ca3af]">{e.email}</span>
                    </span>
                </div>
            ),
        },
        { key: 'role', header: 'الدور', sortable: true, value: (e) => e.role },
        { key: 'branch', header: 'الفرع', cell: (e) => e.branch || '—' },
        { key: 'phone', header: 'الهاتف', cell: (e) => e.phone || '—' },
        {
            key: 'sales',
            header: 'المبيعات',
            align: 'end',
            sortable: true,
            value: (e) => e.sales,
            cell: (e) => <span className="tabular-nums">{money(e.sales, currency)}</span>,
        },
        {
            /*
             * نسبةُ تحقيق الهدف — لا مبلغُ المبيعات وعليه علامة `%`.
             *
             * كان يرسم `achieved` (مبيعات الشهر بالريال) ويُلحق به `%`: «٢١٪»
             * لواحدٍ وعشرين ريالًا. والهدفُ الذي يضبطه التاجر في ملفّ الموظف
             * لم يكن يدخل الحساب — انظر `Demo::employees`.
             *
             * ومن لا هدف له تُرسم له شرطة: صفرٌ يُقرأ تقصيرًا، والشرطةُ تقول
             * «لم يُضبط له هدف» — وهو ما يقوله نموذجُه: «اتركه فارغًا لبلا هدف».
             */
            key: 'achieved',
            header: 'تحقيق الهدف',
            align: 'end',
            sortable: true,
            // ومن لا هدف له يقع في ذيل الترتيب لا في صدره — انظر `targetSortKey`
            value: targetSortKey,
            cell: (e) =>
                e.target_pct === null ? (
                    <span className="text-[#9ca3af]">—</span>
                ) : (
                    <span className="tabular-nums">{number(e.target_pct)}%</span>
                ),
        },
        { key: 'status', header: 'الحالة', cell: (e) => <Badge status={e.status} /> },
        {
            /**
             * إجراءات الصف — كانت في القائمة القديمة ولم تُنقل: الطريق الوحيد
             * للتعديل صار المرور بملف الموظف. والتعطيل موجود على الخادم منذ
             * البداية بلا مدخل من القائمة.
             */
            key: 'actions',
            header: '',
            align: 'end',
            cell: (e) => (
                <RowActions
                    show={{ href: route('admin.employees.show', e.id), routeName: 'admin.employees.show' }}
                    edit={{ href: route('admin.employees.edit', e.id), routeName: 'admin.employees.edit' }}
                    extra={[
                        {
                            label: e.status === 'نشط' ? 'تعطيل الحساب' : 'تفعيل الحساب',
                            icon: e.status === 'نشط' ? <Lock className="size-4" /> : <LockOpen className="size-4" />,
                            onSelect: () =>
                                router.post(route('admin.employees.toggle', e.id), {}, { preserveScroll: true }),
                        },
                    ]}
                />
            ),
        },
    ];

    const filters: Filter<Employee>[] = [
        {
            label: 'كل الأدوار',
            options: [...new Set(employees.map((e) => e.role))].map((r) => ({ label: r, value: r })),
            match: (e, value) => e.role === value,
        },
        {
            label: 'كل الحالات',
            asTabs: true,
            options: [
                { label: 'نشط', value: 'نشط' },
                { label: 'متوقف', value: 'متوقف' },
            ],
            match: (e, value) => e.status === value,
        },
    ];

    return (
        <div className="min-w-0">
            <Tabs
                tabs={TABS.map((x) => ({ key: x.key, label: x.label }))}
                current={tab}
                onChange={(k) => setTab(k as typeof tab)}
                className="mb-6"
                trailing={
                    tab === 'employees' ? (
                        <Button asChild className="mb-2">
                            <SmartLink routeName={'admin.employees.create'} href={route('admin.employees.create')}>
                                <Plus />
                                {t('موظف جديد')}
                            </SmartLink>
                        </Button>
                    ) : (
                        <Button onClick={() => openTitle()} className="mb-2">
                            <Plus />
                            {t('إضافة وظيفة')}
                        </Button>
                    )
                }
            />

            {tab === 'employees' ? (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={employees}
                        columns={columns}
                        rowKey={(e) => e.id}
                        searchPlaceholder="ابحث بالاسم أو البريد أو الهاتف…"
                        searchable={(e) => `${e.name} ${e.email} ${e.phone} ${e.role}`}
                        filters={filters}
                        empty="لا يوجد موظفون بعد"
                    />
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={jobTitles}
                        columns={titleColumns}
                        rowKey={(x) => x.id}
                        searchPlaceholder="ابحث باسم الوظيفة…"
                        searchable={(x) => `${x.name} ${x.description ?? ''}`}
                        empty="أضِف أول وظيفة لفريق عملك."
                    />
                </Card>
            )}

            <Dialog open={titleDialog !== null} onOpenChange={(o) => !o && closeTitle()}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t(editingTitle ? 'تعديل الوظيفة' : 'إضافة وظيفة')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submitTitle} className="space-y-4 px-5 pb-5">
                        <Field
                            label="اسم الوظيفة"
                            hint="اسمٌ حرّ يظهر في بطاقة الموظف — «منسّق زهور»، «مشرف وردية»."
                            required
                            error={titleForm.errors.name}
                        >
                            <Input
                                value={titleForm.data.name}
                                onChange={(e) => titleForm.setData('name', e.target.value)}
                                placeholder={t('مثال: منسّق زهور')}
                                required
                            />
                        </Field>

                        <Field label="الوصف" error={titleForm.errors.description}>
                            <Input
                                value={titleForm.data.description}
                                onChange={(e) => titleForm.setData('description', e.target.value)}
                                placeholder={t('وصف مختصر (اختياري)')}
                            />
                        </Field>

                        {/* أثرٌ يمتدّ إلى موظفين قائمين — يُقال قبل الحفظ لا بعده */}
                        {editingTitle && editingTitle.usage > 0 && (
                            <p className="flex items-start gap-2 rounded-[12px] bg-[#fffbeb] px-3 py-2.5 text-[12px] text-[#b45309]">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {t('يحمل هذه الوظيفة')} {number(editingTitle.usage)} {t('موظفًا')} —{' '}
                                    {t('سيُحدَّث اسمها عندهم فورًا.')}
                                </span>
                            </p>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={closeTitle}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={titleForm.processing}>
                                <Briefcase />
                                {t(editingTitle ? 'حفظ التغييرات' : 'إضافة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
