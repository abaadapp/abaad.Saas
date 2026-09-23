import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import EmployeeForm, { type EmployeeFormValues } from '@/Pages/Admin/Employees/partials/EmployeeForm';
import type { Branch } from '@/types/models';

/**
 * شاشةُ الموظّف تقول القفلَ الذي يفرضه الخادم — لا قفلًا آخر.
 *
 * الخادمُ صار يشترط ثمانيةً فيها حرفٌ ورقم (`AStaffPasswordIsNotAFourDigitPinTest`)،
 * وكانت الشاشةُ تقول «أربعة أحرف على الأقل».
 *
 * وشاشةٌ تعِد بأربعةٍ وخادمٌ يردّ عليها أسوأُ من الاثنين: التاجرُ يكتب ما
 * قيل له فيُردّ، فيظنّ العطبَ في النظام ويعيد المحاولة.
 *
 * ولذلك يُقرأ ملفُّ المتحكّم نفسُه هنا: حارسٌ يكتب القاعدةَ بيده يصير نسخةً
 * ثانيةً تفترق يومَ تُبدَّل. فالمقياسُ أن **ما تقوله الشاشة هو ما يفرضه
 * الخادم** — انظر `permissions-return-to-the-job-title` على المنوال نفسِه.
 */

const CONTROLLER = readFileSync(
    resolve(process.cwd(), 'app/Http/Controllers/Admin/EmployeeController.php'),
    'utf8',
);

const BRANCHES = [{ id: 1, name: 'الرئيسي' }] as unknown as Branch[];

const EXISTING: EmployeeFormValues = {
    id: 5,
    name: 'سالم',
    job_title: 'كاشير',
    branch: 'الرئيسي',
    phone: null,
    email: 'salim@abaadapp.om',
    username: 'salim',
    on_domain: true,
    status: 'نشط',
    permissions: null,
    role_permissions: ['pos'],
};

function draw(employee?: EmployeeFormValues) {
    return render(
        <EmployeeForm
            branches={BRANCHES}
            branchOptions={[{ value: 1, label: 'الرئيسي' }]}
            jobTitles={['كاشير']}
            employee={employee}
            sections={{ pos: 'نقطة البيع' }}
            actions={{}}
            titleGrants={{ كاشير: ['pos'] }}
        />,
    );
}

describe('قفلُ الموظّف يُقال قبل أن يُفرض', () => {
    it('يقول الشرطَ عند التوظيف', () => {
        draw();

        expect(screen.getByText(/ثمانية أحرف على الأقل فيها حرف ورقم/)).toBeInTheDocument();
    });

    it('ولا يَعِد بأربعة', () => {
        draw();

        expect(screen.queryByText(/أربعة أحرف على الأقل/)).not.toBeInTheDocument();
    });

    it('ويقوله عند التعديل كذلك — والفراغُ يبقى «أبقِ الحالية»', () => {
        draw(EXISTING);

        expect(screen.getByText(/اتركها فارغة للإبقاء على الحالية/)).toBeInTheDocument();
        expect(screen.getByText(/ثمانية أحرف فيها حرف ورقم/)).toBeInTheDocument();
    });

    it('وما تقوله الشاشةُ هو ما يفرضه الخادم', () => {
        expect(CONTROLLER).toContain("'min:8'");
        expect(CONTROLLER).toContain("'regex:/[A-Za-z]/'");
        expect(CONTROLLER).toContain("'regex:/[0-9]/'");
        // ولا يبقى البابُ القديم مفتوحًا في موضعٍ منهما
        expect(CONTROLLER).not.toContain("'min:4'");
    });
});
