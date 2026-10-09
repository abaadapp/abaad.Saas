import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertCircle, ArrowRight, Check, CheckCheck, Clock, Search } from 'lucide-react';
import { WhatsAppBusinessMark } from '@/Components/BrandMarks';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import type { ServerPagination } from '@/Components/DataTable';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** محادثةٌ في القائمة — انظر `WhatsAppConversations::page` */
export interface Conversation {
    /** معرّفُ آخر رسالة — مفتاحُ المحادثة في العنوان، لا رقمُ الزبون */
    key: number;
    name: string | null;
    phone: string;
    messages: number;
    at: string | null;
    preview: string;
    status: string | null;
    status_label: string | null;
}

/** رسالةٌ في المحادثة — انظر `WhatsAppConversations::bubble` */
export interface Bubble {
    id: number;
    at: string | null;
    time: string | null;
    event: string;
    status: string;
    status_label: string;
    reason: string | null;
    error: string | null;
    ours: boolean | null;
    subject: { label: string; url: string } | null;
    subject_kind: 'order' | 'invoice' | null;
    /** النصُّ كما حاول النظامُ إرسالَه — أو لا شيء إن لم يُحفظ */
    body: string | null;
    /** وصفٌ لا نصّ: «إشعار … — #123» — لِما لا نصَّ محفوظًا له */
    summary: string | null;
}

export interface Thread {
    key: number;
    name: string | null;
    phone: string;
    messages: Bubble[];
    has_more: boolean;
    before: number | null;
}

interface Props {
    connected: boolean;
    number: string | null;
    conversations: Conversation[];
    pagination: ServerPagination | null;
    params: { q: string; c: string | null };
    thread: Thread | null;
}

/**
 * علامةُ الحال — بحالات النظام نفسِها (`WhatsAppStatus::LABELS`) لا بقاموسٍ ثانٍ.
 *
 * ✓ خرجت، ✓✓ وصلت، ✓✓ زرقاء قُرئت، ساعةٌ تنتظر، ودائرةُ تنبيهٍ لم تصل.
 */
function StatusMark({ status, label }: { status: string | null; label: string | null }) {
    if (!status) return null;

    const icon =
        status === 'queued' ? <Clock size={13} /> :
        status === 'sent' ? <Check size={14} /> :
        status === 'delivered' ? <CheckCheck size={14} /> :
        status === 'read' ? <CheckCheck size={14} className="text-[#0ea5e9]" /> :
        status === 'failed' ? <AlertCircle size={13} className="text-[#b91c1c]" /> : null;

    return (
        <span className={cn('inline-flex items-center gap-1', status === 'failed' ? 'text-[#b91c1c]' : 'text-[#6b7280]')}>
            {icon}
            <span>{label}</span>
        </span>
    );
}

/**
 * محادثات واتساب — ما أرسله النظامُ من رقم المحلّ، مرتّبًا محادثاتٍ.
 *
 * قراءةٌ محضة: لا حقلَ كتابةٍ ولا زرَّ إرسال ولا ردّ. ولا واردَ ولا تاريخَ
 * من واتساب: صفوفُ الرسائل الصادرة من الرقم الحاليّ وحدها.
 *
 * والمحادثةُ المختارة في العنوان (`?c=`) بمعرّف رسالةٍ لا برقم الزبون — فزرُّ
 * الرجوع في الهاتف يعود إلى القائمة كما يتوقّع صاحبُه.
 */
