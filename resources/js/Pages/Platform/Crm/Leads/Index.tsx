import { type FormEvent, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Plus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select, type SelectOption } from '@/Components/Field';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Lead {
    id: number;
    name: string;
    businessName: string | null;
    phone: string;
    stage: string;
    stageLabel: string;
    stageTone: string;
    status: string;
    statusLabel: string;
    source: string;
    sourceLabel: string;
    assignee: string | null;
    plan: string | null;
    lastContactAt: string | null;
    nextFollowUpAt: string | null;
    followUpOverdue: boolean;
    preview: string | null;
    url: string;
}

interface Props {
    leads: Lead[];
    pagination: ServerPagination;
    filters: Record<string, string>;
    counts: Record<string, number>;
    stages: SelectOption[];
    sources: SelectOption[];
    statuses: SelectOption[];
    staff: SelectOption[];
}

/** لونُ الشارة — من نغمةِ المرحلة التي يحسبها الخادم، لا من لوحةٍ ثانية هنا */
const TONE: Record<string, string> = {
    info: 'bg-[#eff6ff] text-[#1d4ed8]',
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    warning: 'bg-[#fffbeb] text-[#b45309]',
    success: 'bg-[#f0fdf4] text-[#15803d]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    gray: 'bg-[#f4f4f5] text-[#52525b]',
};

/**
 * خياراتُ `SelectOption` كما يقرؤها مُرشِّحُ الجدول.
 *
 * `SelectOption.value` يقبل رقمًا، ومُرشِّحُ `DataTable` يكتب القيمة في
 * الرابط فيريدها نصًّا. والتحويلُ صريحٌ هنا لا `as` يُسكت المدقّق: صمتُ
 * المدقّق يُخفي يومًا قيمةً رقميّةً تصل الرابطَ `[object Object]`.
 */
function asFilter(options: SelectOption[]): { label: string; value: string }[] {
    return options.map((o) => ({ label: o.label, value: String(o.value) }));
}

export default function CrmLeadsIndex() {
    const { leads, pagination, filters, counts, stages, sources, statuses, staff } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [adding, setAdding] = useState(false);

    const form = useForm({
        phone: '',
        name: '',
        business_name: '',
        wilayat: '',
        branches_count: '',
        current_system: '',
        source: 'manual',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.crm.leads.store'), {
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const columns: Column<Lead>[] = [
        {
            key: 'name',
            header: 'العميل المحتمل',
            cell: (l) => (
                <SmartLink routeName="super-admin.crm.leads.show" href={l.url} className="block min-w-0">
                    <div className="truncate font-medium text-[#111]">{l.name}</div>
                    <div className="truncate text-[12px] text-[#6b7280]">
                        {l.businessName ? `${l.businessName} · ${l.phone}` : l.phone}
                    </div>
                </SmartLink>
            ),
        },
        {
            key: 'stage',
            header: 'المرحلة',
            cell: (l) => (
                <span className={`inline-flex rounded-full px-2 py-0.5 text-[12px] font-medium ${TONE[l.stageTone] ?? TONE.gray}`}>
                    {l.stageLabel}
                </span>
            ),
        },
        { key: 'source', header: 'المصدر', cell: (l) => <span className="text-[13px] text-[#4b5563]">{l.sourceLabel}</span> },
        {
            key: 'assignee',
            header: 'المسؤول',
            cell: (l) =>
                l.assignee ? (
                    <span className="text-[13px] text-[#4b5563]">{l.assignee}</span>
                ) : (
                    <span className="text-[12px] text-[#9ca3af]">{t('غير معيّن')}</span>
                ),
        },
        {
            key: 'nextFollowUpAt',
            header: 'المتابعة القادمة',
            cell: (l) =>
                l.nextFollowUpAt ? (
                    <span className={`text-[13px] ${l.followUpOverdue ? 'font-medium text-[#b91c1c]' : 'text-[#4b5563]'}`}>
                        {l.followUpOverdue && <AlertTriangle className="me-1 inline size-3.5" />}
                        {l.nextFollowUpAt}
                    </span>
                ) : (
                    <span className="text-[12px] text-[#9ca3af]">—</span>
                ),
        },
        {
            key: 'lastContactAt',
            header: 'آخر تواصل',
            cell: (l) => <span className="text-[13px] text-[#6b7280]">{l.lastContactAt ?? '—'}</span>,
        },
    ];

    const tableFilters: Filter<Lead>[] = [
        {
            label: 'العرض',
            param: 'assignment',
            options: [
                { label: t('الكل'), value: 'all' },
                { label: `${t('محادثاتي')} (${counts.mine})`, value: 'mine' },
                { label: `${t('غير معيّن')} (${counts.unassigned})`, value: 'unassigned' },
                { label: `${t('متابعات متأخرة')} (${counts.overdue})`, value: 'overdue' },
            ],
        },
        { label: 'المرحلة', param: 'stage', options: [{ label: t('كل المراحل'), value: 'all' }, ...asFilter(stages)] },
        { label: 'الحالة', param: 'status', options: [{ label: t('كل الحالات'), value: 'all' }, ...asFilter(statuses)] },
        { label: 'المصدر', param: 'source', options: [{ label: t('كل المصادر'), value: 'all' }, ...asFilter(sources)] },
    ];

    return (
        <PlatformLayout title={t('العملاء المحتملون')}>
            <PageHeader
                title="العملاء المحتملون"
                subtitle={t(':n في الدفتر · :a نشط', { n: String(counts.all), a: String(counts.active) })}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus className="size-4" />
                        {t('عميل محتمل جديد')}
                    </Button>
                }
            />

            <DataTable
                rows={leads}
                columns={columns}
                rowKey={(l) => l.id}
                searchPlaceholder="ابحث بالاسم أو رقم الجوال..."
                filters={tableFilters}
                empty={t('لا عملاء محتملون بهذا الترشيح.')}
                server={{ pagination, params: filters, searchParam: 'q' }}
            />

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('عميل محتمل جديد')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-3 px-5 pb-5">
                        <Field label="رقم الجوال" error={form.errors.phone} required>
                            <Input
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                placeholder="9XXXXXXX"
                                dir="ltr"
                            />
                        </Field>

                        <Field label="الاسم" error={form.errors.name} hint={t('اتركه فارغًا إن لم يُعرف بعد')}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>

                        <Field label="اسم النشاط" error={form.errors.business_name}>
                            <Input
                                value={form.data.business_name}
                                onChange={(e) => form.setData('business_name', e.target.value)}
                            />
                        </Field>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="الولاية" error={form.errors.wilayat}>
                                <Input value={form.data.wilayat} onChange={(e) => form.setData('wilayat', e.target.value)} />
                            </Field>

                            <Field label="عدد الفروع" error={form.errors.branches_count}>
                                <Input
                                    type="number"
                                    min={0}
                                    value={form.data.branches_count}
                                    onChange={(e) => form.setData('branches_count', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="النظام الحالي" error={form.errors.current_system}>
                            <Input
                                value={form.data.current_system}
                                onChange={(e) => form.setData('current_system', e.target.value)}
                            />
                        </Field>

                        <Field label="المصدر" error={form.errors.source} required>
                            <Select
                                value={form.data.source}
                                onChange={(e) => form.setData('source', e.target.value)}
                                options={sources}
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {t('إضافة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* والموظّفون يُقرأون هنا ليُسنَد من الملفّ — لا قائمةَ إسنادٍ في الجدول */}
            <span className="hidden">{staff.length}</span>
        </PlatformLayout>
    );
}
