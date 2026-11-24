import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, BellOff, ListChecks, Megaphone, MessageSquareText, Pause, Play, Plus, RefreshCw, Send, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import Field, { Select } from '@/Components/Field';
import { SettingsPage, SettingsSection } from '@/Components/Settings';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Lang = 'ar' | 'en';

export interface CampaignTemplate {
    id: number;
    title: string;
    language: Lang;
    body: string;
    preview: string;
    footer: string | null;
    button: { title: string; url: string } | null;
    opt_out: string;
    status: string;
    label: string;
    why: string | null;
    usable: boolean;
    checked_at: string | null;
}

export interface CampaignCounts {
    pending: number;
    out: number;
    delivered: number;
    read: number;
    failed: number;
    skipped: number;
}

export interface CampaignRow {
    id: number;
    name: string;
    status: 'scheduled' | 'sending' | 'paused' | 'done' | 'cancelled';
    why: string | null;
    total: number;
    counts: CampaignCounts;
    body: string;
    failures: { why: string; n: number }[];
    scheduled_at: string | null;
    started_at: string | null;
    finished_at: string | null;
}

/** ما يعرضه الخادم — `WhatsAppCampaigns::view` */
export interface CampaignsView {
    may_manage: boolean;
    obstacle: string | null;
    number: string | null;
    store: string;
    store_link: string | null;
    templates: CampaignTemplate[];
    campaigns: CampaignRow[];
    optouts: { total: number; by: Record<string, number> };
    branches: { id: number; name: string }[];
    limits: {
        body: number; title: number; button: number; templates: number; days: number[]; quiet: number[]; recipients: number;
        per_hour: number; quiet_from: number; quiet_to: number; schedule_days: number;
    };
    footers: Record<Lang, string>;
    opt_out_titles: Record<Lang, string>;
}

interface Audience {
    segment: 'all' | 'recent' | 'away' | 'never';
    days: number;
    min_spent: string;
    branch_id: number | null;
    language: Lang | '';
    quiet_days: number;
}

interface CampaignDraft {
    name: string;
    template_id: number | null;
    audience: Audience;
    confirm_count: string;
    consent: boolean;
    scheduled_at: string;
}

interface AudienceCount {
    count: number;
    skipped: { no_phone: number; opted_out: number; recent: number; duplicate: number };
}

const STATUS: Record<CampaignRow['status'], { label: string; variant: 'success' | 'neutral' | 'warning' }> = {
    scheduled: { label: 'مجدولة', variant: 'neutral' },
    sending: { label: 'تُرسل الآن', variant: 'success' },
    paused: { label: 'موقوفة', variant: 'warning' },
    done: { label: 'انتهت', variant: 'neutral' },
    cancelled: { label: 'ملغاة', variant: 'neutral' },
};

const SEGMENT: Record<Audience['segment'], string> = {
    recent: 'اشتروا في المدة',
    away: 'لم يشتروا في المدة',
    never: 'لم يشتروا بعد',
    all: 'كل العملاء',
};

export const FEES_URL = 'https://developers.facebook.com/docs/whatsapp/pricing';

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '');

/** ‏‏الرسالةُ كما تصل — نصُّها وسطرُها الأخير وزرُّ الإيقاف الذي لا يُحذف */
export function OfferPreview({ body, footer, optOut, button }: { body: string; footer: string; optOut: string; button: string | null }) {
    const t = useTranslate();

    return (
        <aside className="h-fit rounded-[16px] bg-[#efeae2] p-4" data-testid="offer-preview" aria-label={t('معاينة الرسالة')}>
            <div className="w-full max-w-[280px] rounded-[12px] rounded-ss-none bg-white px-3 py-2 text-[13px] leading-relaxed text-[#111] shadow-sm">
                <p className="whitespace-pre-line break-words" data-testid="offer-body">{body || t('اكتب نص الرسالة لترى كيف تصل.')}</p>
                <p className="mt-1.5 text-[11px] text-[#8a8a8a]">{footer}</p>
            </div>
            {[optOut, button].filter(Boolean).map((b) => (
                <div key={b} className="mt-1 w-full max-w-[280px] rounded-[10px] bg-white px-3 py-1.5 text-center text-[13px] font-medium text-[#027eb5] shadow-sm" data-testid="offer-button">{b}</div>
            ))}
        </aside>
    );
}

