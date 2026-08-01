import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import Logo from '@/Components/Logo';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { NAV, type NavGroup } from '@/lib/nav';
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

                <nav className="flex-1 overflow-y-auto px-3 pb-4">
                    {nav.map((group, gi) => {
                        const visible = group.items.filter((item) => can(item.section));
                        if (visible.length === 0) return null;

                        return (
                            <div key={gi} className="mb-1">
                                {group.heading && (
                                    <p className="px-3 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wide text-[#9ca3af]">
                                        {t(group.heading)}
                                    </p>
                                )}
                                {visible.map((item) => {
                                    const Icon = item.icon;
                                    const active = current === item.route || current?.startsWith(item.route.replace(/\.index$/, '.'));

                                    return (
                                        <SmartLink
                                            key={item.route}
                                            routeName={item.route}
                                            href={route(item.route)}
                                            onClick={onClose}
                                            className={cn('ui-nav-link', active && 'is-active')}
                                        >
                                            <Icon className="size-[18px] shrink-0" />
                                            <span className="truncate">{t(item.label)}</span>
                                        </SmartLink>
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
