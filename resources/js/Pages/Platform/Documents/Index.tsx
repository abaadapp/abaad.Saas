import { useState, type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { FilePlus2, FileSignature, LayoutTemplate } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { STATE_VARIANT, type DocumentRow } from '@/lib/platformDocuments';
import type { PageProps } from '@/types';

interface Template {
    id: number;
    name: string;
    service: string | null;
    includes_plan: boolean;
    includes_amount: boolean;
}

interface PlanOption {
    id: number;
    name: string;
    prices: Record<string, string>;
}

interface BusinessOption {
    id: number;
    name: string;
    plan_id: number | null;
    owner: string | null;
    phone: string | null;
}

interface Props {
    documents: DocumentRow[];
    pagination: ServerPagination;
    filters: { state: string; q: string };
    counts: { sent: number; signed: number };
    templates: Template[];
    plans: PlanOption[];
    cycles: { value: string; label: string }[];
    businesses: BusinessOption[];
    days: number;
}

export default function DocumentsIndex() {
    const { documents, pagination, filters, counts, templates, plans, cycles, businesses, days } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [creating, setCreating] = useState(false);

    const columns: Column<DocumentRow>[] = [
        {
            key: 'number',
            header: 'المستند',
            cell: (d) => (
                <SmartLink routeName="super-admin.documents.show" href={d.url} className="block min-w-0">
                    <div className="truncate font-medium text-[#111]">{d.title}</div>
                    <div dir="ltr" className="truncate text-end font-mono text-[12px] text-[#6b7280]">
                        {d.number}
                    </div>
                </SmartLink>
            ),
        },
        {
            key: 'client',
            header: 'العميل',
            cell: (d) => (
                <div className="min-w-0">
                    <div className="truncate text-[#111]">{d.client_biz || d.client_name || '—'}</div>
                    {d.client_biz && d.client_name && (
                        <div className="truncate text-[12px] text-[#6b7280]">{d.client_name}</div>
                    )}
                </div>
            ),
        },
        { key: 'service', header: 'الخدمة', cell: (d) => d.service || '—' },
        {
            key: 'state',
            header: 'الحالة',
            cell: (d) => <Badge variant={STATE_VARIANT[d.state]}>{t(d.state_label)}</Badge>,
        },
        { key: 'created_at', header: 'أُصدر', cell: (d) => <span dir="ltr">{d.created_at}</span> },
        {
            key: 'signed_at',
            header: 'وُقِّع',
            cell: (d) => <span dir="ltr">{d.state === 'signed' ? d.signed_at : '—'}</span>,
        },
    ];

    const tableFilters: Filter<DocumentRow>[] = [
        {
            label: 'كل المستندات',
            asTabs: true,
            param: 'state',
            options: [
                { label: 'بانتظار التوقيع', value: 'sent' },
                { label: 'موقّع', value: 'signed' },
                { label: 'انتهت صلاحية الرابط', value: 'expired' },
                { label: 'ملغى', value: 'cancelled' },
            ],
        },
    ];

    return (
        <PlatformLayout title="المستندات">
            <PageHeader
                title="المستندات"
                subtitle={t('عقود العملاء ومستندات الخدمات — تُرسل برابط ويوقّعها العميل من هاتفه · :sent بانتظار التوقيع · :signed موقّع', {
                    sent: String(counts.sent),
                    signed: String(counts.signed),
                })}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="super-admin.documents.templates" href={route('super-admin.documents.templates')}>
                                <LayoutTemplate />
                                {t('القوالب')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setCreating(true)} disabled={templates.length === 0}>
                            <FilePlus2 />
                            {t('مستند جديد')}
                        </Button>
                    </>
                }
            />

            <DataTable
                rows={documents}
                columns={columns}
                rowKey={(d) => d.id}
                filters={tableFilters}
                searchPlaceholder="ابحث برقم المستند أو اسم العميل أو هاتفه…"
                empty={
                    <div className="flex flex-col items-center gap-2 py-6 text-[#6b7280]">
                        <FileSignature className="size-6" />
                        {t('لا مستندات بعد — أصدر أول مستند لعميل')}
                    </div>
                }
                server={{ pagination, params: filters }}
            />

            {creating && (
                <NewDocumentDialog
                    templates={templates}
                    plans={plans}
                    cycles={cycles}
                    businesses={businesses}
                    days={days}
                    onClose={() => setCreating(false)}
                />
            )}
        </PlatformLayout>
    );
}

