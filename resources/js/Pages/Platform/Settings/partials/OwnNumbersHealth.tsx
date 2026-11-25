import { Link, router } from '@inertiajs/react';
import { RefreshCw, Webhook } from 'lucide-react';
import StatusPill from '@/Components/StatusPill';
import { BILLING_PILL, BILLING_STATES, type BillingState } from '@/Components/WhatsappBilling';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LinkHealth } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';

/** صفٌّ لكلّ متجرٍ على رقمه المستقلّ — انظر App\Support\WhatsAppLinkHealth::fleet. بلا رمزٍ ولا معرّف رقم. */
export interface OwnNumberRow {
    business_id: number;
    business: string;
    phone: string | null;
    inbox: boolean;
    health: LinkHealth;
    /** ورقمُ إرساله: معتمَد، أو ينتظر، أو تعطّل (`WhatsAppSender::brief`) */
    sender?: { state: 'checking' | 'adopted' | 'problem' | 'own' | 'shared'; text: string };
    /** وعلى من رسومُه عند Meta (`WhatsAppBillingLock::brief`) */
    billing?: { state: BillingState; label: string; locked: boolean; own_app: boolean; sends?: boolean; text: string | null } | null;
}

const TONE: Record<LinkHealth['tone'], string> = {
    success: 'text-[#047857]',
    info: 'text-[#1d4ed8]',
    neutral: 'text-[#4b5563]',
    danger: 'text-[#b91c1c]',
};

const unproven = (row: OwnNumberRow) => !!row.billing && row.billing.state !== 'proven';

/**
 * كم متجرًا لم تثبت جهةُ رسومه بعد — وكم منها يُرسل اليوم بلا إثبات.
 *
 * يُعدّ من الصفوف المعروضة تحته نفسِها، فلا يقول العددُ غيرَ ما تقوله القائمة.
 */
function BillingTally({ rows }: { rows: OwnNumberRow[] }) {
    const t = useTranslate();
    const linked = rows.filter((row) => row.billing);

    if (linked.length === 0) {
        return null;
    }

    const open = linked.filter(unproven);
    // متجرُ التجربة يُرسل بإذنها، وتطبيقُ التاجر له حارسُه — والمقصودُ هنا ما سبق القفل وحده
    const sending = open.filter((row) => row.billing?.sends && !row.billing.locked && !row.billing.own_app).length;

    return (
        <div className="mb-4 rounded-[12px] border border-[#e8e8e8] bg-[#fafafa] p-4" data-testid="billing-tally" data-unproven={open.length} data-total={linked.length}>
            <p className="text-[14px] font-semibold text-[#111]">
                {open.length === 0
                    ? t('ثبتت جهة رسوم واتساب عند كل المتاجر (:total)', { total: linked.length })
                    : t('لم تثبت جهة رسوم واتساب عند :count من :total من المتاجر', { count: open.length, total: linked.length })}
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
                {BILLING_STATES.map((state) => {
                    const same = linked.filter((row) => row.billing?.state === state);

                    return same.length === 0 ? null : (
                        <span key={state} data-testid="billing-tally-state" data-state={state} data-count={same.length}>
                            <StatusPill state={BILLING_PILL[state]} label={`${same[0].billing?.label}: ${same.length}`} />
                        </span>
                    );
                })}
            </div>
            {sending > 0 && (
                <p className="mt-2 text-[12px] leading-relaxed text-[#92400e]" data-testid="billing-tally-sending" data-count={sending}>
                    {t('منها :count تُرسل الآن لأنها رُبطت قبل القفل، ولا تتوقف إلا بقرارك. راجع جهة رسومها من صفحة المتجر.', { count: sending })}
                </p>
            )}
        </div>
    );
}

/**
 * أرقامُ المتاجر المستقلّة — أيصلنا ما يكتبه زبائنُها؟
 *
 * الشاشةُ تقرأ ما خُزّن ولا تُنادي ميتا. والفحصُ الحيّ زرّان، كلاهما قراءةٌ عند
 * ميتا: «فحص الربط» لمتجرٍ بعينه، و«فحص إعداد تطبيق أبعاد» مرّةً للكلّ — عنوانُ
 * الإشعارات وحقلُ `messages` في التطبيق نفسه.
 */
