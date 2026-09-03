import { type ReactNode, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Toaster, toast } from 'sonner';
import ImpersonationBar from '@/Components/ImpersonationBar';
import Sidebar from '@/Components/Sidebar';
import SubscriptionBanner from '@/Components/SubscriptionBanner';
import Topbar from '@/Components/Topbar';
import { useOnScreenKeyboard } from '@/lib/keyboard';
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

    /*
     * ارتفاع لوحة المفاتيح في `--kb` — هنا كما في نقطة البيع.
     *
     * أكثرُ ما يُكتب في اللوحة يُكتب في نافذةٍ منبثقة: منتجٌ جديد، وقيدٌ،
     * وموظّف. والنافذة موسّطةٌ رأسيًّا، فبلا هذا يبقى زرُّ الحفظ تحت اللوحة
     * على الآيباد — انظر `DialogContent` و`useOnScreenKeyboard`.
     */
    useOnScreenKeyboard();

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
        /* dvh لا vh — انظر التعليق في PosLayout: على الآيباد يقيس vh شاشةً
           بشريط سفاري مطويًّا، فيتغيّر الارتفاع عند أوّل تمرير */
        <div className="admin-ui min-h-dvh">
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

                {/*
                 * سقف العرض هنا وحده، لا في كل صفحة.
                 *
                 * كانت الإعدادات وحدها تضع سقفًا لنفسها وبقيّة الصفحات تمتدّ
                 * بلا حدّ، فيشعر المستخدم أن النظام ينكمش حين ينتقل إليها.
                 * والسقف في الحاوية يجعل الصفحات كلّها بعرضٍ واحد بلا أن
                 * تعرف أيّ صفحةٍ شيئًا عنه.
                 *
                 * و1600 لا أكبر: بعده يتباعد الشريط الجانبي عن المحتوى فتقطع
                 * العينُ مسافةً في كل نظرة.
                 */}
                <motion.main
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
                    className="mx-auto w-full max-w-[1600px] p-4 lg:p-6"
                >
                    <SubscriptionBanner />
                    {children}
                </motion.main>
            </div>

            {/* والرسالة لا تُدفن تحت اللوحة: «تم الحفظ» يقع في القاع نفسه */}
            <Toaster
                position="bottom-center"
                richColors
                dir="rtl"
                offset={{ bottom: 'calc(24px + var(--kb, 0px))' }}
                mobileOffset={{ bottom: 'calc(16px + var(--kb, 0px))' }}
            />
        </div>
    );
}
