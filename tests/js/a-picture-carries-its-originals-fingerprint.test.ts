import { webcrypto } from 'node:crypto';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { originalOf, sha256, shrunkWithOriginal } from '@/lib/image-fingerprint';

/**
 * بصمةُ الأصل قبل التصغير — يرسلها المتصفّح مع الصورة المصغَّرة وحدها.
 *
 * والخادمُ يقرؤها تلميحًا (`ImageFingerprints::hint`): أربعةٌ وستّون حرفًا ستّ عشريًّا،
 * وما سواها لا شيء. وانظر `AShopKeepsOneCopyOfTheSamePictureTest` لما يفعله بها.
 */
describe('بصمةُ الأصل', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('SHA-256 بأربعةٍ وستّين حرفًا — كما يقرؤها الخادم', async () => {
        vi.stubGlobal('crypto', webcrypto);

        // ‏بصمةُ «abc» المعروفة
        await expect(sha256(new Blob(['abc']))).resolves.toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    });

    it('تُحفظ مع المصغَّر وحده — والمرسَلُ كما هو لا يحمل شيئًا', async () => {
        vi.stubGlobal('crypto', webcrypto);
        const original = new File(['photo-bytes'], 'a.jpg', { type: 'image/jpeg' });
        const small = new File(['smaller'], 'a.jpg', { type: 'image/jpeg' });

        const sent = await shrunkWithOriginal(original, async () => small);
        expect(sent).toBe(small);
        expect(originalOf(small)).toBe(await sha256(original));
        expect(originalOf(small)).toMatch(/^[0-9a-f]{64}$/);

        // ‏ما تعذّر تصغيرُه يُرسَل كما هو — بايتاتُه أصلُه، فلا بصمةَ تُرسَل
        const same = await shrunkWithOriginal(original, async (f) => f);
        expect(same).toBe(original);
        expect(originalOf(original)).toBe('');
    });

    it('وبلا crypto.subtle (صفحةٌ بلا https) يبقى الرفعُ كما كان', async () => {
        vi.stubGlobal('crypto', {});
        const original = new File(['x'], 'a.jpg');
        const small = new File(['y'], 'a.jpg');

        await expect(sha256(original)).resolves.toBeNull();
        await expect(shrunkWithOriginal(original, async () => small)).resolves.toBe(small);
        expect(originalOf(small)).toBe('');
    });
});
