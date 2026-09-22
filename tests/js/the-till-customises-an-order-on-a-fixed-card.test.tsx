import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import CustomArrangementDialog, { NOTE_MAX, type PosTemplate } from '@/Pages/Pos/partials/CustomArrangementDialog';
import CustomOrderCard from '@/Pages/Pos/partials/CustomOrderCard';
import type { Addon, Product } from '@/types/models';
import { pageProps } from './setup';

/*
 * الصفحةُ كاملةً تُركَّب مرّةً واحدة — بلا هيكلٍ ولا رصيدٍ حيّ.
 *
 * الهيكلُ يقرأ `route().current()` وقوائمَ لا شأنَ لها بالبطاقة، والرصيدُ
 * الحيّ يستطلع الخادم. فيُبدَّلان بأبسط ما يصدق، وتبقى الشبكةُ والسلّةُ
 * والنافذةُ كما هي.
 */
vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * تخصيصُ الطلب من بطاقةٍ ثابتة — يكتب الكاشير، ويرى السعر، ويزيده، ويضيف.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   · البطاقةُ في أوّل الشبكة لا في شريط الأقسام — ومن ضغطها فُتح التخصيص.
 *   · التفاصيلُ تخرج مع الحمولة ملاحظةً — وطولُها طولُ ما يقبله الخادم.
 *   · السعرُ يُحسب من الموادّ والإضافات ويُعرض في الحقل قبل أن يُلمس.
 *   · «+٥» تزيد ما يُعرض، وما كُتب باليد لا يُعاد حسابُه من تحته.
 *   · والوضعُ من القالب: «نهائيّ» إن أذن، وإلّا «قيمةٌ» ناقصَ الإضافات —
 *     فيدفع الزبون في الحالين الرقمَ الذي قرأه.
 */

const PRODUCTS = [
    { id: 1, label: 'ورد أحمر', sku: 'ROSE-RED', price: 2, cost: 0.5, qty: 50, active: true },
    { id: 2, label: 'كيس أسود', sku: 'BAG-BLACK', price: 1, cost: 0.4, qty: 20, active: true },
] as unknown as Product[];

const ADDONS = [{ id: 10, label: 'كرت', price: 0.5, icon: '🎁', active: true }] as unknown as Addon[];

const TEMPLATE: PosTemplate = {
    id: 1,
    name: 'طلب مخصص',
    modes: ['value', 'budget'],
    default_mode: 'value',
    base_label: 'قيمة الطلب',
    allow_components: true,
    allow_addons: true,
    restockable_default: false,
    fields: [],
};

const money = (v: number) => `${v.toFixed(3)} ر.ع`;

function open(over: Partial<Parameters<typeof CustomArrangementDialog>[0]> = {}) {
    const onConfirm = vi.fn();
    render(
        <CustomArrangementDialog
            open
            template={TEMPLATE}
            products={PRODUCTS}
            addons={ADDONS}
            money={money}
            initial={null}
            onClose={vi.fn()}
            onConfirm={onConfirm}
            {...over}
        />,
    );

    return { onConfirm, user: userEvent.setup() };
}

const priceBox = () => screen.getByLabelText('السعر النهائي') as HTMLInputElement;
const addToCart = () => screen.getByRole('button', { name: 'إضافة للسلة' });

/** يختار مادّةً من المخزون باسمها */
async function pick(user: ReturnType<typeof userEvent.setup>, label: string) {
    await user.click(screen.getByPlaceholderText('ابحث عن صنف من المخزون'));
    await user.type(screen.getByPlaceholderText('ابحث عن صنف من المخزون'), label);
    await user.click(screen.getByRole('button', { name: 'إضافة' }));
}

