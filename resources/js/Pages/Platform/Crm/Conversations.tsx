import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Clock,
    Lock,
    MessageCircle,
    Phone,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    UserRound,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import SmartLink from '@/Components/SmartLink';
import type { SelectOption } from '@/Components/Field';
import type { ServerPagination } from '@/Components/DataTable';
import { MessageComposer } from '@/Components/conversations/Composer';
import { ConversationDetailsPanel, DetailLine, DetailSection } from '@/Components/conversations/Details';
import {
    Avatar,
    ConversationEmptyState,
    ConversationList,
    ConversationListItem,
    ConversationPage,
    ConversationPageHeader,
    ConversationSearch,
    ConversationShell,
    FilterChips,
    type Pane,
    Pill,
} from '@/Components/conversations/Shell';
import { ConversationHeader, ConversationThread, MessageBubble } from '@/Components/conversations/Thread';
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
    /** كم رسالةَ عميلٍ لم يقرأها هذا الموظّف — صفرٌ لا يُرسم */
    unread: number;
}

interface Message {
    id: number;
    direction: 'in' | 'out';
    body: string | null;
    sender: string | null;
    at: string | null;
    /* حالُ التسليم كما قالتها ميتا — لا كما أردناها */
    delivery: 'sent' | 'delivered' | 'read' | 'failed' | 'blocked' | 'partial' | null;
    deliveryLabel: string | null;
    deliveryError: string | null;
    mediaType: string | null;
    /* ما نزل من واتساب أو رُفع من هنا — رابطُه يمرّ بمتحكّمٍ يسأل عن الجلسة */
    files: { id: number; name: string; size: number; image: boolean; url: string }[];
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
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}

interface Suggestion {
    text: string;
    model: string | null;
}

