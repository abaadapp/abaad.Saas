import { useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Hash, Link2, MessageSquareText, Plus, Send, Sparkles, X } from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';
import Field, { Select } from '@/Components/Field';
import { SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface AutomationButton {
    type: 'reply' | 'link';
    title: string;
    url?: string;
}

export interface AutomationRow {
    id: number;
    name: string;
    template: string | null;
    match: 'contains' | 'exact';
    keywords: string[];
    excluded: string[];
    reply_ar: string;
    reply_en: string;
    buttons: AutomationButton[];
    product_id: number | null;
    status: 'draft' | 'published' | 'paused';
    cooldown_minutes: number;
    counts: { matched: number; sent: number; failed: number };
    last_run_at: string | null;
}

export interface AutomationTemplate {
    key: string;
    name: string;
    description: string;
    keywords: string[];
    reply_ar: string;
    reply_en: string;
    buttons: AutomationButton[];
    needs_product: boolean;
}

export interface AutomationProduct {
    id: number;
    name: string;
    price: string;
    link: string | null;
}

export interface AutomationLimits {
    automations: number;
    keywords: number;
    keyword: number;
    name: number;
    text: number;
    buttons: number;
    button_title: number;
    cooldown_max: number;
}

export interface WizardContext {
    store: string;
    store_link: string | null;
}

/** ما يُحفظ — كما يقبله `WhatsAppAutomationsController::validated` */
export interface AutomationDraft {
    name: string;
    template: string | null;
    match: 'contains' | 'exact';
    keywords: string[];
    excluded: string[];
    reply_ar: string;
    reply_en: string;
    buttons: AutomationButton[];
    product_id: number | null;
    cooldown_minutes: number;
    status: 'draft' | 'published';
}

/** المتغيّراتُ — كما في `ChannelAutomations::VARIABLES` */
export const VARIABLES: { token: string; en: string; label: string }[] = [
    { token: '{الاسم}', en: '{name}', label: 'اسم الزبون' },
    { token: '{المتجر}', en: '{store}', label: 'اسم المتجر' },
    { token: '{رابط_المتجر}', en: '{store_link}', label: 'رابط المتجر' },
    { token: '{المنتج}', en: '{product}', label: 'المنتج' },
    { token: '{السعر}', en: '{price}', label: 'السعر' },
    { token: '{رابط_المنتج}', en: '{product_link}', label: 'رابط المنتج' },
    { token: '{آخر_طلب}', en: '{last_order}', label: 'حالة آخر طلب' },
];

/** ما بين قوسين ينتظر أن يكتبه التاجر — كما في `ChannelAutomations::PLACEHOLDER` */
export const PLACEHOLDER = /\[[^\]\n]{1,80}\]/u;

export const COOLDOWNS = [5, 15, 30, 60, 180, 720, 1440];

export const blankDraft = (): AutomationDraft => ({
    name: '',
    template: null,
    match: 'contains',
    keywords: [],
    excluded: [],
    reply_ar: '',
    reply_en: '',
    buttons: [],
    product_id: null,
    cooldown_minutes: 60,
    status: 'published',
});

export const draftFrom = (a: AutomationRow): AutomationDraft => ({
    name: a.name,
    template: a.template,
    match: a.match,
    keywords: a.keywords,
    excluded: a.excluded,
    reply_ar: a.reply_ar,
    reply_en: a.reply_en,
    buttons: a.buttons,
    product_id: a.product_id,
    cooldown_minutes: a.cooldown_minutes,
    status: a.status === 'draft' ? 'draft' : 'published',
});

export const draftFromTemplate = (tpl: AutomationTemplate): AutomationDraft => ({
    ...blankDraft(),
    name: tpl.name,
    template: tpl.key,
    keywords: tpl.keywords,
    reply_ar: tpl.reply_ar,
    reply_en: tpl.reply_en,
    buttons: tpl.buttons,
});

