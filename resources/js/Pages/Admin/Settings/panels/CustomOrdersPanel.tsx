import { useState } from 'react';
import { router } from '@inertiajs/react';
import { GripVertical, Pencil, Plus, Trash2, X } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import StatusPill from '@/Components/StatusPill';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';

/**
 * قوالبُ الطلب المخصَّص — صاحبُ النشاط يكتب شكلَ طلبه.
 *
 * ═══ ولمَ لا قوالبَ جاهزةً لكلّ صناعة ═══
 *
 * قائمةٌ من «محل ورد» و«مطعم» و«عطور» تنسى التاليَ دائمًا، وتُجبر من لا
 * يجد صناعتَه على أن يختار أقربَها فيكتب في حقلٍ لا يعنيه. فالقالبُ يبدأ
 * فارغًا، وصاحبُ النشاط يسمّيه ويكتب حقولَه — وهو أعرفُ بما يبيع.
 *
 * ═══ ومحرّرٌ صغير لا بانيَ نماذج ═══
 *
 * ستّةُ أنواعِ حقولٍ وخياراتُها. ولا شروطَ ولا تفرّعاتٌ ولا حسابات: من
 * يحتاجها يحتاج نظامًا آخر، ومن يحتاج أن يسأل زبونَه ثلاثةَ أسئلةٍ يجدها
 * هنا في دقيقة.
 */

export interface CustomOrderOption {
    id?: number;
    label: string;
    label_en?: string | null;
    active?: boolean;
}

export interface CustomOrderField {
    id?: number;
    label: string;
    label_en?: string | null;
    type: string;
    required?: boolean;
    internal?: boolean;
    active?: boolean;
    options?: CustomOrderOption[];
}

export interface CustomOrderTemplate {
    id: number;
    name: string;
    name_en?: string | null;
    modes: string[];
    default_mode?: string | null;
    base_label?: string | null;
    base_label_en?: string | null;
    allow_components: boolean;
    allow_addons: boolean;
    components_restockable_default: boolean;
    active: boolean;
    fields: CustomOrderField[];
}

interface Props {
    enabled: boolean;
    onEnabledChange: (on: boolean) => void;
    templates: CustomOrderTemplate[];
    fieldTypes: { value: string; label: string }[];
}

/** الأنواعُ التي لها قائمةُ خيارات — ونظيرُها `CustomOrderField::OPTION_TYPES` */
const OPTION_TYPES = ['select', 'multi_select'];

/** قالبٌ فارغ — لا قالبُ صناعة */
const BLANK: CustomOrderTemplate = {
    id: 0,
    name: '',
    name_en: '',
    modes: ['value', 'budget'],
    default_mode: 'value',
    base_label: '',
    base_label_en: '',
    allow_components: true,
    allow_addons: true,
    components_restockable_default: false,
    active: true,
    fields: [],
};

