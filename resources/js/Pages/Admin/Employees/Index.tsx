import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { EMPLOYEE_TABS } from '@/Components/SectionTabs';
import BackToSettings from '../Settings/partials/BackToSettings';
import EmployeesPanel, { type JobTitle } from '../Settings/panels/EmployeesPanel';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Employee } from '@/types/models';

/**
 * صفحة الموظفين المستقلّة — الجسم نفسه الذي يُفتح داخل الإعدادات.
 *
 * تبقى لأن رابطها منشورٌ في روابط محفوظة، ولأن «موظف جديد» يعود إليها.
 */
export default function EmployeesIndex() {
    const { employees, jobTitles } = usePage<PageProps<{ employees: Employee[]; jobTitles: JobTitle[] }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="الموظفون">
            <PageHeader
                title="الموظفون"
                subtitle={t(':n موظف', { n: number(employees.length) })}
            />

            <SectionTabs tabs={EMPLOYEE_TABS} current="admin.employees.index" />

            <BackToSettings />
            <EmployeesPanel employees={employees} jobTitles={jobTitles} />
        </AdminLayout>
    );
}
