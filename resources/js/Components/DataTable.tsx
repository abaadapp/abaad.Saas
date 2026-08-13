import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ChevronDown, ChevronUp, Search } from 'lucide-react';
import { Select } from '@/Components/Field';
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
    /** حقل تاريخ بدل قائمة اختيار */
    type?: 'select' | 'date';
    options?: { label: string; value: string }[];
    /** الوضع المحلي: يُرجع true إن كان الصف مطابقًا للقيمة المختارة */
    match?: (row: T, value: string) => boolean;
    /** الوضع الخادمي: اسم معامل الرابط الذي تُرسل فيه القيمة */
    param?: string;
    /**
     * قيمة مُفعَّلة عند أول عرض (الوضع المحلي).
     *
     * تخدم الوصول من تنبيه يحمل فلتره في الرابط: تصل الصفحة مفلترة فعلًا،
     * وتُظهر القائمةُ المنسدلة الفلترَ المطبَّق بدل أن تقول «كل الحالات»
     * فيظنّ المستخدم أنه يرى القائمة كاملة.
     */
    initial?: string;
}

/** شكل الترقيم كما يُصدره paginate() في Laravel */
export interface ServerPagination {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface ServerMode {
    pagination: ServerPagination;
    /** قيم المعاملات الحالية كما أعادها الخادم */
    params: Record<string, string | null | undefined>;
    /** اسم معامل البحث (افتراضيًا q) */
    searchParam?: string;
}

interface DataTableProps<T> {
    rows: T[];
    columns: Column<T>[];
    rowKey: (row: T) => string | number;
    searchPlaceholder?: string;
    /** الحقول التي يشملها البحث النصّي (الوضع المحلي) */
    searchable?: (row: T) => string;
    filters?: Filter<T>[];
    empty?: ReactNode;
    /** يُعرض بين شريط البحث والجدول */
    toolbar?: ReactNode;
    pageSize?: number;
    /**
     * وضع خادمي: البحث والتصفية والترقيم تمرّ بالخادم عبر معاملات الرابط.
     *
     * لازم للقوائم التي تُرقّم على الخادم (المنتجات والعملاء): تمرير الصفحة
     * الحالية فقط إلى الوضع المحلي كان سيجعل البحث يرى 12 صفًا لا الجدول كله.
     */
    server?: ServerMode;
    /**
     * يستبدل الجدول بعرض آخر (بطاقات مثلًا) مع إبقاء شريط البحث والتصفية
     * والترقيم كما هو — فلا يفقد العرض الشبكي أدوات التصفية.
     */
    renderBody?: (rows: T[]) => ReactNode;
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
    server,
    renderBody,
}: DataTableProps<T>) {
    const t = useTranslate();
    const searchParam = server?.searchParam ?? 'q';
    const [query, setQuery] = useState(server ? String(server.params[searchParam] ?? '') : '');
    const [active, setActive] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            filters.map((f, i) => [
                i,
                server ? (f.param ? String(server.params[f.param] ?? '') : '') : (f.initial ?? ''),
            ]),
        ),
    );
    const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null);
    const [page, setPage] = useState(0);

    /** يزور الرابط بالمعاملات الجديدة — يحذف الفارغ منها حتى يبقى الرابط نظيفًا */
    const go = (patch: Record<string, string | number | null>) => {
        const next: Record<string, string | number> = {};
        const merged = { ...server?.params, [searchParam]: query, ...patch };
        Object.entries(merged).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') next[k] = v as string | number;
        });
        router.get(window.location.pathname, next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // البحث الخادمي يُمهَل قليلًا: طلب لكل حرف يُغرق الخادم ويجعل الحقل يتلعثم
    const first = useRef(true);
    useEffect(() => {
        if (!server) return;
        if (first.current) {
            first.current = false;
            return;
        }
        const id = setTimeout(() => go({ page: null }), 350);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const filtered = useMemo(() => {
        if (server) return rows; // الخادم رشَّح ورتّب ورقّم بالفعل

        let result = rows;

        if (query.trim() && searchable) {
            const needle = query.trim().toLowerCase();
            result = result.filter((row) => searchable(row).toLowerCase().includes(needle));
        }

        filters.forEach((filter, i) => {
            const value = active[i];
            if (value && filter.match) result = result.filter((row) => filter.match!(row, value));
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
    }, [rows, query, active, sort, columns, filters, searchable, server]);

    const pageCount = server ? server.pagination.last_page : Math.ceil(filtered.length / pageSize);
    const visible = server ? filtered : filtered.slice(page * pageSize, (page + 1) * pageSize);
    const isFiltering = query.trim() !== '' || Object.values(active).some(Boolean);

    /*
     * أكثر الشاشات تمرّر رسالة الفراغ عربيةً خامًا، فكانت تبقى عربية في الوضع
     * الإنجليزي رغم وجود ترجمتها في en.json. الترجمة هنا تُصلحها كلّها دفعةً
     * واحدة. وما لم يكن نصًّا (عنصر React) يُترك كما هو.
     */
    const emptyText = typeof empty === 'string' ? t(empty) : empty;

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

                    {filters.map((filter, i) => {
                        const onPick = (value: string) => {
                            setActive((prev) => ({ ...prev, [i]: value }));
                            setPage(0);
                            if (server && filter.param) go({ [filter.param]: value || null, page: null });
                        };

                        /*
                         * التسمية مكتوبةً داخل الحقل لا في aria وحدها.
                         *
                         * حقل type="date" لا يقبل placeholder — فبينما تعلن
                         * القائمةُ عن نفسها بـ«كل الحالات» مكتوبةً فيها، كان
                         * حقل التاريخ يظهر «dd/mm/yyyy» فارغًا من أي عنوان.
                         * ومتى وقف حقلان متجاوران هكذا لم يعرف الناظر أيّهما
                         * «من» وأيّهما «إلى» إلا بالتجربة. والتسمية كانت في
                         * aria-label: يقرؤها قارئ الشاشة ولا تراها العين.
                         */
                        return filter.type === 'date' ? (
                            <label
                                key={i}
                                className={cn(
                                    'flex h-10 items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)]',
                                    'bg-white px-3 text-sm transition-[border-color,box-shadow] sm:w-52',
                                    'focus-within:border-[#d1d5db] focus-within:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                                )}
                            >
                                <span className="whitespace-nowrap text-[#6b7280]">{t(filter.label)}</span>
                                <Input
                                    type="date"
                                    aria-label={t(filter.label)}
                                    value={active[i] ?? ''}
                                    onChange={(e) => onPick(e.target.value)}
                                    className="h-auto min-w-0 flex-1 border-0 bg-transparent p-0 focus:shadow-none"
                                />
                            </label>
                        ) : (
                            <Select
                                key={i}
                                value={active[i] ?? ''}
                                onChange={(e) => onPick(e.target.value)}
                                // التسمية هي خيار «الكل»: تظهر في الزر بلا تصفية وتبقى قابلة للاختيار للرجوع
                                placeholder={filter.label}
                                options={filter.options ?? []}
                                aria-label={t(filter.label)}
                                className="sm:w-48"
                            />
                        );
                    })}

                    {toolbar && <div className="sm:ms-auto">{toolbar}</div>}
                </div>
            )}

            {renderBody ? (
                <div className="px-4 pb-4">
                    {visible.length === 0 ? (
                        <p className="py-12 text-center text-sm text-[#6b7280]">
                            {isFiltering ? t('لا نتائج مطابقة للبحث أو التصفية') : emptyText}
                        </p>
                    ) : (
                        renderBody(visible)
                    )}
                </div>
            ) : (
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
                            {isFiltering ? t('لا نتائج مطابقة للبحث أو التصفية') : emptyText}
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
            )}

            {pageCount > 1 && (
                <div className="flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                    <p className="text-[12px] text-[#6b7280]">
                        {server
                            ? `${server.pagination.from ?? 0}–${server.pagination.to ?? 0}`
                            : `${page * pageSize + 1}–${Math.min((page + 1) * pageSize, filtered.length)}`}{' '}
                        {t('من')} {server ? server.pagination.total : filtered.length}
                    </p>
                    <div className="flex gap-1.5">
                        <button
                            onClick={() =>
                                server
                                    ? go({ page: server.pagination.current_page - 1 })
                                    : setPage((p) => Math.max(0, p - 1))
                            }
                            disabled={server ? server.pagination.current_page <= 1 : page === 0}
                            className="inline-flex h-8 items-center rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 text-[13px] transition-colors hover:bg-[#fafafa] disabled:pointer-events-none disabled:opacity-50"
                        >
                            {t('السابق')}
                        </button>
                        <button
                            onClick={() =>
                                server
                                    ? go({ page: server.pagination.current_page + 1 })
                                    : setPage((p) => Math.min(pageCount - 1, p + 1))
                            }
                            disabled={
                                server
                                    ? server.pagination.current_page >= server.pagination.last_page
                                    : page >= pageCount - 1
                            }
                            className="inline-flex h-8 items-center rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 text-[13px] transition-colors hover:bg-[#fafafa] disabled:pointer-events-none disabled:opacity-50"
                        >
                            {t('التالي')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
