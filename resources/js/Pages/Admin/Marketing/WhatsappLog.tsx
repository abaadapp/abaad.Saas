import { Link, usePage } from '@inertiajs/react';
import { MessageCircle, MessagesSquare, SlidersHorizontal } from 'lucide-react';
import { WhatsAppBusinessMark } from '@/Components/BrandMarks';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ConnectSteps, type Readiness } from '@/Components/Connect';
import type { PageProps } from '@/types';

/** صفٌّ من الدفتر — انظر `App\Support\WhatsAppLog::row` */
export interface Row {
    id: number;
    /** مفتاحٌ لا يتكرّر بين المصدرين — `n12` إشعار، `r5` ردّ */
    key: string;
    /** إشعارٌ أرسله النظام، أو ردُّ موظّفٍ من المحادثات */
    kind: 'notice' | 'reply';
    /** من ردّ — كما كُتب اسمُه ساعتَها */
    sender: string | null;
    at: string | null;
    event: string;
    phone: string | null;
    /** اسمُ العميل في هذا المتجر — إن سمّته الرسالة */
    customer: string | null;
    /** النصُّ كما قرأه الزبون — إن حُفظ */
    body: string | null;
    /** وإلّا وصفٌ: الحدثُ والورقة — لا يُعرض بشكل النصّ */
    summary: string | null;
    source: 'business_own' | 'abaad_shared';
    source_label: string;
    /** متى خرجت ووصلت وقُرئت أو فشلت — ما وقع منها */
    times: Partial<Record<'sent' | 'delivered' | 'read' | 'failed', string>>;
    /** محادثتُه في الصندوق — لمن يراه، ولما خرج إلى واتساب */
    open: string | null;
    status: string;
    status_label: string;
    /** سببُ الامتناع بلغةٍ تُقرأ — أو لا شيء لأخطاء ميتا */
    reason: string | null;
    /** نصُّ ميتا كما ردّته — للفاشلة وحدها */
    error: string | null;
    /** أعلى أبعادَ إصلاحُه — `null` لما لم يفشل */
    ours: boolean | null;
    subject: { label: string; url: string } | null;
}

interface Props {
    readiness: Readiness;
    /** في الدفتر صادرٌ قديم — فلا يُخفى خلف «اربط أوّلًا» إن انقطع الربط */
    hasHistory: boolean;
    rows: Row[];
    pagination: ServerPagination;
    summary: Record<string, number>;
    summaryDays: number;
    buckets: { key: string; label: string }[];
    sources: { key: string; label: string }[];
    kinds: { key: string; label: string }[];
    params: { filter: string; source: string; kind: string; q: string };
}

const TIME_LABELS: Record<string, string> = {
    sent: 'خرجت',
    delivered: 'وصلت',
    read: 'قُرئت',
    failed: 'فشلت',
};

/**
 * لونُ الحال — ولا لونَ ثالثٌ بين «وصلت» و«لم تصل».
 *
 * و«خرجت إلى واتساب» رماديّة لا خضراء: الخضرةُ تُقرأ «تمّ»، وهي لم تتمّ بعد
 * — قبِلتها ميتا ولم تصل جهازًا. وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب.
 */
const TONE: Record<string, string> = {
    queued: 'bg-[#f3f4f6] text-[#4b5563]',
    sent: 'bg-[#f3f4f6] text-[#4b5563]',
    delivered: 'bg-[#dcfce7] text-[#166534]',
    read: 'bg-[#dcfce7] text-[#166534]',
    failed: 'bg-[#fee2e2] text-[#b91c1c]',
    skipped: 'bg-[#fef3c7] text-[#92400e]',
    quota_exceeded: 'bg-[#fef3c7] text-[#92400e]',
};

/**
 * سجلُّ رسائل واتساب — أيُّ رسالةٍ خرجت، ولمن، وماذا جرى لها.
 *
 * ═══ ولمَ وُجدت هذه الشاشة ═══
 *
 * الجدولُ قائمٌ منذ أوّل يوم ولم تفتحه شاشة. فكان جرسُ اللوحة يقول «لم تصل
 * ٣ رسائل إلى زبائنك» ويقود إلى شاشة الربط — وهي لا تعرف أيَّ الثلاث ولا
 * لمن ولا لماذا. فيُقال للتاجر إنّ شيئًا انكسر ولا يُقال ماذا.
 *
 * وهي قراءةٌ محضة: لا إعادةَ إرسالٍ ولا حذف. زرُّ «أعِد الإرسال» يحتاج
 * حصّةً تُحجز ومنعَ تكرارٍ يُفتح، وكلاهما قرارٌ لم يُتّخذ — وزرٌّ لا يُدير
 * شيئًا أسوأ من غياب الزرّ.
 */