/**
 * ‏‏نصُّ الردّ كما يصل الزبون في المعاينة — بقيمٍ مثالٍ للاسم والطلب، وبمتجر التاجر ومنتجه الحقيقيّين.
 * والمتغيّرُ بلا قيمة يُحذف كما يحذفه الخادم (`ChannelAutomations::render`).
 */
export function renderReply(text: string, values: Record<string, string>): string {
    let out = text;

    for (const v of VARIABLES) {
        const key = v.en.slice(1, -1);
        out = out.split(v.token).join(values[key] ?? '').split(v.en).join(values[key] ?? '');
    }

    return out.replace(/[ \t]+([،,.!؟?])/gu, '$1').replace(/[ \t]{2,}/gu, ' ').trim();
}

/** ‏‏أيمنع شيءٌ النشر؟ — كما يرفضه الخادم (`ChannelAutomations::unpublishable`) */
export function publishProblem(d: Pick<AutomationDraft, 'keywords' | 'reply_ar' | 'reply_en'>): string | null {
    if (d.keywords.length === 0) {
        return 'اكتب كلمة واحدة على الأقل يكتبها الزبون.';
    }

    if (d.reply_ar.trim() === '' && d.reply_en.trim() === '') {
        return 'اكتب نص الرد قبل النشر.';
    }

    if (PLACEHOLDER.test(`${d.reply_ar} ${d.reply_en}`)) {
        return 'أكمل ما بين القوسين [ ] في نص الرد قبل النشر.';
    }

    return null;
}

function Chips({ value, onChange, max, maxLength, placeholder, testId }: {
    value: string[];
    onChange: (next: string[]) => void;
    max: number;
    maxLength: number;
    placeholder: string;
    testId: string;
}) {
    const t = useTranslate();
    const [text, setText] = useState('');

    const add = () => {
        const parts = text.split(/[,،\n]/u).map((p) => p.trim()).filter(Boolean);
        const next = [...value];

        for (const p of parts) {
            if (next.length < max && ! next.includes(p)) {
                next.push(p.slice(0, maxLength));
            }
        }

        onChange(next);
        setText('');
    };

    const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',' || e.key === '،') {
            e.preventDefault();
            add();
        }
    };

    return (
        <div className="space-y-2" data-testid={testId}>
            <div className="flex flex-wrap gap-1.5">
                {value.map((w) => (
                    <span key={w} className="inline-flex items-center gap-1 rounded-full bg-[#f3f4f6] px-2.5 py-1 text-[12px] text-[#111]">
                        {w}
                        <button type="button" aria-label={t('إزالة')} onClick={() => onChange(value.filter((x) => x !== w))} className="text-[#6b7280] hover:text-[#111]">
                            <X className="size-3" />
                        </button>
                    </span>
                ))}
            </div>
            <div className="flex gap-2">
                <Input value={text} onChange={(e) => setText(e.target.value)} onKeyDown={onKey} onBlur={add} placeholder={t(placeholder)} maxLength={maxLength * 4} />
                <Button type="button" variant="outline" onClick={add} disabled={value.length >= max}>
                    <Plus />
                    {t('أضف')}
                </Button>
            </div>
        </div>
    );
}

/**
 * ‏‏المعالج — ثلاثُ خطوات: ما يشغّلها، ومن يصله الردّ، والردّ نفسُه. وبجانبها معاينةُ المحادثة.
 */