const TONE: Record<string, string> = {
    info: 'bg-[#eff6ff] text-[#1d4ed8]',
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    warning: 'bg-[#fffbeb] text-[#b45309]',
    success: 'bg-[#f0fdf4] text-[#15803d]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    gray: 'bg-[#f4f4f5] text-[#52525b]',
};

export default function CrmConversations() {
    const { conversations, pagination, filters, counts, stages, active, messages, signals, assistant, line, maxFiles, maxKb, extensions } =
        usePage<PageProps<Props>>().props;
    /*
     * والاقتراحُ يُعرض ولا يُرسل.
     *
     * يعود في `flash` بعد التوليد، ويُنسخ إلى المُحرِّر بضغطة «استخدام
     * الردّ» — لا قبلها. وملؤه تلقائيًّا يجعل ضغطةَ «إرسال» التاليةَ تُخرج
     * ما لم يقرأه أحد.
     */
    const suggestion = (usePage().props as unknown as { suggestion?: Suggestion }).suggestion;
    const t = useTranslate();
    const [query, setQuery] = useState(filters.q ?? '');
    const [pane, setPane] = useState<Pane>(active ? 'thread' : 'list');
    const [tab, setTab] = useState<'info' | 'signals'>('info');

    const go = (params: Record<string, string | number | undefined>) =>
        router.get(route('super-admin.crm.conversations'), { ...filters, ...params }, {
            preserveState: true,
            preserveScroll: true,
        });

    const views = [
        { key: 'all', label: t('الكل'), n: counts.all },
        { key: 'window', label: t('النافذة مفتوحة'), n: counts.window },
        { key: 'mine', label: t('محادثاتي'), n: counts.mine },
        { key: 'unassigned', label: t('غير معيّن'), n: counts.unassigned },
    ];

    return (
        <PlatformLayout title={t('محادثات العملاء المحتملين')}>
            <ConversationPage>
            <ConversationPageHeader
                title={t('المحادثات')}
                context={t('CRM')}
                subtitle={t('محادثات العملاء المحتملين والمبيعات')}
                tone="violet"
            />

            {/*
                رقمٌ غير موصول يُقال في الرأس لا يُصمت عنه.
                شاشةٌ فارغةٌ بلا سبب تُقرأ عطبًا، فيُعاد فتحُها ويُسأل عنها.
            */}
            {!line.connected && (
                <div className="mb-3 flex shrink-0 flex-wrap items-center gap-2 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] px-3 py-2.5 text-[13px] text-[#92400e]">
                    <AlertTriangle className="size-4 shrink-0" />
                    <span>
                        {t('رقم مبيعات أبعاد غير موصول — لا يصل وارد ولا يخرج ردّ. يُربط من إعدادات واتساب في لوحة المنصّة.')}
                    </span>
                </div>
            )}

            <ConversationShell
                pane={pane}
                list={
                    <ConversationList
                        header={
                            <div className="space-y-2">
                                <ConversationSearch
                                    value={query}
                                    onChange={setQuery}
                                    onSubmit={() => go({ q: query, page: 1 })}
                                    placeholder={t('ابحث بالاسم أو رقم الجوال...')}
                                    label={t('ابحث بالاسم أو رقم الجوال...')}
                                />
                                <FilterChips
                                    items={views}
                                    value={filters.assignment ?? 'all'}
                                    onChange={(assignment) => go({ assignment, page: 1 })}
                                    label={t('العرض')}
                                />
                                {/* والمرحلةُ مرشِّحٌ قائمٌ في الخادم — كان بلا مقبضٍ في الشاشة */}
                                <select
                                    value={filters.stage ?? 'all'}
                                    onChange={(e) => go({ stage: e.target.value, page: 1 })}
                                    aria-label={t('المرحلة')}
                                    className="h-8 w-full rounded-[9px] border border-[var(--ui-border,#e8e8e8)] bg-white px-2 text-[12px] text-[#4b4b4b] outline-none focus:border-[#059669]"
                                >
                                    <option value="all">{t('كل المراحل')}</option>
                                    {stages.map((s) => (
                                        <option key={String(s.value)} value={String(s.value)}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        }
                        footer={
                            pagination.last_page > 1 ? (
                                <div className="flex items-center justify-between gap-2 text-[12px] text-[#71717a]">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={!pagination.prev_page_url}
                                        onClick={() => go({ page: pagination.current_page - 1 })}
                                    >
                                        {t('السابق')}
                                    </Button>
                                    <span className="tabular-nums">
                                        {pagination.current_page} / {pagination.last_page}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={!pagination.next_page_url}
                                        onClick={() => go({ page: pagination.current_page + 1 })}
                                    >
                                        {t('التالي')}
                                    </Button>
                                </div>
                            ) : undefined
                        }
                    >
                        {conversations.length === 0 && (
                            <li>
                                <ConversationEmptyState text={t('لا محادثات بهذا الترشيح.')} />
                            </li>
                        )}

                        {conversations.map((c) => (
                            <ConversationListItem
                                key={c.id}
                                active={active?.id === c.id}
                                onClick={() => {
                                    setPane('thread');
                                    go({ lead: c.id });
                                }}
                                avatar={<Avatar name={c.name} />}
                                title={c.name}
                                subtitle={c.businessName}
                                preview={c.preview}
                                time={c.at}
                                unread={c.unread}
                                badges={
                                    <>
                                        <Pill className={TONE[c.stageTone] ?? TONE.gray}>{c.stageLabel}</Pill>
                                        {/* والنافذةُ تُقرأ في القائمة: من ضاق وقتُه يُردّ عليه أوّلًا */}
                                        {c.windowOpen && (
                                            <Pill className="bg-[#f0fdf4] text-[#15803d]">
                                                <Clock className="size-3" />
                                                {t('النافذة مفتوحة')}
                                            </Pill>
                                        )}
                                        {c.assignee && (
                                            <span className="truncate text-[10.5px] text-[#9ca3af]">{c.assignee}</span>
                                        )}
                                    </>
                                }
                            />
                        ))}
                    </ConversationList>
                }
                thread={
                    !active ? (
                        <ConversationEmptyState text={t('اختر محادثةً لقراءتها.')} icon={<MessageCircle className="size-6" />} />
                    ) : (
                        <>
                            <ConversationHeader
                                avatar={<Avatar name={active.name} size="sm" />}
                                title={active.name}
                                subtitle={
                                    <>
                                        <span dir="ltr">{active.phone}</span>
                                        {active.businessName && (
                                            <>
                                                <span className="mx-1.5 text-[#d4d4d8]">·</span>
                                                {active.businessName}
                                            </>
                                        )}
                                    </>
                                }
                                badges={<Pill className={TONE[active.stageTone] ?? TONE.gray}>{active.stageLabel}</Pill>}
                                actions={
                                    <SmartLink routeName="super-admin.crm.leads.show" href={active.url}>
                                        <Button variant="outline" size="sm">
                                            <UserRound />
                                            <span className="hidden sm:inline">{t('ملفّ العميل')}</span>
                                        </Button>
                                    </SmartLink>
                                }
                                onBack={() => setPane('list')}
                                onDetails={() => setPane('details')}
                            />

                            {/* تاجرٌ عندنا أصلًا — يُقال قبل أن يُباع ما اشتراه */}
                            {active.business && (
                                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-[#f3f4f6] bg-[#eff6ff] px-3 py-2 text-[12px] text-[#1e40af]">
                                    <Building2 className="size-3.5 shrink-0" />
                                    <span>{t('مشترك — :name', { name: active.business.name })}</span>
                                    <SmartLink routeName="super-admin.businesses.show" href={active.business.url} className="underline">
                                        {t('افتح ملفّ المتجر')}
                                    </SmartLink>
                                </div>
                            )}

                            {messages.length === 0 ? (
                                <ConversationEmptyState text={t('لا رسائل بعد.')} />
                            ) : (
                                <ConversationThread threadKey={active.id} count={messages.length}>
                                    {messages.map((m) => (
                                        <MessageBubble
                                            key={m.id}
                                            /* والمعنى معنى المبيعات: `out` منّا و`in` منه — لا غير */
                                            side={m.direction}
                                            body={m.body}
                                            sender={m.sender}
                                            time={m.at}
                                            files={m.files}
                                            footer={
                                                /*
                                                    وحالُ التسليم تُعرض كما قالتها ميتا.
                                                    وصمتٌ بعد الإرسال يُقرأ نجاحًا — وهو ما
                                                    لا يجوز أن يُترك للتأويل.
                                                */
                                                m.deliveryLabel || m.deliveryError ? (
                                                    <p
                                                        className={cn(
                                                            'mt-0.5 flex items-start gap-1 text-[10.5px]',
                                                            m.delivery === 'failed' || m.delivery === 'blocked'
                                                                ? 'font-medium text-[#b91c1c]'
                                                                : /* وبعضُها خرج: لا أحمرَ يقول «ضاع» ولا رماديٌّ يقول «تمّ» */
                                                                  m.delivery === 'partial'
                                                                  ? 'font-medium text-[#b45309]'
                                                                  : 'text-[#8a8a8a]',
                                                        )}
                                                    >
                                                        {(m.delivery === 'failed' || m.delivery === 'blocked' || m.delivery === 'partial') && (
                                                            <AlertTriangle className="mt-px size-3 shrink-0" />
                                                        )}
                                                        <span>
                                                            {m.deliveryLabel}
                                                            {m.deliveryError && ` — ${m.deliveryError}`}
                                                        </span>
                                                    </p>
                                                ) : null
                                            }
                                        />
                                    ))}
                                </ConversationThread>
                            )}

                            <CrmComposer
                                active={active}
                                assistant={assistant}
                                suggestion={suggestion}
                                maxFiles={maxFiles}
                                maxKb={maxKb}
                                extensions={extensions}
                            />
                        </>
                    )
                }
                details={
                    !active ? (
                        <ConversationEmptyState text={t('اختر محادثةً لقراءتها.')} icon={<UserRound className="size-6" />} />
                    ) : (
                        <ConversationDetailsPanel
                            title={t('معلومات العميل المحتمل')}
                            onBack={() => setPane('thread')}
                            tabs={
                                signals
                                    ? {
                                          items: [
                                              { key: 'info', label: t('معلومات') },
                                              { key: 'signals', label: t('إشارات') },
                                          ],
                                          value: tab,
                                          onChange: (k) => setTab(k as 'info' | 'signals'),
                                      }
                                    : undefined
                            }
                        >
                            {tab === 'signals' && signals ? (
                                <SignalsPanel signals={signals} />
                            ) : (
                                <LeadPanel active={active} />
                            )}
                        </ConversationDetailsPanel>
                    )
                }
            />
            </ConversationPage>
        </PlatformLayout>
    );
}

/* ═══════════════════ المُحرِّر والمساعد ═══════════════════ */

function CrmComposer({
    active,
    assistant,
    suggestion,
    maxFiles,
    maxKb,
    extensions,
}: {
    active: Active;
    assistant: { available: boolean; reason: string | null };
    suggestion: Suggestion | undefined;
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}) {
    const t = useTranslate();

    const form = useForm<{ body: string; ai_model: string; ai_edited: boolean; files: File[] }>({
        body: '',
        ai_model: '',
        ai_edited: false,
        files: [],
    });
    const suggestForm = useForm({ steer: '' });

    /* وما اقتُرح أصلًا — ليُعرف إن عدّله الإنسان قبل الإرسال */
    const [usedSuggestion, setUsedSuggestion] = useState<string | null>(null);

    const useSuggestion = () => {
        if (!suggestion) return;
        form.setData({
            ...form.data,
            body: suggestion.text,
            ai_model: suggestion.model ?? '',
            ai_edited: false,
        });
        setUsedSuggestion(suggestion.text);
    };

    const feedback = (verdict: 'up' | 'down') => {
        if (!suggestion) return;
        router.post(
            route('super-admin.crm.conversations.feedback', active.id),
            { verdict, suggestion: suggestion.text, model: suggestion.model ?? '' },
            { preserveScroll: true },
        );
    };

    const send = () => {
        if (form.processing || !form.data.body.trim()) return;
        form.post(route('super-admin.crm.conversations.reply', active.id), {
            preserveScroll: true,
            /*
                والمرفقاتُ تُمسح مع النصّ.
                ملفٌّ يبقى في المُحرِّر بعد إرساله يخرج مرّةً ثانيةً مع
                الردّ التالي — ويصل العميلَ مرّتين.
            */
            onSuccess: () => form.reset('body', 'files'),
        });
    };

    /*
        والسببُ يُقال قبل الكتابة لا بعد المنع.
        من يكتب ردًّا طويلًا ثمّ يُردّ «النافذة مغلقة» يكون قد كتب على لا شيء.
    */
    if (active.blockedReason) {
        return (
            <div className="shrink-0 border-t border-[var(--ui-border,#e8e8e8)] p-3">
                <div className="flex items-start gap-2 rounded-[12px] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]">
                    <Lock className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p>{active.blockedReason}</p>
                        <p className="mt-1 text-[11px]">
                            {t('ولا قوالبَ معتمَدةً في هذه النسخة — فلا يخرج شيء حتى يكتب هو.')}
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <MessageComposer
            value={form.data.body}
            onChange={(body) =>
                /*
                    و«عدّله الإنسان» يُقاس ولا يُفترض: يُقارَن ما في المُحرِّر
                    بما اقتُرح. وافتراضُه دائمًا يجعل السجلَّ يقول إنّ كلَّ ردٍّ
                    رُوجع وهو لم يُمسّ.
                */
                form.setData({
                    ...form.data,
                    body,
                    ai_edited: usedSuggestion !== null && body !== usedSuggestion,
                })
            }
            onSend={send}
            files={form.data.files}
            onAddFiles={(picked) => form.setData('files', [...form.data.files, ...picked].slice(0, maxFiles))}
            onRemoveFile={(i) =>
                form.setData(
                    'files',
                    form.data.files.filter((_, j) => j !== i),
                )
            }
            accept={extensions.map((e) => `.${e}`).join(',')}
            maxFiles={maxFiles}
            maxKb={maxKb}
            processing={form.processing}
            placeholder={t('اكتب رسالتك...')}
            errors={{ body: form.errors.body, files: form.errors.files }}
            trailing={
                active.windowEndsAt ? (
                    <span className="hidden shrink-0 items-center gap-1 self-center rounded-full bg-[#f0fdf4] px-2 py-0.5 text-[11px] text-[#15803d] sm:inline-flex">
                        <Clock className="size-3" />
                        {t('النافذة مفتوحة')}
                    </span>
                ) : undefined
            }
            above={
                /* ── مساعد أبعاد الذكيّ — يقترح، والإنسانُ يُرسل ── */
                <div className="mb-2 rounded-[12px] border border-[#ede9fe] bg-[#faf8ff] p-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="flex items-center gap-1 text-[12px] font-bold text-[#5b21b6]">
                            <Sparkles className="size-3.5" />
                            {t('مساعد أبعاد الذكي')}
                        </span>
                        <span className="rounded-full bg-[#f5f3ff] px-2 py-0.5 text-[10.5px] text-[#6d28d9]">
                            {t('يقترح ولا يُرسل')}
                        </span>

                        {assistant.available ? (
                            <>
                                <Input
                                    value={suggestForm.data.steer}
                                    onChange={(e) => suggestForm.setData('steer', e.target.value)}
                                    placeholder={t('توجيه اختياري: «أقصر»، «اذكر التجربة»...')}
                                    className="h-8 min-w-[160px] flex-1 rounded-[9px] text-[12px]"
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    loading={suggestForm.processing}
                                    onClick={() =>
                                        suggestForm.post(route('super-admin.crm.conversations.suggest', active.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <Sparkles className="size-4" />
                                    {suggestion ? t('إعادة إنشاء') : t('اقتراح رد')}
                                </Button>
                            </>
                        ) : (
                            /*
                                ولا زرَّ يُعرض ولا يُفتح.
                                زرٌّ مُطفأ فوق سببٍ مكتوب خيرٌ من زرٍّ يُضغط
                                فيردّ خطأً إنجليزيًّا من مزوّد.
                            */
                            <span className="text-[12px] text-[#6b7280]">{assistant.reason}</span>
                        )}
                    </div>

                    {assistant.available && suggestion && (
                        <div className="mt-2 rounded-[10px] border border-[#e9d5ff] bg-white p-2.5">
                            <p className="whitespace-pre-wrap text-[13px] text-[#111]" dir="auto">
                                {suggestion.text}
                            </p>

                            <div className="mt-2 flex flex-wrap items-center gap-2">
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
                </div>
            }
        />
    );
}

/* ═══════════════════ لوحةُ العميل ═══════════════════ */

function LeadPanel({ active }: { active: Active }) {
    const t = useTranslate();
    const unknown = t('غير معروف');

    return (
        <>
            <DetailSection>
                <div className="flex items-center gap-3">
                    <Avatar name={active.name} size="lg" />
                    <div className="min-w-0">
                        <p className="truncate text-[14px] font-bold text-[#111]">{active.name}</p>
                        <p className="mt-0.5 flex items-center gap-1 text-[12px] text-[#71717a]">
                            <Phone className="size-3" />
                            <span dir="ltr">{active.phone}</span>
                        </p>
                        <Pill className={cn('mt-1', TONE[active.stageTone] ?? TONE.gray)}>{active.stageLabel}</Pill>
                    </div>
                </div>
            </DetailSection>

            <DetailSection title={t('النشاط')}>
                <dl>
                    <DetailLine label={t('اسم النشاط')} value={active.businessName} empty={unknown} />
                    <DetailLine label={t('الولاية')} value={active.wilayat} empty={unknown} />
                    <DetailLine
                        label={t('عدد الفروع')}
                        value={active.branchesCount !== null ? String(active.branchesCount) : null}
                        empty={unknown}
                    />
                    <DetailLine label={t('النظام الحالي')} value={active.currentSystem} empty={unknown} />
                    <DetailLine label={t('الباقة المهتم بها')} value={active.plan} empty={unknown} />
                </dl>
            </DetailSection>

            <DetailSection title={t('المتابعة')}>
                <dl>
                    <DetailLine label={t('المصدر')} value={active.sourceLabel} empty={unknown} />
                    <DetailLine label={t('المسؤول')} value={active.assignee} empty={unknown} />
                    <DetailLine label={t('أول تواصل')} value={active.firstContactAt} empty={unknown} />
                    <DetailLine label={t('آخر تواصل')} value={active.lastContactAt} empty={unknown} />
                    <DetailLine label={t('المتابعة القادمة')} value={active.nextFollowUpAt} empty={unknown} />
                </dl>
            </DetailSection>

            {active.notesSummary && (
                <DetailSection title={t('آخر ملاحظة داخلية')}>
                    <p className="text-[12.5px] text-[#111]" dir="auto">
                        {active.notesSummary}
                    </p>
                </DetailSection>
            )}

            <SmartLink routeName="super-admin.crm.leads.show" href={active.url} className="block">
                <Button variant="outline" className="w-full">
                    {t('المراحل والملاحظات والمهام')}
                </Button>
            </SmartLink>
        </>
    );
}

/* ═══════════════════ تحليلُ المحادثة: إشاراتٌ مقيسةٌ لا تقديرُ نموذج ═══════════════════ */

function SignalsPanel({ signals }: { signals: Signals }) {
    const t = useTranslate();

    return (
        <>
            {/*
                ويُقال مصدرُه صراحةً.
                «٧٥٪» بلا مصدرٍ تُقرأ قياسًا، ومن يقرؤها يبني عليها ترتيبَ من
                يُتابَع أوّلًا.
            */}
            <p className="text-[11px] text-[#9ca3af]">{t('مقروءٌ من نصّ رسائله — لا تقديرَ نموذجٍ فيه.')}</p>

            {signals.handoff && (
                <div className="rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3">
                    <p className="flex items-center gap-1.5 text-[12px] font-medium text-[#991b1b]">
                        <AlertTriangle className="size-3.5" />
                        {t('يحتاج تدخل بشري')}
                    </p>
                    <p className="mt-1 text-[11px] text-[#991b1b]" dir="auto">
                        «{signals.handoff}»
                    </p>
                </div>
            )}

            <DetailSection title={t('درجة الاهتمام')} action={<span className="text-[13px] font-bold text-[#111]">{signals.score}%</span>}>
                <div className="h-1.5 overflow-hidden rounded-full bg-[#ecece9]">
                    <div className="h-full rounded-full bg-[#8b5cf6]" style={{ width: `${signals.score}%` }} />
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
            </DetailSection>

            {signals.intent.length > 0 && (
                <DetailSection title={t('الاحتياجات المكتشفة')}>
                    <ul className="space-y-1.5">
                        {signals.intent.map((i) => (
                            <li key={i.key} className="rounded-[8px] bg-[#f5f3ff] p-2">
                                <span className="text-[12px] font-medium text-[#6d28d9]">{i.label}</span>
                                <p className="mt-0.5 text-[11px] text-[#6b7280]" dir="auto">
                                    «{i.quote}»
                                </p>
                            </li>
                        ))}
                    </ul>
                </DetailSection>
            )}

            {signals.objections.length > 0 && (
                <DetailSection title={t('الاعتراضات')}>
                    <ul className="space-y-1.5">
                        {signals.objections.map((o) => (
                            <li key={o.key} className="rounded-[8px] bg-[#fffbeb] p-2">
                                <span className="text-[12px] font-medium text-[#b45309]">{o.label}</span>
                                <p className="mt-0.5 text-[11px] text-[#6b7280]" dir="auto">
                                    «{o.quote}»
                                </p>
                            </li>
                        ))}
                    </ul>
                </DetailSection>
            )}

            <DetailSection title={t('الإجراء المقترح')}>
                <p className="text-[12.5px] text-[#111]">{signals.nextAction}</p>
            </DetailSection>

            <p className="text-[11px] text-[#9ca3af]">
                {t(':in واردة · :out صادرة', { in: signals.inbound, out: signals.outbound })}
            </p>
        </>
    );
}
