import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { normalizeHost, siteOrigin } from '@/lib/host';
import { store } from './helpers';

/**
 * حلُّ النطاق وحالاتُ أبعاد الأربع.
 *
 * وهذه هي الحالات التي تُنسى فتصير صفحاتٍ بيضاء أو رسائلَ إنجليزية عن
 * `ECONNREFUSED` في موقع تاجرٍ عربيّ.
 */
describe('المضيف يُقرأ بحذر', () => {
    it('يقصّ المنفذ وwww والنقطة الأخيرة', () => {
        expect(normalizeHost('WWW.Wrood.Om:3000')).toBe('wrood.om');
        expect(normalizeHost('wrood.om.')).toBe('wrood.om');
        expect(normalizeHost(' shop.abaad.store ')).toBe('shop.abaad.store');
    });

    it('يردّ null لما ليس نطاقًا', () => {
        for (const bad of [null, '', 'localhost', '../etc/passwd', 'a b.om', 'wrood.om/x', '-x.om', 'x..om']) {
            expect(normalizeHost(bad), String(bad)).toBeNull();
        }
    });

    it('يأخذ أوّل مضيفٍ إن جاءت الترويسة مكرّرة', () => {
        expect(normalizeHost('wrood.om, evil.com')).toBe('wrood.om');
    });

    it('الأصل https دائمًا — ولا يُقرأ ما يقوله الوكيل', () => {
        expect(siteOrigin('wrood.om')).toBe('https://wrood.om');
        expect(siteOrigin('shop.abaad.store')).toBe('https://shop.abaad.store');
    });
});

describe('حالات أبعاد', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('ABAAD_API_URL', 'https://app.test');
        fetchMock.mockReset();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.unstubAllEnvs();
    });

    /** `cache` من React تحفظ النتيجة، فيُستورد الملفّ من جديد لكلّ حالة */
    async function call(host: string | null) {
        vi.resetModules();
        const { fetchSite } = await import('@/lib/api');

        return fetchSite(host);
    }

    function reply(status: number, body: unknown): Response {
        return { status, ok: status >= 200 && status < 300, json: async () => body } as Response;
    }

    it('موقعٌ منشور يصل مستندًا', async () => {
        fetchMock.mockResolvedValue(reply(200, { site: store, version: 3, published_at: '2026-09-01T10:00:00+00:00' }));

        const result = await call('wrood.om');

        expect(result.status).toBe('ok');
        expect(result.status === 'ok' && result.doc.pages.length).toBeGreaterThan(0);
        expect(fetchMock.mock.calls[0][0]).toBe('https://app.test/site/wrood.om');
    });

    it('نطاقٌ لا موقع عليه — لا موجود', async () => {
        fetchMock.mockResolvedValue(reply(404, { error: 'not_found' }));

        expect(await call('nope.om')).toEqual({ status: 'missing', reason: 'not_found' });
    });

    it('موقعٌ مسوّدةٌ لم يُنشر — لا يخرج', async () => {
        fetchMock.mockResolvedValue(reply(404, { error: 'not_published' }));

        expect(await call('draft.om')).toEqual({ status: 'missing', reason: 'not_published' });
    });

    it('مضيفٌ مرفوض لا يُطلب من أبعاد أصلًا', async () => {
        expect(await call(null)).toEqual({ status: 'missing', reason: 'bad_host' });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('الصيانة تصل برسالتها وهويّتها ولا تكشف الصفحات', async () => {
        fetchMock.mockResolvedValue(
            reply(503, {
                maintenance: true,
                name: 'ورود مسقط',
                message: 'نجدّد المحل — نعود الأحد',
                tokens: store.tokens,
                brand: store.brand,
            }),
        );

        const result = await call('wrood.om');

        expect(result.status).toBe('maintenance');
        expect(result.status === 'maintenance' && result.info.message).toBe('نجدّد المحل — نعود الأحد');
        expect(JSON.stringify(result)).not.toContain('"pages"');
    });

    it('عطبٌ في أبعاد لا يصل الزائر خبرًا تقنيًّا', async () => {
        fetchMock.mockResolvedValue(reply(500, { message: 'Server Error' }));

        expect(await call('wrood.om')).toEqual({ status: 'unavailable', reason: 'server' });
    });

    it('انقطاعُ الشبكة حالةٌ لا استثناءٌ يُرمى', async () => {
        fetchMock.mockRejectedValue(new TypeError('fetch failed'));

        expect(await call('wrood.om')).toEqual({ status: 'unavailable', reason: 'network' });
    });

    it('المهلة تنتهي فتُعرض «غير متاح» لا صفحةٌ تدور إلى الأبد', async () => {
        const abort = new Error('aborted');

        abort.name = 'AbortError';
        fetchMock.mockRejectedValue(abort);

        expect(await call('wrood.om')).toEqual({ status: 'unavailable', reason: 'timeout' });
    });

    it('جسمٌ مشوَّه لا يُرسم موقعًا فارغًا', async () => {
        fetchMock.mockResolvedValue(reply(200, { site: { name: 'x' } }));

        expect(await call('wrood.om')).toEqual({ status: 'unavailable', reason: 'bad_payload' });
    });

    it('جسمٌ ليس JSON أصلًا', async () => {
        fetchMock.mockResolvedValue({
            status: 200,
            ok: true,
            json: async () => {
                throw new SyntaxError('Unexpected token <');
            },
        } as unknown as Response);

        expect(await call('wrood.om')).toEqual({ status: 'unavailable', reason: 'bad_payload' });
    });
});
