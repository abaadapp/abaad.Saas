import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * الأنماط منقولة حرفيًا عن <x-button> في Blade حتى لا يتغيّر شكل الواجهة.
 */
const buttonVariants = cva(
    // `transition` (لا transition-all): قائمةٌ منتقاة تشمل اللون والتحويل
    // (scale) دون خصائص التخطيط (العرض/الحشو)، فلا يهتزّ الزر. active:scale
    // ردُّ فعلٍ لمسيّ طفيف يعمل للزر وللرابط المُغلَّف (Slot) معًا لأنه صنف.
    // motion-reduce يلغي التحجيم لمن طلب تقليل الحركة.
    'relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[10px] font-medium ' +
        'transition duration-150 active:scale-[0.97] motion-reduce:active:scale-100 ' +
        'disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none ' +
        'focus-visible:ring-2 focus-visible:ring-[var(--ring)] [&_svg]:pointer-events-none [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-[#111] text-white hover:bg-[#2a2a2a] active:bg-[#000]',
                outline: 'border border-[var(--border-strong,#dcdcdc)] bg-white text-[#111] hover:bg-[#fafafa]',
                ghost: 'text-[#4b4b4b] hover:bg-[rgba(17,17,17,0.045)] hover:text-[#111]',
                danger: 'bg-[#dc2626] text-white hover:bg-[#b91c1c]',
                success: 'bg-[#059669] text-white hover:bg-[#047857]',
                subtle: 'bg-[#f2f2f0] text-[#111] hover:bg-[#e9e9e6]',
                link: 'text-[#111] underline-offset-4 hover:underline',
            },
            size: {
                sm: 'h-8 px-3 text-[13px] [&_svg]:size-4',
                md: 'h-10 px-4 text-sm [&_svg]:size-[18px]',
                lg: 'h-12 px-6 text-[15px] [&_svg]:size-5',
                icon: 'h-10 w-10 [&_svg]:size-[18px]',
                'icon-sm': 'h-8 w-8 [&_svg]:size-4',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
        },
    },
);

export interface ButtonProps
    extends React.ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof buttonVariants> {
    asChild?: boolean;
    /**
     * حالة الإرسال. المحتوى يبقى في مكانه ويُخفى بصريًا فقط، والمؤشّر يُركَّب
     * فوقه بالإحداثيات المطلقة — فلا يتغيّر عرض الزر ولا يقفز ما حوله.
     *
     * تُعطّل الزر أيضًا، فتمنع الضغط المكرّر وإرسال النموذج مرّتين.
     */
    loading?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
    (
        { className, variant, size, asChild = false, loading = false, disabled, children, ...props },
        ref,
    ) => {
        const Comp = asChild ? Slot : 'button';

        // Slot يشترط ابنًا واحدًا، فلا يُغلَّف محتواه — والروابط لا تُرسِل نماذج أصلًا
        const content =
            loading && !asChild ? (
                <>
                    <span className="inline-flex items-center gap-2 opacity-0">{children}</span>
                    <Loader2 className="absolute animate-spin" aria-hidden="true" />
                </>
            ) : (
                children
            );

        return (
            <Comp
                ref={ref}
                className={cn(buttonVariants({ variant, size }), className)}
                disabled={asChild ? disabled : disabled || loading}
                aria-busy={loading || undefined}
                {...props}
            >
                {content}
            </Comp>
        );
    },
);
Button.displayName = 'Button';

export { Button, buttonVariants };
