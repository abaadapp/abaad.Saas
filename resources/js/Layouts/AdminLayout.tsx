import { type ReactNode, useEffect, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Toaster, toast } from 'sonner';
import Sidebar from '@/Components/Sidebar';
import Topbar from '@/Components/Topbar';
import type { PageProps } from '@/types';

interface AdminLayoutProps {
    title: string;
    children: ReactNode;
}

export default function AdminLayout({ title, children }: AdminLayoutProps) {
    const { flash } = usePage<PageProps>().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // رسائل الجلسة القادمة من الخادم تُعرض كـtoast
    useEffect(() => {
        if (!flash?.toast) return;
        const { msg, type } = flash.toast;
        const fn =
            type === 'success' ? toast.success
            : type === 'danger' ? toast.error
            : type === 'warning' ? toast.warning
            : toast;
        fn(msg);
    }, [flash?.toast]);

    return (
        <div className="admin-ui min-h-screen">
            <Head title={title} />

            <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

            {/* الهامش من جهة البداية لأن الشريط الجانبي على اليمين في RTL */}
            <div className="lg:me-64">
                <Topbar onMenuClick={() => setSidebarOpen(true)} />

                <motion.main
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
                    className="p-4 lg:p-6"
                >
                    {children}
                </motion.main>
            </div>

            <Toaster position="bottom-center" richColors dir="rtl" />
        </div>
    );
}
