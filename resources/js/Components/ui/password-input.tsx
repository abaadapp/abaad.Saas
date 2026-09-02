import * as React from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';

/**
 * كلمة المرور تُرى حين يطلب صاحبها أن يراها.
 *
 * حقلٌ مُعمّى بالكامل يُكتب على لوحةٍ عربية أو على هاتف، ثمّ يُرفض الدخول
 * ولا يعرف صاحبه أخطأ حرفًا أم نسي الكلمة. وأشدّ ما يقع في شاشة الموظفين:
 * المدير يكتب كلمةً لموظّفه ويُمليها عليه بالهاتف — وهو لا يرى ما كتب.
 *
 * وكانت العينُ في شاشة الدخول وحدها، مكتوبةً بيدها. فنُقلت إلى مكانٍ واحد
 * تقرؤه الشاشات كلّها: حقلان يقولان الشيء نفسه يفترقان يومًا.
 */
export function PasswordInput({
    className,
    leading,
    ...props
}: Omit<React.ComponentProps<typeof Input>, 'type'> & { leading?: React.ReactNode }) {
    const [reveal, setReveal] = React.useState(false);
    const t = useTranslate();

    return (
        <span className="relative block" dir="ltr">
            {leading}
            <Input
                {...props}
                type={reveal ? 'text' : 'password'}
                /* مكانُ الزرّ محجوز دائمًا: بلا هذا يمرّ آخرُ الحروف تحته */
                className={cn('pe-10', className)}
            />
            <button
                type="button"
                onClick={() => setReveal((v) => !v)}
                aria-label={reveal ? t('إخفاء كلمة المرور') : t('إظهار كلمة المرور')}
                title={reveal ? t('إخفاء كلمة المرور') : t('إظهار كلمة المرور')}
                className="absolute end-2 top-1.5 flex size-7 items-center justify-center rounded-[8px] text-[#9ca3af] transition-colors hover:bg-[#f2f2f0] hover:text-[#4b4b4b]"
            >
                {reveal ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
        </span>
    );
}
