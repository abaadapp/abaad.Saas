import { useState, type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Building2, FileDown, FilePlus2, FileSignature, LayoutTemplate, RefreshCcw, X } from 'lucide-react';
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
import { DocumentFormView, TeamInputs } from '@/Components/DocumentFormView';
import {
    STATE_VARIANT,
    addMonths,
    formInputs,
    useLiveReload,
    type DocumentForm,
    type DocumentRow,
    type FormValues,
} from '@/lib/platformDocuments';
import type { PageProps } from '@/types';

interface Template {
    id: number;
    name: string;
    service: string | null;
    title: string;
    terms: { h: string; b: string }[];
    /** اتفاقيةٌ بنموذجها كما صمّمها المالك — وإلّا فبنودٌ */
    form: DocumentForm | null;
    includes_plan: boolean;
    includes_amount: boolean;
    default_amount: string | null;
    default_amount_label: string | null;
    /** fixed: الرسومُ كما اعتُمدت ويذكرها النصّ · min: تبدأ منها · null: يكتبها المدير */
    amount_rule: 'fixed' | 'min' | null;
}

interface PlanOption {
    id: number;
    name: string;
    prices: Record<string, string>;
}

interface CycleOption {
    value: string;
    label: string;
    months: number;
    duration: string;
}

interface BusinessOption {
    id: number;
    name: string;
    plan_id: number | null;
    owner: string | null;
    phone: string | null;
    starts_on: string;
}

/** قالبٌ لا يُصدر منه بعد — يُعرض في القائمة معطَّلًا بسببه */
interface WaitingTemplate {
    id: number;
    name: string;
    service: string | null;
    reason: string;
}

/** الشركةُ التي فُتحت مستنداتُها من ملفّها (`?business=`) */
interface Company {
    id: number;
    name: string;
    url: string;
}

/** عقدٌ موقَّعٌ يُفتح منه عقدُ تجديد (`?renew=`) */
interface Renewal {
    id: number;
    number: string;
    client: string | null;
    phone: string;
    template_id: number | null;
    plan_id: number | null;
    cycle: string;
    starts_on: string | null;
}

interface Props {
    documents: DocumentRow[];
    pagination: ServerPagination;
    filters: { state: string; q: string; business?: string };
    counts: { sent: number; signed: number };
    templates: Template[];
    waiting: WaitingTemplate[];
    company: Company | null;
    /** «مستند جديد» من ملفّ الشركة: النافذةُ تُفتح والشركةُ مختارة */
    opening: boolean;
    plans: PlanOption[];
    cycles: CycleOption[];
    businesses: BusinessOption[];
    today: string;
    days: number;
    renew: Renewal | null;
}

