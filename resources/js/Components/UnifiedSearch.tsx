import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    LayoutGrid,
    LoaderCircle,
    Package,
    Receipt,
    Search,
    ShoppingCart,
    Truck,
    Users,
} from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { fold, type PageEntry } from '@/lib/pages';
import { cn } from '@/lib/utils';

interface SearchItem {
    label: string;
    meta: string;
    url: string;
}

interface SearchGroup {
    title: string;
    icon: string;
    items: SearchItem[];
}

/** أيقونات المجموعات كما يسمّيها SearchController */
const ICONS: Record<string, LucideIcon> = {
    package: Package,
    'shopping-cart': ShoppingCart,
    users: Users,
    truck: Truck,
    // بحث المنصّة يُرسل هذه منذ البداية، وكانت تسقط إلى أيقونة الصندوق
    'building-2': Building2,
    // السندات والمعاملات: ما يُطلب برمزه
    receipt: Receipt,
};

/** صفٌّ في القائمة — صفحةٌ من الدليل أو نتيجةٌ من الخادم، وكلاهما يُفتح */
interface Row {
    key: string;
    url: string;
    label: string;
    meta?: string;
    icon: LucideIcon;
    /**
     * أيُترجَم نصُّ الصفّ؟
     *
     * أسماءُ الشاشات نصوصُ واجهةٍ لها مقابلٌ في القاموس، وأسماءُ المنتجات
     * والعملاء وأرقامُ الفواتير بياناتُ التاجر — تمرّ كما كتبها. وتمريرُها
     * على المترجم بلا ضرر اليوم، لكنّه يقول إنّ للاسم ترجمةً تُنتظر.
     */
    ui?: boolean;
    /** أوّل صفٍّ في مجموعته يحمل عنوانها؛ وما تحته لا يعيده */
    heading?: { title: string; icon: LucideIcon };
}

/** ما يُعرض من الدليل حين يُكتب نصّ — قائمةٌ لا تدفع نتائج الخادم خارج الشاشة */
const PAGE_HITS = 6;

/**
 * البحث الموحّد — دليلُ الصفحات أوّلًا، ثمّ ما في القاعدة.
 *
 * كان الصندوق لا يقول شيئًا حتى يُكتب فيه حرفان، فيُفتح ويبقى فارغًا: لا
 * يعرف من نقره ما الذي يقبله — أرقمُ فاتورةٍ أم اسمُ عميلٍ أم اسمُ شاشة؟
 * فيُغلقه ويعود إلى القائمة الجانبية. وصندوقٌ لا يقول ما يقبل يُهجَر.
 *
 * فالنقرةُ وحدها تفتح دليل النظام كلّه: كلّ صفحةٍ بأيقونتها، مجموعةً كما
 * تُعرض في القائمة. ثمّ يضيق الدليل بما يُكتب، وتنضمّ إليه نتائج الخادم —
 * منتجاتٌ وطلبات وعملاء ومورّدون وسنداتٌ برموزها.
 *
 * ولوحةُ المفاتيح تكفي: ⌘K تفتحه من أيّ شاشة، والسهمان يمشيان في القائمة،
 * وEnter يفتح. من يعرف وجهته لا يجب أن يرفع يده إلى الفأرة.
 */
