import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import type { InboxAccess } from './partials/InboxAccessField';
import EmployeeForm, { type EmployeeFormValues } from './partials/EmployeeForm';
import type { PermissionGroup } from './partials/PermissionGroups';
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
    actionHints?: Record<string, string>;
    /** كلُّ قسمٍ وتحته ما يُفعل فيه — `SectionActions::groups()` */
    permissionGroups?: PermissionGroup[];
    /**
     * ما يملك الفاعلُ منحَه — يُعطَّل سواه بسببه مكتوبًا.
     *
     * يحسبها الخادمُ منذ البداية ولم تكن تُمرَّر إلى النموذج — فتُرسم
     * المربّعاتُ كلُّها مفتوحة، ويؤشّر المديرُ ما لا يملكه، ويُصفع بـ٤٠٣
     * تمحو النموذج. والحارسُ لم يكن معطوبًا، لكنّ الشاشةَ كانت تَعِد بما
     * يردّه.
     */
    grantable?: string[];
    /** وظائفُ لا يُسندها الفاعل — تُعطَّل في القائمة بدل أن تُردّ عند الحفظ */
    blockedTitles?: string[];
    /** ما تفتحه كلُّ وظيفة — يُعرض حين يُختار «اتبع صلاحيات الوظيفة» */
    titleGrants?: Record<string, string[]>;
    /** ومن لا يقرأ الرواتب لا تُرسم له حقولُها — انظر EmployeeController */
    may_read_payroll: boolean;
    /** «محادثات واتساب» — لمتجرٍ فُتح له الصندوق (`EmployeeController::inboxField`) */
    inbox?: InboxAccess | null;
}

export default function EmployeeEdit() {
    const { employee, branches, branchOptions, jobTitles, sections, actions, actionHints, permissionGroups, may_read_payroll, inbox, grantable, blockedTitles, titleGrants, auth } =
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
                inbox={inbox}
                employee={employee}
                branches={branches}
                branchOptions={branchOptions}
                jobTitles={jobTitles}
                sections={sections}
                actions={actions}
                actionHints={actionHints}
                permissionGroups={permissionGroups}
                grantable={grantable}
                blockedTitles={blockedTitles}
                titleGrants={titleGrants}
                canEditPermissions={auth?.user.id !== employee.id}
            />
        </AdminLayout>
    );
}
