import { router } from '@inertiajs/react';
import Tabs from '@/Components/Tabs';

export type ReportRange = 'today' | 'week' | 'month' | 'year' | 'all';

/** الفترات كما يفهمها الخادم — يجب أن تطابق Demo::RANGES */
const RANGES: { key: ReportRange; label: string }[] = [
    { key: 'today', label: 'اليوم' },
    { key: 'week', label: 'الأسبوع' },
    { key: 'month', label: 'الشهر' },
    { key: 'year', label: 'السنة' },
    { key: 'all', label: 'الكل' },
];

interface Props {
    current: ReportRange;
    /** أعمدة الصفحة كلّها تُعاد من الخادم — الفترة تغيّر الأرقام لا شكلها */
    only?: string[];
}

/**
 * مبدّل فترة التقرير.
 *
 * كانت كل شاشة تقرير تقرأ فترتها المخفيّة: البطاقات تجمع عمر المتجر كلّه،
 * والمخطّط تحتها يرسم السنة الجارية، والمقارنة تقيس شهرًا بشهر — ثلاث فترات
 * في شاشةٍ واحدة ولا شيء يقول ذلك. فصارت واحدةً يختارها التاجر ويقرأ الجميعُ
 * منها.
 *
 * والفترة في الرابط لا في الجلسة: رابطٌ يُرسَل أو يُحفَظ يفتح على ما فُتح
 * عليه، ولا تتبدّل شاشة أحدٍ لأن آخر بدّلها من جهازٍ ثانٍ.
 *
 * وشكلُه شكلُ تبويبات النظام: كان شريط حبّاتٍ مرسومًا باليد هنا، وهو النوع
 * الذي أُلغي حين وُحّدت التبويبات على الخطّ السفليّ. فعاد المبدّل ولم يعد
 * شكلُه معه — يُبنى على `Tabs` فلا يفترق عمّا حوله مرّةً أخرى.
 */
export default function RangeTabs({ current, only }: Props) {
    const go = (range: string) => {
        if (range === current) return;

        router.get(window.location.pathname, { range }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
        });
    };

    return <Tabs className="mb-4" current={current} onChange={go} tabs={RANGES} />;
}
