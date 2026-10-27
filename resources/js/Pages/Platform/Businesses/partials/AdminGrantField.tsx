import { useState } from 'react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

interface Props {
    /** أيُسأل هذا المتجرُ عن منحة؟ — سياسةُ الإضافات أو حسابٌ بانتظار السداد (`AdminGrants::asked`) */
    grantable: boolean;
    value: string;
    error?: string;
    onChange: (value: string) => void;
    testId: string;
    className?: string;
}

/**
 * المنحةُ الإداريّة — إجراءٌ صريحٌ يُفتح قبل الإرسال.
 *
 * باقةٌ أعلى، أو مدّةٌ أطول، أو حسابٌ بانتظار السداد تُرقّى وتُجدَّد بفاتورتها من
 * صفحة المتجر. ومن أراد منحتها بلا سداد فتح هذا وكتب سببها — ويبقى الحكمُ للخادم
 * (`AdminGrants`): يطلب السببَ وتاريخَ انتهاءٍ لم يمضِ ويسجّلها، أو يردّ. فلا يفتح
 * المربّعُ شيئًا بنفسه، ولا يُرسَل سببٌ لم يُفتح له الحقل.
 *
 * والسابقةُ لا يُسأل عنها شيء، فلا زرَّ لها — إلّا خطأً يقوله الخادم.
 */
export default function AdminGrantField({ grantable, value, error, onChange, testId, className }: Props) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);

    if (!grantable && !error && !value) return null;

    if (!open && !error && !value) {
        return (
            <div className={className}>
                <Button type="button" variant="outline" size="sm" data-testid={`${testId}-open`} onClick={() => setOpen(true)}>
                    {t('منحة إدارية…')}
                </Button>
                <p className="mt-1 text-[12px] text-[#9ca3af]">{t('لترقية أو تمديد بلا فاتورة — تتطلب سببًا وتاريخ انتهاء.')}</p>
            </div>
        );
    }

    return (
        <div className={className} data-testid={testId}>
            <Field label="سبب المنحة الإدارية" required error={error}>
                <Input
                    data-testid={`${testId}-reason`}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    minLength={5}
                    maxLength={500}
                    required
                />
            </Field>
            <p className="mt-1 text-[12px] text-[#6b7280]">
                {t('المنحة تتطلب سببًا واضحًا وتاريخ انتهاء لم يمضِ، وتُسجَّل في سجل النشاط. والخادم يحكم بها: ما لا يفتح شيئًا يُحفظ بلا منحة.')}
            </p>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-1"
                data-testid={`${testId}-cancel`}
                onClick={() => {
                    setOpen(false);
                    onChange('');
                }}
            >
                {t('إلغاء المنحة')}
            </Button>
        </div>
    );
}
