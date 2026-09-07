import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ProductDialog } from '@/Pages/Admin/CustomerInvoices/Create';

/**
 * نافذةُ الكتالوج — كما يراها من يكتب الفاتورة.
 *
 * وهذه أوّلُ اختباراتٍ تُشغّل الواجهة نفسها في هذا المستودع. وكانت تُحرَس
 * بقراءة المصدر: اختبارٌ يفتّش عن `e.key === 'ArrowDown'` في الملفّ فيطمئنّ.
 * وذلك يمنع الحذف ولا يثبت أنّ الضغط ينقل المؤشّر.
 */

const PRODUCTS = [
    { id: 1, name: 'باقة ورد أحمر', sku: 'FLW-014', barcode: '6291041500213', price: 12.5 },
    { id: 2, name: 'إناء زجاجي', sku: 'VAS-٠٠٧', barcode: '6291041500220', price: 4 },
    { id: 3, name: 'بطاقة معايدة', sku: 'CRD-001', barcode: null, price: 0.5 },
];

function open(over: Partial<React.ComponentProps<typeof ProductDialog>> = {}) {
    const onPick = vi.fn();
    const onOpenChange = vi.fn();

    render(
        <ProductDialog
            open
            onOpenChange={onOpenChange}
            products={PRODUCTS}
            truncated={false}
            picked={[]}
            onPick={onPick}
            {...over}
        />,
    );

    return { onPick, onOpenChange, user: userEvent.setup() };
}

/** الصفوفُ المعروضة بأسمائها — الحوارُ يحمل حقلًا وأزرارًا غيرَها */
const rows = () =>
    screen
        .getAllByRole('button')
        .filter((b) => PRODUCTS.some((p) => b.textContent?.includes(p.name)));

describe('البحث', () => {
    it('يجد الصنف باسمه', async () => {
        const { user } = open();
        await user.type(screen.getByRole('textbox'), 'ورد');

        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('باقة ورد أحمر');
    });

    /** ومن يقرأ الرمز من أمر شراء العميل لا يحفظ الاسم */
    it('يجد الصنف برمزه', async () => {
        const { user } = open();
        await user.type(screen.getByRole('textbox'), 'CRD-001');

        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('بطاقة معايدة');
    });

    /** وقارئُ الباركود يكتب أرقامًا ثمّ Enter */
    it('يجد الصنف بباركوده', async () => {
        const { user } = open();
        await user.type(screen.getByRole('textbox'), '6291041500220');

        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('إناء زجاجي');
    });

    /** والهمزةُ تُهمل: من يبحث يكتب أسرعَ ما تصل إليه يده */
    it('يتجاهل الهمزة', async () => {
        const { user } = open();
        await user.type(screen.getByRole('textbox'), 'اناء');

        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('إناء زجاجي');
    });

    /**
     * وشكلُ الرقم يُهمل في الاتجاهين.
     *
     * رمزُ الإناء مكتوبٌ في الكتالوج بأرقامٍ عربية، والباحثُ يكتب لاتينيّة —
     * وقارئُ الباركود لا يكتب غيرها.
     */
    it('يجد رمزًا بأرقام عربية حين يُكتب بلاتينية', async () => {
        const { user } = open();
        await user.type(screen.getByRole('textbox'), 'VAS-007');

        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('إناء زجاجي');
    });

    it('يقول حين لا يجد', async () => {
        const { user } = open();
        await user.type(screen.getByRole('textbox'), 'شيء لا وجود له');

        expect(rows()).toHaveLength(0);
        expect(screen.getByText('لا صنف بهذا الاسم أو الرمز')).toBeInTheDocument();
    });

    it('يقول «لا أصناف» حين يكون الكتالوج فارغًا — لا «لا صنف بهذا الاسم»', () => {
        open({ products: [] });

        expect(screen.getByText(/لا أصناف في الكتالوج بعد/)).toBeInTheDocument();
    });
});

describe('لوحة المفاتيح', () => {
    /** فقارئُ الباركود يمسح ثمّ يضغط Enter — بلا لمسة */
    it('‏Enter يُضيف أوّلَ المطابق', async () => {
        const { onPick, user } = open();
        await user.type(screen.getByRole('textbox'), 'بطاقة{Enter}');

        expect(onPick).toHaveBeenCalledTimes(1);
        expect(onPick.mock.calls[0][0].id).toBe(3);
    });

    it('السهم ينقل المؤشّر، وEnter يُضيف ما تحته', async () => {
        const { onPick, user } = open();
        const box = screen.getByRole('textbox');

        await user.click(box);
        await user.keyboard('{ArrowDown}{ArrowDown}{Enter}');

        expect(onPick.mock.calls[0][0].id).toBe(3);
    });

    /** والقائمةُ دائرة: السهم لأعلى من أوّلها يقع على آخرها */
    it('السهم لأعلى من أوّل الصفوف يدور إلى آخرها', async () => {
        const { onPick, user } = open();

        await user.click(screen.getByRole('textbox'));
        await user.keyboard('{ArrowUp}{Enter}');

        expect(onPick.mock.calls[0][0].id).toBe(3);
    });

    /**
     * ومؤشّرٌ باقٍ على صفٍّ من قائمةٍ سابقة يُضيف غيرَ ما يراه صاحبُه مضيئًا.
     *
     * والبحثُ هنا يُبقي ثلاثةَ صفوفٍ عمدًا: لو قُصّت القائمةُ إلى صفٍّ واحدٍ
     * لسقط المؤشّرُ خارجَها فردّته الحيطةُ (`?? shown[0]`) إلى أوّلها —
     * فيمرّ الاختبارُ وحارسُه مرفوع. أثبتَ ذلك أنّ إسقاطَ التصفير لم يُسقط
     * الاختبارَ حين كان البحثُ يُبقي صفًّا واحدًا.
     */
    it('المؤشّر يعود إلى أوّل الصفوف كلّما تبدّل البحث', async () => {
        const { onPick, user } = open();
        const box = screen.getByRole('textbox');

        await user.click(box);
        await user.keyboard('{ArrowDown}{ArrowDown}');
        // ‏«ا» تُطابق الثلاثة — فالمؤشّر القديم يقع على صفٍّ قائمٍ لا خارجَ القائمة
        await user.type(box, 'ا');
        expect(rows()).toHaveLength(3);

        await user.keyboard('{Enter}');

        expect(onPick.mock.calls[0][0].id).toBe(1);
    });

    it('‏Enter على قائمةٍ فارغة لا يُضيف شيئًا', async () => {
        const { onPick, user } = open();
        await user.type(screen.getByRole('textbox'), 'لا شيء{Enter}');

        expect(onPick).not.toHaveBeenCalled();
    });
});

