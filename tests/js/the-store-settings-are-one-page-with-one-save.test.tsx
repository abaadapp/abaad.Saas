import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SaveBar from '@/Pages/Admin/Website/theme/SaveBar';
import SettingsRail from '@/Pages/Admin/Website/theme/SettingsRail';
import Address from '@/Pages/Admin/Website/theme/sections/Address';
import Checkout from '@/Pages/Admin/Website/theme/sections/Checkout';
import { pageProps } from './setup';
import { THEME_SITE, themeForm } from './theme-form';

/**
 * ضبطُ متجر الواجهة الخاصّة — صفحةٌ واحدة، وعمودٌ يقفز، وزرُّ حفظٍ واحد.
 *
 * ═══ ما كان ═══
 *
 * ستُّ شاشاتٍ بشريط تبويبات، وفوقها شاشةُ «عام» التي هي قائمةٌ ثانية: سبعُ
 * بطاقاتٍ تقود إلى التبويبات نفسِها. فمن أراد تغيير رسم التوصيل مرّ
 * بقائمتين وحمّل الصفحةَ مرّتين قبل أن يبلغ حقلًا واحدًا.
 *
 * وكان التوزيعُ غيرَ عادل: ثلاثُ شاشاتٍ فيها ثلاثةُ مقابض، وواحدةٌ فيها
 * اثنان وثلاثون ومعها زرّا حفظٍ متجاوران لا يحفظ أحدُهما ما يحفظه الآخر:
 * من عدّل رسمَ التوصيل ثمّ ضغط الزرَّ الأسفل أضاع ما كتب ولا شيء يقول له.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ الشريطَ لا يظهر إلّا حين يكون له معنى، وأنّ العمودَ يقول ما في كلّ
 * قسمٍ قبل القفز إليه، وأنّ الأقسامَ تقول ما يقع.
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

describe('عمودُ الأقسام', () => {
    beforeEach(reset);

    const items = [
        { id: 'address', label: 'العنوان والنشر', note: 'منشور' },
        { id: 'checkout', label: 'الدفع والاستلام', note: 'نقد · تحويل' },
        { id: 'seo', label: 'الظهور في البحث', note: null },
    ];

    it('يرسم مدخلًا لكلّ قسم', () => {
        render(<SettingsRail items={items} />);

        for (const item of items) {
            expect(screen.getByTestId(`rail-${item.id}`)).toHaveTextContent(item.label);
        }
    });

    /**
     * وما في القسم الآن يُقرأ من العمود قبل القفز إليه.
     *
     * فمن يبحث عن حالِ متجره يقرأها بلا أن يفتح قسمًا — وهو ما كانت تفعله
     * شاشةُ «عام» في سبع بطاقاتٍ تحمّل صفحةً كاملة.
     */
    it('ويقول ما في كلّ قسمٍ الآن', () => {
        render(<SettingsRail items={items} />);

        expect(screen.getByTestId('rail-address')).toHaveTextContent('منشور');
        expect(screen.getByTestId('rail-checkout')).toHaveTextContent('نقد · تحويل');
    });

    /** والأوّلُ مضاءٌ حين تُفتح الصفحة — فلا يقف القارئ بلا موضع */
    it('ويُضيء القسمَ الذي تقف عليه العين', () => {
        render(<SettingsRail items={items} />);

        expect(screen.getByTestId('rail-address')).toHaveAttribute('aria-current', 'true');
        expect(screen.getByTestId('rail-seo')).not.toHaveAttribute('aria-current');
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
