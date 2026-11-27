import { Link, router } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** طلبُ المحادثة الآليّ — `WhatsAppOrders::forChat` */
export interface GuidedOrder {
    id: number;
    state: 'collecting' | 'confirming' | 'awaiting_payment' | 'paid' | 'cancelled' | 'needs_human';
    open: boolean;
    step: string | null;
    total: number | null;
    reason: string | null;
    error: string | null;
    items: number;
    invoice: { id: number; number: string } | null;
    link: { status: string; open: boolean; url: string } | null;
    /** أين وصلت رسالتا الرابط والتأكيد — `sending` أو حالٌ من `WhatsAppStatus`، و`null` لما لم يُرسل */
    link_message: string | null;
    paid_message: string | null;
    can_resend_link: boolean;
    /** محاولةُ رابطٍ لم تُعرف نتيجتُها عند Paymob — الإعادةُ تطلب جديدًا بقرارٍ صريح */
    link_uncertain: boolean;
    can_resend_paid: boolean;
    updated_at: string | null;
}

/** الحالُ بكلماتها ولونها — كما طلبها المالك: خمسُ حالاتٍ يقرؤها الموظّف */
export const STATES: Record<GuidedOrder['state'], { label: string; tone: string }> = {
    collecting: { label: 'جمع المعلومات', tone: 'bg-[#eff6ff] text-[#1d4ed8]' },
    confirming: { label: 'بانتظار تأكيد العميل', tone: 'bg-[#fefce8] text-[#a16207]' },
    awaiting_payment: { label: 'بانتظار الدفع', tone: 'bg-[#fff7ed] text-[#c2410c]' },
    paid: { label: 'مدفوع — في التجهيز', tone: 'bg-[#f0fdf4] text-[#15803d]' },
    cancelled: { label: 'ملغى', tone: 'bg-[#f3f4f6] text-[#4b5563]' },
    needs_human: { label: 'يحتاج تدخلًا بشريًا', tone: 'bg-[#fef2f2] text-[#b91c1c]' },
};

/** لماذا حُوِّل لموظّف — `WhatsAppOrders::REASON_*` */
const REASONS: Record<string, string> = {
    asked: 'طلب العميل التحدث مع موظف',
    special_request: 'طلب خاص لا يُسعَّر آليًا',
    staff: 'تسلّمها موظف',
    no_branch: 'لا فرع للاستلام',
    no_dates: 'لا مواعيد متاحة',
    empty_catalog: 'لا أصناف متاحة للطلب',
    cancel_after_link: 'طلب الإلغاء بعد صدور الفاتورة',
    link_closed: 'انتهى رابط الدفع أو أُلغي',
    invoice_failed: 'تعذّر إصدار الفاتورة آليًا',
    link_failed: 'تعذّر إنشاء رابط الدفع',
};

/** حالُ رسالةٍ للعميل — وما لم يصله بالأحمر */
const MESSAGE: Record<string, { label: string; bad: boolean }> = {
    sending: { label: 'تُرسل الآن', bad: false },
    queued: { label: 'في الطابور', bad: false },
    sent: { label: 'أُرسلت', bad: false },
    delivered: { label: 'وصلت', bad: false },
    read: { label: 'قُرئت', bad: false },
    failed: { label: 'لم تصل', bad: true },
    skipped: { label: 'لم تُرسل', bad: true },
    quota_exceeded: { label: 'لم تُرسل', bad: true },
};

function MessageState({ label, status, testId }: { label: string; status: string | null; testId: string }) {
    const t = useTranslate();
    const known = status ? MESSAGE[status] : undefined;
    if (!known) return null;

    return (
        <span className={known.bad ? 'font-semibold text-[#b91c1c]' : 'text-[#6b7280]'} data-testid={testId} data-status={status}>
            {t(label)}: {t(known.label)}
        </span>
    );
}

/**
 * حالُ الطلب الآليّ فوق المحادثة — وما يفعله الموظّف به.
 *
 * «تسلّم المحادثة» يُسكت الآليَّ فيها، و«إنهاء الطلب الآلي» يعيده يستقبل من
 * يكتب «طلب». ولا يمسّ أيٌّ منهما فاتورةً أو رابطًا صدرا: يُداران من صفحتهما.
 *
 * وما لم يصل العميلَ من رسالتَي الرابط والتأكيد يُعاد من هنا — الرابطُ نفسُه،
 * والتأكيدُ رسالةً وحدها (`WhatsAppOrders::resendLink` و`resendPaid`).
 */
