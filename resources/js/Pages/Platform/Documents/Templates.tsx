import { useState, type FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, BadgeCheck, Pencil, Plus, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { DocumentFormView } from '@/Components/DocumentFormView';
import { useTranslate } from '@/lib/i18n';
import type { DocumentForm } from '@/lib/platformDocuments';
import type { PageProps } from '@/types';

interface Term {
    h: string;
    b: string;
}

interface Template {
    id: number;
    name: string;
    service: string | null;
    title: string;
    title_en: string | null;
    terms: Term[];
    /** اتفاقيةُ المالك بنموذجها — تُقرأ هنا ولا تُحرَّر */
    form: DocumentForm | null;
    default_amount: string | null;
    default_amount_label: string | null;
    /** fixed: الرسومُ هي المبلغ · min: يبدأ منها · null: يكتبه المدير عند الإصدار */
    amount_rule: 'fixed' | 'min' | null;
    includes_plan: boolean;
    includes_amount: boolean;
    active: boolean;
    documents_count: number;
    version: number;
    /** draft: مسوّدة · approved: معتمد · legacy: قائمٌ قبل سياسة الاعتماد · changed: تغيّر بعد اعتماده */
    approval: 'draft' | 'approved' | 'legacy' | 'changed';
    approved_at: string | null;
    approved_by: string | null;
    /** بصمةُ ما يُعتمد — يُرسل مع الاعتماد فيُردّ إن تغيّر القالب بعد فتحه */
    hash: string;
}

interface Provider {
    name: string;
    legal_name: string;
    trade_name: string;
    cr: string;
    address: string;
}

type TemplatesProps = { templates: Template[]; provider: Provider; providerMissing: string[] };

const APPROVAL: Record<Template['approval'], { label: string; variant: 'warning' | 'success' | 'neutral' | 'danger' }> = {
    draft: { label: 'مسوّدة — بانتظار المراجعة القانونية', variant: 'warning' },
    approved: { label: 'معتمد', variant: 'success' },
    legacy: { label: 'قائم قبل سياسة الاعتماد', variant: 'neutral' },
    changed: { label: 'تغيّر بعد اعتماده — بانتظار اعتماد جديد', variant: 'danger' },
};

/** يُفعَّل ويُصدر منه: نصُّه هو المعتمد (أو القائمُ قبل الاعتماد كما هو) */
const approvedNow = (tpl: Template) => tpl.approval === 'approved' || tpl.approval === 'legacy';

export default function DocumentTemplates() {
    const { templates, providerMissing } = usePage<PageProps<TemplatesProps>>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<Template | 'new' | null>(null);
    const [approving, setApproving] = useState<Template | null>(null);

    return (
        <PlatformLayout title="قوالب المستندات">
            <BackLink routeName="super-admin.documents.index" href={route('super-admin.documents.index')} label="المستندات" />
            <PageHeader
                title="قوالب المستندات"
                subtitle={t('قالب لكل خدمة — تعديله لا يغيّر مستندًا صدر منه')}
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <Plus />
                        {t('قالب جديد')}
                    </Button>
                }
            />

            {providerMissing.length > 0 && (
                <Card className="mb-3 border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]" data-testid="provider-missing">
                    {t('بيانات مقدّم الخدمة ناقصة: :fields — لا يُعتمد قالب ولا يُصدر من قالب معتمد قبل إكمالها.', { fields: providerMissing.map((f) => t(f)).join('، ') })}{' '}
                    <Link href={route('super-admin.settings.index')} className="font-medium underline">
                        {t('إعدادات المنصة')}
                    </Link>
                </Card>
            )}

            <div className="grid gap-3 md:grid-cols-2">
                {templates.map((tpl) => (
                    <Card key={tpl.id} className="flex flex-col gap-2 p-5" data-testid={`template-${tpl.id}`}>
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="truncate text-[15px] font-semibold text-[#111]">{tpl.name}</div>
                                <div className="text-[12px] text-[#6b7280]">
                                    {tpl.service || t('بلا خدمة محددة')} ·{' '}
                                    {tpl.form ? t(':n قسمًا', { n: String(tpl.form.sections.length) }) : t(':n بند', { n: String(tpl.terms.length) })} ·{' '}
                                    {t(':n مستند', { n: String(tpl.documents_count) })}
                                </div>
                            </div>
                            <Button variant="ghost" size="icon-sm" onClick={() => setEditing(tpl)} aria-label={t('تعديل')}>
                                <Pencil />
                            </Button>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            <Badge variant={APPROVAL[tpl.approval].variant} data-testid={`approval-${tpl.id}`}>
                                {t(APPROVAL[tpl.approval].label)}
                                {tpl.approval === 'approved' && ` · ${t('الإصدار :n', { n: String(tpl.version) })}`}
                            </Badge>
                            {!tpl.active && <Badge variant="neutral">{t('معطّل')}</Badge>}
                            {tpl.form && <Badge variant="success">{t('نموذج كما صُمّم')}</Badge>}
                            {tpl.includes_plan && <Badge variant="info">{t('الباقة والدورة')}</Badge>}
                            {tpl.includes_amount && <Badge variant="info">{t('مبلغ الخدمة')}</Badge>}
                        </div>
                        {tpl.approval === 'approved' && tpl.approved_at && (
                            <div className="text-[11.5px] text-[#6b7280]">
                                {t('اعتمده :name في :at', { name: tpl.approved_by ?? '—', at: tpl.approved_at })}
                            </div>
                        )}
                        {tpl.approval !== 'approved' && (
                            <div>
                                <Button variant="outline" size="sm" onClick={() => setApproving(tpl)} data-testid={`approve-${tpl.id}`}>
                                    <BadgeCheck />
                                    {t('مراجعة واعتماد')}
                                </Button>
                            </div>
                        )}
                    </Card>
                ))}
            </div>

            {editing && <TemplateDialog template={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {approving && <ApproveDialog template={approving} onClose={() => setApproving(null)} />}
        </PlatformLayout>
    );
}

function TemplateDialog({ template, onClose }: { template: Template | null; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({
        name: template?.name ?? '',
        service: template?.service ?? '',
        title: template?.title ?? '',
        title_en: template?.title_en ?? '',
        // قالبُ النموذج لا بنودَ له — وبندٌ فارغٌ يُرسل معه يردّ الحفظَ بخطأٍ على حقلٍ لا يُرى
        terms: template?.form ? [] : template?.terms?.length ? template.terms : [{ h: '', b: '' }],
        includes_plan: template?.includes_plan ?? false,
        includes_amount: template?.includes_amount ?? false,
        active: template?.active ?? false,
        default_amount: template?.default_amount ?? '',
        default_amount_label: template?.default_amount_label ?? '',
        amount_rule: template?.amount_rule ?? '',
    });
    const isForm = !!template?.form;
    // يُفعَّل ما اعتُمد نصُّه وحده — والجديدُ مسوّدةٌ حتى يُعتمد
    const canActivate = !!template && approvedNow(template);
    const errors = form.errors as Record<string, string | undefined>;

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
        if (template) {
            form.put(route('super-admin.documents.templates.update', template.id), { onSuccess: () => onClose() });
        } else {
            form.post(route('super-admin.documents.templates.store'), { onSuccess: () => onClose() });
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{template ? t('تعديل القالب') : t('قالب جديد')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="template-form">
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
                            <input
                                type="checkbox"
                                checked={form.data.includes_plan}
                                onChange={(e) => form.setData('includes_plan', e.target.checked)}
                            />
                            {t('يتضمن الباقة ودورة الاشتراك')}
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.includes_amount}
                                onChange={(e) => form.setData('includes_amount', e.target.checked)}
                            />
                            {t('يتضمن مبلغ الخدمة')}
                        </label>
                        <label className={`flex items-center gap-2 ${canActivate ? '' : 'text-[#9ca3af]'}`}>
                            <input
                                type="checkbox"
                                checked={canActivate && form.data.active}
                                disabled={!canActivate}
                                onChange={(e) => form.setData('active', e.target.checked)}
                                data-testid="template-active"
                            />
                            {t('مفعّل')}
                        </label>
                    </div>
                    {errors.active && <p className="text-[12px] text-[#b91c1c]">{errors.active}</p>}
                    {!canActivate && (
                        <p className="text-[12px] text-[#92400e]" data-testid="needs-approval">
                            {t('لا يُفعَّل قبل اعتماده. وتعديل نصه أو رسومه بعد الاعتماد يجعله إصدارًا جديدًا معطّلًا حتى يُعتمد.')}
                        </p>
                    )}

                    {form.data.includes_amount && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field
                                label={form.data.amount_rule ? 'رسوم القالب (ر.ع)' : 'المبلغ المقترح (ر.ع)'}
                                hint={
                                    form.data.amount_rule === 'fixed'
                                        ? 'رسوم ثابتة: هي مبلغ كل مستند، ويذكرها نصه. تعديلها يحتاج اعتمادًا جديدًا'
                                        : form.data.amount_rule === 'min'
                                          ? 'السعر يبدأ منها: لا يُصدر مستند بأقل منها. تعديلها يحتاج اعتمادًا جديدًا'
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
                            {!isForm && (
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
                            )}
                        </div>
                    )}

                    {isForm && template?.form && (
                        <div className="space-y-2">
                            <div className="text-[13px] font-medium text-[#111]">{t('النموذج')}</div>
                            <p className="text-[12px] text-[#6b7280]">
                                {t('هذه الاتفاقية بنموذجها كما صمّمتها: أقسامها وحقولها وقوائمها. يُعدَّل هنا اسمها وعنوانها ومبلغها وتفعيلها.')}
                            </p>
                            {!approvedNow(template) && <DraftNotice />}
                            <div className="max-h-80 overflow-y-auto rounded-[10px] border border-[#e7e8ea] p-3">
                                <DocumentFormView form={template.form} />
                            </div>
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
                                    <Input
                                        value={term.h}
                                        onChange={(e) => setTerm(i, 'h', e.target.value)}
                                        placeholder={t('عنوان البند (اختياري)')}
                                    />
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
                                <textarea
                                    value={term.b}
                                    onChange={(e) => setTerm(i, 'b', e.target.value)}
                                    rows={2}
                                    placeholder={t('نص البند')}
                                    className="w-full rounded-[8px] border border-[#e7e8ea] px-3 py-2 text-[13px] leading-relaxed outline-none focus:border-[#111]"
                                />
                                {errors[`terms.${i}.b`] && <p className="text-[12px] text-[#b91c1c]">{errors[`terms.${i}.b`]}</p>}
                            </div>
                        ))}
                        <Button type="button" variant="outline" size="sm" onClick={() => form.setData('terms', [...form.data.terms, { h: '', b: '' }])}>
                            <Plus />
                            {t('إضافة بند')}
                        </Button>
                    </div>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('حفظ القالب')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** علامةُ المسوّدة في شاشة المدير وحدها — لا تصل نسخةَ العميل: المسوّدةُ لا يُصدر منها شيء */
function DraftNotice() {
    const t = useTranslate();
    return (
        <div className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12.5px] text-[#92400e]" data-testid="draft-notice">
            {t('مسوّدة — النص ينتظر المراجعة القانونية ولم يُعتمد. لا يُصدر منه مستند ولا يراه عميل.')}
        </div>
    );
}

/**
 * مراجعةُ القالب واعتمادُه — يرى المديرُ ما سيوقّعه العميلُ بنصّه ورسومه ومقدّم خدمته،
 * ثمّ يعتمده بكلمة مروره. والاعتمادُ على بصمة ما رآه: إن تغيّر القالبُ بعد فتحه رُدّ.
 * ولا يفعّله — التفعيلُ قرارٌ بعده من نافذة التعديل.
 */
function ApproveDialog({ template, onClose }: { template: Template; onClose: () => void }) {
    const t = useTranslate();
    const { provider, providerMissing } = usePage<PageProps<TemplatesProps>>().props;
    const form = useForm({ hash: template.hash, password: '' });
    const [reviewed, setReviewed] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;
    const fee = template.default_amount;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.documents.templates.approve', template.id), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('مراجعة واعتماد: :name', { name: template.name })}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="approve-form">
                    <DraftNotice />
                    <dl className="grid grid-cols-1 gap-x-4 gap-y-1.5 text-[13px] sm:grid-cols-2">
                        <dt className="text-[#6b7280]">{t('الإصدار')}</dt>
                        <dd className="text-[#111]">{template.version}</dd>
                        <dt className="text-[#6b7280]">{t('مقدّم الخدمة')}</dt>
                        <dd className="text-[#111]" data-testid="approve-provider">
                            {[provider.legal_name || provider.name, provider.trade_name, provider.cr, provider.address].filter(Boolean).join(' — ')}
                        </dd>
                        {template.includes_amount && (
                            <>
                                <dt className="text-[#6b7280]">{t('الرسوم')}</dt>
                                <dd className="text-[#111]" data-testid="approve-fee">
                                    {fee ? `${fee} ${t('ر.ع')}` : '—'}
                                    {template.amount_rule === 'fixed' && ` · ${t('ثابتة')}`}
                                    {template.amount_rule === 'min' && ` · ${t('يبدأ منها')}`}
                                </dd>
                            </>
                        )}
                        {template.includes_plan && (
                            <>
                                <dt className="text-[#6b7280]">{t('الرسوم')}</dt>
                                <dd className="text-[#111]">{t('حسب الباقة ودورة الفوترة في النظام')}</dd>
                            </>
                        )}
                    </dl>

                    {providerMissing.length > 0 && (
                        <p className="text-[12.5px] text-[#b91c1c]" data-testid="approve-blocked">
                            {t('لا يُعتمد قبل إكمال بيانات مقدّم الخدمة: :fields', { fields: providerMissing.map((f) => t(f)).join('، ') })}
                        </p>
                    )}

                    <div className="max-h-80 overflow-y-auto rounded-[10px] border border-[#e7e8ea] p-3">
                        {template.form ? (
                            <DocumentFormView form={template.form} />
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

                    <label className="flex items-start gap-2 text-[13px]">
                        <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} className="mt-1" data-testid="approve-reviewed" />
                        {t('راجعت نص هذا القالب ورسومه وبيانات مقدّم الخدمة، وأعتمده للإصدار. يُسجَّل الاعتماد باسمي.')}
                    </label>
                    <Field label="كلمة المرور" required error={errors.password}>
                        <Input type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} data-testid="approve-password" />
                    </Field>
                    {(errors.approve || errors.hash) && <p className="text-[12.5px] text-[#b91c1c]">{errors.approve ?? errors.hash}</p>}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing} disabled={!reviewed || form.data.password === '' || providerMissing.length > 0} data-testid="approve-submit">
                            {t('اعتماد القالب')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
