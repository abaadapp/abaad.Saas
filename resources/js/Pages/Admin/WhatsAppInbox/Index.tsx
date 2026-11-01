import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, ArrowRight, Check, CheckCheck, Clock, FileText, Paperclip, Search, Send, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ConnectSteps, type Readiness } from '@/Components/Connect';
import { useConfirm } from '@/Components/ConfirmDialog';
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
    attachments?: { id: number; name: string; image: boolean; url: string }[];
    /** الإشعار: الحدثُ ووصفُه حين لا نصَّ محفوظًا */
    event?: string;
    summary?: string | null;
    subject?: { label: string; url: string } | null;
}

export interface Thread {
    id: number;
    name: string | null;
    phone: string;
    profile_name: string | null;
    customer: { name: string; url: string | null } | null;
    orders: { number: string; total: number; status: string; url: string }[];
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
    params: { q: string; c: string | null };
    thread: Thread | null;
    canReply: boolean;
    line: { mode: string; number: string | null; usable: boolean; status: string | null; expires_at: string | null };
    /** خطواتُ الربط وحالُ كلٍّ — `WhatsAppFeature::readiness` */
    readiness: Readiness;
    /** يملك الربطَ فيُدَلّ عليه */
    mayManage: boolean;
    unread: number;
    stamp: string;
    files: { max: number; max_kb: number; accept: string };
}

/** كلَّ كم يُسأل الخادم: أوصل جديد؟ — وأما زالت الصلاحية؟ */
export const POLL_MS = 10_000;

function StatusMark({ status, label }: { status: string | null; label: string | null }) {
    if (!status || !label) return null;

    const icon =
        status === 'queued' ? <Clock size={13} /> :
        status === 'sent' ? <Check size={14} /> :
        status === 'delivered' ? <CheckCheck size={14} /> :
        status === 'read' ? <CheckCheck size={14} className="text-[#0ea5e9]" /> :
        status === 'failed' ? <AlertCircle size={13} className="text-[#b91c1c]" /> : null;

    return (
        <span className={cn('inline-flex items-center gap-1', status === 'failed' ? 'text-[#b91c1c]' : 'text-[#6b7280]')} data-testid="item-status">
            {icon}
            <span>{label}</span>
        </span>
    );
}

/**
 * «محادثات واتساب» — صندوقُ المتجر مع زبائنه.
 *
 * من يقرأ يرى القائمةَ والخيط، ومن يردّ يرى مربّعَ الردّ معهما. وما يمنع
 * الردَّ يُقال فوق المربّع قبل الكتابة — بالسبب نفسِه الذي يردّ به الخادم.
 *
 * والصفحةُ تسأل كلَّ بضع ثوانٍ: وصل جديد فتُحدَّث، أو سُحبت الصلاحيةُ فيُغلق
 * ما أمامه — لا يبقى خيطٌ مفتوحٌ لمن لم يعد له.
 */
