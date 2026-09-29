import { router } from '@inertiajs/react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    CONFIRMED,
    PENDING,
    prepareWebsiteConfirmPrint,
    wantsWebsiteConfirmPrint,
    websiteConfirmPrintCallbacks,
} from '@/lib/website-confirm-print';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';
import { pageProps } from './setup';

const warning = vi.fn();
vi.mock('sonner', () => ({ toast: { warning: (...a: unknown[]) => warning(...a), error: vi.fn(), success: vi.fn() } }));

/* الصفحةُ تُختبر لا القشرةُ حولها — ولا نداءاتُ اللوحة الدوريّة */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/hooks/useBoardLive', () => ({ default: () => ({ refresh: vi.fn(), refreshing: false, failed: false }) }));
vi.mock('@/hooks/useNewArrivals', () => ({ default: () => ({ fresh: [], acknowledge: vi.fn(), armSound: vi.fn() }) }));

/**
 * «طباعة تلقائية عند تأكيد طلب الموقع» — في المتصفّح.
 *
 * ═══ وما يُقاس هنا ═══
 *
 * **متى** تُفتح النافذة: في الضغطة نفسِها، قبل أن يُجيب الخادم — وإلّا
 * ابتلعها مانعُ النوافذ صامتًا (انظر `the-till-prints-what-it-promised`).
 * ثمّ ما يُفعل بها بعد الجواب: تُوجَّه إلى الإيصال الحراريّ إن قال الخادمُ
 * «أُكّد الآن»، وتُغلق إن لم يقلها، ويُقال للموظّف إن منعها المتصفّح.
 *
 * وقاعدةُ «أُكّد الآن» نفسُها في الخادم — حارسُها
 * `AWebsiteOrderPrintsWhenItIsConfirmedAtTheTillTest`.
 */

type Sheet = { location: { replace: ReturnType<typeof vi.fn> }; close: ReturnType<typeof vi.fn> };

let sheet: Sheet;
let openMock: ReturnType<typeof vi.fn>;

/** ما يردّه الخادمُ بعد النقل — `websiteConfirmed` كما يُومضها */
const serverSays = (websiteConfirmed: string | null, ok = true) =>
    vi.mocked(router.post).mockImplementation(((_url: string, _data: unknown, options: Record<string, (...a: unknown[]) => void>) => {
        if (ok) options?.onSuccess?.({ props: { flash: { websiteConfirmed } } });
        else options?.onError?.({ status: 'مرفوض' });
        options?.onFinish?.();
    }) as never);

const printer = (over: Record<string, unknown> = {}) => ({
    type: 'طابعة', name: 'طابعة الصندوق', paperWidth: 80, autoPrint: false, autoPrintWebsite: true, ...over,
});

const register = (peripherals: unknown[], abilities = ['orders', 'pos', 'preparation']) =>
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals },
        auth: { abilities, mayActions: [] },
    });

beforeEach(() => {
    warning.mockClear();
    vi.mocked(router.post).mockReset();
    sheet = { location: { replace: vi.fn() }, close: vi.fn() };
    openMock = vi.fn().mockReturnValue(sheet);
    vi.stubGlobal('open', openMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
        orders: undefined,
        order: undefined,
    });
});

/* ═══════════════════════════ القاعدة ═══════════════════════════ */

