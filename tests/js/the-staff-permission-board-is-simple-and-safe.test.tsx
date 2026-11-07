import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';
import EmployeeForm, { type EmployeeFormValues } from '@/Pages/Admin/Employees/partials/EmployeeForm';
import { PERMISSION_GROUPS, groupPermissions, searchable } from '@/Pages/Admin/Employees/partials/permissionGroups';
import type { Branch } from '@/types/models';
import { pageProps } from './setup';

/**
 * لوحةُ صلاحيات الموظّف: بطاقاتٌ وبحثٌ وتصفية — والقائمةُ هي هي.
 *
 * ═══ ما يُحرس ═══
 *
 *   - كلُّ مفتاحٍ أرسله الخادم يُعرض مرّةً واحدة، وما لم تذكره مجموعةٌ في «أخرى».
 *   - البحثُ والتصفيةُ والطيُّ تُخفي ولا تنزع: ما خفي يُرسَل كما هو.
 *   - «تحديد الكل» يمسّ أقسامَ المجموعة التي يملك الفاعلُ منحَها — لا فعلًا
 *     حسّاسًا، ولا مربّعًا معطَّلًا. و«إلغاء التحديد» مثله.
 *   - «اتبع الوظيفة» عرضٌ لا مقبض، والباقةُ التي لا تبيع التخصيص لا تفتحه.
 *   - موظّفٌ لم تُمسّ صلاحياتُه يُحفظ بقائمته كما جاءت — ولو فيها مفتاحٌ لا يُعرض.
 *   - حمولةُ متجرٍ آخر تُرسم وحدها: لا يبقى من الأوّل اسمٌ ولا تأشير.
 *
 * والخادمُ يحرس ما يُحفظ: `TheStaffPermissionScreenKeepsEveryGuardTest`.
 */

const BRANCHES = [{ id: 1, name: 'الرئيسي' }] as unknown as Branch[];

const SECTIONS: Record<string, string> = {
    dashboard: 'لوحة التحكم',
    reports: 'التقارير',
    customers: 'العملاء',
    orders: 'المبيعات',
    pos: 'نقطة البيع',
    finance: 'المالية',
    expenses: 'مصاريف شهرية',
    settings: 'الإعدادات',
    mystery: 'قسمٌ لم يُصنَّف',
};
const ACTIONS: Record<string, string> = {
    'order.edit': 'تعديل فواتير المبيعات وطلبات الموقع',
    'pos.movement': 'قيد مبسّط من نقطة البيع',
    'credit.override': 'ضبط ائتمان العميل وتجاوز حدّه',
    'customerPayment.cancel': 'إلغاء تحصيل عميل',
    'business.export': 'تنزيل نسخة احتياطية من بيانات النشاط',
};
const HINTS = { 'business.export': 'لصاحب النشاط وحده.' };
const TITLE_GRANTS: Record<string, string[]> = { كاشير: ['pos'], محاسب: ['pos', 'finance', 'customerPayment.cancel'] };

const CUSTOM: EmployeeFormValues = {
    id: 7,
    name: 'موظّف',
    job_title: 'كاشير',
    branch: 'الرئيسي',
    phone: '96890000000',
    email: 'staff@abaadapp.om',
    username: 'staff',
    on_domain: true,
    status: 'نشط',
    // ومفتاحٌ لا تعرضه الشاشة — يُرسَل كما جاء
    permissions: ['pos', 'order.edit', 'legacy.hidden'],
    role_permissions: ['pos'],
};

let sent: Record<string, unknown>[] = [];

beforeEach(() => {
    sent = [];
    for (const method of ['put', 'post'] as const) {
        vi.spyOn(core, method).mockImplementation(((_url: string, data: Record<string, unknown>) => {
            sent.push(data);
        }) as never);
    }
});

afterEach(() => {
    vi.restoreAllMocks();
    delete (pageProps.auth as Record<string, unknown>).planFeatures;
});

function draw(employee?: EmployeeFormValues, extra: Partial<React.ComponentProps<typeof EmployeeForm>> = {}) {
    return render(
        <EmployeeForm
            branches={BRANCHES}
            branchOptions={[{ value: 1, label: 'الرئيسي' }]}
            jobTitles={Object.keys(TITLE_GRANTS)}
            employee={employee}
            sections={SECTIONS}
            actions={ACTIONS}
            actionHints={HINTS}
            titleGrants={TITLE_GRANTS}
            {...extra}
        />,
    );
}

const box = (label: string) => screen.getByLabelText(label) as HTMLInputElement;
const card = (id: string) => screen.getByTestId(`permission-group-${id}`);
const search = (text: string) => fireEvent.change(screen.getByLabelText('ابحث عن صلاحية أو قسم'), { target: { value: text } });
const save = async () => {
    await act(async () => {
        fireEvent.submit(document.querySelector('form')!);
    });
};