function NewDocumentDialog({
    templates,
    plans,
    cycles,
    businesses,
    days,
    onClose,
}: {
    templates: Template[];
    plans: PlanOption[];
    cycles: { value: string; label: string }[];
    businesses: BusinessOption[];
    days: number;
    onClose: () => void;
}) {
    const t = useTranslate();
    const [source, setSource] = useState<'business' | 'new'>('business');
    const form = useForm({
        template_id: String(templates[0]?.id ?? ''),
        business_id: '',
        client: { name: '', biz: '', phone: '', email: '' },
        plan_id: '',
        cycle: 'monthly',
        amount: '',
        amount_label: '',
        days: String(days),
        notes: '',
    });
    const template = templates.find((x) => String(x.id) === form.data.template_id);
    const plan = plans.find((p) => String(p.id) === form.data.plan_id);
    const errors = form.errors as Record<string, string | undefined>;

    const pickBusiness = (id: string) => {
        const b = businesses.find((x) => String(x.id) === id);
        form.setData((d) => ({ ...d, business_id: id, plan_id: b?.plan_id ? String(b.plan_id) : d.plan_id }));
    };
    const setClient = (k: keyof typeof form.data.client, v: string) =>
        form.setData('client', { ...form.data.client, [k]: v });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, business_id: source === 'business' ? d.business_id : '' }));
        form.post(route('super-admin.documents.store'), { onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('مستند جديد للتوقيع')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="new-document">
                    <Field label="نوع المستند" required error={errors.template_id}>
                        <Select
                            value={form.data.template_id}
                            onChange={(e) => form.setData('template_id', e.target.value)}
                            options={templates.map((x) => ({
                                label: x.service ? `${x.name} · ${x.service}` : x.name,
                                value: String(x.id),
                            }))}
                        />
                    </Field>

                    <div className="flex gap-1 rounded-[10px] bg-[#f4f4f5] p-1 text-[13px]" role="tablist">
                        {(['business', 'new'] as const).map((s) => (
                            <button
                                key={s}
                                type="button"
                                role="tab"
                                aria-selected={source === s}
                                onClick={() => setSource(s)}
                                className={`flex-1 rounded-[8px] px-3 py-1.5 ${source === s ? 'bg-white font-medium shadow-sm' : 'text-[#6b7280]'}`}
                            >
                                {s === 'business' ? t('نشاط مسجّل') : t('عميل جديد')}
                            </button>
                        ))}
                    </div>

                    {source === 'business' ? (
                        <Field
                            label="النشاط"
                            required
                            hint="تُعبّأ بيانات النشاط في المستند، ويكمل العميل الباقي عند التوقيع"
                            error={errors.business_id}
                        >
                            <Select
                                value={form.data.business_id}
                                onChange={(e) => pickBusiness(e.target.value)}
                                placeholder="اختر النشاط"
                                options={businesses.map((b) => ({ label: b.name, value: String(b.id) }))}
                            />
                        </Field>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="اسم العميل" error={errors['client.name']}>
                                <Input value={form.data.client.name} onChange={(e) => setClient('name', e.target.value)} />
                            </Field>
                            <Field label="اسم النشاط" error={errors['client.biz']}>
                                <Input value={form.data.client.biz} onChange={(e) => setClient('biz', e.target.value)} />
                            </Field>
                            <Field label="الهاتف" error={errors['client.phone']}>
                                <Input
                                    dir="ltr"
                                    value={form.data.client.phone}
                                    onChange={(e) => setClient('phone', e.target.value)}
                                    placeholder="+968"
                                />
                            </Field>
                            <Field label="البريد الإلكتروني" error={errors['client.email']}>
                                <Input
                                    dir="ltr"
                                    type="email"
                                    value={form.data.client.email}
                                    onChange={(e) => setClient('email', e.target.value)}
                                />
                            </Field>
                        </div>
                    )}

                    {template?.includes_plan && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="الباقة" required error={errors.plan_id}>
                                <Select
                                    value={form.data.plan_id}
                                    onChange={(e) => form.setData('plan_id', e.target.value)}
                                    placeholder="اختر الباقة"
                                    options={plans.map((p) => ({ label: p.name, value: String(p.id) }))}
                                />
                            </Field>
                            <Field
                                label="دورة الاشتراك"
                                hint={plan ? t(':price ر.ع', { price: plan.prices[form.data.cycle] ?? '' }) : undefined}
                                error={errors.cycle}
                            >
                                <Select
                                    value={form.data.cycle}
                                    onChange={(e) => form.setData('cycle', e.target.value)}
                                    options={cycles.map((c) => ({ label: c.label, value: c.value }))}
                                />
                            </Field>
                        </div>
                    )}

                    {template?.includes_amount && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="البيان" hint="مثال: تخصيص الموقع الإلكتروني" error={errors.amount_label}>
                                <Input
                                    value={form.data.amount_label}
                                    onChange={(e) => form.setData('amount_label', e.target.value)}
                                    placeholder={template.name}
                                />
                            </Field>
                            <Field label="المبلغ (ر.ع)" required error={errors.amount}>
                                <Input
                                    dir="ltr"
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    onChange={(e) => form.setData('amount', e.target.value)}
                                />
                            </Field>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field label="صلاحية الرابط (أيام)" error={errors.days}>
                            <Input
                                dir="ltr"
                                type="number"
                                min={1}
                                max={90}
                                value={form.data.days}
                                onChange={(e) => form.setData('days', e.target.value)}
                            />
                        </Field>
                        <Field label="ملاحظة داخلية" hint="لا تظهر للعميل" error={errors.notes}>
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                    </div>

                    {errors.document && <p className="text-[13px] text-[#b91c1c]">{errors.document}</p>}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('إصدار المستند وإنشاء الرابط')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
