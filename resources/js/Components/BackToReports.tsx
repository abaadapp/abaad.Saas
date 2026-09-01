import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';

/**
 * الرجوع إلى فهرس التقارير.
 *
 * وحلّ محلّ شريط تبويباتٍ يعرض التقارير الخمسة عشر كلَّها فوق كلّ صفحة:
 * صفٌّ يفيض عن الشاشة ويُمرَّر أفقيًّا، ويأخذ من ارتفاع الطيّة أكثر ممّا
 * يعطي — والقارئ جاء ليقرأ تقريرًا لا ليختار غيره.
 *
 * والباب واحد: الفهرس. منه تُفتح التقارير كلّها، وإليه يُرجع بضغطة.
 */
export default function BackToReports() {
    const t = useTranslate();

    return (
        <Link
            href={route('admin.reports.index')}
            className="mb-3 inline-flex items-center gap-1 text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111]"
        >
            {/* السهم يشير إلى جهة الرجوع: يمينًا في العربية، ويُقلب في الإنجليزية */}
            <ChevronRight className="size-4 ltr:rotate-180" />
            {t('كل التقارير')}
        </Link>
    );
}
