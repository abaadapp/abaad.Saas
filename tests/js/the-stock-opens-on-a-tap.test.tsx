import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import CustomArrangementDialog, { type PosTemplate } from '@/Pages/Pos/partials/CustomArrangementDialog';
import type { Addon, Product } from '@/types/models';

/**
 * قائمةُ الموادّ في الطلب المخصَّص — تُفتح بالضغطة لا بالكلمة.
 *
 * ═══ العطب ═══
 *
 * كان الحقلُ لا يُظهر شيئًا حتى يُكتب فيه. والكاشيرُ لا يحفظ أسماءَ الدلاء:
 * فيُلزَم أن يعرف ما يبحث عنه قبل أن يبحث، ويبقى المخزونُ مخفيًّا خلف كلمةٍ
 * لا يعرفها. فيكتب «ورد أحمر» ولا يجده لأنّ اسمه في المتجر «روز ريد»،
 * فيُركّب الباقةَ بلا موادّ — ويخرج الوردُ من الدلو ولا يَنقص من الدفتر.
 *
 * ═══ والمُرشِّحُ واحدٌ هنا وهناك ═══
 *
 * ما تعرضه الشاشةُ هو ما يقبله الخادم: `p.active` هنا، و`$product->active`
 * في `CustomArrangement::components`. وعرضُ صنفٍ موقوفٍ كان سيُردّ بـ٤٢٢ بعد
 * أن يختاره الموظّف — رفضٌ لا يفهم سببَه، على بابٍ فتحته الشاشةُ له.
 */

const PRODUCTS: Product[] = [
    { id: 1, label: 'ورد أبيض', sku: 'ROSE-WHITE', cost: 0.5, qty: 50, active: true },
    { id: 2, label: 'ورد وردي', sku: 'ROSE-PINK', cost: 0.6, qty: 40, active: true },
    { id: 3, label: 'كيس أسود', sku: 'BAG-BLACK', cost: 0.8, qty: 20, active: true },
    // موقوفٌ عن البيع — الخادمُ يردّه، فالشاشةُ لا تعرضه
    { id: 4, label: 'ورد ذابل', sku: 'ROSE-OLD', cost: 0.1, qty: 5, active: false },
] as unknown as Product[];

const ADDONS: Addon[] = [
    { id: 10, label: 'كرت', price: 0.5, active: true },
] as unknown as Addon[];

/**
 * قالبٌ يسمح بالموادّ — وهذا الحارسُ عن حقل البحث لا عن شكل القالب.
 *
 * ولا حقولَ فيه: حقلٌ اسمُه فيه كلمةُ «ورد» كان سيُحسب في `offered`
 * ويجعل الحارسَ يمرّ أو يسقط لسببٍ لا علاقةَ له بالرفّ.
 */
const TEMPLATE: PosTemplate = {
    id: 1,
    name: 'باقة على الطلب',
    modes: ['value', 'budget'],
    default_mode: 'value',
    base_label: 'قيمة الطلب',
    allow_components: true,
    allow_addons: true,
    restockable_default: false,
    fields: [],
};

/** النافذةُ بحمولةٍ ثابتة — يبقى `open` وحدَه متغيّرًا بين التركيبتين */
function Dialog({ open }: { open: boolean }) {
    return (
        <CustomArrangementDialog
            open={open}
            template={TEMPLATE}
            products={PRODUCTS}
            addons={ADDONS}
            money={(v: number) => `${v.toFixed(3)} ر.ع`}
            initial={null}
            onClose={vi.fn()}
            onConfirm={vi.fn()}
        />
    );
}

function open() {
    const { rerender } = render(<Dialog open />);

    const reopen = () => {
        rerender(<Dialog open={false} />);
        rerender(<Dialog open />);
    };

    return { reopen, user: userEvent.setup() };
}

/** حقلُ البحث — النافذةُ فيها حقولٌ أخرى، وهذا وحدَه له هذا النائب */
const field = () => screen.getByPlaceholderText('ابحث عن صنف من المخزون');

/** أسماءُ ما يُعرض من المخزون تحت الحقل */
const offered = () =>
    PRODUCTS.filter((p) => screen.queryAllByText(p.label).length > 0).map((p) => p.label);