describe('متى تُفتح النافذة', () => {
    const base = {
        peripherals: [printer()],
        receipt: 'admin.orders.receipt',
        channel: 'website',
        from: PENDING,
        to: CONFIRMED,
    };

    it('طلبُ موقعٍ «جديد ← مؤكّد» على صندوقٍ طابعتُه مضبوطة — نعم', () => {
        expect(wantsWebsiteConfirmPrint(base)).toBe(true);
    });

    it('والخيارُ مطفأ — لا', () => {
        expect(wantsWebsiteConfirmPrint({ ...base, peripherals: [printer({ autoPrintWebsite: false })] })).toBe(false);
    });

    it('و«بعد البيع» وحده لا يطبع طلبَ الموقع — الخياران مستقلّان', () => {
        expect(
            wantsWebsiteConfirmPrint({ ...base, peripherals: [printer({ autoPrint: true, autoPrintWebsite: false })] }),
        ).toBe(false);
    });

    it('ولا صندوقَ على هذا المتصفّح (هاتفٌ مثلًا) — لا', () => {
        expect(wantsWebsiteConfirmPrint({ ...base, peripherals: [] })).toBe(false);
        expect(wantsWebsiteConfirmPrint({ ...base, peripherals: undefined })).toBe(false);
    });

    it('والخيارُ على ماسحٍ لا طابعة — لا', () => {
        expect(wantsWebsiteConfirmPrint({ ...base, peripherals: [{ type: 'ماسح باركود', autoPrintWebsite: true }] })).toBe(
            false,
        );
    });

    it('وطلبُ الصندوق — لا', () => {
        expect(wantsWebsiteConfirmPrint({ ...base, channel: 'pos' })).toBe(false);
        expect(wantsWebsiteConfirmPrint({ ...base, channel: null })).toBe(false);
    });

    it('وأيُّ نقلٍ غيرُ «جديد ← مؤكّد» — لا', () => {
        expect(wantsWebsiteConfirmPrint({ ...base, to: 'قيد التجهيز' })).toBe(false);
        expect(wantsWebsiteConfirmPrint({ ...base, from: CONFIRMED, to: 'قيد التجهيز' })).toBe(false);
        expect(wantsWebsiteConfirmPrint({ ...base, from: 'قيد التجهيز', to: CONFIRMED })).toBe(false);
        expect(wantsWebsiteConfirmPrint({ ...base, from: 'قيد التجهيز', to: 'جاهز' })).toBe(false);
    });

    it('و«مؤكّد» بالشدّة كما في الخادم — «مؤكد» بلا شدّة ليست حالةً عنده', () => {
        expect(CONFIRMED).toBe('مؤكّد');
        expect(wantsWebsiteConfirmPrint({ ...base, to: 'مؤكد' })).toBe(false);
    });

    it('والبابُ تختاره الشاشة — لا يُسأل عن «الطلبات» ولا «نقطة البيع»', () => {
        // لا حقلَ للصلاحيّات في المُدخل أصلًا: البابُ يُمرَّر كما هو
        expect(prepareWebsiteConfirmPrint({ ...base, receipt: 'admin.preparation.receipt' })?.route).toBe(
            'admin.preparation.receipt',
        );
        expect(prepareWebsiteConfirmPrint(base)?.route).toBe('admin.orders.receipt');
    });
});

/* ═══════════════════════ ما يُفعل بالنافذة ═══════════════════════ */

