import { Link, router } from '@inertiajs/react';
import { RefreshCw, Webhook } from 'lucide-react';
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
}

const TONE: Record<LinkHealth['tone'], string> = {
    success: 'text-[#047857]',
    info: 'text-[#1d4ed8]',
    neutral: 'text-[#4b5563]',
    danger: 'text-[#b91c1c]',
};

/**
 * أرقامُ المتاجر المستقلّة — أيصلنا ما يكتبه زبائنُها؟
 *
 * الشاشةُ تقرأ ما خُزّن ولا تُنادي ميتا. والفحصُ الحيّ زرّان، كلاهما قراءةٌ عند
 * ميتا: «فحص الربط» لمتجرٍ بعينه، و«فحص إعداد تطبيق أبعاد» مرّةً للكلّ — عنوانُ
 * الإشعارات وحقلُ `messages` في التطبيق نفسه.
 */
export default function OwnNumbersHealth({ rows }: { rows: OwnNumberRow[] }) {
    const t = useTranslate();

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

            {rows.length === 0 ? (
                <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-[#6b7280]">{t('لا متجر مربوطٌ برقمه المستقل بعد.')}</p>
            ) : (
                <ul className="divide-y divide-[#f0f0f0]">
                    {rows.map((row) => (
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
                                    <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-[#9ca3af]">
                                        <div>
                                            <dt className="inline">{t('آخر حالة تسليم')}: </dt>
                                            <dd className="inline" dir="ltr">{row.health.last_status_at ?? '—'}</dd>
                                        </div>
                                        <div>
                                            <dt className="inline">{t('آخر رسالة عميل')}: </dt>
                                            <dd className="inline" dir="ltr">{row.health.last_inbound_at ?? '—'}</dd>
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
