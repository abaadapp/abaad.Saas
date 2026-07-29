import * as React from 'react';
import { cn } from '@/lib/utils';

const Table = React.forwardRef<HTMLTableElement, React.HTMLAttributes<HTMLTableElement>>(
    ({ className, ...props }, ref) => (
        // التمرير الأفقي داخل الحاوية — الجدول لا يدفع الصفحة للتمدد
        <div className="w-full overflow-x-auto">
            <table ref={ref} className={cn('w-full caption-bottom text-sm', className)} {...props} />
        </div>
    ),
);
Table.displayName = 'Table';

const TableHeader = React.forwardRef<
    HTMLTableSectionElement,
    React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
    <thead ref={ref} className={cn('border-b border-[var(--ui-border,#e8e8e8)]', className)} {...props} />
));
TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef<
    HTMLTableSectionElement,
    React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
    <tbody
        ref={ref}
        className={cn('[&_tr:last-child]:border-0', className)}
        {...props}
    />
));
TableBody.displayName = 'TableBody';

const TableRow = React.forwardRef<HTMLTableRowElement, React.HTMLAttributes<HTMLTableRowElement>>(
    ({ className, ...props }, ref) => (
        <tr
            ref={ref}
            className={cn(
                'border-b border-[var(--ui-border,#e8e8e8)] transition-colors hover:bg-[#fafafa]',
                className,
            )}
            {...props}
        />
    ),
);
TableRow.displayName = 'TableRow';

const TableHead = React.forwardRef<
    HTMLTableCellElement,
    React.ThHTMLAttributes<HTMLTableCellElement>
>(({ className, ...props }, ref) => (
    <th
        ref={ref}
        className={cn(
            'h-11 px-4 text-start align-middle text-[12px] font-semibold text-[#6b7280]',
            className,
        )}
        {...props}
    />
));
TableHead.displayName = 'TableHead';

const TableCell = React.forwardRef<
    HTMLTableCellElement,
    React.TdHTMLAttributes<HTMLTableCellElement>
>(({ className, ...props }, ref) => (
    <td ref={ref} className={cn('px-4 py-3 align-middle text-[#111]', className)} {...props} />
));
TableCell.displayName = 'TableCell';

/** حالة القائمة الفارغة — تُستخدم في كل الجداول بدل تكرارها */
function TableEmpty({ colSpan, children }: { colSpan: number; children: React.ReactNode }) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-4 py-14 text-center text-sm text-[#9ca3af]">
                {children}
            </td>
        </tr>
    );
}

export { Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmpty };
