import { router, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import {
    ArrowLeft,
    Building2,
    ExternalLink,
    FileText,
    Inbox,
    Lock,
    Paperclip,
    Search,
    Send,
    X,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/* ═══════════════════ الأنواع ═══════════════════ */

interface Row {
    id: number;
    reference: string;
    business: string;
    businessLogo: string | null;
    opener: string | null;
    subject: string;
    preview: string;
    channel: string;
    channelLabel: string;
    status: string;
    statusLabel: string;
    priority: string;
    priorityLabel: string;
    assignee: string | null;
    lastMessageAt: string | null;
    unread: number;
}

interface Message {
    id: number;
    scope: 'business' | 'platform' | 'system';
    internal: boolean;
    body: string | null;
    event: string | null;
    eventText: string | null;
    sender: string;
    at: string | null;
    files: { id: number; name: string; size: number; isImage: boolean; url: string }[];
}

interface Active {
    id: number;
    reference: string;
    subject: string;
    category: string;
    status: string;
    statusLabel: string;
    priority: string;
    channelLabel: string;
    assigneeId: number | null;
    assignee: string | null;
    lastMessageAt: string | null;
    business: {
        id: number | null;
        name: string;
        logo: string | null;
        status: string | null;
        owner: string | null;
        email: string | null;
        phone: string | null;
        url: string | null;
    };
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    conversations: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { q: string; status: string; assignment: string };
    counts: Record<string, number>;
    active: Active | null;
    messages: Message[];
    staff: { id: number; name: string }[];
    statuses: Option[];
    priorities: Option[];
    channels: Option[];
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}

/* ═══════════════════ أدوات ═══════════════════ */

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('');

/**
 * وقتٌ يُقرأ بلمحة — «منذ ٣ دقائق» لا طابعُ ISO.
 *
 * ومن يقرأ قائمةَ دعمٍ يريد أن يعرف «متى» لا «أيّ لحظةٍ بالضبط»: التاريخُ
 * الكامل يُقرأ حرفًا حرفًا، والفارقُ يُقرأ نظرة.
 */
const ago = (iso: string | null, t: (k: string, r?: Record<string, string | number>) => string) => {
    if (!iso) return '';
    const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return t('الآن');
    if (mins < 60) return t('منذ :n دقيقة', { n: mins });
    const hours = Math.floor(mins / 60);
    if (hours < 24) return t('منذ :n ساعة', { n: hours });
    const days = Math.floor(hours / 24);
    if (days === 1) return t('أمس');
    if (days < 30) return t('منذ :n يوم', { n: days });
    return new Date(iso).toLocaleDateString();
};

const kb = (bytes: number) => `${Math.max(1, Math.round(bytes / 1024))} KB`;

/** لونُ حبّةِ الحالة — والمعنى قبل اللون: النصّ مكتوبٌ فيها دائمًا */
const statusTone = (status: string) =>
    ({
        new: 'bg-[#eef2ff] text-[#4338ca]',
        open: 'bg-[#ecfdf5] text-[#047857]',
        waiting_customer: 'bg-[#fffbeb] text-[#b45309]',
        waiting_abaad: 'bg-[#fef2f2] text-[#b91c1c]',
        resolved: 'bg-[#f0fdf4] text-[#15803d]',
        closed: 'bg-[#f4f4f5] text-[#52525b]',
    })[status] ?? 'bg-[#f4f4f5] text-[#52525b]';

const priorityTone = (priority: string) =>
    ({
        low: 'text-[#9ca3af]',
        normal: 'text-[#6b7280]',
        high: 'text-[#b45309]',
        urgent: 'text-[#b91c1c]',
    })[priority] ?? 'text-[#6b7280]';

/* ═══════════════════ الشاشة ═══════════════════ */

export default function Conversations({
    conversations,
    filters,
    counts,
    active,
    messages,
    staff,
    statuses,
    priorities,
    channels,
    maxFiles,
    maxKb,
    extensions,
}: Props) {
    const t = useTranslate();
    const [term, setTerm] = useState(filters.q);

    /*
     * وثلاثةُ أعمدةٍ على الحاسوب، وواحدٌ على الهاتف.
     *
     * `pane` يقول أيَّها يُعرض على الشاشة الصغيرة. وثلاثةُ أعمدةٍ مضغوطةٍ
     * في عرض هاتفٍ ليست ثلاثةَ أعمدة — هي ثلاثةُ أشرطةٍ لا يُقرأ منها شيء.
     */
    const [pane, setPane] = useState<'list' | 'thread' | 'details'>(active ? 'thread' : 'list');

    const go = (params: Record<string, string | number | null>) => {
        router.get(route('super-admin.conversations.index'), { ...filters, ...params } as never, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const openThread = (id: number) => {
        setPane('thread');
        go({ conversation: id });
    };

    const tabs = useMemo(
        () => [
            { key: 'all', label: t('الكل'), n: counts.all },
            { key: 'unread', label: t('غير مقروء'), n: counts.unread },
            { key: 'open', label: t('مفتوحة'), n: counts.open },
            { key: 'waiting_customer', label: t('بانتظار العميل'), n: counts.waiting_customer },
            { key: 'waiting_abaad', label: t('بانتظار فريق أبعاد'), n: counts.waiting_abaad },
            { key: 'resolved', label: t('تم الحل'), n: counts.resolved },
            { key: 'closed', label: t('مغلقة'), n: counts.closed },
        ],
        [counts, t],
    );

    return (
        <PlatformLayout title="مركز المحادثات">
            <div className="mb-4">
                <h1 className="text-[20px] font-bold text-[#111]">{t('مركز المحادثات')}</h1>
                <p className="mt-0.5 text-[13px] text-[#71717a]">
                    {t('محادثات الدعم بين أبعاد وأصحاب المتاجر')}
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,340px)_minmax(0,1fr)_minmax(0,320px)]">
                {/* ═══════════ العمود الأول: القائمة ═══════════ */}
                <section
                    className={cn(
                        'min-w-0 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white',
                        pane !== 'list' && 'hidden xl:block',
                    )}
                >
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] p-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-[#9ca3af]" />
                            <Input
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && go({ q: term })}
                                onBlur={() => term !== filters.q && go({ q: term })}
                                placeholder={t('بحث في المحادثات...')}
                                className="ps-9"
                                aria-label={t('بحث في المحادثات')}
                            />
                        </div>

                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => go({ status: tab.key, page: null })}
                                    aria-pressed={filters.status === tab.key}
                                    className={cn(
                                        'rounded-full px-2.5 py-1 text-[12px] font-medium transition-colors',
                                        filters.status === tab.key
                                            ? 'bg-[#111] text-white'
                                            : 'bg-[#f7f7f5] text-[#4b4b4b] hover:bg-[#efefed]',
                                    )}
                                >
                                    {tab.label}
                                    {tab.n > 0 && <span className="ms-1.5 tabular-nums opacity-70">{tab.n}</span>}
                                </button>
                            ))}
                        </div>

                        <div className="mt-2 flex gap-1.5">
                            {[
                                { key: 'all', label: t('الكل') },
                                { key: 'mine', label: t('محادثاتي') },
                                { key: 'unassigned', label: t('غير معيّنة') },
                            ].map((a) => (
                                <button
                                    key={a.key}
                                    type="button"
                                    onClick={() => go({ assignment: a.key, page: null })}
                                    aria-pressed={filters.assignment === a.key}
                                    className={cn(
                                        'rounded-[8px] px-2 py-1 text-[12px] transition-colors',
                                        filters.assignment === a.key
                                            ? 'bg-[#f5f3ff] font-medium text-[#5b21b6]'
                                            : 'text-[#71717a] hover:bg-[#fafafa]',
                                    )}
                                >
                                    {a.label}
                                </button>
                            ))}
                        </div>

                        {/*
                            والقنواتُ المرسومةُ هي العاملةُ وحدَها.
                            مُرشِّحٌ لقناةٍ لا تصلها رسالةٌ يقول إنّها موصولة.
                        */}
                        {channels.length > 1 && (
                            <div className="mt-2 flex gap-1.5">
                                {channels.map((c) => (
                                    <span key={c.value} className="text-[12px] text-[#9ca3af]">
                                        {c.label}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>

                    <ul className="max-h-[62dvh] divide-y divide-[var(--ui-border,#f0f0ef)] overflow-y-auto xl:max-h-[calc(100svh-19rem)]">
                        {conversations.data.length === 0 && (
                            <li className="px-4 py-14 text-center">
                                <Inbox className="mx-auto size-9 text-[#d4d4d8]" />
                                <p className="mt-3 text-[14px] font-medium text-[#4b4b4b]">
                                    {t('لا توجد محادثات حاليًا')}
                                </p>
                            </li>
                        )}

                        {conversations.data.map((c) => (
                            <li key={c.id}>
                                <button
                                    type="button"
                                    onClick={() => openThread(c.id)}
                                    aria-current={active?.id === c.id}
                                    className={cn(
                                        'flex w-full gap-3 p-3 text-start transition-colors hover:bg-[#fafafa]',
                                        active?.id === c.id &&
                                            'bg-[#f5f3ff] shadow-[inset_2px_0_0_#6d28d9] hover:bg-[#f5f3ff]',
                                    )}
                                >
                                    {/* ولا وجهَ يُختلق: الأحرفُ الأولى حين لا شعار */}
                                    <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#f4f4f5] text-[12px] font-bold text-[#71717a]">
                                        {c.businessLogo ? (
                                            <img src={c.businessLogo} alt="" className="size-full object-cover" />
                                        ) : (
                                            initials(c.business)
                                        )}
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-[14px] font-bold text-[#111]">
                                                {c.business}
                                            </span>
                                            <span className="ms-auto shrink-0 text-[11px] text-[#9ca3af]">
                                                {ago(c.lastMessageAt, t)}
                                            </span>
                                        </span>

                                        <span className="mt-0.5 block truncate text-[13px] text-[#4b4b4b]">
                                            {c.subject}
                                        </span>

                                        <span className="mt-1 flex items-center gap-1.5">
                                            <span
                                                className={cn(
                                                    'rounded-full px-1.5 py-0.5 text-[10px] font-medium',
                                                    statusTone(c.status),
                                                )}
                                            >
                                                {c.statusLabel}
                                            </span>
                                            {c.priority !== 'normal' && (
                                                <span className={cn('text-[10px] font-medium', priorityTone(c.priority))}>
                                                    {c.priorityLabel}
                                                </span>
                                            )}
                                            {c.assignee && (
                                                <span className="truncate text-[10px] text-[#9ca3af]">{c.assignee}</span>
                                            )}
                                            {c.unread > 0 && (
                                                <span className="ms-auto flex size-[18px] shrink-0 items-center justify-center rounded-full bg-[#6d28d9] text-[10px] font-bold tabular-nums text-white">
                                                    {c.unread}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>

                    {conversations.links.length > 3 && (
                        <nav className="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--ui-border,#e8e8e8)] p-2">
                            {conversations.links.map((l, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    disabled={!l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                    className={cn(
                                        'min-w-7 rounded-[6px] px-2 py-1 text-[12px]',
                                        l.active ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#fafafa]',
                                        !l.url && 'cursor-not-allowed opacity-40',
                                    )}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                        </nav>
                    )}
                </section>

                {/* ═══════════ العمود الثاني: المحادثة ═══════════ */}
                <section
                    className={cn(
                        'flex min-w-0 flex-col rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white',
                        pane !== 'thread' && 'hidden xl:flex',
                    )}
                >
                    {!active ? (
                        <div className="flex min-h-[40dvh] flex-col items-center justify-center p-10 text-center">
                            <Inbox className="size-10 text-[#d4d4d8]" />
                            <p className="mt-3 text-[14px] text-[#71717a]">
                                {t('اختر محادثة لعرض الرسائل')}
                            </p>
                        </div>
                    ) : (
                        <>
                            <Header active={active} onBack={() => setPane('list')} onDetails={() => setPane('details')} />
                            <Thread messages={messages} />
                            <Composer conversationId={active.id} maxFiles={maxFiles} maxKb={maxKb} extensions={extensions} />
                        </>
                    )}
                </section>

                {/* ═══════════ العمود الثالث: النشاط والتفاصيل ═══════════ */}
                <section
                    className={cn('min-w-0 space-y-3', pane !== 'details' && 'hidden xl:block')}
                >
                    {pane === 'details' && (
                        <Button variant="outline" size="sm" className="xl:hidden" onClick={() => setPane('thread')}>
                            <ArrowLeft className="rtl:rotate-180" />
                            {t('رجوع')}
                        </Button>
                    )}

                    {active ? (
                        <Details
                            active={active}
                            staff={staff}
                            statuses={statuses}
                            priorities={priorities}
                        />
                    ) : (
                        <div className="rounded-[16px] border border-dashed border-[var(--ui-border,#e8e8e8)] p-8 text-center text-[13px] text-[#9ca3af]">
                            {t('اختر محادثة لعرض الرسائل')}
                        </div>
                    )}
                </section>
            </div>
        </PlatformLayout>
    );
}

/* ═══════════════════ ترويسة المحادثة ═══════════════════ */

function Header({
    active,
    onBack,
    onDetails,
}: {
    active: Active;
    onBack: () => void;
    onDetails: () => void;
}) {
    const t = useTranslate();

    return (
        <header className="flex items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] p-3">
            <Button variant="outline" size="sm" className="xl:hidden" onClick={onBack} aria-label={t('رجوع')}>
                <ArrowLeft className="rtl:rotate-180" />
            </Button>

            <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#f4f4f5] text-[12px] font-bold text-[#71717a]">
                {active.business.logo ? (
                    <img src={active.business.logo} alt="" className="size-full object-cover" />
                ) : (
                    initials(active.business.name)
                )}
            </span>

            <div className="min-w-0 flex-1">
                <p className="truncate text-[15px] font-bold text-[#111]">{active.business.name}</p>
                <p className="truncate text-[12px] text-[#71717a]">
                    {active.subject}
                    <span className="mx-1.5 text-[#d4d4d8]">·</span>
                    <span dir="ltr">{active.reference}</span>
                </p>
            </div>

            <Badge className={cn('shrink-0 border-0', statusTone(active.status))}>{active.statusLabel}</Badge>

            <Button variant="outline" size="sm" className="xl:hidden" onClick={onDetails}>
                <Building2 />
            </Button>
        </header>
    );
}

/* ═══════════════════ الخيط ═══════════════════ */

function Thread({ messages }: { messages: Message[] }) {
    const t = useTranslate();

    if (messages.length === 0) {
        return (
            <div className="flex flex-1 items-center justify-center p-10 text-[13px] text-[#9ca3af]">
                {t('لا توجد رسائل بعد')}
            </div>
        );
    }

    return (
        <div className="max-h-[50dvh] flex-1 space-y-3 overflow-y-auto p-4 xl:max-h-[calc(100svh-24rem)]">
            {messages.map((m) =>
                m.event ? (
                    /* حدثُ النظام سطرٌ محايدٌ في التسلسل لا فقاعة */
                    <p key={m.id} className="text-center text-[11px] text-[#9ca3af]">
                        {m.eventText}
                    </p>
                ) : (
                    <Bubble key={m.id} m={m} />
                ),
            )}
        </div>
    );
}

function Bubble({ m }: { m: Message }) {
    const t = useTranslate();
    const mine = m.scope === 'platform';

    return (
        <div className={cn('flex', mine ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[80%] rounded-[14px] px-3.5 py-2.5',
                    m.internal
                        ? /* والملاحظةُ الداخليّة تُرى بشكلها قبل نصّها: لونٌ آخر وقفلٌ ظاهر */
                          'border border-dashed border-[#f59e0b] bg-[#fffbeb]'
                        : mine
                          ? 'bg-[#f5f3ff]'
                          : 'bg-[#f7f7f5]',
                )}
            >
                {m.internal && (
                    <p className="mb-1 flex items-center gap-1 text-[10px] font-bold text-[#b45309]">
                        <Lock className="size-3" />
                        {t('ملاحظة داخلية')}
                    </p>
                )}

                {m.body && (
                    <p className="whitespace-pre-wrap break-words text-[13px] leading-relaxed text-[#111]">
                        {m.body}
                    </p>
                )}

                {m.files.map((f) => (
                    <a
                        key={f.id}
                        href={f.url}
                        className="mt-2 flex items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white p-2 text-[12px] hover:bg-[#fafafa]"
                    >
                        <FileText className="size-4 shrink-0 text-[#6d28d9]" />
                        <span className="min-w-0 flex-1 truncate">{f.name}</span>
                        <span className="shrink-0 text-[11px] text-[#9ca3af]">{kb(f.size)}</span>
                    </a>
                ))}

                <p className="mt-1 text-[10px] text-[#9ca3af]">
                    {m.sender}
                    {m.at && (
                        <>
                            <span className="mx-1">·</span>
                            <span dir="ltr">{new Date(m.at).toLocaleString()}</span>
                        </>
                    )}
                </p>
            </div>
        </div>
    );
}

/* ═══════════════════ المُنشئ ═══════════════════ */

function Composer({
    conversationId,
    maxFiles,
    maxKb,
    extensions,
}: {
    conversationId: number;
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}) {
    const t = useTranslate();
    const [internal, setInternal] = useState(false);
    const picker = useRef<HTMLInputElement>(null);

    const form = useForm<{ body: string; internal: boolean; files: File[] }>({
        body: '',
        internal: false,
        files: [],
    });

    const send = () => {
        if (form.processing || form.data.body.trim() === '') return;

        form.transform((d) => ({ ...d, internal }));
        form.post(route('super-admin.conversations.reply', conversationId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset('body', 'files'),
        });
    };

    return (
        <div className="border-t border-[var(--ui-border,#e8e8e8)] p-3">
            {/*
                وضعان صريحان لا مفتاحٌ صغير.
                من يكتب ملاحظةً لفريقه ويضغط «إرسال» ظانًّا أنّها ملاحظة —
                يرسلها إلى التاجر. فالوضعُ يُقرأ قبل الكتابة لا بعدها.
            */}
            <div
                role="tablist"
                aria-label={t('وضع الإرسال')}
                className="mb-2 inline-flex overflow-hidden rounded-[10px] border border-[var(--ui-border,#e8e8e8)]"
            >
                {[
                    { key: false, label: t('رد للعميل') },
                    { key: true, label: t('ملاحظة داخلية') },
                ].map((mode) => (
                    <button
                        key={String(mode.key)}
                        type="button"
                        role="tab"
                        aria-selected={internal === mode.key}
                        onClick={() => setInternal(mode.key)}
                        className={cn(
                            'px-3 py-1.5 text-[12px] font-medium transition-colors',
                            internal === mode.key
                                ? mode.key
                                    ? 'bg-[#fffbeb] text-[#b45309]'
                                    : 'bg-[#111] text-white'
                                : 'bg-white text-[#4b4b4b] hover:bg-[#fafafa]',
                        )}
                    >
                        {mode.key && <Lock className="me-1 inline size-3" />}
                        {mode.label}
                    </button>
                ))}
            </div>

            {internal && (
                <p className="mb-2 text-[11px] text-[#b45309]">
                    {t('لا تظهر لصاحب المتجر ولا تُرسل إليه إشعارًا.')}
                </p>
            )}

            <div
                className={cn(
                    'rounded-[12px] border p-2',
                    internal ? 'border-[#f59e0b] bg-[#fffbeb]' : 'border-[var(--ui-border,#e8e8e8)]',
                )}
            >
                <textarea
                    rows={3}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    placeholder={internal ? t('لفريقك وحده…') : t('اكتب رسالتك هنا…')}
                    className="w-full resize-none border-0 bg-transparent p-1.5 text-[13px] outline-none placeholder:text-[#9ca3af]"
                />

                {form.data.files.length > 0 && (
                    <ul className="mb-2 space-y-1">
                        {form.data.files.map((f, i) => (
                            <li
                                key={i}
                                className="flex items-center gap-2 rounded-[8px] bg-white p-1.5 text-[12px]"
                            >
                                <Paperclip className="size-3.5 shrink-0 text-[#9ca3af]" />
                                <span className="min-w-0 flex-1 truncate">{f.name}</span>
                                <button
                                    type="button"
                                    aria-label={t('إزالة')}
                                    onClick={() =>
                                        form.setData(
                                            'files',
                                            form.data.files.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <X className="size-3.5 text-[#9ca3af] hover:text-[#b91c1c]" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {form.errors.body && <p className="text-[12px] text-[#b91c1c]">{form.errors.body}</p>}
                {form.errors.files && <p className="text-[12px] text-[#b91c1c]">{form.errors.files}</p>}

                <div className="flex items-center gap-2">
                    <input
                        ref={picker}
                        type="file"
                        multiple
                        className="hidden"
                        accept={extensions.map((e) => `.${e}`).join(',')}
                        onChange={(e) =>
                            form.setData(
                                'files',
                                [...form.data.files, ...Array.from(e.target.files ?? [])].slice(0, maxFiles),
                            )
                        }
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => picker.current?.click()}
                        disabled={form.data.files.length >= maxFiles}
                        title={t('حتى :n ملفات، :kb ميغابايت للملف', {
                            n: maxFiles,
                            kb: Math.round(maxKb / 1024),
                        })}
                    >
                        <Paperclip />
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        className="ms-auto"
                        onClick={send}
                        /* والضغطتان أمرٌ واحد: `processing` يُقفل الزرّ حتّى يعود الردّ */
                        disabled={form.processing || form.data.body.trim() === ''}
                    >
                        <Send />
                        {t('إرسال')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/* ═══════════════════ بطاقتا النشاط والتفاصيل ═══════════════════ */

function Details({
    active,
    staff,
    statuses,
    priorities,
}: {
    active: Active;
    staff: { id: number; name: string }[];
    statuses: Option[];
    priorities: Option[];
}) {
    const t = useTranslate();

    const post = (name: string, data: Record<string, string | number | null>) =>
        router.post(route(name, active.id), data as never, { preserveScroll: true, preserveState: true });

    return (
        <>
            <div className="rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4">
                <h2 className="mb-3 text-[14px] font-bold text-[#111]">{t('معلومات النشاط')}</h2>

                <div className="flex items-center gap-3">
                    <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#f4f4f5] text-[13px] font-bold text-[#71717a]">
                        {active.business.logo ? (
                            <img src={active.business.logo} alt="" className="size-full object-cover" />
                        ) : (
                            initials(active.business.name)
                        )}
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-[14px] font-bold text-[#111]">{active.business.name}</p>
                        {active.business.status && (
                            <Badge className="mt-1 border-0 bg-[#ecfdf5] text-[11px] text-[#047857]">
                                {t(active.business.status)}
                            </Badge>
                        )}
                    </div>
                </div>

                <dl className="mt-3 space-y-2 text-[12px]">
                    <Line label={t('فتحها')} value={active.business.owner} />
                    <Line label={t('البريد الإلكتروني')} value={active.business.email} ltr />
                    <Line label={t('رقم التواصل')} value={active.business.phone} ltr />
                </dl>

                {active.business.url && (
                    <Button variant="outline" size="sm" className="mt-3 w-full" asChild>
                        <a href={active.business.url}>
                            <ExternalLink />
                            {t('فتح صفحة النشاط')}
                        </a>
                    </Button>
                )}
            </div>

            <div className="rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4">
                <h2 className="mb-3 text-[14px] font-bold text-[#111]">{t('تفاصيل المحادثة')}</h2>

                <dl className="space-y-3 text-[12px]">
                    <div>
                        <dt className="mb-1 text-[#71717a]">{t('الحالة')}</dt>
                        <dd>
                            <select
                                value={active.status}
                                onChange={(e) => post('super-admin.conversations.status', { status: e.target.value })}
                                aria-label={t('الحالة')}
                                className="w-full rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-2 py-1.5 text-[12px]"
                            >
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </dd>
                    </div>

                    <Line label={t('القناة')} value={active.channelLabel} />
                    <Line label={t('التصنيف')} value={active.category} />

                    <div>
                        <dt className="mb-1 text-[#71717a]">{t('المسؤول')}</dt>
                        <dd>
                            <select
                                value={active.assigneeId ?? ''}
                                onChange={(e) =>
                                    post('super-admin.conversations.assign', {
                                        user_id: e.target.value === '' ? null : Number(e.target.value),
                                    })
                                }
                                aria-label={t('المسؤول')}
                                className="w-full rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-2 py-1.5 text-[12px]"
                            >
                                <option value="">{t('غير معيّن')}</option>
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </dd>
                    </div>

                    <div>
                        <dt className="mb-1 text-[#71717a]">{t('الأولوية')}</dt>
                        <dd>
                            <select
                                value={active.priority}
                                onChange={(e) =>
                                    post('super-admin.conversations.priority', { priority: e.target.value })
                                }
                                aria-label={t('الأولوية')}
                                className="w-full rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-2 py-1.5 text-[12px]"
                            >
                                {priorities.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </dd>
                    </div>

                    <Line label={t('رقم المحادثة')} value={active.reference} ltr />
                </dl>

                <div className="mt-4 grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => post('super-admin.conversations.status', { status: 'resolved' })}
                        disabled={active.status === 'resolved'}
                    >
                        {t('تم الحل')}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            post('super-admin.conversations.status', {
                                status: active.status === 'closed' ? 'open' : 'closed',
                            })
                        }
                    >
                        {active.status === 'closed' ? t('إعادة فتح') : t('إغلاق المحادثة')}
                    </Button>
                </div>
            </div>
        </>
    );
}

function Line({ label, value, ltr }: { label: string; value: string | null; ltr?: boolean }) {
    if (!value) return null;

    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="shrink-0 text-[#71717a]">{label}</dt>
            <dd className="min-w-0 truncate text-[#111]" dir={ltr ? 'ltr' : undefined}>
                {value}
            </dd>
        </div>
    );
}