describe('بعد جواب الخادم', () => {
    const input = { peripherals: [printer()], receipt: 'admin.orders.receipt', channel: 'website', from: PENDING, to: CONFIRMED };
    const t = (k: string) => k;

    it('أُكّد الآن ← تُوجَّه النافذةُ إلى الإيصال الحراريّ', () => {
        const attempt = prepareWebsiteConfirmPrint(input);
        const cb = websiteConfirmPrintCallbacks(attempt, 'INV-9', t);

        cb.onSuccess({ props: { flash: { websiteConfirmed: 'INV-9' } } });
        cb.onFinish();

        expect(sheet.location.replace).toHaveBeenCalledWith('/admin.orders.receipt/INV-9');
        expect(sheet.close).not.toHaveBeenCalled();
    });

    it('رُفض النقل ← تُغلق النافذةُ الفارغة', () => {
        const cb = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'INV-9', t);

        cb.onFinish();

        expect(sheet.close).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).not.toHaveBeenCalled();
    });

    it('نجح النقلُ ولم يقل الخادمُ «أُكّد الآن» (زميلٌ سبقه) ← تُغلق', () => {
        const cb = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'INV-9', t);

        cb.onSuccess({ props: { flash: { websiteConfirmed: null } } });
        cb.onFinish();

        expect(sheet.close).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).not.toHaveBeenCalled();
    });

    it('ومضةٌ لطلبٍ آخر لا تُطبع على هذا', () => {
        const cb = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'INV-9', t);

        cb.onSuccess({ props: { flash: { websiteConfirmed: 'INV-8' } } });

        expect(sheet.location.replace).not.toHaveBeenCalled();
        expect(sheet.close).toHaveBeenCalledTimes(1);
    });

    it('منعها المتصفّح ← يُقال، وبابُ الطباعة اليدويّة باقٍ', () => {
        openMock.mockReturnValue(null);
        const cb = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'INV-9', t);

        cb.onSuccess({ props: { flash: { websiteConfirmed: 'INV-9' } } });

        expect(warning).toHaveBeenCalledTimes(1);
        const [msg, options] = warning.mock.calls[0] as [string, { action: { label: string; onClick: () => void } }];
        expect(msg).toMatch(/منع المتصفح/);
        expect(options.action.label).toBe('طباعة الفاتورة');

        // والزرُّ يفتح الإيصالَ نفسَه — ضغطةٌ جديدة، فلا يمنعها شيء
        options.action.onClick();
        expect(openMock).toHaveBeenLastCalledWith('/admin.orders.receipt/INV-9', '_blank', 'noopener');
    });

    it('ولا تُطبع مرّتين من الجواب نفسه', () => {
        const cb = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'INV-9', t);

        cb.onSuccess({ props: { flash: { websiteConfirmed: 'INV-9' } } });
        cb.onSuccess({ props: { flash: { websiteConfirmed: 'INV-9' } } });
        cb.onFinish();

        expect(sheet.location.replace).toHaveBeenCalledTimes(1);
        expect(sheet.close).not.toHaveBeenCalled();
    });

    it('وما لا تُفتح له نافذةٌ لا يفعل بعد الجواب شيئًا', () => {
        const cb = websiteConfirmPrintCallbacks(
            prepareWebsiteConfirmPrint({ ...input, channel: 'pos' }),
            'INV-9',
            t,
        );

        cb.onSuccess({ props: { flash: { websiteConfirmed: 'INV-9' } } });
        cb.onFinish();

        expect(openMock).not.toHaveBeenCalled();
        expect(warning).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════ لوحة التجهيز ═══════════════════════ */

const prep = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'WEB-1',
    status: PENDING,
    channel: 'website',
    customer: 'سارة',
    fulfillment: 'delivery',
    scheduled_for: null,
    scheduled: null,
    overdue: false,
    recipient: null,
    recipient_phone: null,
    address: null,
    occasion: null,
    card_message: null,
    sender: null,
    hide_sender: false,
    delivery_notes: null,
    internal_notes: null,
    order_notes: null,
    branch: null,
    items: [],
    next: [CONFIRMED, 'قيد التجهيز', 'ملغي'],
    checks: {},
    ...over,
} as PrepOrder);

const board = async (orders: PrepOrder[]) => {
    Object.assign(pageProps, {
        orders,
        filters: { when: null, type: null },
        counts: { all: orders.length, overdue: 0, today: orders.length, tomorrow: 0 },
        typeCounts: { all: orders.length, delivery: orders.length, pickup: 0 },
        columns: { new: [PENDING], confirmed: [CONFIRMED], preparing: ['قيد التجهيز'] },
        columnCounts: { new: orders.length },
        truncated: null,
        fetchedAt: '2026-09-29T10:00:00+04:00',
    });
    const { default: Board } = await import('@/Pages/Admin/Preparation/Index');

    return render(<Board />);
};

