import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import {
    AlertTriangle,
    ArrowLeftRight,
    Building2,
    CheckCircle2,
    ExternalLink,
    Hash,
    LifeBuoy,
    Lock,
    Mail,
    Phone,
    Tag,
    User,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { useConfirm } from '@/Components/ConfirmDialog';
import { MessageComposer } from '@/Components/conversations/Composer';
import {
    ConversationDetailsPanel,
    DetailFiles,
    DetailIdentity,
    DetailLine,
    DetailSection,
    DetailSelect,
} from '@/Components/conversations/Details';
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
    PageLinks,
    Pill,
} from '@/Components/conversations/Shell';
import {
    ConversationHeader,
    ConversationThread,
    MessageBubble,
    SystemRow,
} from '@/Components/conversations/Thread';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

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
    /* حالُ الخروج إلى واتساب — وفارغةٌ في المحادثات التي لا تخرج أصلًا */
    delivery: 'sent' | 'partial' | 'failed' | 'blocked' | null;
    deliveryLabel: string | null;
    deliveryError: string | null;
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
    channel: string;
    channelLabel: string;
    whatsappWindowOpen: boolean;
    whatsappWindowEndsAt: string | null;
    whatsappLine: boolean | null;
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

/**
 * ساعةُ الرسالة في الخيط — واليومُ يُقال في فاصلٍ فوقها لا في كلّ سطر.
 *
 * وبلغة الواجهة لا بلغة المتصفّح: لوحةٌ عربيّةٌ تقول «الاثنين ٢١ سبتمبر» لا
 * «Monday, September 21». والأرقامُ لاتينيّةٌ في الحالين (`nu-latn`) كما في
 * بقيّة اللوحة.
 */
const tag = (locale: string) => (locale === 'en' ? 'en-u-nu-latn' : 'ar-u-nu-latn');

const clock = (iso: string | null, locale: string) =>
    iso ? new Date(iso).toLocaleTimeString(tag(locale), { hour: '2-digit', minute: '2-digit' }) : null;

const day = (iso: string | null) => (iso ? new Date(iso).toDateString() : '');

