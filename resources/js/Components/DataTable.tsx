import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import type { LucideIcon } from 'lucide-react';
import { ArrowUpDown, ChevronDown, ChevronUp, ListFilter, Search, X } from 'lucide-react';
import Tabs from '@/Components/Tabs';
import { statusDot } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
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

/** رمادي «الكل» — غيابُ تصفية لا حالة، فلا يأخذ لون حالةٍ من الخريطة */
const NEUTRAL_DOT = '#9ca3af';

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
    /**
     * يُرسم شريطَ تبويباتٍ فوق الجدول بدل أن يسكن قائمة «أضف فلتر».
     *
     * لفلتر الحالة وحده: هو السؤال الأوّل عن أي قائمة — «ما غير المدفوع؟» —
     * وإخفاؤه خلف زرّ يجعل أكثر ما يُسأل عنه أبعدَ ما يُوصل إليه. وما عداه
     * (المورّد، النوع، الفرع) يُسأل عنه أحيانًا، فمكانه القائمة.
     *
     * ولا يُعلَّم به إلا فلترٌ خياراته قليلة: التبويبات لا تنكمش، وعشرون
     * خيارًا تصير شريطًا ينزلق أفقيًّا لا يُقرأ.
     */
    asTabs?: boolean;
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
    /**
     * مفاتيح الأعمدة التي يرتّبها الخادم فعلًا — كما تُرسلها `Sort::keys`.
     *
     * المتحكّم وحده يقرّر، ولا يُشتقّ من `column.sortable`: الاثنان ينحرفان،
     * فيعرض الزرُّ عمودًا لا يرتّبه الخادم ويعود المستخدم إلى ترتيبٍ يدّعي
     * نفسه — وهو العطب الذي كان.
     */
    sorts?: string[];
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
    /**
     * مبدّل شكل العرض في طرف الشريط — لمن عنده `renderBody`.
     *
     * أزرارُ أيقونةٍ لا شريطٌ مقسَّم: للنظام شكل تبويبٍ واحد هو الخطّ السفلي،
     * وشريطٌ مقسَّم هنا يُعيد الشكل الذي نُزع. وهو تبديلُ هيئةٍ لا تنقّلٌ بين
     * وجهات، فالأيقونة تكفيه.
     */
    views?: {
        current: string;
        onChange: (key: string) => void;
        options: { key: string; label: string; icon: LucideIcon }[];
    };
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
    views,
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
    // في الوضع الخادمي يعيش الترتيب في الرابط لا في الذاكرة: رابطٌ يُرسَل أو
    // يُحفَظ يفتح على ما فُتح عليه، والرجوع بالمتصفّح يُرجع الترتيب معه
    const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(
        server?.params.sort ? { key: String(server.params.sort), dir: server.params.dir === 'asc' ? 'asc' : 'desc' } : null,
    );
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
        const next: { key: string; dir: 'asc' | 'desc' } =
            sort?.key === key
                ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: 'asc' };

        setSort(next);
        if (server) go({ sort: next.key, dir: next.dir, page: null });
    };

    const clearSort = () => {
        setSort(null);
        if (server) go({ sort: null, dir: null, page: null });
    };

    /** تطبيق قيمة فلتر — والوضع الخادمي يزور الرابط بها */
    const pick = (i: number, value: string) => {
        setActive((prev) => ({ ...prev, [i]: value }));
        setPage(0);
        const filter = filters[i];
        if (server && filter?.param) go({ [filter.param]: value || null, page: null });
    };

    /*
     * الفلاتر بابان: شريطُ تبويبات فوق الجدول لفلتر الحالة، وقائمةٌ منسدلة
     * لما عداه. والمطبَّق من الثانية يظهر شريحةً — وإلا صار الفلتر خفيًّا
     * يعمل: تُخفيه القائمة بعد اختياره فيقرأ الناظر قائمةً منقوصة ولا شيء
     * على الشاشة يقول لِمَ.
     */
    const indexed = filters.map((f, i) => ({ f, i }));
    const tabFilters = indexed.filter(({ f }) => f.asTabs);
    const rest = indexed.filter(({ f }) => !f.asTabs);

    /*
     * التاريخ يبقى في الشريط ولا يدخل القائمة.
     *
     * منتقي التاريخ الأصليّ يُفتح في طبقةٍ خارج القائمة المنسدلة، فتفقد
     * القائمة التركيز وتنغلق قبل أن يُختار يوم — حقلٌ يُفتح ولا يُملأ.
     * وهو أيضًا لا قيمة «كل» له تُعرض شريحةً: مداه مكتوبٌ في الحقل نفسه.
     */
    const dateFilters = rest.filter(({ f }) => f.type === 'date');
    const menuFilters = rest.filter(({ f }) => f.type !== 'date');
    const applied = menuFilters.filter(({ i }) => active[i]);
    const unapplied = menuFilters.filter(({ i }) => !active[i]);

    /** نصّ القيمة المختارة كما يقرؤه الإنسان — لا مفتاحها */
    const valueLabel = (filter: Filter<T>, value: string) =>
        filter.options?.find((o) => o.value === value)?.label ?? value;

    /*
     * «مسح الكل» يمسح التاريخ أيضًا.
     *
     * وشريط الحالة يبقى على ما هو: هو ظاهرٌ يقول نفسه، ومسحُه مع الشرائح
     * يُعيد المستخدم إلى «الكل» وهو لم يطلب ذلك.
     */
    const clearable = [...menuFilters, ...dateFilters];

    const clearFilters = () => {
        setActive((prev) => {
            const next = { ...prev };
            clearable.forEach(({ i }) => { next[i] = ''; });
            return next;
        });
        setPage(0);
        if (server) {
            const patch: Record<string, null> = {};
            clearable.forEach(({ f }) => { if (f.param) patch[f.param] = null; });
            go({ ...patch, page: null });
        }
    };

    /*
     * ما يُرتَّب فعلًا — لا ما يُزعم أنه يُرتَّب.
     *
     * على الخادم: مفاتيحه هو (`server.sorts`)، فلا يُعرض عمودٌ لا يعرف كيف
     * يرتّبه. وفي المحلّي: ما عُلِّم `sortable` وله `value` بدائيّة تُقارَن.
     *
     * وقبلُ كان الرأس قابلًا للضغط في الحالتين والوضعُ الخادميّ لا يرتّب —
     * تضغط «المبلغ» في المنتجات فينقلب السهم ولا يتحرّك صفّ.
     */
    const sortable = server
        ? columns.filter((c) => server.sorts?.includes(c.key))
        : columns.filter((c) => c.sortable && c.value);

    return (
        <div>
            {(searchable || filters.length > 0 || toolbar) && (
                <div className="flex flex-wrap items-center gap-1 px-4 py-2">
                    {/* البحث بلا إطار: حقلٌ محاطٌ بحدٍّ داخل بطاقةٍ محاطةٍ بحدّ
                        يُثقل الشريط، والأيقونة وحدها تقول ما هو */}
                    {searchable && (
                        <div className="flex min-w-0 flex-1 items-center gap-2 sm:max-w-[18rem]">
                            <Search className="size-4 shrink-0 text-[#9ca3af]" />
                            <input
                                value={query}
                                onChange={(e) => {
                                    setQuery(e.target.value);
                                    setPage(0);
                                }}
                                placeholder={t(searchPlaceholder)}
                                className="h-9 w-full min-w-0 border-0 bg-transparent text-sm text-[#111] placeholder:text-[#9ca3af] focus:outline-none"
                            />
                        </div>
                    )}

                    {unapplied.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm">
                                    <ListFilter />
                                    {t('أضف فلتر')}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="min-w-52">
                                {unapplied.map(({ f, i }) => (
                                    <DropdownMenuSub key={i}>
                                        <DropdownMenuSubTrigger>{t(f.label)}</DropdownMenuSubTrigger>
                                        <DropdownMenuSubContent className="max-h-72 overflow-y-auto">
                                            {(f.options ?? []).map((o) => (
                                                <DropdownMenuItem
                                                    key={o.value}
                                                    onSelect={() => pick(i, o.value)}
                                                >
                                                    {t(o.label)}
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuSubContent>
                                    </DropdownMenuSub>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}

                    {/* المدى الزمنيّ في الشريط — انظر `dateFilters` */}
                    {dateFilters.map(({ f, i }) => (
                        <label
                            key={i}
                            className="flex h-8 items-center gap-1.5 rounded-[8px] px-2 text-[13px] transition-colors hover:bg-[rgba(17,17,17,0.045)] focus-within:bg-[rgba(17,17,17,0.045)]"
                        >
                            <span className="whitespace-nowrap text-[#6b7280]">{t(f.label)}</span>
                            <Input
                                type="date"
                                aria-label={t(f.label)}
                                value={active[i] ?? ''}
                                onChange={(e) => pick(i, e.target.value)}
                                className="h-auto w-[8.5rem] min-w-0 border-0 bg-transparent p-0 text-[13px] focus:shadow-none"
                            />
                        </label>
                    ))}

                    {sortable.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm">
                                    <ArrowUpDown />
                                    {sort
                                        ? t(columns.find((c) => c.key === sort.key)?.header ?? 'ترتيب')
                                        : t('ترتيب')}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="min-w-48">
                                {sortable.map((c) => (
                                    <DropdownMenuItem key={c.key} onSelect={() => toggleSort(c.key)}>
                                        <span className="flex-1">{t(c.header)}</span>
                                        {sort?.key === c.key &&
                                            (sort.dir === 'asc' ? (
                                                <ChevronUp className="size-3.5" />
                                            ) : (
                                                <ChevronDown className="size-3.5" />
                                            ))}
                                    </DropdownMenuItem>
                                ))}
                                {sort && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem onSelect={clearSort}>
                                            {t('بلا ترتيب')}
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}

                    {views && (
                        <div className="ms-auto flex items-center gap-0.5">
                            {views.options.map((v) => {
                                const Icon = v.icon;
                                const on = v.key === views.current;

                                return (
                                    <Button
                                        key={v.key}
                                        variant={on ? 'subtle' : 'ghost'}
                                        size="icon-sm"
                                        aria-pressed={on}
                                        title={t(v.label)}
                                        aria-label={t(v.label)}
                                        onClick={() => views.onChange(v.key)}
                                    >
                                        <Icon />
                                    </Button>
                                );
                            })}
                        </div>
                    )}

                    {toolbar && <div className={cn(!views && 'ms-auto')}>{toolbar}</div>}
                </div>
            )}

            {/* الفلاتر المطبَّقة — شريحةٌ لكلٍّ منها تُنزع بضغطة */}
            {applied.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 px-4 pb-2">
                    {applied.map(({ f, i }) => (
                        <span
                            key={i}
                            className="inline-flex items-center gap-1.5 rounded-full bg-[#f2f2f0] py-1 pe-1.5 ps-3 text-[13px] text-[#111]"
                        >
                            <span className="text-[#6b7280]">{t(f.label)}:</span>
                            {t(valueLabel(f, active[i]))}
                            <button
                                type="button"
                                onClick={() => pick(i, '')}
                                aria-label={t('إزالة الفلتر')}
                                className="rounded-full p-0.5 text-[#6b7280] transition-colors hover:bg-[rgba(17,17,17,0.08)] hover:text-[#111]"
                            >
                                <X className="size-3.5" />
                            </button>
                        </span>
                    ))}
                    {clearable.filter(({ i }) => active[i]).length > 1 && (
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="text-[13px] text-[#6b7280] transition-colors hover:text-[#111]"
                        >
                            {t('مسح الكل')}
                        </button>
                    )}
                </div>
            )}

            {/* فلتر الحالة شريطًا — أوّل ما يُسأل عنه لا يُخبَّأ خلف زرّ.
                ونقطةُ كلٍّ بلونها من خريطة الشارات، فيتّفق الشريط مع شارات
                الصفوف تحته. و«الكل» رماديّة: ليست حالةً بل غيابُ تصفية. */}
            {tabFilters.map(({ f, i }) => (
                <Tabs
                    key={i}
                    tabs={[
                        { key: '', label: 'الكل', dot: NEUTRAL_DOT },
                        /*
                         * اللون من التسمية لا من القيمة.
                         *
                         * أكثر القوائم تجعلهما واحدًا («مدفوع» قيمةً واسمًا)،
                         * لكنّ المنتجات تُرسل `active`/`inactive` إلى الخادم
                         * وتكتب «مفعّل»/«غير مفعّل» للقارئ. والخريطة عربيّة،
                         * فالقيمةُ فيها لا تُعرف وتسقط النقطتان إلى الرمادي.
                         */
                        ...(f.options ?? []).map((o) => ({
                            key: o.value,
                            label: o.label,
                            dot: statusDot(o.label),
                        })),
                    ]}
                    current={active[i] ?? ''}
                    onChange={(k) => pick(i, k)}
                    className="px-4"
                />
            ))}

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
                        {columns.map((column) => {
                            // يُرتَّب حيث يُرتَّب فعلًا — انظر `sortable`
                            const sorts = sortable.some((c) => c.key === column.key);

                            return (
                                <TableHead
                                    key={column.key}
                                    className={cn(
                                        column.align === 'end' && 'text-end',
                                        column.align === 'center' && 'text-center',
                                        sorts && 'cursor-pointer select-none hover:text-[#111]',
                                        column.className,
                                    )}
                                    onClick={sorts ? () => toggleSort(column.key) : undefined}
                                >
                                    <span className="inline-flex items-center gap-1">
                                        {t(column.header)}
                                        {sorts &&
                                            sort?.key === column.key &&
                                            (sort.dir === 'asc' ? (
                                                <ChevronUp className="size-3.5" />
                                            ) : (
                                                <ChevronDown className="size-3.5" />
                                            ))}
                                    </span>
                                </TableHead>
                            );
                        })}
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