export default function UnifiedSearch({ url, pages }: { url: string; pages: PageEntry[] }) {
    const t = useTranslate();
    const [q, setQ] = useState('');
    const [groups, setGroups] = useState<SearchGroup[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const boxRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    // النقر خارج الصندوق يُغلق القائمة
    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);
        return () => document.removeEventListener('mousedown', away);
    }, []);

    /*
     * ⌘K — أو Ctrl+K على ويندوز.
     *
     * الاختصار المتعارف عليه لصندوق الأوامر في كلّ أداةٍ يعرفها المستخدم،
     * فمن جرّبه هنا ولم يجد شيئًا استنتج أنّ الصندوق ليس منها.
     */
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            /*
             * والموضعُ لا الحرف: لوحةٌ عربية تُخرج «ك» من هذا الزرّ، فشرطٌ
             * على الحرف وحده يجعل الاختصار يعمل بلغةٍ ويسكت بأخرى.
             */
            if ((e.metaKey || e.ctrlKey) && (e.code === 'KeyK' || e.key.toLowerCase() === 'k')) {
                e.preventDefault();
                inputRef.current?.focus();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, []);

    /**
     * الخادم يتجاهل ما دون حرفين، فلا نُتعبه بها. والطلب السابق يُلغى منطقيًا
     * بعلم `alive` كي لا تسبق نتيجة قديمة نتيجةً أحدث منها.
     */
    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setGroups([]);
            setLoading(false);
            return;
        }
        let alive = true;
        setLoading(true);
        const id = setTimeout(async () => {
            try {
                const res = await fetch(`${url}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok || !alive) return;
                const data: { groups?: SearchGroup[] } = await res.json();
                if (!alive) return;
                setGroups(data.groups ?? []);
            } catch {
                // انقطاع عابر — نُبقي آخر نتيجة بدل إفراغ القائمة
            } finally {
                if (alive) setLoading(false);
            }
        }, 300);
        return () => {
            alive = false;
            clearTimeout(id);
        };
    }, [q, url]);

    /*
     * الدليل يُرشَّح هنا لا عند الخادم.
     *
     * أسماء الشاشات معروفةٌ للمتصفّح كلّها، فطلبُها من الخادم يعني انتظار
     * الشبكة لأمرٍ في اليد. والمطابقة تُهمل الهمزة والشدّة — انظر `fold`.
     */
    const hits = useMemo(() => {
        const term = fold(q);
        if (!term) return pages;

        return pages
            .filter((p) => fold(p.label).includes(term) || fold(p.group).includes(term))
            .slice(0, PAGE_HITS);
    }, [pages, q]);

    /*
     * صفوفٌ مسطّحة: العنوان صفةٌ على أوّل صفٍّ من مجموعته لا صفٌّ بنفسه.
     *
     * والسهمان يمشيان على هذه القائمة، فلو كانت العناوين صفوفًا فيها لتوقّف
     * المؤشّر على سطرٍ لا يُفتح — ومن ضغط Enter عليه لا يذهب إلى شيء.
     */
    const rows = useMemo<Row[]>(() => {
        const out: Row[] = [];

        hits.forEach((p, i) => {
            out.push({
                key: `page:${p.href}`,
                url: p.href,
                label: p.label,
                meta: p.group,
                icon: p.icon,
                ui: true,
                heading: i === 0 ? { title: 'الصفحات', icon: LayoutGrid } : undefined,
            });
        });

        groups.forEach((g) => {
            const icon = ICONS[g.icon] ?? Package;

            g.items.forEach((item, i) => {
                out.push({
                    key: `${g.title}:${item.url}:${item.label}`,
                    url: item.url,
                    label: item.label,
                    meta: item.meta,
                    icon,
                    heading: i === 0 ? { title: g.title, icon } : undefined,
                });
            });
        });

        return out;
    }, [hits, groups]);

    // القائمة تبدّلت تحت المؤشّر، فيعود إلى رأسها بدل أن يشير إلى صفٍّ ذهب
    useEffect(() => setActive(0), [rows.length, q]);

    // الصفّ المختار يبقى في الشاشة حين يمشي المؤشّر إلى ما تحت حافّتها
    useEffect(() => {
        listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const go = (row: Row) => {
        setOpen(false);
        setQ('');
        inputRef.current?.blur();
        router.visit(row.url);
    };

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            setOpen(false);
            inputRef.current?.blur();
            return;
        }
        if (!open || rows.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % rows.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i - 1 + rows.length) % rows.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            go(rows[active] ?? rows[0]);
        }
    };

    const typing = q.trim().length > 0;
    const empty = !loading && rows.length === 0;

    /*
     * وقائمةٌ فارغة لا تُفتح على «لا توجد نتائج لـ «»».
     *
     * حسابٌ لا يملك قسمًا واحدًا لا صفحةَ له في الدليل، فنقرةُ الصندوق كانت
     * تفتح صندوقًا يشكو من طلبٍ لم يُكتب.
     */
    const showList = rows.length > 0 || typing;

    return (
        <div ref={boxRef} className="relative hidden w-full max-w-sm sm:block">
            <Search className="pointer-events-none absolute top-1/2 size-4 -translate-y-1/2 text-[#9ca3af] start-3" />
            <Input
                ref={inputRef}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onFocus={() => setOpen(true)}
                onKeyDown={onKey}
                placeholder={t('ابحث عن صفحة أو منتج أو رقم فاتورة…')}
                className="h-10 ps-9 pe-9"
            />
            {loading && (
                <LoaderCircle className="absolute top-1/2 size-4 -translate-y-1/2 animate-spin text-[#9ca3af] end-3" />
            )}

            {open && showList && (
                <div
                    ref={listRef}
                    className="absolute z-30 mt-2 max-h-[70vh] w-full overflow-y-auto rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white shadow-lg start-0"
                >
                    {empty ? (
                        <p className="px-4 py-8 text-center text-sm text-[#9ca3af]">
                            {t('لا توجد نتائج لـ')} «{q.trim()}»
                        </p>
                    ) : (
                        rows.map((row, i) => (
                            <div key={row.key}>
                                {row.heading && (
                                    <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] bg-[#fbfbfa] px-4 py-2 text-[12px] font-semibold text-[#6b7280]">
                                        <row.heading.icon className="size-3.5" />
                                        {t(row.heading.title)}
                                    </div>
                                )}
                                {/*
                                    زرٌّ لا رابط: الوجهة نصٌّ جاهز من الدليل أو من
                                    الخادم، والفتح يمرّ بـrouter.visit فيتبع
                                    لوحةَ المفاتيح والفأرةَ بطريقٍ واحد.
                                */}
                                <button
                                    type="button"
                                    data-active={i === active}
                                    onMouseEnter={() => setActive(i)}
                                    onClick={() => go(row)}
                                    className={cn(
                                        'flex w-full items-center justify-between gap-3 border-b border-[#f2f2f0] px-4 py-2.5 text-start transition-colors',
                                        i === active ? 'bg-[#f5f5f4]' : 'hover:bg-[#fafafa]',
                                    )}
                                >
                                    <span className="flex min-w-0 items-center gap-2.5">
                                        <row.icon className="size-4 shrink-0 text-[#9ca3af]" />
                                        <span className="truncate text-sm text-[#111]">
                                            {row.ui ? t(row.label) : row.label}
                                        </span>
                                    </span>
                                    {row.meta && (
                                        <span className="shrink-0 truncate text-[12px] text-[#9ca3af]">
                                            {row.ui ? t(row.meta) : row.meta}
                                        </span>
                                    )}
                                </button>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