export default function AutomationWizard({ initial, editingId, products, context, limits, mayManage, onClose, onWelcome }: {
    initial: AutomationDraft;
    editingId: number | null;
    products: AutomationProduct[];
    context: WizardContext;
    limits: AutomationLimits;
    mayManage: boolean;
    onClose: () => void;
    onWelcome?: () => void;
}) {
    const t = useTranslate();
    const [step, setStep] = useState(1);
    const [lang, setLang] = useState<'ar' | 'en'>('ar');
    const form = useForm<AutomationDraft>(initial);
    const d = form.data;

    const product = products.find((p) => p.id === d.product_id) ?? null;
    const usesProduct = /\{(المنتج|السعر|رابط_المنتج|product|price|product_link)\}/u.test(`${d.reply_ar} ${d.reply_en}`)
        || d.buttons.some((b) => b.url === '{رابط_المنتج}' || b.url === '{product_link}');
    const linkButton = d.buttons.find((b) => b.type === 'link') ?? null;
    const buttonMode: 'none' | 'reply' | 'link' = linkButton ? 'link' : d.buttons.length > 0 ? 'reply' : 'none';

    const sample = {
        name: t('أحمد'),
        store: context.store,
        store_link: context.store_link ?? '',
        product: product?.name ?? '',
        price: product?.price ?? '',
        product_link: product?.link ?? context.store_link ?? '',
        last_order: lang === 'en' ? 'Your order 1024: Preparing' : 'طلبك رقم 1024: قيد التجهيز',
    };
    const replyText = lang === 'en' ? (d.reply_en || d.reply_ar) : (d.reply_ar || d.reply_en);
    const preview = renderReply(replyText, sample);
    const problem = publishProblem(d);

    const insert = (token: string) => {
        const key = lang === 'en' ? 'reply_en' : 'reply_ar';
        const tok = lang === 'en' ? (VARIABLES.find((v) => v.token === token)?.en ?? token) : token;
        form.setData(key, `${d[key]}${d[key] && ! d[key].endsWith(' ') && ! d[key].endsWith('\n') ? ' ' : ''}${tok}`.slice(0, limits.text));
    };

    const setButtonMode = (mode: 'none' | 'reply' | 'link') => {
        form.setData('buttons', mode === 'none' ? [] : mode === 'link'
            ? [{ type: 'link', title: t('افتح المتجر'), url: '{رابط_المتجر}' }]
            : [{ type: 'reply', title: '' }]);
    };

    const save = (status: 'draft' | 'published') => {
        form.transform((data) => ({ ...data, status, buttons: data.buttons.filter((b) => b.title.trim() !== '') }));
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (editingId === null) {
            form.post(route('admin.integrations.whatsapp.automations.store'), options);
        } else {
            form.put(route('admin.integrations.whatsapp.automations.update', editingId), options);
        }
    };

    const STEPS = ['اختر ما يشغّلها', 'حدّد من يصله الرد', 'جهّز الرد'];

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]" data-testid="automation-wizard">
            <SettingsSection title={editingId === null ? 'أتمتة جديدة' : 'تعديل الأتمتة'} icon={Sparkles}>
                <ol className="mb-6 flex flex-wrap gap-2" data-testid="wizard-steps">
                    {STEPS.map((label, i) => (
                        <li key={label}>
                            <button
                                type="button"
                                onClick={() => setStep(i + 1)}
                                aria-current={step === i + 1 ? 'step' : undefined}
                                className={`inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[12px] font-medium ${step === i + 1 ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#4b4b4b]'}`}
                            >
                                <span className="tabular-nums">{i + 1}</span>
                                {t(label)}
                            </button>
                        </li>
                    ))}
                </ol>

                {step === 1 && (
                    <div className="grid gap-3 sm:grid-cols-2" data-testid="wizard-trigger">
                        <div className="rounded-[12px] border-2 border-[#111] p-4">
                            <Hash className="mb-2 size-5" />
                            <p className="font-bold text-[#111]">{t('كلمة في الرسالة')}</p>
                            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">{t('حين يكتب الزبون كلمة تحددها — مثل «السعر» أو «التوصيل».')}</p>
                        </div>
                        <button type="button" onClick={onWelcome} className="rounded-[12px] border border-[#e5e7eb] p-4 text-start hover:border-[#111]" data-testid="wizard-trigger-welcome">
                            <MessageSquareText className="mb-2 size-5" />
                            <p className="font-bold text-[#111]">{t('أول رسالة في محادثة جديدة')}</p>
                            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">{t('يرد عليها الترحيب — وتعدّله من صفحة واتساب.')}</p>
                        </button>
                    </div>
                )}

                {step === 2 && (
                    <div className="space-y-5">
                        <Field label="الكلمات التي يكتبها الزبون" hint="اكتب الكلمة واضغط Enter. «السعر» تطابق «السّعر؟» و«سعره» أيضًا." error={form.errors.keywords}>
                            <Chips value={d.keywords} onChange={(v) => form.setData('keywords', v)} max={limits.keywords} maxLength={limits.keyword} placeholder="مثل: السعر، بكم" testId="wizard-keywords" />
                        </Field>
                        <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" data-testid="wizard-match">
                            {([['contains', 'في أي موضع من الرسالة', '«كم سعر الباقة» فيها «سعر»'], ['exact', 'الرسالة كلها هي الكلمة', '«سعر» وحدها لا غير']] as const).map(([value, label, hint]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={d.match === value}
                                    onClick={() => form.setData('match', value)}
                                    className={`rounded-[12px] border p-3 text-start ${d.match === value ? 'border-2 border-[#111]' : 'border-[#e5e7eb]'}`}
                                >
                                    <p className="text-[13px] font-bold text-[#111]">{t(label)}</p>
                                    <p className="mt-0.5 text-[12px] text-[#6b7280]">{t(hint)}</p>
                                </button>
                            ))}
                        </div>
                        <Field label="كلمات تمنع الرد (اختياري)" hint="إن كتبها الزبون لا يخرج هذا الرد — مثل «إلغاء»." error={form.errors.excluded}>
                            <Chips value={d.excluded} onChange={(v) => form.setData('excluded', v)} max={limits.keywords} maxLength={limits.keyword} placeholder="مثل: إلغاء" testId="wizard-excluded" />
                        </Field>
                    </div>
                )}

                {step === 3 && (
                    <div className="space-y-5">
                        <Field label="اسم الأتمتة" hint="يراه فريقك في المحادثة وفي القائمة — لا يراه الزبون." error={form.errors.name} required>
                            <Input value={d.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={limits.name} data-testid="wizard-name" />
                        </Field>

                        <div className="flex gap-2" role="tablist">
                            {(['ar', 'en'] as const).map((l) => (
                                <button key={l} type="button" role="tab" aria-selected={lang === l} onClick={() => setLang(l)}
                                    className={`rounded-full px-3 py-1 text-[12px] font-medium ${lang === l ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#4b4b4b]'}`}>
                                    {l === 'ar' ? t('بالعربية') : t('بالإنجليزية')}
                                </button>
                            ))}
                        </div>
                        <Field
                            label={lang === 'ar' ? 'نص الرد' : 'نص الرد بالإنجليزية (اختياري)'}
                            hint={lang === 'ar' ? 'يصل بالعربية لمن لا نعرف لغته.' : 'يصل لمن لغته في ملفه الإنجليزية — وإن تركته يصله العربي.'}
                            error={lang === 'ar' ? form.errors.reply_ar : form.errors.reply_en}
                        >
                            <Textarea
                                value={lang === 'ar' ? d.reply_ar : d.reply_en}
                                onChange={(e) => form.setData(lang === 'ar' ? 'reply_ar' : 'reply_en', e.target.value)}
                                maxLength={limits.text}
                                className="min-h-28"
                                dir={lang === 'ar' ? 'rtl' : 'ltr'}
                                data-testid="wizard-reply"
                            />
                        </Field>
                        <div className="flex flex-wrap gap-1.5" data-testid="wizard-variables">
                            {VARIABLES.map((v) => (
                                <button key={v.token} type="button" onClick={() => insert(v.token)} className="rounded-full border border-[#e5e7eb] px-2.5 py-1 text-[12px] text-[#4b4b4b] hover:border-[#111]">
                                    + {t(v.label)}
                                </button>
                            ))}
                        </div>

                        {(usesProduct || d.product_id !== null) && (
                            <Field label="المنتج" hint="اسمه وسعره ورابطه يصلون كما هم في متجرك لحظة الرد." error={form.errors.product_id}>
                                <Select
                                    value={d.product_id ?? ''}
                                    onChange={(e) => form.setData('product_id', e.target.value === '' ? null : Number(e.target.value))}
                                    options={[{ value: '', label: t('اختر منتجًا') }, ...products.map((p) => ({ value: p.id, label: `${p.name} — ${p.price}` }))]}
                                />
                            </Field>
                        )}

                        <Field label="الأزرار (اختياري)" hint="ثلاثة أزرار رد، أو زر رابط واحد — واتساب لا يجمع بينهما." error={form.errors.buttons}>
                            <div className="space-y-3" data-testid="wizard-buttons">
                                <div className="flex flex-wrap gap-2">
                                    {([['none', 'بلا أزرار'], ['reply', 'أزرار رد'], ['link', 'زر رابط']] as const).map(([mode, label]) => (
                                        <button key={mode} type="button" onClick={() => setButtonMode(mode)} aria-pressed={buttonMode === mode}
                                            className={`rounded-full px-3 py-1 text-[12px] font-medium ${buttonMode === mode ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#4b4b4b]'}`}>
                                            {t(label)}
                                        </button>
                                    ))}
                                </div>
                                {buttonMode === 'reply' && (
                                    <div className="space-y-2">
                                        {d.buttons.map((b, i) => (
                                            <div key={i} className="flex gap-2">
                                                <Input value={b.title} maxLength={limits.button_title} placeholder={t('عنوان الزر — مثل: السعر')}
                                                    onChange={(e) => form.setData('buttons', d.buttons.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} />
                                                <Button type="button" variant="outline" aria-label={t('إزالة')} onClick={() => form.setData('buttons', d.buttons.filter((_, j) => j !== i))}>
                                                    <X />
                                                </Button>
                                            </div>
                                        ))}
                                        {d.buttons.length < limits.buttons && (
                                            <Button type="button" variant="outline" onClick={() => form.setData('buttons', [...d.buttons, { type: 'reply', title: '' }])}>
                                                <Plus />
                                                {t('زر آخر')}
                                            </Button>
                                        )}
                                        <p className="text-[12px] leading-relaxed text-[#9ca3af]">{t('ضغطة الزر تصل بعنوانه — فاجعل عنوانه كلمة أتمتة أخرى ليصل ردها.')}</p>
                                    </div>
                                )}
                                {buttonMode === 'link' && linkButton && (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <Input value={linkButton.title} maxLength={limits.button_title} placeholder={t('عنوان الزر')}
                                            onChange={(e) => form.setData('buttons', [{ ...linkButton, title: e.target.value }])} />
                                        <Select
                                            value={['{رابط_المتجر}', '{رابط_المنتج}'].includes(linkButton.url ?? '') ? (linkButton.url as string) : 'custom'}
                                            onChange={(e) => form.setData('buttons', [{ ...linkButton, url: e.target.value === 'custom' ? 'https://' : e.target.value }])}
                                            options={[
                                                { value: '{رابط_المتجر}', label: t('رابط المتجر') },
                                                { value: '{رابط_المنتج}', label: t('رابط المنتج') },
                                                { value: 'custom', label: t('رابط آخر') },
                                            ]}
                                        />
                                        {! ['{رابط_المتجر}', '{رابط_المنتج}'].includes(linkButton.url ?? '') && (
                                            <Input className="sm:col-span-2" dir="ltr" value={linkButton.url ?? ''} placeholder="https://"
                                                onChange={(e) => form.setData('buttons', [{ ...linkButton, url: e.target.value }])} />
                                        )}
                                        {form.errors['buttons.0.url' as keyof AutomationDraft] && (
                                            <p className="text-[12px] text-[#b91c1c] sm:col-span-2">{form.errors['buttons.0.url' as keyof AutomationDraft]}</p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </Field>

                        <Field label="لا يتكرر للزبون نفسه قبل" hint="من سأل عن السعر الآن لا يصله الرد نفسه ثانية قبل هذه المدة." error={form.errors.cooldown_minutes}>
                            <Select
                                value={d.cooldown_minutes}
                                onChange={(e) => form.setData('cooldown_minutes', Number(e.target.value))}
                                options={COOLDOWNS.map((m) => ({ value: m, label: m < 60 ? t(':n دقيقة', { n: m }) : t(':n ساعة', { n: m / 60 }) }))}
                            />
                        </Field>
                    </div>
                )}

                {(form.errors as Record<string, string>).status && (
                    <p className="mt-4 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]" data-testid="wizard-error">
                        {(form.errors as Record<string, string>).status}
                    </p>
                )}

                <div className="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-[#f0f0f0] pt-4">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('إلغاء')}
                    </Button>
                    <div className="flex flex-wrap gap-2">
                        {step > 1 && (
                            <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                                <ArrowRight className="rtl:rotate-0 ltr:rotate-180" />
                                {t('رجوع')}
                            </Button>
                        )}
                        {step < 3 ? (
                            <Button type="button" onClick={() => setStep(step + 1)} data-testid="wizard-next">
                                {t('التالي')}
                                <ArrowLeft className="rtl:rotate-0 ltr:rotate-180" />
                            </Button>
                        ) : (
                            <>
                                <Button type="button" variant="outline" disabled={! mayManage || form.processing} onClick={() => save('draft')} data-testid="wizard-draft">
                                    {t('احفظ مسوّدة')}
                                </Button>
                                <Button type="button" disabled={! mayManage || form.processing || problem !== null} onClick={() => save('published')} data-testid="wizard-publish">
                                    <Send />
                                    {t('انشر')}
                                </Button>
                            </>
                        )}
                    </div>
                </div>
                {step === 3 && problem && (
                    <p className="mt-2 text-[12px] text-[#b45309]" data-testid="wizard-problem">{t(problem)}</p>
                )}
            </SettingsSection>

            <ChatPreview customer={d.keywords[0] ?? t('كم السعر؟')} reply={preview} buttons={d.buttons.filter((b) => b.title.trim() !== '')} store={context.store} />
        </div>
    );
}

/** ‏‏معاينةُ المحادثة الناتجة — رسالةُ الزبون، ثمّ الردُّ بأزراره */
export function ChatPreview({ customer, reply, buttons, store }: { customer: string; reply: string; buttons: AutomationButton[]; store: string }) {
    const t = useTranslate();

    return (
        <aside className="h-fit rounded-[16px] bg-[#efeae2] p-4" data-testid="chat-preview" aria-label={t('معاينة المحادثة')}>
            <p className="mb-3 text-center text-[11px] font-medium text-[#6b7280]">{t('معاينة')} · {store}</p>
            <div className="space-y-2">
                <div className="ms-auto w-fit max-w-[85%] rounded-[12px] rounded-se-none bg-white px-3 py-2 text-[13px] text-[#111] shadow-sm" data-testid="preview-customer">
                    {customer}
                </div>
                {reply !== '' ? (
                    <div className="w-fit max-w-[85%]">
                        <div className="whitespace-pre-line rounded-[12px] rounded-ss-none bg-[#d9fdd3] px-3 py-2 text-[13px] leading-relaxed text-[#111] shadow-sm" data-testid="preview-reply">
                            {reply}
                        </div>
                        {buttons.map((b, i) => (
                            <div key={i} className="mt-1 flex items-center justify-center gap-1 rounded-[10px] bg-white px-3 py-1.5 text-[13px] font-medium text-[#027eb5] shadow-sm" data-testid="preview-button">
                                {b.type === 'link' && <Link2 className="size-3.5" />}
                                {b.title}
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-[12px] text-[#6b7280]">{t('اكتب نص الرد لترى كيف يصل.')}</p>
                )}
            </div>
        </aside>
    );
}