describe('البقاءُ مفتوحةً', () => {
    /** ففاتورةٌ بخمسة بنودٍ كانت تعني فتحَها خمس مرّات */
    it('لا تُغلق عند الاختيار، وتُفرّغ البحث، وتعدّ ما أُضيف', async () => {
        const { onPick, onOpenChange, user } = open();
        const box = screen.getByRole('textbox');

        await user.type(box, 'ورد{Enter}');

        expect(onPick).toHaveBeenCalledTimes(1);
        expect(onOpenChange).not.toHaveBeenCalled();
        expect(box).toHaveValue('');
        expect(screen.getByText('أُضيف 1 بندًا')).toBeInTheDocument();

        await user.type(box, 'بطاقة{Enter}');

        expect(onPick).toHaveBeenCalledTimes(2);
        expect(screen.getByText('أُضيف 2 بندًا')).toBeInTheDocument();
    });

    it('و«تمّ» وحدها تُغلقها', async () => {
        const { onOpenChange, user } = open();
        await user.click(screen.getByRole('button', { name: 'تمّ' }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    /**
     * والمؤشّرُ يعود إلى الحقل بعد كلّ إضافة — وإلّا كُتب البندُ التالي في العدم.
     *
     * والاختيارُ هنا بالنقر لا بـEnter عمدًا: النقرُ ينقل التركيز إلى الصفّ
     * المنقور، فيصير عودتُه إلى الحقل فعلًا يُثبَت. ومع Enter يكون التركيزُ
     * في الحقل أصلًا، فيمرّ الاختبارُ ولو نُزع السطر — أثبتَ ذلك أنّ نزعَه
     * لم يُسقطه.
     */
    it('يعود المؤشّر إلى حقل البحث بعد إضافةٍ بالنقر', async () => {
        const { user } = open();

        await user.click(rows()[0]);

        expect(screen.getByRole('textbox')).toHaveFocus();
    });
});

describe('ما تقوله القائمة', () => {
    it('تُعلّم ما صار في الفاتورة فلا يُضاف مرّتين سهوًا', async () => {
        const { user } = open({ picked: [2] });
        await user.type(screen.getByRole('textbox'), 'اناء');

        expect(within(rows()[0]).getByText('في الفاتورة')).toBeInTheDocument();
    });

    it('لا تُعلّم ما لم يُضَف', async () => {
        const { user } = open({ picked: [2] });
        await user.type(screen.getByRole('textbox'), 'ورد');

        expect(within(rows()[0]).queryByText('في الفاتورة')).not.toBeInTheDocument();
    });

    it('تعرض السعر بعملة المتجر', () => {
        open();

        expect(screen.getByText('12.500 ر.ع')).toBeInTheDocument();
    });

    /** ومن رأى آخرَ صفٍّ لا يظنّه آخرَ ما في مخزنه */
    it('تقول كم عرضت من كم طابق حين تُقصّ', async () => {
        const many = Array.from({ length: 60 }, (_, i) => ({
            id: 100 + i,
            name: `صنف ${i}`,
            sku: null,
            barcode: null,
            price: 1,
        }));
        const { user } = open({ products: many });

        // ‏أربعون تُرسم من ستّين — والباقي يُقال لا يُبتر صامتًا
        expect(screen.getByText('عُرض 40 من 60 — ضيّق البحث')).toBeInTheDocument();

        await user.type(screen.getByRole('textbox'), 'صنف 1');
        expect(screen.queryByText(/عُرض .* من .* — ضيّق البحث/)).not.toBeInTheDocument();
    });

    it('تقول إنّ الكتالوج أكبر ممّا حُمّل', () => {
        open({ truncated: true });

        expect(screen.getByText(/الكتالوج أكبر ممّا يُحمَّل هنا/)).toBeInTheDocument();
    });

    /** والتحذيرُ يغيب عند البحث: ما لم يصل لا يُبحث فيه، وقد وصل ما يكفي */
    it('ولا تقولها والبحثُ مكتوب', async () => {
        const { user } = open({ truncated: true });
        await user.type(screen.getByRole('textbox'), 'ورد');

        expect(screen.queryByText(/الكتالوج أكبر ممّا يُحمَّل هنا/)).not.toBeInTheDocument();
    });
});