describe('من لوحة التجهيز', () => {
    it('«مؤكّد» على طلب موقعٍ جديد يفتح النافذةَ في الضغطة ويوجّهها بعد الجواب', async () => {
        register([printer()]);
        let openedBeforeServer = false;
        vi.mocked(router.post).mockImplementation(((_u: string, _d: unknown, o: Record<string, (...a: unknown[]) => void>) => {
            // الخادمُ لم يُجب بعد — والنافذةُ مفتوحةٌ من الضغطة نفسِها
            openedBeforeServer = openMock.mock.calls.length === 1;
            o?.onSuccess?.({ props: { flash: { websiteConfirmed: 'WEB-1' } } });
            o?.onFinish?.();
        }) as never);

        await board([prep()]);
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(openedBeforeServer).toBe(true);
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.preparation.move/WEB-1');
        expect(sheet.location.replace).toHaveBeenCalledWith('/admin.preparation.receipt/WEB-1');
    });

    /*
     * والبابُ بابُ اللوحة لكلّ من يؤكّد منها — لا يُختار من «الطلبات» ولا
     * «نقطة البيع». فمن خُصّص له التجهيزُ وحده يطبع، ومن يملك الثلاثة لا
     * يُساق إلى بابٍ آخر. وحارسُ الباب في الخادم:
     * `AWebsiteOrderPrintsWhenItIsConfirmedAtTheTillTest`.
     */
    it.each([
        ['التجهيزُ وحده', ['preparation']],
        ['ومعه «نقطة البيع»', ['pos', 'preparation']],
        ['ومعه «الطلبات» و«نقطة البيع»', ['orders', 'pos', 'preparation']],
    ])('%s ← الورقةُ من باب اللوحة', async (_label, abilities) => {
        register([printer()], abilities);
        serverSays('WEB-1');

        await board([prep()]);
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(openMock).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).toHaveBeenCalledWith('/admin.preparation.receipt/WEB-1');
    });

    it('ومنعُ المتصفّح على اللوحة يُبقي زرَّ الطباعة على باب اللوحة', async () => {
        register([printer()], ['preparation']);
        openMock.mockReturnValue(null);
        serverSays('WEB-1');

        await board([prep()]);
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        const options = warning.mock.calls[0][1] as { action: { onClick: () => void } };
        options.action.onClick();
        expect(openMock).toHaveBeenLastCalledWith('/admin.preparation.receipt/WEB-1', '_blank', 'noopener');
    });

    it('«قيد التجهيز» على الطلب نفسه لا يفتح شيئًا', async () => {
        register([printer()]);
        serverSays(null);

        await board([prep()]);
        await userEvent.click(screen.getByRole('button', { name: 'قيد التجهيز' }));

        expect(openMock).not.toHaveBeenCalled();
    });

    it('وطلبُ الصندوق الجديد إن أُكّد لا يفتح شيئًا', async () => {
        register([printer()]);
        serverSays(null);

        await board([prep({ channel: 'pos' })]);
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(openMock).not.toHaveBeenCalled();
    });

    it('والخيارُ مطفأ ← لا نافذة', async () => {
        register([printer({ autoPrintWebsite: false })]);
        serverSays('WEB-1');

        await board([prep()]);
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(openMock).not.toHaveBeenCalled();
    });

    it('ورفضُ الخادم يُغلق النافذةَ الفارغة', async () => {
        register([printer()]);
        serverSays(null, false);

        await board([prep()]);
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(openMock).toHaveBeenCalledTimes(1);
        expect(sheet.close).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).not.toHaveBeenCalled();
    });

    it('وإعادةُ الرسم بخصائصَ جديدة (التحديثُ الدوريّ) لا تطبع شيئًا', async () => {
        register([printer()]);
        const { rerender } = await board([prep()]);
        const { default: Board } = await import('@/Pages/Admin/Preparation/Index');

        // وصل التحديثُ الدوريّ بالطلب نفسه وقد صار مؤكّدًا — وومضةٌ عالقةٌ في الخصائص
        Object.assign(pageProps, {
            orders: [prep({ status: CONFIRMED, next: ['قيد التجهيز'] })],
            flash: { toast: null, status: null, websiteConfirmed: 'WEB-1' },
        });
        rerender(<Board />);

        await waitFor(() => expect(openMock).not.toHaveBeenCalled());
        Object.assign(pageProps, { flash: undefined });
    });
});

