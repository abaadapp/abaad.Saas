import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (p: string) => readFileSync(resolve(process.cwd(), p), 'utf8');

const SHARED = [
    'resources/js/Components/conversations/Shell.tsx',
    'resources/js/Components/conversations/Thread.tsx',
    'resources/js/Components/conversations/Details.tsx',
    'resources/js/Components/conversations/Composer.tsx',
];

const PLATFORM = 'resources/js/Pages/Platform/Conversations/Index.tsx';
const CRM = 'resources/js/Pages/Platform/Crm/Conversations.tsx';

/** كلُّ `var(--cv-…)` في نصٍّ ما، وما بعد فاصلتِه الأولى إن وُجدت */
const uses = (src: string) => [...src.matchAll(/var\(\s*(--cv-[a-z-]+)\s*(,?)/g)].map((m) => ({ token: m[1], fallback: m[2] === ',' }));

/**
 * اللوحُ الداكن لا يخرج من صفحته.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * مكوّناتُ `Components/conversations` تُستعمل في شاشتين: دعمُ مدير المنصّة
 * ومبيعاتُ CRM. وصُبغت الأولى داكنةً بمتغيّرات CSS تُعرَّف على لوحها وحدَه،
 * وألوانُ المكوّنات صارت `var(--cv-X, <لونُها القديم>)`.
 *
 * فالعقدُ ثلاثةُ بنود، وكسرُ أيٍّ منها يُغيّر شاشةً لم يُطلب تغييرُها:
 *
 * ١) لكلّ متغيّرٍ بديلٌ مكتوب. و`var(--cv-panel)` بلا بديلٍ تُخرج CRM بلا
 *    لونٍ أصلًا — لوحٌ شفّافٌ على أبيض، أو نصٌّ بلا لون.
 * ٢) البديلُ هو اللونُ الذي كان. فمن بدّله بدّل CRM من حيث لا يقصد.
 * ٣) وصفحةُ CRM لا تُعرّف شيئًا من هذه المتغيّرات.
 */
describe('اللوحُ الداكن لا يخرج من صفحته', () => {
    it('كلُّ متغيّرٍ في المكوّنات المشتركة له بديلٌ مكتوب', () => {
        for (const file of SHARED) {
            const bare = uses(read(file)).filter((u) => !u.fallback);

            expect(bare.map((u) => `${file}: ${u.token}`)).toEqual([]);
        }
    });

    /**
     * والبديلُ هو لونُ اليوم — عيّنةٌ من أكثرها أثرًا.
     *
     * لو صار بديلُ `--cv-panel` داكنًا لَأسودّت لوحةُ CRM كلُّها بلا أن
     * تُفتح، ولا اختبارَ بصريَّ يقف في الطريق.
     */
    it('والبديلُ هو اللونُ الذي كان', () => {
        const shell = read(SHARED[0]);
        const thread = read(SHARED[1]);

        expect(shell).toContain('bg-[var(--cv-panel,#fff)]');
        expect(shell).toContain('bg-[var(--cv-accent,#2563eb)]');
        expect(shell).toContain('text-[var(--cv-ink,#111)]');
        expect(thread).toContain('bg-[var(--cv-out,#2563eb)]');
        expect(thread).toContain('bg-[var(--cv-in,#f3f5f9)]');
    });

    it('وصفحةُ CRM لا تُعرّف لوحًا ولا متغيّرًا', () => {
        const crm = read(CRM);

        expect(crm).not.toMatch(/--cv-/);
    });

    /**
     * وكلُّ ما تقرؤه المكوّناتُ تكتبه هذه الصفحة.
     *
     * متغيّرٌ يُضاف في مكوّنٍ ولا يُعرَّف هنا يعني عنصرًا يبقى بلونه الفاتح
     * داخل اللوح الداكن — نصٌّ رماديٌّ باهتٌ على أسود لا يُقرأ.
     */
    it('وصفحةُ الدعم تُعرّف كلَّ متغيّرٍ تقرؤه المكوّنات', () => {
        const page = read(PLATFORM);
        const defined = new Set([...page.matchAll(/'(--cv-[a-z-]+)':/g)].map((m) => m[1]));
        const needed = new Set(SHARED.flatMap((f) => uses(read(f)).map((u) => u.token)));

        expect([...needed].filter((tk) => !defined.has(tk))).toEqual([]);
    });
});