export default function WhatsappConversations() {
    const { connected, number, conversations, pagination, params, thread } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    if (!connected) {
        return (
            <AdminLayout title="محادثات واتساب">
                <PageHeader title="محادثات واتساب" subtitle={t('ما أرسله النظام من رقمك إلى زبائنك')} />
                <Gate
                    mark={<WhatsAppBusinessMark size={80} />}
                    title="واتساب غير مربوط بعد"
                    description="اربط رقم واتساب لبدء سجل المحادثات"
                    action={
                        <Button asChild size="lg">
                            <Link href={route('admin.integrations.whatsapp')}>{t('اذهب إلى ربط واتساب')}</Link>
                        </Button>
                    }
                />
            </AdminLayout>
        );
    }

    const empty = conversations.length === 0 && params.q === '';

    return (
        <AdminLayout title="محادثات واتساب">
            <PageHeader
                title="محادثات واتساب"
                subtitle={number ? t('ما أرسله النظام من رقمك :number — للقراءة فقط', { number }) : t('ما أرسله النظام من رقمك إلى زبائنك')}
            />

            {empty ? (
                <Card className="p-10 text-center text-[14px] text-[#6b7280]" data-testid="conversations-empty">
                    {t('لم تُرسل رسائل عبر رقمك بعد')}
                </Card>
            ) : (
                <Card className="grid min-h-[560px] overflow-hidden md:grid-cols-[320px_1fr]">
                    {/* القائمة — وتختفي في الهاتف حين تُفتح محادثة */}
                    <div className={cn('flex flex-col border-[var(--ui-border,#e8e8e8)] md:border-e', thread && 'hidden md:flex')}>
                        <ConversationList conversations={conversations} pagination={pagination} params={params} />
                    </div>

                    <div className={cn('flex min-w-0 flex-col', !thread && 'hidden md:flex')}>
                        {thread ? (
                            <ThreadView key={thread.key} thread={thread} q={params.q} />
                        ) : (
                            <div className="m-auto p-8 text-center text-[13px] text-[#9ca3af]">
                                {t('اختر محادثةً لعرض رسائلها')}
                            </div>
                        )}
                    </div>
                </Card>
            )}
        </AdminLayout>
    );
}

