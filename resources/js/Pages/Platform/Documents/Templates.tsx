import { useMemo, useState, type FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, FileDown, History, Pencil, Plus, Send, Square, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { DocumentFormView } from '@/Components/DocumentFormView';
import { DocumentFormEditor } from '@/Components/DocumentFormEditor';
import { useTranslate } from '@/lib/i18n';
import { emptyForm, formTokens, renderTokens, type DocumentForm } from '@/lib/platformDocuments';
import type { PageProps } from '@/types';

interface Term {
    h: string;
    b: string;
}

interface Version {
    version: number;
    hash: string;
    saved_at: string;
    saved_by: string | null;
    published_at: string | null;
    published_by: string | null;
}

interface Template {
    id: number;
    name: string;
    service: string | null;
    title: string;
    title_en: string | null;
    terms: Term[];
    /** نموذجُ الاتفاقية كما يُحرَّر — برموزه (`{fee}`، `{provider}`) */
    form: DocumentForm | null;
    default_amount: string | null;
    default_amount_label: string | null;
    /** fixed: الرسومُ هي المبلغ · min: يبدأ منها · null: يكتبه المدير عند الإصدار */
    amount_rule: 'fixed' | 'min' | null;
    includes_plan: boolean;
    includes_amount: boolean;
    documents_count: number;
    version: number;
    /** published: منشور · draft: لم يُنشر قطّ · changed: عُدِّل بعد نشره · paused: أُوقف نشرُه */
    status: 'published' | 'draft' | 'changed' | 'paused';
    published_at: string | null;
    published_by: string | null;
    /** بصمةُ الإصدار الحاليّ — يُرسل مع النشر فيُردّ إن تغيّر القالب بعد فتحه */
    hash: string;
    /** ما يمنع نشرَه الآن — من الخادم */
    problems: string[];
    preview_url: string;
    preview_pdf_url: string;
    versions: Version[];
}

interface Provider {
    name: string;
    legal_name: string;
    trade_name: string;
    cr: string;
    address: string;
}

type TemplatesProps = { templates: Template[]; provider: Provider; providerLine: string; providerMissing: string[] };

const STATUS: Record<Template['status'], { label: string; variant: 'warning' | 'success' | 'neutral' | 'danger' }> = {
    published: { label: 'منشور', variant: 'success' },
    draft: { label: 'غير منشور', variant: 'warning' },
    changed: { label: 'عُدِّل — الإصدار الجديد غير منشور', variant: 'warning' },
    paused: { label: 'موقوف', variant: 'neutral' },
};

export default function DocumentTemplates() {
    const { templates, providerMissing } = usePage<PageProps<TemplatesProps>>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<Template | 'new' | null>(null);
    const [publishing, setPublishing] = useState<Template | null>(null);
    const [pausing, setPausing] = useState<Template | null>(null);

    return (
        <PlatformLayout title="قوالب المستندات">
            <BackLink routeName="super-admin.documents.index" href={route('super-admin.documents.index')} label="المستندات" />
            <PageHeader
                title="قوالب المستندات"
                subtitle={t('حرّر أي قالب كاملًا، عاينه كما يراه العميل، ثم انشره — تعديله لا يغيّر مستندًا صدر منه')}
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <Plus />
                        {t('قالب جديد')}
                    </Button>
                }
            />

            {providerMissing.length > 0 && (
                <Card className="mb-3 p-4 text-[13px] text-[#6b7280]" data-testid="provider-missing">
                    {t('لم تُكتب في بيانات مقدّم الخدمة: :fields — لا تظهر في العقود حتى تُكتب، ولا تمنع النشر ولا الإصدار.', { fields: providerMissing.map((f) => t(f)).join('، ') })}{' '}
                    <Link href={route('super-admin.settings.index')} className="font-medium underline">
                        {t('إعدادات المنصة')}
                    </Link>
                </Card>
            )}

            <div className="grid gap-3 md:grid-cols-2">
                {templates.map((tpl) => (
                    <TemplateCard
                        key={tpl.id}
                        tpl={tpl}
                        onEdit={() => setEditing(tpl)}
                        onPublish={() => setPublishing(tpl)}
                        onPause={() => setPausing(tpl)}
                    />
                ))}
            </div>

            {editing && <TemplateDialog template={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {publishing && <PublishDialog template={publishing} onClose={() => setPublishing(null)} />}
            {pausing && <PauseDialog template={pausing} onClose={() => setPausing(null)} />}
        </PlatformLayout>
    );
}

function TemplateCard({ tpl, onEdit, onPublish, onPause }: { tpl: Template; onEdit: () => void; onPublish: () => void; onPause: () => void }) {
    const t = useTranslate();
    const [history, setHistory] = useState(false);

    return (
        <Card className="flex flex-col gap-2 p-5" data-testid={`template-${tpl.id}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="truncate text-[15px] font-semibold text-[#111]">{tpl.name}</div>
                    <div className="text-[12px] text-[#6b7280]">
                        {tpl.service || t('بلا خدمة محددة')} ·{' '}
                        {tpl.form ? t(':n قسمًا', { n: String(tpl.form.sections.length) }) : t(':n بند', { n: String(tpl.terms.length) })} ·{' '}
                        {t(':n مستند', { n: String(tpl.documents_count) })}
                    </div>
                </div>
                <Button variant="ghost" size="icon-sm" onClick={onEdit} aria-label={t('تعديل')} data-testid={`edit-${tpl.id}`}>
                    <Pencil />
                </Button>
            </div>
            <div className="flex flex-wrap gap-1.5">
                <Badge variant={STATUS[tpl.status].variant} data-testid={`status-${tpl.id}`}>
                    {t(STATUS[tpl.status].label)} · {t('الإصدار :n', { n: String(tpl.version) })}
                </Badge>
                {tpl.form && <Badge variant="neutral">{t('نموذج بأقسام')}</Badge>}
                {tpl.includes_plan && <Badge variant="info">{t('الباقة والدورة')}</Badge>}
                {tpl.includes_amount && <Badge variant="info">{t('مبلغ الخدمة')}</Badge>}
            </div>
            {tpl.status === 'published' && tpl.published_at && (
                <div className="text-[11.5px] text-[#6b7280]">
                    {/* ما نُشر قبل سجلّ النشر لا اسمَ لناشره */}
                    {tpl.published_by ? t('نشره :name في :at', { name: tpl.published_by, at: tpl.published_at }) : t('منشور منذ :at', { at: tpl.published_at })}
                </div>
            )}
            {tpl.status !== 'published' && (
                <div className="text-[11.5px] text-[#6b7280]">{t('يُختار عند إصدار مستند، ويُنشر بتأكيد واحد. أو انشره من هنا.')}</div>
            )}
            <div className="flex flex-wrap gap-2 pt-1">
                <Button variant="outline" size="sm" asChild>
                    <a href={tpl.preview_url} target="_blank" rel="noopener noreferrer" data-testid={`preview-${tpl.id}`}>
                        <Eye />
                        {t('صفحة التوقيع')}
                    </a>
                </Button>
                <Button variant="outline" size="sm" asChild>
                    <a href={tpl.preview_pdf_url} target="_blank" rel="noopener noreferrer">
                        <FileDown />
                        PDF
                    </a>
                </Button>
                {tpl.status === 'published' ? (
                    <Button variant="ghost" size="sm" onClick={onPause} data-testid={`pause-${tpl.id}`}>
                        <Square />
                        {t('إيقاف النشر')}
                    </Button>
                ) : (
                    <Button size="sm" onClick={onPublish} data-testid={`publish-${tpl.id}`}>
                        <Send />
                        {t('نشر الإصدار :n', { n: String(tpl.version) })}
                    </Button>
                )}
                <Button variant="ghost" size="sm" onClick={() => setHistory((v) => !v)} aria-expanded={history}>
                    <History />
                    {t('الإصدارات (:n)', { n: String(tpl.versions.length) })}
                </Button>
            </div>
            {history && (
                <ol className="space-y-1 rounded-[10px] bg-[#fafafa] p-3 text-[12px] text-[#4b5563]" data-testid={`versions-${tpl.id}`}>
                    {tpl.versions.map((v) => (
                        <li key={v.version}>
                            <b className="text-[#111]">{t('الإصدار :n', { n: String(v.version) })}</b> · {t('حُفظ :at', { at: v.saved_at })}
                            {v.saved_by && ` · ${v.saved_by}`}
                            {v.published_at && ` · ${t('نُشر :at بيد :name', { at: v.published_at, name: v.published_by ?? '—' })}`}
                            <span dir="ltr" className="ms-1 font-mono text-[11px] text-[#9ca3af]">
                                {v.hash}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </Card>
    );
}

/**
 * تحريرُ القالب كلِّه — اسمُه وعناوينُه ورسومُه وقاعدةُ مبلغه، وبنودُه أو نموذجُه.
 * والمعاينةُ بجانبه كما سيُكتب في المستند (برسومه ومقدّم خدمته). والحفظُ إصدارٌ
 * جديدٌ غيرُ منشور إن تغيّر ما يصل إلى المستند.
 */
function TemplateDialog({ template, onClose }: { template: Template | null; onClose: () => void }) {
    const t = useTranslate();
    const { providerLine } = usePage<PageProps<TemplatesProps>>().props;
    const [kind, setKind] = useState<'form' | 'terms'>(template ? (template.form ? 'form' : 'terms') : 'form');
    const [tab, setTab] = useState<'edit' | 'preview'>('edit');
    const form = useForm({
        name: template?.name ?? '',
        service: template?.service ?? '',
        title: template?.title ?? '',
        title_en: template?.title_en ?? '',
        terms: template?.terms?.length ? template.terms : [{ h: '', b: '' }],
        form: (template?.form ?? emptyForm()) as DocumentForm,
        includes_plan: template?.includes_plan ?? false,
        includes_amount: template?.includes_amount ?? false,
        default_amount: template?.default_amount ?? '',
        default_amount_label: template?.default_amount_label ?? '',
        amount_rule: template?.amount_rule ?? '',
    });
    const isForm = kind === 'form';
    const errors = form.errors as Record<string, string | undefined>;
    // رمزٌ كان في النص وأُزيل: الموضعُ الذي كان يذكر الرسوم أو مقدّم الخدمة لن يذكرهما
    const removed = useMemo(() => {
        if (!template?.form) return [];
        const now = formTokens(form.data.form);
        return formTokens(template.form).filter((tok) => !now.includes(tok));
    }, [template, form.data.form]);

    const setTerm = (i: number, key: keyof Term, value: string) =>
        form.setData('terms', form.data.terms.map((term, j) => (j === i ? { ...term, [key]: value } : term)));
    const move = (i: number, by: number) => {
        const next = [...form.data.terms];
        const [row] = next.splice(i, 1);
        next.splice(i + by, 0, row);
        form.setData('terms', next);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // لقالب النموذج نموذجُه وحده، ولقالب البنود بنودُه وحدها
        form.transform((d) => (isForm ? { ...d, terms: [] } : { ...d, form: null }));
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (template) {
            form.put(route('super-admin.documents.templates.update', template.id), options);
        } else {
            form.post(route('super-admin.documents.templates.store'), options);
        }
    };

    const preview = isForm ? renderTokens(form.data.form, { fee: form.data.default_amount || null, provider: providerLine }) : null;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {template ? t('تعديل القالب — الإصدار :n', { n: String(template.version) }) : t('قالب جديد')}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="template-form">
                    {!template && (
                        <div className="flex gap-1 rounded-[10px] bg-[#f4f4f5] p-1 text-[13px]" role="tablist">
                            {(['form', 'terms'] as const).map((k) => (
                                <button
                                    key={k}
                                    type="button"
                                    role="tab"
                                    aria-selected={kind === k}
                                    onClick={() => setKind(k)}
                                    className={`flex-1 rounded-[8px] px-3 py-1.5 ${kind === k ? 'bg-white font-medium shadow-sm' : 'text-[#6b7280]'}`}
                                >
                                    {k === 'form' ? t('نموذج بأقسام وحقول') : t('بنود')}
                                </button>
                            ))}
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field label="اسم القالب" required error={errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field label="الخدمة" hint="مثال: تخصيص الموقع، تطبيق خاص، أجهزة" error={errors.service}>
                            <Input value={form.data.service} onChange={(e) => form.setData('service', e.target.value)} />
                        </Field>
                        <Field label="عنوان المستند" required error={errors.title}>
                            <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        </Field>
                        <Field label="العنوان بالإنجليزية" error={errors.title_en}>
                            <Input dir="ltr" value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />
                        </Field>
                    </div>

                    <div className="flex flex-wrap gap-x-5 gap-y-2 text-[13px]">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={form.data.includes_plan} onChange={(e) => form.setData('includes_plan', e.target.checked)} data-testid="includes-plan" />
                            {t('يتضمن الباقة ودورة الاشتراك')}
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={form.data.includes_amount} onChange={(e) => form.setData('includes_amount', e.target.checked)} data-testid="includes-amount" />
                            {t('يتضمن مبلغ الخدمة')}
                        </label>
                    </div>

                    {form.data.includes_amount && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Field
                                label={form.data.amount_rule ? 'رسوم القالب (ر.ع)' : 'المبلغ المقترح (ر.ع)'}
                                hint={
                                    form.data.amount_rule === 'fixed'
                                        ? 'رسوم ثابتة: هي مبلغ كل مستند، ويذكرها نصه في {fee}'
                                        : form.data.amount_rule === 'min'
                                          ? 'السعر يبدأ منها: لا يُصدر مستند بأقل منها'
                                          : 'يُقترح في نافذة الإصدار ويُعدَّل فيها'
                                }
                                error={errors.default_amount}
                            >
                                <Input
                                    dir="ltr"
                                    type="number"
                                    step="0.001"
                                    value={form.data.default_amount}
                                    onChange={(e) => form.setData('default_amount', e.target.value)}
                                    data-testid="default-amount"
                                />
                            </Field>
                            <Field label="بيان المبلغ" error={errors.default_amount_label}>
                                <Input value={form.data.default_amount_label} onChange={(e) => form.setData('default_amount_label', e.target.value)} />
                            </Field>
                            <Field label="قاعدة المبلغ" error={errors.amount_rule}>
                                <select
                                    value={form.data.amount_rule}
                                    onChange={(e) => form.setData('amount_rule', e.target.value as '' | 'fixed' | 'min')}
                                    className="h-10 w-full rounded-[10px] border border-[#e8e8e8] bg-white px-3 text-[13px]"
                                    data-testid="amount-rule"
                                >
                                    <option value="">{t('يكتبه المدير عند الإصدار')}</option>
                                    <option value="fixed">{t('رسوم ثابتة')}</option>
                                    <option value="min">{t('يبدأ من الرسوم')}</option>
                                </select>
                            </Field>
                        </div>
                    )}

                    {isForm && (
                        <div className="space-y-2">
                            <div className="flex gap-1 rounded-[10px] bg-[#f4f4f5] p-1 text-[13px]" role="tablist">
                                {(['edit', 'preview'] as const).map((k) => (
                                    <button
                                        key={k}
                                        type="button"
                                        role="tab"
                                        aria-selected={tab === k}
                                        onClick={() => setTab(k)}
                                        className={`flex-1 rounded-[8px] px-3 py-1.5 ${tab === k ? 'bg-white font-medium shadow-sm' : 'text-[#6b7280]'}`}
                                        data-testid={`tab-${k}`}
                                    >
                                        {k === 'edit' ? t('تحرير النموذج') : t('معاينة كما يراها العميل')}
                                    </button>
                                ))}
                            </div>
                            {removed.length > 0 && (
                                <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12.5px] text-[#92400e]" data-testid="removed-tokens">
                                    {t('أُزيل من النص: :tokens — لن يذكره المستند في ذلك الموضع.', { tokens: removed.join('، ') })}
                                </p>
                            )}
                            {errors.form && (
                                <ul className="list-disc space-y-0.5 rounded-[10px] bg-[#fef2f2] px-3 py-2 ps-7 text-[12.5px] text-[#b91c1c]" data-testid="form-problems">
                                    {errors.form.split('\n').map((line) => (
                                        <li key={line}>{line}</li>
                                    ))}
                                </ul>
                            )}
                            {tab === 'edit' ? (
                                <DocumentFormEditor value={form.data.form} onChange={(next) => form.setData('form', next)} />
                            ) : (
                                <div className="rounded-[10px] border border-[#e7e8ea] p-4" data-testid="live-preview">
                                    {preview && <DocumentFormView form={preview} />}
                                    {template && (
                                        <p className="mt-4 text-[12px] text-[#6b7280]">
                                            {t('صفحة التوقيع وPDF تعرضان آخر ما حُفظ:')}{' '}
                                            <a href={template.preview_url} target="_blank" rel="noopener noreferrer" className="underline">
                                                {t('صفحة التوقيع')}
                                            </a>{' '}
                                            ·{' '}
                                            <a href={template.preview_pdf_url} target="_blank" rel="noopener noreferrer" className="underline">
                                                PDF
                                            </a>
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {!isForm && (
                        <div className="space-y-2">
                            <div className="text-[13px] font-medium text-[#111]">{t('البنود')}</div>
                            {errors.terms && <p className="text-[12px] text-[#b91c1c]">{errors.terms}</p>}
                            {form.data.terms.map((term, i) => (
                                <div key={i} className="space-y-2 rounded-[10px] border border-[#e7e8ea] p-3">
                                    <div className="flex items-center gap-2">
                                        <span className="w-5 shrink-0 text-[12px] text-[#9ca3af]">{i + 1}.</span>
                                        <Input value={term.h} onChange={(e) => setTerm(i, 'h', e.target.value)} placeholder={t('عنوان البند (اختياري)')} />
                                        <Button type="button" variant="ghost" size="icon-sm" disabled={i === 0} onClick={() => move(i, -1)} aria-label={t('أعلى')}>
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            disabled={i === form.data.terms.length - 1}
                                            onClick={() => move(i, 1)}
                                            aria-label={t('أسفل')}
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            disabled={form.data.terms.length === 1}
                                            onClick={() => form.setData('terms', form.data.terms.filter((_, j) => j !== i))}
                                            aria-label={t('حذف البند')}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    <Textarea value={term.b} onChange={(e) => setTerm(i, 'b', e.target.value)} rows={2} placeholder={t('نص البند')} />
                                    {errors[`terms.${i}.b`] && <p className="text-[12px] text-[#b91c1c]">{errors[`terms.${i}.b`]}</p>}
                                </div>
                            ))}
                            <Button type="button" variant="outline" size="sm" onClick={() => form.setData('terms', [...form.data.terms, { h: '', b: '' }])}>
                                <Plus />
                                {t('إضافة بند')}
                            </Button>
                        </div>
                    )}

                    <p className="text-[12px] text-[#6b7280]">
                        {t('الحفظ لا ينشر: يصير إصدارًا جديدًا تعاينه ثم تنشره، أو تنشره وأنت تُصدر منه. المستندات التي صدرت لا تتغيّر.')}
                    </p>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing} data-testid="save-template">
                            {t('حفظ القالب')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * نشرُ الإصدار الحاليّ — تأكيدٌ واحد بلا كلمة مرور، على بصمة ما يُعرض هنا.
 * ويُسجَّل باسم المدير ووقته وإصداره وبصمته.
 */
function PublishDialog({ template, onClose }: { template: Template; onClose: () => void }) {
    const t = useTranslate();
    const { providerLine } = usePage<PageProps<TemplatesProps>>().props;
    const form = useForm({ hash: template.hash });
    const errors = form.errors as Record<string, string | undefined>;
    const preview = template.form ? renderTokens(template.form, { fee: template.default_amount, provider: providerLine }) : null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.documents.templates.publish', template.id), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('نشر الإصدار :v: :name', { v: String(template.version), name: template.name })}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="publish-form">
                    <dl className="grid grid-cols-1 gap-x-4 gap-y-1.5 text-[13px] sm:grid-cols-2">
                        <dt className="text-[#6b7280]">{t('مقدّم الخدمة')}</dt>
                        <dd className="text-[#111]">{providerLine || '—'}</dd>
                        {template.includes_amount && (
                            <>
                                <dt className="text-[#6b7280]">{t('الرسوم')}</dt>
                                <dd className="text-[#111]" data-testid="publish-fee">
                                    {template.default_amount ? `${template.default_amount} ${t('ر.ع')}` : '—'}
                                    {template.amount_rule === 'fixed' && ` · ${t('ثابتة')}`}
                                    {template.amount_rule === 'min' && ` · ${t('يبدأ منها')}`}
                                </dd>
                            </>
                        )}
                        <dt className="text-[#6b7280]">{t('البصمة')}</dt>
                        <dd dir="ltr" className="text-end font-mono text-[12px] text-[#6b7280]">
                            {template.hash.slice(0, 12)}
                        </dd>
                    </dl>
                    <div className="max-h-80 overflow-y-auto rounded-[10px] border border-[#e7e8ea] p-3">
                        {preview ? (
                            <DocumentFormView form={preview} />
                        ) : (
                            <ol className="list-decimal space-y-1 ps-5 text-[13px] text-[#374151]">
                                {template.terms.map((term, i) => (
                                    <li key={i}>
                                        {term.h && <b>{term.h}: </b>}
                                        {term.b}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                    {template.problems.length > 0 && (
                        <ul className="list-disc rounded-[10px] bg-[#fef2f2] px-3 py-2 ps-7 text-[12.5px] text-[#b91c1c]" data-testid="publish-problems">
                            {template.problems.map((p) => (
                                <li key={p}>{p}</li>
                            ))}
                        </ul>
                    )}
                    {errors.publish && <p className="text-[12.5px] text-[#b91c1c]">{errors.publish}</p>}
                    <p className="text-[12.5px] text-[#4b5563]">
                        {t('يُنشر هذا الإصدار بنصه ورسومه كما تراه هنا، ويُسجَّل النشر باسمك. المستندات التي صدرت من إصدار سابق لا تتغيّر.')}
                    </p>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing} disabled={template.problems.length > 0} data-testid="publish-submit">
                            {t('نشر الإصدار :n', { n: String(template.version) })}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PauseDialog({ template, onClose }: { template: Template; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({});

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('إيقاف نشر: :name', { name: template.name })}</DialogTitle>
                </DialogHeader>
                <div className="space-y-4 px-5 pb-5">
                    <p className="text-[13px] text-[#4b5563]">
                        {t('يبقى القالب ظاهرًا في نافذة الإصدار، ويحتاج تأكيد نشره عند الإصدار منه. المستندات التي صدرت منه لا تتغيّر وروابطها تعمل.')}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            loading={form.processing}
                            onClick={() => form.post(route('super-admin.documents.templates.unpublish', template.id), { preserveScroll: true, onSuccess: () => onClose() })}
                            data-testid="pause-submit"
                        >
                            {t('إيقاف النشر')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
