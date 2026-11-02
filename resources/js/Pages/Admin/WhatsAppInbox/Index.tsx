import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { MessageCircle, PanelLeftClose, PanelLeftOpen, Send } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button } from '@/Components/ui/button';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { ConnectSteps, type Readiness } from '@/Components/Connect';
import { useConfirm } from '@/Components/ConfirmDialog';
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
    Pill,
    type Pane,
} from '@/Components/conversations/Shell';
import { ConversationHeader, ConversationThread, type DeliveryMark, MessageBubble, SystemRow } from '@/Components/conversations/Thread';
import { MessageComposer } from '@/Components/conversations/Composer';
import { ConversationDetailsPanel, DetailIdentity, DetailLine, DetailSection } from '@/Components/conversations/Details';
import GuidedOrderBar, { type GuidedOrder } from '@/Pages/Admin/WhatsAppInbox/partials/GuidedOrderBar';
import type { PageProps } from '@/types';

/** محادثةٌ في القائمة — انظر `WhatsAppInbox::page` */
export interface Chat {
    /** محادثةٌ قائمة — أو `null` لزبونٍ وصلته إشعاراتٌ ولم يكتب بعد */
    id: number | null;
    key: string;
    /** وتلك تُفتح من رسالةٍ خرجت إليه (`admin.whatsappInbox.open`) */
    url: string | null;
    name: string | null;
    phone: string;
    at: string | null;
    preview: string;
    direction: 'in' | 'out' | null;
    /** واردٌ لم يقرأه هذا الموظّف وحده */
    unread: number;
    source: 'business_own' | 'abaad_shared';
}

/** رسالةٌ في الخيط: واردة، أو ردُّ موظّف، أو إشعارُ طلبٍ خرج آليًّا */
export interface Item {
    kind: 'in' | 'out' | 'notice';
    key: string;
    at: string | null;
    time: string | null;
    body: string | null;
    /** ردُّ الموظّف: اسمُه كما كُتب ساعتَها */
    sender?: string | null;
    status: string | null;
    status_label: string | null;
    error?: string | null;
    reason?: string | null;
    /** رسالةُ الزبون: خرج إليه ردٌّ آليّ — نصُّه لا يُحفظ فلا يُعرض */
    auto_replied?: boolean;
    attachments?: { id: number; name: string; size?: number; image: boolean; url: string }[];
    /** الإشعار: الحدثُ ووصفُه حين لا نصَّ محفوظًا */
    event?: string;
    summary?: string | null;
    subject?: { label: string; url: string } | null;
}

/** طلبٌ أو فاتورةٌ في تفاصيل العميل — لمن يملك قسمَهما وحده */
interface Paper {
    number: string;
    total: number;
    status: string;
    url: string;
}

export interface Thread {
    id: number;
    name: string | null;
    phone: string;
    profile_name: string | null;
    customer: { name: string; url: string | null } | null;
    orders: Paper[];
    invoices?: Paper[];
    source: 'business_own' | 'abaad_shared';
    line_number: string | null;
    window_ends_at: string | null;
    /** لمَ لا يخرج ردٌّ الآن — من `WhatsAppInbox::check` نفسِها التي يسألها الخادم */
    blocked: string | null;
    /** وخارج النافذة: اسمُ قالب المتابعة المعتمَد — وإلّا لا شيء */
    template: string | null;
    /** كلُّ ما خرج إليه في سجلّ الرسائل — لمن يملك قسمه */
    log_url: string | null;
    /** طلبُه الآليّ إن كان — حالُه وفاتورتُه ورابطُه (`WhatsAppOrders::forChat`) */
    order?: GuidedOrder | null;
    messages: Item[];
    has_more: boolean;
    before: number | null;
}

