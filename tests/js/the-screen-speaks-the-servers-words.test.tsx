import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import CustomArrangementDialog, { type PosTemplate } from '@/Pages/Pos/partials/CustomArrangementDialog';
import type { Addon, Product } from '@/types/models';

/**
 * ما تكتبه الشاشةُ هو ما يقرؤه الخادم — بالحرف.
 *
 * ═══ العطب الذي كُتب هذا الملفّ بعده ═══
 *
 * كانت النافذةُ ترسل كميّةَ المادّة باسم `qty`، و`CustomArrangement::rules`
 * تطلبها باسم `quantity`. فكلُّ بيعةٍ مخصَّصةٍ من الصندوق كانت تُردّ بـ٤٢٢.
 *
 * ولم يمسكه شيء: اختباراتُ الخادم تُرسل حمولةً تكتبها بيدها — بالاسم الذي
 * يقبله الخادم — فتمرّ كلُّها. واختباراتُ الشاشة تقرأ ما تعرضه ولا تقرأ ما
 * ترسله. فالطرفان لا يلتقيان في اختبارٍ واحد، وبينهما اسمٌ يفترق.
 *
 * ═══ ولمَ يُقرأ ملفُّ القواعد نفسُه ═══
 *
 * حارسٌ يُعدّد الأسماءَ بيده يصير نسخةً ثالثةً من العقد — تفترق يومَ يُضاف
 * مفتاحٌ إلى القواعد ولا يُضاف إليها. فالمرجعُ واحد: `rules()` تُقرأ، وما
 * طلبته `required_with` يُطلب هنا.
 */

const RULES = readFileSync(resolve(process.cwd(), 'app/Support/CustomArrangement.php'), 'utf8');

/** المفاتيحُ التي يشترطها الخادم تحت قائمةٍ ما — من ملفّ القواعد لا من الذاكرة */
function requiredKeysUnder(list: string): string[] {
    const rows = [...RULES.matchAll(/\$p\.'([a-z_]+)\.\*\.([a-z_]+)'\s*=>\s*\[([^\]]*)\]/g)];

    return rows
        .filter(([, group, , body]) => group === list && body.includes('required_with'))
        .map(([, , key]) => key);
}

const PRODUCTS = [
    { id: 7, label: 'ورد أبيض', sku: 'ROSE-WHITE', cost: 0.5, qty: 50, active: true },
] as unknown as Product[];

const ADDONS = [] as unknown as Addon[];

const TEMPLATE: PosTemplate = {
    id: 3,
    name: 'باقة على الطلب',
    modes: ['value', 'budget'],
    default_mode: 'value',
    base_label: 'قيمة الطلب',
    allow_components: true,
    allow_addons: true,
    restockable_default: false,
    fields: [
        /* اختيارٌ لا نصّ: زرُّه يُنتقى باسمه، وحقلُ النصّ لا يتميّز عن حقل البحث */
        {
            id: 11,
            label: 'لون الشريطة',
            type: 'select',
            required: false,
            internal: false,
            options: [{ id: 91, label: 'ذهبي' }],
        },
    ],
};

/**
 * يملأ النافذةَ بأقلّ ما يُقبل ويعيد الحمولةَ التي خرجت منها.
 *
 * و`flip` يقلب مقبضَ «يعود للمخزون» على المادّة قبل الإضافة.
 */