const dayLabel = (iso: string | null, locale: string) =>
    iso ? new Date(iso).toLocaleDateString(tag(locale), { weekday: 'long', day: 'numeric', month: 'long' }) : '';

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
        low: 'bg-[#f4f4f5] text-[#71717a]',
        normal: 'bg-[#f4f4f5] text-[#52525b]',
        high: 'bg-[#fff7ed] text-[#c2410c]',
        urgent: 'bg-[#fef2f2] text-[#b91c1c]',
    })[priority] ?? 'bg-[#f4f4f5] text-[#52525b]';

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
    const [pane, setPane] = useState<Pane>(active ? 'thread' : 'list');

    /*
     * والمحادثةُ المفتوحة تبقى مفتوحةً وأنت ترشّح أو تبحث.
     *
     * كانت المرشِّحاتُ تُرسَل بلا `conversation`، فمن يقرأ خيطًا ويضيّق
     * القائمةَ بجانبه يجد الخيطَ قد أُغلق — والبحثُ عن محادثةٍ أخرى ليس
     * طلبًا لإغلاق هذه.
     */
    const go = (params: Record<string, string | number | null>) => {
        router.get(route('super-admin.conversations.index'), { ...filters, conversation: active?.id ?? null, ...params } as never, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    /*
     * والبحثُ يُرسَل مرّةً عن الكلمة الواحدة.
     *
     * الحقل يبحث بـEnter وبمغادرته. وصارت Enter على الأجهزة اللمسية تُنهي
     * التركيز لتُغلق لوحةَ المفاتيح (انظر `lib/enter-key`)، فتمرّ الضغطةُ
     * بالبابين. وحارسُ المغادرة `term !== filters.q` لا يمنع: `filters` تأتي
     * من الخادم ولم تكن قد وصلت بعد — فيُرسَل طلبان عن بحثٍ واحد.
     */
    const asked = useRef<string | null>(null);

    const search = (q: string) => {
        if (asked.current === q) {
            return;
        }

        asked.current = q;
        go({ q });
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

    const assignments = useMemo(
        () => [
            { key: 'all', label: t('الكل') },
            { key: 'mine', label: t('محادثاتي') },
            { key: 'unassigned', label: t('غير معيّنة') },
        ],
        [t],
    );

    return (
        <PlatformLayout title="مركز المحادثات">
            <ConversationPage>
            <ConversationPageHeader
                title={t('المحادثات')}
                context={t('الدعم')}
                subtitle={t('محادثات الدعم بين أبعاد وأصحاب المتاجر')}
                tone="green"
            />

            <ConversationShell
                pane={pane}
                list={
                    <ConversationList
                        header={
                            <div className="space-y-2">
                                <ConversationSearch
                                    value={term}
                                    onChange={setTerm}
                                    onSubmit={() => search(term)}
                                    onBlur={() => term !== filters.q && search(term)}
                                    placeholder={t('بحث في المحادثات...')}
                                    label={t('بحث في المحادثات')}
                                />
                                <FilterChips items={tabs} value={filters.status} onChange={(status) => go({ status, page: null })} label={t('الحالة')} />
                                <FilterChips
                                    items={assignments}
                                    value={filters.assignment}
                                    onChange={(assignment) => go({ assignment, page: null })}
                                    label={t('المسؤول')}
                                    size="sm"
                                />

                                {/*
                                    والقنواتُ المرسومةُ هي العاملةُ وحدَها.
                                    مُرشِّحٌ لقناةٍ لا تصلها رسالةٌ يقول إنّها موصولة.
                                */}
                                {channels.length > 1 && (
                                    <div className="flex gap-1.5">
                                        {channels.map((c) => (
                                            <span key={c.value} className="text-[11px] text-[#9ca3af]">
                                                {c.label}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        }
                        footer={
                            <PageLinks
                                links={conversations.links}
                                onGo={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
                            />
                        }
                    >
                        {conversations.data.length === 0 && (
                            <li>
                                <ConversationEmptyState text={t('لا توجد محادثات حاليًا')} />
                            </li>
                        )}

                        {conversations.data.map((c) => (
                            <ConversationListItem
                                key={c.id}
                                active={active?.id === c.id}
                                onClick={() => openThread(c.id)}
                                avatar={<Avatar name={c.business} src={c.businessLogo} />}
                                title={c.business}
                                subtitle={c.subject}
                                preview={c.preview}
                                time={ago(c.lastMessageAt, t)}
                                unread={c.unread}
                                badges={
                                    <>
                                        <Pill className={statusTone(c.status)}>{c.statusLabel}</Pill>
                                        {c.priority !== 'normal' && (
                                            <Pill className={priorityTone(c.priority)}>{c.priorityLabel}</Pill>
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
                        <ConversationEmptyState text={t('اختر محادثة لعرض الرسائل')} icon={<LifeBuoy className="size-6" />} />
                    ) : (
                        <>
                            <ConversationHeader
                                avatar={<Avatar name={active.business.name} src={active.business.logo} size="sm" />}
                                title={active.business.name}
                                subtitle={
                                    <>
                                        {active.subject}
                                        <span className="mx-1.5 text-[#d4d4d8]">·</span>
                                        <span dir="ltr">{active.reference}</span>
                                    </>
                                }
                                badges={
                                    <>
                                        <Pill className="bg-[#f4f4f5] text-[#52525b]">{active.channelLabel}</Pill>
                                        <Pill className={statusTone(active.status)}>{active.statusLabel}</Pill>
                                    </>
                                }
                                onBack={() => setPane('list')}
                                onDetails={() => setPane('details')}
                            />
                            <Thread active={active} messages={messages} />
                            <Composer active={active} maxFiles={maxFiles} maxKb={maxKb} extensions={extensions} />
                        </>
                    )
                }
                details={
                    active ? (
                        <Details
                            active={active}
                            files={messages.flatMap((m) => m.files.map((f) => ({ ...f, image: f.isImage })))}
                            staff={staff}
                            statuses={statuses}
                            priorities={priorities}
                            onBack={() => setPane('thread')}
                        />
                    ) : (
                        <ConversationEmptyState text={t('اختر محادثة لعرض الرسائل')} icon={<Building2 className="size-6" />} />
                    )
                }
            />
            </ConversationPage>
        </PlatformLayout>
    );
}

/* ═══════════════════ الخيط ═══════════════════ */

function Thread({ active, messages }: { active: Active; messages: Message[] }) {
    const t = useTranslate();
    const { locale } = usePage<PageProps>().props;

    if (messages.length === 0) {
        return <ConversationEmptyState text={t('لا توجد رسائل بعد')} />;
    }

    return (
        <ConversationThread threadKey={active.id} count={messages.length}>
            {messages.map((m, i) => (
                <div key={m.id} className="space-y-2">
                    {/* فاصلُ اليوم مرّةً فوق أوّل رسائله */}
                    {(i === 0 || day(m.at) !== day(messages[i - 1].at)) && m.at && (
                        <SystemRow>{dayLabel(m.at, locale)}</SystemRow>
                    )}

                    {m.event ? (
                        /* حدثُ النظام سطرٌ محايدٌ في التسلسل لا فقاعة */
                        <SystemRow>{m.eventText}</SystemRow>
                    ) : (
                        <MessageBubble
                            /*
                                والمعنى يبقى معنى الدعم: `platform` منّا، و`business`
                                من صاحب المتجر، و`internal` لا يبلغه أبدًا — والفقاعةُ
                                لا تعرف إلا جهةً ونبرة.
                            */
                            side={m.scope === 'platform' ? 'out' : 'in'}
                            avatar={<Avatar name={active.business.name} src={active.business.logo} size="sm" />}
                            tone={m.internal ? 'internal' : 'default'}
                            internalLabel={t('ملاحظة داخلية')}
                            body={m.body}
                            sender={m.sender}
                            time={clock(m.at, locale)}
                            files={m.files.map((f) => ({ ...f, image: f.isImage }))}
                            footer={
                                /*
                                    وحالُ الخروج تُقرأ تحت الرسالة نفسِها.
                                    ردٌّ حُفظ ولم يخرج إلى هاتف التاجر هو ردٌّ لم يصل — وصمتُ
                                    الشاشة عنه يجعل الدعمَ ينتظر جوابًا على كلامٍ لم يقرأه أحد.
                                */
                                m.deliveryLabel ? (
                                    <p
                                        className={cn(
                                            'mt-1 flex items-start gap-1 text-[10.5px]',
                                            m.delivery === 'sent'
                                                ? 'text-[#15803d]'
                                                : /* وبعضُها خرج: لا أخضرَ يقول «وصل كلُّه» ولا أحمرَ يقول «لم يصل شيء» */
                                                  m.delivery === 'partial'
                                                  ? 'text-[#b45309]'
                                                  : 'text-[#b91c1c]',
                                        )}
                                    >
                                        {m.delivery === 'sent' ? (
                                            <CheckCircle2 className="mt-px size-3 shrink-0" />
                                        ) : (
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
                    )}
                </div>
            ))}
        </ConversationThread>
    );
}

/* ═══════════════════ المُنشئ ═══════════════════ */

function Composer({
    active,
    maxFiles,
    maxKb,
    extensions,
}: {
    active: Active;
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}) {
    const t = useTranslate();
    const [internal, setInternal] = useState(false);
    const conversationId = active.id;

    /*
        ونافذةُ واتساب تُقال قبل الكتابة لا بعد المنع.

        ميتا لا تُمرّر نصًّا حرًّا بعد أربعٍ وعشرين ساعةً من آخر رسالةٍ وصلت
        من التاجر. ومن يكتب ردًّا في محادثةٍ أُغلقت نافذتُها يستحقّ أن يعرف
        وهو يكتب: سيُحفظ الردُّ ولن يخرج إلى هاتفه.
    */
    const wa = active.channel === 'whatsapp' && !internal;
    const waBlocked = wa && (!active.whatsappLine || !active.whatsappWindowOpen);

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
        <MessageComposer
            value={form.data.body}
            onChange={(body) => form.setData('body', body)}
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
            placeholder={internal ? t('لفريقك وحده…') : t('اكتب رسالتك هنا…')}
            tone={internal ? 'internal' : 'default'}
            errors={{ body: form.errors.body, files: form.errors.files }}
            above={
                <div className="mb-2 flex flex-wrap items-center gap-2">
                    {/*
                        وضعان صريحان لا مفتاحٌ صغير.
                        من يكتب ملاحظةً لفريقه ويضغط «إرسال» ظانًّا أنّها ملاحظة —
                        يرسلها إلى التاجر. فالوضعُ يُقرأ قبل الكتابة لا بعدها.
                    */}
                    <div
                        role="tablist"
                        aria-label={t('وضع الإرسال')}
                        className="inline-flex overflow-hidden rounded-[9px] border border-[var(--ui-border,#e8e8e8)]"
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
                                    'px-2.5 py-1 text-[12px] font-medium transition-colors',
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
                        <p className="text-[11px] text-[#b45309]">
                            {t('لا تظهر لصاحب المتجر ولا تُرسل إليه إشعارًا، ولا تخرج إلى واتساب.')}
                        </p>
                    )}

                    {wa && (
                        <p
                            className={cn(
                                'flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px]',
                                waBlocked ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#f0fdf4] text-[#166534]',
                            )}
                        >
                            {waBlocked ? (
                                <AlertTriangle className="size-3.5 shrink-0" />
                            ) : (
                                <CheckCircle2 className="size-3.5 shrink-0" />
                            )}
                            <span>
                                {!active.whatsappLine
                                    ? t('خطُّ دعم واتساب غير موصول — سيُحفظ ردّك هنا ولن يخرج.')
                                    : active.whatsappWindowOpen
                                      ? t('يخرج ردّك إلى واتساب. تُغلق النافذة :at', {
                                            at: active.whatsappWindowEndsAt
                                                ? new Date(active.whatsappWindowEndsAt).toLocaleString()
                                                : '—',
                                        })
                                      : t('نافذة واتساب مغلقة — سيُحفظ ردّك هنا ولن يخرج حتى يكتب التاجر من جديد.')}
                            </span>
                        </p>
                    )}
                </div>
            }
        />
    );
}

/* ═══════════════════ التفاصيل ═══════════════════ */

function Details({
    active,
    files,
    staff,
    statuses,
    priorities,
    onBack,
}: {
    active: Active;
    /* ما مرّ في الخيط من مرفقات — مشتقٌّ من الرسائل نفسِها، لا جدولَ آخر */
    files: { id: number; name: string; size: number; image: boolean; url: string }[];
    staff: { id: number; name: string }[];
    statuses: Option[];
    priorities: Option[];
    onBack: () => void;
}) {
    const t = useTranslate();
    const [confirm, confirmDialog] = useConfirm();

    const post = (name: string, data: Record<string, string | number | null>) =>
        router.post(route(name, active.id), data as never, { preserveScroll: true, preserveState: true });

    /*
        ═══ ونقلُ خيطٍ طرق البابَ الخطأ ═══

        الواردُ يُوزَّع بـ«من كتب» لا بـ«ما كتب» — وهو الفاصلُ الذي يمنع أن
        تصير زبونةُ محلِّ ورودٍ صفًّا في دفتر مبيعاتنا. وهو لا يقرأ النيّة:
        تاجرٌ مسجَّلٌ عندنا يسأل عن سعر الباقة الأكبر يصل إلى هنا.

        فالموزِّعُ يبقى كما هو، والحالةُ النادرة تُصحَّح بضغطةٍ — بيد إنسانٍ
        وبعد تأكيد، وبالمسار القائم نفسِه (`ConversationHandover`).

        ولا يُعرض الزرُّ على خيطٍ فُتح من داخل اللوحة: لا رقمَ له ولا نافذةَ
        ردّ، ونقلُه يفتح عميلًا لا نملك أن نكتب إليه حرفًا.
    */
    const handover = async () => {
        const ok = await confirm({
            title: t('نقل إلى CRM'),
            message: t('سيتم نقل المحادثة إلى مسار المبيعات وإغلاق محادثة الدعم مع الاحتفاظ بالسجل.'),
            action: t('نقل إلى CRM'),
        });

        if (ok) post('super-admin.conversations.toCrm', {});
    };

    return (
        <ConversationDetailsPanel title={t('التفاصيل')} onBack={onBack}>
            {confirmDialog}

            <DetailIdentity
                avatar={<Avatar name={active.business.name} src={active.business.logo} size="xl" />}
                name={active.business.name}
                subtitle={<span dir="ltr">{active.reference}</span>}
            >
                <Pill className={statusTone(active.status)}>{active.statusLabel}</Pill>
                {active.business.status && <Pill className="bg-[#ecfdf5] text-[#047857]">{t(active.business.status)}</Pill>}
            </DetailIdentity>

            <DetailSection title={t('معلومات النشاط')}>
                <dl>
                    <DetailLine label={t('فتحها')} value={active.business.owner} icon={<User className="size-3.5" />} />
                    <DetailLine label={t('البريد الإلكتروني')} value={active.business.email} ltr icon={<Mail className="size-3.5" />} />
                    <DetailLine label={t('رقم التواصل')} value={active.business.phone} ltr icon={<Phone className="size-3.5" />} />
                </dl>

                {active.business.url && (
                    <Button variant="outline" size="sm" className="mt-3 w-full" asChild>
                        <a href={active.business.url}>
                            <ExternalLink />
                            {t('فتح صفحة النشاط')}
                        </a>
                    </Button>
                )}
            </DetailSection>

            <DetailSection title={t('المحادثة')}>
                <dl>
                    <DetailLine label={t('رقم المحادثة')} value={active.reference} ltr icon={<Hash className="size-3.5" />} />
                    <DetailLine label={t('القناة')} value={active.channelLabel} />
                    <DetailLine label={t('التصنيف')} value={active.category} icon={<Tag className="size-3.5" />} />
                </dl>
            </DetailSection>

            <DetailSection title={t('الإدارة')}>
                <div className="space-y-2.5">
                    <DetailSelect
                        label={t('الحالة')}
                        value={active.status}
                        onChange={(status) => post('super-admin.conversations.status', { status })}
                        options={statuses}
                    />
                    <DetailSelect
                        label={t('المسؤول')}
                        value={active.assigneeId === null ? '' : String(active.assigneeId)}
                        onChange={(v) =>
                            post('super-admin.conversations.assign', { user_id: v === '' ? null : Number(v) })
                        }
                        options={[
                            { value: '', label: t('غير معيّن') },
                            ...staff.map((s) => ({ value: String(s.id), label: s.name })),
                        ]}
                    />
                    <DetailSelect
                        label={t('الأولوية')}
                        value={active.priority}
                        onChange={(priority) => post('super-admin.conversations.priority', { priority })}
                        options={priorities}
                    />
                </div>

                <div className="mt-3 grid grid-cols-2 gap-2">
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
            </DetailSection>

            <DetailFiles title={t('الملفات')} files={files} />

            {active.channel === 'whatsapp' && (
                <DetailSection title={t('ليست دعمًا؟')}>
                    <p className="mb-2 text-[11.5px] text-[#71717a]">
                        {t('سيتم نقل المحادثة إلى مسار المبيعات وإغلاق محادثة الدعم مع الاحتفاظ بالسجل.')}
                    </p>
                    <Button variant="outline" size="sm" className="w-full" onClick={handover}>
                        <ArrowLeftRight />
                        {t('نقل إلى CRM')}
                    </Button>
                </DetailSection>
            )}
        </ConversationDetailsPanel>
    );
}
