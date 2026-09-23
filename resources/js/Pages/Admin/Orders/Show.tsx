import { useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    Calendar,
    ClipboardList,
    FileText,
    Gift,
    MapPin,
    MessageCircle,
    MessageSquareQuote,
    PencilLine,
    Phone,
    Printer,
    ReceiptText,
    Send,
    Star,
    Truck,
    User,
} from 'lucide-react';
import DocumentPanel, { DocumentAside } from '@/Components/DocumentPanel';
import {
    CorrectItemDialog,
    CorrectPaymentDialog,
    type CorrectableItem,
    type OrderEditRecord,
} from '@/Components/InvoiceCorrection';
import Field, { Select } from '@/Components/Field';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from "@/lib/i18n";
import type { PageProps } from '@/types';

interface OrderDetail {
    id: string;
    customer: string;
    /** لغةُ رسائل واتساب من بطاقة الزبون — `null` يعني لغةَ المتجر */
    customer_language: string | null;
    /** تنبيهٌ داخليٌّ على الزبون — للموظّف قبل أن يراسله، ولا يدخل نصَّ رسالة */
    customer_alert: { type: 'warning' | 'block'; reason: string } | null;
    employee: string;
    branch: string | null;
    date: string;
    payment: string;
    payment_status: string;
    notes: string | null;
    /* تفاصيل التنفيذ — كلّها قد تكون فارغة في طلبٍ قديم */
    recipient_name: string | null;
    recipient_phone: string | null;
    fulfillment_type: string | null;
    scheduled_for: string | null;
    occasion_type: string | null;
    card_message: string | null;
    card_align: string | null;
    card_file: string | null;
    card_file_name: string | null;
    sender_name: string | null;
    hide_sender: boolean;
    delivery_address: string | null;
    delivery_notes: string | null;
    internal_notes: string | null;
    status: string;
    next_statuses: string[];
    occasions: { value: string; label: string }[];
    fulfillments: { value: string; label: string }[];
    subtotal: number;
    discount: number;
    tax: number;
    delivery: number;
    total: number;
    /** والمعرّف يُقرأ لا يُعرض: به يُنادى مسارُ التصحيح على البند بعينه */
    items: {
        id: number;
        name: string;
        qty: number;
        price: number;
        total: number;
        /**
         * وصفُ الطلب المخصَّص كما بيع — لقطةٌ لا مرجع.
         *
         * `null` لكلّ بندٍ من الكتالوج. والداخليُّ من حقوله يظهر هنا خلافًا
         * للورق: هذه شاشةُ صاحب المتجر، وتلك ورقةُ زبونه.
         */
        custom?: {
            template: string | null;
            base_label: string | null;
            base_value: number | null;
            fields: { label: string; internal: boolean; values: string[] }[];
        } | null;
    }[];
    /** ما أذن به التاجر وحده — لا تُصحَّح وسيلةُ الدفع إلى وسيلةٍ مُطفأة */
    payment_methods: string[];
    /** تصحيحات وقعت على الفاتورة بعد بيعها — انظر App\Support\OrderCorrection */
    edits: OrderEditRecord[];
}

/**
 * صفحة الطلب — قسمان لا كومةٌ واحدة.
 *
 * كانت البطاقات الستّ تتراكم في عمودٍ واحد: بيانات العميل، ووسيلة الدفع،
 * والتنفيذ، والبطاقة، والحالة، والتصحيحات — يقرؤها المحاسب باحثًا عن مبلغ
 * فيمرّ على نصّ بطاقة إهداء، ويقرؤها من يجهّز الباقة باحثًا عن العنوان فيمرّ
 * على الضريبة. وهما قارئان مختلفان لحاجتين مختلفتين.
 *
 * فصارت تبويبين: **بيانات الطلب** — ما بيع وبكم ولمن ومتى وبأي حالة. و**ورقة
 * التفاصيل** — الورقة التي تُنفَّذ منها: إلى من تصل، ومتى، وإلى أين، وبأي
 * بطاقة. وتُعدَّل من مكانها.
 *
 * وتعديلُ الورقة لا يمسّ مالًا ولا حالة: `OrderDetailController::update` يكتب
 * حقول التنفيذ وحدها ولا يعيد حسبة إجمالي، والحالة بابها الآخر في تبويب
 * البيانات. فمن يصحّح رقم هاتفٍ لا يُحرّك ريالًا ولا يُقدّم طلبًا في مساره.
 *
 * وتصحيحُ **متن الفاتورة** بابٌ ثالث: `Pos\OrderEditController` نفسُه الذي
 * يُصحّح به الكاشير — يمسّ الكميّة ووسيلة الدفع، ويُحرّك معهما المخزونَ
 * والضريبةَ والنقاطَ والمعاملةَ المالية، ويترك أثرًا باسم من صحّح. وشرطاه
 * صلاحيةُ `order.edit` ويومُ البيع، يقيسهما الخادم قبل الرسم وعند الكتابة.
 */
