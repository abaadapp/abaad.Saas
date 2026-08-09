import { type ReactNode, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Toaster, toast } from 'sonner';
import ImpersonationBar from '@/Components/ImpersonationBar';
import Sidebar from '@/Components/Sidebar';
import SubscriptionBanner from '@/Components/SubscriptionBanner';
import Topbar from '@/Components/Topbar';
import type { NavGroup } from '@/lib/nav';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface AdminLayoutProps {
    title: string;
    children: ReactNode;
    /** قائمة الشريط الجانبي — تمرّرها لوحة المنصة عبر PlatformLayout */
    nav?: NavGroup[];
    /** السطر تحت الشعار في الشريط الجانبي */
    sidebarSubtitle?: string;
}

export default function AdminLayout({ title, children, nav, sidebarSubtitle }: AdminLayoutProps) {
    const { flash } = usePage<PageProps>().props;
    const t = useTranslate();
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // رسائل الجلسة القادمة من الخادم تُعرض كـtoast
    useEffect(() => {
        if (!flash?.toast) return;
        const { msg, type, undo } = flash.toast;
        const fn =
            type === 'success' ? toast.success
            : type === 'danger' ? toast.error
            : type === 'warning' ? toast.warning
            : toast;

        /*
         * «تراجع» بعد الحذف — هنا تصل قيمة سلّة المحذوفات إلى صاحب النشاط.
         *
         * السلّة مدفونة في الإعدادات، ومن حذف بالخطأ لا يعرف بوجودها فيتّصل
         * بالدعم. والزرّ يردّ الخطأ في مكانه، ومهلته أطول من المعتاد لأن من
         * يكتشف الخطأ يحتاج ثانيةً ليقرأ ما حدث قبل أن يتصرّف.
         */
        fn(msg, undo ? {
            duration: 12000,
            action: {
                label: t('تراجع'),
                onClick: () => router.post(undo.url, {}, { preserveScroll: true }),
            },
        } : undefined);
    }, [flash?.toast, t]);

    return (
        <div className="admin-ui min-h-screen">
            <Head title={title} />

            <ImpersonationBar />

            <Sidebar
                open={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
                nav={nav}
                subtitle={sidebarSubtitle}
            />

            {/* الهامش من جهة الشريط نفسه: يمينًا في العربية ويسارًا في الإنجليزية */}
            <div className="lg:ms-64">
                <Topbar onMenuClick={() => setSidebarOpen(true)} />

                {/* حاوية موحّدة: عرضٌ أقصى وتوسيطٌ وهامشٌ سائل لكل صفحات اللوحة
                    في مكان واحد — بدل أن تخترع كل صفحة max-w خاصًّا بها. */}
                <motion.main
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
                    className="ui-main"
                >
                    <SubscriptionBanner />
                    {children}
                </motion.main>
            </div>

            <Toaster position="bottom-center" richColors dir="rtl" />
        </div>
    );
}
