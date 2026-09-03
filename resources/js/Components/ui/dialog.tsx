import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Overlay>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-50 bg-black/20 backdrop-blur-sm',
            'data-[state=open]:animate-in data-[state=closed]:animate-out',
            'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            className,
        )}
        {...props}
    />
));
DialogOverlay.displayName = 'DialogOverlay';

const DialogContent = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & { hideClose?: boolean }
>(({ className, children, hideClose, ...props }, ref) => (
    <DialogPrimitive.Portal>
        <DialogOverlay />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                /*
                 * والمركز يُحسب على ما يُرى لا على الشاشة.
                 *
                 * لوحةُ المفاتيح على الآيباد لا تُقلّص الصفحة، فنافذةٌ موسّطة
                 * رأسيًّا تبقى في منتصف الشاشة كلّها — ونصفُها السفليّ تحت
                 * اللوحة، وفيه زرُّ التأكيد. فتُرفع بنصف ارتفاع اللوحة،
                 * ويُقصّ سقفُها بمقدارها فيصير ما بداخلها قابلًا للتمرير
                 * بدل أن يُقصّ. انظر `useOnScreenKeyboard`.
                 */
                'fixed start-1/2 top-[calc(50%-var(--kb,0px)/2)] z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 rtl:translate-x-1/2',
                'max-h-[calc(100dvh-var(--kb,0px)-1.5rem)] overflow-y-auto overscroll-contain',
                'rounded-[var(--ui-radius,16px)] border border-[var(--ui-border,#e8e8e8)] bg-white',
                'shadow-[0_20px_60px_rgba(0,0,0,0.15)] focus:outline-none',
                'data-[state=open]:animate-in data-[state=closed]:animate-out',
                'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
                'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                className,
            )}
            {...props}
        >
            {children}
            {!hideClose && (
                <DialogPrimitive.Close
                    className={cn(
                        'absolute end-4 top-4 rounded-[8px] p-1.5 text-[#6b7280] transition-colors',
                        'hover:bg-[#f2f2f0] hover:text-[#111] focus:outline-none',
                    )}
                >
                    <X className="size-4" />
                    <span className="sr-only">Close</span>
                </DialogPrimitive.Close>
            )}
        </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
));
DialogContent.displayName = 'DialogContent';

function DialogHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-col gap-1.5 p-5 pb-3', className)} {...props} />;
}

function DialogFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn('flex flex-row-reverse items-center gap-2 p-5 pt-3', className)}
            {...props}
        />
    );
}

const DialogTitle = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Title>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Title
        ref={ref}
        className={cn('text-[17px] font-semibold text-[#111]', className)}
        {...props}
    />
));
DialogTitle.displayName = 'DialogTitle';

const DialogDescription = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Description>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Description
        ref={ref}
        className={cn('text-[13px] text-[#6b7280]', className)}
        {...props}
    />
));
DialogDescription.displayName = 'DialogDescription';

export {
    Dialog,
    DialogTrigger,
    DialogClose,
    DialogContent,
    DialogHeader,
    DialogFooter,
    DialogTitle,
    DialogDescription,
    DialogOverlay,
};
