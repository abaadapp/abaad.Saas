import * as React from 'react';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface FieldProps {
    label?: string;
    hint?: string;
    /** رسالة الخطأ القادمة من التحقق الخادمي */
    error?: string;
    required?: boolean;
    htmlFor?: string;
    className?: string;
    children: React.ReactNode;
}

/** غلاف حقل: تسمية + عنصر + تلميح + خطأ — بديل <x-input>/<x-select> في Blade */
export default function Field({
    label,
    hint,
    error,
    required,
    htmlFor,
    className,
    children,
}: FieldProps) {
    const t = useTranslate();

    return (
        <div className={cn('space-y-1.5', className)}>
            {label && (
                <Label htmlFor={htmlFor} required={required}>
                    {t(label)}
                </Label>
            )}
            {children}
            {error ? (
                <p className="text-[12px] text-[#b91c1c]">{error}</p>
            ) : (
                hint && <p className="text-[12px] text-[#9ca3af]">{t(hint)}</p>
            )}
        </div>
    );
}

export interface SelectOption {
    label: string;
    value: string | number;
}

interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
    options: SelectOption[];
    /** الخيار الفارغ الأول */
    placeholder?: string;
}

/** قائمة اختيار أصلية بمظهر النظام — كافية للنماذج ولا تحتاج ثقل Radix */
export const Select = React.forwardRef<HTMLSelectElement, SelectProps>(
    ({ className, options, placeholder, ...props }, ref) => {
        const t = useTranslate();

        return (
            <select
                ref={ref}
                className={cn(
                    'ui-select h-10 w-full appearance-none rounded-[10px] border border-[var(--ui-border,#e8e8e8)]',
                    'bg-white px-3 text-sm text-[#111]',
                    'transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none',
                    'focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] disabled:opacity-60',
                    className,
                )}
                {...props}
            >
                {placeholder !== undefined && <option value="">{t(placeholder)}</option>}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {t(o.label)}
                    </option>
                ))}
            </select>
        );
    },
);
Select.displayName = 'Select';
