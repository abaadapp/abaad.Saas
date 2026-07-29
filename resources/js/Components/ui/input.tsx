import * as React from 'react';
import { cn } from '@/lib/utils';

const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
    ({ className, type, ...props }, ref) => (
        <input
            type={type}
            ref={ref}
            className={cn(
                'flex h-10 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2',
                'text-sm text-[#111] placeholder:text-[#9ca3af]',
                'transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none',
                'focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                'disabled:cursor-not-allowed disabled:bg-[#fafafa] disabled:opacity-60',
                'file:border-0 file:bg-transparent file:text-sm file:font-medium',
                className,
            )}
            {...props}
        />
    ),
);
Input.displayName = 'Input';

const Textarea = React.forwardRef<
    HTMLTextAreaElement,
    React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => (
    <textarea
        ref={ref}
        className={cn(
            'flex min-h-20 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2',
            'text-sm text-[#111] placeholder:text-[#9ca3af]',
            'transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none',
            'focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
            'disabled:cursor-not-allowed disabled:bg-[#fafafa] disabled:opacity-60',
            className,
        )}
        {...props}
    />
));
Textarea.displayName = 'Textarea';

export { Input, Textarea };
