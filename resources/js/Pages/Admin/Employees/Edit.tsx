import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import EmployeeForm, { type EmployeeFormValues } from './partials/EmployeeForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types/models';

interface Props {
    employee: EmployeeFormValues;
    branches: Branch[];
    jobTitles: string[];
    sections: Record<string, string>;
}

export default function EmployeeEdit() {
    const { employee, branches, jobTitles, sections, auth } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="تعديل الموظف">
            <PageHeader
                title="تعديل الموظف"
                subtitle={`${t('تعديل بيانات')}: ${employee.name}`}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الموظفون', href: route('admin.employees.index') },
                    { label: 'تعديل' },
                ]}
            />
            <EmployeeForm
                employee={employee}
                branches={branches}
                jobTitles={jobTitles}
                sections={sections}
                canEditPermissions={auth?.user.id !== employee.id}
            />
        </AdminLayout>
    );
}