function ConversationList({
    conversations,
    pagination,
    params,
}: {
    conversations: Conversation[];
    pagination: ServerPagination | null;
    params: Props['params'];
}) {
    const t = useTranslate();
    const [q, setQ] = useState(params.q);
    const first = useRef(true);

    /* البحثُ في الخادم بعد أن يسكت الكاتب — لا ترشيحُ ما وصل */
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = window.setTimeout(() => {
            router.get(route('admin.marketing.whatsapp.log'), q ? { q } : {}, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
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

            <ul className="flex-1 divide-y divide-[var(--ui-border,#f0f0f0)] overflow-y-auto" data-testid="conversation-list">
                {conversations.length === 0 && (
                    <li className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا محادثات تطابق البحث')}</li>
                )}
                {conversations.map((c) => (
                    <li key={c.key}>
                        <Link
                            href={route('admin.marketing.whatsapp.log', { ...(params.q ? { q: params.q } : {}), c: c.key })}
                            preserveScroll
                            className={cn(
                                'block px-4 py-3 hover:bg-[#f9fafb]',
                                params.c === String(c.key) && 'bg-[#f3f4f6]',
                            )}
                        >
                            <div className="flex items-baseline justify-between gap-2">
                                <span className="truncate text-[14px] font-bold text-[#111]" dir={c.name ? undefined : 'ltr'}>
                                    {c.name ?? c.phone}
                                </span>
                                <span className="shrink-0 text-[11px] text-[#9ca3af]" dir="ltr">{c.at}</span>
                            </div>
                            {c.name && <div className="text-[12px] text-[#6b7280]" dir="ltr">{c.phone}</div>}
                            <div className="mt-1 flex items-center gap-2 text-[12px] text-[#6b7280]">
                                <span className="truncate">{c.preview}</span>
                            </div>
                            <div className="mt-1 text-[11px]">
                                <StatusMark status={c.status} label={c.status_label} />
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>

            {pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-[var(--ui-border,#e8e8e8)] p-3 text-[12px]">
                    {pagination.prev_page_url ? (
                        <Link href={pagination.prev_page_url} preserveScroll className="text-[#6d28d9]">{t('السابق')}</Link>
                    ) : <span />}
                    <span className="text-[#9ca3af]">{pagination.current_page} / {pagination.last_page}</span>
                    {pagination.next_page_url ? (
                        <Link href={pagination.next_page_url} preserveScroll className="text-[#6d28d9]">{t('التالي')}</Link>
                    ) : <span />}
                </div>
            )}
        </>
    );
}

function ThreadView({ thread, q }: { thread: Thread; q: string }) {
    const t = useTranslate();
    const [messages, setMessages] = useState<Bubble[]>(thread.messages);
    const [before, setBefore] = useState<number | null>(thread.before);
    const [more, setMore] = useState(thread.has_more);
    const [loading, setLoading] = useState(false);

    /* الأقدمُ خمسون قبل أقدم ما ظهر — من الخادم، ويُضاف فوقها */
    const loadOlder = async () => {
        if (!before || loading) return;
        setLoading(true);
        try {
            const res = await fetch(
                route('admin.marketing.whatsapp.conversations.older', { message: thread.key, before }),
                { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' },
            );
            if (res.ok) {
                const data = (await res.json()) as { messages: Bubble[]; has_more: boolean; before: number | null };
                setMessages((m) => [...data.messages, ...m]);
                setBefore(data.before);
                setMore(data.has_more);
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <div className="flex items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                {/* الرجوعُ إلى القائمة — في الهاتف وحده، فالقائمةُ ظاهرةٌ بجوارها في الشاشة العريضة */}
                <Link
                    href={route('admin.marketing.whatsapp.log', q ? { q } : {})}
                    preserveScroll
                    className="md:hidden"
                    aria-label={t('رجوع إلى المحادثات')}
                    data-testid="thread-back"
                >
                    <ArrowRight size={18} className="rtl:rotate-0 ltr:rotate-180" />
                </Link>
                <div className="min-w-0">
                    <div className="truncate text-[15px] font-bold text-[#111]" dir={thread.name ? undefined : 'ltr'}>
                        {thread.name ?? thread.phone}
                    </div>
                    {thread.name && <div className="text-[12px] text-[#6b7280]" dir="ltr">{thread.phone}</div>}
                </div>
            </div>

            <div className="flex flex-1 flex-col gap-3 overflow-y-auto bg-[#f7f7f5] p-4" data-testid="thread">
                {more && (
                    <Button variant="outline" size="sm" className="self-center" onClick={loadOlder} disabled={loading}>
                        {loading ? t('جاري التحميل…') : t('عرض الرسائل الأقدم')}
                    </Button>
                )}

                {messages.map((m) => (
                    <div
                        key={m.id}
                        data-testid="bubble"
                        className="max-w-[85%] self-end rounded-[14px] bg-white px-3 py-2 shadow-sm md:max-w-[70%]"
                    >
                        {m.body ? (
                            <p className="whitespace-pre-wrap text-[14px] leading-relaxed text-[#111]">{m.body}</p>
                        ) : (
                            /* وصفٌ لا نصّ — يُكتب بغير شكل الرسالة كي لا يُقرأ نصَّها الحرفيّ */
                            <p className="text-[13px] italic text-[#6b7280]" data-testid="bubble-summary">
                                {m.summary}
                                <span className="mt-0.5 block text-[11px] not-italic text-[#9ca3af]">
                                    {t('نصّ الرسالة غير محفوظ — هذا وصفها')}
                                </span>
                            </p>
                        )}

                        {m.subject && (
                            <Link href={m.subject.url} className="mt-1 inline-block text-[12px] text-[#6d28d9] hover:underline">
                                {m.subject_kind === 'invoice'
                                    ? t('عرض :label', { label: m.subject.label })
                                    : t('عرض الطلب :label', { label: m.subject.label })}
                            </Link>
                        )}

                        <div className="mt-1 flex flex-wrap items-center justify-end gap-x-2 gap-y-0.5 text-[11px] text-[#9ca3af]">
                            <span>{m.event}</span>
                            <span dir="ltr">{m.time}</span>
                            <StatusMark status={m.status} label={m.status_label} />
                        </div>

                        {/* سببُ الفشل كما يقوله السجلّ نفسُه (`WhatsAppLog::row`) — لا نصٌّ ثانٍ */}
                        {m.status === 'failed' && (m.reason || m.error) && (
                            <div className="mt-1 space-y-0.5 text-[12px] leading-relaxed text-[#6b7280]" data-testid="bubble-failure">
                                {m.reason && <p>{m.reason}</p>}
                                {m.error && (
                                    <p>
                                        <span className={m.ours ? 'font-bold text-[#92400e]' : 'font-bold text-[#b91c1c]'}>
                                            {m.ours ? t('العطب عند أبعاد — ونحن نعالجه.') : t('راجِع رقم الزبون.')}
                                        </span>{' '}
                                        <span dir="ltr">{m.error}</span>
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </>
    );
}
