import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { BellRing, Plus, Target, Trash2 } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';

export interface AlertMetric {
    key: string;
    label: string;
    /** money → مبلغ، count → عدد. تُستعمل لتلميح حقل الحد */
    unit: string;
    section: string;
}

export interface CustomAlertRow {
    id: number;
    type: 'rule' | 'reminder';
    message: string;
    section: string;
    metric: string | null;
    operator: string | null;
    threshold: number | null;
    color: string;
    due_at: string | null;
    active: boolean;
}

interface Props {
    alerts: CustomAlertRow[];
    metrics: AlertMetric[];
    sections: Record<string, string>;
}

/**
 * تنبيهات يعرّفها صاحب النشاط.
 *
 * نوعان: قاعدة تراقب مقياسًا من مقاييس النظام وتظهر متى تحقّق شرطها، وتذكير
 * بنصٍّ وموعد لا يراقب شيئًا. المقاييس قائمة مغلقة يرسلها الخادم — الشرط لا
 * يُكتب بحرّية.
 */
export default function CustomAlerts({ alerts, metrics, sections }: Props) {
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const [open, setOpen] = useState(false);

    const form = useForm({
        type: 'rule' as 'rule' | 'reminder',
        message: '',
        section: 'reports',
        metric: metrics[0]?.key ?? '',
        operator: '<',
        threshold: '',
        color: 'warning',
        due_at: '',
    });

    const metric = metrics.find((m) => m.key === form.data.metric);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.alerts.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const describe = (a: CustomAlertRow) => {
        if (a.type === 'reminder') return `${t('تذكير')} · ${a.due_at?.replace('T', ' ') ?? '—'}`;
        const label = metrics.find((m) => m.key === a.metric)?.label ?? a.metric;
        return `${label} ${a.operator} ${a.threshold}`;
    };

    return (
        <Card className="p-6">
            <div className="mb-5 flex items-start justify-between gap-3">
                <div>
                    <h3 className="font-bold text-[#111]">{t('تنبيهات مخصّصة')}</h3>
                    <p className="mt-1 text-[13px] text-[#6b7280]">
                        {t('راقب ما يهمّك أنت: قاعدة تعمل تلقائيًّا، أو تذكير بموعد.')}
                    </p>
                </div>
                <Button type="button" onClick={() => setOpen((v) => !v)}>
                    <Plus />
                    {t('تنبيه جديد')}
                </Button>
            </div>

            {open && (
                <form
                    onSubmit={submit}
                    className="mb-6 space-y-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="نوع التنبيه" required error={form.errors.type}>
                            <Select
                                value={form.data.type}
                                onChange={(e) => form.setData('type', e.target.value as 'rule' | 'reminder')}
                                options={[
                                    { label: t('قاعدة تلقائية'), value: 'rule' },
                                    { label: t('تذكير بموعد'), value: 'reminder' },
                                ]}
                            />
                        </Field>

                        <Field label="القسم الذي يفتحه" required error={form.errors.section}>
                            <Select
                                value={form.data.section}
                                onChange={(e) => form.setData('section', e.target.value)}
                                options={Object.entries(sections).map(([value, label]) => ({ label, value }))}
                            />
                        </Field>
                    </div>

                    {form.data.type === 'rule' ? (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="المقياس" required error={form.errors.metric}>
                                <Select
                                    value={form.data.metric}
                                    onChange={(e) => form.setData('metric', e.target.value)}
                                    options={metrics.map((m) => ({ label: m.label, value: m.key }))}
                                />
                            </Field>
                            <Field label="الشرط" required error={form.errors.operator}>
                                <Select
                                    value={form.data.operator}
                                    onChange={(e) => form.setData('operator', e.target.value)}
                                    options={[
                                        { label: t('أقل من'), value: '<' },
                                        { label: t('أكثر من'), value: '>' },
                                    ]}
                                />
                            </Field>
                            <Field
                                label="الحد"
                                required
                                hint={metric?.unit === 'money' ? 'مبلغ' : 'عدد'}
                                error={form.errors.threshold}
                            >
                                <Input
                                    inputMode="decimal"
                                    dir="ltr"
                                    value={form.data.threshold}
                                    onChange={(e) => form.setData('threshold', e.target.value)}
                                    placeholder="0"
                                />
                            </Field>
                        </div>
                    ) : (
                        <Field label="موعد التذكير" required error={form.errors.due_at}>
                            <Input
                                type="datetime-local"
                                dir="ltr"
                                value={form.data.due_at}
                                onChange={(e) => form.setData('due_at', e.target.value)}
                            />
                        </Field>
                    )}

                    <Field
                        label="نص التنبيه"
                        required
                        hint="ما تراه في الجرس"
                        error={form.errors.message}
                    >
                        <Input
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
                            placeholder={t('مثال: مبيعات اليوم أقل من المعتاد')}
                        />
                    </Field>

                    <div className="flex items-center gap-2">
                        <Button type="submit" loading={form.processing}>
                            {t('إضافة')}
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </form>
            )}

            {alerts.length === 0 ? (
                <p className="py-8 text-center text-[13px] text-[#9ca3af]">
                    {t('لا تنبيهات مخصّصة بعد.')}
                </p>
            ) : (
                <ul className="space-y-2">
                    {alerts.map((a) => (
                        <li
                            key={a.id}
                            className="flex items-center gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3"
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                                {a.type === 'reminder' ? (
                                    <BellRing className="size-4" />
                                ) : (
                                    <Target className="size-4" />
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-[#111]">{a.message}</p>
                                <p className="truncate text-[12px] text-[#9ca3af]" dir="auto">
                                    {describe(a)} · {sections[a.section] ?? a.section}
                                </p>
                            </div>
                            {!a.active && <Badge status="معطل" />}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.put(
                                        route('admin.alerts.update', a.id),
                                        { active: !a.active },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {a.active ? t('تعطيل') : t('تفعيل')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                onClick={async () => {
                                    if (! await ask({ message: 'حذف هذا التنبيه؟', danger: true, action: 'حذف' })) return;
                                    router.delete(route('admin.alerts.destroy', a.id), { preserveScroll: true });
                                }}
                            >
                                <Trash2 className="text-[#b91c1c]" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {confirmDialog}
        </Card>
    );
}
