import BackLink from '@/Components/BackLink';

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
    return (
        <BackLink
            routeName="admin.reports.index"
            href={route('admin.reports.index')}
            label="كل التقارير"
        />
    );
}
