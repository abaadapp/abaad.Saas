import { ChevronRight } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';

/**
 * الرجوع من صفحةٍ داخلية إلى بابها.
 *
 * وليس زرَّ متصفّح: `history.back()` يعود إلى ما كان قبلُ أيًّا كان — صفحةَ
 * إرسال نموذجٍ أُعيد التوجيه منها، أو موقعًا خارج النظام، أو لا شيء إن فُتحت
 * الصفحة من رابطٍ محفوظ. والوجهةُ هنا مكتوبةٌ باسمها فتصدق دائمًا.
 *
 * ويقع فوق الترويسة لا في أزرارها: الرجوع طريقٌ لا فعل، فلا يزاحم «حفظ»
 * و«تعديل» على النظر.
 */
export default function BackLink({
    routeName,
    href,
    label,
}: {
    routeName: string;
    href: string;
    label: string;
}) {
    const t = useTranslate();

    return (
        <SmartLink
            routeName={routeName}
            href={href}
            className="mb-3 inline-flex items-center gap-1 text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111]"
        >
            {/* السهم يشير إلى جهة الرجوع: يمينًا في العربية، ويُقلب في الإنجليزية */}
            <ChevronRight className="size-4 ltr:rotate-180" />
            {t(label)}
        </SmartLink>
    );
}
