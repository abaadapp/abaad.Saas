import * as React from 'react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

/**
 * نطاق حسابات الدخول — مصدرٌ واحد تقرأه كلّ شاشة.
 *
 * يطابق `MerchantAccount::DOMAIN` على الخادم. وكان مكتوبًا في شاشة الشركات
 * وحدها، وشاشة الموظفين تكتب البريد كاملًا بيدها — فينتج عناوين على أشكال
 * (`.om` و`.com` وحرفٌ زائد ومسافة في الآخر)، ثمّ لا يدخل صاحبها ولا يعرف
 * السبب. والعنوان معرّف دخولٍ لا صندوق بريد، فلا معنى لأن يختار كلٌّ نطاقه.
 */
export const MERCHANT_DOMAIN = '@abaadapp.om';

/** الاسم من البريد الكامل — لملء الحقل في شاشة التعديل */
export function usernameOf(email?: string | null): string {
    return (email ?? '').split('@')[0] ?? '';
}

/** هل هذا العنوان على نطاق أبعاد؟ */
export function onMerchantDomain(email?: string | null): boolean {
    return (email ?? '').toLowerCase().endsWith(MERCHANT_DOMAIN);
}

interface Props extends Omit<React.ComponentProps<typeof Input>, 'onChange' | 'value' | 'type'> {
    value: string;
    onChange: (value: string) => void;
    /** يعرض العنوان الكامل تحت الحقل — يُطفأ حيث يُعرض بجواره أصلًا */
    preview?: boolean;
}

/**
 * الاسم وحده يُكتب، والنطاق مُلحق ثابت لا يُحرَّر.
 *
 * والحروف تنزل صغيرةً وهي تُكتب: البريد يُقارن بحروفه، و`Zahra` و`zahra`
 * حسابان لا واحد.
 */
export function UsernameInput({ value, onChange, preview = true, className, ...props }: Props) {
    return (
        <>
            <div className="flex items-stretch" dir="ltr">
                <Input
                    className={cn('rounded-e-none', className)}
                    value={value}
                    onChange={(e) => onChange(e.target.value.toLowerCase())}
                    placeholder={props.placeholder ?? 'zahra'}
                    autoComplete="off"
                    {...props}
                />
                <span className="flex items-center rounded-e-[10px] border border-s-0 border-[var(--ui-border,#e8e8e8)] bg-[#f7f7f5] px-3 text-sm text-[#6b7280]">
                    {MERCHANT_DOMAIN}
                </span>
            </div>
            {preview && value !== '' && (
                <p className="mt-1.5 text-[12px] text-[#6b7280]" dir="ltr">
                    {value}
                    {MERCHANT_DOMAIN}
                </p>
            )}
        </>
    );
}
