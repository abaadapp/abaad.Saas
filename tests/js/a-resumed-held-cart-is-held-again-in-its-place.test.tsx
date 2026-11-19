import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

const toasts: { kind: string; msg: string }[] = [];
vi.mock('sonner', () => {
    const at = (kind: string) => (msg: unknown) => void toasts.push({ kind, msg: String(msg) });

    return { toast: Object.assign(at('plain'), { success: at('success'), error: at('error'), warning: at('warning'), info: at('info') }) };
});

/**
 * ‏طلبٌ معلّقٌ مستأنَف: «تعليق» يكتب على الطلب نفسِه، و«إلغاء» لا يمحوه،
 * والسلّةُ لا تُفرَّغ إلّا حين يقول الخادمُ إنّه حفظها.
 *
 * ═══ ما كان ═══
 *
 *   • «تعليق» المستأنف يُرسل بلا معرّفه، فيكتب الخادمُ طلبًا معلّقًا ثانيًا
 *     ويبقى الأصليّ — طلبُ زبونٍ واحد يُستكمل فاتورتين.
 *   • والسلّةُ تُفرَّغ بعد طلب التعليق أيًّا كان ردُّه، ويقول الصندوق «تم تعليق
 *     الطلب»: جلسةٌ انتهت أو رفضٌ أو خطأُ خادمٍ يمحو سلّةً لم تُحفظ.
 *   • وانقطاعُ الشبكة يرمي خطأً لا يُلتقط، فلا يعرف الكاشير أنّها لم تُعلَّق.
 *   • وضغطتان على «تعليق» طلبان معلّقان.
 *   • و«إلغاء» على المستأنف لا يقول إنّ الطلب باقٍ في «الطلبات المعلّقة».
 */

const product = (id: number, name: string, price: number) => ({
    id, name, name_en: null, label: name, cat: 'ورود', price, cost: 1, qty: 50, sku: null, barcode: null,
    image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, pos_enabled: true,
    pos_description: null, alert: 1, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0,
});
const shelf = [product(1, 'باقة معلّقة', 10), product(2, 'باقة جديدة', 4)];
const held = {
    id: 7, number: 'HOLD-0007', customer: null, coupon_code: null,
    items: [{ id: 1, name: 'باقة معلّقة', price: 10, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null }],
};

type Reply = 'ok' | 'gone' | 'offline' | 'expired' | 'error' | 'slow';
let replies: Reply[] = [];
let holds: Record<string, unknown>[] = [];
let others: string[] = [];
let release: (() => void) | null = null;

const open = async (resume: typeof held | null = held) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        products: shelf,
        resumeCart: resume,
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [],
        addons: [],
        coupons: [],
        settings: { vat: { enabled: true, rate: 5, inclusive: false } },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};

/** ‏السلّةُ فارغة: نصُّها حاضر — والمستأنفةُ صنفٌ واحد، فغيابُه يعني أنّها باقية */
const cartEmpty = () => !!screen.queryByText('السلة فارغة');
const pick = async (user: ReturnType<typeof userEvent.setup>, view: ReturnType<typeof render>, name: string) => {
    const card = [...view.container.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === name) as HTMLElement;
    await user.click(card);
};
const button = (name: RegExp) => screen.getByRole('button', { name });
const lastToast = () => toasts.at(-1);

