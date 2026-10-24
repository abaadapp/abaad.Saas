import { useState, type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
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
    includes_plan: boolean;
    includes_amount: boolean;
    active: boolean;
    documents_count: number;
}

export default function DocumentTemplates() {
    const { templates } = usePage<PageProps<{ templates: Template[] }>>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<Template | 'new' | null>(null);

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

            <div className="grid gap-3 md:grid-cols-2">
                {templates.map((tpl) => (
                    <Card key={tpl.id} className="flex flex-col gap-2 p-5" data-testid={`template-${tpl.id}`}>
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="truncate text-[15px] font-semibold text-[#111]">{tpl.name}</div>
                                <div className="text-[12px] text-[#6b7280]">
                                    {tpl.service || t('بلا خدمة محددة')} · {t(':n بند', { n: String(tpl.terms.length) })} ·{' '}
                                    {t(':n مستند', { n: String(tpl.documents_count) })}
                                </div>
                            </div>
                            <Button variant="ghost" size="icon-sm" onClick={() => setEditing(tpl)} aria-label={t('تعديل')}>
                                <Pencil />
                            </Button>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {!tpl.active && <Badge variant="neutral">{t('معطّل')}</Badge>}
                            {tpl.includes_plan && <Badge variant="info">{t('الباقة والدورة')}</Badge>}
                            {tpl.includes_amount && <Badge variant="info">{t('مبلغ الخدمة')}</Badge>}
                        </div>
                    </Card>
                ))}
            </div>

            {editing && <TemplateDialog template={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
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
        terms: template?.terms?.length ? template.terms : [{ h: '', b: '' }],
        includes_plan: template?.includes_plan ?? false,
        includes_amount: template?.includes_amount ?? false,
        active: template?.active ?? true,
    });
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
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.active}
                                onChange={(e) => form.setData('active', e.target.checked)}
                            />
                            {t('مفعّل')}
                        </label>
                    </div>

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
