import { Check, Copy } from 'lucide-react';
import { Button, type ButtonProps } from '@/Components/ui/button';
import { useCopy } from '@/lib/copy';
import { useTranslate } from '@/lib/i18n';

interface CopyButtonProps {
    /** النصُّ الذي يُنسخ — وهو نفسُه ما تُقاس به حالةُ الزرّ */
    text: string;
    /** ما يُكتب على الزرّ. واتركه فارغًا ليكون أيقونةً وحدها */
    label?: string;
    /** ما يُقال بعد النسخ — «نُسخت» لكلمة المرور، «نُسخ» لغيرها */
    done?: string;
    /** اسمُ الزرّ لقارئ الشاشة حين لا نصَّ عليه */
    name?: string;
    variant?: ButtonProps['variant'];
    size?: ButtonProps['size'];
    className?: string;
}

/**
 * زرُّ «انسخ» — واحدٌ في النظام كلّه.
 *
 * والقولُ يقع على الزرّ الذي ضُغط لا في سطرٍ أسفل الشاشة: ثلاثةُ أزرارٍ
 * متجاورة وسطرٌ واحد يقول «نُسخ.» لا يقول أيَّها.
 *
 * ولا يُعلَن النسخ إلّا بعد وقوعه — انظر `useCopy`.
 */
export default function CopyButton({
    text,
    label,
    done = 'نُسخ',
    name = 'نسخ',
    variant = 'outline',
    size = 'sm',
    className,
}: CopyButtonProps) {
    const t = useTranslate();
    const { copy, copied } = useCopy();

    // واسمُ الأيقونة يتبدّل كما يتبدّل النصّ: من لا يرى الأيقونة يسمع الخبر
    const spoken = t(copied ? done : name);

    return (
        <Button
            type="button"
            variant={variant}
            size={size}
            className={className}
            onClick={() => copy(text)}
            aria-label={label ? undefined : spoken}
            title={label ? undefined : spoken}
        >
            {copied ? <Check className="text-[#047857]" /> : <Copy />}
            {label && t(copied ? done : label)}
        </Button>
    );
}
