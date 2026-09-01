import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export interface ReportTab {
    key: string;
    label: string;
    href: string;
}

interface Props {
    tabs: ReportTab[];
    /** مفتاح التقرير المفتوح — يُبرز تبويبه ولا يُجعل رابطًا إلى نفسه */
    current: string;
}

/**
 * التنقّل بين التقارير — شريطٌ فوق كل تقريرٍ له صفحة.
 *
 * صار لكل تقريرٍ صفحته، ولولا هذا الشريط لَصار كلُّ انتقالٍ بينها رجوعًا
 * إلى الفهرس ثمّ اختيارًا من جديد: خطوتان بدل واحدة، ومن يقارن وسائل الدفع
 * بأداء الموظفين يفعلها عشر مرّات.
 *
 * والتبويبات تأتي من الخادم مصفّاةً بصلاحية صاحبها وباقته (Support\Reports)،
 * فلا يُعرض تبويبٌ يقود إلى ٤٠٣ — والبابُ المعروضُ الذي لا يُفتح أسوأ من
 * بابٍ لا يُعرض.
 *
 * ويُمرَّر أفقيًّا لا يُلفّ: خمسة تقارير على شاشة هاتفٍ تصير ثلاثة صفوفٍ
 * تدفع المحتوى إلى أسفل الطيّة.
 */
export default function ReportTabs({ tabs, current }: Props) {
    // تبويبٌ واحد ليس تنقّلًا: لا يُرسم شريطٌ لا يقود إلى شيء
    if (tabs.length < 2) return null;

    return (
        <nav
            className="mb-5 -mx-1 flex gap-1 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            aria-label="التقارير"
        >
            {tabs.map((tab) => {
                const active = tab.key === current;

                return active ? (
                    <span
                        key={tab.key}
                        aria-current="page"
                        className="flex h-9 shrink-0 items-center rounded-full bg-[#111] px-4 text-[13px] font-medium text-white"
                    >
                        {tab.label}
                    </span>
                ) : (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        className={cn(
                            'flex h-9 shrink-0 items-center rounded-full px-4 text-[13px] font-medium transition-colors',
                            'border border-[var(--ui-border,#e8e8e8)] bg-white text-[#6b7280]',
                            'hover:border-[#d4d4d4] hover:text-[#111]',
                        )}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