export default function OwnNumbersHealth({ rows }: { rows: OwnNumberRow[] }) {
    const t = useTranslate();
    // ومن لم تثبت جهةُ رسومه أوّلًا — وبقيّةُ الترتيب كما جاء
    const ordered = [...rows.filter(unproven), ...rows.filter((row) => !unproven(row))];

    return (
        <Card className="mt-6 p-6" data-testid="own-numbers-health">
            <div className="mb-1 flex flex-wrap items-center justify-between gap-3">
                <h3 className="text-[18px] font-bold text-[#111]">{t('أرقام المتاجر المستقلة')}</h3>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="app-webhook-check"
                    onClick={() => router.post(route('super-admin.whatsapp.app-webhook.check'), {}, { preserveScroll: true })}
                >
                    <Webhook />
                    {t('فحص إعداد تطبيق أبعاد')}
                </Button>
            </div>
            <p className="mb-4 text-[13px] leading-relaxed text-[#6b7280]">
                {t('«متصل» لا يعني «يستقبل»: حالة التسليم خبرٌ عمّا أرسله المتجر، ورسالة العميل وحدها تُثبت أنّ ما يكتبه الزبائن يصل. الفحص قراءة عند ميتا فقط.')}
            </p>

            <BillingTally rows={rows} />

            {rows.length === 0 ? (
                <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-[#6b7280]">{t('لا متجر مربوطٌ برقمه المستقل بعد.')}</p>
            ) : (
                <ul className="divide-y divide-[#f0f0f0]">
                    {ordered.map((row) => (
                        <li key={row.business_id} className="py-3" data-testid="own-number-row" data-state={row.health.key}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={route('super-admin.businesses.show', row.business_id)}
                                        className="font-semibold text-[#111] hover:underline"
                                    >
                                        {row.business}
                                    </Link>
                                    {row.phone && (
                                        <span className="ms-2 text-[12px] text-[#6b7280]" dir="ltr">
                                            {row.phone}
                                        </span>
                                    )}
                                    <p className={cn('mt-0.5 text-[13px] font-medium', TONE[row.health.tone])}>{t(row.health.label)}</p>
                                    <p className="mt-0.5 text-[12px] leading-relaxed text-[#6b7280]">{t(row.health.hint)}</p>
                                    {row.sender && (
                                        <p
                                            className={cn('mt-0.5 text-[12px] leading-relaxed', row.sender.state === 'problem' ? 'font-medium text-[#b91c1c]' : row.sender.state === 'checking' ? 'text-[#92400e]' : 'text-[#6b7280]')}
                                            data-testid="own-number-sender"
                                            data-state={row.sender.state}
                                        >
                                            {row.sender.text}
                                        </p>
                                    )}
                                    {row.billing && (
                                        <p className="mt-1 flex flex-wrap items-center gap-2 text-[12px] text-[#6b7280]" data-testid="own-number-billing" data-state={row.billing.state}>
                                            <span>{t('رسوم واتساب')}</span>
                                            <StatusPill state={BILLING_PILL[row.billing.state]} label={row.billing.label} />
                                            {!row.billing.locked && !row.billing.own_app && <span>{t('ربط من قبل القفل')}</span>}
                                            {row.billing.own_app && <span>{t('بتطبيقه الخاص')}</span>}
                                        </p>
                                    )}
                                    <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-[#9ca3af]">
                                        <div>
                                            <dt className="inline">{t('آخر حالة تسليم')}: </dt>
                                            <dd className="inline" dir="ltr">{row.health.last_status_at ?? '—'}</dd>
                                        </div>
                                        <div>
                                            <dt className="inline">{t('آخر رسالة عميل وصلت')}: </dt>
                                            <dd className="inline" dir="ltr">{row.health.last_inbound_at ?? '—'}</dd>
                                        </div>
                                        <div>
                                            <dt className="inline">{t('آخر رسالة حُفظت في المحادثات')}: </dt>
                                            <dd className="inline" dir="ltr">{row.health.last_inbound_saved_at ?? '—'}</dd>
                                        </div>
                                        <div>
                                            <dt className="inline">{t('صندوق المحادثات')}: </dt>
                                            <dd className="inline">{row.inbox ? t('مفتوح') : t('مغلق')}</dd>
                                        </div>
                                        {row.health.checked_at && (
                                            <div>
                                                <dt className="inline">{t('آخر فحص')}: </dt>
                                                <dd className="inline" dir="ltr">{row.health.checked_at}</dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    data-testid="own-number-check"
                                    onClick={() => router.post(route('super-admin.businesses.whatsapp.check', row.business_id), {}, { preserveScroll: true })}
                                >
                                    <RefreshCw />
                                    {t('فحص الربط')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
