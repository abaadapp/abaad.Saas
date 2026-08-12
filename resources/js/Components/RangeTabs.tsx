import { router } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

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
 */
export default function RangeTabs({ current, only }: Props) {
    const t = useTranslate();

    const go = (range: ReportRange) => {
        if (range === current) return;
        router.get(window.location.pathname, { range }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
        });
    };

    return (
        <div className="mb-4 inline-flex rounded-full bg-[#f5f5f4] p-1">
            {RANGES.map((r) => (
                <button
                    key={r.key}
                    type="button"
                    onClick={() => go(r.key)}
                    className={cn(
                        'rounded-full px-4 py-1.5 text-[13px] font-medium transition-colors',
                        r.key === current ? 'bg-white text-[#111] shadow-sm' : 'text-[#6b7280] hover:text-[#111]',
                    )}
                >
                    {t(r.label)}
                </button>
            ))}
        </div>
    );
}
