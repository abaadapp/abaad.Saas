import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import EmployeeForm, { type EmployeeFormValues } from '@/Pages/Admin/Employees/partials/EmployeeForm';
import type { Branch } from '@/types/models';

/**
 * «اتبع صلاحيات الوظيفة» — مقبضٌ لم يكن في الشاشة.
 *
 * ═══ العطب ═══
 *
 * `users.permissions` لها معنيان: `null` يعني «اتبع وظيفتك» فتتبدّل صلاحياتُه
 * معها، ومصفوفةٌ تعني «قائمةٌ لك وحدك» لا تتبدّل. والمتحكّم يعالج الأولى في
 * ثلاثة مواضع — والنموذج كان يرسل `manual_permissions: true` أبدًا.
 *
 * فمن خُصّصت صلاحياتُه مرّةً بقي عليها ولو رُقّي. وصاحبُ الباقة الأساسية —
 * وهي لا تبيع التخصيص — لا يجد الحالَ التي اشتراها.
 *
 * ═══ ولمَ يُقرأ ملفُّ المتحكّم نفسُه ═══
 *
 * القاعدةُ في الخادم تقول متى تُطلب القائمة. وحارسٌ يكتبها بيده يصير نسخةً
 * ثانيةً من العقد تفترق يوم تُبدَّل. فيُقرأ `EmployeeController` ويُطلب أن
 * تكون القاعدة `required_if` — لا `required_with` التي تعمل على **حضور**
 * الحقل فتردّ «اتبع الوظيفة» وهي لم تطلب شيئًا.
 */

const CONTROLLER = readFileSync(
    resolve(process.cwd(), 'app/Http/Controllers/Admin/EmployeeController.php'),
    'utf8',
);

const BRANCHES = [{ id: 1, name: 'الرئيسي' }] as unknown as Branch[];

const SECTIONS = { pos: 'نقطة البيع', finance: 'المالية', inventory: 'المخزون' };
const ACTIONS = { 'invoice.override': 'تصحيح فاتورة مكتملة' };

const TITLE_GRANTS: Record<string, string[]> = {
    كاشير: ['pos'],
    محاسب: ['pos', 'finance'],
};

const CUSTOMISED: EmployeeFormValues = {
    id: 5,
    name: 'موظّف',
    job_title: 'كاشير',
    branch: 'الرئيسي',
    phone: null,
    email: 'e@abaadapp.om',
    username: 'e',
    on_domain: true,
    status: 'نشط',
    permissions: ['pos', 'inventory'],
    role_permissions: ['pos'],
};

function draw(employee?: EmployeeFormValues) {
    return render(
        <EmployeeForm
            branches={BRANCHES}
            branchOptions={[{ value: 1, label: 'الرئيسي' }]}
            jobTitles={Object.keys(TITLE_GRANTS)}
            employee={employee}
            sections={SECTIONS}
            actions={ACTIONS}
            titleGrants={TITLE_GRANTS}
        />,
    );
}

/** مربّعُ قسمٍ باسمه المعروض */
const box = (label: string) => screen.getByLabelText(label) as HTMLInputElement;

