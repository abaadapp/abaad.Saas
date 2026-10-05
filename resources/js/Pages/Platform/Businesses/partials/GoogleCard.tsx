import { MapPin, Star } from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface BusinessGoogle {
    maps: { hasKey: boolean; enabled: boolean; linkedBranches: number; branches: number };
    business: {
        configured: boolean;
        connected: boolean;
        reconnect: boolean;
        locations: number;
        syncedAt: string | null;
        error: boolean;
    };
}

/**
 * Google هذا المتجر — حالٌ تُقرأ، لا إعدادٌ يُضبط.
 *
 * يربطه التاجر بنفسه: مفتاحُ الخرائط من مشروعه في Google Cloud، وحسابُ ملفّ
 * الأعمال بإذنه هو. فلا حقلَ هنا ولا زرّ، ولا مفتاحَ ولا آخرَ أحرفه ولا رمز —
 * نعم أو لا، وأين انقطع، ليعرف مدير المنصّة ما يقول لمن يتّصل.
 */
export default function GoogleCard({ data }: { data: BusinessGoogle }) {
    const t = useTranslate();

    const row = (label: string, ok: boolean, value: string) => (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <dt className="text-[#6b7280]">{t(label)}</dt>
            <dd className={cn('font-medium', ok ? 'text-[#166534]' : 'text-[#6b7280]')}>{value}</dd>
        </div>
    );

    const gbp = data.business;

    return (
        <Card className="mb-6 p-6" data-testid="business-google">
            <h3 className="mb-1 font-bold text-[#111]">{t('Google')}</h3>
            <p className="mb-4 text-[13px] text-[#6b7280]">
                {t('يربطه التاجر بنفسه من لوحته — المنصة ترى الحال فقط، ولا ترى مفتاحًا ولا رمزًا.')}
            </p>

            <div className="grid gap-4 md:grid-cols-2">
                <dl className="space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                    <p className="mb-1 flex items-center gap-1.5 font-bold text-[#111]">
                        <MapPin className="size-4" />
                        {t('خرائط Google')}
                    </p>
                    {row('مفتاح التاجر', data.maps.hasKey, data.maps.hasKey ? t('محفوظ') : t('لا يوجد'))}
                    {row('الحال', data.maps.enabled, data.maps.enabled ? t('مفعّلة') : t('متوقفة'))}
                    {row(
                        'الفروع المربوطة',
                        data.maps.branches > 0 && data.maps.linkedBranches === data.maps.branches,
                        `${data.maps.linkedBranches} / ${data.maps.branches}`,
                    )}
                </dl>

                <dl className="space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                    <p className="mb-1 flex items-center gap-1.5 font-bold text-[#111]">
                        <Star className="size-4" />
                        {t('تقييمات Google')}
                    </p>
                    {row(
                        'حساب Google',
                        gbp.connected,
                        !gbp.configured
                            ? t('غير متاحة على المنصة')
                            : gbp.connected
                              ? t('مربوط')
                              : gbp.reconnect
                                ? t('انقطع الربط')
                                : t('غير مربوط'),
                    )}
                    {row('المواقع المربوطة', gbp.locations > 0, String(gbp.locations))}
                    {row('آخر سحب', !gbp.error && gbp.syncedAt !== null, gbp.error ? t('تعثّر') : (gbp.syncedAt ?? '—'))}
                </dl>
            </div>
        </Card>
    );
}