export default function WhatsAppInbox() {
    const { chats, pagination, params, thread, canReply, line, stamp, files, readiness, mayManage } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [revoked, setRevoked] = useState(false);
    const [replyAllowed, setReplyAllowed] = useState(canReply);
    const last = useRef(stamp);

    useEffect(() => setReplyAllowed(canReply), [canReply]);
    useEffect(() => {
        last.current = stamp;
    }, [stamp]);

    useEffect(() => {
        if (revoked) return;

        const id = window.setInterval(async () => {
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
                    router.reload({ only: ['chats', 'thread', 'unread', 'stamp', 'canReply', 'line', 'readiness'] });
                }
            } catch {
                /* انقطاعُ شبكةٍ لحظيّ — يُسأل في الدورة التالية */
            }
        }, POLL_MS);

        return () => window.clearInterval(id);
    }, [revoked]);

    if (revoked) {
        return (
            <AdminLayout title="صندوق محادثات واتساب">
                <PageHeader title="صندوق محادثات واتساب" />
                <Card className="p-10 text-center text-[14px] text-[#6b7280]" data-testid="inbox-revoked">
                    {t('لم تعد لك صلاحية على محادثات واتساب — أُغلقت المحادثة. راجع صاحب المتجر.')}
                </Card>
            </AdminLayout>
        );
    }

    const empty = chats.length === 0 && params.q === '';

    return (
        <AdminLayout title="صندوق محادثات واتساب">
            <PageHeader
                title="صندوق محادثات واتساب"
                subtitle={line.mode === 'business_own'
                    ? (line.number ? t('على رقم متجرك :number', { number: line.number }) : t('على رقم متجرك'))
                    : t('ردود عملائك على رقم أبعاد المشترك — ما ثبت أنه لمتجرك وحده')}
            />

            {!line.usable && (
                <div className="mb-4 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e]" data-testid="inbox-line-down">
                    {line.mode === 'business_own'
                        ? t('رقم متجرك لا يرسل الآن — تُقرأ المحادثات ولا يخرج رد حتى يُعاد الربط. ولا يُرسل من رقم آخر.')
                        : t('رقم أبعاد المشترك لا يرسل الآن — تُقرأ المحادثات ولا يخرج رد.')}
                </div>
            )}

            {/* وما ينقص الربطَ بخطواته وأين يُصلَح — والمحادثاتُ تحته تُقرأ كما هي */}
            {!readiness.ready && (
                <details className="mb-4" data-testid="inbox-readiness">
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
                <Card className="p-10 text-center text-[14px] text-[#6b7280]" data-testid="inbox-empty">
                    {t('لا محادثات بعد — تظهر هنا رسائل عملائك وما خرج إليهم من إشعارات')}
                </Card>
            ) : (
                <Card className="grid min-h-[560px] overflow-hidden md:grid-cols-[320px_1fr]">
                    <div className={cn('flex flex-col border-[var(--ui-border,#e8e8e8)] md:border-e', thread && 'hidden md:flex')}>
                        <ChatList chats={chats} pagination={pagination} params={params} />
                    </div>

                    <div className={cn('flex min-w-0 flex-col', !thread && 'hidden md:flex')}>
                        {thread ? (
                            <ThreadView
                                key={thread.id}
                                thread={thread}
                                q={params.q}
                                canReply={replyAllowed}
                                files={files}
                            />
                        ) : (
                            <div className="m-auto p-8 text-center text-[13px] text-[#9ca3af]">{t('اختر محادثةً لعرض رسائلها')}</div>
                        )}
                    </div>
                </Card>
            )}
        </AdminLayout>
    );
}