export default function WhatsappLog() {
    const { readiness, hasHistory, rows, pagination, summary, summaryDays, buckets, sources, kinds, params } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * والبابُ لمن لا دفترَ له أصلًا — لا لمن انقطع ربطُه.
     *
     * رقمٌ فُكّ أو انتهت صلاحيتُه لا يمحو ما خرج قبله: السجلُّ يُقرأ، وما
     * ينقص الربطَ يُقال فوقه بخطواته.
     */
    if (! readiness.connected && ! hasHistory) {
        return (
            <AdminLayout title="سجلّ رسائل واتساب">
                <PageHeader title="سجلّ رسائل واتساب" subtitle={t('ما خرج فعلًا إلى زبائنك — وماذا جرى له')} />
                <Gate
                    mark={<WhatsAppBusinessMark size={80} />}
                    title="واتساب غير مربوط بعد"
                    description="لا دفترَ قبل أوّل رسالة — والربط أوّلًا."
                    action={
                        <Button asChild size="lg">
                            <Link href={route('admin.integrations.whatsapp')}>{t('اذهب إلى ربط واتساب')}</Link>
                        </Button>
                    }
                />
            </AdminLayout>
        );
    }

    const columns: Column<Row>[] = [
        {
            key: 'at',
            header: 'التاريخ',
            cell: (r) => <span className="whitespace-nowrap text-[13px] text-[#6b7280]" dir="ltr">{r.at ?? '—'}</span>,
        },
        {
            key: 'customer',
            header: 'العميل',
            cell: (r) => (
                <div className="text-[13px]">
                    {r.customer && <p className="font-medium text-[#111]">{r.customer}</p>}
                    <p dir="ltr" className={cn('text-start', r.customer ? 'text-[12px] text-[#6b7280]' : '')}>{r.phone ?? '—'}</p>
                </div>
            ),
        },
        {
            key: 'message',
            header: 'الرسالة',
            cell: (r) => (
                <div className="max-w-[360px] space-y-1">
                    <p className="text-[12px] font-medium text-[#6b7280]" data-testid="log-event">
                        {r.event}
                        {r.sender && <span data-testid="log-sender"> · {r.sender}</span>}
                    </p>
                    {/* النصُّ كما قرأه الزبون — أو وصفٌ بغير شكل النصّ، لا نصٌّ مخترَع */}
                    {r.body ? (
                        <p className="line-clamp-3 whitespace-pre-wrap text-[13px] leading-relaxed text-[#111]" data-testid="log-body">{r.body}</p>
                    ) : r.summary ? (
                        <p className="text-[12px] italic text-[#6b7280]" data-testid="log-summary">{r.summary}</p>
                    ) : null}
                    <p className="text-[11px] text-[#9ca3af]" data-testid="log-source">{r.source_label}</p>
                </div>
            ),
        },
        {
            key: 'subject',
            header: 'الطلب أو الفاتورة',
            cell: (r) =>
                r.subject ? (
                    <Link href={r.subject.url} className="text-[13px] text-[#6d28d9] hover:underline">
                        {r.subject.label}
                    </Link>
                ) : (
                    <span className="text-[13px] text-[#9ca3af]">—</span>
                ),
        },
        {
            key: 'status',
            header: 'ماذا جرى',
            cell: (r) => (
                <div className="space-y-1">
                    <span
                        className={cn(
                            'inline-block rounded-full px-2.5 py-0.5 text-[12px] font-bold',
                            TONE[r.status] ?? 'bg-[#f3f4f6] text-[#4b5563]',
                        )}
                    >
                        {r.status_label}
                    </span>

                    {Object.entries(r.times).length > 0 && (
                        <p className="text-[11px] text-[#9ca3af]" data-testid="log-times">
                            {Object.entries(r.times).map(([k, v]) => (
                                <span key={k} className="me-2 inline-block">
                                    {t(TIME_LABELS[k] ?? k)} <span dir="ltr">{v}</span>
                                </span>
                            ))}
                        </p>
                    )}

                    {/* السببُ يُقرأ ولا يُخمَّن — ونصُّ ميتا يُعرض كما ردّته */}
                    {r.reason && <p className="max-w-[420px] text-[12px] leading-relaxed text-[#6b7280]">{r.reason}</p>}

                    {r.error && (
                        <p className="max-w-[420px] text-[12px] leading-relaxed text-[#6b7280]">
                            {/*
                                والعطبُ يُنسب إلى صاحبه: «راجع رقم زبونك»
                                لتاجرٍ تطبيقُنا محجوب لومٌ في غير محلّه،
                                ويجعله يلاحق ما لا يملك إصلاحه.
                            */}
                            <span className={r.ours ? 'font-bold text-[#92400e]' : 'font-bold text-[#b91c1c]'}>
                                {r.ours ? t('العطب عند أبعاد — ونحن نعالجه.') : t('راجِع رقم الزبون.')}
                            </span>{' '}
                            <span dir="ltr">{r.error}</span>
                        </p>
                    )}

                    {r.open && (
                        <Link href={r.open} className="inline-flex items-center gap-1 text-[12px] text-[#6d28d9] hover:underline" data-testid="log-open-chat">
                            <MessagesSquare size={13} />
                            {t('فتح المحادثة')}
                        </Link>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="سجلّ رسائل واتساب">
            <PageHeader
                title="سجلّ رسائل واتساب"
                subtitle={t('ما خرج فعلًا إلى زبائنك — وماذا جرى له')}
                actions={
                    <Button asChild variant="outline">
                        <Link href={route('admin.marketing.whatsapp')}>
                            <SlidersHorizontal />
                            {t('مقابض الإشعارات')}
                        </Link>
                    </Button>
                }
            />

            {! readiness.ready && (
                <ConnectSteps
                    className="mb-6"
                    readiness={readiness}
                    title={t('ربط واتساب غير مكتمل')}
                    done={t('رسائلك تخرج')}
                    waiting={t('ما خرج قبل ذلك محفوظٌ هنا ويُقرأ — والرسائلُ الجديدة لا تخرج حتى تكتمل هذه الخطوات.')}
                />
            )}

            {/* عدّادُ الشهر — والنافذةُ مكتوبةٌ فيه: رقمٌ بلا مدّةٍ لا يُقرأ */}
            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {buckets
                    .filter((b) => b.key !== 'all')
                    .map((b) => (
                        <Card key={b.key} className="p-4">
                            <p className="text-[13px] text-[#6b7280]">{b.label}</p>
                            <p className="mt-1 text-[24px] font-bold text-[#111]">{summary[b.key] ?? 0}</p>
                            <p className="mt-1 text-[11px] text-[#9ca3af]">
                                {t('آخر :n يومًا', { n: summaryDays })}
                            </p>
                        </Card>
                    ))}
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={rows}
                    columns={columns}
                    rowKey={(r) => r.key}
                    searchPlaceholder="ابحث بنصّ الرسالة أو اسم العميل أو رقمه أو رقم الطلب أو الفاتورة…"
                    searchable={() => ''}
                    empty={
                        params.q !== '' || params.filter !== 'all' || params.source !== 'all' || params.kind !== 'all'
                            ? 'لا رسائل تطابق البحث'
                            : 'لم تخرج رسالةٌ بعد'
                    }
                    filters={[
                        {
                            label: 'ماذا جرى',
                            param: 'filter',
                            asTabs: true,
                            options: buckets.map((b) => ({ label: b.label, value: b.key })),
                        },
                        {
                            label: 'النوع',
                            param: 'kind',
                            options: kinds.map((k) => ({ label: k.label, value: k.key })),
                        },
                        {
                            label: 'الرقم',
                            param: 'source',
                            options: sources.map((s) => ({ label: s.label, value: s.key })),
                        },
                    ]}
                    server={{ pagination, params }}
                />
            </Card>

            <p className="mt-4 flex items-center gap-2 text-[12px] leading-relaxed text-[#9ca3af]">
                <MessageCircle size={14} />
                {t('«خرجت إلى واتساب» تعني أنّ واتساب قبِل الرسالة، لا أنّها وصلت الجهاز. والوصولُ يُكتب حين يصل إشعارُه.')}
            </p>
        </AdminLayout>
    );
}