beforeEach(() => {
    vi.resetModules();
    replies = [];
    holds = [];
    others = [];
    toasts.length = 0;
    release = null;
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            if (String(url).includes('/pos/hold')) {
                holds.push(JSON.parse(String(init?.body)));
                const reply = replies.shift() ?? 'ok';
                if (reply === 'offline') throw new TypeError('Failed to fetch');
                if (reply === 'slow') await new Promise<void>((ok) => (release = ok));
                if (reply === 'gone') {
                    return { ok: false, status: 422, json: async () => ({ message: 'x', errors: { resume_id: ['هذه السلّة المعلّقة لم تعد معلّقة'] } }) };
                }
                if (reply === 'expired') return { ok: false, status: 419, json: async () => ({ message: 'CSRF token mismatch.' }) };
                if (reply === 'error') return { ok: false, status: 500, json: async () => ({ message: 'Server Error' }) };

                return { ok: true, status: 200, json: async () => ({ ok: true, number: 'HOLD-0007', id: 7 }) };
            }
            others.push(`${init?.method ?? 'GET'} ${url}`);

            return { ok: true, status: 200, json: async () => ({}) };
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('طلبٌ معلّقٌ مستأنَف يُعلَّق في مكانه', () => {
    it('يُعرض رقمُه فوق السلّة، و«تعليق» يحمل معرّفه — ثمّ تُفرَّغ السلّة', async () => {
        await open();
        const user = userEvent.setup();

        expect(screen.getByTestId('pos-resumed').textContent).toContain('HOLD-0007');
        await user.click(button(/تعليق/));
        await waitFor(() => expect(holds).toHaveLength(1));

        expect(holds[0].resume_id).toBe(7);
        expect(holds[0].kind).toBe('hold');
        await waitFor(() => expect(cartEmpty()).toBe(true));
        expect(lastToast()?.msg).toContain('HOLD-0007');
        expect(screen.queryByTestId('pos-resumed')).toBeNull();
    });

    it('و«حفظ» كذلك يحمل معرّفه', async () => {
        await open();
        const user = userEvent.setup();

        await user.click(button(/^حفظ$/));
        await waitFor(() => expect(holds).toHaveLength(1));

        expect(holds[0]).toMatchObject({ resume_id: 7, kind: 'save' });
    });

    it('«إلغاء» لا يسأل الخادم شيئًا ويقول إنّ الطلب باقٍ — وما يُعلَّق بعده طلبٌ جديد', async () => {
        const view = await open();
        const user = userEvent.setup();

        await user.click(button(/إلغاء/));

        expect(cartEmpty()).toBe(true);
        expect(others.filter((r) => /orders|hold|checkout/.test(r))).toEqual([]);
        expect(lastToast()?.msg).toContain('HOLD-0007');
        expect(lastToast()?.msg).toContain('باقٍ');
        expect(screen.queryByTestId('pos-resumed')).toBeNull();

        await pick(user, view, 'باقة جديدة');
        await user.click(button(/تعليق/));
        await waitFor(() => expect(holds).toHaveLength(1));
        expect(holds[0].resume_id).toBeNull();
    });

    it('سلّةٌ جديدة تُعلَّق بلا معرّف', async () => {
        const view = await open(null);
        const user = userEvent.setup();

        await pick(user, view, 'باقة جديدة');
        await user.click(button(/تعليق/));
        await waitFor(() => expect(holds).toHaveLength(1));

        expect(holds[0].resume_id).toBeNull();
        expect(lastToast()).toEqual({ kind: 'warning', msg: 'تم تعليق الطلب' });
    });
});

describe('والسلّةُ لا تُفرَّغ إلّا حين يحفظها الخادم', () => {
    it('رُدّ التعليقُ (دُفع الطلبُ أو حُذف من جهازٍ آخر): تبقى السلّةُ وطلبُها، ويُقال السبب', async () => {
        await open();
        const user = userEvent.setup();
        replies = ['gone'];

        await user.click(button(/تعليق/));
        await waitFor(() => expect(lastToast()?.kind).toBe('error'));

        expect(lastToast()?.msg).toContain('لم تعد معلّقة');
        expect(cartEmpty()).toBe(false);
        expect(screen.getByTestId('pos-resumed').textContent).toContain('HOLD-0007');
    });

    it('بلا اتّصال: تبقى السلّة، ويُقال إنّها لم تُعلَّق', async () => {
        await open();
        const user = userEvent.setup();
        replies = ['offline'];

        await user.click(button(/تعليق/));
        await waitFor(() => expect(lastToast()?.kind).toBe('error'));

        expect(lastToast()?.msg).toContain('لم يُعلَّق');
        expect(cartEmpty()).toBe(false);
    });

    it('انتهت الجلسة (419) أو أخطأ الخادم (500): تبقى السلّة — ثمّ تُعلَّق حين يقبل', async () => {
        await open();
        const user = userEvent.setup();
        replies = ['expired', 'error', 'ok'];

        await user.click(button(/تعليق/));
        await waitFor(() => expect(toasts).toHaveLength(1));
        expect(toasts[0]).toMatchObject({ kind: 'error' });
        expect(toasts[0].msg).toContain('انتهت الجلسة');
        expect(cartEmpty()).toBe(false);

        await user.click(button(/تعليق/));
        await waitFor(() => expect(toasts).toHaveLength(2));
        expect(toasts[1].kind).toBe('error');
        expect(cartEmpty()).toBe(false);

        await user.click(button(/تعليق/));
        await waitFor(() => expect(cartEmpty()).toBe(true));
        expect(holds.map((h) => h.resume_id)).toEqual([7, 7, 7]);
    });

    it('ضغطتان على «تعليق» والأولى لم تُردّ: طلبٌ واحد', async () => {
        await open();
        const user = userEvent.setup();
        replies = ['slow'];

        await user.click(button(/تعليق/));
        await waitFor(() => expect(holds).toHaveLength(1));
        await user.click(button(/تعليق/));
        await user.click(button(/^حفظ$/));
        release?.();
        await waitFor(() => expect(cartEmpty()).toBe(true));

        expect(holds).toHaveLength(1);
    });
});
