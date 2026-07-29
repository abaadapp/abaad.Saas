import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/** شارات الحالة — نفس ألوان <x-badge> الحالية (مكتمل/معلّق/ملغي…) */
const badgeVariants = cva(
    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-medium whitespace-nowrap',
    {
        variants: {
            variant: {
                neutral: 'bg-[#f2f2f0] text-[#4b4b4b]',
                success: 'bg-[#ecfdf5] text-[#047857]',
                warning: 'bg-[#fffbeb] text-[#d97706]',
                danger: 'bg-[#fef2f2] text-[#b91c1c]',
                info: 'bg-[#eff6ff] text-[#2563eb]',
                primary: 'bg-[#f5f3ff] text-[#6d28d9]',
                outline: 'border border-[var(--ui-border,#e8e8e8)] text-[#4b4b4b]',
            },
        },
        defaultVariants: { variant: 'neutral' },
    },
);

/** خريطة حالات النظام العربية → لون الشارة */
const STATUS_VARIANT: Record<string, VariantProps<typeof badgeVariants>['variant']> = {
    مكتمل: 'success',
    مدفوع: 'success',
    نشط: 'success',
    مستلم: 'success',
    'قيد التنفيذ': 'info',
    جاهز: 'info',
    مُرسل: 'info',
    مفتوحة: 'info',
    معلّق: 'warning',
    'غير مدفوع': 'warning',
    منخفض: 'warning',
    ملغي: 'danger',
    متوقف: 'danger',
    نفد: 'danger',
    مغلقة: 'neutral',
};

export interface BadgeProps
    extends React.HTMLAttributes<HTMLSpanElement>,
        VariantProps<typeof badgeVariants> {
    /** مرّر نص الحالة ليُختار اللون تلقائيًا */
    status?: string;
}

function Badge({ className, variant, status, children, ...props }: BadgeProps) {
    const resolved = variant ?? (status ? (STATUS_VARIANT[status] ?? 'neutral') : 'neutral');

    return (
        <span className={cn(badgeVariants({ variant: resolved }), className)} {...props}>
            {children ?? status}
        </span>
    );
}

export { Badge, badgeVariants };
