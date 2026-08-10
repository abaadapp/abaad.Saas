import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';

/**
 * طريق العودة إلى لوحة الإعدادات.
 *
 * صارت الإعدادات لوحةَ بطاقات: تُفتح البطاقة مكان اللوحة، ويُرجَع بزرٍّ فوقها.
 * وخمس بطاقات في «النظام» تنقل إلى صفحاتٍ مستقلّة — الفروع والموظفون
 * والأجهزة وسجل النشاط والمحذوفات — وكانت هذه وحدها ما زالت تحمل العمود
 * الجانبي القديم. فيضغط المستخدم بطاقةً فيهبط في صفحةٍ بهيئةٍ أخرى لا وجود
 * لها في سواها، وطريقُ العودة فيها عمودٌ لم يعد له نظير.
 *
 * فصار الزرّ مكوّنًا واحدًا تحمله اللوحة وتلك الصفحات معًا: من دخل من بطاقة
 * يخرج من حيث دخل.
 *
 * وله وجهان: رابطٌ في الصفحات المستقلّة، وزرٌّ في اللوحة نفسها حيث يتبدّل
 * القسم في مكانه بلا تنقّل — والشكل واحد فلا تعرف العين فرقًا.
 */
const STYLE =
    'mb-5 inline-flex items-center gap-1.5 text-sm font-medium text-[#6b7280] transition-colors hover:text-[#111]';

/* السهم إلى اليمين في العربية، ويُقلب في الإنجليزية: الرجوع يتبع اتجاه القراءة */
const ARROW = <ChevronRight className="size-4 ltr:rotate-180" />;

export default function BackToSettings(
    props: { as?: 'link' } | { as: 'button'; onClick: () => void },
) {
    const t = useTranslate();

    if (props.as === 'button') {
        return (
            <button type="button" onClick={props.onClick} className={STYLE}>
                {ARROW}
                {t('كل الإعدادات')}
            </button>
        );
    }

    return (
        <Link href={route('admin.settings.index')} className={STYLE}>
            {ARROW}
            {t('كل الإعدادات')}
        </Link>
    );
}