function ChatList({ chats, pagination, params }: { chats: Chat[]; pagination: Props['pagination']; params: Props['params'] }) {
    const t = useTranslate();
    const [q, setQ] = useState(params.q);
    const first = useRef(true);

    /* البحثُ في الخادم بعد أن يسكت الكاتب — باسم العميل أو رقمه */
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = window.setTimeout(() => {
            router.get(route('admin.whatsappInbox.index'), q ? { q } : {}, { preserveState: true, preserveScroll: true, replace: true });
        }, 350);
        return () => window.clearTimeout(id);
    }, [q]);

    return (
        <>
            <div className="border-b border-[var(--ui-border,#e8e8e8)] p-3">
                <div className="relative">
                    <Search size={15} className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-[#9ca3af]" />
                    <Input
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('ابحث باسم العميل أو رقمه…')}
                        aria-label={t('ابحث باسم العميل أو رقمه…')}
                        className="ps-9"
                    />
                </div>
            </div>

            <ul className="flex-1 divide-y divide-[var(--ui-border,#f0f0f0)] overflow-y-auto" data-testid="chat-list">
                {chats.length === 0 && <li className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا محادثات تطابق البحث')}</li>}
                {chats.map((c) => (
                    <li key={c.key}>
                        <Link
                            href={c.url ?? route('admin.whatsappInbox.index', { ...(params.q ? { q: params.q } : {}), c: c.id })}
                            preserveScroll
                            className={cn('block px-4 py-3 hover:bg-[#f9fafb]', c.id !== null && params.c === String(c.id) && 'bg-[#f3f4f6]')}
                            data-testid="chat-row"
                        >
                            <div className="flex items-baseline justify-between gap-2">
                                <span className={cn('truncate text-[14px] text-[#111]', c.unread > 0 ? 'font-bold' : 'font-medium')} dir={c.name ? undefined : 'ltr'}>
                                    {c.name ?? c.phone}
                                </span>
                                <span className="shrink-0 text-[11px] text-[#9ca3af]" dir="ltr">{c.at}</span>
                            </div>
                            {c.name && <div className="text-[12px] text-[#6b7280]" dir="ltr">{c.phone}</div>}
                            <div className="mt-1 flex items-center gap-2 text-[12px] text-[#6b7280]">
                                <span className="truncate">{c.direction === 'out' ? `${t('أنت')}: ` : ''}{c.preview}</span>
                                {c.unread > 0 && (
                                    <span className="ms-auto flex min-w-[18px] shrink-0 items-center justify-center rounded-full bg-[#16a34a] px-1 text-[10px] font-bold tabular-nums text-white" data-testid="chat-unread">
                                        {c.unread}
                                    </span>
                                )}
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>

            {pagination.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-[var(--ui-border,#e8e8e8)] p-3 text-[12px]">
                    {pagination.prev_page_url ? <Link href={pagination.prev_page_url} preserveScroll className="text-[#6d28d9]">{t('السابق')}</Link> : <span />}
                    <span className="text-[#9ca3af]">{pagination.current_page} / {pagination.last_page}</span>
                    {pagination.next_page_url ? <Link href={pagination.next_page_url} preserveScroll className="text-[#6d28d9]">{t('التالي')}</Link> : <span />}
                </div>
            )}
        </>
    );
}

export function ThreadView({ thread, q, canReply, files }: { thread: Thread; q: string; canReply: boolean; files: Props['files'] }) {
    const t = useTranslate();
    const [items, setItems] = useState<Item[]>(thread.messages);
    const [before, setBefore] = useState<number | null>(thread.before);
    const [more, setMore] = useState(thread.has_more);
    const [loading, setLoading] = useState(false);

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

    return (
        <>
            <div className="flex items-start gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <Link href={route('admin.whatsappInbox.index', q ? { q } : {})} preserveScroll className="mt-1 md:hidden" aria-label={t('رجوع إلى المحادثات')}>
                    <ArrowRight size={18} className="rtl:rotate-0 ltr:rotate-180" />
                </Link>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[15px] font-bold text-[#111]" dir={thread.name ? undefined : 'ltr'} data-testid="thread-name">
                        {thread.name ?? thread.phone}
                    </div>
                    <div className="text-[12px] text-[#6b7280]" dir="ltr">{thread.phone}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
                        {thread.customer && (thread.customer.url ? (
                            <Link href={thread.customer.url} className="text-[#6d28d9] hover:underline" data-testid="thread-customer">{t('ملف العميل')}</Link>
                        ) : (
                            <span className="text-[#6b7280]" data-testid="thread-customer">{t('عميل مسجَّل')}</span>
                        ))}
                        {thread.orders.map((o) => (
                            <Link key={o.number} href={o.url} className="text-[#6d28d9] hover:underline" dir="ltr">#{o.number}</Link>
                        ))}
                        <span className="text-[#9ca3af]">
                            {thread.source === 'abaad_shared' ? t('على رقم أبعاد المشترك') : thread.line_number ? t('على رقم :number', { number: thread.line_number }) : t('على رقم سابق')}
                        </span>
                        {thread.log_url && (
                            <Link href={thread.log_url} className="text-[#6d28d9] hover:underline" data-testid="thread-log">{t('سجلّ رسائله')}</Link>
                        )}
                    </div>
                </div>
            </div>

            {thread.order && <GuidedOrderBar chatId={thread.id} order={thread.order} canReply={canReply} />}

            <div className="flex flex-1 flex-col gap-3 overflow-y-auto bg-[#f7f7f5] p-4" data-testid="thread">
                {more && (
                    <Button variant="outline" size="sm" className="self-center" onClick={loadOlder} disabled={loading}>
                        {loading ? t('جاري التحميل…') : t('عرض الرسائل الأقدم')}
                    </Button>
                )}

                {items.map((m) => <Bubble key={m.key} item={m} />)}
            </div>

            {canReply ? (
                <Composer thread={thread} files={files} />
            ) : (
                <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[12px] text-[#6b7280]" data-testid="inbox-read-only">
                    {t('صلاحيتك على المحادثات للمشاهدة فقط — لا ترسل ردودًا.')}
                </div>
            )}
        </>
    );
}

function Bubble({ item: m }: { item: Item }) {
    const t = useTranslate();
    const ours = m.kind !== 'in';

    return (
        <div
            data-testid={`bubble-${m.kind}`}
            className={cn(
                'max-w-[85%] rounded-[14px] px-3 py-2 shadow-sm md:max-w-[70%]',
                ours ? 'self-end' : 'self-start',
                m.kind === 'out' ? 'bg-[#d9fdd3]' : 'bg-white',
            )}
        >
            {m.kind === 'notice' && <p className="mb-0.5 text-[11px] font-medium text-[#6b7280]">{t('إشعار آلي')} · {m.event}</p>}

            {m.body ? (
                <p className="whitespace-pre-wrap text-[14px] leading-relaxed text-[#111]">{m.body}</p>
            ) : m.summary ? (
                <p className="text-[13px] italic text-[#6b7280]">{m.summary}</p>
            ) : null}

            {m.attachments?.map((a) => (
                <a key={a.id} href={a.url} target="_blank" rel="noreferrer" className="mt-1 block" data-testid="bubble-attachment">
                    {a.image ? (
                        <img src={a.url} alt={a.name} className="max-h-60 rounded-[10px]" />
                    ) : (
                        <span className="inline-flex items-center gap-1 text-[13px] text-[#6d28d9] hover:underline">
                            <FileText size={14} /> {a.name}
                        </span>
                    )}
                </a>
            ))}

            {m.subject && (
                <Link href={m.subject.url} className="mt-1 inline-block text-[12px] text-[#6d28d9] hover:underline">{m.subject.label}</Link>
            )}

            <div className="mt-1 flex flex-wrap items-center justify-end gap-x-2 gap-y-0.5 text-[11px] text-[#9ca3af]">
                {m.kind === 'out' && m.sender && <span data-testid="bubble-sender">{m.sender}</span>}
                <span dir="ltr">{m.time}</span>
                {ours && <StatusMark status={m.status} label={m.status_label} />}
            </div>

            {m.kind === 'in' && m.auto_replied && (
                <p className="mt-1 text-[11px] italic text-[#6b7280]" data-testid="bubble-auto-replied">{t('أُرسل إليه ردٌّ آليّ — نصُّه لا يُحفظ في المحادثة')}</p>
            )}

            {m.status === 'failed' && (m.error || m.reason) && (
                <p className="mt-1 text-[12px] leading-relaxed text-[#b91c1c]" data-testid="bubble-failure">{m.error ?? m.reason}</p>
            )}
        </div>
    );
}

function Composer({ thread, files }: { thread: Thread; files: Props['files'] }) {
    const t = useTranslate();
    const picker = useRef<HTMLInputElement>(null);
    const form = useForm<{ body: string; files: File[] }>({ body: '', files: [] });
    const followUp = useForm({});
    // وقالبُ المتابعة مدفوعٌ عند ميتا — يُسأل عنه قبل أن يخرج (`cost_acknowledged`)
    const [ask, dialog] = useConfirm();

    const send = (e: React.FormEvent) => {
        e.preventDefault();
        if (thread.blocked || (form.data.body.trim() === '' && form.data.files.length === 0)) return;

        form.post(route('admin.whatsappInbox.reply', thread.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={send} className="space-y-2 border-t border-[var(--ui-border,#e8e8e8)] p-3" data-testid="inbox-composer">
            {thread.blocked ? (
                <div className="space-y-2 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]" data-testid="inbox-blocked">
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
                                if (! yes) return;

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
                <p className="text-[11px] text-[#9ca3af]">{t('نافذة الرد مفتوحة حتى :at', { at: thread.window_ends_at })}</p>
            ) : null}

            {form.data.files.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {form.data.files.map((f, i) => (
                        <li key={`${f.name}-${i}`} className="inline-flex items-center gap-1 rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[12px]">
                            {f.name}
                            <button
                                type="button"
                                aria-label={t('إزالة')}
                                onClick={() => form.setData('files', form.data.files.filter((_, j) => j !== i))}
                            >
                                <X size={12} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex items-end gap-2">
                <textarea
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    disabled={!!thread.blocked}
                    rows={2}
                    maxLength={4000}
                    placeholder={t('اكتب ردك…')}
                    aria-label={t('اكتب ردك…')}
                    className="min-h-[44px] flex-1 resize-y rounded-[10px] border border-[#e5e7eb] px-3 py-2 text-[14px] disabled:bg-[#f9fafb]"
                    data-testid="inbox-body"
                />
                <input
                    ref={picker}
                    type="file"
                    multiple
                    accept={files.accept}
                    className="hidden"
                    data-testid="inbox-files"
                    onChange={(e) => {
                        const picked = Array.from(e.target.files ?? []);
                        form.setData('files', [...form.data.files, ...picked].slice(0, files.max));
                        e.target.value = '';
                    }}
                />
                <Button type="button" variant="outline" size="icon" disabled={!!thread.blocked} onClick={() => picker.current?.click()} aria-label={t('إرفاق ملف')}>
                    <Paperclip />
                </Button>
                <Button type="submit" loading={form.processing} disabled={!!thread.blocked} data-testid="inbox-send">
                    <Send />
                    {t('إرسال')}
                </Button>
            </div>

            {(form.errors.body || Object.entries(form.errors).find(([k]) => k.startsWith('files'))?.[1]) && (
                <p className="text-[12px] text-[#b91c1c]" data-testid="inbox-error">
                    {form.errors.body ?? Object.entries(form.errors).find(([k]) => k.startsWith('files'))?.[1]}
                </p>
            )}

            {dialog}
        </form>
    );
}
