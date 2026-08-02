import * as React from 'react';
import { usePage } from '@inertiajs/react';
import { Label } from '@/Components/ui/label';
import {
    Select as SelectRoot,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
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

/**
 * Radix يمنع القيمة الفارغة على الخيار لأنها تعني «لا اختيار» عنده، بينما
 * <select> الأصلية تسمح بخيار فارغ يُختار عمدًا (كـ«كل الحالات»). فنُمرّر
 * قيمة بديلة داخليًا ونُعيدها فارغة للمستدعي — فتبقى واجهة المكوّن كما هي.
 */
const EMPTY = '__empty__';

interface SelectProps {
    options: SelectOption[];
    /** الخيار الفارغ الأول — يظهر في الزر حين لا قيمة، ويبقى قابلًا للاختيار للرجوع إليه */
    placeholder?: string;
    value?: string | number | null;
    /** بنفس شكل حدث <select> الأصلي كي لا تتغيّر مواضع الاستدعاء */
    onChange?: (event: { target: { value: string; name: string } }) => void;
    name?: string;
    id?: string;
    disabled?: boolean;
    required?: boolean;
    className?: string;
    'aria-label'?: string;
}

/**
 * قائمة اختيار مرسومة داخل الصفحة لا بواسطة نظام التشغيل: تفتح تحت الحقل
 * بعرضه كاملًا فيظهر النص الطويل كاملًا بدل أن تحجبه نافذة النظام الضيّقة.
 */
export const Select = React.forwardRef<HTMLButtonElement, SelectProps>(
    ({ className, options, placeholder, value, onChange, name, id, disabled, required, ...props }, ref) => {
        const t = useTranslate();
        const current = value === null || value === undefined ? '' : String(value);
        // Radix يفرض dir="ltr" على المحتوى المنقول إلى body ما لم نُخبره،
        // فتنقلب المحاذاة وموضع علامة الصح داخل القائمة في الواجهة العربية
        const { dir } = usePage<{ dir: 'rtl' | 'ltr' }>().props;

        return (
            <SelectRoot
                dir={dir}
                // القيمة الفارغة تُمرَّر كما هي: لو مرّرناها undefined ظنّها Radix
                // مكوّنًا غير مضبوط فاحتفظ باختياره الداخلي ولم يعُد التصفير يعمل
                value={current}
                onValueChange={(next) =>
                    onChange?.({ target: { value: next === EMPTY ? '' : next, name: name ?? '' } })
                }
                name={name}
                disabled={disabled}
                required={required}
            >
                <SelectTrigger ref={ref} id={id} className={className} aria-label={props['aria-label']}>
                    <SelectValue placeholder={placeholder !== undefined ? t(placeholder) : undefined} />
                </SelectTrigger>
                <SelectContent>
                    {placeholder !== undefined && <SelectItem value={EMPTY}>{t(placeholder)}</SelectItem>}
                    {options.map((o) => (
                        <SelectItem key={o.value} value={String(o.value)}>
                            {t(o.label)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </SelectRoot>
        );
    },
);
Select.displayName = 'Select';
