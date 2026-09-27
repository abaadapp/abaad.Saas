import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import EmployeeForm from '@/Pages/Admin/Employees/partials/EmployeeForm';
import { Button } from '@/Components/ui/button';
import type { Branch } from '@/types/models';
import { pageProps } from './setup';

/**
 * شاشةُ الموظّف الجديد تُفتح على وظيفته — لا على تخصيصٍ فارغ.
 *
 * ═══ العطب ═══
 *
 * كانت تُفتح على «خصّص لهذا الموظّف» وقائمتُه فارغة. فمن ملأ الحقولَ وضغط
 * «حفظ» بلا أن يمرّ على قسم الصلاحيات:
 *
 *   • على باقةٍ لا تبيع التخصيص: يُردّ الحفظُ برسالةٍ عن قدرةٍ لم يخترها،
 *     ومربّعاتُ الصلاحيات معطّلةٌ في شاشته — فالبابُ مغلقٌ من الجهتين ولا
 *     يُحفظ الموظّف أبدًا.
 *   • على غيرها: يُحفظ بقائمةٍ فارغة — حسابٌ يدخل ولا يفتح شيئًا.
 *
 * فالأصلُ «اتبع صلاحيات الوظيفة»: وظيفتُه تمنحه، ومن أراد تخصيصًا طلبه.
 */

const BRANCHES = [{ id: 1, name: 'الرئيسي' }] as unknown as Branch[];
const SECTIONS = { pos: 'نقطة البيع', finance: 'المالية', inventory: 'المخزون' };
const TITLE_GRANTS: Record<string, string[]> = { كاشير: ['pos'], محاسب: ['pos', 'finance'] };

function drawCreateScreen() {
    return render(
        <EmployeeForm
            branches={BRANCHES}
            branchOptions={[{ value: 1, label: 'الرئيسي' }]}
            jobTitles={Object.keys(TITLE_GRANTS)}
            sections={SECTIONS}
            titleGrants={TITLE_GRANTS}
        />,
    );
}

const box = (label: string) => screen.getByLabelText(label) as HTMLInputElement;

/**
 * الخيارُ المختار: في دائرته نقطةٌ سوداء، والآخرُ دائرةٌ فارغة.
 *
 * ولا تُقاس بالخلفيّة: غيرُ المختار يحملها في `hover:` — فيُقرأ مختارًا وهو
 * ليس كذلك، ويمرّ الاختبارُ على الحالين.
 */
const card = (label: string) => screen.getByText(label).closest('button')!;
const isPicked = (label: string) => card(label).querySelector('.size-2') !== null;

/** والباقةُ تُقرأ من المشترَك كما تقرؤها `usePlanFeature` */
const sellsCustomPermissions = (on: boolean) => {
    (pageProps.auth as Record<string, unknown>).planFeatures = { custom_permissions: on };
};

afterEach(() => {
    delete (pageProps.auth as Record<string, unknown>).planFeatures;
});

describe('الموظّفُ الجديد يُفتح على وظيفته', () => {
    it('يفتح شاشةَ الإضافة على «اتبع صلاحيات الوظيفة»', () => {
        drawCreateScreen();

        expect(isPicked('اتبع صلاحيات الوظيفة')).toBe(true);
        expect(isPicked('خصّص لهذا الموظّف')).toBe(false);
    });

    /** ومربّعاتُها عرضٌ لا مقبض — فلا تُقرأ قائمةٌ فارغة قائمةً مختارة */
    it('يُطفئ المربّعات في الحال المفتوحة', () => {
        drawCreateScreen();

        expect(box('نقطة البيع').disabled).toBe(true);
        expect(box('المالية').disabled).toBe(true);
    });

    /** وتعرض ما تفتحه الوظيفةُ المختارةُ في النموذج نفسِه */
    it('يعرض ما تمنحه الوظيفةُ المختارة', async () => {
        drawCreateScreen();

        const titleBox = [...document.querySelectorAll('select')]
            .find((el) => [...el.options].some((o) => o.textContent === 'محاسب'))!;
        await userEvent.selectOptions(titleBox, 'محاسب');

        expect(box('المالية').checked).toBe(true);
        expect(box('نقطة البيع').checked).toBe(true);
        expect(box('المخزون').checked).toBe(false);
    });

    /**
     * ═══ وباقةٌ لا تبيع التخصيص لا تُفتح على خيارٍ معطَّل ═══
     *
     * هذا موضعُ الطريق المسدود: الحالُ المفتوحةُ كانت «خصّص» وهو معطّلٌ على
     * هذه الباقة — فلا يُحفظ الموظّف ولا يُفهم لماذا.
     */
    it('لا يفتح على خيارٍ معطَّل حين لا تبيع الباقةُ التخصيص', () => {
        sellsCustomPermissions(false);
        drawCreateScreen();

        expect((card('خصّص لهذا الموظّف') as HTMLButtonElement).disabled).toBe(true);
        expect(isPicked('اتبع صلاحيات الوظيفة')).toBe(true);
    });

    /** ومن يشتريه يجد بابَه مفتوحًا — القفلُ على الباقة لا على الشاشة */
    it('يفتح بابَ التخصيص لمن اشتراه', async () => {
        sellsCustomPermissions(true);
        drawCreateScreen();

        expect((card('خصّص لهذا الموظّف') as HTMLButtonElement).disabled).toBe(false);

        await userEvent.click(screen.getByText('خصّص لهذا الموظّف'));

        expect(box('المالية').disabled).toBe(false);
    });
});

describe('والشاشةُ تقرأ ما يقرؤه الخادم', () => {
    const CONTROLLER = readFileSync(
        resolve(process.cwd(), 'app/Http/Controllers/Admin/EmployeeController.php'),
        'utf8',
    );

    /**
     * العلمُ يصل منطقيًّا لا نصًّا.
     *
     * Inertia ترسل JSON، فـ`manual_permissions` تصل `true` لا `'1'`. و
     * `required_if:…,1` تقارن مقارنةً صارمة فلا تتحقّق أبدًا — قاعدةٌ ميّتة
     * تمرّ معها القائمةُ الفارغة ويُحفظ موظّفٌ لا يفتح شيئًا.
     */
    it('يشترط قاعدةً تقرأ العلمَ المنطقيّ', () => {
        expect(CONTROLLER).toContain("'required_if_accepted:manual_permissions'");
        expect(CONTROLLER).not.toContain("'required_if:manual_permissions,1'");
    });

    /** والرسالةُ معلّقةٌ على مفتاح القاعدة نفسِه — وإلّا ظهرت رسالةُ Laravel الإنجليزية */
    it('يعلّق الرسالةَ على مفتاح القاعدة', () => {
        expect(CONTROLLER).toContain("'permissions.required_if_accepted' =>");
    });
});

describe('وزرُّ الحفظ لا يُضغط مرّتين', () => {
    const FORM = readFileSync(
        resolve(process.cwd(), 'resources/js/Pages/Admin/Employees/partials/EmployeeForm.tsx'),
        'utf8',
    );

    /** الزرُّ يُقفل أثناء الإرسال — لا يُدرج موظّفان بضغطتين */
    it('يقفل الزرَّ ما دام الحفظُ جاريًا', () => {
        render(<Button loading>حفظ الموظف</Button>);

        expect(screen.getByRole('button')).toBeDisabled();
    });

    /** وزرُّ هذا النموذج بعينه موصولٌ بحال الإرسال */
    it('يصل زرَّ النموذج بحال الإرسال', () => {
        expect(FORM).toContain('loading={form.processing}');
    });
});
