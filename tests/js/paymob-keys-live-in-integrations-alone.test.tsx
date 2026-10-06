import { act, fireEvent, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';
import { THEME_SITE } from './theme-form';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ThemeStore } = await import('@/Pages/Admin/Website/ThemeStore');
const { default: Paymob } = await import('@/Pages/Admin/Integrations/Paymob');

/**
 * مفاتيحُ Paymob في «التطبيقات التكاملية» وحدها — وشاشاتُ الموقع تعرض الحال.
 *
 * ═══ ما كان ═══
 *
 * نموذجان متطابقان لمفاتيح Paymob في شاشتين من شاشات الموقع، يُرسمان لمن في
 * قائمة المالك (`allowed`) وحده، ولا حقلَ لـApple Pay.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بطاقةُ الموقع حالٌ ورابط: Paymob والبطاقات و Apple Pay، و«إدارة Paymob»
 *     إلى بيت المفاتيح — لكلّ متجر، ولا حقلَ يُكتب فيه مفتاح.
 *   - و Apple Pay «مُضاف» أو «غير مُضاف» — لا «مفعّل».
 *   - وصفحةُ Paymob ترسل رقمَ Apple Pay مع البطاقة إلى بابها، والسرّان يبدآن
 *     فارغين، وتقول ما يلزم عن الوضع الحيّ والتجريبيّ وعنوان الإشعار.
 *
 * وما يفعله الخادمُ بها حارسُه `PaymobIsEachMerchantsOwnGatewayTest`.
 */

const summary = (over: Record<string, unknown> = {}) => ({
    state: 'off',
    card_ready: false,
    apple_pay: false,
    checkout: true,
    online: false,
    ...over,
});

const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
};

const drawStore = (gw: Record<string, unknown>) => {
    reset();
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['website'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        theme: 'ribbon',
        site: THEME_SITE,
        settings: {},
        fieldStates: {},
        fulfilments: ['delivery', 'pickup'],
        pages: { allowed: 'about,contact' },
        seo: { title: '', desc: '', index: true },
        storeOn: true,
        slug: 'ribbon',
        gateway: gw,
    });

    return render(<ThemeStore />);
};

const status = () => screen.getByTestId('paymob-status');

describe('بطاقةُ «الدفع الإلكتروني» في شاشة المتجر', () => {
    beforeEach(reset);

    it('تُرسم لكلّ متجر — بلا قائمة — وتُحيل إلى بيت المفاتيح', () => {
        drawStore(summary());

        expect(screen.getByText('الدفع الإلكتروني')).toBeInTheDocument();
        expect(status()).toHaveTextContent('غير مربوط');
        const link = screen.getByRole('link', { name: /ربط Paymob/ });
        expect(link).toHaveAttribute('href', '/admin.integrations.paymob');
    });

    it('ولا حقلَ فيها يُكتب فيه مفتاحٌ أو رقمُ تكامل', () => {
        drawStore(summary({ state: 'ready', card_ready: true, online: true, apple_pay: true }));

        const card = document.getElementById('gateway')!;
        expect(card.querySelectorAll('input, textarea, form')).toHaveLength(0);
        expect(screen.queryByLabelText('المفتاح العامّ')).toBeNull();
        expect(screen.queryByLabelText('المفتاح السرّي')).toBeNull();
        expect(screen.queryByLabelText('رقم تكامل البطاقة')).toBeNull();
    });

    it('وتقول الحالَ كما هو: مربوط، والبطاقات جاهزة، و Apple Pay مُضاف — و«إدارة Paymob»', () => {
        drawStore(summary({ state: 'ready', card_ready: true, online: true, apple_pay: true }));

        expect(status()).toHaveTextContent('مربوط');
        expect(status()).toHaveTextContent('جاهزة');
        expect(status()).toHaveTextContent('مُضاف');
        expect(status()).not.toHaveTextContent('مفعّل');
        expect(screen.getByRole('link', { name: /إدارة Paymob/ })).toHaveAttribute('href', '/admin.integrations.paymob');
    });

    it('وربطٌ لم يكتمل يُقال «لم يكتمل» ويُدعى إلى إكماله', () => {
        drawStore(summary({ state: 'partial' }));

        expect(status()).toHaveTextContent('لم يكتمل');
        expect(status()).toHaveTextContent('غير مُضاف');
        expect(screen.getByRole('link', { name: /إكمال الربط/ })).toBeInTheDocument();
    });

    it('ومربوطٌ بلا سلّة يُقال سببُه — ولا تُقال البطاقاتُ جاهزة', () => {
        drawStore(summary({ state: 'ready', card_ready: true, checkout: false, online: false }));

        expect(status()).toHaveTextContent('غير جاهزة');
        expect(status()).toHaveTextContent('لا سلّة في موقعك بعد');
    });
});

