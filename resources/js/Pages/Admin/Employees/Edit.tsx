import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import EmployeeForm, { type EmployeeFormValues } from './partials/EmployeeForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types/models';

interface Props {
    employee: EmployeeFormValues;
    branches: Branch[];
    branchOptions: { value: number; label: string }[];
    jobTitles: string[];
    sections: Record<string, string>;
    actions: Record<string, string>;
    /** ومن لا يقرأ الرواتب لا تُرسم له حقولُها — انظر EmployeeController */
    may_read_payroll: boolean;
}

export default function EmployeeEdit() {
    const { employee, branches, branchOptions, jobTitles, sections, actions, may_read_payroll, auth } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="تعديل الموظف">
            {/* إلى ملفّه لا إلى القائمة: التعديل بابٌ داخل الملفّ، والملفّ يردّ إليها */}
            <BackLink
                routeName="admin.employees.show"
                href={route('admin.employees.show', employee.id!)}
                label="ملف الموظف"
            />
            <PageHeader
                title="تعديل الموظف"
                subtitle={`${t('تعديل بيانات')}: ${employee.name}`}
            />
            <EmployeeForm
                mayReadPayroll={may_read_payroll}
                employee={employee}
                branches={branches}
                branchOptions={branchOptions}
                jobTitles={jobTitles}
                sections={sections}
                actions={actions}
                canEditPermissions={auth?.user.id !== employee.id}
            />
        </AdminLayout>
    );
}
