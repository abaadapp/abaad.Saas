import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, XCircle } from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** عميلٌ لم تصله رسالتُه — برقمٍ محجوب (`WhatsAppHealth::delivery`) */
export interface DeliveryFailure {
    phone: string;
    count: number;
    event: string;
    template: string | null;
    sent_at: string | null;
    failed_at: string;
    code: string | null;
    error: string | null;
    explain: string | null;
    ours: boolean;
    status_label: string;
}

/** حالُ التسليم في آخر يوم — من الخادم وحده (`WhatsAppHealth::view`) */
export interface Delivery {
    state: 'untested' | 'unconfirmed' | 'ok' | 'partial' | 'failing';
    window_hours: number;
    attempts: number;
    delivered: number;
    failed: number;
    pending: number;
    customers: number;
    failures: DeliveryFailure[];
    /** ردودُ المحادثات — لمن يرى المحادثات وحده */
    replies: { attempts: number; delivered: number; failed: number } | null;
    log_url: string | null;
}

/**
 * تسليمُ الرسائل — مستقلٌّ عن مراحل الربط.
 *
 * عميلٌ لا يستقبل رقمُه ليس عطبًا في الربط، فيُقال هنا باسمه (محجوبًا)
 * وسببِه، ولا تُطفأ له المرحلة. وحين لا يصل شيءٌ يُقال ذلك بلونه.
 */
export default function WhatsappDelivery({ delivery }: { delivery: Delivery }) {
    const t = useTranslate();
    const d = delivery;

    const tone = {
        ok: { box: 'border-[#bbf7d0] bg-[#f0fdf4]', text: 'text-[#166534]', Icon: CheckCircle2 },
        partial: { box: 'border-[#fde68a] bg-[#fffbeb]', text: 'text-[#92400e]', Icon: AlertTriangle },
        failing: { box: 'border-[#fecaca] bg-[#fef2f2]', text: 'text-[#991b1b]', Icon: XCircle },
        unconfirmed: { box: 'border-[#e5e7eb] bg-[#fafafa]', text: 'text-[#b45309]', Icon: Clock },
        untested: { box: 'border-[#e5e7eb] bg-[#fafafa]', text: 'text-[#4b5563]', Icon: Clock },
    }[d.state];

    const title = {
        ok: t('التسليم يعمل'),
        partial: t('واتساب يعمل — بعض الرسائل لم تصل إلى عملائها'),
        failing: t('لم تصل أيٌّ من رسائل آخر يوم'),
        unconfirmed: t('لم يُؤكَّد التسليم بعد'),
        untested: t('لم تُختبر عملية التسليم مؤخرًا'),
    }[d.state];

    const line = {
        ok: t('أكّد واتساب وصول :n من :t رسالة في آخر 24 ساعة.', { n: d.delivered, t: d.attempts }),
        partial: t('الربط يعمل: وصلت :n رسالة، ولم تصل :f رسالة إلى :c عميل. تحقّق من أرقامهم أو تواصل معهم بطريقة أخرى.', {
            n: d.delivered,
            f: d.failed,
            c: d.customers,
        }),
        failing: t('قد يكون الاتصال بـ Meta قائمًا، لكنّ التسليم لا ينجح: لم تصل :f رسالة، ولم يُؤكَّد وصول أيّ رسالة.', { f: d.failed }),
        unconfirmed: t('خرجت :n رسالة ولم يصل من واتساب ما يؤكّد تسليمها — لا تُعدّ واصلةً قبل التأكيد.', { n: d.attempts }),
        untested: t('لم تخرج رسالةٌ إلى عميل في آخر 24 ساعة — فلا نقول إنّها تصل ولا إنّها لا تصل.'),
    }[d.state];

    return (
        <Card className={cn('border p-5 sm:p-6', tone.box)} asChild>
            <section aria-label={t('تسليم الرسائل')}>
                <div className="flex items-start gap-3">
                    <tone.Icon className={cn('mt-0.5 size-5 shrink-0', tone.text)} />
                    <div className="min-w-0 flex-1">
                        <h2 className={cn('font-bold', tone.text)}>{title}</h2>
                        <p className="mt-0.5 text-[13px] leading-relaxed text-[#374151]">{line}</p>

                        {d.attempts > 0 && (
                            <p className="mt-2 text-[12px] text-[#6b7280]">
                                {t('وصلت')}: {d.delivered} · {t('لم تصل')}: {d.failed} · {t('في الطريق')}: {d.pending}
                                {d.customers > 0 && <> · {t('عملاء لم تصلهم رسائلهم')}: {d.customers}</>}
                            </p>
                        )}
                    </div>
                </div>

                {d.failures.length > 0 && (
                    <ul className="mt-4 divide-y divide-[var(--ui-border,#e8e8e8)] rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                        {d.failures.map((f) => (
                            <li key={f.phone + f.failed_at} className="px-4 py-3 text-[12px] leading-relaxed">
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span dir="ltr" className="font-mono text-[13px] font-medium text-[#111]">{f.phone}</span>
                                    <span className="text-[#6b7280]">{t(':n رسالة', { n: f.count })}</span>
                                    <span className="rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] font-medium text-[#b91c1c]">{f.status_label}</span>
                                </div>
                                <p className="mt-1 text-[#374151]">
                                    {f.event}
                                    {f.template && <> · <span dir="ltr" className="font-mono text-[11px]">{f.template}</span></>}
                                </p>
                                <p className="text-[#6b7280]">
                                    {f.sent_at && <>{t('أُرسلت')} <span dir="ltr">{f.sent_at}</span> · </>}
                                    {t('فشلت')} <span dir="ltr">{f.failed_at}</span>
                                </p>
                                {(f.code || f.error) && (
                                    <p className="text-[#6b7280]">
                                        {t('رمز Meta')}: <span dir="ltr">{[f.code, f.error].filter(Boolean).join(' — ')}</span>
                                    </p>
                                )}
                                {f.explain && <p className={cn('mt-0.5', f.ours ? 'text-[#b45309]' : 'text-[#b91c1c]')}>{f.explain}</p>}
                            </li>
                        ))}
                    </ul>
                )}

                {d.customers > d.failures.length && (
                    <p className="mt-2 text-[12px] text-[#6b7280]">
                        {t('و:n عميلًا آخر في سجلّ الرسائل.', { n: d.customers - d.failures.length })}
                    </p>
                )}

                {/* وردودُ الموظّفين تُعدّ وحدها: ردٌّ وصل لا يقول إنّ إشعار الطلب يصل */}
                {d.replies && d.replies.attempts > 0 && (
                    <p className="mt-3 text-[12px] text-[#374151]">
                        {t('ردود المحادثات في آخر 24 ساعة: أكّد واتساب وصول :n من :t.', {
                            n: d.replies.delivered,
                            t: d.replies.attempts,
                        })}
                        {d.replies.failed > 0 && <> {t('ولم يصل :f.', { f: d.replies.failed })}</>}
                    </p>
                )}

                {d.log_url && d.failed > 0 && (
                    <Link href={d.log_url} className="mt-3 inline-block text-[12px] font-medium text-[#111] underline underline-offset-2">
                        {t('عرض الرسائل التي لم تصل في السجلّ')}
                    </Link>
                )}
            </section>
        </Card>
    );
}