/*
 * وشاشةُ الإعدادات العامّة — صفحةٌ بألف سطرٍ لا تُركَّب لسؤالٍ واحد، فيُقرأ
 * مصدرُها كما تُقرأ صفحاتُ الإعدادات في هذا المستودع.
 */
describe('الإعداداتُ العامّة', () => {
    const source = readFileSync(path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'), 'utf8');

    it('تعرض الحالَ ولا تحمل نموذجَ مفاتيح', () => {
        expect(source).toContain('<PaymobStatus gateway={store.gateway} />');
        expect(source).not.toContain('admin.marketing.store.gateway');
        expect(source).not.toContain('gatewayForm');
        expect(source).not.toContain('store.gateway.allowed');
        expect(source).not.toContain('secret_key');
    });
});

describe('صفحةُ Paymob في التطبيقات التكاملية', () => {
    let sent: { url: string; data: Record<string, unknown> }[] = [];

    const gateway = (over: Record<string, unknown> = {}) => ({
        active: true,
        public_key: 'public-a',
        card_integration_id: '111',
        omannet_integration_id: '',
        apple_pay_integration_id: '',
        omannet: false,
        has_secret: true,
        has_hmac: true,
        state: 'ready',
        card_ready: true,
        apple_pay: false,
        checkout: true,
        online: true,
        webhook: 'https://app.test/webhooks/paymob',
        ...over,
    });

    const drawPage = (gw: Record<string, unknown>) => {
        reset();
        Object.assign(pageProps, {
            translations: {},
            auth: { abilities: ['integrations'], mayActions: [] },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            gateway: gw,
        });

        return render(<Paymob />);
    };

    beforeEach(() => {
        sent = [];
        vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>) => {
            sent.push({ url, data });
        }) as never);
    });

    afterEach(() => vi.restoreAllMocks());

    it('فيها حقلُ Apple Pay بتسميته وتنبيهه — ولا وعدَ بتفعيل', () => {
        drawPage(gateway());

        expect(screen.getByLabelText('رقم تكامل Apple Pay')).toBeInTheDocument();
        expect(screen.getByText(/لا يملك Test Integration ID/)).toBeInTheDocument();
        expect(screen.getByText('يجب أن يكون Apple Pay مفعّلًا أولًا في حساب Paymob الخاص بك.')).toBeInTheDocument();
        expect(screen.getByTestId('paymob-test-warning')).toHaveTextContent('فاترك هذا الحقل فارغًا');
        // وOmanNet بجانبه غيرُ مُضافٍ كذلك — حالان لا حالٌ واحد
        expect(screen.getAllByText('غير مُضاف')).toHaveLength(2);
    });

    it('وتقول إنّ المال يصل حسابَ التاجر، وتعرض عنوانَ الإشعار للتكاملين', () => {
        drawPage(gateway());

        expect(screen.getByText('مفاتيحك أنت من حساب Paymob الخاص بك، والمال يصل إلى حسابك ولا يمر عبر أبعاد.')).toBeInTheDocument();
        expect(screen.getByDisplayValue('https://app.test/webhooks/paymob')).toBeInTheDocument();
        expect(screen.getByText(/على كل تكامل تستعمله: البطاقة، وOmanNet، وApple Pay/)).toBeInTheDocument();
    });

    it('والسرّان يبدآن فارغين — لا يصل نصُّهما الشاشة', () => {
        drawPage(gateway());

        expect((screen.getByLabelText('المفتاح السرّي') as HTMLInputElement).value).toBe('');
        expect((screen.getByLabelText('سرّ التوقيع') as HTMLInputElement).value).toBe('');
    });

    it('والحفظُ يرسل البطاقة و Apple Pay إلى بابها', async () => {
        drawPage(gateway());

        fireEvent.change(screen.getByLabelText('رقم تكامل Apple Pay'), { target: { value: '222' } });
        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin.integrations.paymob.save');
        expect(sent[0].data).toMatchObject({
            card_integration_id: '111',
            apple_pay_integration_id: '222',
            public_key: 'public-a',
            secret_key: '',
            hmac_secret: '',
        });
    });

    it('وحفظٌ لم يمسّ حقلَ Apple Pay يُبقي رقمَه — لا يُرسَل فارغًا فيُمحى', async () => {
        drawPage(gateway({ apple_pay_integration_id: '222', apple_pay: true }));

        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });

        expect(sent[0].data.apple_pay_integration_id).toBe('222');
        expect(sent[0].data.card_integration_id).toBe('111');
    });

    it('وموقعٌ بلا سلّة يُقال له ذلك — الربطُ يُحفظ ولا دفعَ على موقعه', () => {
        drawPage(gateway({ checkout: false, online: false }));

        expect(screen.getByTestId('paymob-usage')).toHaveTextContent('لا سلّة في موقعك بعد');
    });

    it('و Apple Pay المحفوظ يُقال «مُضاف» لا «مفعّل»', () => {
        drawPage(gateway({ apple_pay_integration_id: '222', apple_pay: true }));

        expect(screen.getByText('مُضاف')).toBeInTheDocument();
        expect(screen.queryByText('مفعّل')).toBeNull();
    });

    /*
        OmanNet — خانةٌ ثالثةٌ مستقلّة، لا خانةُ Apple Pay ولا خانةُ البطاقة.
        والقرارُ في الخادم: `PaymobIsEachMerchantsOwnGatewayTest` (القسم ١١).
    */
    it('فيها حقلُ OmanNet بتسميته وتنبيه الوضع — منفصلًا عن البطاقة و Apple Pay', () => {
        drawPage(gateway());

        const omannet = screen.getByLabelText('رقم تكامل OmanNet');
        expect(omannet).toBeInTheDocument();
        expect(omannet).not.toBe(screen.getByLabelText('رقم تكامل Apple Pay'));
        expect(omannet).not.toBe(screen.getByLabelText('رقم تكامل البطاقة'));
        expect(screen.getByText(/انسخ Integration ID الخاص بـ OmanNet/)).toHaveTextContent('نفس الوضع Live أو Test');
    });

    it('والحفظُ يرسل OmanNet في خانته هو — ولا يمسّ Apple Pay', async () => {
        drawPage(gateway({ apple_pay_integration_id: '222', apple_pay: true }));

        fireEvent.change(screen.getByLabelText('رقم تكامل OmanNet'), { target: { value: '333' } });
        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });

        expect(sent[0].data).toMatchObject({
            card_integration_id: '111',
            omannet_integration_id: '333',
            apple_pay_integration_id: '222',
        });
    });

    it('و OmanNet المحفوظ يبقى في خانته ويُقال «مُضاف»', async () => {
        drawPage(gateway({ omannet_integration_id: '333', omannet: true }));

        expect((screen.getByLabelText('رقم تكامل OmanNet') as HTMLInputElement).value).toBe('333');
        expect(screen.getByText('مُضاف')).toBeInTheDocument();

        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });
        expect(sent[0].data.omannet_integration_id).toBe('333');
    });

    it('وبالإنجليزيّة', () => {
        reset();
        Object.assign(pageProps, {
            translations: {
                'رقم تكامل OmanNet': 'OmanNet Integration ID',
                'اختياري — انسخ Integration ID الخاص بـ OmanNet من حسابك في Paymob ← Payment Integrations. استخدم رقمًا من نفس الوضع Live أو Test المستخدم في مفاتيحك.':
                    'Optional — copy the OmanNet Integration ID from your Paymob Payment Integrations. Use an ID from the same Live/Test mode as your Paymob credentials.',
            },
            locale: 'en',
            dir: 'ltr',
            auth: { abilities: ['integrations'], mayActions: [] },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            gateway: gateway(),
        });
        render(<Paymob />);

        expect(screen.getByLabelText('OmanNet Integration ID')).toBeInTheDocument();
        expect(screen.getByText(/same Live\/Test mode as your Paymob credentials/)).toBeInTheDocument();
    });
});
