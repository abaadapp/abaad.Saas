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
    actions: Record<string, string>;
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
}

export default function EmployeeCreate() {
    const { branches, branchOptions, jobTitles, currentBranchName, sections, actions, may_read_payroll, grantable, blockedTitles, titleGrants } =
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
                mayReadPayroll={may_read_payroll}
                branches={branches}
                branchOptions={branchOptions}
                jobTitles={jobTitles}
                defaultBranch={currentBranchName}
                sections={sections}
                actions={actions}
                grantable={grantable}
                blockedTitles={blockedTitles}
                titleGrants={titleGrants}
            />
        </AdminLayout>
    );
}