describe('بطاقةُ تخصيص الطلب', () => {
    it('تُرسم باسمها وتفتح التخصيص بالضغطة', async () => {
        const onClick = vi.fn();
        render(<CustomOrderCard label="طلب مخصص" onClick={onClick} />);

        const card = screen.getByTestId('pos-custom-card');
        expect(card).toHaveTextContent('طلب مخصص');
        expect(card).toHaveTextContent('السعر عند التخصيص');

        await userEvent.setup().click(card);
        expect(onClick).toHaveBeenCalledTimes(1);
    });

    /** والبطاقةُ في الشبكة لا في شريط الأقسام — يُقرأ من الشاشة نفسها */
    it('تقف في أوّل شبكة المنتجات لا في شريط الأقسام', () => {
        const src = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Pos/Index.tsx'), 'utf8');

        const grid = src.indexOf('grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-4');
        const card = src.indexOf('<CustomOrderCard');
        const products = src.indexOf('{visibleProducts.map((p) => (');

        expect(grid).toBeGreaterThan(0);
        expect(card).toBeGreaterThan(grid);
        expect(products).toBeGreaterThan(card);
        // ولا زرَّ منقّطًا بقي في الشريط
        expect(src).not.toContain('border-dashed border-gray-300');
    });

    /**
     * والصفحةُ نفسُها: بطاقةٌ تُرسم ولو لم يكن صنفٌ واحد، تفتح التخصيص،
     * ويخرج منه بندٌ في السلّة يحمل التفاصيلَ ملاحظةً.
     */
    it('تُرسم في صفحة الصندوق بلا أصناف، وما يُخصَّص يدخل السلّة بتفاصيله', async () => {
        Object.assign(pageProps, {
            products: [],
            categories: [{ value: 'الكل', label: 'الكل' }],
            customers: [],
            addons: [],
            coupons: [],
            resumeCart: null,
            settings: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
            orderOptions: undefined,
            customOrder: { templates: [TEMPLATE] },
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });
        localStorage.clear();

        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);
        const user = userEvent.setup();

        await user.click(screen.getByTestId('pos-custom-card'));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('تفاصيل الطلب');

        await user.type(screen.getByPlaceholderText('مثال: ٢٠ وردة حمراء، تغليف أسود، شريطة ذهبية'), 'شريطة ذهبية');
        await user.type(priceBox(), '25');
        await user.click(addToCart());

        // في السلّة: اسمُ القالب، وملاحظةُ البند هي التفاصيل
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(screen.getByDisplayValue('شريطة ذهبية')).toBeInTheDocument();
        // سطرُ البند والمجموعُ الفرعيّ كلاهما ٢٥
        expect(screen.getAllByText('25.000 ر.ع').length).toBeGreaterThanOrEqual(2);
    });

    /** والتفاصيلُ تُكتب ملاحظةً على البند بعد أن يُضاف أو يُستبدل — لا قبل */
    it('تكتب التفاصيل ملاحظةً على البند في السلّة', () => {
        const src = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Pos/Index.tsx'), 'utf8');

        const add = src.indexOf('cart.add({ ...line, key });');
        const note = src.indexOf('cart.setNote(key, note);');

        expect(add).toBeGreaterThan(0);
        expect(note).toBeGreaterThan(add);
    });
});

