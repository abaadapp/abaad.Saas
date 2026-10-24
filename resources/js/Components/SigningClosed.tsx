import { ShieldAlert } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';

/**
 * بلا مزوّدٍ يوصل رمزَ التحقق إلى هاتف العميل: الروابطُ تُصدر ولا تُوقَّع.
 *
 * يُقال للمدير قبل أن يرسل رابطًا لا يُوقَّع — انظر `SigningCodes::available`.
 */
export default function SigningClosed() {
    const t = useTranslate();

    return (
        <div
            className="mb-4 flex items-start gap-2 rounded-[10px] border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e]"
            data-testid="signing-closed"
        >
            <ShieldAlert className="mt-0.5 size-4 shrink-0" />
            {t('التوقيع مغلق: لم تُربط بعد خدمة ترسل رمز التحقق إلى هاتف العميل. تُصدر الروابط، لكن لا يستطيع العميل التوقيع حتى تُفعَّل.')}
        </div>
    );
}
