import { type ReactNode, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { ChevronDown, ChevronUp, Search } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface Column<T> {
    key: string;
    header: string;
    /** ما يُعرض في الخلية؛ افتراضيًا قيمة الحقل نفسه */
    cell?: (row: T) => ReactNode;
    /** النص المستخدم في البحث والترتيب — يجب أن يكون بدائيًا لا JSX */
    value?: (row: T) => string | number;
    align?: 'start' | 'end' | 'center';
    className?: string;
    sortable?: boolean;
}

export interface Filter<T> {
    label: string;
    options: { label: string; value: string }[];
    /** يُرجع true إن كان الصف مطابقًا للقيمة المختارة */
    match: (row: T, value: string) => boolean;
}

interface DataTableProps<T> {
    rows: T[];
    columns: Column<T>[];
    rowKey: (row: T) => string | number;
    searchPlaceholder?: string;
    /** الحقول التي يشملها البحث النصّي */
    searchable?: (row: T) => string;
    filters?: Filter<T>[];
    empty?: ReactNode;
    /** يُعرض بين شريط البحث والجدول */
    toolbar?: ReactNode;
    pageSize?: number;
}

/**
 * جدول القوائم المشترك: بحث + تصفية + ترتيب + ترقيم.
 *
 * رسالة «لا نتائج» تُفرَّق عن «لا بيانات» — التصفية التي تُرجع صفرًا يجب أن
 * تقول ذلك صراحة بدل أن تبدو كقائمة فارغة (وهو عيب أُصلح سابقًا في Blade).
 */
export default function DataTable<T>({
    rows,
    columns,
    rowKey,
    searchPlaceholder = 'بحث…',
    searchable,
    filters = [],
    empty = 'لا توجد بيانات بعد',
    toolbar,
    pageSize = 25,
}: DataTableProps<T>) {
    const t = useTranslate();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState<Record<number, string>>({});
    const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null);
    const [page, setPage] = useState(0);

    const filtered = useMemo(() => {
        let result = rows;

        if (query.trim() && searchable) {
            const needle = query.trim().toLowerCase();
            result = result.filter((row) => searchable(row).toLowerCase().includes(needle));
        }

        filters.forEach((filter, i) => {
            const value = active[i];
            if (value) result = result.filter((row) => filter.match(row, value));
        });

        if (sort) {
            const column = columns.find((c) => c.key === sort.key);
            if (column?.value) {
                result = [...result].sort((a, b) => {
                    const va = column.value!(a);
                    const vb = column.value!(b);
                    const cmp =
                        typeof va === 'number' && typeof vb === 'number'
                            ? va - vb
                            : String(va).localeCompare(String(vb), 'ar');
                    return sort.dir === 'asc' ? cmp : -cmp;
                });
            }
        }

        return result;
    }, [rows, query, active, sort, columns, filters, searchable]);

    const pageCount = Math.ceil(filtered.length / pageSize);
    const visible = filtered.slice(page * pageSize, (page + 1) * pageSize);
    const isFiltering = query.trim() !== '' || Object.values(active).some(Boolean);

    const toggleSort = (key: string) => {
        setSort((prev) =>
            prev?.key === key
                ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: 'asc' },
        );
    };

    return (
        <div>
            {(searchable || filters.length > 0 || toolbar) && (
                <div className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                    {searchable && (
                        <div className="relative sm:w-72">
                            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                            <Input
                                value={query}
                                onChange={(e) => {
                                    setQuery(e.target.value);
                                    setPage(0);
                                }}
                                placeholder={t(searchPlaceholder)}
                                className="ps-9"
                            />
                        </div>
                    )}

                    {filters.map((filter, i) => (
                        <select
                            key={i}
                            value={active[i] ?? ''}
                            onChange={(e) => {
                                setActive((prev) => ({ ...prev, [i]: e.target.value }));
                                setPage(0);
                            }}
                            className="ui-select h-10 appearance-none rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-sm text-[#111] focus:outline-none"
                        >
                            <option value="">{t(filter.label)}</option>
                            {filter.options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(option.label)}
                                </option>
                            ))}
                        </select>
                    ))}

                    {toolbar && <div className="sm:ms-auto">{toolbar}</div>}
                </div>
            )}

            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        {columns.map((column) => (
                            <TableHead
                                key={column.key}
                                className={cn(
                                    column.align === 'end' && 'text-end',
                                    column.align === 'center' && 'text-center',
                                    column.sortable && 'cursor-pointer select-none hover:text-[#111]',
                                    column.className,
                                )}
                                onClick={column.sortable ? () => toggleSort(column.key) : undefined}
                            >
                                <span className="inline-flex items-center gap-1">
                                    {t(column.header)}
                                    {column.sortable &&
                                        sort?.key === column.key &&
                                        (sort.dir === 'asc' ? (
                                            <ChevronUp className="size-3.5" />
                                        ) : (
                                            <ChevronDown className="size-3.5" />
                                        ))}
                                </span>
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>

                <TableBody>
                    {visible.length === 0 ? (
                        <TableEmpty colSpan={columns.length}>
                            {isFiltering ? t('لا نتائج مطابقة للبحث أو التصفية') : empty}
                        </TableEmpty>
                    ) : (
                        visible.map((row, i) => (
                            <motion.tr
                                key={rowKey(row)}
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                transition={{ duration: 0.2, delay: Math.min(i * 0.015, 0.2) }}
                                className="border-b border-[var(--ui-border,#e8e8e8)] transition-colors last:border-0 hover:bg-[#fafafa]"
                            >
                                {columns.map((column) => (
                                    <TableCell
                                        key={column.key}
                                        className={cn(
                                            column.align === 'end' && 'text-end',
                                            column.align === 'center' && 'text-center',
                                            column.className,
                                        )}
                                    >
                                        {column.cell
                                            ? column.cell(row)
                                            : String((row as Record<string, unknown>)[column.key] ?? '—')}
                                    </TableCell>
                                ))}
                            </motion.tr>
                        ))
                    )}
                </TableBody>
            </Table>

            {pageCount > 1 && (
                <div className="flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                    <p className="text-[12px] text-[#6b7280]">
                        {page * pageSize + 1}–{Math.min((page + 1) * pageSize, filtered.length)} {t('من')}{' '}
                        {filtered.length}
                    </p>
                    <div className="flex gap-1.5">
                        <button
                            onClick={() => setPage((p) => Math.max(0, p - 1))}
                            disabled={page === 0}
                            className="rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 py-1.5 text-[13px] transition-colors hover:bg-[#fafafa] disabled:opacity-40"
                        >
                            {t('السابق')}
                        </button>
                        <button
                            onClick={() => setPage((p) => Math.min(pageCount - 1, p + 1))}
                            disabled={page >= pageCount - 1}
                            className="rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 py-1.5 text-[13px] transition-colors hover:bg-[#fafafa] disabled:opacity-40"
                        >
                            {t('التالي')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
