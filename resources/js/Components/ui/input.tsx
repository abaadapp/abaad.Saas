import * as React from 'react';
import { useAsciiDigits } from '@/lib/numerals';
import { cn } from '@/lib/utils';

/** أنواع التاريخ/الوقت — تُعالَج بشكل موحّد (اتجاه وقياس) في مكان واحد */
const DATETIME_TYPES = ['date', 'datetime-local', 'time', 'month', 'week'];

const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
    ({ className, type, dir, ...props }, ref) => {
        const attach = useAsciiDigits<HTMLInputElement>(ref);
        /*
         * حقول التاريخ/الوقت تُعرض LTR دائمًا: خاناتها (سنة/شهر/يوم) وأيقونة
         * التقويم تُقرأ يسارًا-يمينًا حتى في واجهة عربية. بلا ذلك تنقلب ترتيب
         * الخانات ويتبدّل موضع الأيقونة، فيختلف شكل الحقل عن أخواته — وهذا ما
         * كان يظهر في بعض النوافذ التي نسيت dir. يُضبط هنا مرّةً لكل الحقول،
         * ويبقى قابلًا للتجاوز بتمرير dir صراحةً.
         */
        const isDateTime = type !== undefined && DATETIME_TYPES.includes(type);

        return (
            <input
                type={type}
                ref={attach}
                dir={dir ?? (isDateTime ? 'ltr' : undefined)}
                className={cn(
                    'flex h-10 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2',
                    'text-sm text-[#111] placeholder:text-[#9ca3af]',
                    'transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none',
                    'focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                    'disabled:cursor-not-allowed disabled:bg-[#fafafa] disabled:opacity-60',
                    'file:border-0 file:bg-transparent file:text-sm file:font-medium',
                    /*
                     * تطبيع حقول التاريخ: min-w-0 كي لا يفرض عرضُها الجوهريّ
                     * (خانات yyyy/mm/dd + الأيقونة) قياسًا أدنى يكسر الشبكة أو
                     * يجعلها أعرض من الحقل المجاور. وأيقونة تقويمٍ واضحة قابلة
                     * للنقر بدل الباهتة الافتراضية.
                     */
                    isDateTime &&
                        'min-w-0 [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-70',
                    className,
                )}
                {...props}
            />
        );
    },
);
Input.displayName = 'Input';

const Textarea = React.forwardRef<
    HTMLTextAreaElement,
    React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => {
    const attach = useAsciiDigits<HTMLTextAreaElement>(ref);

    return (
        <textarea
            ref={attach}
            className={cn(
                'flex min-h-20 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2',
                'text-sm text-[#111] placeholder:text-[#9ca3af]',
                'transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none',
                'focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                'disabled:cursor-not-allowed disabled:bg-[#fafafa] disabled:opacity-60',
                className,
            )}
            {...props}
        />
    );
});
Textarea.displayName = 'Textarea';

export { Input, Textarea };
