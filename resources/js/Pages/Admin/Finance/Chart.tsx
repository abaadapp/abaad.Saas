import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import ChartPanel, { type ChartData } from '@/Pages/Admin/Settings/panels/ChartPanel';
import type { PageProps } from '@/types';

/**
 * شجرة الحسابات في صفحتها تحت المالية.
 *
 * والجسم مشترَكٌ مع الإعدادات ‹ المالية: المحاسب يصلها من قسمه، وصاحب النشاط
 * يصلها من إعداداته، وكلاهما يرى الشيء نفسه لأنّه ملفٌّ واحد.
 */
export default function Chart() {
    const { accounts, trial, types } = usePage<PageProps<ChartData>>().props;

    return (
        <AdminLayout title="شجرة الحسابات">
            <PageHeader title="شجرة الحسابات" subtitle="الهيكل الذي تُقرأ عليه كلّ أرقام النشاط" />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.chart" />

            <ChartPanel accounts={accounts} trial={trial} types={types} />
        </AdminLayout>
    );
}
