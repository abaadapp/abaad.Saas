import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PaymentDialog from '@/Pages/Pos/partials/PaymentDialog';
import { pageProps } from './setup';

const warning = vi.fn();
vi.mock('sonner', () => ({ toast: { warning: (...a: unknown[]) => warning(...a), error: vi.fn() } }));

/**
 * «طباعة تلقائية» مقبضٌ يعد — فليقع ما وعد به، أو فليُقل إنّه لم يقع.
 *
 * ═══ العطبُ الذي يعالجه ═══
 *
 * كانت النافذةُ تُفتح **بعد** `await onCheckout` — أي بعد أن ينتهي أثرُ
 * ضغطة الكاشير. والمتصفّحات لا تسمح بفتح نافذةٍ إلّا من إيماءةِ مستخدمٍ
 * حيّة، فكان مانعُ النوافذ يبتلعها صامتًا: الكاشيرُ ضبط المقبض، ويرى شاشةَ
 * النجاح، ولا يخرج من الطابعة شيء — ولا رسالةَ تقول لمَ. فيسلّم الزبونَ
 * بلا إيصالٍ وهو يظنّ أنّه خرج.
 *
 * ولم يكن للمقبض حارسٌ واحدٌ في المستودع: سطرٌ يتيمٌ لا يقيسه شيء.
 *
 * ═══ وما يُقاس هنا ═══
 *
 * **متى** تُفتح النافذة لا **أن** تُفتح: الفرقُ بين العامل والمعطوب كلُّه
 * في الترتيب، وقراءةُ السطر في ملفٍّ لا تُظهره.
 */
describe('الطباعة التلقائية بعد البيع', () => {
    const PDF = /pos\.receipt\.pdf/;

    let opened: { location: { replace: ReturnType<typeof vi.fn> }; close: ReturnType<typeof vi.fn> };
    let openMock: ReturnType<typeof vi.fn>;

    /** صندوقٌ بطابعةٍ — والمقبضُ عليها */
    const till = (autoPrint: boolean) => {
        Object.assign(pageProps, {
            context: {
                currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
                peripherals: [{ type: 'طابعة', autoPrint, paperWidth: 80 }],
            },
        });
    };

    const dialog = (onCheckout: (m: string) => Promise<unknown>) =>
        render(
            <PaymentDialog
                open
                onOpenChange={() => {}}
                total={12.5}
                displayTotal={12.5}
                customer="عميل نقدي"
                money={(v) => String(v)}
                fmt={(v) => String(v)}
                onCheckout={onCheckout as never}
                onNewOrder={() => {}}
                methods={['نقدي']}
            />,
        );

    /** اختيارُ الوسيلة ثمّ الضغط — وهي الإيماءة التي تُقاس */
    const pay = async () => {
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /نقدي/ }));
        await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    };

    beforeEach(() => {
        warning.mockClear();
        opened = { location: { replace: vi.fn() }, close: vi.fn() };
        openMock = vi.fn().mockReturnValue(opened);
        vi.stubGlobal('open', openMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        Object.assign(pageProps, {
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        });
    });

    it('تفتح النافذة في ضغطة الكاشير نفسِها — لا بعد أن يردّ الخادم', async () => {
        let settle: (v: unknown) => void = () => {};
        const checkout = vi.fn(
            () => new Promise((resolve) => { settle = resolve; }),
        );

        till(true);
        dialog(checkout as never);
        await pay();

        /*
         * وهذه هي الدعوى كلُّها: النافذةُ مفتوحةٌ والخادمُ لم يردّ بعد.
         * فالإيماءةُ ما زالت حيّة، ولا يبتلعها المانع.
         */
        await waitFor(() => expect(checkout).toHaveBeenCalled());
        expect(openMock).toHaveBeenCalledTimes(1);
        expect(openMock.mock.calls[0][0]).toBe('');
        expect(opened.location.replace).not.toHaveBeenCalled();

        settle({ synced: true, invoice: 'INV-9', points: 0, rejected: false });

        await waitFor(() => expect(opened.location.replace).toHaveBeenCalledTimes(1));
        expect(String(opened.location.replace.mock.calls[0][0])).toMatch(PDF);

        // ولا تُغلق بعد أن وُجّهت — إغلاقٌ هنا يُطفئ الإيصال وهو يُحمَّل
        expect(opened.close).not.toHaveBeenCalled();
    });

    it('ولا تُفتح أصلًا حين لا طباعةَ تلقائية', async () => {
        till(false);
        dialog(vi.fn().mockResolvedValue({ synced: true, invoice: 'INV-9', points: 0, rejected: false }) as never);
        await pay();

        await screen.findByText(/تم الدفع بنجاح/);

        expect(openMock).not.toHaveBeenCalled();
    });

    it('وتُغلق الفارغةُ حين تُحفظ البيعة بلا اتصال — فلا يبقى لسانٌ أبيض', async () => {
        till(true);
        dialog(vi.fn().mockResolvedValue({ synced: false, invoice: null, points: 0, rejected: false }) as never);
        await pay();

        await waitFor(() => expect(opened.close).toHaveBeenCalledTimes(1));
        expect(opened.location.replace).not.toHaveBeenCalled();
    });

    /*
     * ومُنعت رغم الإيماءة — فيُقال ولا يُسكت عنه.
     *
     * صمتُ المقبض يجعل الكاشير يسلّم الزبونَ بلا إيصالٍ وهو يظنّ أنّه خرج.
     */
    it('وإن منعها المتصفّح رغم ذلك قيلت ولم تُبتلع صامتة', async () => {
        openMock.mockReturnValue(null);
        till(true);
        dialog(vi.fn().mockResolvedValue({ synced: true, invoice: 'INV-9', points: 0, rejected: false }) as never);
        await pay();

        await waitFor(() => expect(warning).toHaveBeenCalledTimes(1));
        // ويدلّه على الزرّ الذي أمامه — لا يترك بلا مخرج
        expect(String(warning.mock.calls[0][0])).toMatch(/اطبع/);
    });

    it('ولا رسالةَ منعٍ حين لا طباعةَ تلقائية مطلوبة', async () => {
        openMock.mockReturnValue(null);
        till(false);
        dialog(vi.fn().mockResolvedValue({ synced: true, invoice: 'INV-9', points: 0, rejected: false }) as never);
        await pay();

        await screen.findByText(/تم الدفع بنجاح/);

        expect(warning).not.toHaveBeenCalled();
    });
});
