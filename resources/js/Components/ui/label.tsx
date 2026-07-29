import * as React from 'react';
import * as LabelPrimitive from '@radix-ui/react-label';
import { cn } from '@/lib/utils';

const Label = React.forwardRef<
    React.ElementRef<typeof LabelPrimitive.Root>,
    React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root> & { required?: boolean }
>(({ className, children, required, ...props }, ref) => (
    <LabelPrimitive.Root
        ref={ref}
        className={cn(
            'block text-[13px] font-medium text-[#4b4b4b]',
            'peer-disabled:cursor-not-allowed peer-disabled:opacity-70',
            className,
        )}
        {...props}
    >
        {children}
        {required && <span className="text-[#dc2626]"> *</span>}
    </LabelPrimitive.Root>
));
Label.displayName = 'Label';

export { Label };
