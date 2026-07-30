import type { ReactNode } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PLATFORM_NAV } from '@/lib/nav';

/**
 * قشرة لوحة المنصة.
 *
 * ليست نسخة من قشرة لوحة التاجر بل هي نفسها: AdminLayout ذاته بقائمة تنقّل
 * أخرى. فالثيمة مطابقة بحكم البناء — أي تعديل على الشريط أو الترويسة أو
 * الحاوية يسري على اللوحتين معًا ولا تتباعدان.
 */
export default function PlatformLayout({ title, children }: { title: string; children: ReactNode }) {
    return (
        <AdminLayout title={title} nav={PLATFORM_NAV} sidebarSubtitle="لوحة إدارة المنصة">
            {children}
        </AdminLayout>
    );
}
