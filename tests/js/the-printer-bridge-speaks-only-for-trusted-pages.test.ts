import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * `window.AbaadPrinter` كما يحقنه التطبيقان — `mobile/shared/js/abaad-printer-shim.js`.
 *
 * ملفٌّ واحدٌ يحمله Android وiOS كما هو، فيُختبر هنا مرّةً للاثنين. وما يُقاس:
 * أنّه لا يتكلّم إلّا لصفحةٍ موثوقةٍ في الإطار الرئيسيّ، وأنّ ما يُرسله طلبٌ
 * مُهيكلٌ لا بايتات، وأنّ الصفحة لا تستطيع تبديلَ طريقه بعد التحميل، وأنّ
 * صمتَ الطرف الأصليّ لا يُقرأ «طُبع».
 *
 * والتحقّقُ الحقيقيّ في Kotlin وSwift — هذا الملفّ راحةٌ لا حراسة.
 */

const SOURCE = readFileSync(path.resolve(__dirname, '../../mobile/shared/js/abaad-printer-shim.js'), 'utf8');
const ORIGIN = 'https://pos.example.test';

interface Page {
    AbaadPrinter?: {
        version: number;
        platform: string;
        isAvailable(): boolean;
        printReceipt(job: unknown, onProgress?: (s: string) => void): Promise<Record<string, unknown>>;
        testPrinter(job: unknown, onProgress?: (s: string) => void): Promise<Record<string, unknown>>;
        openReceipt(job: unknown): Promise<Record<string, unknown>>;
    };
    __abaadPrinterConfig?: unknown;
    location: { origin: string };
    top: unknown;
    self: unknown;
}

/** صفحةٌ معزولة يُحقن فيها الملفّ — كما يحقنه التطبيق عند بدء المستند */
function inject({ origin = ORIGIN, iframe = false, post }: { origin?: string; iframe?: boolean; post?: (s: string) => void } = {}) {
    const sent: string[] = [];
    let deliver: (raw: unknown) => void = () => {};
    const page: Page = { location: { origin }, top: null, self: null };
    page.self = page;
    page.top = iframe ? {} : page;
    page.__abaadPrinterConfig = {
        platform: 'android',
        trustedOrigins: [ORIGIN],
        post: post ?? ((s: string) => sent.push(s)),
        listen: (receive: (raw: unknown) => void) => {
            deliver = receive;
        },
    };

    new Function('window', SOURCE)(page);

    return { page, sent, reply: (raw: unknown) => deliver(raw), api: page.AbaadPrinter! };
}

const JOB = {
    invoice: 'INV-1',
    receiptUrl: `${ORIGIN}/pos/receipt/INV-1/pdf`,
    printer: { id: 1, name: 'P', model: null, connection: 'network', address: '192.168.1.50', port: null, paperWidth: 80 },
};

afterEach(() => {
    vi.useRealTimers();
});

describe('الصفحةُ الموثوقة', () => {
    it('تُرسل طلبًا مُهيكلًا — ورقمُه يربط الردَّ به', async () => {
        const { api, sent, reply } = inject();

        expect(api.isAvailable()).toBe(true);
        expect(api.platform).toBe('android');

        const stages: string[] = [];
        const pending = api.printReceipt(JOB, (s) => stages.push(s));

        expect(sent).toHaveLength(1);
        const message = JSON.parse(sent[0]);
        expect(message).toEqual({ v: 1, id: expect.any(String), method: 'printReceipt', payload: JOB });

        reply(JSON.stringify({ id: message.id, type: 'progress', stage: 'sending' }));
        reply(JSON.stringify({ id: message.id, type: 'result', result: { ok: true, jobId: 'n-1', effectivePort: 9100 } }));

        await expect(pending).resolves.toEqual({ ok: true, jobId: 'n-1', effectivePort: 9100 });
        expect(stages).toEqual(['sending']);
    });

    it('ولا بابَ لبايتاتٍ خام — ثلاثُ طلباتٍ مُهيكلة وحدها', () => {
        const { api } = inject();

        expect(Object.keys(api).sort()).toEqual(['isAvailable', 'openReceipt', 'platform', 'printReceipt', 'testPrinter', 'version']);
    });

    it('والإعدادُ يُؤخذ ثمّ يُمحى — والواجهةُ لا تُبدَّل', () => {
        const { page, api } = inject();

        expect(page.__abaadPrinterConfig).toBeUndefined();
        expect(Object.getOwnPropertyDescriptor(page, 'AbaadPrinter')).toMatchObject({ writable: false, configurable: false });
        expect(Object.isFrozen(api)).toBe(true);
    });
});

