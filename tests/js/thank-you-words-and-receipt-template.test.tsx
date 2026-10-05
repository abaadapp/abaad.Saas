import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Checkout from '@/Pages/Admin/Website/theme/sections/Checkout';
import { SCREEN_KEYS } from '@/Pages/Admin/Website/theme/sections/form';
import { pageProps } from './setup';
import { themeForm } from './theme-form';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: TemplateEditor } = await import('@/Pages/Admin/Settings/TemplateEditor');

/**
 * صفحةُ الشكر في «إعدادات الموقع»، والإيصالُ الحراريّ في «قوالب الأوراق».
 *
 * ═══ ما يُحرس ═══
 *
 *   · «صفحة الشكر» في قسم «الدفع والاستلام»: أربعةُ نصوصٍ لكلّ لغة، والعلامةُ
 *     المائيّةُ نصُّ النظام، وتُحفظ مع شاشة «المتجر» — لا شاشةَ ثانية.
 *   · ومحرّرُ «فاتورة البيع» يحمل الترويسةَ والتذييلَ بالإنجليزيّة لهذا القالب
 *     وحده، ويُعاين بالإنجليزيّة حين يُطلب.
 *
 * والقرارُ في الخادم: `AThankYouPageSpeaksTheShopsWordsTest`
 * و`AThermalReceiptSpeaksItsCustomersLanguageTest`.
 */
const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['website', 'settings'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    });
};

describe('صفحةُ الشكر في إعدادات الموقع', () => {
    beforeEach(reset);

    it('أربعةُ نصوصٍ لكلّ لغة — والعلامةُ المائيّةُ نصُّ النظام', () => {
        render(<Checkout form={themeForm()} gatewayReady />);

        expect(screen.getByText('صفحة الشكر')).toBeInTheDocument();
        expect(screen.getByLabelText('عنوان الصفحة')).toHaveAttribute('placeholder', 'شكراً لك، تم استلام طلبك');
        expect(screen.getByLabelText('Page title — English')).toHaveAttribute('placeholder', 'Thank you, your order is received');
        expect(screen.getByLabelText('Page title — English')).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('الرسالة تحت العنوان')).toBeInTheDocument();
        expect(screen.getByLabelText('Message — English')).toBeInTheDocument();
        expect(screen.getByLabelText('زر الإيصال')).toHaveAttribute('placeholder', 'عرض الفاتورة');
        expect(screen.getByLabelText('Receipt button — English')).toBeInTheDocument();
        expect(screen.getByLabelText('زر متابعة التسوق')).toBeInTheDocument();
        expect(screen.getByLabelText('Continue shopping button — English')).toBeInTheDocument();
    });

    it('وما يُكتب يُحفظ في مفتاح لغته — مع شاشة «المتجر»', () => {
        const form = themeForm();
        render(<Checkout form={form} gatewayReady />);

        fireEvent.change(screen.getByLabelText('عنوان الصفحة'), { target: { value: 'شكرًا من ريبون' } });
        fireEvent.change(screen.getByLabelText('Page title — English'), { target: { value: 'Thanks from Ribbon' } });

        expect(form.setData).toHaveBeenCalledWith('store_thanks_title', 'شكرًا من ريبون');
        expect(form.setData).toHaveBeenCalledWith('store_thanks_title_en', 'Thanks from Ribbon');

        for (const key of [
            'store_thanks_title', 'store_thanks_title_en', 'store_thanks_message', 'store_thanks_message_en',
            'store_thanks_receipt', 'store_thanks_receipt_en', 'store_thanks_continue', 'store_thanks_continue_en',
        ] as const) {
            expect(SCREEN_KEYS.store).toContain(key);
        }
    });

    it('والحدُّ حدُّ الخادم', () => {
        render(<Checkout form={themeForm()} gatewayReady />);

        expect(screen.getByLabelText('عنوان الصفحة')).toHaveAttribute('maxLength', '120');
        expect(screen.getByLabelText('Message — English')).toHaveAttribute('maxLength', '300');
        expect(screen.getByLabelText('زر الإيصال')).toHaveAttribute('maxLength', '40');
    });
});

const template = (over: Record<string, unknown> = {}) => ({
    key: 'sale',
    label: 'فاتورة البيع',
    desc: 'الإيصال الحراري وفاتورة A4 والفاتورة الضريبية — قالبٌ واحد يحكم الثلاث',
    section: 'المبيعات',
    hasPaper: true,
    hasStrip: true,
    hasEnglish: true,
    fields: [
        { key: 'show_logo', label: 'شعار المتجر', hint: null },
        { key: 'show_phone', label: 'هاتف المتجر', hint: null },
        { key: 'show_website', label: 'عنوان المتجر الإلكتروني', hint: null },
    ],
    fonts: ['صغير', 'عادي', 'كبير'],
    papers: ['A4', 'A5'],
    strips: ['80mm', '58mm'],
    values: {
        show_logo: false, show_phone: true, show_website: false,
        header: '', footer: 'شكرًا لزيارتكم', header_en: '', footer_en: '',
        font: 'عادي', paper: 'A4', strip: '80mm',
    },
    ...over,
});

const editor = (over: Record<string, unknown> = {}) => {
    reset();
    Object.assign(pageProps, {
        template: template(over),
        templates: [],
        brand: { primary: '#111', accent: '#111', logo: null, cover: null },
    });

    return render(<TemplateEditor {...(pageProps as unknown as Parameters<typeof TemplateEditor>[0])} />);
};

describe('الإيصالُ الحراريّ في «قوالب الأوراق»', () => {
    const fetchMock = vi.fn(async () => ({ json: async () => ({ html: '<p>paper</p>', size: '80mm' }) }));

    beforeEach(() => {
        fetchMock.mockClear();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('ترويسةٌ وتذييلٌ بالإنجليزيّة — ومقبضا الهاتف والموقع', () => {
        editor();

        expect(screen.getByLabelText('Line under the store name — English')).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('Footer — English')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: /هاتف المتجر/ })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: /عنوان المتجر الإلكتروني/ })).toHaveAttribute('aria-checked', 'false');
    });

    it('والمعاينةُ بالإنجليزيّة تُطلب باللغة', async () => {
        editor();

        fireEvent.click(screen.getByRole('button', { name: 'English' }));

        await waitFor(() => {
            const bodies = fetchMock.mock.calls.map((c) => JSON.parse(String((c as unknown[])[1] && ((c as unknown[])[1] as { body: string }).body)));
            expect(bodies.some((b) => b.lang === 'en')).toBe(true);
        });
    });

    it('وقالبٌ بلا إنجليزيّة لا تُعرض له حقولُها ولا لغتُها', () => {
        editor({ key: 'purchase', hasStrip: false, hasEnglish: false });

        expect(screen.queryByLabelText('Footer — English')).toBeNull();
        expect(screen.queryByRole('button', { name: 'English' })).toBeNull();
    });
});