/* ═══════════════════════ شاشة الطلب ═══════════════════════ */

const detail = (over: Record<string, unknown> = {}) => ({
    id: 'WEB-7',
    customer: 'سارة',
    customer_language: null,
    customer_alert: null,
    employee: 'الموقع',
    branch: 'الرئيسي',
    date: '2026-09-29 10:00',
    payment: 'نقدي',
    payment_status: 'مدفوع',
    notes: null,
    recipient_name: null,
    recipient_phone: null,
    fulfillment_type: 'pickup',
    scheduled_for: null,
    occasion_type: null,
    card_message: null,
    card_align: null,
    card_file: null,
    card_file_name: null,
    sender_name: null,
    hide_sender: false,
    delivery_address: null,
    delivery_notes: null,
    internal_notes: null,
    status: PENDING,
    next_statuses: [CONFIRMED, 'قيد التجهيز', 'ملغي'],
    channel: 'website',
    occasions: [],
    fulfillments: [],
    subtotal: 25,
    discount: 0,
    tax: 0,
    delivery: 0,
    total: 25,
    items: [{ id: 1, name: 'باقة', qty: 1, price: 25, total: 25 }],
    payment_methods: ['نقدي'],
    edits: [],
    ...over,
});

const orderScreen = async (order: Record<string, unknown>) => {
    Object.assign(pageProps, {
        order,
        invoiceEdit: { can: false, reason: null },
        otherBranch: null,
        paper: null,
        taxInvoice: { registered: false, ready: false },
        googleReview: { show: false, reason: null, requestedAt: null },
        storeReview: { show: false, reason: null, written: false },
        statusNotice: { event: null, show: false, reason: null, preparedAt: null },
    });
    const { default: OrderShow } = await import('@/Pages/Admin/Orders/Show');

    return render(<OrderShow />);
};

describe('من شاشة الطلب', () => {
    it('«مؤكّد» يفتح النافذةَ في الضغطة ويوجّهها إلى الإيصال الحراريّ بعد الجواب', async () => {
        register([printer()]);
        let openedBeforeServer = false;
        vi.mocked(router.post).mockImplementation(((_u: string, _d: unknown, o: Record<string, (...a: unknown[]) => void>) => {
            openedBeforeServer = openMock.mock.calls.length === 1;
            o?.onSuccess?.({ props: { flash: { websiteConfirmed: 'WEB-7' } } });
            o?.onFinish?.();
        }) as never);

        await orderScreen(detail());
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(openedBeforeServer).toBe(true);
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.orders.status/WEB-7');
        expect(sheet.location.replace).toHaveBeenCalledWith('/admin.orders.receipt/WEB-7');
        // والورقةُ الحراريّة لا فاتورةُ A4
        expect(sheet.location.replace).not.toHaveBeenCalledWith(expect.stringMatching(/admin\.orders\.pdf/));
    });

    it('وأيُّ حالةٍ أخرى لا تفتح شيئًا', async () => {
        register([printer()]);
        serverSays(null);

        await orderScreen(detail());
        await userEvent.click(screen.getByRole('button', { name: 'قيد التجهيز' }));

        expect(openMock).not.toHaveBeenCalled();
    });

    it('ومنعُ المتصفّح يُقال مع زرٍّ للطباعة يدويًّا', async () => {
        register([printer()]);
        openMock.mockReturnValue(null);
        serverSays('WEB-7');

        await orderScreen(detail());
        await userEvent.click(screen.getByRole('button', { name: CONFIRMED }));

        expect(warning).toHaveBeenCalledTimes(1);
        const options = warning.mock.calls[0][1] as { action: { label: string } };
        expect(options.action.label).toBe('طباعة الفاتورة');
    });
});

/* ═══════════════════════ شاشة الجهاز الملحق ═══════════════════════ */