describe('ما لا يتكلّم له', () => {
    it('أصلٌ غيرُ موثوق — لا يُرسل شيئًا', async () => {
        const { api, sent } = inject({ origin: 'https://evil.test' });

        expect(api.isAvailable()).toBe(false);
        await expect(api.printReceipt(JOB)).resolves.toMatchObject({ ok: false, code: 'UNTRUSTED_ORIGIN' });
        expect(sent).toHaveLength(0);
    });

    it('وإطارٌ داخل الصفحة — ولو من الأصل نفسه', async () => {
        const { api, sent } = inject({ iframe: true });

        expect(api.isAvailable()).toBe(false);
        await expect(api.testPrinter({})).resolves.toMatchObject({ ok: false, code: 'UNTRUSTED_ORIGIN' });
        expect(sent).toHaveLength(0);
    });
});

describe('الردودُ لا تُصدَّق إلّا صريحة', () => {
    it('ردٌّ لطلبٍ آخر، أو نصٌّ مكسور — يُهمل', async () => {
        vi.useFakeTimers();
        const { api, sent, reply } = inject();
        const pending = api.openReceipt({ invoice: 'INV-1', receiptUrl: JOB.receiptUrl });
        const id = JSON.parse(sent[0]).id;

        reply('{not json');
        reply(JSON.stringify({ id: 'someone-else', type: 'result', result: { ok: true } }));
        reply(JSON.stringify({ id, type: 'result', result: { ok: false } }));

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'INTERNAL' });
    });

    it('و«نعم» غيرُ `true` ليست نجاحًا', async () => {
        const { api, sent, reply } = inject();
        const pending = api.printReceipt(JOB);
        const id = JSON.parse(sent[0]).id;

        reply(JSON.stringify({ id, type: 'result', result: { ok: 1, printed: true } }));

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'INTERNAL' });
    });

    it('ولا ردَّ في المهلة ← «قد يكون طُبع»، لا «طُبع» ولا إعادة', async () => {
        vi.useFakeTimers();
        const { api } = inject();
        const pending = api.printReceipt(JOB);

        vi.advanceTimersByTime(120_000);

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'BRIDGE_TIMEOUT', mayHavePrinted: true });
    });

    /*
     * والمهلةُ صمتٌ لا مدّة: إيصالٌ طويلٌ على طابعةٍ بطيئة يُطبع دقائق، والطرفُ الأصليّ
     * يعيد المرحلةَ نبضةً ما دام يتقدّم. فخمسُ دقائقَ بنبضةٍ كلَّ دقيقةٍ لا تُقطع.
     */
    it('وكلُّ رسالة تقدّمٍ تبدأ المهلةَ من جديد — فالإيصالُ الطويل لا يُقطع انتظارُه', async () => {
        vi.useFakeTimers();
        const { api, sent, reply } = inject();
        const stages: string[] = [];
        const pending = api.printReceipt(JOB, (s) => stages.push(s));
        const id = JSON.parse(sent[0]).id;
        let settled = false;
        void pending.then(() => {
            settled = true;
        });

        for (let minute = 0; minute < 5; minute++) {
            vi.advanceTimersByTime(60_000);
            reply(JSON.stringify({ id, type: 'progress', stage: 'sending' }));
        }
        await Promise.resolve();
        expect(settled).toBe(false);

        reply(JSON.stringify({ id, type: 'result', result: { ok: true, jobId: 'n-1', printed: true } }));
        await expect(pending).resolves.toMatchObject({ ok: true, printed: true });
        expect(stages).toEqual(['sending', 'sending', 'sending', 'sending', 'sending']);
    });

    it('وصمتٌ بطول المهلة بعد آخر نبضة ← «قد يكون طُبع»', async () => {
        vi.useFakeTimers();
        const { api, sent, reply } = inject();
        const pending = api.printReceipt(JOB);
        const id = JSON.parse(sent[0]).id;

        vi.advanceTimersByTime(100_000);
        reply(JSON.stringify({ id, type: 'progress', stage: 'sending' }));
        vi.advanceTimersByTime(119_999);
        let settled = false;
        void pending.then(() => {
            settled = true;
        });
        await Promise.resolve();
        expect(settled).toBe(false);

        vi.advanceTimersByTime(1);
        await expect(pending).resolves.toMatchObject({ ok: false, code: 'BRIDGE_TIMEOUT', mayHavePrinted: true });
    });

    it('وفتحُ الملفّ لا يطبع شيئًا — فمهلتُه لا تقول «قد يكون طُبع»', async () => {
        vi.useFakeTimers();
        const { api } = inject();
        const pending = api.openReceipt({ invoice: 'INV-1', receiptUrl: JOB.receiptUrl });

        vi.advanceTimersByTime(120_000);

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'BRIDGE_TIMEOUT', mayHavePrinted: false });
    });

    it('وطرفٌ أصليٌّ لا يُستقبل ← فشلٌ يُعرض', async () => {
        const { api } = inject({
            post: () => {
                throw new Error('gone');
            },
        });

        await expect(api.printReceipt(JOB)).resolves.toMatchObject({ ok: false, code: 'BRIDGE_UNAVAILABLE' });
    });
});
