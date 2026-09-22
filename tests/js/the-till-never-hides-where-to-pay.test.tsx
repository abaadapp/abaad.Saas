import { render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/*
 * الهيكلُ والرصيدُ الحيّ يُبدَّلان — كما في حارس بطاقة التخصيص.
 *
 * `PosLayout` يقرأ `route().current()` وهو غير موجودٍ في بديل الاختبار،
 * و`useLiveStock` يستطلع الخادم. ولا شأنَ لأيٍّ منهما بارتفاع ذيل السلّة.
 */
vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * الصندوق لا يُخفي أين يُدفع — مهما طال ملخّصُه.
 *
 * ═══ العطبُ الذي بلّغ عنه صاحبُ المتجر ═══
 *
 * «يوم فعّلت نقاط الولاء اختفى مال الدفع وما كنت أقدر أسوّي scroll».
 *
 * وذيلُ السلّة كان كتلةً واحدة `shrink-0` بلا تمرير: تأخذ ارتفاعَ محتواها
 * مهما طال، داخل شاشةٍ `overflow-hidden`. وارتفاعُها متغيّر — الكوبون،
 * وبطاقةُ النقاط، وسطرُ خصمها، وسطرُ ما سيُكسب، والضريبة، وتحذيرُ المخزون —
 * فمتجرٌ فعّل الولاء وفتح عميلًا له رصيد يزيد ذيلُه نحوَ مئتي بكسل دفعةً
 * واحدة، فينزل «الإجمالي» وزرُّ الدفع تحت الحافّة ولا سبيل إليهما.
 *
 * وهو ثالثُ عطبٍ من نوعه في هذه الشاشة — والاثنان قبله موثّقان في مصدرها.
 * فالحارسُ على البنية لا على الحالة: ما يُقصّ لا يُقاس في jsdom، لكنّ
 * السببَ يُقرأ — كتلةٌ لا تنكمش ولا تُمرَّر، وزرُّ الدفع بداخلها.
 */

const CUSTOMER = {
    id: 1, name: 'مريم', label: 'مريم — 96899110001', phone: '96899110001',
    points: 500, language: 'ar',
};

const draw = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        translations: {},
        products: [],
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [CUSTOMER],
        addons: [],
        coupons: [],
        // عميلٌ له رصيدٌ وسلّةٌ فيها بضاعة — وبهما تظهر كتلُ الولاء كلُّها
        resumeCart: { id: null, customer: 'مريم', items: [{ id: 1, name: 'باقة ورد', price: 20, qty: 2 }] },
        settings: { loyaltyEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        ...over,
    });
    localStorage.clear();
};

describe('ذيلُ سلّة الصندوق', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    /** أوّلًا: الحالةُ التي فجّرت العطب تُبنى فعلًا — وإلّا حرس الحارسُ لا شيء */
    it('يطول حين تُفعَّل النقاط ويكون للعميل رصيد', async () => {
        draw();
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        expect(screen.getByText(/نقاط العميل:/)).toBeInTheDocument();
        expect(screen.getByText(/سيكسب العميل/)).toBeInTheDocument();
    });

    /**
     * وزرُّ الدفع **خارج** كتلة التمرير.
     *
     * وهذا هو الحارسُ الأهمّ: لو عاد إلى داخلها لعاد العطبُ نفسُه — يمرّر
     * الكاشيرُ ليجد أين يدفع، أو لا يجد.
     */
    it('وزرُّ الدفع في قاعٍ مثبَّتٍ لا داخل ما يُمرَّر', async () => {
        draw();
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        const summary = screen.getByTestId('pos-summary');
        const paybar = screen.getByTestId('pos-paybar');
        const pay = screen.getByRole('button', { name: /الدفع/ });

        expect(paybar).toContainElement(pay);
        expect(summary).not.toContainElement(pay);
        // والقاعُ لا ينكمش ولا يُمرَّر: هو آخرُ ما يُتنازل عنه
        expect(paybar.className).toContain('shrink-0');
    });

    /** والملخّصُ ينكمش ويُمرَّر — فما زاد يُطلب بعجلة لا يُقصّ */
    it('والملخّصُ يُمرَّر حين يطول ولا يُقصّ', async () => {
        draw();
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        const summary = screen.getByTestId('pos-summary');

        expect(summary.className).toContain('overflow-y-auto');
        // و`shrink-0` هي بعينها ما كان يمنع انكماشَه — فلا تعود
        expect(summary.className).not.toContain('shrink-0');
        // و`min-h-0` تأذن بالنزول تحت مقاس المحتوى؛ بدونها لا ينكمش شيء
        expect(summary.className).toContain('min-h-0');
    });

    /**
     * وللبنود أرضيّةٌ لا تنزل تحتها.
     *
     * فالانكماشُ الذي يُنقذ الدفعَ لا يُسكت السلّة: كتلةٌ بلا أرضيّة تُسحق
     * إلى صفرٍ حين يطول الملخّص، فتُقرأ سلّةٌ فارغةٌ وفيها بضاعة.
     */
    it('والبنودُ لا تُسحق إلى صفر', () => {
        const src = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Pos/Index.tsx'), 'utf8');

        expect(src).toMatch(/min-h-\[7rem\] flex-1 overflow-y-auto/);
    });

    /**
     * والسلّةُ تنكمش رأسيًّا تحت ٧٦٨ ولا تنكمش عرضًا فوقها.
     *
     * فوق الحدّ الصفُّ أفقيّ، والانكماشُ يدفعها خارج الإطار فتُقصّ — وهو
     * عطبٌ وقع مرّةً وموثّقٌ في المصدر. وتحته العمودُ رأسيّ، والانكماشُ هو
     * ما يمنع قصَّ ذيلها على الهاتف.
     */
    it('والسلّةُ تنكمش رأسيًّا على الهاتف لا عرضًا على اللوحيّ', () => {
        const src = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Pos/Index.tsx'), 'utf8');
        const aside = src.slice(src.indexOf('<aside className="'), src.indexOf('<aside className="') + 260);

        expect(aside).toContain('md:shrink-0');
        expect(aside).toContain('max-md:min-h-[20rem]');
        // ولا `shrink-0` مطلقةً تعود فتُبطل الأولى
        expect(aside).not.toMatch(/\sshrink-0/);
    });
});