export default function GuidedOrderBar({ chatId, order, canReply }: { chatId: number; order: GuidedOrder; canReply: boolean }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const state = STATES[order.state];

    const post = (
        name:
            | 'admin.whatsappInbox.order.takeOver'
            | 'admin.whatsappInbox.order.close'
            | 'admin.whatsappInbox.order.resendLink'
            | 'admin.whatsappInbox.order.resendPaid',
        data: Record<string, boolean> = {},
    ) => router.post(route(name, chatId), data, { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] bg-white px-4 py-2 text-[12px]" data-testid="guided-order">
            <ShoppingBag size={15} className="text-[#6b7280]" />
            <span className="text-[#6b7280]">{t('الطلب الآلي')}</span>
            <span className={cn('rounded-full px-2 py-0.5 font-semibold', state.tone)} data-testid="guided-order-state">{t(state.label)}</span>

            {order.total !== null && (
                <span className="text-[#374151]" dir="ltr">{order.total.toFixed(3)}</span>
            )}
            {order.invoice && (
                <Link href={route('admin.customerInvoices.show', order.invoice.id)} className="text-[#6d28d9] hover:underline" dir="ltr" data-testid="guided-order-invoice">
                    {order.invoice.number}
                </Link>
            )}
            {order.link && (
                <span className={order.link.open ? 'text-[#15803d]' : 'text-[#b91c1c]'}>
                    {order.link.open ? t('رابط الدفع مفتوح') : t('رابط الدفع مغلق')}
                </span>
            )}
            {order.state === 'needs_human' && order.reason && REASONS[order.reason] && (
                <span className="text-[#b91c1c]" data-testid="guided-order-reason">{t(REASONS[order.reason])}</span>
            )}
            {order.link_uncertain && (
                <span className="font-semibold text-[#a16207]" data-testid="guided-order-link-uncertain">
                    {t('لم تُعرف نتيجة إنشاء الرابط عند Paymob')}
                </span>
            )}
            <MessageState label="رسالة الرابط" status={order.link_message} testId="guided-order-link-message" />
            <MessageState label="رسالة التأكيد" status={order.paid_message} testId="guided-order-paid-message" />

            {canReply && order.can_resend_paid && (
                <span className="ms-auto flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-testid="guided-order-resend-paid"
                        onClick={async () => {
                            const yes = await ask({
                                title: 'إعادة إرسال تأكيد الدفع',
                                message: 'تُرسل رسالة تأكيد الدفع إلى العميل مرة أخرى. لا يتغير شيء في التحصيل ولا في لوحة التجهيز.',
                                action: 'إرسال',
                            });
                            if (yes) post('admin.whatsappInbox.order.resendPaid');
                        }}
                    >
                        {t('إعادة إرسال التأكيد')}
                    </Button>
                </span>
            )}

            {canReply && order.open && (
                <span className="ms-auto flex gap-2">
                    {order.can_resend_link && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            data-testid="guided-order-resend-link"
                            onClick={async () => {
                                const yes = await ask(
                                    order.link_uncertain
                                        ? {
                                              title: 'طلب رابط جديد',
                                              message: 'لم تُعرف نتيجة المحاولة السابقة عند Paymob، وقد يكون رابطها أُنشئ هناك دون أن يصلنا أو يصل العميل. يُطلب رابط جديد على الفاتورة نفسها ويُرسل وحده للعميل.',
                                              action: 'طلب وإرسال',
                                          }
                                        : {
                                              title: 'إعادة إرسال رابط الدفع',
                                              message: 'يُرسل رابط الدفع نفسه إلى العميل، وتُصدر الفاتورة أو الرابط إن تعذّرا من قبل. لا فاتورة ثانية ولا رابط ثانٍ.',
                                              action: 'إرسال',
                                          },
                                );
                                if (yes) post('admin.whatsappInbox.order.resendLink', order.link_uncertain ? { replace_uncertain: true } : {});
                            }}
                        >
                            {t('إعادة إرسال الرابط')}
                        </Button>
                    )}
                    {order.state !== 'needs_human' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            data-testid="guided-order-take-over"
                            onClick={async () => {
                                const yes = await ask({
                                    title: 'تسلّم المحادثة',
                                    message: 'يتوقف الطلب الآلي عن الرد في هذه المحادثة حتى تُنهيه. تكمل أنت مع العميل من هنا.',
                                    action: 'تسلّم',
                                });
                                if (yes) post('admin.whatsappInbox.order.takeOver');
                            }}
                        >
                            {t('تسلّم المحادثة')}
                        </Button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-testid="guided-order-close"
                        onClick={async () => {
                            const yes = await ask({
                                title: 'إنهاء الطلب الآلي',
                                message: 'يُغلق الطلب الآلي في هذه المحادثة، ويعود الآلي يستقبل من يكتب «طلب». الفاتورة ورابط الدفع إن صدرا يبقيان كما هما.',
                                action: 'إنهاء',
                            });
                            if (yes) post('admin.whatsappInbox.order.close');
                        }}
                    >
                        {t('إنهاء الطلب الآلي')}
                    </Button>
                </span>
            )}
            {dialog}
        </div>
    );
}
