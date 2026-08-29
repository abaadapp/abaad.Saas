import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { useTranslate } from '@/lib/i18n';
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

/**
 * خريطة حالات النظام العربية → لون الشارة.
 *
 * ومصدرٌ واحد لا اثنان: تقرؤها الشارةُ في الصفّ، وتقرؤها نقطةُ تبويب الحالة
 * فوق الجدول. ولو كان لكلٍّ خريطته لظهرت الحالة الواحدة بلونين على شاشةٍ
 * واحدة — النقطة في الشريط والشارة في الصفّ تحتها.
 */
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
    // «متوقف» موقوفٌ لا عاطل: صفراءُ تنبيه لا حمراءُ خطأ
    متوقف: 'warning',
    نفد: 'danger',
    مغلقة: 'neutral',
    // حالات لوحة المنصة — بنفس ألوان x-badge القديمة
    مدفوعة: 'success',
    'غير مدفوعة': 'danger',
    منتهي: 'danger',
    معطل: 'danger',
    موقوف: 'warning',
    /*
     * حالاتٌ لم تكن في الخريطة فكانت تسقط إلى الرمادي.
     *
     * والرمادي في الشارة يمرّ، أمّا في نقطة التبويب فيُبطل معناها: صفٌّ من
     * نقاطٍ رماديّة لا يميّز شيئًا عن شيء — وهو ما وُضعت النقطة له.
     */
    مسودة: 'neutral',
    'مستلم جزئيًا': 'info',
    جزئي: 'warning',
    ملغى: 'danger',
    'مُسلَّم': 'success',
    'مفعّل': 'success',
    'غير مفعّل': 'neutral',
    متوفر: 'success',
    'نفد المخزون': 'danger',
    راكد: 'warning',
    منشور: 'success',
    مرفوض: 'danger',
    'قيد التجهيز': 'info',
    'خرج للتوصيل': 'info',
    // حالاتٌ أُضيفت مع مسار طلب الورد — انظر App\Support\OrderStatus
    'مؤكّد': 'primary',
    'تم التسليم': 'success',
    'تم الاستلام': 'success',
    'تعذّر التوصيل': 'danger',
};

/**
 * لون نقطة الحالة — مصمتٌ لا خلفية.
 *
 * خلفيةُ الشارة فاتحةٌ جدًّا (‏#ecfdf5)، ونقطةٌ بقياس ٦ بكسل بهذا اللون لا
 * تكاد تُرى على أبيض. فتُؤخذ نغمةُ النصّ وهي القويّة.
 */
const TONE_DOT: Record<string, string> = {
    neutral: '#9ca3af',
    success: '#059669',
    warning: '#d97706',
    danger: '#dc2626',
    info: '#2563eb',
    primary: '#6d28d9',
    outline: '#9ca3af',
};

/** لون نقطة الحالة كما تراه الشارة — وما لا يُعرف رماديّ */
export function statusDot(status: string): string {
    return TONE_DOT[STATUS_VARIANT[status] ?? 'neutral'] ?? TONE_DOT.neutral;
}

export interface BadgeProps
    extends React.HTMLAttributes<HTMLSpanElement>,
        VariantProps<typeof badgeVariants> {
    /** مرّر نص الحالة ليُختار اللون تلقائيًا */
    status?: string;
}

function Badge({ className, variant, status, children, ...props }: BadgeProps) {
    const resolved = variant ?? (status ? (STATUS_VARIANT[status] ?? 'neutral') : 'neutral');
    const t = useTranslate();

    /*
     * الحالة نصٌّ من قاموس النظام لا من إدخال التاجر («نشط»، «متوفر»،
     * «مكتمل»…) وترجماتها موجودة في en.json، لكنها كانت تُطبع خامًا فتبقى
     * عربية في الوضع الإنجليزي. اللون يُشتقّ من القيمة العربية قبل الترجمة،
     * فلا يتأثّر.
     */
    return (
        <span className={cn(badgeVariants({ variant: resolved }), className)} {...props}>
            {children ?? (status ? t(status) : status)}
        </span>
    );
}

export { Badge, badgeVariants };
