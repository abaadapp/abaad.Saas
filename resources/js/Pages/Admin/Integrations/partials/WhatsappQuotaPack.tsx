import { router } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/** عرضُ الحزمة وحالُ طلب هذا المتجر — من `WhatsAppPacks::view` */
export interface QuotaPack {
    size: number;
    price: number;
    /** مطلوبة | مصدَّرة — و`null` إن لا طلبَ مفتوح */
    status: string | null;
    invoice_number: string | null;
    requested_at: string | null;
}

/**
 * ما بعد الحصّة — رسائلُ تُشترى، لا شهرٌ يُنتظر.
 *
 * ═══ ولمَ ليس زرَّ «ادفع الآن» ═══
 *
 * لا بوّابةَ دفعٍ في المنتج. فزرٌّ يقول «ادفع» يأخذ ضغطةً ولا يأخذ مالًا،
 * ثمّ ينظر التاجر إلى رصيدٍ لم يزد فيظنّ العطبَ في النظام. وهذا يقول ما
 * يقع فعلًا في ثلاث جُمَل: تطلب، تصلك فاتورة، يُضاف الرصيد فور السداد.
 *
 * ═══ وثلاثُ حالاتٍ لا اثنتان ═══
 *
 * «لا طلبَ لك» غيرُ «طلبُك عندنا» غيرُ «فاتورتُك صدرت وننتظر التحويل».
 * ولو جُمعت الأخيرتان في «قيد المعالجة» لَما عرف التاجر أعليه فعلٌ أم لا —
 * وفي إحداهما عليه أن يُحوّل، وفي الأخرى ليس عليه شيء.
 */
export default function WhatsappQuotaPack({
    pack,
    exhausted,
    mayManage,
}: {
    pack: QuotaPack;
    /** نفدت العطيّة والرصيد معًا — فلا تخرج رسالة */
    exhausted: boolean;
    mayManage: boolean;
}) {
    const t = useTranslate();

    if (pack.status === 'مصدَّرة') {
        return (
            <p className="mt-3 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]">
                {t('صدرت فاتورتك :inv — ويُضاف الرصيد فور تسجيل السداد.', {
                    inv: pack.invoice_number ?? '—',
                })}
            </p>
        );
    }

    if (pack.status === 'مطلوبة') {
        return (
            <p className="mt-3 rounded-[10px] bg-[#f5f3ff] px-3 py-2 text-[12px] leading-relaxed text-[#6d28d9]">
                {t('وصلنا طلبُك — تصلك الفاتورة قريبًا، ولا شيء عليك الآن.')}
            </p>
        );
    }

    return (
        <div
            className={`mt-3 rounded-[10px] px-3 py-3 ${
                exhausted ? 'bg-[#fffbeb] text-[#b45309]' : 'bg-[#fafafa] text-[#6b7280]'
            }`}
        >
            <p className="text-[12px] leading-relaxed">
                {exhausted
                    ? t('نفدت رسائل هذا الشهر — الطلبات تعمل كالمعتاد، ولا تخرج رسالة. اطلب حزمةً الآن أو انتظر الشهر الجديد.')
                    : t('وإن نفدت قبل الشهر الجديد، تُشترى حزمةٌ إضافية ولا تسقط بقيّتُها آخر الشهر.')}
            </p>

            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <span className="text-[13px] font-medium text-[#111]" dir="ltr">
                    {pack.size} {t('رسالة')} · {pack.price.toFixed(3)} {t('ر.ع')}
                </span>

                {/*
                    والزرُّ لصاحب الحساب أو مديره: هذا التزامٌ بمال، لا
                    إعدادُ عرضٍ يُبدّله أيُّ موظّف.
                */}
                {mayManage && (
                    <Button
                        type="button"
                        variant={exhausted ? 'primary' : 'outline'}
                        onClick={() => router.post(route('admin.integrations.whatsapp.packs.request'), {}, { preserveScroll: true })}
                    >
                        <ShoppingCart />
                        {t('اطلب حزمة رسائل')}
                    </Button>
                )}
            </div>
        </div>
    );
}
