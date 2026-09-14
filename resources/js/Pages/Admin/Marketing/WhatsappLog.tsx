import { Link, usePage } from '@inertiajs/react';
import { MessageCircle, SlidersHorizontal } from 'lucide-react';
import { WhatsAppBusinessMark } from '@/Components/BrandMarks';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Readiness } from '@/Components/Connect';
import type { PageProps } from '@/types';

/** صفٌّ من الدفتر — انظر `App\Support\WhatsAppLog::row` */
interface Row {
    id: number;
    at: string | null;
    event: string;
    phone: string | null;
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
    rows: Row[];
    pagination: ServerPagination;
    summary: Record<string, number>;
    summaryDays: number;
    buckets: { key: string; label: string }[];
    params: { filter: string; q: string };
}

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
    const { readiness, rows, pagination, summary, summaryDays, buckets, params } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    if (! readiness.connected) {
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
        { key: 'event', header: 'الحدث', cell: (r) => <span className="text-[13px]">{r.event}</span> },
        {
            key: 'phone',
            header: 'رقم الزبون',
            cell: (r) => <span dir="ltr" className="text-[13px]">{r.phone ?? '—'}</span>,
        },
        {
            key: 'subject',
            header: 'الورقة',
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
                    rowKey={(r) => r.id}
                    searchPlaceholder="ابحث برقم الزبون أو رقم الطلب أو الفاتورة…"
                    searchable={() => ''}
                    empty={
                        params.q !== '' || params.filter !== 'all'
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