interface Props {
    chats: Chat[];
    pagination: { current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
    params: { q: string; c: string | null; f?: 'all' | 'unread' };
    thread: Thread | null;
    canReply: boolean;
    line: { mode: string; number: string | null; usable: boolean; status: string | null; expires_at: string | null };
    /** خطواتُ الربط وحالُ كلٍّ — `WhatsAppFeature::readiness` */
    readiness: Readiness;
    /** يملك الربطَ فيُدَلّ عليه */
    mayManage: boolean;
    unread: number;
    /** كم محادثةً فيها ما لم يقرأه هذا الموظّف — عدُّ «غير المقروءة» */
    unreadChats?: number;
    stamp: string;
    files: { max: number; max_kb: number; accept: string };
}

/** كلَّ كم يُسأل الخادم: أوصل جديد؟ — وأما زالت الصلاحية؟ */
export const POLL_MS = 5_000;

/** ما يُعاد من الخادم حين يتبدّل الختم — القائمةُ والخيطُ وما حولهما */
const FRESH = ['chats', 'thread', 'unread', 'unreadChats', 'stamp', 'canReply', 'line', 'readiness'];

/** إخفاءُ التفاصيل على الشاشة العريضة — راحةٌ لهذا المتصفّح وحده */
const DETAILS_KEY = 'wa-inbox-details-hidden';

/** مفتاحُ ردٍّ جديد — الضغطةُ الثانية تحمل المفتاحَ نفسَه فلا يخرج الردُّ مرّتين */
function replyKey(): string {
    try {
        return crypto.randomUUID();
    } catch {
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
    }
}

/** تاريخُ اليوم كما يكتبه الخادم (`Y-m-d`) — بساعة هذا الجهاز */
function ymd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** حالُ الصادر علامةً كما في واتساب — وما لم يخرج بعد بلا علامة */
function mark(status: string | null): DeliveryMark {
    return status === 'sent' || status === 'delivered' || status === 'read' || status === 'failed' ? status : null;
}

/**
 * «محادثات واتساب» — صندوقٌ واحدٌ للمتجر مع زبائنه، يراه صاحبُه وكلُّ من مُنحه.
 *
 * ═══ شكلُ واتساب ويب ═══
 *
 * ثلاثةُ أعمدة من مكوّنات المحادثات المشتركة (`Components/conversations`):
 * القائمةُ بالبحث و«الكل/غير المقروءة»، والخيطُ بفقاعاته وعلاماته ومُحرِّره
 * الثابت، وتفاصيلُ العميل بطلباته وفواتيره — تُخفى على الشاشة العريضة إن
 * شاء القارئ. وعلى الهاتف عمودٌ واحد ورجوعٌ واضحٌ بينها.
 *
 * ═══ ومشتركٌ لا موزَّع ═══
 *
 * كلُّ من مُنح المحادثات يرى المحادثاتِ نفسَها، وكلُّ ردٍّ باسم كاتبه، وكلُّه
 * من رقم المتجر. وما لم يُقرأ لكلّ قارئٍ وحده. والصفحةُ تسأل كلَّ بضع ثوانٍ:
 * وصل جديدٌ — ردُّ زميلٍ أو رسالةُ زبونٍ أو إشعارٌ آليّ — فتُحدَّث، أو سُحبت
 * الصلاحيةُ فيُغلق ما أمامه.
 */
export default function WhatsAppInbox() {
    const { chats, pagination, params, thread, canReply, line, stamp, files, readiness, mayManage, unreadChats = 0 } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [revoked, setRevoked] = useState(false);
    const [replyAllowed, setReplyAllowed] = useState(canReply);
    const [pane, setPane] = useState<Pane>(thread ? 'thread' : 'list');
    const [detailsHidden, setDetailsHidden] = useState(() => {
        try {
            return window.localStorage.getItem(DETAILS_KEY) === '1';
        } catch {
            return false;
        }
    });
    const last = useRef(stamp);
    const filter = params.f ?? 'all';

    useEffect(() => setReplyAllowed(canReply), [canReply]);
    useEffect(() => {
        last.current = stamp;
    }, [stamp]);

    /* محادثةٌ أخرى فُتحت — فالخيطُ هو ما يُرى على الهاتف */
    useEffect(() => {
        if (thread) setPane((p) => (p === 'list' ? 'thread' : p));
    }, [thread?.id]);

    useEffect(() => {
        if (revoked) return;

        const id = window.setInterval(async () => {
            // تبويبٌ مخفيّ لا يسأل — ويلحق بما فاته في أوّل سؤالٍ بعد ظهوره
            if (document.visibilityState === 'hidden') return;

            try {
                const res = await fetch(route('admin.whatsappInbox.poll'), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (res.status === 403 || res.status === 404 || res.status === 401) {
                    setRevoked(true);
                    return;
                }

                if (!res.ok) return;

                const data = (await res.json()) as { stamp: string; can_reply: boolean };
                setReplyAllowed(data.can_reply);

                if (data.stamp !== last.current) {
                    last.current = data.stamp;
                    router.reload({ only: FRESH });
                }
            } catch {
                /* انقطاعُ شبكةٍ لحظيّ — يُسأل في الدورة التالية */
            }
        }, POLL_MS);

        return () => window.clearInterval(id);
    }, [revoked]);

    const toggleDetails = () => {
        const next = !detailsHidden;
        setDetailsHidden(next);
        try {
            window.localStorage.setItem(DETAILS_KEY, next ? '1' : '0');
        } catch {
            /* تخزينٌ مقفل — يبقى الاختيارُ لهذه الزيارة وحدها */
        }
    };

    /* المحادثةُ المفتوحة والبحثُ والمرشِّحُ في العنوان — فالتحديثُ والرجوعُ يعيدانها */
    const go = (next: Record<string, string | number | null>) => {
        const all: Record<string, string | number | null> = { q: params.q || null, f: filter === 'all' ? null : filter, c: params.c, ...next };
        const query = Object.fromEntries(Object.entries(all).filter(([, v]) => v !== null && v !== ''));

        router.get(route('admin.whatsappInbox.index'), query as never, { preserveState: true, preserveScroll: true, replace: true });
    };

    const header = (
        <ConversationPageHeader
            title={t('محادثات واتساب')}
            context={line.mode === 'business_own' ? (line.number ?? t('رقم متجرك')) : t('رقم أبعاد المشترك')}
            subtitle={line.mode === 'business_own'
                ? t('صندوق واحد لك ولموظفيك — كل رد يخرج من رقم متجرك باسم كاتبه')
                : t('ردود عملائك على رقم أبعاد المشترك — ما ثبت أنه لمتجرك وحده')}
        />
    );

    if (revoked) {
        return (
            <AdminLayout title="صندوق محادثات واتساب">
                {header}
                <div className="rounded-[6px] border border-[var(--cv-border,#e9edef)] p-10 text-center text-[14px] text-[#6b7280]" data-testid="inbox-revoked">
                    {t('لم تعد لك صلاحية على محادثات واتساب — أُغلقت المحادثة. راجع صاحب المتجر.')}
                </div>
            </AdminLayout>
        );
    }

    const empty = chats.length === 0 && params.q === '' && filter === 'all';

    return (
        <AdminLayout title="صندوق محادثات واتساب">
            <ConversationPage>
                {header}

                {!line.usable && (
                    <div className="mb-2 shrink-0 rounded-[8px] border border-[#fde68a] bg-[#fffbeb] px-4 py-2.5 text-[13px] text-[#92400e]" data-testid="inbox-line-down">
                        {line.mode === 'business_own'
                            ? t('رقم متجرك لا يرسل الآن — تُقرأ المحادثات ولا يخرج رد حتى يُعاد الربط. ولا يُرسل من رقم آخر.')
                            : t('رقم أبعاد المشترك لا يرسل الآن — تُقرأ المحادثات ولا يخرج رد.')}
                    </div>
                )}

                {/* وما ينقص الربطَ بخطواته وأين يُصلَح — والمحادثاتُ تحته تُقرأ كما هي */}
                {!readiness.ready && (
                    <details className="mb-2 shrink-0" data-testid="inbox-readiness">
                        <summary className="cursor-pointer text-[13px] font-medium text-[#92400e]">
                            {t('ربط واتساب غير مكتمل — اعرض ما ينقص وكيف يُصلَح')}
                        </summary>
                        <ConnectSteps
                            className="mt-3"
                            readiness={readiness}
                            title={t('خطوات ربط واتساب')}
                            done={t('رسائلك تخرج')}
                            waiting={t('المحادثات محفوظة وتُقرأ — والردود والإشعارات الجديدة لا تخرج حتى تكتمل هذه الخطوات.')}
                        />
                        {mayManage && (
                            <Button asChild variant="outline" size="sm" className="mt-3">
                                <Link href={route('admin.integrations.whatsapp')}>{t('اذهب إلى ربط واتساب')}</Link>
                            </Button>
                        )}
                    </details>
                )}

                {empty ? (
                    <div className="flex flex-1 flex-col overflow-hidden rounded-[6px] border border-[var(--cv-border,#e9edef)]" data-testid="inbox-empty">
                        <ConversationEmptyState
                            icon={<MessageCircle className="size-7" />}
                            text={t('لا محادثات بعد — تظهر هنا رسائل عملائك وما خرج إليهم من إشعارات')}
                        />
                    </div>
                ) : (
                    <ConversationShell
                        pane={pane}
                        detailsHidden={detailsHidden || !thread}
                        list={
                            <ChatList
                                chats={chats}
                                pagination={pagination}
                                params={params}
                                filter={filter}
                                unreadChats={unreadChats}
                                go={go}
                                onOpen={() => setPane('thread')}
                            />
                        }
                        thread={thread ? (
                            <ThreadView
                                key={thread.id}
                                thread={thread}
                                canReply={replyAllowed}
                                files={files}
                                detailsHidden={detailsHidden}
                                onToggleDetails={toggleDetails}
                                onBack={() => setPane('list')}
                                onDetails={() => setPane('details')}
                            />
                        ) : (
                            <ConversationEmptyState icon={<MessageCircle className="size-7" />} text={t('اختر محادثة لعرض رسائلها')} />
                        )}
                        details={thread ? <CustomerDetails thread={thread} onBack={() => setPane('thread')} /> : null}
                    />
                )}
            </ConversationPage>
        </AdminLayout>
    );
}

function ChatList({
    chats,
    pagination,
    params,
    filter,
    unreadChats,
    go,
    onOpen,
}: {
    chats: Chat[];
    pagination: Props['pagination'];
    params: Props['params'];
    filter: 'all' | 'unread';
    unreadChats: number;
    go: (next: Record<string, string | number | null>) => void;
    onOpen: () => void;
}) {
    const t = useTranslate();
    const [q, setQ] = useState(params.q);
    const asked = useRef(params.q);
    const today = ymd(new Date());

    /* البحثُ في الخادم بعد أن يسكت الكاتب — باسم العميل أو رقمه، ومرّةً للكلمة الواحدة */
    const search = (term: string) => {
        if (asked.current === term) return;
        asked.current = term;
        go({ q: term || null, page: null });
    };

    useEffect(() => {
        const id = window.setTimeout(() => search(q), 350);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    return (
        <ConversationList
            testId="chat-list"
            header={
                <div className="space-y-2">
                    <ConversationSearch
                        value={q}
                        onChange={setQ}
                        onSubmit={() => search(q)}
                        placeholder={t('ابحث باسم العميل أو رقمه…')}
                        label={t('ابحث باسم العميل أو رقمه…')}
                    />
                    <FilterChips
                        label={t('عرض المحادثات')}
                        value={filter}
                        onChange={(f) => go({ f: f === 'all' ? null : f, page: null })}
                        items={[
                            { key: 'all', label: t('الكل') },
                            { key: 'unread', label: t('غير المقروءة'), n: unreadChats },
                        ]}
                    />
                </div>
            }
            footer={pagination.last_page > 1 ? (
                <div className="flex items-center justify-between text-[12px]">
                    {pagination.prev_page_url
                        ? <button type="button" className="text-[var(--cv-accent,#00a884)]" onClick={() => router.get(pagination.prev_page_url!, {}, { preserveState: true })}>{t('السابق')}</button>
                        : <span />}
                    <span className="text-[var(--cv-faint,#667781)] tabular-nums">{pagination.current_page} / {pagination.last_page}</span>
                    {pagination.next_page_url
                        ? <button type="button" className="text-[var(--cv-accent,#00a884)]" onClick={() => router.get(pagination.next_page_url!, {}, { preserveState: true })}>{t('التالي')}</button>
                        : <span />}
                </div>
            ) : undefined}
        >
            {chats.length === 0 && (
                <li className="p-6 text-center text-[13px] text-[var(--cv-faint,#667781)]">
                    {filter === 'unread' && params.q === '' ? t('لا محادثات غير مقروءة') : t('لا محادثات تطابق البحث')}
                </li>
            )}
            {chats.map((c) => (
                <ConversationListItem
                    key={c.key}
                    testId="chat-row"
                    unreadTestId="chat-unread"
                    title={c.name ?? c.phone}
                    subtitle={c.name ? c.phone : null}
                    preview={`${c.direction === 'out' ? `${t('أنت')}: ` : ''}${c.preview}`}
                    time={c.at ? (c.at.slice(0, 10) === today ? c.at.slice(11, 16) : c.at.slice(0, 10)) : null}
                    unread={c.unread}
                    avatar={<Avatar name={c.name ?? c.phone.slice(-2)} />}
                    badges={c.source === 'abaad_shared' ? <Pill className="bg-[#f0f2f5] text-[#54656f]">{t('رقم أبعاد المشترك')}</Pill> : undefined}
                    active={c.id !== null && params.c === String(c.id)}
                    onClick={() => {
                        onOpen();
                        if (c.url) {
                            router.visit(c.url);
                        } else if (c.id !== null) {
                            go({ c: c.id });
                        }
                    }}
                />
            ))}
        </ConversationList>
    );
}

export function ThreadView({
    thread,
    canReply,
    files,
    detailsHidden,
    onToggleDetails,
    onBack,
    onDetails,
}: {
    thread: Thread;
    canReply: boolean;
    files: Props['files'];
    detailsHidden: boolean;
    onToggleDetails: () => void;
    onBack: () => void;
    onDetails: () => void;
}) {
    const t = useTranslate();
    const [items, setItems] = useState<Item[]>(thread.messages);
    const [before, setBefore] = useState<number | null>(thread.before);
    const [more, setMore] = useState(thread.has_more);
    const [loading, setLoading] = useState(false);
    const today = ymd(new Date());
    const yesterday = ymd(new Date(Date.now() - 86_400_000));
    // «اليوم» و«أمس» بلغة الشاشة من المتصفّح — مفتاحُ «اليوم» في القاموس معناه «Day» لا «Today»
    const relative = new Intl.RelativeTimeFormat(usePage<PageProps>().props.locale === 'en' ? 'en' : 'ar', { numeric: 'auto' });
    const named = (n: number) => {
        const word = relative.format(n, 'day');
        return word.charAt(0).toUpperCase() + word.slice(1);
    };

    /* والجديدُ يصل بتحديث الصفحة — فيُستبدل الخيطُ بما وصل لا يُضاف إليه */
    useEffect(() => {
        setItems(thread.messages);
        setBefore(thread.before);
        setMore(thread.has_more);
    }, [thread.messages, thread.before, thread.has_more]);

    const loadOlder = async () => {
        if (!before || loading) return;
        setLoading(true);
        try {
            const res = await fetch(route('admin.whatsappInbox.older', { chat: thread.id, before }), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (res.ok) {
                const data = (await res.json()) as { messages: Item[]; has_more: boolean; before: number | null };
                setItems((m) => [...data.messages, ...m]);
                setBefore(data.before);
                setMore(data.has_more);
            }
        } finally {
            setLoading(false);
        }
    };

    const dayLabel = (day: string) => (day === today ? named(0) : day === yesterday ? named(-1) : day);

    return (
        <>
            <ConversationHeader
                avatar={<Avatar name={thread.name ?? thread.phone.slice(-2)} size="sm" />}
                title={thread.name ?? thread.phone}
                subtitle={<span dir="ltr">{thread.phone}</span>}
                onBack={onBack}
                onDetails={onDetails}
                actions={
                    <Button
                        variant="ghost"
                        size="icon"
                        className="hidden text-[var(--cv-icon,#54656f)] xl:inline-flex"
                        onClick={onToggleDetails}
                        aria-label={detailsHidden ? t('إظهار تفاصيل العميل') : t('إخفاء تفاصيل العميل')}
                        title={detailsHidden ? t('إظهار تفاصيل العميل') : t('إخفاء تفاصيل العميل')}
                        data-testid="inbox-details-toggle"
                    >
                        {detailsHidden ? <PanelLeftOpen className="rtl:rotate-180" /> : <PanelLeftClose className="rtl:rotate-180" />}
                    </Button>
                }
            />

            {thread.order && <GuidedOrderBar chatId={thread.id} order={thread.order} canReply={canReply} />}

            <ConversationThread threadKey={thread.id} count={items.length}>
                <div data-testid="thread" className="space-y-1">
                    {more && (
                        <div className="flex justify-center pb-2">
                            <Button variant="outline" size="sm" onClick={loadOlder} disabled={loading}>
                                {loading ? t('جاري التحميل…') : t('عرض الرسائل الأقدم')}
                            </Button>
                        </div>
                    )}

                    {items.map((m, i) => {
                        const prev = items[i - 1];
                        const day = m.at?.slice(0, 10) ?? null;
                        const newDay = day !== null && day !== (prev?.at?.slice(0, 10) ?? null);
                        const side = m.kind === 'in' ? 'in' : 'out';
                        const first = newDay || !prev || (prev.kind === 'in' ? 'in' : 'out') !== side;

                        return (
                            <div key={m.key}>
                                {newDay && day !== null && <SystemRow>{dayLabel(day)}</SystemRow>}
                                <Bubble item={m} first={first} />
                            </div>
                        );
                    })}
                </div>
            </ConversationThread>

            {canReply ? (
                <Composer thread={thread} files={files} />
            ) : (
                <div className="shrink-0 bg-[var(--cv-bar,#f0f2f5)] px-4 py-3 text-center text-[12.5px] text-[var(--cv-muted,#667781)]" data-testid="inbox-read-only">
                    {t('صلاحيتك على المحادثات للمشاهدة فقط — لا ترسل ردودًا.')}
                </div>
            )}
        </>
    );
}

function Bubble({ item: m, first }: { item: Item; first: boolean }) {
    const t = useTranslate();
    const failure = m.status === 'failed' ? (m.error ?? m.reason ?? null) : null;
    const queued = m.kind !== 'in' && m.status === 'queued';

    const footer = (failure || m.auto_replied || m.subject || queued) ? (
        <div className="mt-0.5 space-y-0.5 px-1 text-[11.5px]">
            {queued && <p className="text-[var(--cv-faint,#667781)]">{m.status_label}</p>}
            {failure && <p className="leading-relaxed text-[#b91c1c]" data-testid="bubble-failure">{failure}</p>}
            {m.kind === 'in' && m.auto_replied && (
                <p className="italic text-[var(--cv-muted,#667781)]" data-testid="bubble-auto-replied">{t('أُرسل إليه ردٌّ آليّ — نصُّه لا يُحفظ في المحادثة')}</p>
            )}
            {m.subject && (
                <Link href={m.subject.url} className="text-[var(--cv-accent,#00a884)] hover:underline">{m.subject.label}</Link>
            )}
        </div>
    ) : null;

    return (
        <div data-testid={`bubble-${m.kind}`}>
            <MessageBubble
                side={m.kind === 'in' ? 'in' : 'out'}
                first={first}
                body={m.body ?? (m.kind === 'notice' ? (m.summary ?? null) : null)}
                sender={m.kind === 'notice' ? `${t('إشعار آلي')} · ${m.event ?? ''}` : m.kind === 'out' ? m.sender : null}
                time={m.time}
                status={m.kind === 'in' ? null : mark(m.status)}
                statusLabel={m.status_label}
                files={m.attachments?.map((a) => ({ ...a, size: a.size ?? 0 }))}
                footer={footer}
            />
        </div>
    );
}

function Composer({ thread, files }: { thread: Thread; files: Props['files'] }) {
    const t = useTranslate();
    const form = useForm<{ body: string; files: File[]; client_key: string }>({ body: '', files: [], client_key: replyKey() });
    const followUp = useForm({});
    // وقالبُ المتابعة مدفوعٌ عند ميتا — يُسأل عنه قبل أن يخرج (`cost_acknowledged`)
    const [ask, dialog] = useConfirm();
    const errors = form.errors as Record<string, string | undefined>;
    const filesError = Object.entries(errors).find(([k]) => k.startsWith('files'))?.[1];

    const send = () => {
        if (thread.blocked || form.processing || (form.data.body.trim() === '' && form.data.files.length === 0)) return;

        form.post(route('admin.whatsappInbox.reply', thread.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                // ردٌّ خرج فمفتاحُه انتهى — والردُّ التالي بمفتاحه
                form.setData('client_key', replyKey());
            },
        });
    };

    const above = thread.blocked ? (
        <div className="mb-2 space-y-2 rounded-[8px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]" data-testid="inbox-blocked">
            <p>{thread.blocked}</p>
            {/* وخارج النافذة: القالبُ المعتمَد وحده — يُرسل مرّةً، ونصُّه من ميتا */}
            {thread.template && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    loading={followUp.processing}
                    onClick={async () => {
                        const yes = await ask({
                            title: 'إرسال قالب المتابعة',
                            message: 'قالب المتابعة رسالةٌ خارج نافذة ٢٤ ساعة، تحتسبها ميتا على حساب واتساب متجرك — لا على أبعاد — بحسب فئة القالب وبلد العميل. لا يُرسل إلّا بتأكيدك هذا. متابعة؟',
                            action: 'أرسل القالب',
                        });
                        if (!yes) return;

                        followUp.transform(() => ({ cost_acknowledged: true }));
                        followUp.post(route('admin.whatsappInbox.followUp', thread.id), { preserveScroll: true });
                    }}
                    data-testid="inbox-follow-up"
                >
                    <Send />
                    {t('إرسال قالب المتابعة')}
                </Button>
            )}
            {thread.template && (
                <p className="text-[11px] text-[#92400e]/80" data-testid="inbox-follow-up-cost">
                    {t('رسالةٌ مدفوعة تحتسبها ميتا على حساب واتساب متجرك.')}
                </p>
            )}
            {((followUp.errors as Record<string, string | undefined>).body ?? (followUp.errors as Record<string, string | undefined>).cost_acknowledged) && (
                <p className="text-[#b91c1c]" data-testid="inbox-follow-up-error">
                    {(followUp.errors as Record<string, string | undefined>).body ?? (followUp.errors as Record<string, string | undefined>).cost_acknowledged}
                </p>
            )}
        </div>
    ) : thread.window_ends_at ? (
        <p className="mb-1.5 text-center text-[11px] text-[var(--cv-faint,#667781)]">{t('نافذة الرد مفتوحة حتى :at', { at: thread.window_ends_at })}</p>
    ) : null;

    return (
        <div data-testid="inbox-composer">
            <MessageComposer
                value={form.data.body}
                onChange={(v) => form.setData('body', v)}
                onSend={send}
                files={form.data.files}
                onAddFiles={(picked) => form.setData('files', [...form.data.files, ...picked].slice(0, files.max))}
                onRemoveFile={(i) => form.setData('files', form.data.files.filter((_, j) => j !== i))}
                accept={files.accept}
                maxFiles={files.max}
                maxKb={files.max_kb}
                processing={form.processing}
                placeholder={t('اكتب ردك…')}
                disabled={!!thread.blocked}
                allowFilesOnly
                above={above}
                errors={{ body: errors.body, files: filesError }}
                testIds={{ body: 'inbox-body', send: 'inbox-send', files: 'inbox-files' }}
            />
            {dialog}
        </div>
    );
}

function CustomerDetails({ thread, onBack }: { thread: Thread; onBack: () => void }) {
    const t = useTranslate();
    const currency = usePage<PageProps>().props.context!.currency;
    const papers = (title: string, rows: Paper[], testId: string) => rows.length > 0 && (
        <DetailSection title={title}>
            <ul className="space-y-1.5" data-testid={testId}>
                {rows.map((p) => (
                    <li key={p.number} className="flex items-center gap-2 text-[12.5px]">
                        <Link href={p.url} className="font-medium text-[var(--cv-accent,#00a884)] hover:underline" dir="ltr">#{p.number}</Link>
                        <Pill className="bg-[#f0f2f5] text-[#54656f]">{p.status}</Pill>
                        <span className="ms-auto tabular-nums text-[var(--cv-muted,#667781)]">{money(p.total, currency)}</span>
                    </li>
                ))}
            </ul>
        </DetailSection>
    );

    return (
        <ConversationDetailsPanel title={t('تفاصيل العميل')} onBack={onBack}>
            <DetailSection>
                <DetailIdentity
                    avatar={<Avatar name={thread.name ?? thread.phone.slice(-2)} size="lg" />}
                    name={thread.name ?? thread.phone}
                    subtitle={<span dir="ltr">{thread.phone}</span>}
                >
                    {thread.customer ? (thread.customer.url ? (
                        <Link href={thread.customer.url} className="text-[12.5px] text-[var(--cv-accent,#00a884)] hover:underline" data-testid="thread-customer">{t('ملف العميل')}</Link>
                    ) : (
                        <span className="text-[12.5px] text-[var(--cv-muted,#667781)]" data-testid="thread-customer">{t('عميل مسجَّل')}</span>
                    )) : (
                        <span className="text-[12px] text-[var(--cv-faint,#667781)]">{t('لم يُربط بملف عميل بعد')}</span>
                    )}
                </DetailIdentity>
            </DetailSection>

            <DetailSection title={t('المحادثة')}>
                <dl>
                    <DetailLine
                        label={t('رقم المتجر')}
                        value={thread.source === 'abaad_shared' ? t('رقم أبعاد المشترك') : (thread.line_number ?? t('رقم سابق'))}
                        ltr={thread.source !== 'abaad_shared' && !!thread.line_number}
                    />
                    {thread.orders[0] && <DetailLine label={t('حالة آخر طلب')} value={thread.orders[0].status} />}
                    <DetailLine label={t('نافذة الرد')} value={thread.window_ends_at ? t('مفتوحة حتى :at', { at: thread.window_ends_at }) : null} empty={t('مغلقة — بالقالب المعتمد وحده')} />
                </dl>
                {thread.log_url && (
                    <Link href={thread.log_url} className="mt-1 inline-block text-[12.5px] text-[var(--cv-accent,#00a884)] hover:underline" data-testid="thread-log">{t('سجلّ رسائله')}</Link>
                )}
            </DetailSection>

            {papers(t('الطلبات'), thread.orders, 'thread-orders')}
            {papers(t('الفواتير'), thread.invoices ?? [], 'thread-invoices')}
        </ConversationDetailsPanel>
    );
}
