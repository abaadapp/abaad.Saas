import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import EmployeeForm from './partials/EmployeeForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types/models';

interface Props {
    branches: Branch[];
    branchOptions: { value: number; label: string }[];
    jobTitles: string[];
    currentBranchName: string | null;
    sections: Record<string, string>;
}

export default function EmployeeCreate() {
    const { branches, branchOptions, jobTitles, currentBranchName, sections } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="إضافة موظف">
            <BackLink
                routeName="admin.employees.index"
                href={route('admin.employees.index')}
                label="الموظفون"
            />
            <PageHeader
                title="إضافة موظف"
                subtitle={t('أضف موظفًا جديدًا وحدّد دوره وفرعه')}
            />
            <EmployeeForm
                branches={branches}
                branchOptions={branchOptions}
                jobTitles={jobTitles}
                defaultBranch={currentBranchName}
                sections={sections}
            />
        </AdminLayout>
    );
}
