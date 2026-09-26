import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SaveBar from '@/Pages/Admin/Website/theme/SaveBar';
import Address from '@/Pages/Admin/Website/theme/sections/Address';
import Checkout from '@/Pages/Admin/Website/theme/sections/Checkout';
import { pageProps } from './setup';
import { SCREEN_KEYS, only } from '@/Pages/Admin/Website/theme/sections/form';
import type { ThemeSettingsData } from '@/Pages/Admin/Website/theme/sections/form';
import { THEME_SITE, themeForm } from './theme-form';

/**
 * ضبطُ متجر الواجهة الخاصّة — ستُّ شاشات، كلٌّ تحفظ مفاتيحَها وحدَها.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * الستُّ تكتب في البابِ نفسِه (`marketing.store.save`). فلو أرسلت كلُّ
 * واحدةٍ نموذجَها **كاملًا** لَكتبت فوق ما ضبطته أختُها بقيمٍ التقطتها يومَ
 * فُتحت: يضبط التاجرُ رسمَ التوصيل في «المتجر»، ثمّ يفتح «الظهور في البحث»
 * في لسانٍ كان مفتوحًا قبله ويحفظ — فيعود الرسمُ إلى ما كان، بلا خطأٍ ولا
 * رسالة. ولا يُكتشف إلّا من زبونٍ دفع رسمًا غيرَ الذي في الشاشة.
 *
 * فالقسمةُ في `SCREEN_KEYS`، والإرسالُ بـ`only` — وهما ما يُسأل عنه هنا.
 *
 * ═══ وزرُّ الحفظ واحدٌ في كلّ شاشة ═══
 *
 * وكان في «المتجر والطلبات» زرّان متجاوران لا يحفظ أحدُهما ما يحفظه الآخر:
 * من عدّل رسمَ التوصيل ثمّ ضغط الزرَّ الأسفل أضاع ما كتب ولا شيء يقول له.
 */

const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['website'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    });
};

describe('شريطُ الحفظ', () => {
    beforeEach(reset);

    /**
     * ولا يظهر على صفحةٍ لم يتغيّر فيها شيء.
     *
     * زرُّ حفظٍ دائمُ الظهور يُدرَّب صاحبُه على تجاهله، فلا يعود يقول شيئًا
     * حين يكون له ما يقوله.
     */
    it('لا يظهر قبل أن يتغيّر شيء', () => {
        render(<SaveBar dirty={false} processing={false} onSave={vi.fn()} onReset={vi.fn()} />);

        expect(screen.queryByTestId('save-bar')).toBeNull();
    });

    it('ويظهر عند أوّل تغيير ويقول ما وقع', () => {
        render(<SaveBar dirty processing={false} onSave={vi.fn()} onReset={vi.fn()} />);

        expect(screen.getByTestId('save-bar')).toHaveTextContent('عندك تغييراتٌ لم تُحفظ بعد.');
    });

    it('ويحفظ ما يُطلب منه ويتراجع عمّا يُطلب', () => {
        const onSave = vi.fn();
        const onReset = vi.fn();
        render(<SaveBar dirty processing={false} onSave={onSave} onReset={onReset} />);

        fireEvent.click(screen.getByRole('button', { name: /حفظ التغييرات/ }));
        expect(onSave).toHaveBeenCalledOnce();

        fireEvent.click(screen.getByRole('button', { name: /تراجع/ }));
        expect(onReset).toHaveBeenCalledOnce();
    });

    /** وزرّاه يُغلقان وهو يحفظ — فلا يُضغط الحفظُ مرّتين ولا يُتراجَع أثناءه */
    it('ويُغلق زرَّيه وهو يحفظ', () => {
        render(<SaveBar dirty processing onSave={vi.fn()} onReset={vi.fn()} />);

        expect(screen.getByRole('button', { name: /تراجع/ })).toBeDisabled();
    });
});

