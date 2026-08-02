import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { LoaderCircle, Package, Search, ShoppingCart, Users } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

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
const ICONS: Record<string, typeof Package> = {
    package: Package,
    'shopping-cart': ShoppingCart,
    users: Users,
};

/**
 * البحث الموحّد — منتجات وطلبات وعملاء في قائمة منسدلة واحدة.
 *
 * كان الشريط العلوي يربط `admin.search` برابط عادي، والمسار يُعيد JSON لا
 * صفحة: فالنقر على «بحث» كان يعرض {"groups":[]} خامًا في المتصفح ويُخرج
 * المستخدم من اللوحة. النقطة كانت مبنية للاستطلاع الحيّ منذ البداية —
 * ينقصها هذا المكوّن فقط.
 */
export default function UnifiedSearch({ url }: { url: string }) {
    const t = useTranslate();
    const [q, setQ] = useState('');
    const [groups, setGroups] = useState<SearchGroup[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const boxRef = useRef<HTMLDivElement>(null);

    // النقر خارج الصندوق يُغلق القائمة
    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);
        return () => document.removeEventListener('mousedown', away);
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
                setOpen(true);
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

    const noResults = !loading && q.trim().length >= 2 && groups.length === 0;

    return (
        <div ref={boxRef} className="relative hidden w-full max-w-sm sm:block">
            <Search className="pointer-events-none absolute top-1/2 size-4 -translate-y-1/2 text-[#9ca3af] start-3" />
            <Input
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onFocus={() => groups.length > 0 && setOpen(true)}
                placeholder={t('ابحث في المنتجات والطلبات والعملاء…')}
                className="h-10 ps-9 pe-9"
            />
            {loading && (
                <LoaderCircle className="absolute top-1/2 size-4 -translate-y-1/2 animate-spin text-[#9ca3af] end-3" />
            )}

            {open && (noResults || groups.length > 0) && (
                <div className="absolute z-30 mt-2 max-h-96 w-full overflow-y-auto rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white shadow-lg">
                    {noResults ? (
                        <p className="px-4 py-8 text-center text-sm text-[#9ca3af]">
                            {t('لا توجد نتائج لـ')} «{q.trim()}»
                        </p>
                    ) : (
                        groups.map((group) => {
                            const Icon = ICONS[group.icon] ?? Package;
                            return (
                                <div key={group.title}>
                                    <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] bg-[#fbfbfa] px-4 py-2 text-[12px] font-semibold text-[#6b7280]">
                                        <Icon className="size-3.5" />
                                        {group.title}
                                    </div>
                                    {group.items.map((item) => (
                                        <Link
                                            key={item.url}
                                            href={item.url}
                                            onClick={() => setOpen(false)}
                                            className="flex items-center justify-between gap-3 border-b border-[#f2f2f0] px-4 py-2.5 transition-colors hover:bg-[#fafafa]"
                                        >
                                            <span className="truncate text-sm text-[#111]">{item.label}</span>
                                            <span className="shrink-0 truncate text-[12px] text-[#9ca3af]">
                                                {item.meta}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