async function submit({ flip = false }: { flip?: boolean } = {}) {
    const onConfirm = vi.fn();

    render(
        <CustomArrangementDialog
            open
            template={TEMPLATE}
            products={PRODUCTS}
            addons={ADDONS}
            money={(v: number) => `${v.toFixed(3)} ر.ع`}
            initial={null}
            onClose={vi.fn()}
            onConfirm={onConfirm}
        />,
    );

    const user = userEvent.setup();

    /*
     * والحقولُ تُنتقى بشكلها لا بتسميتها: `Label` هنا ليست مربوطةً بـ`htmlFor`،
     * وتسميةُ حقلِ القالب بيدِ التاجر أصلًا فلا يُبنى عليها حارس.
     *
     * والقيمةُ الأساسية `inputMode="decimal"` — انظر `Components/ui/input`:
     * حقلُ رقمٍ بخطوةٍ كسريّة يُرسم نصًّا ليقبل الفاصلة على لوحةِ اللمس.
     */
    const boxes = document.querySelectorAll<HTMLInputElement>('input[inputmode="decimal"]');
    expect(boxes).toHaveLength(1);

    await user.type(boxes[0], '20');
    await user.click(screen.getByRole('button', { name: 'ذهبي' }));
    await user.click(screen.getByPlaceholderText('ابحث عن صنف من المخزون'));
    await user.click(screen.getByRole('button', { name: 'إضافة' }));

    if (flip) {
        await user.click(screen.getByTitle('يعود للمخزون عند الإلغاء'));
    }

    await user.click(screen.getByRole('button', { name: 'إضافة للسلة' }));

    expect(onConfirm).toHaveBeenCalledTimes(1);

    return onConfirm.mock.calls[0][0] as Record<string, unknown> & { components: unknown[] };
}

describe('حمولةُ الطلب المخصَّص', () => {
    it('تحمل كلَّ مفتاحٍ تشترطه قواعدُ الخادم في المكوّنات', async () => {
        const payload = await submit();
        const component = (payload.components as Record<string, unknown>[])[0];


        const required = requiredKeysUnder('components');

        // ولو لم يُقرأ شيءٌ لَمرّ الحارسُ فارغًا — فيُطلب أن يكون قد قرأ
        expect(required.length).toBeGreaterThan(0);

        for (const key of required) {
            expect(component, `الخادم يشترط components.*.${key} ولا ترسله الشاشة`).toHaveProperty(key);
        }
    });

    it('تحمل كلَّ مفتاحٍ تشترطه قواعدُ الخادم في حقول القالب', async () => {
        const payload = await submit();
        const field = (payload.fields as Record<string, unknown>[])[0];

        const required = requiredKeysUnder('fields');
        expect(required.length).toBeGreaterThan(0);

        for (const key of required) {
            expect(field, `الخادم يشترط fields.*.${key} ولا ترسله الشاشة`).toHaveProperty(key);
        }
    });

    /**
     * ولا تُرسل صيغةً قديمة.
     *
     * `rules()` تقبل `flower_value` و`colors` وأختيها لتستأنف سلّةً عُلّقت
     * قبل الترقية — لا لتُكتب من جديد. وإرسالُها من شاشةِ اليوم يُعيد
     * الصيغةَ الأولى إلى الحياة، فيبقى قارئان للأبد.
     */
    it('لا تُرسل مفاتيحَ الصيغة الأولى', async () => {
        const payload = await submit();

        for (const dead of ['flower_value', 'colors', 'packaging_label', 'florist_notes']) {
            expect(payload).not.toHaveProperty(dead);
        }
    });

    /**
     * ومقبضُ «يعود للمخزون» يصل الخادمَ — وهو وحدَه لا يمسكه حارسُ الأسماء.
     *
     * ═══ وهذه طفرةٌ نجت قبل أن يُكتب هذا الحارس ═══
     *
     * `components.*.restockable` قاعدتُها `nullable` لا `required_with`،
     * فحذفُها من الحمولة يمرّ على الحارس الذي يقرأ المشترَط. ولا يمرّ على
     * المتجر: الموظّف يقلب المقبضَ فلا ينقلب شيء، ويُلغى الطلبُ فلا يعود
     * ما وعدت الشاشةُ بعودته — أو يعود ما لا يعود.
     *
     * فيُطلب الطرفان: افتراضُ القالب حين لا يُلمس، والمقلوبُ حين يُلمس.
     */
    it('تحمل سياسةَ الإرجاع كما قالها الموظّف', async () => {
        expect((await submit()).components).toMatchObject([{ restockable: false }]);
    });

    it('تحمل سياسةَ الإرجاع مقلوبةً حين يقلبها الموظّف', async () => {
        expect((await submit({ flip: true })).components).toMatchObject([{ restockable: true }]);
    });

    /** والقالبُ يُقال بمعرّفه: بلا هذا يبيع الخادمُ بأوّل قالبٍ يجده */
    it('تقول أيَّ قالبٍ رُكّبت عليه', async () => {
        const payload = await submit();

        expect(payload.template_id).toBe(TEMPLATE.id);
    });
});