function NewTemplate({ view, onClose }: { view: CampaignsView; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm<{ title: string; language: Lang; body: string; button: { title: string; url: string } }>({ title: '', language: 'ar', body: '', button: { title: '', url: '' } });
    const d = form.data;
    const errors = form.errors as Record<string, string>;
    const tokens: [string, string][] = d.language === 'en' ? [['{name}', 'اسم العميل'], ['{store}', 'اسم المتجر']] : [['{الاسم}', 'اسم العميل'], ['{المتجر}', 'اسم المتجر']];
    const shown = d.body
        .replaceAll('{الاسم}', 'سارة').replaceAll('{name}', 'Sara')
        .replaceAll('{المتجر}', view.store).replaceAll('{store}', view.store);

    const send = () => {
        form.transform((data) => ({ ...data, button: data.button.title.trim() === '' ? { title: '', url: '' } : data.button }));
        form.post(route('admin.marketing.whatsapp.campaigns.templates.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]" data-testid="template-form">
            <div className="space-y-4">
                <Field label="اسم القالب" hint="يراه فريقك وحده." error={errors.title} required>
                    <Input value={d.title} maxLength={view.limits.title} onChange={(e) => form.setData('title', e.target.value)} data-testid="template-title" />
                </Field>
                <div className="flex gap-2" role="radiogroup" aria-label={t('لغة القالب')}>
                    {(['ar', 'en'] as const).map((l) => (
                        <button key={l} type="button" role="radio" aria-checked={d.language === l} onClick={() => form.setData('language', l)}
                            className={`rounded-full px-3 py-1 text-[12px] font-medium ${d.language === l ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#4b4b4b]'}`}>
                            {l === 'ar' ? t('بالعربية') : t('بالإنجليزية')}
                        </button>
                    ))}
                </div>
                <Field label="نص الرسالة" hint="لا يبدأ بالاسم أو المتجر ولا ينتهي بهما — واتساب يرفضه." error={errors.body} required>
                    <Textarea value={d.body} maxLength={view.limits.body} dir={d.language === 'ar' ? 'rtl' : 'ltr'} className="min-h-28"
                        onChange={(e) => form.setData('body', e.target.value)} data-testid="template-body" />
                </Field>
                <div className="flex flex-wrap gap-1.5">
                    {tokens.map(([token, label]) => (
                        <button key={token} type="button" onClick={() => form.setData('body', `${d.body}${d.body && ! /\s$/.test(d.body) ? ' ' : ''}${token}`.slice(0, view.limits.body))}
                            className="rounded-full border border-[#e5e7eb] px-2.5 py-1 text-[12px] text-[#4b4b4b] hover:border-[#111]">
                            + {t(label)}
                        </button>
                    ))}
                </div>
                <Field label="زر رابط (اختياري)" error={errors['button.title'] ?? errors['button.url']}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Input value={d.button.title} maxLength={view.limits.button} placeholder={t('مثل: اطلب الآن')} onChange={(e) => form.setData('button', { ...d.button, title: e.target.value })} data-testid="template-button" />
                        <Input value={d.button.url} dir="ltr" placeholder={view.store_link ?? 'https://'} onChange={(e) => form.setData('button', { ...d.button, url: e.target.value })} data-testid="template-url" />
                    </div>
                    {view.store_link && d.button.title !== '' && d.button.url === '' && (
                        <button type="button" className="mt-1 text-[12px] text-[#1d4ed8]" onClick={() => form.setData('button', { ...d.button, url: view.store_link ?? '' })}>{t('استعمل رابط متجري')}</button>
                    )}
                </Field>
                <p className="rounded-[10px] bg-[#f9fafb] px-3 py-2 text-[12px] leading-relaxed text-[#6b7280]">
                    {t('يُضاف زر «إيقاف الرسائل» لكل قالب، فمن لا يريد عروضك يوقفها بضغطة. ويراجع واتساب القالب قبل أن تستعمله، غالبًا خلال ساعات.')}
                </p>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>{t('إلغاء')}</Button>
                    <Button type="button" onClick={send} disabled={form.processing || d.title.trim() === '' || d.body.trim() === ''} data-testid="template-send">
                        <Send />
                        {t('أرسل للمراجعة')}
                    </Button>
                </div>
            </div>
            <OfferPreview body={shown} footer={view.footers[d.language]} optOut={view.opt_out_titles[d.language]} button={d.button.title || null} />
        </div>
    );
}

function NewCampaign({ view, onClose }: { view: CampaignsView; onClose: () => void }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const usable = view.templates.filter((x) => x.usable);
    const form = useForm<CampaignDraft>({
        name: '', template_id: usable[0]?.id ?? null, confirm_count: '', consent: false, scheduled_at: '',
        audience: { segment: 'recent', days: 90, min_spent: '', branch_id: null, language: '', quiet_days: 3 },
    });
    const d = form.data;
    const a = d.audience;
    const errors = form.errors as Record<string, string>;
    const [found, setFound] = useState<AudienceCount | null>(null);
    const [failed, setFailed] = useState(false);
    const seq = useRef(0);
    const chosen = usable.find((x) => x.id === d.template_id) ?? null;

    const set = (patch: Partial<Audience>) => form.setData({ ...d, audience: { ...a, ...patch }, confirm_count: '' });

    // ‏‏العددُ يُسأل عنه بعد أن يهدأ الاختيار — وجوابٌ متأخّرٌ لاختيارٍ سابق لا يُعرض
    useEffect(() => {
        const mine = ++seq.current;
        setFound(null);
        setFailed(false);

        const timer = window.setTimeout(() => {
            fetch(route('admin.marketing.whatsapp.campaigns.preview'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ audience: a }),
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
                .then((r: AudienceCount) => { if (mine === seq.current) { setFound({ count: r.count, skipped: r.skipped }); } })
                .catch(() => { if (mine === seq.current) { setFailed(true); } });
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(a)]);

    const count = found?.count ?? 0;
    const tooMany = count > view.limits.recipients;
    const ready = d.name.trim() !== '' && chosen !== null && found !== null && count > 0 && ! tooMany && d.consent && d.confirm_count.trim() === String(count);

    const launch = async () => {
        const ok = await ask({
            title: 'إرسال الحملة',
            message: d.scheduled_at === ''
                ? 'تخرج :n رسالة من رقم متجرك، وتُحتسب كل رسالة تصل على حسابك عند Meta. ما خرج لا يُسترجع. متابعة؟'
                : 'تُجدول :n رسالة من رقم متجرك، وتُحتسب كل رسالة تصل على حسابك عند Meta. متابعة؟',
            values: { n: count },
            action: 'أرسل',
        });

        if (ok) {
            form.transform((data) => ({
                ...data,
                confirm_count: Number(data.confirm_count),
                // ‏‏وقتُ الجهاز بتوقيته — والخادمُ يقرؤه لحظةً لا نصًّا
                scheduled_at: data.scheduled_at === '' ? null : new Date(data.scheduled_at).toISOString(),
            }) as never);
            form.post(route('admin.marketing.whatsapp.campaigns.store'), { preserveScroll: true, onSuccess: onClose });
        }
    };

    if (usable.length === 0) {
        return (
            <div className="space-y-3" data-testid="campaign-no-template">
                <p className="text-[13px] text-[#6b7280]">{t('لا قالب معتمد بعد — أنشئ قالبًا وانتظر موافقة واتساب عليه.')}</p>
                <Button type="button" variant="ghost" onClick={onClose}>{t('إغلاق')}</Button>
            </div>
        );
    }

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]" data-testid="campaign-form">
            {dialog}
            <div className="space-y-5">
                <Field label="اسم الحملة" hint="يراه فريقك وحده." error={errors.name} required>
                    <Input value={d.name} maxLength={80} onChange={(e) => form.setData('name', e.target.value)} data-testid="campaign-name" />
                </Field>
                <Field label="القالب" error={errors.template_id}>
                    <Select value={d.template_id ?? ''} onChange={(e) => form.setData('template_id', Number(e.target.value))} options={usable.map((x) => ({ value: x.id, label: x.title }))} />
                </Field>

                <div className="space-y-3" data-testid="campaign-audience">
                    <p className="text-[13px] font-bold text-[#111]">{t('لمن تُرسل؟')}</p>
                    <div className="grid gap-2 sm:grid-cols-2" role="radiogroup">
                        {(Object.keys(SEGMENT) as Audience['segment'][]).map((s) => (
                            <button key={s} type="button" role="radio" aria-checked={a.segment === s} onClick={() => set({ segment: s })} data-testid={`segment-${s}`}
                                className={`rounded-[12px] border p-3 text-start text-[13px] font-medium ${a.segment === s ? 'border-2 border-[#111]' : 'border-[#e5e7eb]'}`}>
                                {t(SEGMENT[s])}
                            </button>
                        ))}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {(a.segment === 'recent' || a.segment === 'away') && (
                            <Field label="المدة">
                                <Select value={a.days} onChange={(e) => set({ days: Number(e.target.value) })} options={view.limits.days.map((n) => ({ value: n, label: t('آخر :n يومًا', { n }) }))} />
                            </Field>
                        )}
                        {a.segment !== 'never' && (
                            <Field label="اشترى بمبلغ لا يقل عن (اختياري)">
                                <Input value={a.min_spent} inputMode="decimal" dir="ltr" placeholder="0" onChange={(e) => set({ min_spent: e.target.value })} data-testid="audience-min" />
                            </Field>
                        )}
                        {view.branches.length > 1 && (
                            <Field label="الفرع">
                                <Select value={a.branch_id ?? ''} onChange={(e) => set({ branch_id: e.target.value === '' ? null : Number(e.target.value) })} options={[{ value: '', label: t('كل الفروع') }, ...view.branches.map((b) => ({ value: b.id, label: b.name }))]} />
                            </Field>
                        )}
                        <Field label="لغة العميل">
                            <Select value={a.language} onChange={(e) => set({ language: e.target.value as Lang | '' })} options={[{ value: '', label: t('كل اللغات') }, { value: 'ar', label: t('العربية') }, { value: 'en', label: t('الإنجليزية') }]} />
                        </Field>
                        <Field label="لا ترسل لمن وصلته حملة في آخر">
                            <Select value={a.quiet_days} onChange={(e) => set({ quiet_days: Number(e.target.value) })} options={view.limits.quiet.map((n) => ({ value: n, label: n === 0 ? t('أرسل للجميع') : t(':n يوم', { n }) }))} />
                        </Field>
                    </div>
                    {errors.audience && <p className="text-[12px] text-[#b91c1c]">{errors.audience}</p>}
                </div>

                <div className="rounded-[12px] bg-[#f9fafb] p-4" data-testid="campaign-count" aria-live="polite">
                    {failed ? (
                        <p className="text-[13px] text-[#b45309]">{t('تعذّر حساب العدد — غيّر الاختيار أو حدّث الصفحة.')}</p>
                    ) : found === null ? (
                        <p className="text-[13px] text-[#9ca3af]">{t('نحسب العدد…')}</p>
                    ) : (
                        <>
                            <p className="text-[20px] font-bold text-[#111] tabular-nums">{t(':n رسالة', { n: count })}</p>
                            <p className="mt-1 text-[12px] text-[#6b7280] tabular-nums">
                                {t('خارجها: :phone بلا رقم، و:out طلبوا الإيقاف، و:recent وصلتهم حملة قريبًا، و:dup رقم مكرر.', {
                                    phone: found.skipped.no_phone, out: found.skipped.opted_out, recent: found.skipped.recent, dup: found.skipped.duplicate,
                                })}
                            </p>
                            {tooMany && <p className="mt-2 text-[12px] text-[#b91c1c]">{t('أكثر من :max — ضيّق الاختيار.', { max: view.limits.recipients })}</p>}
                        </>
                    )}
                    <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                        {t('كل رسالة تصل تُحتسب على حسابك عند Meta بسعر رسائل العروض في بلد العميل.')}{' '}
                        <a href={FEES_URL} target="_blank" rel="noreferrer" className="text-[#1d4ed8]">{t('أسعار Meta')}</a>
                    </p>
                </div>

                <Field label="متى؟" hint="اتركه فارغًا لتبدأ الآن. لا تخرج رسالة بين العاشرة مساءً والثامنة صباحًا." error={errors.scheduled_at}>
                    <Input type="datetime-local" dir="ltr" value={d.scheduled_at} onChange={(e) => form.setData('scheduled_at', e.target.value)} data-testid="campaign-at" />
                </Field>

                <label className="flex items-start gap-2 text-[13px] text-[#111]">
                    <input type="checkbox" className="mt-1" checked={d.consent} onChange={(e) => form.setData('consent', e.target.checked)} data-testid="campaign-consent" />
                    <span>{t('أقرّ بأن هؤلاء العملاء وافقوا على استلام عروض متجري، وأن كلفة الرسائل على حسابي عند Meta.')}</span>
                </label>
                {errors.consent && <p className="text-[12px] text-[#b91c1c]">{errors.consent}</p>}

                <Field label="اكتب عدد الرسائل لتأكيدها" error={errors.confirm_count}>
                    <Input value={d.confirm_count} inputMode="numeric" dir="ltr" placeholder={found ? String(count) : ''} onChange={(e) => form.setData('confirm_count', e.target.value)} data-testid="campaign-confirm" />
                </Field>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>{t('إلغاء')}</Button>
                    <Button type="button" onClick={launch} disabled={! ready || form.processing} data-testid="campaign-launch">
                        <Send />
                        {d.scheduled_at === '' ? t('أرسل الحملة') : t('جدول الحملة')}
                    </Button>
                </div>
            </div>
            {chosen && <OfferPreview body={chosen.preview} footer={chosen.footer ?? ''} optOut={chosen.opt_out} button={chosen.button?.title ?? null} />}
        </div>
    );
}

/**
 * ‏‏حملاتُ واتساب — قالبٌ يعتمده واتساب، ثمّ لمن يُرسل، ثمّ العددُ والإقرار.
 *
 * والعددُ يُكتب بيد التاجر: كلُّ رسالةٍ تُحتسب عليه، وما خرج لا يُسترجع.
 */
export function CampaignsScreen({ view }: { view: CampaignsView }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const [open, setOpen] = useState<'campaign' | 'template' | null>(null);
    const [phone, setPhone] = useState('');
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const can = view.may_manage && view.obstacle === null;

    const act = async (c: CampaignRow, name: 'pause' | 'resume' | 'cancel') => {
        const routes = {
            pause: 'admin.marketing.whatsapp.campaigns.pause',
            resume: 'admin.marketing.whatsapp.campaigns.resume',
            cancel: 'admin.marketing.whatsapp.campaigns.cancel',
        } as const;

        if (name === 'cancel' && ! (await ask({ message: 'إلغاء الحملة «:name»؟ ما خرج منها لا يُسترجع، وما بقي لا يخرج.', values: { name: c.name }, danger: true, action: 'ألغِ الحملة' }))) {
            return;
        }

        router.post(route(routes[name], c.id), {}, { preserveScroll: true });
    };

    const removeTemplate = async (x: CampaignTemplate) => {
        if (await ask({ message: 'حذف القالب «:name»؟ يُحذف من واتساب أيضًا.', values: { name: x.title }, danger: true, action: 'حذف' })) {
            router.delete(route('admin.marketing.whatsapp.campaigns.templates.destroy', x.id), { preserveScroll: true });
        }
    };

    return (
        <>
            {dialog}
            {view.obstacle && (
                <p className="flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]" data-testid="campaigns-obstacle">
                    <AlertTriangle className="size-4 shrink-0" />{view.obstacle}
                </p>
            )}
            {(errors.campaign || errors.templates) && (
                <p className="rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]" data-testid="campaigns-error">{errors.campaign ?? errors.templates}</p>
            )}

            <SettingsSection title="طريقة العمل" icon={ListChecks}>
                <ol className="space-y-3" data-testid="campaigns-how">
                    {[
                        ['اكتب قالب العرض', 'يراجعه واتساب مرة واحدة، ثم تستعمله في حملات كثيرة.'],
                        ['اختر لمن يُرسل', 'من اشترى قريبًا، أو غاب، أو الكل — ومن طلب الإيقاف لا تصله.'],
                        ['راجع العدد وأرسل', 'تخرج من رقم متجرك دفعة بعد دفعة، وترى ما وصل وما قُرئ.'],
                    ].map(([title, line], i) => (
                        <li key={title} className="flex gap-3">
                            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-[#111] text-[12px] font-bold text-white tabular-nums">{i + 1}</span>
                            <div>
                                <p className="font-bold text-[#111]">{t(title)}</p>
                                <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{t(line)}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </SettingsSection>

            <SettingsSection
                title="الحملات"
                icon={Megaphone}
                action={view.may_manage && open !== 'campaign' ? (
                    <Button type="button" onClick={() => setOpen('campaign')} disabled={! can} data-testid="campaign-new">
                        <Plus />
                        {t('حملة جديدة')}
                    </Button>
                ) : undefined}
            >
                {open === 'campaign' && <div className="mb-6 border-b border-[#f0f0f0] pb-6"><NewCampaign view={view} onClose={() => setOpen(null)} /></div>}
                <ul className="divide-y divide-[#f0f0f0]" data-testid="campaign-list">
                    {view.campaigns.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid={`campaign-${c.id}`}>
                            <div className="min-w-0 space-y-1">
                                <p className="flex flex-wrap items-center gap-2 font-bold text-[#111]">
                                    {c.name}
                                    <Badge variant={STATUS[c.status].variant}>{t(STATUS[c.status].label)}</Badge>
                                </p>
                                <p className="truncate text-[12px] text-[#6b7280]">{c.body}</p>
                                <p className="text-[12px] text-[#4b4b4b] tabular-nums" data-testid="campaign-counts">
                                    {t('خرجت :out من :total', { out: c.counts.out + c.counts.failed, total: c.total })} · {t('وصلت :n', { n: c.counts.delivered })} · {t('قُرئت :n', { n: c.counts.read })}
                                    {c.counts.failed > 0 && <> · <span className="text-[#b91c1c]">{t('فشلت :n', { n: c.counts.failed })}</span></>}
                                    {c.counts.skipped > 0 && <> · {t('تُخطّيت :n', { n: c.counts.skipped })}</>}
                                </p>
                                {c.failures.length > 0 && (
                                    <p className="text-[12px] text-[#6b7280]" data-testid="campaign-failures">{c.failures.slice(0, 3).map((f) => `${f.why}: ${f.n}`).join(' · ')}</p>
                                )}
                                {c.why && <p className="text-[12px] text-[#b45309]" data-testid="campaign-why">{c.why}</p>}
                                <p className="text-[11px] text-[#9ca3af]">
                                    {c.status === 'scheduled' ? t('تبدأ :when', { when: when(c.scheduled_at) }) : c.finished_at ? t('انتهت :when', { when: when(c.finished_at) }) : when(c.started_at)}
                                </p>
                            </div>
                            {view.may_manage && ['scheduled', 'sending', 'paused'].includes(c.status) && (
                                <div className="flex items-center gap-2">
                                    {c.status === 'paused' ? (
                                        <Button type="button" variant="outline" onClick={() => act(c, 'resume')} disabled={view.obstacle !== null} data-testid="campaign-resume"><Play />{t('استأنف')}</Button>
                                    ) : (
                                        <Button type="button" variant="outline" onClick={() => act(c, 'pause')} data-testid="campaign-pause"><Pause />{t('أوقف')}</Button>
                                    )}
                                    <Button type="button" variant="outline" onClick={() => act(c, 'cancel')} aria-label={t('ألغِ الحملة')} data-testid="campaign-cancel"><X /></Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
                {view.campaigns.length === 0 && open !== 'campaign' && <p className="text-[13px] text-[#6b7280]" data-testid="campaign-empty">{t('لا حملة بعد.')}</p>}
            </SettingsSection>

            <SettingsSection
                title="قوالب العروض"
                description="يراجعها واتساب قبل استعمالها."
                icon={MessageSquareText}
                action={view.may_manage ? (
                    <div className="flex flex-wrap gap-2">
                        {view.templates.length > 0 && (
                            <Button type="button" variant="outline" onClick={() => router.post(route('admin.marketing.whatsapp.campaigns.templates.sync'), {}, { preserveScroll: true })} data-testid="template-sync">
                                <RefreshCw />
                                {t('حدّث حالها')}
                            </Button>
                        )}
                        {open !== 'template' && (
                            <Button type="button" onClick={() => setOpen('template')} disabled={! can || view.templates.length >= view.limits.templates} data-testid="template-new">
                                <Plus />
                                {t('قالب جديد')}
                            </Button>
                        )}
                    </div>
                ) : undefined}
            >
                {open === 'template' && <div className="mb-6 border-b border-[#f0f0f0] pb-6"><NewTemplate view={view} onClose={() => setOpen(null)} /></div>}
                <ul className="divide-y divide-[#f0f0f0]">
                    {view.templates.map((x) => (
                        <li key={x.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid={`template-${x.id}`}>
                            <div className="min-w-0 space-y-1">
                                <p className="flex flex-wrap items-center gap-2 font-bold text-[#111]">
                                    {x.title}
                                    <Badge variant={x.status === 'APPROVED' ? 'success' : x.status === 'PENDING' ? 'neutral' : 'warning'}>{t(x.label)}</Badge>
                                </p>
                                <p className="line-clamp-2 whitespace-pre-line text-[12px] text-[#6b7280]">{x.preview}</p>
                                {x.why && <p className="text-[12px] text-[#b91c1c]">{x.why}</p>}
                                {x.status === 'APPROVED' && ! x.usable && <p className="text-[12px] text-[#b45309]">{t('من رقم رُبط قبل هذا — لا يُرسل من رقمك الآن.')}</p>}
                            </div>
                            {view.may_manage && (
                                <Button type="button" variant="outline" aria-label={t('حذف')} onClick={() => removeTemplate(x)} data-testid="template-delete"><Trash2 /></Button>
                            )}
                        </li>
                    ))}
                </ul>
                {view.templates.length === 0 && open !== 'template' && <p className="text-[13px] text-[#6b7280]">{t('لا قالب بعد — ابدأ بواحد.')}</p>}
            </SettingsSection>

            <SettingsSection title="من طلب الإيقاف" description="لا تصلهم حملة. وتصلهم رسائل طلباتهم كالمعتاد." icon={BellOff}>
                <p className="text-[13px] text-[#111] tabular-nums" data-testid="optouts-total">{t(':n رقم', { n: view.optouts.total })}</p>
                {view.may_manage && (
                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post(route('admin.marketing.whatsapp.campaigns.optouts.store'), { phone }, { preserveScroll: true, onSuccess: () => setPhone('') });
                        }}
                    >
                        <Input className="max-w-[220px]" value={phone} dir="ltr" inputMode="tel" placeholder="9123 4567" onChange={(e) => setPhone(e.target.value)} data-testid="optout-phone" />
                        <Button type="submit" variant="outline" disabled={phone.trim() === ''} data-testid="optout-add">{t('أضف رقمًا')}</Button>
                    </form>
                )}
                {errors.phone && <p className="mt-2 text-[12px] text-[#b91c1c]">{errors.phone}</p>}
            </SettingsSection>

            {! view.may_manage && <p className="text-[12px] text-[#9ca3af]">{t('إنشاء الحملات وإرسالها لصاحب الحساب.')}</p>}
        </>
    );
}

export default function MarketingWhatsappCampaigns() {
    const { view } = usePage<PageProps<{ view: CampaignsView }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="حملات واتساب">
            <BackLink routeName="admin.marketing.whatsapp" href={route('admin.marketing.whatsapp')} label="إشعارات واتساب" />
            <PageHeader title="حملات واتساب" subtitle={t('عرض يصل عملاءك من رقم متجرك — بقالب يعتمده واتساب')} />
            <SettingsPage width="wide">
                <CampaignsScreen view={view} />
            </SettingsPage>
        </AdminLayout>
    );
}