export default function OrderShow() {
    const { order, context, taxInvoice, googleReview, storeReview, statusNotice, paper, invoiceEdit, otherBranch } =
        usePage<
        PageProps<{
            order: OrderDetail;
            /** أيُصحَّح متنُ الفاتورة الآن — وإن لم يُصحَّح فلمَ. يُقاس في الخادم */
            invoiceEdit: { can: boolean; reason: string | null };
            /** فرعُ الطلب حين لا يكون الفرعَ المختار — و`null` حين يكون */
            otherBranch: { id: number; name: string } | null;
            /** ورقةُ الطلب مرسومةً — من وصفة الطباعة نفسِها. و`null` لطلبٍ لا صفَّ له */
            paper: { html: string; size: string } | null;
            taxInvoice: { registered: boolean; ready: boolean };
            /** حالُ طلب تقييم Google — تُقاس في الخادم، والشاشةُ ترسم ما قِيس */
            googleReview: { show: boolean; reason: string | null; requestedAt: string | null };
            /**
             * حالُ دعوةِ الرأي لموقع المتجر — بابٌ آخر غير Google.
             *
             * و`written` تُقرأ من التقييم نفسِه لا من ختمِ «طُلب»: ما يعود
             * إلينا يُعرف وصولُه، فيُقال «وصل رأيه» لا «جُهِّزت الدعوة».
             */
            storeReview: { show: boolean; reason: string | null; written: boolean };
            statusNotice: {
                event: string | null;
                show: boolean;
                reason: string | null;
                preparedAt: string | null;
            };
        }>
    >().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    /*
     * ═══ زرّان فوق الصفحة لا زرٌّ واحد ═══
     *
     * «تعديل» كان واحدًا يفتح **ورقة التفاصيل**: من ضغطه باحثًا عن كميّةٍ
     * أدخلها خطأً — وهو أكثرُ من يضغط زرًّا بهذا الاسم فوق فاتورة — وجد
     * حقولَ اسم المستلم وموعد التسليم ونصّ البطاقة، ولا شيء فيها يمسّ
     * الرقم الذي فتح الصفحة من أجله. فرجع يظنّ أن الفاتورة لا تُصحَّح.
     *
     * فصارا بابين باسمين: **تعديل الفاتورة** يمسّ ما بيع وبكم — ويُحرّك
     * المخزون والضريبة والنقاط ويترك أثرًا باسم من صحّح. و**ورقة التفاصيل**
     * تمسّ التنفيذ وحده ولا تلمس ريالًا.
     *
     * ولكلٍّ مرجعٌ يمضي إليه: زرٌّ يُضغط فيتغيّر شيءٌ خارج نظر من ضغطه
     * مقبضٌ لا يُدير شيئًا.
     */
    const sheetRef = useRef<HTMLDivElement>(null);
    const invoiceRef = useRef<HTMLDivElement>(null);

    /*
     * ═══ طلبُ فرعٍ آخر: يُقرأ ولا يُتصرَّف فيه ═══
     *
     * الصفحة تُفتح لطلبات المتجر كلِّها — يصلها التاجر من البحث العامّ ومن
     * التنبيهات ومن صفحة العميل، ولا واحدةٌ منها تُرشِّح بالفرع. والأفعالُ
     * تتبع الفرع في الخادم. فكانت الصفحة تُرسم بأزرارها كاملةً على طلبِ فرعٍ
     * آخر، ويضغط التاجر «جاهز» أو «إرسال» فتُردّ صفحةُ ٤٠٤ بلا كلمة: لا هو
     * يعرف أنّ الطلب من فرعٍ غير فرعه، ولا أنّ بدّالة الفروع هي الحلّ.
     *
     * فتُخفى الأفعال ويُقال السبب مرّةً واحدة في لافتةٍ فوق الصفحة، ومعها
     * البابُ الذي يفتحها. والقراءةُ تبقى كلُّها: الفاتورة والإيصال وسند
     * التسليم وسجلّ التصحيحات — لا حاجة لتبديل فرعٍ لقراءة ورقة.
     */
    const actionable = !otherBranch;


    /*
     * نموذج تعديل التنفيذ — يبدأ من القيم المحفوظة.
     *
     * القيم الفارغة تُبدَّل بسلسلةٍ فارغة لا تُترك null: حقلٌ قيمتُه null
     * يبدأ غير مضبوط في React، فأوّل حرفٍ يُكتب فيه يقلبه إلى مضبوط ويطبع
     * تحذيرًا في المتصفّح.
     */
    const [editing, setEditing] = useState(false);
    const [previewing, setPreviewing] = useState(false);
    /*
     * وضعُ تصحيح الفاتورة — يُفتح بالزرّ ولا يبدأ مفتوحًا.
     *
     * أقلامٌ مرسومةٌ دائمًا على جدول فاتورةٍ صدرت تجعل تغييرَ ما بيع أقربَ
     * إلى اليد من قراءته، وأكثرُ من يفتح هذه الصفحة إنّما جاء يقرأ.
     */
    const [correcting, setCorrecting] = useState(false);
    const [correctingItem, setCorrectingItem] = useState<CorrectableItem | null>(null);
    const [fixingPayment, setFixingPayment] = useState(false);
    const form = useForm({
        fulfillment_type: order.fulfillment_type ?? '',
        recipient_name: order.recipient_name ?? '',
        recipient_phone: order.recipient_phone ?? '',
        scheduled_for: order.scheduled_for ?? '',
        occasion_type: order.occasion_type ?? '',
        card_message: order.card_message ?? '',
        sender_name: order.sender_name ?? '',
        hide_sender: order.hide_sender,
        delivery_address: order.delivery_address ?? '',
        delivery_notes: order.delivery_notes ?? '',
        internal_notes: order.internal_notes ?? '',
    });
    const isDelivery = form.data.fulfillment_type === 'delivery';

    /* إرسالُ الفاتورة — نموذجٌ فارغ: النصُّ كلُّه يُكتب في الخادم */
    const sending = useForm({});

    const occasionLabel = order.occasions.find((o) => o.value === order.occasion_type)?.label;
    const fulfillmentLabel = order.fulfillments.find((f) => f.value === order.fulfillment_type)?.label;

    /*
     * ورقةٌ فارغة تُقال فارغةً ولا تُخفى.
     *
     * كانت البطاقة تختفي كلّها حين لا شيء فيها، فطلبٌ قديم بلا تفاصيل لا
     * يعرف صاحبه أن للتفاصيل موضعًا أصلًا — ولا كيف يضيفها.
     */
    const hasSheet = Boolean(
        order.fulfillment_type ||
            order.scheduled_for ||
            order.recipient_name ||
            order.recipient_phone ||
            order.delivery_address ||
            order.card_message ||
            order.sender_name ||
            order.internal_notes,
    );

    const summary: { label: string; value: string; tone?: string }[] = [
        { label: 'المجموع الفرعي', value: m(order.subtotal) },
        { label: 'الخصم', value: `- ${m(order.discount)}`, tone: 'text-[#b91c1c]' },
        { label: 'الضريبة', value: m(order.tax) },
        { label: 'رسوم التوصيل', value: m(order.delivery) },
    ];

    /** سطرٌ في الورقة: عنوانٌ خافت وقيمةٌ ظاهرة — والفارغ يُكتب شرطة لا يُحذف */
    const Row = ({ label, value, ltr }: { label: string; value: string | null; ltr?: boolean }) => (
        <div className="flex items-start justify-between gap-4 py-2">
            <dt className="shrink-0 text-[13px] text-[#6b7280]">{t(label)}</dt>
            <dd
                className={`text-end text-sm font-medium ${value ? 'text-[#111]' : 'text-[#9ca3af]'}`}
                dir={ltr && value ? 'ltr' : undefined}
            >
                {value || '—'}
            </dd>
        </div>
    );

    /** كتلةٌ في الورقة: عنوانٌ بأيقونة وما تحته */
    const Block = ({
        icon: Icon,
        title,
        children,
    }: {
        icon: typeof Truck;
        title: string;
        children: React.ReactNode;
    }) => (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
            <h4 className="mb-1 flex items-center gap-2 text-[13px] font-bold text-[#111]">
                <Icon className="size-4 text-[#9ca3af]" />
                {t(title)}
            </h4>
            <dl className="divide-y divide-[var(--ui-border,#e8e8e8)]">{children}</dl>
        </div>
    );

    return (
        <AdminLayout title="تفاصيل الطلب">
            <PageHeader
                title={`${t('الطلب')} ${order.id}`}
                subtitle={order.date}
                actions={
                    <>
                        {/*
                            ═══ وتسلسلُ الأفعال: ماذا يفعل من فتح هذه الصفحة ═══

                            **الأوّلُ إرسال** — وهو الفعلُ الذي جاء له أكثرُ من
                            يفتح طلبًا: يبلّغ الزبونَ أنّ طلبَه جاهز. فيأخذ
                            الوزنَ البصريّ وحده.

                            ثمّ **أوراقُ الطلب** — معاينةٌ وفاتورةٌ ضريبيّةٌ
                            وسندُ تسليم: ثلاثةُ مستنداتٍ مختلفة لا ثلاثُ نسخٍ
                            من واحد.

                            و**تعديل** آخرًا: فعلٌ يخصّ حالاتٍ بعينها، وموضعُه
                            حيث لا يُضغَط سهوًا.
                        */}
                        {actionable && (
                            <Button
                                disabled={sending.processing}
                                onClick={() =>
                                    sending.post(route('admin.orders.send', order.id), { preserveScroll: true })
                                }
                            >
                                <Send />
                                {/*
                                    و«إرسال» وحدَها كذبة.

                                    الزرُّ يفتح واتساب التاجر بنصٍّ مكتوب، ويضغط
                                    هو «إرسال» هناك. ومن قرأ «إرسال» ظنّ أنّ
                                    الفاتورة خرجت، فأغلق الشاشة ولم يرسلها —
                                    ولا يعرف أنّه لم يرسلها.
                                */}
                                {t('إرسال بواتسابك')}
                            </Button>
                        )}

                        {/*
                            و«معاينة» على الشاشة الضيّقة وحدها.

                            الورقةُ على العريضة قائمةٌ إلى جانب التفاصيل
                            وفوقها أزرارُها، فزرٌّ هنا يفعل ما يفعله زرٌّ
                            مرئيٌّ في الوقت نفسه — فعلان بمسمّيين لشيءٍ واحد.
                            وعلى الضيّقة تنزل الورقةُ أسفل كلّ بطاقات
                            التفاصيل، فالمختصرُ هنا يوصل إليها.
                        */}
                        {paper && (
                            <Button variant="outline" className="xl:hidden" onClick={() => setPreviewing(true)}>
                                <FileText />
                                {t('معاينة الفاتورة')}
                            </Button>
                        )}

                        {/*
                            و«أبلغ الزبون» يدويٌّ صراحةً: يُفتح واتساب التاجر
                            بنصٍّ مكتوب، ويضغط هو «إرسال». فيعمل وميتا محجوبة
                            — والزبون لا يبقى بلا خبرٍ لأنّ أتمتةً توقّفت.

                            ولا يُعرض إلّا لحالةٍ لها إشعار: «قيد التجهيز» لا
                            خبرَ فيها، و«ملغى» ليست بشرى.

                            وحين يمنعه مانعٌ يبقى معروضًا معطَّلًا بسببه
                            مكتوبًا: بابٌ يقول لمَ لا يُفتح خيرٌ من بابٍ يُفتح
                            على رفض. والسببُ نفسُه يُقاس في الخادم ثانيةً —
                            فمن نادى المسار دون الشاشة يُردّ بالجواب نفسِه.
                        */}
                        {actionable &&
                            statusNotice.event &&
                            (statusNotice.show ? (
                                <Button
                                    variant="outline"
                                    disabled={sending.processing}
                                    onClick={() =>
                                        sending.post(route('admin.orders.statusNotice', order.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <MessageCircle />
                                    {/*
                                        و«مجددًا» تشهد بأنّ الأولى وصلت.

                                        ولم تصل: `preparedAt` تقول إنّ النصَّ
                                        جُهِّز، لا إنّه خرج — ولا يعرف النظامُ
                                        أنّ التاجر ضغط «إرسال» في واتساب.
                                        فيُقال «جهّزه» لا «أبلغه».
                                    */}
                                    {statusNotice.preparedAt
                                        ? t('جهّز الإشعار مجددًا')
                                        : t('أبلغ الزبون بواتسابك')}
                                </Button>
                            ) : (
                                <Button variant="outline" disabled title={statusNotice.reason ?? undefined}>
                                    <MessageCircle />
                                    {statusNotice.reason}
                                </Button>
                            ))}
                        {/*
                            و«طلب تقييم Google» يُعرض بعد التسليم وحده، ولفرعٍ
                            مربوط — والشرطان يُقاسان في الخادم. والنصُّ والرابط
                            يُبنيان هناك بملفّ **فرع هذا الطلب**، ثمّ يُفتح واتساب
                            التاجر ليضغط هو «إرسال»: لا مُرسِلَ آليّ ولا قالبَ
                            ميتا معتمَد لطلب تقييم.
                        */}
                        {actionable && googleReview.show && (
                            <Button
                                variant="outline"
                                disabled={sending.processing}
                                onClick={() =>
                                    sending.post(route('admin.orders.reviewRequest', order.id), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                <Star />
                                {/*
                                    و«Google» في الاسم لا في التلميح وحده.

                                    زرّان متجاوران يطلبان رأيًا: هذا يُخرج
                                    الزبون إلى ملفٍّ عامّ لا يملك التاجر ما
                                    يُكتب فيه، والذي تحته يُدخل الرأي إلى
                                    شاشته ليأذن بنشره. واسمان متشابهان يعنيان
                                    تاجرًا يضغط ما لم يقصده.
                                */}
                                {googleReview.requestedAt
                                    ? t('جهّز طلب Google مجددًا')
                                    : t('اطلب تقييم Google بواتسابك')}
                            </Button>
                        )}
                        {/*
                            ورأيُ الزبون لموقع المتجر — يصل معلَّقًا إلى شاشة
                            «تقييمات العملاء»، فيقرؤه صاحبُ المحلّ ويُنشره أو
                            يرفضه. والرابطُ في الرسالة رمزُ **هذا الطلب**: لا
                            يكتب فيه إلا من اشترى، ولا يُكتب فيه إلا رأيٌ واحد.
                        */}
                        {actionable &&
                            (storeReview.show ? (
                                <Button
                                    variant="outline"
                                    disabled={sending.processing}
                                    onClick={() =>
                                        sending.post(route('admin.orders.reviewInvite', order.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <MessageSquareQuote />
                                    {t('اطلب رأيه لموقعك بواتسابك')}
                                </Button>
                            ) : storeReview.reason ? (
                                <Button variant="outline" disabled title={storeReview.reason}>
                                    <MessageSquareQuote />
                                    {storeReview.reason}
                                </Button>
                            ) : null)}
                        {/*
                            الفاتورة الضريبيّة — ولا تُعرض لمن لا فاتورةَ ضريبيّةَ
                            له.

                            رُفع هذا الزرّ مرّةً فبقيت الورقة بلا مدخل: مسارٌ
                            وقالبٌ واختباراتٌ لا يقود إليها زرّ، فلا تُفتح إلا
                            بكتابة عنوانها. والتاجر يجبي الضريبة ولا يجد بابًا
                            يُخرج ورقتَها.

                            وعاد بشرطه: متجرٌ غير مسجَّلٍ في الضريبة لا يراه —
                            القالبُ يُسقط الرمز ولا يدّعي أنّها ضريبيّة، فزرٌّ
                            بهذا الاسم يَعِد بورقةٍ لا تُنتَج. ومتجرٌ لم يُسمَّ
                            يراه معطَّلًا بسببه مكتوبًا: بابٌ يُفتح على رفضٍ
                            أسوأ من بابٍ يقول لمَ لا يُفتح.
                        */}
                        {taxInvoice.registered &&
                            (taxInvoice.ready ? (
                                <Button variant="outline" asChild>
                                    <a
                                        href={route('admin.orders.taxInvoice', order.id)}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ReceiptText />
                                        {t('فاتورة ضريبية')}
                                    </a>
                                </Button>
                            ) : (
                                <Button variant="outline" asChild>
                                    <a
                                        href="/admin/setup"
                                        title={t('لم يُسمَّ متجرك بعد — والفاتورة الضريبية تحمل اسمه.')}
                                    >
                                        <ReceiptText />
                                        {t('فاتورة ضريبية — سمِّ متجرك أولًا')}
                                    </a>
                                </Button>
                            ))}
                        {/*
                            وإيصالُ الصندوق مخرجٌ ثانٍ لا بديلٌ عن الفاتورة.

                            كان مقاسُ القالب يختار أيَّهما يخرج من زرٍّ واحد:
                            من ضبط صندوقَه على ٨٠مم لم تكن له فاتورةُ A4
                            أصلًا. والطلبُ الواحد له الاثنان — ورقةٌ تُرسَل
                            وشريطٌ يُسلَّم — فزرّان لا زرٌّ يُبدَّل.
                        */}
                        <Button variant="outline" asChild>
                            <a href={route('admin.orders.receipt', order.id)} target="_blank" rel="noreferrer">
                                <Printer />
                                {t('إيصال حراري')}
                            </a>
                        </Button>

                        {/*
                            وسندُ التسليم ورقةٌ أخرى لا نسخةٌ من الفاتورة:
                            يحملها السائق، ويوقّعها المستلم، وقالبُها يُخفي
                            الأسعار — فلا يرى من استلم الهديّة ثمنَها.
                        */}
                        <Button variant="outline" asChild>
                            <a href={route('admin.orders.deliveryNote', order.id)} target="_blank" rel="noreferrer">
                                <Truck />
                                {t('سند تسليم')}
                            </a>
                        </Button>

                        {/*
                            و«تعديل الفاتورة» يفتح أقلامَ الجدول ويمضي إليه:
                            الكميّة وما بيع ووسيلة الدفع — لا اسمُ المستلم.

                            ومن لا يملك `order.edit` لا يراه أصلًا. ومن يملكها
                            وانتهى يومُ فاتورته يراه معطَّلًا بسببه مكتوبًا:
                            حدٌّ زمنيّ يُقدَّر، لا رفضٌ لشخصه. والشرطان يُقاسان
                            في الخادم ثانيةً عند الكتابة.
                        */}
                        {invoiceEdit.can && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setCorrecting(true);
                                    invoiceRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }}
                            >
                                <PencilLine />
                                {t('تعديل الفاتورة')}
                            </Button>
                        )}
                        {!invoiceEdit.can && invoiceEdit.reason && (
                            <Button variant="outline" disabled title={invoiceEdit.reason}>
                                <PencilLine />
                                {invoiceEdit.reason}
                            </Button>
                        )}

                        {/*
                            وورقةُ التفاصيل بابُها زرُّها: ضغطةٌ منفصلة باسمٍ
                            يقول إلى أين تمضي — لا «تعديل» يُفهم منه المال.
                        */}
                        {actionable && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setEditing(true);
                                    sheetRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }}
                            >
                                <ClipboardList />
                                {t('ورقة التفاصيل')}
                            </Button>
                        )}
                    </>
                }
            />

            {/*
                ولافتةٌ واحدة تقول السبب والباب معًا.

                والبابُ فيها لا خارجَها: من قرأ «هذا الطلب من فرع صلالة» ولم
                يجد كيف يذهب إليه يبحث عن بدّالة الفروع في الترويسة — إن عرف
                أنّها موجودة. فالتبديلُ ضغطةٌ في مكان الخبر.
            */}
            {otherBranch && (
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] px-4 py-3">
                    <p className="text-sm text-[#92400e]">
                        {t('هذا الطلب من فرع «:branch» — يُقرأ من هنا، ولا يُتصرَّف فيه إلا من فرعه.', {
                            branch: otherBranch.name,
                        })}
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <a href={route('admin.branch.switch', otherBranch.id)}>
                            <Building2 />
                            {t('انتقل إلى :branch', { branch: otherBranch.name })}
                        </a>
                    </Button>
                </div>
            )}

            {/*
                قسمان: ما يقوله النظام عن الطلب، وما ستراه الجهةُ التي تستلم
                ورقتَه.

                ═══ ولمَ جنبًا إلى جنب لا في نافذةٍ تُطلب ═══

                من يراجع فاتورةً أمام زبونٍ — أو يقابل ما طُبع بما سُجّل —
                يحتاج الاثنين في نظرةٍ واحدة. ونافذةٌ تُفتح وتُغلق تجعله
                يبدّل ذهابًا وإيابًا ويحفظ الأرقامَ في رأسه بينهما.

                ═══ والانقسامُ من `xl` وحدها ═══

                على شاشةٍ أضيق تصير الورقةُ عمودًا من ثلاثمئة بكسل: لا تُقرأ
                ولا تنفع، وتزاحم ما ينفع. فتنزل تحت التفاصيل بعرض الشاشة،
                مُصغَّرةً بمقاسها الحقيقيّ كما في كلّ معاينة.

                ═══ وثلاثةُ أخماسٍ إلى خمسين ═══

                التفاصيلُ هي المقصودة: فيها الجدولُ الذي يُنسَخ ويُبحَث
                ونموذجُ التعديل. والورقةُ شاهدٌ إلى جانبها لا ندٌّ لها.
            */}
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-6 xl:col-span-3">
                    <div className="space-y-6">
                        <div ref={invoiceRef}>
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-bold text-[#111]">{t('تفاصيل المنتجات')}</h3>
                                {/* ومخرجٌ من الوضع بالوضوح الذي دخل به — لا بضغط «رجوع» */}
                                {correcting && (
                                    <Button variant="ghost" size="sm" onClick={() => setCorrecting(false)}>
                                        {t('إنهاء التعديل')}
                                    </Button>
                                )}
                            </div>
                            {/*
                                والحدُّ يُقال داخل الوضع لا قبل الدخول إليه: من
                                فتح الأقلام يعرف قبل أن يضغط أحدَها أنّ ما يفعله
                                يمسّ الرفَّ والدفتر معًا ويبقى باسمه.
                            */}
                            {correcting && (
                                <p className="mb-3 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                                    {t('التصحيح يُعيد المخزون ويُحتسب الضريبة والنقاط من جديد، ويبقى مقيَّدًا باسمك في سجلّ الفاتورة.')}
                                </p>
                            )}
                            <Card className="overflow-hidden">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead>{t('المنتج')}</TableHead>
                                            <TableHead className="text-end">{t('الكمية')}</TableHead>
                                            <TableHead className="text-end">{t('السعر')}</TableHead>
                                            <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                            {correcting && <TableHead className="text-end">{t('تصحيح')}</TableHead>}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {order.items.map((line, i) => (
                                            <TableRow key={i}>
                                                <TableCell className="font-medium text-[#111]">
                                                    {line.name}
                                                    {/*
                                                        وما اختاره الزبونُ تحت اسم بنده.

                                                        كان البندُ المخصَّص سطرًا واحدًا هنا — اسمًا وسعرًا —
                                                        واللونُ والمقاسُ واسمُ المُهدى إليه في القاعدة لا يقرؤها
                                                        أحد. فيتّصل الزبونُ يقول «طلبتُ الأحمر» وصاحبُ المتجر
                                                        أمام شاشةٍ لا تحسم. والورقةُ تحملها ولوحةُ التجهيز
                                                        تحملها، وهذه وحدها كانت عمياء.
                                                    */}
                                                    {line.custom && line.custom.fields.length > 0 && (
                                                        <ul className="mt-1 space-y-0.5">
                                                            {line.custom.fields.map((f, k) => (
                                                                <li
                                                                    key={k}
                                                                    className="text-[12px] font-normal text-[#6b7280]"
                                                                >
                                                                    <span className="text-[#4b4b4b]">{f.label}</span>
                                                                    {': '}
                                                                    {f.values.join(' · ')}
                                                                    {/* والداخليُّ يُوسم: يُقرأ هنا ولا يُطبع للزبون */}
                                                                    {f.internal && (
                                                                        <span className="ms-1 text-[11px] text-[#b45309]">
                                                                            {t('داخلي')}
                                                                        </span>
                                                                    )}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {number(line.qty)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                                    {m(line.price)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums font-medium">
                                                    {m(line.total)}
                                                </TableCell>
                                                {correcting && (
                                                    <TableCell className="text-end">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={t('تصحيح البند')}
                                                            onClick={() => setCorrectingItem(line)}
                                                        >
                                                            <PencilLine />
                                                        </Button>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        </div>

                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('الملخص المالي')}</h3>
                            <dl className="space-y-3 text-sm">
                                {summary.map((row) => (
                                    <div key={row.label} className="flex items-center justify-between">
                                        <dt className="text-[#6b7280]">{t(row.label)}</dt>
                                        <dd className={`font-medium tabular-nums ${row.tone ?? 'text-[#111]'}`}>
                                            {row.value}
                                        </dd>
                                    </div>
                                ))}
                                <div className="flex items-center justify-between border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                                    <dt className="font-bold text-[#111]">{t('الإجمالي')}</dt>
                                    <dd className="text-[18px] font-bold tabular-nums text-[#6d28d9]">
                                        {m(order.total)}
                                    </dd>
                                </div>
                            </dl>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('بيانات العميل')}</h3>
                            <div className="mb-4 flex items-center gap-3">
                                <span className="flex size-11 items-center justify-center rounded-full bg-[#f5f3ff] font-bold text-[#6d28d9]">
                                    {order.customer.slice(0, 1)}
                                </span>
                                <div>
                                    <p className="flex items-center gap-2 font-medium text-[#111]">
                                        {order.customer}
                                        {order.customer_language === 'en' && (
                                            <span
                                                title={t('يُراسَل بالإنجليزيّة على واتساب')}
                                                className="rounded-md border border-[#c7d2fe] bg-[#eef2ff] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#4338ca]"
                                            >
                                                EN
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-[12px] text-[#9ca3af]">{order.branch || '—'}</p>
                                </div>
                            </div>
                            {/*
                                التنبيهُ الداخليّ يُقال هنا — حيث أزرارُ «أبلغ الزبون»
                                و«إرسال بواتسابك» — لا في نصّ الرسالة. والحظرُ يمنع البيعَ
                                لا التواصل، فالأزرار تبقى.
                            */}
                            {order.customer_alert && (
                                <div
                                    className={
                                        order.customer_alert.type === 'block'
                                            ? 'mb-4 rounded-xl border border-[#fecaca] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]'
                                            : 'mb-4 rounded-xl border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]'
                                    }
                                >
                                    <p className="font-bold">
                                        {order.customer_alert.type === 'block' ? '⛔ ' : '⚠️ '}
                                        {t('يوجد تنبيه داخلي على هذا العميل')}
                                    </p>
                                    {order.customer_alert.reason && <p className="mt-1">{order.customer_alert.reason}</p>}
                                </div>
                            )}
                            <dl className="space-y-2.5 text-sm text-[#4b4b4b]">
                                <div className="flex items-center gap-2">
                                    <User className="size-4 text-[#9ca3af]" />
                                    {t('الموظف')}: {order.employee}
                                </div>
                                <div className="flex items-center gap-2">
                                    <Calendar className="size-4 text-[#9ca3af]" />
                                    {order.date}
                                </div>
                            </dl>
                        </Card>

                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-[#111]">{t('حالة الدفع')}</h3>
                            {/*
                                ووسيلةُ الدفع تُصحَّح كما تُصحَّح الكميّة: «نقدي»
                                على دفعةٍ بالبطاقة يجعل الإقفال يطلب مالًا لم
                                يدخل الدرج، ولا يظهر السبب في أيّ شاشة.
                            */}
                            <div className="mb-3 flex items-center justify-between gap-2">
                                <span className="text-sm text-[#6b7280]">{t('وسيلة الدفع')}</span>
                                <span className="flex items-center gap-1">
                                    <Badge status={order.payment}>
                                        {t(order.payment === 'بطاقة' ? 'فيزا' : order.payment)}
                                    </Badge>
                                    {correcting && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={t('تصحيح وسيلة الدفع')}
                                            onClick={() => setFixingPayment(true)}
                                        >
                                            <PencilLine />
                                        </Button>
                                    )}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-[#6b7280]">{t('حالة الدفع')}</span>
                                <Badge variant="success">{t(order.payment_status)}</Badge>
                            </div>
                        </Card>

                        {/*
                            نقل الحالة — الخيارات من الخادم لا من قائمةٍ تُكتب هنا.
                            وحارسُ الانتقالات في الخادم؛ هذه تعرض ما يجوز وحده.

                            وموضعها هنا لا في الورقة عمدًا: تعديل التفاصيل لا يُقدّم
                            الطلب في مساره، فلا يُوضع البابان في يدٍ واحدة.
                        */}
                        <Card className="p-6">
                            <h3 className="mb-3 font-bold text-[#111]">{t('حالة الطلب')}</h3>
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-sm text-[#6b7280]">{t('الحالة')}</span>
                                <Badge status={order.status}>{t(order.status)}</Badge>
                            </div>
                            {actionable && order.next_statuses.length > 0 && (
                                <div className="flex flex-wrap gap-2">
                                    {order.next_statuses.map((s) => (
                                        <Button
                                            key={s}
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    route('admin.orders.status', order.id),
                                                    { status: s },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t(s)}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </Card>

                        {order.notes && (
                            <Card className="p-6">
                                <h3 className="mb-3 font-bold text-[#111]">{t('ملاحظات الطلب')}</h3>
                                <p className="text-sm leading-relaxed text-[#4b4b4b]">{order.notes}</p>
                            </Card>
                        )}

                        {/*
                            ما غُيّر في هذه الفاتورة بعد بيعها.
                            فاتورةٌ نقص إجماليّها تُسأل «لماذا؟» عند قراءتها، فالجواب
                            بجانبها لا في سجلّ نشاطٍ يُفتح بقصد ويُبحث فيه.
                        */}
                        {order.edits.length > 0 && (
                            <Card className="p-6">
                                <h3 className="mb-3 flex items-center gap-2 font-bold text-[#111]">
                                    <PencilLine className="size-4 text-[#b45309]" />
                                    {t('تصحيحات بعد البيع')}
                                </h3>
                                <ul className="flex flex-col gap-3 text-sm">
                                    {order.edits.map((e, i) => (
                                        <li key={i} className="rounded-[10px] bg-[#fffbeb] p-3">
                                            <p className="font-medium text-[#111]">
                                                {e.kind === 'وسيلة دفع'
                                                    ? `${t('وسيلة الدفع')}: ${t(e.value_before ?? '')} ← ${t(e.value_after ?? '')}`
                                                    : e.qty_after === 0
                                                      ? `${t('حُذف')} «${e.subject}»`
                                                      : `«${e.subject}» ${e.qty_before} ← ${e.qty_after}`}
                                            </p>
                                            <p className="mt-0.5 text-[#92400e]">{e.reason}</p>
                                            <p className="mt-1 text-[12px] text-[#a16207]">
                                                {/* الإجمالي يُذكر حين يتغيّر — وسيلةُ الدفع لا تمسّه */}
                                                {e.total_before !== e.total_after && (
                                                    <>
                                                        {money(e.total_before, currency)} ←{' '}
                                                        {money(e.total_after, currency)}
                                                        {' · '}
                                                    </>
                                                )}
                                                {e.by} · <span dir="ltr">{e.at}</span>
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        )}
                    </div>
                    <Card ref={sheetRef} className="p-6">
                        <div className="mb-5 flex flex-wrap items-start justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] pb-4">
                            <div>
                                <h3 className="flex items-center gap-2 font-bold text-[#111]">
                                    <ClipboardList className="size-4 text-[#9ca3af]" />
                                    {t('ورقة تفاصيل الطلب')}
                                </h3>
                                {/* الحدّ يُقال قبل الضغط لا بعده: من يفتح «تعديل» باحثًا عن
                                    المبلغ يجب أن يعرف أنه ليس هنا */}
                                <p className="mt-1 text-[12px] text-[#9ca3af]">
                                    {t('بيانات التنفيذ وحدها — لا تمسّ المبالغ ولا حالة الطلب')}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge status={order.status}>{t(order.status)}</Badge>
                                {actionable && (
                                    <Button variant="ghost" size="sm" onClick={() => setEditing((e) => !e)}>
                                        <PencilLine />
                                        {t(editing ? 'إلغاء' : 'تعديل')}
                                    </Button>
                                )}
                            </div>
                        </div>

                        {editing ? (
                            <form
                                className="grid grid-cols-1 gap-4 md:grid-cols-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.put(route('admin.orders.details.update', order.id), {
                                        preserveScroll: true,
                                        onSuccess: () => setEditing(false),
                                    });
                                }}
                            >
                                <Field label="نوع التنفيذ" error={form.errors.fulfillment_type}>
                                    <Select
                                        placeholder="—"
                                        options={order.fulfillments}
                                        value={form.data.fulfillment_type}
                                        onChange={(e) => form.setData('fulfillment_type', e.target.value)}
                                    />
                                </Field>
                                <Field label="موعد التسليم" error={form.errors.scheduled_for}>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.scheduled_for}
                                        onChange={(e) => form.setData('scheduled_for', e.target.value)}
                                    />
                                </Field>
                                <Field label="اسم المستلِم" required={isDelivery} error={form.errors.recipient_name}>
                                    <Input
                                        value={form.data.recipient_name}
                                        onChange={(e) => form.setData('recipient_name', e.target.value)}
                                    />
                                </Field>
                                <Field label="هاتف المستلِم" required={isDelivery} error={form.errors.recipient_phone}>
                                    <Input
                                        inputMode="tel"
                                        value={form.data.recipient_phone}
                                        onChange={(e) => form.setData('recipient_phone', e.target.value)}
                                    />
                                </Field>
                                {isDelivery && (
                                    <>
                                        <Field label="عنوان التوصيل" required error={form.errors.delivery_address}>
                                            <Input
                                                value={form.data.delivery_address}
                                                onChange={(e) => form.setData('delivery_address', e.target.value)}
                                            />
                                        </Field>
                                        <Field label="تعليمات التوصيل" error={form.errors.delivery_notes}>
                                            <Input
                                                value={form.data.delivery_notes}
                                                onChange={(e) => form.setData('delivery_notes', e.target.value)}
                                            />
                                        </Field>
                                    </>
                                )}
                                <Field label="المناسبة" error={form.errors.occasion_type}>
                                    <Select
                                        placeholder="—"
                                        options={order.occasions}
                                        value={form.data.occasion_type}
                                        onChange={(e) => form.setData('occasion_type', e.target.value)}
                                    />
                                </Field>
                                <Field label="اسم المُهدي" error={form.errors.sender_name}>
                                    <Input
                                        value={form.data.sender_name}
                                        onChange={(e) => form.setData('sender_name', e.target.value)}
                                    />
                                </Field>
                                <div className="md:col-span-2">
                                    <Field label="نصّ البطاقة" error={form.errors.card_message}>
                                        <textarea
                                            rows={3}
                                            value={form.data.card_message}
                                            onChange={(e) => form.setData('card_message', e.target.value)}
                                            className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                                        />
                                    </Field>
                                </div>
                                <Field label="إخفاء المُهدي" hint="لا يظهر للمستلِم">
                                    <label className="flex h-9 items-center gap-2 text-sm text-[#4b4b4b]">
                                        <input
                                            type="checkbox"
                                            checked={form.data.hide_sender}
                                            onChange={(e) => form.setData('hide_sender', e.target.checked)}
                                            className="size-4 accent-[#6d28d9]"
                                        />
                                        {t('إخفاء')}
                                    </label>
                                </Field>
                                <Field label="ملاحظات داخلية" hint="لا تُطبع للزبون" error={form.errors.internal_notes}>
                                    <Input
                                        value={form.data.internal_notes}
                                        onChange={(e) => form.setData('internal_notes', e.target.value)}
                                    />
                                </Field>

                                <div className="flex items-center gap-2 md:col-span-2">
                                    <Button type="submit" disabled={form.processing}>
                                        {t('حفظ')}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                                        {t('إلغاء')}
                                    </Button>
                                </div>
                            </form>
                        ) : hasSheet ? (
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <Block icon={Truck} title="التنفيذ والموعد">
                                    <Row label="نوع التنفيذ" value={fulfillmentLabel ? t(fulfillmentLabel) : null} />
                                    <Row
                                        label="موعد التسليم"
                                        value={order.scheduled_for?.replace('T', ' ') ?? null}
                                        ltr
                                    />
                                </Block>

                                <Block icon={Phone} title="المستلِم">
                                    <Row label="الاسم" value={order.recipient_name} />
                                    <Row label="الهاتف" value={order.recipient_phone} ltr />
                                </Block>

                                <Block icon={MapPin} title="العنوان والتعليمات">
                                    <Row label="عنوان التوصيل" value={order.delivery_address} />
                                    <Row label="تعليمات التوصيل" value={order.delivery_notes} />
                                </Block>

                                <Block icon={Gift} title="المناسبة والبطاقة">
                                    <Row label="المناسبة" value={occasionLabel ? t(occasionLabel) : null} />
                                    <Row
                                        label="اسم المُهدي"
                                        value={
                                            order.sender_name
                                                ? order.hide_sender
                                                    ? `${order.sender_name} · ${t('مخفيّ عن المستلِم')}`
                                                    : order.sender_name
                                                : null
                                        }
                                    />
                                    {order.card_message && (
                                        <div className="pt-3">
                                            <p className="mb-1 text-[13px] text-[#6b7280]">{t('نصّ البطاقة')}</p>
                                            {/* مرتَّبًا كما رتّبه صاحبُه — والأسطرُ جزءٌ ممّا كتب */}
                                            <p
                                                className="whitespace-pre-wrap rounded-[10px] bg-[#faf5ff] p-3 text-sm leading-relaxed text-[#4b4b4b]"
                                                style={{ textAlign: (order.card_align as 'right' | 'center' | 'left') || 'right' }}
                                            >
                                                {order.card_message}
                                            </p>
                                        </div>
                                    )}
                                    {order.card_file && (
                                        <div className="pt-3">
                                            <p className="mb-1 text-[13px] text-[#6b7280]">{t('ملفّ البطاقة')}</p>
                                            <a
                                                href={order.card_file}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-sm font-medium text-[#1d4ed8] underline"
                                            >
                                                {order.card_file_name || t('ملفٌّ مرفق')}
                                            </a>
                                        </div>
                                    )}
                                </Block>

                                {order.internal_notes && (
                                    <div className="md:col-span-2">
                                        <p className="rounded-[10px] bg-gray-50 p-3 text-[13px] text-[#6b7280]">
                                            {t('ملاحظات داخلية')} · {t('لا تُطبع للزبون')}: {order.internal_notes}
                                        </p>
                                    </div>
                                )}
                            </div>
                        ) : (
                            /* طلبٌ قديم بلا تفاصيل: يُقال له أين تُضاف بدل أن يختفي الباب */
                            <div className="py-10 text-center">
                                <ClipboardList className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                                <p className="text-sm text-[#6b7280]">{t('لا تفاصيل تنفيذ لهذا الطلب بعد')}</p>
                                {actionable && (
                                    <Button className="mt-4" variant="outline" onClick={() => setEditing(true)}>
                                        <PencilLine />
                                        {t('أضِف التفاصيل')}
                                    </Button>
                                )}
                            </div>
                        )}
                    </Card>
                </div>

                {/*
                    ═══ والورقةُ HTML لا PDF ═══

                    إطارُ PDF يحمل فوقه شريطَ قارئ المتصفّح — تنزيلٌ وطباعةٌ
                    وتكبيرٌ وقائمة — فيرى التاجر واجهتين: واجهةَ أبعاد
                    وواجهةَ القارئ. ويُشغَّل محرّكُ طباعةٍ كامل على الخادم
                    لكلّ من فتح الصفحة ليقرأ حالةَ طلب.

                    واللوحةُ مشتركةٌ مع أمر الشراء وسند الاستلام وفاتورة
                    العميل — انظر `Components/DocumentPanel`.
                */}
                {paper && (
                    <DocumentAside className="xl:col-span-2">
                        <DocumentPanel
                            html={paper.html}
                            size={paper.size}
                            url={route('admin.orders.pdf', order.id)}
                            filename={`${order.id}.pdf`}
                            label={`${t('الفاتورة')} ${order.id}`}
                            open={previewing}
                            onOpenChange={setPreviewing}
                        />
                    </DocumentAside>
                )}
            </div>

            {/*
                ونافذتا التصحيح من `Components/InvoiceCorrection` — هما اللتان
                يُصحّح بهما الكاشير حرفًا بحرف. ونسختان تفترقان يومًا.
            */}
            {correctingItem && (
                <CorrectItemDialog
                    url={route('admin.orders.items.update', [order.id, correctingItem.id])}
                    item={correctingItem}
                    onClose={() => setCorrectingItem(null)}
                />
            )}

            {fixingPayment && (
                <CorrectPaymentDialog
                    url={route('admin.orders.payment.update', order.id)}
                    current={order.payment}
                    methods={order.payment_methods}
                    onClose={() => setFixingPayment(false)}
                />
            )}
        </AdminLayout>
    );
}