const peripheral = (over: Record<string, unknown> = {}) => ({
    id: 1,
    name: 'طابعة الكاونتر',
    type: 'طابعة',
    connection: 'usb',
    model: null,
    address: null,
    port: null,
    paperWidth: 80,
    autoPrint: false,
    autoPrintWebsite: false,
    notes: null,
    active: true,
    drivable: true,
    ...over,
});

const hardware = async (peripherals: unknown[]) => {
    const { default: PeripheralsDialog } = await import('@/Pages/Admin/Devices/partials/PeripheralsDialog');

    return render(
        <PeripheralsDialog
            deviceId={3}
            deviceName="صندوق الكاونتر"
            peripherals={peripherals as never}
            types={['طابعة', 'ماسح باركود', 'درج نقدي', 'شاشة عميل', 'ميزان']}
            drivableTypes={['طابعة', 'ماسح باركود']}
            paperWidths={[58, 80]}
            onClose={() => {}}
        />,
    );
};

/** المفتاحُ بعنوانه — العنوانُ وقفتُه والمفتاحُ جارُه في الصفّ نفسه */
const switchFor = (label: string) =>
    screen.getByText(label).closest('div')!.parentElement!.querySelector('[role="switch"]') as HTMLElement;

const editFirst = async () => {
    const pencil = document.querySelector('svg.lucide-pencil')!.closest('button')!;
    await userEvent.click(pencil);
};

describe('خيارُ الطابعة في شاشة الجهاز الملحق', () => {
    it('طابعةٌ جديدة: الخياران ظاهران مستقلّين، والجديدُ مطفأ', async () => {
        await hardware([]);
        await userEvent.click(screen.getByRole('button', { name: /إضافة جهاز ملحق/ }));

        expect(screen.getByText('طباعة تلقائية بعد البيع')).toBeInTheDocument();
        expect(screen.getByText('فتح إيصال الطباعة فور إتمام البيع في نقطة البيع.')).toBeInTheDocument();
        expect(screen.getByText('طباعة تلقائية عند تأكيد طلب الموقع')).toBeInTheDocument();
        expect(screen.getByText('فتح إيصال طلب الموقع عند تأكيده على هذا الجهاز.')).toBeInTheDocument();
        expect(switchFor('طباعة تلقائية عند تأكيد طلب الموقع')).toHaveAttribute('aria-checked', 'false');
    });

    it('وطابعةٌ ضُبط عليها تُفتح به — ولا يُشتقّ منه «بعد البيع»', async () => {
        await hardware([peripheral({ autoPrintWebsite: true, autoPrint: false })]);
        await editFirst();

        expect(switchFor('طباعة تلقائية عند تأكيد طلب الموقع')).toHaveAttribute('aria-checked', 'true');
        expect(switchFor('طباعة تلقائية بعد البيع')).toHaveAttribute('aria-checked', 'false');

        // وقلبُ أحدهما لا يقلب الآخر
        await userEvent.click(switchFor('طباعة تلقائية بعد البيع'));
        expect(switchFor('طباعة تلقائية بعد البيع')).toHaveAttribute('aria-checked', 'true');
        expect(switchFor('طباعة تلقائية عند تأكيد طلب الموقع')).toHaveAttribute('aria-checked', 'true');
    });

    it('والماسحُ لا يحمل الخيار', async () => {
        await hardware([peripheral({ id: 2, name: 'الماسح', type: 'ماسح باركود', paperWidth: null })]);
        await editFirst();

        expect(screen.queryByText('طباعة تلقائية عند تأكيد طلب الموقع')).toBeNull();
        expect(screen.queryByText('طباعة تلقائية بعد البيع')).toBeNull();
    });

    it('والقائمةُ تقول أيُّ طابعةٍ تطبع طلبَ الموقع', async () => {
        await hardware([peripheral({ autoPrintWebsite: true })]);

        expect(screen.getByText(/طباعة طلب الموقع عند تأكيده/)).toBeInTheDocument();
    });
});
