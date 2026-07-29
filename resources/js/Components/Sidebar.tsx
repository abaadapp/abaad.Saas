import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Flower } from 'lucide-react';
import { NAV } from '@/lib/nav';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface SidebarProps {
    open: boolean;
    onClose: () => void;
}

export default function Sidebar({ open, onClose }: SidebarProps) {
    const { auth, context } = usePage<PageProps>().props;
    const current = route().current();

    const can = (section: string) => auth?.abilities.includes(section) ?? false;

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
                    'fixed inset-y-0 end-0 z-40 flex w-64 flex-col border-s border-[var(--ui-border,#e8e8e8)] bg-white',
                    'transition-transform duration-300 lg:translate-x-0',
                    open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0',
                )}
            >
                {/* الشعار واسم المتجر — يُقرأ من الخادم لا مكتوبًا في القالب */}
                <div className="flex h-16 shrink-0 items-center gap-3 px-5">
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-[10px] bg-[#111] text-white">
                        <Flower className="size-[18px]" />
                    </span>
                    <p className="truncate text-[15px] font-semibold text-[#111]">
                        {context?.businessName ?? 'Abad POS'}
                    </p>
                </div>

                <nav className="flex-1 overflow-y-auto px-3 pb-4">
                    {NAV.map((group, gi) => {
                        const visible = group.items.filter((item) => can(item.section));
                        if (visible.length === 0) return null;

                        return (
                            <div key={gi} className="mb-1">
                                {group.heading && (
                                    <p className="px-3 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wide text-[#9ca3af]">
                                        {group.heading}
                                    </p>
                                )}
                                {visible.map((item) => {
                                    const Icon = item.icon;
                                    const active = current === item.route || current?.startsWith(item.route.replace(/\.index$/, '.'));

                                    return (
                                        <Link
                                            key={item.route}
                                            href={route(item.route)}
                                            onClick={onClose}
                                            className={cn('ui-nav-link', active && 'is-active')}
                                        >
                                            <Icon className="size-[18px] shrink-0" />
                                            <span className="truncate">{item.label}</span>
                                        </Link>
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
