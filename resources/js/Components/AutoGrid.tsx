import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface AutoGridProps {
    /**
     * أدنى عرضٍ للبطاقة قبل أن ينكسر عمودٌ جديد (مثل "15rem"). كلّما كبر،
     * قلّ عدد الأعمدة على العرض نفسه. القيمة تُمرَّر إلى الشبكة كـ--ui-col-min.
     */
    min?: string;
    className?: string;
    style?: CSSProperties;
    children: ReactNode;
}

/**
 * شبكة ذاتية التوزيع لمجموعة بطاقاتٍ متجانسة.
 *
 * بدل `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4` المربوطة بحجم الشاشة —
 * التي تهدر المساحة بين نقطتَي قطع وتترك بطاقاتٍ يتيمة في الصفّ الأخير —
 * تحسب هذه عددَ الأعمدة من الحيّز المتاح فعلًا: أكبر عددٍ يسعه بعرض `min`،
 * ثم تمدّ البطاقات لتملأ الصفّ. فلا فراغ يمينَه، ولا بطاقةٌ ناقصة، وتتكيّف
 * مع مكانها (عمودٌ ضيّق أو عرضٌ كامل) لا مع حجم الشاشة. المنطق كلّه في
 * .ui-autogrid بـ app.css، وهذا غلافٌ يمرّر العرض الأدنى ويضمّ أي أصناف.
 */
export default function AutoGrid({ min = '16rem', className, style, children }: AutoGridProps) {
    return (
        <div
            className={cn('ui-autogrid', className)}
            style={{ '--ui-col-min': min, ...style } as CSSProperties}
        >
            {children}
        </div>
    );
}