describe('مصدرُ صلاحيات الموظّف يُختار في الشاشة', () => {
    it('يعرض البابين — اتّباعَ الوظيفة وتخصيصًا له وحده', () => {
        draw(CUSTOMISED);

        expect(screen.getByText('اتبع صلاحيات الوظيفة')).toBeInTheDocument();
        expect(screen.getByText('خصّص لهذا الموظّف')).toBeInTheDocument();
    });

    /**
     * ويُفتح على حال الموظّف كما هي.
     *
     * من خُصّصت له قائمةٌ يُفتح نموذجُه على «خصّص» ومربّعاتُه قائمتُه هو.
     */
    it('يفتح المخصَّصَ على قائمته هو', () => {
        draw(CUSTOMISED);

        expect(box('نقطة البيع').checked).toBe(true);
        expect(box('المخزون').checked).toBe(true);
        expect(box('المالية').checked).toBe(false);
    });

    /** ومن يتبع وظيفتَه يُفتح على «اتبع» لا على «خصّص» */
    it('يفتح المتبِعَ على وظيفته', () => {
        draw({ ...CUSTOMISED, permissions: null });

        // قائمةُ «كاشير» — لا قائمةٌ محفوظةٌ له
        expect(box('نقطة البيع').checked).toBe(true);
        expect(box('المخزون').checked).toBe(false);
        expect(box('نقطة البيع').disabled).toBe(true);
    });

    /**
     * والمربّعاتُ في «اتبع الوظيفة» عرضٌ لا مقبض.
     *
     * نقرةٌ عليها تُغيّر قائمةً لا تُقرأ أصلًا، فيظنّ المديرُ أنّه نزع صلاحيةً
     * وهي قائمة. ومقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض.
     */
    it('يُطفئ المربّعات حين تُتبع الوظيفة', async () => {
        draw({ ...CUSTOMISED, permissions: null });

        expect(box('المالية').disabled).toBe(true);
        expect(box('المخزون').disabled).toBe(true);
    });

    /** والتخصيصُ يفتحها */
    it('يفتح المربّعات حين يُختار التخصيص', async () => {
        draw({ ...CUSTOMISED, permissions: null });

        await userEvent.click(screen.getByText('خصّص لهذا الموظّف'));

        expect(box('المالية').disabled).toBe(false);
    });

    /**
     * والتخصيصُ يبدأ من حيث انتهت الوظيفة.
     *
     * من ضغط «خصّص» يريد أن يزيد أو ينقص، لا أن يبدأ من صفحةٍ بيضاء فيعيد
     * تأشير ما كانت وظيفتُه تمنحه — وأوّلُ حفظةٍ بلا تأشير تُردّ.
     */
    it('يبدأ التخصيصُ من قائمة الوظيفة لا من فراغ', async () => {
        draw({ ...CUSTOMISED, permissions: null });

        await userEvent.click(screen.getByText('خصّص لهذا الموظّف'));

        expect(box('نقطة البيع').checked).toBe(true);
    });

    /**
     * وفي شاشة الإضافة يبدأ التخصيصُ من قائمة الوظيفة كذلك.
     *
     * الموظّفُ الجديد يُفتح نموذجُه بقائمةٍ فارغة. فمن اختار وظيفتَه ثمّ ضغط
     * «خصّص» كان سيجد كلَّ المربّعات خالية — فيعيد تأشير ما تمنحه الوظيفةُ
     * أصلًا، أو يحفظ فيُردّ «حدّد صلاحيات الموظف».
     */
    it('يبدأ تخصيصُ موظّفٍ جديد من قائمة وظيفته المختارة', async () => {
        draw();

        // الوظيفةُ تُختار من القائمة كما يختارها المدير
        const titleBox = [...document.querySelectorAll('select')]
            .find((el) => [...el.options].some((o) => o.textContent === 'محاسب'))!;
        await userEvent.selectOptions(titleBox, 'محاسب');

        await userEvent.click(screen.getByText('خصّص لهذا الموظّف'));

        expect(box('المالية').checked).toBe(true);
        expect(box('نقطة البيع').checked).toBe(true);
        expect(box('المخزون').checked).toBe(false);
    });

    /** والرجوعُ يعرض قائمةَ الوظيفة لا القائمةَ التي كانت */
    it('يعرض قائمةَ الوظيفة عند الرجوع عن التخصيص', async () => {
        draw(CUSTOMISED);
        expect(box('المخزون').checked).toBe(true);

        await userEvent.click(screen.getByText('اتبع صلاحيات الوظيفة'));

        // «كاشير» تفتح نقطة البيع وحدها — والمخزونُ كان تخصيصًا
        expect(box('نقطة البيع').checked).toBe(true);
        expect(box('المخزون').checked).toBe(false);
    });

    /** ومسمًّى جديدٌ يُختار تتبدّل معه المعاينة — لا تبقى على الوظيفة القديمة */
    it('تتبع المعاينةُ المسمّى المختار في النموذج نفسِه', async () => {
        draw({ ...CUSTOMISED, permissions: null, job_title: 'محاسب' });

        expect(box('المالية').checked).toBe(true);
    });

    /**
     * ومسمًّى لا صفَّ له لا يُخترع له جواب.
     *
     * يقع فعلًا: موظّفٌ على الإنتاج يحمل «أمين مخزن» وليست في وظائف متجره.
     */
    it('يقول عن مسمًّى لا وجود له إنّه كذلك', () => {
        draw({ ...CUSTOMISED, permissions: null, job_title: 'أمين مخزن' });

        expect(screen.getByText(/ليس في قائمة الوظائف/)).toBeInTheDocument();
    });
});

describe('والشاشةُ تقرأ ما يقرؤه الخادم', () => {
    /**
     * القاعدةُ تقيس **قيمةَ** العلم لا حضورَه.
     *
     * ولو عادت `required_with` لَردّ الخادمُ كلَّ «اتبع الوظيفة» — والمقبضُ
     * الذي أُضيف هنا يصير بابًا معروضًا لا يُفتح.
     */
    it('القاعدة في الخادم `required_if` لا `required_with`', () => {
        expect(CONTROLLER).toContain("'permissions' => ['required_if:manual_permissions,1', 'array']");
        expect(CONTROLLER).not.toContain("'required_with:manual_permissions'");
    });

    /** والنموذجُ يرسل العلمَ دائمًا — بلا ذلك لا يميّز الخادمُ الحالين */
    it('النموذج يحمل manual_permissions في كلّ حفظة', () => {
        const form = readFileSync(
            resolve(process.cwd(), 'resources/js/Pages/Admin/Employees/partials/EmployeeForm.tsx'),
            'utf8',
        );

        expect(form).toContain('manual_permissions:');
    });
});
