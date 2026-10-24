import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * شاشةُ تفعيل الجهاز — «صندوق جديد» لا يُفتح لكاشيرٍ بجوار طابعةٍ قائمة.
 *
 * كان الخيارَ الوحيدَ المفتوحَ له: كاشيرٌ انتقل من Chrome إلى تطبيق أبعاد
 * يضغطه فيولد صندوقٌ بلا طابعة، والطابعةُ على القديم. الآن يُغلق ويُقال له
 * من يفعّله ولماذا. والخادمُ يحرس الشيءَ نفسَه (`TillActivation::guardNew`
 * — `APrinterStaysWithItsTillWhenTheDeviceChangesTest`).
 */

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: string | Record<string, unknown>, value?: unknown) =>
                setData((d) => (typeof key === 'string' ? { ...d, [key]: value } : key)),
            transform: () => undefined,
            post: () => undefined,
        };
    };

    return { ...actual, usePage: () => ({ props: pageProps, url: '/', component: 'Test' }), Head: () => null, useForm };
});

const DeviceSetup = (await import('@/Pages/Pos/DeviceSetup')).default;

const till = { id: 1, name: 'كاشير Chrome', branchId: 7, branch: 'الرئيسي', active: true, printers: 1, lastSeen: null, version: 'v1' };

const open = (over: Record<string, unknown>) => {
    Object.assign(pageProps, {
        branches: [{ value: 7, label: 'الرئيسي' }],
        businessName: 'متجر الورد',
        devices: [till],
        previous: null,
        errors: {},
        ...over,
    });
    render(<DeviceSetup />);
};

const card = (title: string) => screen.getByText(title).closest('button') as HTMLButtonElement;

describe('a cashier beside a till that holds a printer', () => {
    afterEach(() => {
        for (const key of ['canReplace', 'newNeedsManager']) delete (pageProps as Record<string, unknown>)[key];
    });

    it('cannot pick «صندوق جديد», and is told a manager activates this device', () => {
        open({ canReplace: false, newNeedsManager: [7] });

        expect(card('صندوق جديد').disabled).toBe(true);
        expect(card('يحلّ محلّ صندوق قائم').disabled).toBe(true);
        expect(screen.getByText(/اطلب من المدير أن يدخل من هذا الجهاز/)).toBeTruthy();
    });

    it('still opens a new till where no printer can be lost', () => {
        open({ canReplace: false, newNeedsManager: [], devices: [{ ...till, printers: 0 }] });

        expect(card('صندوق جديد').disabled).toBe(false);
        expect(screen.queryByText(/اطلب من المدير أن يدخل من هذا الجهاز/)).toBeNull();
    });

    it('leaves both doors to a manager, who sees the printer and chooses', () => {
        open({ canReplace: true, newNeedsManager: [] });

        expect(card('صندوق جديد').disabled).toBe(false);
        expect(card('يحلّ محلّ صندوق قائم').disabled).toBe(false);
    });
});
