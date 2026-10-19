import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * إتمامُ RIBBON — آخرُ تسعيرةٍ وحدها تُرسم، ولا صفَّ توصيلٍ لمن يستلم.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الاستلامُ من المحلّ لا يُرسم له صفُّ «التوصيل» أصلًا — لا «مجاني» ولا
 *     فراغ. والتوصيلُ يُرسم برسمه، و«مجاني» لتوصيلٍ رسمُه صفرٌ حقًّا.
 *   - والقرارُ من طريقة الاستلام التي **سعّرها الخادم** (`q.fulfil`) لا من زرٍّ.
 *   - وتسعيرتان تتسابقان: الأخيرةُ تُرسم والسابقةُ تُطرح ولو عادت بعدها.
 *     والزرُّ مقفلٌ ما دامت الأخيرةُ في الطريق، وانقطاعٌ لا يُبقيه مقفلًا.
 *
 * والمكتبةُ تُضمَّن في القالب كما هي، فيُقرأ القالبُ هنا ليُعرف أنّه يقرأ منها. وما يرسمه الخادمُ في `TheRibbonCheckoutDrawsNoDeliveryForPickupTest`.
 */

type Quote = { ok: boolean; fulfil?: string; delivery?: number; delivery_text?: string; total?: number };
type QuoteApi = {
    latest: (fetcher: (args: unknown) => Promise<Quote>) => { run: (args: unknown) => Promise<Quote | null>; pending: () => boolean };
    shipping: (q: Quote | null, T: { free: string }) => { show: boolean; text: string };
};

const ROOT = resolve(__dirname, '../..');
const QUOTE = resolve(ROOT, 'resources/js/store/ribbon-quote.js');
const CHECKOUT = resolve(ROOT, 'resources/views/store/ribbon/checkout.blade.php');

const quoteApi = () => (window as unknown as { RBQuote: QuoteApi }).RBQuote;

beforeAll(() => {
    new Function(readFileSync(QUOTE, 'utf8'))();
});

const T = { free: 'مجاني' };

/** وعدٌ يُحَلّ بيد الاختبار — ليعود الجوابان بالترتيب الذي يختاره */
function deferred<V>() {
    let resolve!: (v: V) => void;
    let reject!: (e: unknown) => void;
    const promise = new Promise<V>((res, rej) => { resolve = res; reject = rej; });

    return { promise, resolve, reject };
}

describe('صفُّ التوصيل في الملخّص', () => {
    it('الاستلامُ لا صفَّ له — ولو كان الرسمُ صفرًا', () => {
        expect(quoteApi().shipping({ ok: true, fulfil: 'pickup', delivery: 0, delivery_text: '0.000 ر.ع' }, T))
            .toEqual({ show: false, text: '' });
    });

    it('والتوصيلُ برسمه كما كتبه الخادم', () => {
        expect(quoteApi().shipping({ ok: true, fulfil: 'delivery', delivery: 2, delivery_text: '2.000 ر.ع' }, T))
            .toEqual({ show: true, text: '2.000 ر.ع' });
    });

    it('و«مجاني» لتوصيلٍ رسمُه صفرٌ حقًّا', () => {
        expect(quoteApi().shipping({ ok: true, fulfil: 'delivery', delivery: 0, delivery_text: '0.000 ر.ع' }, T))
            .toEqual({ show: true, text: 'مجاني' });
    });

    it('وتسعيرةٌ لم تنجح — أو لم تعد — لا ترسم صفًّا', () => {
        expect(quoteApi().shipping({ ok: false }, T).show).toBe(false);
        expect(quoteApi().shipping(null, T).show).toBe(false);
    });
});

describe('آخرُ تسعيرةٍ وحدها تُرسم', () => {
    it('توصيلٌ ثمّ استلامٌ بسرعة، وعادت تسعيرةُ التوصيل آخرًا — تُطرح', async () => {
        const calls: Array<ReturnType<typeof deferred<Quote>>> = [];
        const q = quoteApi().latest(() => { const d = deferred<Quote>(); calls.push(d); return d.promise; });

        const first = q.run({ fulfil: 'delivery' });
        const second = q.run({ fulfil: 'pickup' });
        expect(q.pending()).toBe(true);

        calls[1].resolve({ ok: true, fulfil: 'pickup', delivery: 0, total: 10 });
        expect(await second).toEqual({ ok: true, fulfil: 'pickup', delivery: 0, total: 10 });
        expect(q.pending()).toBe(false);

        calls[0].resolve({ ok: true, fulfil: 'delivery', delivery: 2, total: 12 });
        expect(await first).toBeNull();
        expect(q.pending()).toBe(false);
    });

    it('ثلاثُ ضغطاتٍ عادت بغير ترتيبها — الأخيرةُ وحدها', async () => {
        const calls: Array<ReturnType<typeof deferred<Quote>>> = [];
        const q = quoteApi().latest(() => { const d = deferred<Quote>(); calls.push(d); return d.promise; });

        const runs = [q.run({ fulfil: 'pickup' }), q.run({ fulfil: 'delivery' }), q.run({ fulfil: 'pickup' })];
        calls[2].resolve({ ok: true, fulfil: 'pickup' });
        calls[0].resolve({ ok: true, fulfil: 'pickup' });
        calls[1].resolve({ ok: true, fulfil: 'delivery' });

        expect(await Promise.all(runs)).toEqual([null, null, { ok: true, fulfil: 'pickup' }]);
    });

    it('والزرُّ مقفلٌ ما دام الأخيرُ في الطريق — ولو عاد ما سبقه', async () => {
        const calls: Array<ReturnType<typeof deferred<Quote>>> = [];
        const q = quoteApi().latest(() => { const d = deferred<Quote>(); calls.push(d); return d.promise; });

        q.run({});
        const last = q.run({});
        calls[0].resolve({ ok: true });
        await Promise.resolve();
        expect(q.pending()).toBe(true);

        calls[1].resolve({ ok: true });
        await last;
        expect(q.pending()).toBe(false);
    });

    it('وانقطاعُ الشبكة يُقرأ ردًّا لم ينجح — لا قفلًا للأبد', async () => {
        const q = quoteApi().latest(() => Promise.reject(new Error('offline')));
        expect(await q.run({})).toEqual({ ok: false, errors: {} });
        expect(q.pending()).toBe(false);
    });
});

describe('القالبُ يقرأ من المكتبة', () => {
    const checkout = readFileSync(CHECKOUT, 'utf8');

    it('الإتمامُ يضمّن `ribbon-quote.js` ويرسم صفَّ التوصيل منه', () => {
        expect(checkout).toContain("resource_path('js/store/ribbon-quote.js')");
        expect(checkout).toContain('RBQuote.latest(');
        expect(checkout).toContain('RBQuote.shipping(q, T)');
        expect(checkout).toContain('data-rb-ship-row');
        // ولا «مجاني» لكلّ رسمٍ صفرٍ كما كان
        expect(checkout).not.toContain("q.delivery > 0 ? q.delivery_text : T.free");
    });

    it('ولا يُرسَل الطلبُ وتسعيرةٌ في الطريق', () => {
        expect(checkout).toMatch(/form\.addEventListener\('submit'[\s\S]*?if \(quoting\.pending\(\)\) return;/);
    });

});
