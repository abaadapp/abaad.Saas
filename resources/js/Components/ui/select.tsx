import * as React from 'react';
import * as SelectPrimitive from '@radix-ui/react-select';
import { Check, ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * قائمة منسدلة مبنية على Radix بدل <select> الأصلية.
 *
 * القائمة الأصلية يرسمها نظام التشغيل لا المتصفح: نافذة داكنة ضيّقة تطفو
 * فوق الحقل نفسه فتحجبه، ولا تحترم عرضه ولا خطّه ولا اتجاه الواجهة، ولا
 * يمكن تنسيقها بـCSS إطلاقًا. هنا تُرسم القائمة داخل الصفحة: تفتح تحت
 * الحقل بعرضه كاملًا، والنص يلتف بدل أن يُقصّ.
 */
const Select = SelectPrimitive.Root;
const SelectGroup = SelectPrimitive.Group;
const SelectValue = SelectPrimitive.Value;

const SelectTrigger = React.forwardRef<
    React.ElementRef<typeof SelectPrimitive.Trigger>,
    React.ComponentPropsWithoutRef<typeof SelectPrimitive.Trigger>
>(({ className, children, ...props }, ref) => (
    <SelectPrimitive.Trigger
        ref={ref}
        className={cn(
            'flex h-10 w-full items-center justify-between gap-2 rounded-[10px]',
            'border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-start text-sm text-[#111]',
            'transition-[border-color,box-shadow] outline-none',
            'focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
            'disabled:cursor-not-allowed disabled:opacity-60',
            // النص الطويل يُقصّ في الزر وحده — القائمة المفتوحة تعرضه كاملًا
            '[&>span]:min-w-0 [&>span]:truncate',
            'data-[placeholder]:text-[#9ca3af]',
            className,
        )}
        {...props}
    >
        {children}
        <SelectPrimitive.Icon asChild>
            <ChevronDown className="size-4 shrink-0 text-[#6b7280]" />
        </SelectPrimitive.Icon>
    </SelectPrimitive.Trigger>
));
SelectTrigger.displayName = 'SelectTrigger';

const ScrollButton = ({ dir }: { dir: 'up' | 'down' }) => {
    const Primitive = dir === 'up' ? SelectPrimitive.ScrollUpButton : SelectPrimitive.ScrollDownButton;
    const Icon = dir === 'up' ? ChevronUp : ChevronDown;

    return (
        <Primitive className="flex cursor-default items-center justify-center py-1 text-[#6b7280]">
            <Icon className="size-4" />
        </Primitive>
    );
};

const SelectContent = React.forwardRef<
    React.ElementRef<typeof SelectPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof SelectPrimitive.Content>
>(({ className, children, position = 'popper', ...props }, ref) => (
    <SelectPrimitive.Portal>
        <SelectPrimitive.Content
            ref={ref}
            position={position}
            sideOffset={6}
            className={cn(
                'relative z-50 overflow-hidden rounded-[12px] border border-[var(--ui-border,#e8e8e8)]',
                'bg-white text-[#111] shadow-[0_8px_30px_rgba(0,0,0,0.10)]',
                // العرض من عرض الحقل نفسه، والارتفاع من المتاح في الشاشة
                'max-h-[var(--radix-select-content-available-height)]',
                'w-[var(--radix-select-trigger-width)]',
                'data-[state=open]:animate-in data-[state=closed]:animate-out',
                'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
                'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                className,
            )}
            {...props}
        >
            <ScrollButton dir="up" />
            <SelectPrimitive.Viewport className="p-1.5">{children}</SelectPrimitive.Viewport>
            <ScrollButton dir="down" />
        </SelectPrimitive.Content>
    </SelectPrimitive.Portal>
));
SelectContent.displayName = 'SelectContent';

const SelectLabel = React.forwardRef<
    React.ElementRef<typeof SelectPrimitive.Label>,
    React.ComponentPropsWithoutRef<typeof SelectPrimitive.Label>
>(({ className, ...props }, ref) => (
    <SelectPrimitive.Label
        ref={ref}
        className={cn('px-3 py-1.5 text-[12px] font-semibold text-[#9ca3af]', className)}
        {...props}
    />
));
SelectLabel.displayName = 'SelectLabel';

const SelectItem = React.forwardRef<
    React.ElementRef<typeof SelectPrimitive.Item>,
    React.ComponentPropsWithoutRef<typeof SelectPrimitive.Item>
>(({ className, children, ...props }, ref) => (
    <SelectPrimitive.Item
        ref={ref}
        className={cn(
            'relative flex w-full cursor-pointer select-none items-start gap-2 rounded-[8px] py-2 pe-8 ps-3',
            // النص يلتف على أسطر بدل أن يُقصّ — هذا مقصود داخل القائمة
            'text-start text-sm leading-snug break-words outline-none transition-colors',
            'focus:bg-[#f2f2f0] data-[state=checked]:font-semibold',
            'data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
            className,
        )}
        {...props}
    >
        <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
        <span className="absolute end-2 top-2 flex size-4 items-center justify-center">
            <SelectPrimitive.ItemIndicator>
                <Check className="size-4" />
            </SelectPrimitive.ItemIndicator>
        </span>
    </SelectPrimitive.Item>
));
SelectItem.displayName = 'SelectItem';

const SelectSeparator = React.forwardRef<
    React.ElementRef<typeof SelectPrimitive.Separator>,
    React.ComponentPropsWithoutRef<typeof SelectPrimitive.Separator>
>(({ className, ...props }, ref) => (
    <SelectPrimitive.Separator
        ref={ref}
        className={cn('-mx-1.5 my-1.5 h-px bg-[var(--ui-border,#e8e8e8)]', className)}
        {...props}
    />
));
SelectSeparator.displayName = 'SelectSeparator';

export {
    Select,
    SelectGroup,
    SelectValue,
    SelectTrigger,
    SelectContent,
    SelectLabel,
    SelectItem,
    SelectSeparator,
};