describe('المجموعات: كلُّ مفتاحٍ مرّةً واحدة', () => {
    it('يوزّع المفاتيح على بطاقاتٍ ولا يكرّر مفتاحًا', () => {
        const groups = groupPermissions(SECTIONS, ACTIONS, HINTS);
        const keys = groups.flatMap((g) => g.items.map((it) => it.key));

        expect(new Set(keys).size).toBe(keys.length);
        expect(keys.sort()).toEqual([...Object.keys(SECTIONS), ...Object.keys(ACTIONS)].sort());
    });

    it('يضع ما لم تذكره مجموعةٌ في «أخرى» — ولا يخترع ما لم يُرسَل', () => {
        const groups = groupPermissions(SECTIONS, ACTIONS);

        expect(groups.find((g) => g.id === 'other')!.items.map((it) => it.key)).toEqual(['mystery']);
        // «المنتجات» في تعريف المجموعات ولم يُرسلها الخادم هنا — فلا تُعرض
        expect(groups.flatMap((g) => g.items).some((it) => it.key === 'products')).toBe(false);
    });

    it('لا يذكر مفتاحًا في مجموعتين', () => {
        const listed = PERMISSION_GROUPS.flatMap((g) => g.keys);

        expect(new Set(listed).size).toBe(listed.length);
    });

    it('الحسّاسُ ما جاء في الأفعال وحدها', () => {
        const items = groupPermissions(SECTIONS, ACTIONS).flatMap((g) => g.items);

        expect(items.filter((it) => it.sensitive).map((it) => it.key).sort()).toEqual(Object.keys(ACTIONS).sort());
    });

    it('يرسم كلَّ مربّعٍ مرّةً واحدة في الشاشة', () => {
        draw(CUSTOM);

        for (const label of [...Object.values(SECTIONS), ...Object.values(ACTIONS)]) {
            expect(screen.getAllByLabelText(label)).toHaveLength(1);
        }
        expect(within(card('other')).getByLabelText('قسمٌ لم يُصنَّف')).toBeInTheDocument();
    });
});

describe('البحثُ والتصفية', () => {
    it('يبحث باسم الصلاحية', () => {
        draw(CUSTOM);
        search('المالية');

        expect(screen.getByLabelText('المالية')).toBeInTheDocument();
        expect(screen.queryByLabelText('نقطة البيع')).toBeNull();
    });

    it('يبحث باسم المجموعة فيعرضها كلَّها', () => {
        draw(CUSTOM);
        search('المبيعات ونقطة');

        expect(within(card('sales')).getByLabelText('نقطة البيع')).toBeInTheDocument();
        expect(within(card('sales')).getByLabelText('قيد مبسّط من نقطة البيع')).toBeInTheDocument();
        expect(screen.queryByTestId('permission-group-money')).toBeNull();
    });

    it('لا يتعثّر بالتشكيل ولا بالهمزة', () => {
        expect(searchable('إلغاء تحصيلُ')).toBe(searchable('الغاء تحصيل'));
        draw(CUSTOM);
        search('الغاء تحصيل');

        expect(screen.getByLabelText('إلغاء تحصيل عميل')).toBeInTheDocument();
    });

    it('يقول إن لم يجد شيئًا', () => {
        draw(CUSTOM);
        search('لا شيء بهذا الاسم');

        expect(screen.getByText('لا صلاحية تطابق بحثك.')).toBeInTheDocument();
    });

    it('«المختارة» تعرض المؤشَّر وحده، و«الحساسة» الأفعالَ وحدها', async () => {
        draw(CUSTOM);

        await userEvent.click(screen.getByTestId('permission-filter-selected'));
        expect(screen.getByLabelText('نقطة البيع')).toBeInTheDocument();
        expect(screen.getByLabelText('تعديل فواتير المبيعات وطلبات الموقع')).toBeInTheDocument();
        expect(screen.queryByLabelText('المالية')).toBeNull();

        await userEvent.click(screen.getByTestId('permission-filter-sensitive'));
        expect(screen.getByLabelText('إلغاء تحصيل عميل')).toBeInTheDocument();
        expect(screen.queryByLabelText('نقطة البيع')).toBeNull();
    });

    it('يعدّ المختارةَ من الإجمالي — في الشاشة وفي كلّ بطاقة', async () => {
        sellsCustom(true);
        draw(CUSTOM);

        // ‏«legacy.hidden» لا يُعرض فلا يُعدّ
        expect(screen.getByTestId('permission-count').textContent).toBe('المختارة 2 من 14');
        expect(screen.getByTestId('permission-filter-all').textContent).toContain('14');
        expect(screen.getByTestId('permission-filter-sensitive').textContent).toContain('5');
        expect(screen.getByTestId('permission-group-count-sales').textContent).toBe('2/4');

        await userEvent.click(box('المالية'));
        expect(screen.getByTestId('permission-count').textContent).toBe('المختارة 3 من 14');
        expect(screen.getByTestId('permission-group-count-money').textContent).toBe('1/2');
    });

    it('يُبقي الاختيارَ حين يُخفيه البحث — ويُرسله', async () => {
        sellsCustom(true);
        draw(CUSTOM);

        await userEvent.click(box('المالية'));
        search('التقارير');
        expect(screen.queryByLabelText('المالية')).toBeNull();
        search('');
        expect(box('المالية').checked).toBe(true);

        search('التقارير');
        await save();
        expect(sent[0].permissions).toEqual(['pos', 'order.edit', 'legacy.hidden', 'finance']);
    });

    it('تُطوى البطاقةُ وتُفتح — والبحثُ يفتحها', async () => {
        draw(CUSTOM);
        const header = within(card('money')).getByRole('button', { expanded: true });

        await userEvent.click(header);
        expect(within(card('money')).queryByLabelText('المالية')).toBeNull();

        search('المالية');
        expect(within(card('money')).getByLabelText('المالية')).toBeInTheDocument();
    });
});

