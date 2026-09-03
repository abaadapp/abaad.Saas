import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { ChevronDown } from 'lucide-react';
import Logo from '@/Components/Logo';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { NAV, type NavGroup, type NavItem } from '@/lib/nav';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface SidebarProps {
    open: boolean;
    onClose: () => void;
    /** قائمة التنقّل — لوحة التاجر افتراضًا، ولوحة المنصة تمرّر قائمتها */
    nav?: NavGroup[];
    /** سطر اختياري تحت الشعار — لوحة المنصة وحدها تمرّره */
    subtitle?: string;
}

export default function Sidebar({ open, onClose, nav = NAV, subtitle }: SidebarProps) {
    const { auth } = usePage<PageProps>().props;
    const t = useTranslate();
    const current = route().current();

    // بلا قسم صلاحية يظهر العنصر دائمًا — انظر NavItem.section
    const can = (section?: string) => !section || (auth?.abilities.includes(section) ?? false);

    /*
     * والباقة سؤالٌ ثانٍ فوق الصلاحية.
     *
     * المالك يملك كلّ الأقسام، وباقتُه قد لا تفتح كلّ الأدوات. والغائب يُعدّ
     * مفتوحًا: صفحةٌ قديمة في المتصفّح لا تحمل الخريطة يجب ألّا تُخفي على
     * صاحبها ما اشتراه — والخادم هو الحارس على كل حال.
     */
    const licensed = (feature?: string) =>
        !feature || (auth?.planFeatures?.[feature] ?? true);

    const shown = (item: NavItem) => can(item.section) && licensed(item.feature);

    /*
     * والأب يقود إلى أوّل ما بقي من بنيه.
     *
     * «أدوات التسويق» تفتح «برنامج ولاء» — فإن لم تشمله الباقة قاد الأبُ إلى
     * 403 وأبناؤه المفتوحون تحته. فيتبع الأب أوّل ابنٍ باقٍ، ويسقط هو إن لم
     * يبقَ منهم أحد.
     */
    const visible = (items: NavItem[]): NavItem[] =>
        items
            .filter(shown)
            .map((i) => {
                if (! i.children) return i;

                const kids = i.children.filter(shown);

                return kids.length === 0 ? null : { ...i, children: kids, route: kids[0].route };
            })
            .filter((i): i is NavItem => i !== null);

    // المطابقة بالعائلة تُبقي العنصر مضيئًا في الصفحات الفرعية
    // (admin.products.create مثلًا)
    const matches = (name: string) =>
        current === name || (current?.startsWith(name.replace(/\.index$/, '.')) ?? false);

    /*
     * عنصرٌ مضيء واحد لا أكثر.
     *
     * مسارُ العنصر نفسه يُقدَّم على ما يغطّيه، وأبناءُ القائمة المنسدلة
     * تُقدَّم على آبائها: «تقييمات العملاء» ابنُ «أدوات التسويق»، فلولا
     * الترتيب لأضاء الأب وحده ولم يُعرف أيّ أداةٍ مفتوحة.
     */
    const flat = visible(nav.flatMap((g) => g.items));
    const leaves = flat.flatMap((i) => i.children ?? [i]);

    const activeRoute =
        leaves.find((i) => matches(i.route))?.route ??
        flat.find((i) => i.covers?.some(matches))?.route;

    /*
     * القائمة المنسدلة تُفتح على الأداة المفتوحة.
     *
     * تركُها مطويّةً يُخفي أين المستخدم: يفتح «إشعارات واتساب» فيرى «أدوات
     * التسويق» مطويّةً كما تركها، ولا شيء يقول إنّ ما يقرؤه من تحتها.
     */
    const openBranch = flat.find((i) => i.children?.some((c) => matches(c.route)))?.route ?? null;
    const [expanded, setExpanded] = useState<string | null>(openBranch);

    useEffect(() => {
        if (openBranch) setExpanded(openBranch);
    }, [openBranch]);

    const link = (item: NavItem, depth = 0) => {
        const Icon = item.icon;

        return (
            <SmartLink
                key={item.route}
                routeName={item.route}
                href={route(item.route)}
                onClick={onClose}
                className={cn('ui-nav-link', item.route === activeRoute && 'is-active')}
                // الإزاحة منطقية لا يمينية: تنقلب مع اتجاه المستند
                style={depth ? { paddingInlineStart: `${12 + depth * 22}px` } : undefined}
            >
                <Icon className={cn('shrink-0', depth ? 'size-4' : 'size-[18px]')} />
                <span className="truncate">{t(item.label)}</span>
            </SmartLink>
        );
    };

    return (
        <>
            {/* خلفية معتمة على الجوال فقط */}
            <AnimatePresence>
                {open && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.2 }}
                        onClick={onClose}
                        className="fixed inset-0 z-30 bg-black/20 backdrop-blur-sm lg:hidden"
                    />
                )}
            </AnimatePresence>

            <aside
                className={cn(
                    // start = يمين في العربية، يسار في الإنجليزية — يتبع اتجاه المستند
                    'fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-[var(--ui-border,#e8e8e8)] bg-white',
                    'transition-transform duration-300 lg:translate-x-0',
                    // الإزاحة على الجوال فقط (max-lg): متغيّرات dir محدِّدها أقوى من lg،
                    // فلو تُركت عامة لبقي الشريط مدفوعًا خارج الشاشة على سطح المكتب.
                    open
                        ? 'translate-x-0'
                        : 'max-lg:rtl:translate-x-full max-lg:ltr:-translate-x-full',
                )}
            >
                {/* الشعار وحده في منتصف رأس الشريط. كان مُلصقًا بحافة البداية
                    وتحته اسم المتجر — والاسم حاضر أصلًا في عنوان اللوحة وفي
                    مبدّل الفروع، فكان تكرارًا يزاحم الشعار.
                    justify-center أفقي هنا لأن الاتجاه عمودي في هذه الكتلة. */}
                <div className="flex h-16 shrink-0 flex-col items-center justify-center gap-1 px-5">
                    <Logo className="h-5 w-auto text-[#111]" />
                    {/* لوحة المنصة وحدها تسمّي نفسها — بلا هذا السطر يتعذّر
                        تمييز لوحتها من لوحة التاجر: القائمتان بالشعار نفسه */}
                    {subtitle && (
                        <p className="truncate text-[12px] font-medium text-[#6b7280]">{t(subtitle)}</p>
                    )}
                </div>

                <nav className="flex flex-1 flex-col overflow-y-auto px-3 pb-4">
                    {nav.map((group, gi) => {
                        const items = visible(group.items);
                        if (items.length === 0) return null;

                        /*
                         * الفاصل بين المجموعات خطٌّ لا عنوان.
                         *
                         * العناوين («المتجر»، «الإدارة») تسميةٌ لا تُقرأ: لا
                         * أحد يبحث عن «المالية» تحت «الإدارة»، ويبحث عن
                         * «المالية». والخطّ يفصل بلا أن يشغل سطرًا ولا أن
                         * يدّعي معنى. ولوحة المنصة تُبقي عناوينها كما هي.
                         */
                        const first = nav.slice(0, gi).every((g) => visible(g.items).length === 0);

                        return (
                            <div
                                key={gi}
                                className={cn(
                                    'mb-1',
                                    // تُدفع «الإعدادات» إلى الأسفل، وخطُّها يفصلها عمّا فوقها
                                    group.footer && 'mt-auto',
                                    !group.heading && !first && 'border-t border-[var(--ui-border,#e8e8e8)] pt-2',
                                )}
                            >
                                {group.heading && (
                                    <p className="px-3 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wide text-[#9ca3af]">
                                        {t(group.heading)}
                                    </p>
                                )}

                                {items.map((item) => {
                                    if (! item.children) {
                                        return link(item);
                                    }

                                    const Icon = item.icon;
                                    const isOpen = expanded === item.route;
                                    const holdsActive = item.children.some((c) => c.route === activeRoute);

                                    return (
                                        <div key={item.route}>
                                            <button
                                                type="button"
                                                aria-expanded={isOpen}
                                                onClick={() => setExpanded(isOpen ? null : item.route)}
                                                className={cn(
                                                    'ui-nav-link w-full',
                                                    // الأب يُعلَّم حين تكون إحدى أدواته مفتوحة —
                                                    // ولا يُضاء كالوجهة: هو لا ينقل إلى صفحة
                                                    holdsActive && 'font-semibold text-[#111]',
                                                )}
                                            >
                                                <Icon className="size-[18px] shrink-0" />
                                                <span className="truncate">{t(item.label)}</span>
                                                <ChevronDown
                                                    className={cn(
                                                        'ms-auto size-4 shrink-0 text-[#9ca3af] transition-transform',
                                                        isOpen && 'rotate-180',
                                                    )}
                                                />
                                            </button>

                                            <AnimatePresence initial={false}>
                                                {isOpen && (
                                                    <motion.div
                                                        initial={{ height: 0, opacity: 0 }}
                                                        animate={{ height: 'auto', opacity: 1 }}
                                                        exit={{ height: 0, opacity: 0 }}
                                                        transition={{ duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
                                                        className="overflow-hidden"
                                                    >
                                                        {item.children
                                                            .filter((c) => can(c.section))
                                                            .map((child) => link(child, 1))}
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </nav>
            </aside>
        </>
    );
}