export default function CustomOrdersPanel({ enabled, onEnabledChange, templates, fieldTypes }: Props) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [editing, setEditing] = useState<CustomOrderTemplate | null>(null);

    const move = (at: number, by: number) => {
        const next = [...templates];
        const to = at + by;
        if (to < 0 || to >= next.length) return;
        [next[at], next[to]] = [next[to], next[at]];
        router.post(
            route('admin.customOrders.reorder'),
            { ids: next.map((x) => x.id) },
            { preserveScroll: true },
        );
    };

    const remove = async (tpl: CustomOrderTemplate) => {
        // والطلباتُ الماضية تبقى: كلٌّ منها يحمل لقطةَ اسمه وحقوله
        if (!(await ask({ message: 'يُحذف القالب ولا تتغيّر الطلبات التي بيعت به. أتمضي؟', danger: true, action: 'حذف' }))) {
            return;
        }

        router.delete(route('admin.customOrders.destroy', tpl.id), { preserveScroll: true });
    };

    return (
        <>
            <SettingsSection
                title="الطلبات المخصصة"
                description="طلبٌ يُركَّب عند الصندوق بسعرٍ يقوله الزبون، وموادُّه من مخزونك."
            >
                <SettingsGroup title="التفعيل">
                    <Toggle
                        on={enabled}
                        onChange={onEnabledChange}
                        label={t('تفعيل الطلبات المخصصة')}
                        hint={t('عند الإطفاء لا يظهر الزر في نقطة البيع ولا يُقبل طلب جديد — والطلبات السابقة وقوالبها تبقى كما هي.')}
                    />
                </SettingsGroup>

                <SettingsGroup title="القوالب">
                    {templates.length === 0 ? (
                        <p className="py-3 text-sm text-[#9ca3af]">
                            {t('لا قوالب بعد. أضف قالبًا وسمِّه كما تسمّيه لزبونك.')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {templates.map((tpl, at) => (
                                <li key={tpl.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <span className="flex shrink-0 flex-col">
                                        <button
                                            type="button"
                                            onClick={() => move(at, -1)}
                                            disabled={at === 0}
                                            className="text-[10px] leading-none text-[#9ca3af] hover:text-[#111] disabled:opacity-30"
                                            aria-label={t('تحريك لأعلى')}
                                        >
                                            ▲
                                        </button>
                                        <GripVertical className="size-3 text-[#d1d5db]" />
                                        <button
                                            type="button"
                                            onClick={() => move(at, 1)}
                                            disabled={at === templates.length - 1}
                                            className="text-[10px] leading-none text-[#9ca3af] hover:text-[#111] disabled:opacity-30"
                                            aria-label={t('تحريك لأسفل')}
                                        >
                                            ▼
                                        </button>
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium text-[#111]">{tpl.name}</span>
                                        <span className="mt-0.5 block text-xs text-[#9ca3af]">
                                            {/*
                                              * «الحقول: ٣» لا «٣ حقل».
                                              *
                                              * مترجمُ الواجهة لا يعرف صيغةَ الجمع (لا `|` ولا
                                              * `trans_choice`)، فصيغةٌ تُلحق المعدودَ بالعدد تقرأ
                                              * «1 fields» في الإنجليزية. والصيغةُ المفصولة صحيحةٌ
                                              * في اللغتين ولكلّ عدد.
                                              */}
                                            {t('الحقول: :count', { count: tpl.fields.length })}
                                        </span>
                                    </span>

                                    <StatusPill state={tpl.active ? 'ready' : 'off'} label={tpl.active ? 'نشط' : 'موقوف'} />

                                    <Button type="button" size="sm" variant="outline" onClick={() => setEditing(tpl)}>
                                        <Pencil className="size-3.5" />
                                        {t('تعديل')}
                                    </Button>
                                    <Button type="button" size="sm" variant="outline" onClick={() => remove(tpl)}>
                                        <Trash2 className="size-3.5 text-[#ef4444]" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <Button type="button" variant="outline" className="mt-3" onClick={() => setEditing({ ...BLANK })}>
                        <Plus className="size-4" />
                        {t('إضافة قالب')}
                    </Button>
                </SettingsGroup>
            </SettingsSection>

            {confirmDialog}

            {editing && (
                <TemplateEditor
                    template={editing}
                    fieldTypes={fieldTypes}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

/** محرّرُ قالبٍ واحد — يُحفظ كلُّه بطلبٍ واحد، فلا يبقى نصفَ محفوظ */
function TemplateEditor({
    template,
    fieldTypes,
    onClose,
}: {
    template: CustomOrderTemplate;
    fieldTypes: { value: string; label: string }[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const [form, setForm] = useState<CustomOrderTemplate>(() => ({
        ...template,
        fields: template.fields.map((f) => ({ ...f, options: (f.options ?? []).map((o) => ({ ...o })) })),
    }));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const set = <K extends keyof CustomOrderTemplate>(key: K, value: CustomOrderTemplate[K]) =>
        setForm((p) => ({ ...p, [key]: value }));

    const setField = (at: number, patch: Partial<CustomOrderField>) =>
        setForm((p) => ({ ...p, fields: p.fields.map((f, i) => (i === at ? { ...f, ...patch } : f)) }));

    const toggleMode = (mode: string) => {
        const next = form.modes.includes(mode) ? form.modes.filter((m) => m !== mode) : [...form.modes, mode];
        // ولا يبقى قالبٌ بلا طريقة تسعير: مقبضٌ يُطفئ آخرَ ضوءٍ في الغرفة
        if (next.length === 0) return;
        set('modes', next);
    };

    const save = () => {
        setBusy(true);
        /*
         * والحمولةُ تُمرَّر كما هي — `RequestPayload` تصف حقلًا مسطّحًا،
         * وهذه شجرة. وInertia تُسلسلها JSON فتصل الخادمَ كاملة، والتحقّقُ
         * هناك يقرؤها بقواعد النجمة. فالتحويلُ إعلانُ نيّةٍ لا تجاوزُ فحص.
         */
        const payload = { ...form, fields: form.fields.map((f, at) => ({ ...f, sort_order: at })) } as unknown as Record<string, never>;
        const done = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (e: Record<string, string>) => setErrors(e),
            onFinish: () => setBusy(false),
        };

        if (form.id) {
            router.put(route('admin.customOrders.update', form.id), payload, done);
        } else {
            router.post(route('admin.customOrders.store'), payload, done);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            {/* ولا سقفَ يُكتب هنا: `DialogContent` يقيسه على المرئيّ لا على الشاشة */}
            <DialogContent className="w-[min(46rem,94vw)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{form.id ? t('تعديل القالب') : t('إنشاء قالب طلب مخصص')}</DialogTitle>
                </DialogHeader>

                {/* والحشوُ على الجسم: `DialogContent` لا يحمله عنه */}
                <div className="space-y-5 px-5 pb-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('الاسم بالعربية')} required error={errors.name}>
                            <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
                        </Field>
                        <Field label={t('الاسم بالإنجليزية')} error={errors.name_en}>
                            <Input value={form.name_en ?? ''} onChange={(e) => set('name_en', e.target.value)} />
                        </Field>
                    </div>

                    <Field label={t('طرق التسعير')} required error={errors.modes}>
                        <div className="flex flex-wrap gap-2">
                            {[
                                { key: 'value', label: t('قيمة أساسية + إضافات') },
                                { key: 'budget', label: t('السعر النهائي') },
                            ].map((m) => (
                                <button
                                    key={m.key}
                                    type="button"
                                    onClick={() => toggleMode(m.key)}
                                    className={
                                        'rounded-[10px] border px-3 py-2 text-sm transition ' +
                                        (form.modes.includes(m.key)
                                            ? 'border-[#111] bg-[#111] text-white'
                                            : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#374151]')
                                    }
                                >
                                    {m.label}
                                </button>
                            ))}
                        </div>
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('اسم القيمة الأساسية بالعربية')} hint={t('يُكتب فوق حقل السعر في الصندوق')}>
                            <Input
                                value={form.base_label ?? ''}
                                onChange={(e) => set('base_label', e.target.value)}
                                placeholder={t('القيمة الأساسية')}
                            />
                        </Field>
                        <Field label={t('اسم القيمة الأساسية بالإنجليزية')}>
                            <Input
                                value={form.base_label_en ?? ''}
                                onChange={(e) => set('base_label_en', e.target.value)}
                                placeholder="Base Value"
                            />
                        </Field>
                    </div>

                    <div className="space-y-2 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                        <Toggle
                            on={form.allow_components}
                            onChange={(v) => set('allow_components', v)}
                            label={t('السماح باختيار مكونات من المنتجات')}
                            hint={t('ما يُختار منها يُخصم من المخزون وتُحسب تكلفته.')}
                        />
                        <Toggle
                            on={form.allow_addons}
                            onChange={(v) => set('allow_addons', v)}
                            label={t('السماح بالإضافات')}
                        />
                        <Toggle
                            on={form.components_restockable_default}
                            onChange={(v) => set('components_restockable_default', v)}
                            label={t('إرجاع المكونات للمخزون عند الإلغاء')}
                            hint={t('الافتراض لمكونات هذا القالب — ويُغيَّر لكل مكوّن في الصندوق.')}
                        />
                        <Toggle on={form.active} onChange={(v) => set('active', v)} label={t('نشط')} />
                    </div>

                    <div>
                        <p className="mb-2 text-[13px] font-semibold text-[#6b7280]">{t('الحقول')}</p>

                        <ul className="space-y-3">
                            {form.fields.map((f, at) => (
                                <li key={at} className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                    <div className="mb-2 flex items-start gap-2">
                                        <div className="grid flex-1 gap-3 sm:grid-cols-2">
                                            <Field label={t('اسم الحقل بالعربية')} required>
                                                <Input value={f.label} onChange={(e) => setField(at, { label: e.target.value })} />
                                            </Field>
                                            <Field label={t('اسم الحقل بالإنجليزية')}>
                                                <Input
                                                    value={f.label_en ?? ''}
                                                    onChange={(e) => setField(at, { label_en: e.target.value })}
                                                />
                                            </Field>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setForm((p) => ({ ...p, fields: p.fields.filter((_, i) => i !== at) }))}
                                            className="mt-6 flex size-7 shrink-0 items-center justify-center rounded-full text-[#ef4444] hover:bg-[#fef2f2]"
                                            aria-label={t('حذف')}
                                        >
                                            <X className="size-4" />
                                        </button>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field label={t('نوع الحقل')} required>
                                            <Select
                                                value={f.type}
                                                onChange={(e) => setField(at, { type: e.target.value })}
                                                options={fieldTypes.map((x) => ({ value: x.value, label: x.label }))}
                                            />
                                        </Field>
                                        <Field label={t('الظهور')}>
                                            <Select
                                                value={f.internal ? 'internal' : 'customer'}
                                                onChange={(e) => setField(at, { internal: e.target.value === 'internal' })}
                                                options={[
                                                    { value: 'customer', label: t('يظهر للعميل') },
                                                    { value: 'internal', label: t('داخلي — للتجهيز فقط') },
                                                ]}
                                            />
                                        </Field>
                                    </div>

                                    <div className="mt-2">
                                        <Toggle
                                            on={!!f.required}
                                            onChange={(v) => setField(at, { required: v })}
                                            label={t('إلزامي')}
                                        />
                                    </div>

                                    {OPTION_TYPES.includes(f.type) && (
                                        <div className="mt-3 rounded-[10px] bg-[#fafafa] p-2">
                                            <p className="mb-2 text-xs font-medium text-[#6b7280]">{t('الخيارات')}</p>
                                            <ul className="space-y-2">
                                                {(f.options ?? []).map((o, oi) => (
                                                    <li key={oi} className="flex items-center gap-2">
                                                        <Input
                                                            value={o.label}
                                                            onChange={(e) =>
                                                                setField(at, {
                                                                    options: (f.options ?? []).map((x, i) =>
                                                                        i === oi ? { ...x, label: e.target.value } : x,
                                                                    ),
                                                                })
                                                            }
                                                            placeholder={t('بالعربية')}
                                                            className="h-9"
                                                        />
                                                        <Input
                                                            value={o.label_en ?? ''}
                                                            onChange={(e) =>
                                                                setField(at, {
                                                                    options: (f.options ?? []).map((x, i) =>
                                                                        i === oi ? { ...x, label_en: e.target.value } : x,
                                                                    ),
                                                                })
                                                            }
                                                            placeholder={t('بالإنجليزية')}
                                                            className="h-9"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setField(at, {
                                                                    options: (f.options ?? []).filter((_, i) => i !== oi),
                                                                })
                                                            }
                                                            className="flex size-7 shrink-0 items-center justify-center rounded-full text-[#ef4444] hover:bg-[#fef2f2]"
                                                            aria-label={t('حذف')}
                                                        >
                                                            <X className="size-4" />
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="mt-2"
                                                onClick={() =>
                                                    setField(at, { options: [...(f.options ?? []), { label: '', label_en: '' }] })
                                                }
                                            >
                                                <Plus className="size-3.5" />
                                                {t('إضافة خيار')}
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>

                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3"
                            onClick={() =>
                                setForm((p) => ({
                                    ...p,
                                    fields: [...p.fields, { label: '', label_en: '', type: 'short_text', options: [] }],
                                }))
                            }
                        >
                            <Plus className="size-4" />
                            {t('إضافة حقل')}
                        </Button>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="button" onClick={save} loading={busy}>
                            {t('حفظ')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