const sellsCustom = (on: boolean) => {
    (pageProps.auth as Record<string, unknown>).planFeatures = { custom_permissions: on };
};

describe('«تحديد الكل» و«إلغاء التحديد» داخل المجموعة', () => {
    it('يحدّد أقسامَ المجموعة ولا يمسّ فعلًا حسّاسًا', async () => {
        draw({ ...CUSTOM, permissions: [] });

        await userEvent.click(within(card('sales')).getByText('تحديد الكل'));

        expect(box('المبيعات').checked).toBe(true);
        expect(box('نقطة البيع').checked).toBe(true);
        expect(box('تعديل فواتير المبيعات وطلبات الموقع').checked).toBe(false);
        expect(box('قيد مبسّط من نقطة البيع').checked).toBe(false);

        await save();
        expect(sent[0].permissions).toEqual(['orders', 'pos']);
    });

    it('لا يحدّد مربّعًا معطَّلًا — ما لا يملك الفاعلُ منحَه', async () => {
        draw({ ...CUSTOM, permissions: [] }, { grantable: ['dashboard'] });

        await userEvent.click(within(card('overview')).getByText('تحديد الكل'));

        expect(box('لوحة التحكم').checked).toBe(true);
        expect(box('التقارير').checked).toBe(false);
        expect(box('التقارير').disabled).toBe(true);
    });

    it('يُلغي أقسامَ المجموعة ويُبقي فعلَها الحسّاس ومعطَّلَها', async () => {
        draw({ ...CUSTOM, permissions: ['orders', 'pos', 'order.edit', 'dashboard', 'reports'] }, { grantable: ['orders', 'pos', 'order.edit', 'dashboard'] });

        await userEvent.click(within(card('sales')).getByText('إلغاء التحديد'));
        expect(box('المبيعات').checked).toBe(false);
        expect(box('نقطة البيع').checked).toBe(false);
        expect(box('تعديل فواتير المبيعات وطلبات الموقع').checked).toBe(true);

        // ‏«التقارير» مؤشَّرةٌ ولا يملك الفاعلُ منحَها — لا يُنزعها جماعيًّا
        await userEvent.click(within(card('overview')).getByText('إلغاء التحديد'));
        expect(box('لوحة التحكم').checked).toBe(false);
        expect(box('التقارير').checked).toBe(true);

        await save();
        expect(sent[0].permissions).toEqual(['order.edit', 'reports']);
    });

    it('لا زرَّ جماعيًّا في مجموعةٍ كلُّها أفعالٌ حسّاسة', () => {
        draw({ ...CUSTOM, permissions: [] });

        expect(within(card('invoices')).queryByText('تحديد الكل')).toBeNull();
        expect(within(card('invoices')).getByLabelText('إلغاء تحصيل عميل')).toBeInTheDocument();
    });

    it('يمسّ الظاهرَ وحده حين يضيّقه البحث', async () => {
        draw({ ...CUSTOM, permissions: [] });
        search('شهرية');

        await userEvent.click(within(card('money')).getByText('تحديد الكل'));
        search('');

        expect(box('مصاريف شهرية').checked).toBe(true);
        expect(box('المالية').checked).toBe(false);
    });

    it('والفعلُ الحسّاس يُؤشَّر بيده وحده', async () => {
        draw({ ...CUSTOM, permissions: [] });

        await userEvent.click(box('تنزيل نسخة احتياطية من بيانات النشاط'));
        expect(box('تنزيل نسخة احتياطية من بيانات النشاط').checked).toBe(true);
        expect(screen.getByTestId('action-hint-business.export').textContent).toBe('لصاحب النشاط وحده.');
    });
});

