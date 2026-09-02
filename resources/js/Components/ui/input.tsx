import * as React from 'react';
import { useAsciiDigits } from '@/lib/numerals';
import { cn } from '@/lib/utils';

/** أنواع التاريخ/الوقت — تُعالَج بشكل موحّد (اتجاه وقياس) في مكان واحد */
const DATETIME_TYPES = ['date', 'datetime-local', 'time', 'month', 'week'];

/**
 * هل يقبل هذا الحقل كسورًا؟ — يُقرأ من `step` لا من قائمةٍ تُكتب باليد.
 *
 * حقولُ المال في النظام كلِّها تحمل `step="0.001"` أو ما يشبهها، والكميّاتُ
 * تحمل `1` أو لا تحمل شيئًا. وقائمةٌ بأسماء الحقول العشريّة تنسى التاليَ
 * دائمًا — ويُضاف حقلُ سعرٍ فلا يقبل فاصلته، ولا يُكتشف إلّا في أمر شراء.
 */
function fractional(step: React.InputHTMLAttributes<HTMLInputElement>['step']): boolean {
    if (step === 'any') {
        return true;
    }

    const n = Number(step);

    return Number.isFinite(n) && n > 0 && n < 1;
}

const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
    ({ className, type, dir, ...props }, ref) => {
        const attach = useAsciiDigits<HTMLInputElement>(ref);

        /*
         * الحقلُ العشريّ يُرسم نصًّا بلوحةِ أرقامٍ عشريّة — لا `type="number"`.
         *
         * لأنّ حقل الأرقام يرفض ما لا يكتمل: يكتب التاجر «4.» فيقرؤه المتصفّح
         * قيمةً غير صالحة ويُفرغه، فلا سبيل إلى كتابة فاصلٍ عشريّ نضعه نحن
         * مكان الفاصلة العربية «،» — وهي ما تُخرجه لوحتُه حين يعني الفاصل.
         *
         * و`inputMode="decimal"` تُبقي لوحةَ الأرقام على الهاتف كما كانت،
         * والتنقيةُ تقع في `guardAsciiDigits`: أرقامٌ ونقطةٌ وإشارة لا غير —
         * وهو ما كان حقلُ الأرقام يحرسه.
         *
         * والمفقودُ سهما الزيادة والنقصان، ولا يُستعملان في حقل مال.
         */
        const decimal = type === 'number' && fractional(props.step);
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
                type={decimal ? 'text' : type}
                inputMode={props.inputMode ?? (decimal ? 'decimal' : undefined)}
                ref={attach}
                dir={dir ?? (isDateTime ? 'ltr' : undefined)}
                className={cn(
                    'flex h-10 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 pointer-coarse:h-11',
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