describe('نافذةُ التخصيص', () => {
    it('تخرج بالتفاصيل والسعر المكتوب بلا موادّ', async () => {
        const { onConfirm, user } = open();

        await user.type(screen.getByPlaceholderText('مثال: ٢٠ وردة حمراء، تغليف أسود، شريطة ذهبية'), '٢٠ وردة حمراء وكيس أسود');
        await user.type(priceBox(), '25');
        await user.click(addToCart());

        expect(onConfirm).toHaveBeenCalledTimes(1);
        const [custom, addons, note] = onConfirm.mock.calls[0];
        expect(custom).toMatchObject({ template_id: 1, mode: 'budget', price: 25, base_value: null, components: [] });
        expect(addons).toEqual([]);
        // والأرقامُ الهنديّة تُطبَّع في الحقل نفسه — كما في كلّ حقلٍ في النظام
        expect(note).toBe('20 وردة حمراء وكيس أسود');
    });

    it('لا تُضيف بلا سعر وتقول السبب', () => {
        open();

        expect(addToCart()).toBeDisabled();
        expect(screen.getByText('أدخل السعر النهائي.')).toBeInTheDocument();
    });

    /** والتفاصيلُ لا تتجاوز ما يقبله الخادم في `items.*.note` */
    it('تقف بالتفاصيل عند حدّ الخادم', () => {
        const rules = readFileSync(resolve(process.cwd(), 'app/Http/Controllers/Pos/PosController.php'), 'utf8');
        const m = rules.match(/'items\.\*\.note' => \['nullable', 'string', 'max:(\d+)'\]/);

        expect(m).not.toBeNull();
        expect(NOTE_MAX).toBe(Number(m![1]));

        open();
        expect(screen.getByPlaceholderText('مثال: ٢٠ وردة حمراء، تغليف أسود، شريطة ذهبية')).toHaveAttribute('maxlength', String(NOTE_MAX));
    });

    it('تحسب السعر من الموادّ والإضافات وتعرضه في الحقل', async () => {
        const { user } = open();

        await pick(user, 'أحمر');
        await user.click(screen.getAllByRole('button', { name: '+' })[0]);
        // وردتان بريالين + كرت بنصف
        await user.click(screen.getAllByRole('button', { name: '+' })[1]);

        expect(screen.getByTestId('suggested-price')).toHaveTextContent('4.500 ر.ع');
        expect(priceBox()).toHaveValue('4.5');
        expect(screen.getByTestId('final-price')).toHaveTextContent('4.500 ر.ع');
    });

    it('«+٥» تزيد المحسوبَ وتخرج به', async () => {
        const { onConfirm, user } = open();

        await pick(user, 'أحمر');
        await user.click(screen.getByRole('button', { name: '+5' }));

        expect(priceBox()).toHaveValue('7');

        await user.click(addToCart());
        expect(onConfirm.mock.calls[0][0]).toMatchObject({ mode: 'budget', price: 7, components: [{ product_id: 1, quantity: 1 }] });
    });

    /** ما كُتب باليد يبقى وإن تبدّلت الموادّ بعده */
    it('لا تُعيد حسابَ ما كتبه الكاشير بيده', async () => {
        const { user } = open();

        await user.type(priceBox(), '30');
        await pick(user, 'أحمر');

        expect(priceBox()).toHaveValue('30');
        expect(screen.getByTestId('suggested-price')).toHaveTextContent('2.000 ر.ع');

        // و«= المحسوب» تعيده إلى ما حُسب
        await user.click(screen.getByRole('button', { name: '= المحسوب' }));
        expect(priceBox()).toHaveValue('2');
    });

    /** قالبٌ لا يأذن بالسعر النهائيّ: تُرسل قيمةٌ = الرقم ناقصَ الإضافات */
    it('تُترجم الرقمَ إلى قيمةٍ أساسيّة حين لا يأذن القالب بالنهائيّ', async () => {
        const { onConfirm, user } = open({ template: { ...TEMPLATE, modes: ['value'] } });

        await user.click(screen.getAllByRole('button', { name: '+' })[0]); // كرت +0.5
        await user.clear(priceBox());
        await user.type(priceBox(), '20');
        await user.click(addToCart());

        const [custom, addons] = onConfirm.mock.calls[0];
        expect(custom).toMatchObject({ mode: 'value', price: 19.5, base_value: 19.5 });
        expect(addons).toEqual([{ addon_id: 10, name: 'كرت', price: 0.5, qty: 1 }]);
    });

    it('ترفض رقمًا لا يغطّي الإضافات في وضع القيمة', async () => {
        const { user } = open({ template: { ...TEMPLATE, modes: ['value'] } });

        await user.click(screen.getAllByRole('button', { name: '+' })[0]);
        await user.clear(priceBox());
        await user.type(priceBox(), '0.5');

        expect(addToCart()).toBeDisabled();
        expect(screen.getByText('السعر النهائي لا يغطّي الإضافات.')).toBeInTheDocument();
    });

    /** والتعديلُ يعود بما دفعه الزبون وبتفاصيله وإضافاته */
    it('تعود عند التعديل بالسعر المدفوع والتفاصيل والإضافات', () => {
        open({
            initial: { template_id: 1, template_name: 'طلب مخصص', mode: 'value', price: 20, base_value: 20, fields: [], components: [] },
            initialNote: 'شريطة ذهبية',
            initialAddons: [{ addon_id: 10, name: 'كرت', price: 0.5, qty: 1 }],
        });

        expect(priceBox()).toHaveValue('20.5');
        expect(screen.getByDisplayValue('شريطة ذهبية')).toBeInTheDocument();
        expect(screen.getByText('1')).toBeInTheDocument();
    });
});