describe('اتبع الوظيفة، وخصّص، والباقة', () => {
    it('في «اتبع الوظيفة» تُعرض قائمةُ الوظيفة بلا مقبض ولا زرٍّ جماعيّ', () => {
        draw({ ...CUSTOM, permissions: null, job_title: 'محاسب' });

        expect(box('المالية').checked).toBe(true);
        expect(box('إلغاء تحصيل عميل').checked).toBe(true);
        expect(box('المالية').disabled).toBe(true);
        expect(screen.queryByText('تحديد الكل')).toBeNull();
    });

    it('والتخصيصُ يفتح المقابض والأزرار', async () => {
        draw({ ...CUSTOM, permissions: null });

        await userEvent.click(screen.getByText('خصّص لهذا الموظّف'));

        expect(box('المالية').disabled).toBe(false);
        expect(within(card('money')).getByText('تحديد الكل')).toBeInTheDocument();
    });

    it('باقةٌ لا تبيع التخصيص: لا يُفتح، ولا يُحدَّد شيءٌ جماعيًّا', () => {
        sellsCustom(false);
        draw({ ...CUSTOM, permissions: null });

        expect((screen.getByText('خصّص لهذا الموظّف').closest('button') as HTMLButtonElement).disabled).toBe(true);
        expect(screen.queryByText('تحديد الكل')).toBeNull();
        expect(box('نقطة البيع').disabled).toBe(true);
    });

    it('لا يعرض صلاحياتِ حسابه لمن يفتح حسابَه', () => {
        draw(CUSTOM, { canEditPermissions: false });

        expect(screen.queryByTestId('permission-board')).toBeNull();
        expect(screen.getByText('لا يمكنك تعديل صلاحيات حسابك الخاص.')).toBeInTheDocument();
    });
});

describe('الحفظُ لا يمسّ ما لم يُمسّ', () => {
    it('يُرسل القائمةَ المخصّصة كما جاءت — ولو فيها مفتاحٌ لا يُعرض', async () => {
        draw(CUSTOM);

        await userEvent.clear(screen.getByDisplayValue('96890000000'));
        await userEvent.type(screen.getByPlaceholderText('+968 9xxxxxxx'), '96891111111');
        await save();

        expect(sent[0]).toMatchObject({ manual_permissions: true, permissions: ['pos', 'order.edit', 'legacy.hidden'] });
    });

    it('ومن يتبع وظيفتَه يبقى عليها', async () => {
        draw({ ...CUSTOM, permissions: null });

        search('المالية');
        await userEvent.click(screen.getByTestId('permission-filter-sensitive'));
        await save();

        expect(sent[0]).toMatchObject({ manual_permissions: false });
    });
});

describe('ولا يبقى من متجرٍ شيءٌ في شاشة متجرٍ آخر', () => {
    it('حمولةُ متجرٍ آخر تُرسم وحدها', () => {
        const { rerender } = draw(CUSTOM);
        expect(box('نقطة البيع').checked).toBe(true);

        const B_SECTIONS = { products: 'منتجات المتجر ب', inventory: 'مخزون المتجر ب' };
        rerender(
            <EmployeeForm
                key="shop-b"
                branches={BRANCHES}
                jobTitles={['أمين مخزن']}
                employee={{ ...CUSTOM, id: 99, permissions: ['inventory'], job_title: 'أمين مخزن' }}
                sections={B_SECTIONS}
                actions={{}}
                titleGrants={{ 'أمين مخزن': ['inventory'] }}
            />,
        );

        expect(screen.queryByLabelText('نقطة البيع')).toBeNull();
        expect(screen.queryByLabelText('تعديل فواتير المبيعات وطلبات الموقع')).toBeNull();
        expect(box('مخزون المتجر ب').checked).toBe(true);
        expect(box('منتجات المتجر ب').checked).toBe(false);
        expect(screen.getByTestId('permission-count').textContent).toBe('المختارة 1 من 2');
    });

    it('صفحةُ التعديل تُعيد بناء النموذج لكلّ موظّف', () => {
        const page = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Admin/Employees/Edit.tsx'), 'utf8');

        expect(page).toContain('key={employee.id}');
    });
});