describe('قائمةُ المخزون', () => {
    it('لا تُعرض قبل أن تُطلب', () => {
        open();

        expect(offered()).toEqual([]);
    });

    /** وهذا هو المطلوب: ضغطةٌ على الحقل تكشف الرفّ */
    it('تنكشف بالضغط على الحقل بلا كتابة', async () => {
        const { user } = open();
        await user.click(field());

        expect(offered()).toEqual(['ورد أبيض', 'ورد وردي', 'كيس أسود']);
    });

    /** وضغطةٌ ثانيةٌ تُخفيها — المقبضُ نفسُه يفتح ويغلق */
    it('تختفي بالضغطة الثانية', async () => {
        const { user } = open();
        await user.click(field());
        expect(offered()).not.toEqual([]);

        await user.click(field());

        expect(offered()).toEqual([]);
    });

    /** والثالثةُ تعيدها — لا ضغطةَ تُعطّل المقبض */
    it('تعود بالضغطة الثالثة', async () => {
        const { user } = open();
        await user.click(field());
        await user.click(field());
        await user.click(field());

        expect(offered()).toEqual(['ورد أبيض', 'ورد وردي', 'كيس أسود']);
    });

    /**
     * والكتابةُ تفتحها وإن كانت مغلقة.
     *
     * من كتب يطلب جوابًا. وحقلٌ يُكتب فيه ولا يردّ شيئًا لأنّ ضغطةً سابقةً
     * أغلقت قائمتَه يقول للكاشير إنّ الصنفَ غيرُ موجود — وهو في الرفّ.
     */
    it('تنفتح بالكتابة وإن أُغلقت', async () => {
        const { user } = open();
        await user.click(field());
        await user.click(field());
        expect(offered()).toEqual([]);

        await user.type(field(), 'وردي');

        expect(offered()).toEqual(['ورد وردي']);
    });

    /**
     * وتُغلق بالضغطة ولو بقي النصُّ مكتوبًا.
     *
     * ═══ وهذه طفرةٌ نجت قبل أن يُكتب هذا الحارس ═══
     *
     * كان شرطُ العرض `browsing || search !== ''`، والكتابةُ تفتح الحالَ
     * دائمًا — فبدا الشرطان سواءً. وليسا: من كتب «وردي» ثمّ ضغط الحقلَ
     * ليُخفي القائمة يجدها باقيةً فوق نصِّه، والمقبضُ الذي وُعد به لا يُدير
     * شيئًا. والحالُ وحدَها تحكم العرض.
     */
    it('تختفي بالضغطة ولو بقي النصّ مكتوبًا', async () => {
        const { user } = open();
        await user.click(field());
        await user.type(field(), 'وردي');
        expect(offered()).toEqual(['ورد وردي']);

        await user.click(field());

        expect(field()).toHaveValue('وردي');
        expect(offered()).toEqual([]);
    });

    /** والموقوفُ عن البيع لا يُعرض — مُرشِّحُ الخادم نفسُه */
    it('لا تعرض صنفًا موقوفًا عن البيع', async () => {
        const { user } = open();
        await user.click(field());

        expect(screen.queryByText('ورد ذابل')).toBeNull();
    });

    it('تُرشَّح بالكتابة — بالاسم وبالرمز', async () => {
        const { user } = open();
        await user.click(field());

        await user.type(field(), 'وردي');
        expect(offered()).toEqual(['ورد وردي']);

        await user.clear(field());
        await user.type(field(), 'BAG-BLACK');
        expect(offered()).toEqual(['كيس أسود']);
    });

    /**
     * وتُغلق حين تُفتح النافذةُ من جديد.
     *
     * حالُ النافذة تُهيَّأ عند كلّ فتحة — وهذه منها. وبقاؤها مفتوحةً للطلب
     * التالي يُظهر رفًّا لم يطلبه أحد فوق حقلٍ لم يُلمس.
     */
    it('تُغلق حين تُفتح النافذة من جديد', async () => {
        const { user, reopen } = open();
        await user.click(field());
        expect(offered()).not.toEqual([]);

        reopen();

        expect(offered()).toEqual([]);
    });

    /**
     * وتبقى مفتوحةً بعد الاختيار.
     *
     * الباقةُ تُركَّب من موادَّ عدّة، وإغلاقُها بعد كلّ إضافةٍ يجعل الموظّف
     * يضغط الحقلَ خمس مرّات لخمس موادّ — والزبون واقف.
     */
    it('تبقى مفتوحةً بعد اختيار مادّة', async () => {
        const { user } = open();
        await user.click(field());
        await user.clear(field());
        await user.type(field(), 'أبيض');

        await user.click(screen.getByRole('button', { name: 'إضافة' }));

        // خلا الحقلُ ورجعت القائمةُ كاملةً — لا فراغ
        expect(field()).toHaveValue('');
        expect(offered()).toEqual(['ورد أبيض', 'ورد وردي', 'كيس أسود']);
    });
});