export default function DocumentsIndex() {
    const {
        documents,
        pagination,
        filters,
        counts,
        templates,
        waiting = [],
        company = null,
        opening = false,
        plans,
        cycles,
        businesses,
        today,
        days,
        renew,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [creating, setCreating] = useState(renew !== null || opening);

    // ما ينتظر التوقيع يتحدّث وحده: توقيعُ العميل يظهر هنا والشاشةُ مفتوحة
    useLiveReload(['documents', 'counts', 'pagination'], counts.sent > 0);

    const columns: Column<DocumentRow>[] = [
        {
            key: 'number',
            header: 'المستند',
            cell: (d) => (
                <SmartLink routeName="super-admin.documents.show" href={d.url} className="block min-w-0">
                    <div className="flex items-center gap-1.5">
                        <span className="truncate font-medium text-[#111]">{d.title}</span>
                        {d.renewal && <Badge variant="info">{t('تجديد')}</Badge>}
                    </div>
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
        {
            key: 'service',
            header: 'الباقة أو الخدمة',
            cell: (d) => (
                <div className="min-w-0">
                    <div className="truncate">{d.plan_name || d.service || '—'}</div>
                    {d.cycle_label && <div className="text-[12px] text-[#6b7280]">{t(d.cycle_label)}</div>}
                </div>
            ),
        },
        {
            key: 'value',
            header: 'القيمة',
            cell: (d) =>
                d.value ? (
                    <span className="whitespace-nowrap">
                        <span dir="ltr">{d.value}</span> <span className="text-[12px] text-[#6b7280]">{t('ر.ع')}</span>
                    </span>
                ) : (
                    '—'
                ),
        },
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
        {
            key: 'shared',
            header: 'المشاركة',
            cell: (d) =>
                d.shared ? (
                    <div className="min-w-0 text-[12px]">
                        <div>{t(d.shared)}</div>
                        <div dir="ltr" className="text-end text-[#6b7280]">{d.shared_at}</div>
                    </div>
                ) : (
                    <span className="text-[#9ca3af]">—</span>
                ),
        },
        {
            key: 'actions',
            header: '',
            cell: (d) => (
                <div className="flex items-center justify-end gap-1">
                    <Button variant="ghost" size="sm" asChild>
                        <SmartLink routeName="super-admin.documents.show" href={d.url}>
                            {t('عرض')}
                        </SmartLink>
                    </Button>
                    <Button variant="ghost" size="icon-sm" asChild>
                        <a href={d.pdf_url} target="_blank" rel="noreferrer" aria-label={t('تحميل PDF')} title={t('تحميل PDF')}>
                            <FileDown />
                        </a>
                    </Button>
                </div>
            ),
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
                { label: 'عقود التجديد', value: 'renewal' },
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

            {company && (
                <div className="mb-3 flex flex-wrap items-center gap-2 text-[13px]" data-testid="company-filter">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[#f4f4f5] px-3 py-1 text-[#111]">
                        <Building2 className="size-3.5" />
                        {t('مستندات :name', { name: company.name })}
                        <SmartLink
                            routeName="super-admin.documents.index"
                            href={route('super-admin.documents.index')}
                            className="text-[#6b7280] hover:text-[#111]"
                            aria-label={t('عرض كل المستندات')}
                        >
                            <X className="size-3.5" />
                        </SmartLink>
                    </span>
                    <SmartLink routeName="super-admin.businesses.show" href={company.url} className="text-[#6b7280] underline">
                        {t('ملف الشركة')}
                    </SmartLink>
                </div>
            )}

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
                    waiting={waiting}
                    company={company}
                    plans={plans}
                    cycles={cycles}
                    businesses={businesses}
                    today={today}
                    days={days}
                    renew={renew}
                    onClose={() => setCreating(false)}
                />
            )}
        </PlatformLayout>
    );
}

function NewDocumentDialog({
    templates,
    waiting,
    company,
    plans,
    cycles,
    businesses,
    today,
    days,
    renew,
    onClose,
}: {
    templates: Template[];
    waiting: WaitingTemplate[];
    company: Company | null;
    plans: PlanOption[];
    cycles: CycleOption[];
    businesses: BusinessOption[];
    today: string;
    days: number;
    renew: Renewal | null;
    onClose: () => void;
}) {
    const t = useTranslate();
    const [source, setSource] = useState<'business' | 'new'>('business');
    const [previewing, setPreviewing] = useState(false);
    const [teamOpen, setTeamOpen] = useState(false);
    // نهايةُ المدّة تتبع بدايتَها ودورتَها — حتى يكتب المديرُ نهايةً اتُّفق عليها
    const [endTouched, setEndTouched] = useState(false);
    const firstCycle = renew?.cycle ?? 'monthly';
    // من ملفّ الشركة: تبدأ النافذةُ بها كما لو اختيرت من القائمة
    const firstBusiness = renew ? undefined : businesses.find((b) => b.id === company?.id);
    const firstStart = renew?.starts_on ?? firstBusiness?.starts_on ?? today;
    const monthsOf = (cycle: string) => cycles.find((c) => c.value === cycle)?.months ?? 1;

    const firstTemplate = templates.find((x) => x.id === renew?.template_id) ?? templates[0];
    const form = useForm({
        template_id: String(firstTemplate?.id ?? ''),
        business_id: firstBusiness ? String(firstBusiness.id) : '',
        renews_document_id: renew ? String(renew.id) : '',
        client: { name: '', biz: '', phone: renew?.phone ?? firstBusiness?.phone ?? '', email: '' },
        plan_id: renew?.plan_id ? String(renew.plan_id) : firstBusiness?.plan_id ? String(firstBusiness.plan_id) : '',
        cycle: firstCycle,
        term_starts_on: firstStart,
        term_ends_on: addMonths(firstStart, monthsOf(firstCycle)),
        amount: firstTemplate?.default_amount ?? '',
        amount_label: firstTemplate?.default_amount_label ?? '',
        days: String(days),
        notes: '',
        team: {} as FormValues,
    });
    const template = templates.find((x) => String(x.id) === form.data.template_id);
    const plan = plans.find((p) => String(p.id) === form.data.plan_id);
    const cycle = cycles.find((c) => c.value === form.data.cycle);
    const errors = form.errors as Record<string, string | undefined>;
    const teamInputs = formInputs(template?.form, 'team');

    // قالبٌ آخر: مبلغُه المقترح، وحقولُ فريقٍ فارغةٌ له
    const pickTemplate = (id: string) => {
        const next = templates.find((x) => String(x.id) === id);
        form.setData((d) => ({
            ...d,
            template_id: id,
            amount: next?.default_amount ?? '',
            amount_label: next?.default_amount_label ?? '',
            team: {},
        }));
    };

    const setTerm = (start: string, nextCycle: string) =>
        form.setData((d) => ({
            ...d,
            term_starts_on: start,
            cycle: nextCycle,
            term_ends_on: endTouched ? d.term_ends_on : addMonths(start, monthsOf(nextCycle)),
        }));

    const pickBusiness = (id: string) => {
        const b = businesses.find((x) => String(x.id) === id);
        const start = b?.starts_on ?? today;
        form.setData((d) => ({
            ...d,
            business_id: id,
            plan_id: b?.plan_id ? String(b.plan_id) : d.plan_id,
            // هاتفُ النشاط اقتراحٌ يُراجَع — إليه يُفتح واتساب
            client: { ...d.client, phone: b?.phone ?? '' },
            // المدّةُ الجديدة تبدأ حيث ينتهي اشتراكُه الجاري
            term_starts_on: start,
            term_ends_on: endTouched ? d.term_ends_on : addMonths(start, monthsOf(d.cycle)),
        }));
    };
    const setClient = (k: keyof typeof form.data.client, v: string) =>
        form.setData('client', { ...form.data.client, [k]: v });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => {
            const term = template?.includes_plan
                ? { term_starts_on: d.term_starts_on, term_ends_on: d.term_ends_on }
                : { term_starts_on: '', term_ends_on: '' };
            if (renew) return { ...d, ...term, business_id: '', client: { phone: d.client.phone } };
            // للنشاط المسجّل يُرسل الهاتفُ وحده — اسمٌ كُتب في التبويب الآخر لا يعبر
            return source === 'business'
                ? { ...d, ...term, renews_document_id: '', client: { phone: d.client.phone } }
                : { ...d, ...term, renews_document_id: '', business_id: '' };
        });
        form.post(route('super-admin.documents.store'), { onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{renew ? t('عقد تجديد للتوقيع') : t('مستند جديد للتوقيع')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="new-document">
                    {renew && (
                        <div className="flex items-start gap-2 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[13px] text-[#1e40af]" data-testid="renewal-of">
                            <RefreshCcw className="mt-0.5 size-4 shrink-0" />
                            <span>
                                {t('تجديد للعقد :n — :client. يبدأ ببيان العميل وباقته من العقد السابق.', {
                                    n: renew.number,
                                    client: renew.client ?? '—',
                                })}
                            </span>
                        </div>
                    )}

                    <Field label="نوع المستند" required error={errors.template_id}>
                        <Select
                            value={form.data.template_id}
                            onChange={(e) => pickTemplate(e.target.value)}
                            options={[
                                ...templates.map((x) => ({
                                    label: x.service ? `${x.name} · ${x.service}` : x.name,
                                    value: String(x.id),
                                })),
                                // ما لا يُصدر منه بعدُ ظاهرٌ باسمه وسببه، ولا يُختار
                                ...waiting.map((x) => ({
                                    label: `${x.name} — ${t(x.reason)}`,
                                    value: `waiting-${x.id}`,
                                    disabled: true,
                                })),
                            ]}
                        />
                        {waiting.length > 0 && (
                            <p className="mt-1 text-[12px] text-[#6b7280]" data-testid="waiting-templates">
                                {t('يُصدر من القالب المعتمد والمفعّل وحده — :n بانتظارك في', { n: String(waiting.length) })}{' '}
                                <SmartLink
                                    routeName="super-admin.documents.templates"
                                    href={route('super-admin.documents.templates')}
                                    className="underline"
                                >
                                    {t('القوالب')}
                                </SmartLink>
                            </p>
                        )}
                    </Field>

                    {template && (
                        <div className="rounded-[10px] border border-[#e7e8ea]">
                            <button
                                type="button"
                                className="flex w-full items-center justify-between px-3 py-2 text-[13px] text-[#111]"
                                onClick={() => setPreviewing((v) => !v)}
                                aria-expanded={previewing}
                            >
                                <span>
                                    {template.form
                                        ? t('معاينة النموذج (:n قسمًا)', { n: String(template.form.sections.length) })
                                        : t('معاينة البنود (:n)', { n: String(template.terms.length) })}
                                </span>
                                <span className="text-[#6b7280]">{previewing ? t('إخفاء') : t('عرض')}</span>
                            </button>
                            {previewing && template.form && (
                                <div className="max-h-72 overflow-y-auto border-t border-[#e7e8ea] px-3 py-2" data-testid="terms-preview">
                                    <DocumentFormView form={template.form} />
                                </div>
                            )}
                            {previewing && !template.form && (
                                <ol
                                    className="max-h-56 list-decimal space-y-1.5 overflow-y-auto border-t border-[#e7e8ea] py-2 ps-8 pe-3 text-[12.5px] leading-relaxed text-[#374151]"
                                    data-testid="terms-preview"
                                >
                                    {template.terms.map((term, i) => (
                                        <li key={i}>
                                            {term.h && <span className="font-semibold text-[#111]">{term.h} </span>}
                                            {term.b}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    )}

                    {!renew && (
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
                    )}

                    {!renew &&
                        (source === 'business' ? (
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
                                    <Input
                                        value={form.data.client.name}
                                        onChange={(e) => setClient('name', e.target.value)}
                                        data-testid="client-name"
                                    />
                                </Field>
                                <Field label="اسم النشاط" error={errors['client.biz']}>
                                    <Input
                                        value={form.data.client.biz}
                                        onChange={(e) => setClient('biz', e.target.value)}
                                        data-testid="client-biz"
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
                        ))}

                    <Field label="هاتف العميل" required hint="إليه يُفتح واتساب لإرسال الرابط" error={errors['client.phone']}>
                        <Input
                            dir="ltr"
                            value={form.data.client.phone}
                            onChange={(e) => setClient('phone', e.target.value)}
                            placeholder="+968"
                            data-testid="client-phone"
                        />
                    </Field>

                    {template?.includes_plan && (
                        <>
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
                                        onChange={(e) => setTerm(form.data.term_starts_on, e.target.value)}
                                        options={cycles.map((c) => ({ label: c.label, value: c.value }))}
                                    />
                                </Field>
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <Field label="بداية الاشتراك" required error={errors.term_starts_on}>
                                    <Input
                                        dir="ltr"
                                        type="date"
                                        value={form.data.term_starts_on}
                                        onChange={(e) => setTerm(e.target.value, form.data.cycle)}
                                        data-testid="term-start"
                                    />
                                </Field>
                                <Field
                                    label="نهاية الاشتراك"
                                    required
                                    hint={cycle ? t('المدة: :d — عدّلها إن اتُّفق على غيرها', { d: cycle.duration }) : undefined}
                                    error={errors.term_ends_on}
                                >
                                    <Input
                                        dir="ltr"
                                        type="date"
                                        value={form.data.term_ends_on}
                                        onChange={(e) => {
                                            setEndTouched(true);
                                            form.setData('term_ends_on', e.target.value);
                                        }}
                                        data-testid="term-end"
                                    />
                                </Field>
                            </div>
                        </>
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
                            <Field
                                label="المبلغ (ر.ع)"
                                required
                                error={errors.amount}
                                hint={
                                    template.amount_rule === 'fixed'
                                        ? t('رسوم ثابتة كما اعتُمدت ويذكرها نص الاتفاقية — تُغيَّر من القالب ثم يُعتمد من جديد')
                                        : template.amount_rule === 'min' && template.default_amount
                                          ? t('يبدأ من :fee ر.ع كما اعتُمد', { fee: template.default_amount })
                                          : undefined
                                }
                            >
                                <Input
                                    dir="ltr"
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    readOnly={template.amount_rule === 'fixed'}
                                    onChange={(e) => form.setData('amount', e.target.value)}
                                    data-testid="amount"
                                />
                            </Field>
                        </div>
                    )}

                    {template?.form && teamInputs.length > 0 && (
                        <div className="rounded-[10px] border border-[#e7e8ea]">
                            <button
                                type="button"
                                className="flex w-full items-center justify-between px-3 py-2 text-[13px] text-[#111]"
                                onClick={() => setTeamOpen((v) => !v)}
                                aria-expanded={teamOpen}
                            >
                                <span>{t('حقول فريق أبعاد (:n) — اختيارية', { n: String(teamInputs.length) })}</span>
                                <span className="text-[#6b7280]">{teamOpen ? t('إخفاء') : t('عرض')}</span>
                            </button>
                            {teamOpen && (
                                <div className="max-h-80 overflow-y-auto border-t border-[#e7e8ea] px-3 py-3">
                                    <p className="mb-2 text-[12px] text-[#6b7280]">
                                        {t('يراها العميل ولا يعدّلها. ما يبقى فارغًا يُطبع سطرًا يُكتب عليه.')}
                                    </p>
                                    <TeamInputs form={template.form} value={form.data.team} onChange={(v) => form.setData('team', v)} />
                                </div>
                            )}
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
