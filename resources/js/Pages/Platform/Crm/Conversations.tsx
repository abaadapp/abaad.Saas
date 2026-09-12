import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Building2,
    Clock,
    Inbox,
    Lock,
    MessageCircle,
    Search,
    Send,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import SmartLink from '@/Components/SmartLink';
import type { SelectOption } from '@/Components/Field';
import type { ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/* ═══════════════════ الأنواع ═══════════════════ */

interface Row {
    id: number;
    name: string;
    businessName: string | null;
    phone: string;
    stage: string;
    stageLabel: string;
    stageTone: string;
    assignee: string | null;
    preview: string;
    at: string | null;
    windowOpen: boolean;
}

interface Message {
    id: number;
    direction: 'in' | 'out';
    body: string | null;
    sender: string | null;
    at: string | null;
    /* حالُ التسليم كما قالتها ميتا — لا كما أردناها */
    delivery: 'sent' | 'delivered' | 'read' | 'failed' | 'blocked' | null;
    deliveryLabel: string | null;
    deliveryError: string | null;
    mediaType: string | null;
}

interface Active {
    id: number;
    name: string;
    businessName: string | null;
    phone: string;
    phoneNormalized: string;
    wilayat: string | null;
    branchesCount: number | null;
    currentSystem: string | null;
    stage: string;
    stageLabel: string;
    stageTone: string;
    sourceLabel: string;
    assignee: string | null;
    assigneeId: string | null;
    plan: string | null;
    firstContactAt: string | null;
    lastContactAt: string | null;
    nextFollowUpAt: string | null;
    notesSummary: string | null;
    url: string;
    business: { name: string; url: string } | null;
    windowOpen: boolean;
    windowEndsAt: string | null;
    /** لمَ لا يخرج ردٌّ الآن — و`null` إن كان يخرج */
    blockedReason: string | null;
}

interface Signal {
    key: string;
    label: string;
    /** الجملةُ التي دلّت — فالإشارةُ بلا مصدرٍ ادّعاءٌ لا يُراجَع */
    quote: string;
}

interface Signals {
    intent: Signal[];
    objections: Signal[];
    /** الجملةُ التي طلب فيها إنسانًا — و`null` إن لم يطلب */
    handoff: string | null;
    inbound: number;
    outbound: number;
    lastInboundAt: string | null;
    score: number;
    scoreReasons: { label: string; points: number }[];
    nextAction: string;
}

interface Props {
    conversations: Row[];
    pagination: ServerPagination;
    filters: Record<string, string>;
    counts: Record<string, number>;
    stages: SelectOption[];
    staff: SelectOption[];
    active: Active | null;
    messages: Message[];
    signals: Signals | null;
    assistant: { available: boolean; reason: string | null };
    line: { connected: boolean; number: string | null };
}

const TONE: Record<string, string> = {
    info: 'bg-[#eff6ff] text-[#1d4ed8]',
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    warning: 'bg-[#fffbeb] text-[#b45309]',
    success: 'bg-[#f0fdf4] text-[#15803d]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    gray: 'bg-[#f4f4f5] text-[#52525b]',
};

/** سطرٌ في لوحة البيانات — والمجهولُ يُقال ولا يُخترع */
function Row({ label, value }: { label: string; value: string | null | undefined }) {
    const t = useTranslate();

    return (
        <div className="flex items-start justify-between gap-3 py-1.5">
            <dt className="shrink-0 text-[12px] text-[#6b7280]">{t(label)}</dt>
            <dd className={cn('min-w-0 truncate text-end text-[13px]', value ? 'text-[#111]' : 'text-[#9ca3af]')}>
                {value || t('غير معروف')}
            </dd>
        </div>
    );
}

export default function CrmConversations() {
    const { conversations, filters, counts, active, messages, signals, assistant, line } =
        usePage<PageProps<Props>>().props;
    const { props: pageProps } = usePage<PageProps<Props> & { suggestion?: { text: string; model: string | null } }>();
    const t = useTranslate();
    const [query, setQuery] = useState(filters.q ?? '');

    const form = useForm({ body: '', ai_model: '', ai_edited: false as boolean });
    const suggestForm = useForm({ steer: '' });

    /*
     * والاقتراحُ يُعرض ولا يُرسل.
     *
     * يعود في `flash` بعد التوليد، ويُنسخ إلى المُحرِّر بضغطة «استخدام
     * الردّ» — لا قبلها. وملؤه تلقائيًّا يجعل ضغطةَ «إرسال» التاليةَ تُخرج
     * ما لم يقرأه أحد.
     */
    const suggestion = (pageProps as unknown as { suggestion?: { text: string; model: string | null } }).suggestion;

    /* وما اقتُرح أصلًا — ليُعرف إن عدّله الإنسان قبل الإرسال */
    const [usedSuggestion, setUsedSuggestion] = useState<string | null>(null);

    const useSuggestion = () => {
        if (! suggestion) return;
        form.setData({
            body: suggestion.text,
            ai_model: suggestion.model ?? '',
            ai_edited: false,
        });
        setUsedSuggestion(suggestion.text);
    };

    const feedback = (verdict: 'up' | 'down') => {
        if (! active || ! suggestion) return;
        router.post(
            route('super-admin.crm.conversations.feedback', active.id),
            { verdict, suggestion: suggestion.text, model: suggestion.model ?? '' },
            { preserveScroll: true },
        );
    };

    const go = (params: Record<string, string | number | undefined>) =>
        router.get(route('super-admin.crm.conversations'), { ...filters, ...params }, {
            preserveState: true,
            preserveScroll: true,
        });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (! active) return;
        form.post(route('super-admin.crm.conversations.reply', active.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const views = [
        { key: 'all', label: 'الكل', n: counts.all },
        { key: 'window', label: 'النافذة مفتوحة', n: counts.window },
        { key: 'mine', label: 'محادثاتي', n: counts.mine },
        { key: 'unassigned', label: 'غير معيّن', n: counts.unassigned },
    ];

    return (
        <PlatformLayout title={t('محادثات العملاء المحتملين')}>
            {/*
                رقمٌ غير موصول يُقال في الرأس لا يُصمت عنه.
                شاشةٌ فارغةٌ بلا سبب تُقرأ عطبًا، فيُعاد فتحُها ويُسأل عنها.
            */}
            {! line.connected && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]">
                    <AlertTriangle className="size-4 shrink-0" />
                    <span>
                        {t('رقم مبيعات أبعاد غير موصول — لا يصل وارد ولا يخرج ردّ. يُربط من إعدادات واتساب في لوحة المنصّة.')}
                    </span>
                </div>
            )}

            <div className="grid gap-3 lg:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)_290px]">
                {/* ═══════════ القائمة ═══════════ */}
                <aside
                    className={cn(
                        'rounded-xl border border-[#e8e8e8] bg-white',
                        /* وعلى الجوّال: قائمةٌ أو محادثة، لا الاثنتان في عمودٍ واحد */
                        active ? 'hidden lg:block' : 'block',
                    )}
                >
                    <header className="border-b border-[#f3f4f6] p-3">
                        <h1 className="mb-2 text-[14px] font-bold text-[#111]">{t('محادثات العملاء المحتملين')}</h1>

                        <div className="relative">
                            <Search className="pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4 text-[#9ca3af]" />
                            <Input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && go({ q: query, page: 1 })}
                                placeholder={t('ابحث بالاسم أو رقم الجوال...')}
                                className="ps-8"
                            />
                        </div>

                        <div className="mt-2 flex flex-wrap gap-1">
                            {views.map((v) => (
                                <button
                                    key={v.key}
                                    type="button"
                                    onClick={() => go({ assignment: v.key, page: 1 })}
                                    className={cn(
                                        'rounded-full px-2.5 py-1 text-[12px] transition',
                                        (filters.assignment ?? 'all') === v.key
                                            ? 'bg-[#111] text-white'
                                            : 'bg-[#f4f4f5] text-[#52525b] hover:bg-[#e8e8e8]',
                                    )}
                                >
                                    {t(v.label)} {v.n}
                                </button>
                            ))}
                        </div>
                    </header>

                    {conversations.length === 0 ? (
                        <div className="p-8 text-center">
                            <Inbox className="mx-auto mb-2 size-7 text-[#d1d5db]" />
                            <p className="text-[13px] text-[#6b7280]">{t('لا محادثات بهذا الترشيح.')}</p>
                        </div>
                    ) : (
                        <ul className="max-h-[70vh] divide-y divide-[#f3f4f6] overflow-y-auto">
                            {conversations.map((c) => (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => go({ lead: c.id })}
                                        className={cn(
                                            'w-full p-3 text-start transition hover:bg-[#fafafa]',
                                            active?.id === c.id && 'bg-[#f5f3ff]',
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate text-[13px] font-medium text-[#111]">{c.name}</span>
                                            <span className="shrink-0 text-[11px] text-[#9ca3af]">{c.at}</span>
                                        </div>
                                        <p className="mt-0.5 truncate text-[12px] text-[#6b7280]">{c.preview}</p>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            <span className={cn('rounded-full px-2 py-0.5 text-[11px]', TONE[c.stageTone] ?? TONE.gray)}>
                                                {c.stageLabel}
                                            </span>
                                            {/* والنافذةُ تُقرأ في القائمة: من ضاق وقتُه يُردّ عليه أوّلًا */}
                                            {c.windowOpen && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-[#f0fdf4] px-2 py-0.5 text-[11px] text-[#15803d]">
                                                    <Clock className="size-3" />
                                                    {t('النافذة مفتوحة')}
                                                </span>
                                            )}
                                            {c.assignee && (
                                                <span className="truncate text-[11px] text-[#9ca3af]">{c.assignee}</span>
                                            )}
                                        </div>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </aside>

                {/* ═══════════ المحادثة ═══════════ */}
                <section className="flex min-h-[60vh] flex-col rounded-xl border border-[#e8e8e8] bg-white">
                    {! active ? (
                        <div className="flex flex-1 flex-col items-center justify-center p-10 text-center">
                            <MessageCircle className="mb-2 size-8 text-[#d1d5db]" />
                            <p className="text-[13px] text-[#6b7280]">{t('اختر محادثةً لقراءتها.')}</p>
                        </div>
                    ) : (
                        <>
                            <header className="flex flex-wrap items-center gap-2 border-b border-[#f3f4f6] p-3">
                                <button
                                    type="button"
                                    onClick={() => go({ lead: undefined })}
                                    className="lg:hidden"
                                    aria-label={t('رجوع')}
                                >
                                    <ArrowLeft className="size-4 text-[#6b7280] rtl:rotate-180" />
                                </button>

                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-[14px] font-bold text-[#111]">{active.name}</div>
                                    <div className="truncate text-[12px] text-[#6b7280]" dir="ltr">
                                        {active.phone}
                                    </div>
                                </div>

                                <span className={cn('rounded-full px-2 py-0.5 text-[12px]', TONE[active.stageTone] ?? TONE.gray)}>
                                    {active.stageLabel}
                                </span>

                                <SmartLink routeName="super-admin.crm.leads.show" href={active.url}>
                                    <Button variant="outline" size="sm">{t('ملفّ العميل')}</Button>
                                </SmartLink>
                            </header>

                            {/* تاجرٌ عندنا أصلًا — يُقال قبل أن يُباع ما اشتراه */}
                            {active.business && (
                                <div className="flex flex-wrap items-center gap-2 border-b border-[#f3f4f6] bg-[#eff6ff] p-2.5 text-[12px] text-[#1e40af]">
                                    <Building2 className="size-3.5 shrink-0" />
                                    <span>{t('مشترك — :name', { name: active.business.name })}</span>
                                    <SmartLink routeName="super-admin.businesses.show" href={active.business.url} className="underline">
                                        {t('افتح ملفّ المتجر')}
                                    </SmartLink>
                                </div>
                            )}

                            <div className="flex-1 space-y-3 overflow-y-auto p-4" style={{ maxHeight: '55vh' }}>
                                {messages.length === 0 ? (
                                    <p className="py-8 text-center text-[13px] text-[#9ca3af]">{t('لا رسائل بعد.')}</p>
                                ) : (
                                    messages.map((m) => (
                                        <div
                                            key={m.id}
                                            className={cn('flex', m.direction === 'out' ? 'justify-end' : 'justify-start')}
                                        >
                                            <div
                                                className={cn(
                                                    'max-w-[85%] rounded-[14px] px-3 py-2',
                                                    m.direction === 'out'
                                                        ? 'bg-[#f5f3ff] text-[#111]'
                                                        : 'bg-[#f4f4f5] text-[#111]',
                                                )}
                                            >
                                                <p className="whitespace-pre-wrap text-[13px]">{m.body}</p>

                                                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-[#9ca3af]">
                                                    <span>{m.at}</span>
                                                    {m.sender && <span>· {m.sender}</span>}
                                                    {/*
                                                        وحالُ التسليم تُعرض كما قالتها ميتا.
                                                        وصمتٌ بعد الإرسال يُقرأ نجاحًا — وهو ما
                                                        لا يجوز أن يُترك للتأويل.
                                                    */}
                                                    {m.deliveryLabel && (
                                                        <span
                                                            className={cn(
                                                                m.delivery === 'failed' || m.delivery === 'blocked'
                                                                    ? 'font-medium text-[#b91c1c]'
                                                                    : 'text-[#9ca3af]',
                                                            )}
                                                        >
                                                            · {m.deliveryLabel}
                                                        </span>
                                                    )}
                                                </div>

                                                {m.deliveryError && (
                                                    <p className="mt-1 text-[11px] text-[#b91c1c]">{m.deliveryError}</p>
                                                )}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>

                            {/* ═══ المُحرِّر ═══ */}
                            <footer className="border-t border-[#f3f4f6] p-3">
                                {/*
                                    والسببُ يُقال قبل الكتابة لا بعد المنع.
                                    من يكتب ردًّا طويلًا ثمّ يُردّ «النافذة مغلقة»
                                    يكون قد كتب على لا شيء.
                                */}
                                {active.blockedReason ? (
                                    <div className="flex items-start gap-2 rounded-[12px] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]">
                                        <Lock className="mt-0.5 size-4 shrink-0" />
                                        <div>
                                            <p>{active.blockedReason}</p>
                                            <p className="mt-1 text-[11px]">
                                                {t('ولا قوالبَ معتمَدةً في هذه النسخة — فلا يخرج شيء حتى يكتب هو.')}
                                            </p>
                                        </div>
                                    </div>
                                ) : (
                                    <form onSubmit={submit}>
                                        <div className="rounded-[12px] border border-[#e8e8e8] p-2">
                                            <textarea
                                                rows={2}
                                                value={form.data.body}
                                                onChange={(e) => {
                                                    /*
                                                        و«عدّله الإنسان» يُقاس ولا يُفترض:
                                                        يُقارَن ما في المُحرِّر بما اقتُرح.
                                                        وافتراضُه دائمًا يجعل السجلَّ يقول
                                                        إنّ كلَّ ردٍّ رُوجع وهو لم يُمسّ.
                                                    */
                                                    form.setData({
                                                        ...form.data,
                                                        body: e.target.value,
                                                        ai_edited:
                                                            usedSuggestion !== null &&
                                                            e.target.value !== usedSuggestion,
                                                    });
                                                }}
                                                placeholder={t('اكتب رسالتك...')}
                                                className="w-full resize-none border-0 bg-transparent p-1.5 text-[13px] outline-none placeholder:text-[#9ca3af]"
                                            />

                                            <div className="flex items-center justify-between gap-2">
                                                {active.windowEndsAt && (
                                                    <span className="text-[11px] text-[#9ca3af]">
                                                        {t('النافذة مفتوحة')}
                                                    </span>
                                                )}
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    className="ms-auto"
                                                    disabled={form.processing || ! form.data.body.trim()}
                                                >
                                                    <Send className="size-4" />
                                                    {t('إرسال')}
                                                </Button>
                                            </div>
                                        </div>
                                        {form.errors.body && (
                                            <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors.body}</p>
                                        )}
                                    </form>
                                )}
                            </footer>
                        </>
                    )}
                </section>

                {/* ═══════════ المساعدُ والتحليل ═══════════ */}
                {active && (
                    <div className="lg:col-start-2 xl:col-start-auto xl:row-start-2 xl:col-span-2 space-y-3">
                        {/* ── مساعد أبعاد الذكيّ ── */}
                        <section className="rounded-xl border border-[#e8e8e8] bg-white p-4">
                            <header className="mb-2 flex flex-wrap items-center gap-2">
                                <Sparkles className="size-4 text-[#8b5cf6]" />
                                <h2 className="text-[14px] font-bold text-[#111]">{t('مساعد أبعاد الذكي')}</h2>
                                {/* ويُقال ما هو: يقترح، والإنسانُ يُرسل */}
                                <span className="rounded-full bg-[#f5f3ff] px-2 py-0.5 text-[11px] text-[#6d28d9]">
                                    {t('يقترح ولا يُرسل')}
                                </span>
                            </header>

                            {! assistant.available ? (
                                /*
                                    ولا زرَّ يُعرض ولا يُفتح.
                                    زرٌّ مُطفأ فوق سببٍ مكتوب خيرٌ من زرٍّ يُضغط
                                    فيردّ خطأً إنجليزيًّا من مزوّد.
                                */
                                <p className="text-[13px] text-[#6b7280]">{assistant.reason}</p>
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Input
                                            value={suggestForm.data.steer}
                                            onChange={(e) => suggestForm.setData('steer', e.target.value)}
                                            placeholder={t('توجيه اختياري: «أقصر»، «اذكر التجربة»...')}
                                            className="min-w-[180px] flex-1"
                                        />
                                        <Button
                                            size="sm"
                                            loading={suggestForm.processing}
                                            onClick={() =>
                                                suggestForm.post(
                                                    route('super-admin.crm.conversations.suggest', active.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Sparkles className="size-4" />
                                            {suggestion ? t('إعادة إنشاء') : t('اقتراح رد')}
                                        </Button>
                                    </div>

                                    {suggestion && (
                                        <div className="mt-3 rounded-[12px] border border-[#e9d5ff] bg-[#faf5ff] p-3">
                                            <p className="whitespace-pre-wrap text-[13px] text-[#111]">
                                                {suggestion.text}
                                            </p>

                                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                                <Button size="sm" onClick={useSuggestion}>
                                                    {t('استخدام الرد')}
                                                </Button>
                                                <span className="text-[11px] text-[#6b7280]">
                                                    {t('يُنسخ إلى المُحرِّر — تُعدّله ثم ترسله بنفسك.')}
                                                </span>

                                                <div className="ms-auto flex items-center gap-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => feedback('up')}
                                                        aria-label={t('مناسب')}
                                                        className="rounded-full p-1.5 text-[#6b7280] transition hover:bg-[#f0fdf4] hover:text-[#15803d]"
                                                    >
                                                        <ThumbsUp className="size-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => feedback('down')}
                                                        aria-label={t('غير مناسب')}
                                                        className="rounded-full p-1.5 text-[#6b7280] transition hover:bg-[#fef2f2] hover:text-[#b91c1c]"
                                                    >
                                                        <ThumbsDown className="size-4" />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}
                        </section>

                        {/* ── تحليلُ المحادثة: إشاراتٌ مقيسةٌ لا تقديرُ نموذج ── */}
                        {signals && (
                            <section className="rounded-xl border border-[#e8e8e8] bg-white p-4">
                                <h2 className="mb-1 text-[14px] font-bold text-[#111]">{t('تحليل المحادثة')}</h2>
                                {/*
                                    ويُقال مصدرُه صراحةً.
                                    «٧٥٪» بلا مصدرٍ تُقرأ قياسًا، ومن يقرؤها
                                    يبني عليها ترتيبَ من يُتابَع أوّلًا.
                                */}
                                <p className="mb-3 text-[11px] text-[#9ca3af]">
                                    {t('مقروءٌ من نصّ رسائله — لا تقديرَ نموذجٍ فيه.')}
                                </p>

                                {signals.handoff && (
                                    <div className="mb-3 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3">
                                        <p className="flex items-center gap-1.5 text-[12px] font-medium text-[#991b1b]">
                                            <AlertTriangle className="size-3.5" />
                                            {t('يحتاج تدخل بشري')}
                                        </p>
                                        <p className="mt-1 text-[11px] text-[#991b1b]">«{signals.handoff}»</p>
                                    </div>
                                )}

                                <div className="mb-3">
                                    <div className="mb-1 flex items-center justify-between text-[12px]">
                                        <span className="text-[#6b7280]">{t('درجة الاهتمام')}</span>
                                        <span className="font-bold text-[#111]">{signals.score}%</span>
                                    </div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-[#f3f4f6]">
                                        <div
                                            className="h-full rounded-full bg-[#8b5cf6]"
                                            style={{ width: `${signals.score}%` }}
                                        />
                                    </div>

                                    {/* ولكلّ نقطةٍ سببُها — ومن لا يوافق يرى على ماذا لا يوافق */}
                                    {signals.scoreReasons.length > 0 && (
                                        <ul className="mt-2 space-y-0.5">
                                            {signals.scoreReasons.map((r) => (
                                                <li key={r.label} className="flex justify-between text-[11px]">
                                                    <span className="text-[#6b7280]">{r.label}</span>
                                                    <span className={r.points < 0 ? 'text-[#b91c1c]' : 'text-[#15803d]'}>
                                                        {r.points > 0 ? `+${r.points}` : r.points}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                {signals.intent.length > 0 && (
                                    <div className="mb-3">
                                        <p className="mb-1.5 text-[12px] text-[#6b7280]">{t('الاحتياجات المكتشفة')}</p>
                                        <ul className="space-y-1.5">
                                            {signals.intent.map((i) => (
                                                <li key={i.key} className="rounded-[8px] bg-[#f5f3ff] p-2">
                                                    <span className="text-[12px] font-medium text-[#6d28d9]">{i.label}</span>
                                                    <p className="mt-0.5 text-[11px] text-[#6b7280]">«{i.quote}»</p>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {signals.objections.length > 0 && (
                                    <div className="mb-3">
                                        <p className="mb-1.5 text-[12px] text-[#6b7280]">{t('الاعتراضات')}</p>
                                        <ul className="space-y-1.5">
                                            {signals.objections.map((o) => (
                                                <li key={o.key} className="rounded-[8px] bg-[#fffbeb] p-2">
                                                    <span className="text-[12px] font-medium text-[#b45309]">{o.label}</span>
                                                    <p className="mt-0.5 text-[11px] text-[#6b7280]">«{o.quote}»</p>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                <div className="rounded-[8px] bg-[#fafafa] p-2.5">
                                    <p className="mb-0.5 text-[11px] text-[#9ca3af]">{t('الإجراء المقترح')}</p>
                                    <p className="text-[12px] text-[#111]">{signals.nextAction}</p>
                                </div>
                            </section>
                        )}
                    </div>
                )}

                {/* ═══════════ لوحةُ العميل ═══════════ */}
                {active && (
                    <aside className="rounded-xl border border-[#e8e8e8] bg-white p-4 xl:block">
                        <h2 className="mb-2 text-[14px] font-bold text-[#111]">{t('معلومات العميل المحتمل')}</h2>

                        <dl className="divide-y divide-[#f3f4f6]">
                            <Row label="رقم الجوال" value={active.phone} />
                            <Row label="اسم النشاط" value={active.businessName} />
                            <Row label="الولاية" value={active.wilayat} />
                            <Row
                                label="عدد الفروع"
                                value={active.branchesCount !== null ? String(active.branchesCount) : null}
                            />
                            <Row label="النظام الحالي" value={active.currentSystem} />
                            <Row label="المصدر" value={active.sourceLabel} />
                            <Row label="المسؤول" value={active.assignee} />
                            <Row label="الباقة المهتم بها" value={active.plan} />
                            <Row label="أول تواصل" value={active.firstContactAt} />
                            <Row label="آخر تواصل" value={active.lastContactAt} />
                            <Row label="المتابعة القادمة" value={active.nextFollowUpAt} />
                        </dl>

                        {active.notesSummary && (
                            <div className="mt-3 rounded-lg bg-[#fafafa] p-3">
                                <p className="mb-1 text-[11px] text-[#9ca3af]">{t('آخر ملاحظة داخلية')}</p>
                                <p className="text-[12px] text-[#111]">{active.notesSummary}</p>
                            </div>
                        )}

                        <SmartLink routeName="super-admin.crm.leads.show" href={active.url} className="mt-3 block">
                            <Button variant="outline" className="w-full">
                                {t('المراحل والملاحظات والمهام')}
                            </Button>
                        </SmartLink>
                    </aside>
                )}
            </div>
        </PlatformLayout>
    );
}
