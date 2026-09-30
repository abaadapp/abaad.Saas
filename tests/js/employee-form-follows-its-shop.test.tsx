import { router as coreRouter } from '@inertiajs/core';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EmployeeForm from '@/Pages/Admin/Employees/partials/EmployeeForm';
import type { Branch } from '@/types/models';

/**
 * قائمةُ المسمّيات في نموذج الموظّف تتبع متجرَها — لا ما رُسم أوّلًا.
 *
 * ═══ العطب ═══
 *
 * كانت `useState(jobTitles)`: تُقرأ عند أوّل رسمٍ ثمّ لا تتبع الخاصّية.
 * وInertia تُبقي المكوّنَ نفسَه حيًّا حين تصل صفحةٌ بالمكوّن نفسِه وحالةٍ
 * محفوظة (كلُّ POST، و«رجوع» و«تقدّم») — فتصل مسمّياتُ متجرٍ آخر والقائمةُ
 * على مسمّيات الأوّل، ومعها ما أُضيف فيه.
 *
 * والاختبارُ يُعيد رسمَ **المكوّن نفسِه** بخصائصَ جديدة (`rerender`) — وهو ما
 * تفعله Inertia حين تحفظ الحالة — لا يركّب مكوّنًا جديدًا يمرّ بلا إصلاح.
 */

const BRANCHES = [{ id: 1, name: 'الرئيسي' }] as unknown as Branch[];
const SECTIONS = { pos: 'نقطة البيع' };

const shop = (jobTitles: string[]) => (
    <EmployeeForm
        branches={BRANCHES}
        branchOptions={[{ value: 1, label: 'الرئيسي' }]}
        jobTitles={jobTitles}
        sections={SECTIONS}
        titleGrants={Object.fromEntries(jobTitles.map((t) => [t, ['pos']]))}
    />
);

const SHOP_A = ['كاشير', 'TENANT-A-ONLY'];
const SHOP_B = ['كاشير', 'TENANT-B-ONLY'];

/** قائمةُ «الوظيفة / الدور» — تُعرف بخيار «كاشير» الذي في المتجرين */
const titleSelect = () =>
    [...document.querySelectorAll('select')].find((el) => [...el.options].some((o) => o.value === 'كاشير'))! as HTMLSelectElement;
const options = () => [...titleSelect().options].map((o) => o.value).filter(Boolean);

/** إضافةُ مسمًّى من نافذته — والخادمُ يقبل */
async function addTitle(name: string) {
    vi.spyOn(coreRouter, 'post').mockImplementation(((_url: string, _data: unknown, opts?: { onSuccess?: (p: unknown) => void; onFinish?: (v: unknown) => void }) => {
        opts?.onSuccess?.({ props: {} });
        opts?.onFinish?.({});
    }) as never);

    await userEvent.click(screen.getByRole('button', { name: 'إضافة وظيفة' }));
    await userEvent.type(screen.getByPlaceholderText('مثال: مشرف الصالة'), name);
    await userEvent.click(screen.getByRole('button', { name: 'إضافة' }));
}

afterEach(() => vi.restoreAllMocks());

describe('المسمّياتُ تتبع المتجرَ الحاليّ', () => {
    it('تسقط مسمّياتُ A حين تصل خاصّيّةُ B إلى المكوّن نفسِه', () => {
        const { rerender } = render(shop(SHOP_A));
        expect(options()).toContain('TENANT-A-ONLY');

        rerender(shop(SHOP_B));

        expect(options()).not.toContain('TENANT-A-ONLY');
        expect(options()).toContain('TENANT-B-ONLY');
    });

    it('ولا يعبر مسمًّى أُضيف في A إلى B', async () => {
        const { rerender } = render(shop(SHOP_A));
        await addTitle('TENANT-A-ADDED');
        expect(options()).toContain('TENANT-A-ADDED');

        rerender(shop(SHOP_B));

        expect(options()).not.toContain('TENANT-A-ADDED');
        expect(options()).not.toContain('TENANT-A-ONLY');
        expect(options()).toContain('TENANT-B-ONLY');
    });

    it('ولا يبقى مختارًا في النموذج مسمًّى من A', async () => {
        const { rerender } = render(shop(SHOP_A));
        await userEvent.selectOptions(titleSelect(), 'TENANT-A-ONLY');
        expect(titleSelect().value).toBe('TENANT-A-ONLY');

        rerender(shop(SHOP_B));

        expect(titleSelect().value).not.toBe('TENANT-A-ONLY');
    });
});

describe('والإضافةُ في المتجر نفسِه تعمل كما كانت', () => {
    it('يظهر المسمّى الجديد في القائمة ويُختار فورًا — وما كُتب في النموذج باقٍ', async () => {
        render(shop(SHOP_A));
        // «الاسم الكامل» — أوّلُ حقلٍ إلزاميٍّ في النموذج
        const name = [...document.querySelectorAll('input')].find((i) => i.required)!;
        await userEvent.type(name, 'سالم');

        await addTitle('مشرف الصالة');

        expect(options()).toContain('مشرف الصالة');
        expect(titleSelect().value).toBe('مشرف الصالة');
        expect(name.value).toBe('سالم');
    });

    it('ويبقى بعد أن يردّ الخادمُ قائمتَه وفيها المسمّى', async () => {
        const { rerender } = render(shop(SHOP_A));
        await addTitle('مشرف الصالة');

        // ما يفعله `back()` بعد الحفظ: القائمةُ نفسُها من الخادم ومعها الجديد
        await act(async () => rerender(shop([...SHOP_A, 'مشرف الصالة'])));

        expect(options().filter((o) => o === 'مشرف الصالة')).toHaveLength(1);
        expect(titleSelect().value).toBe('مشرف الصالة');
    });
});
