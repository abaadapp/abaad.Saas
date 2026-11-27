import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏سلّةٌ معلّقةٌ تُستأنف تُتمّ مرّةً واحدة — وما بعدها بيعةٌ مستقلّة.
 *
 * ═══ ما كان ═══
 *
 * معرّفُ السلّة المعلّقة (`resume_id`) يُقرأ مرّةً عند فتح الصفحة ولا يُفرَّغ.
 * فكلُّ بيعةٍ بعدها في الصفحة نفسِها تحمله:
 *
 *   • دُفعت المعلّقة: يردّ الخادمُ البيعةَ التالية «أُتمّت من جهازٍ آخر» —
 *     ولا يبيع الصندوقُ حتى تُعاد الصفحة.
 *   • أُلغيت أو عُلّقت من جديد ثمّ بيع غيرُها: يقبل الخادمُ البيعةَ الجديدة
 *     **ويحذف السلّةَ المعلّقة الأصليّة** (`PosController::checkout`) — يُفقد
 *     طلبُ زبونٍ لم يُدفع بلا أثر.
 *
 * و`client_uuid` كان سليمًا: لكلّ بيعةٍ معرّفُها، ولا يُعاد إلّا لإرسال السلّة
 * نفسِها حين لم تُحفظ (`unkept`).
 */

const product = (id: number, name: string, price: number) => ({
    id, name, name_en: null, label: name, cat: 'ورود', price, cost: 1, qty: 50, sku: null, barcode: null,
    image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, pos_enabled: true,
    pos_description: null, alert: 1, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0,
});
const shelf = [product(1, 'باقة معلّقة', 10), product(2, 'باقة جديدة', 4)];
const held = { id: 7, customer: null, items: [{ id: 1, name: 'باقة معلّقة', price: 10, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null }], coupon_code: null };

type Answer = 'ok' | 'refuse' | 'offline';
let answers: Answer[] = [];
let sent: Record<string, unknown>[] = [];
let holds = 0;

const open = async () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        products: shelf,
        resumeCart: held,
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

const outbox = () => JSON.parse(localStorage.getItem('abadpos:pos:outbox') || '[]') as { uuid: string; payload: Record<string, unknown> }[];

const pay = async (user: ReturnType<typeof userEvent.setup>) => {
    const before = sent.length;
    await user.click(within(screen.getByTestId('pos-paybar')).getAllByRole('button').find((b) => /الدفع/.test(b.textContent ?? ''))!);
    await user.click(await screen.findByRole('button', { name: /نقدي/ }));
    await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    await waitFor(() => expect(sent.length).toBe(before + 1));
};
const confirmAgain = async (user: ReturnType<typeof userEvent.setup>) => {
    const before = sent.length;
    await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    await waitFor(() => expect(sent.length).toBe(before + 1));
};
/** ‏تُغلق ورقةُ «تمّ الدفع» — فتبدأ البيعةُ التالية (`onNewOrder`) */
const done = async (user: ReturnType<typeof userEvent.setup>) => {
    await screen.findByText('تم الدفع بنجاح');
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByText('تم الدفع بنجاح')).toBeNull());
};
const pick = async (user: ReturnType<typeof userEvent.setup>, view: ReturnType<typeof render>, name: string) => {
    const card = [...view.container.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === name) as HTMLElement;
    await user.click(card);
};

beforeEach(() => {
    vi.resetModules();
    answers = [];
    sent = [];
    holds = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            if (String(url).includes('/pos/hold')) {
                holds++;

                return { ok: true, status: 200, json: async () => ({ ok: true }) };
            }
            if (String(url).includes('/pos/checkout')) {
                const body = JSON.parse(String(init?.body));
                sent.push(body);
                const answer = answers.shift() ?? 'ok';
                if (answer === 'offline') throw new TypeError('Failed to fetch');
                if (answer === 'refuse') {
                    return { ok: false, status: 422, json: async () => ({ message: 'رُدّت', errors: { items: ['رُدّت'] } }) };
                }

                return { ok: true, status: 200, json: async () => ({ ok: true, invoice: `INV-${sent.length}`, total: body.expected_total }) };
            }

            return { ok: true, status: 200, json: async () => ({}) };
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('السلّةُ المعلّقة تُتمّ مرّةً واحدة', () => {
    it('تُدفع بمعرّفها، ثمّ بيعةٌ جديدة في الصفحة نفسِها بلا معرّفها وبمعرّف بيعٍ جديد', async () => {
        const view = await open();
        const user = userEvent.setup();

        await pay(user);
        expect(sent[0].resume_id).toBe(7);
        await done(user);

        await pick(user, view, 'باقة جديدة');
        await pay(user);

        expect(sent[1].resume_id).toBeNull();
        expect(sent[1].client_uuid).not.toBe(sent[0].client_uuid);
    });

    it('أُلغيت المستأنفة ثمّ بيع غيرُها: لا تحمل البيعةُ معرّفَها — فلا يحذف الخادمُ السلّةَ المعلّقة', async () => {
        const view = await open();
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /إلغاء/ }));
        await pick(user, view, 'باقة جديدة');
        await pay(user);

        expect(sent[0].resume_id).toBeNull();
    });

    it('وعُلّقت من جديد ثمّ بيع غيرُها: كذلك', async () => {
        const view = await open();
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /تعليق/ }));
        await waitFor(() => expect(holds).toBe(1));
        await pick(user, view, 'باقة جديدة');
        await pay(user);

        expect(sent[0].resume_id).toBeNull();
    });

    it('رُدّت ثمّ أُعيدت السلّةُ نفسُها: تبقى بمعرّفها — فهي هي لم تُتمّ', async () => {
        await open();
        const user = userEvent.setup();
        answers = ['refuse', 'ok'];

        await pay(user);
        expect(sent[0].resume_id).toBe(7);
        await confirmAgain(user);

        expect(sent[1].resume_id).toBe(7);
        // ‏والخادمُ لم يكتب الأولى — فالثانية بمعرّفٍ جديد، لا تُطابَق بما رُدّ
        expect(sent[1].client_uuid).not.toBe(sent[0].client_uuid);
    });

    it('وبلا اتّصال: تبقى في الطابور بمعرّفها، والبيعةُ التالية بلا معرّفها ولكلٍّ معرّفُ بيعه', async () => {
        const view = await open();
        const user = userEvent.setup();
        answers = ['offline', 'offline'];

        await pay(user);
        await done(user);
        await pick(user, view, 'باقة جديدة');
        await pay(user);

        const queued = outbox();
        expect(queued.map((e) => e.payload.resume_id)).toEqual([7, null]);
        expect(new Set(queued.map((e) => e.uuid)).size).toBe(2);
    });
});