describe('قسمةُ المفاتيح على الشاشات', () => {
    beforeEach(reset);

    const full = themeForm().data as ThemeSettingsData;

    /** ولا شاشةَ ترسل مفتاحَ جارتها — فالحفظُ لا يكتب فوق ضبطٍ لم يُفتح */
    it('ترسل كلُّ شاشةٍ مفاتيحَها وحدَها', () => {
        for (const [screen, keys] of Object.entries(SCREEN_KEYS)) {
            const sent = only(full, keys);

            expect(Object.keys(sent).sort(), screen).toEqual([...keys].sort());
        }
    });

    /** والقيمُ تُنسَخ كما هي — لا تُقلب منطقيّةٌ إلى نصٍّ في الطريق */
    it('وتنسخ قيمَها كما هي', () => {
        const sent = only(full, SCREEN_KEYS.seo);

        expect(sent.store_seo_title).toBe(full.store_seo_title);
        expect(sent.store_seo_index).toBe(full.store_seo_index);
    });

    /**
     * والقسمةُ تامّةٌ: كلُّ مفتاحٍ في نموذجٍ له شاشةٌ ترسله، ولا مفتاحَ في
     * شاشتين.
     *
     * فمفتاحٌ يسقط من القائمتين يُرسم ويُقلَّب ولا يُحفظ أبدًا — ومفتاحٌ في
     * قائمتين تكتب إحدى الشاشتين فوق الأخرى.
     */
    it('ولا مفتاحَ بلا شاشة ولا مفتاحَ في شاشتين', () => {
        const spread = Object.values(SCREEN_KEYS).flatMap((keys) => [...keys]);

        expect([...new Set(spread)].sort()).toEqual(Object.keys(full).sort());
        expect(spread.length).toBe(new Set(spread).size);
    });
});

describe('قسمُ «العنوان والنشر»', () => {
    beforeEach(reset);

    const draw = (over: Record<string, unknown> = {}, productCount = 12) =>
        render(
            <Address
                form={themeForm(over)}
                site={THEME_SITE}
                path="sub"
                pricing={{ currency: 'ر.ع', subdomain: { free: true, monthly: 0, yearly: 0 }, custom: { monthly: 0, yearly: 0 } } as never}
                suggestion="ribbon"
                productCount={productCount}
            />,
        );

    /** والعنوانُ يُبنى أمام عينه بقاعدة الخادم نفسِها — لا يُكتب ثمّ يُفاجأ */
    it('تُري العنوان كما سيقرؤه الزبون', () => {
        draw();

        expect(screen.getByText('https://ribbon.abaadapp.om')).toBeInTheDocument();
    });

    /**
     * ولا يُنشر متجرٌ بلا بضاعة بلا كلمة.
     *
     * صفحةٌ فارغةٌ تُفقد الزبونَ ثقتَه ولا يعود إليها بعد أن رآها خالية —
     * والتحذيرُ قبل الضغط أنفعُ من تقريرٍ بعده.
     */
    it('وتحذّر من نشر متجرٍ لا صنفَ معروضًا فيه', () => {
        draw({}, 0);

        expect(screen.getByTestId('empty-shelf')).toHaveTextContent(/ستُفتح الصفحة خالية/);
    });

    /** ومتجرٌ فيه بضاعةٌ لا يُحذَّر — تحذيرٌ لا محلَّ له يُعلَّم أنّه ضجيج */
    it('ولا تحذّر متجرًا فيه بضاعة', () => {
        draw();

        expect(screen.queryByTestId('empty-shelf')).toBeNull();
    });

    /** ومتجرٌ مطفأٌ لا يُحذَّر من فراغ رفّه: لا أحدَ يفتحه أصلًا */
    it('ولا تحذّر متجرًا لم يُنشر بعد', () => {
        draw({ store_on: false }, 0);

        expect(screen.queryByTestId('empty-shelf')).toBeNull();
    });
});

describe('قسمُ «الدفع والاستلام»', () => {
    beforeEach(reset);

    const draw = (over: Record<string, unknown> = {}, gatewayReady = false) =>
        render(<Checkout form={themeForm(over)} gatewayReady={gatewayReady} />);

    /** ومتجرٌ بلا طريقة دفعٍ يُقال عنه ذلك — لا يُترك يُكتشف من زبونٍ لم يستطع الطلب */
    it('تقول إنّ متجرًا بلا طريقة دفعٍ لا يقبل طلبًا', () => {
        draw({ store_pay_cod: false, store_pay_transfer: false });

        expect(screen.getByText(/لا طريقة دفعٍ مفتوحة/)).toBeInTheDocument();
    });

    /** والبطاقةُ طريقةٌ ثالثة — فمن ربط بوّابته لا يُقال له «لا طريقة دفع» */
    it('ولا تقولها لمن ربط بوّابة البطاقة', () => {
        draw({ store_pay_cod: false, store_pay_transfer: false }, true);

        expect(screen.queryByText(/لا طريقة دفعٍ مفتوحة/)).toBeNull();
    });

    /** وحقلُ الحساب البنكي لا يُرسم إلّا لمن فتح التحويل — حقلٌ لا يُقرأ ضجيج */
    it('ولا ترسم حقلَ الحساب إلّا لمن فتح التحويل', () => {
        draw();
        expect(screen.queryByLabelText(/بيانات الحساب البنكي/)).toBeNull();

        reset();
        draw({ store_pay_transfer: true });
        expect(screen.getByLabelText(/بيانات الحساب البنكي/)).toBeInTheDocument();
    });
});
