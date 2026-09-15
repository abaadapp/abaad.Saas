import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { rendererHash } from '../scripts/renderer-hash.mjs';
import { KNOWN_TYPES } from '@/site/blocks';
import { store, profile } from './helpers';

/* المسارات من جذر المشروع: `import.meta.url` تحت vitest ليست مسار ملفّ */
const ROOT = process.cwd();
const SITE = resolve(ROOT, 'src/site');
const MIRROR = resolve(ROOT, '../resources/js/Pages/Admin/Website/preview/renderer');

/**
 * طبقةُ الرسم واحدةٌ في الموضعين.
 *
 * وهذا هو الحارس: من عدّل هنا ولم يزامن يسقط هذا الاختبار، ومن عدّل نسخة
 * المعاينة يسقط نظيرُه هناك. فلا تفترق المعاينة عن الموقع صامتةً — وهو
 * الافتراق الذي يجعل التاجر ينشر ما لم يره.
 *
 * وصار الموضعان في مستودعٍ واحد، فالمزامنةُ والتعديلُ يقعان في التزامٍ
 * واحد — ولا يبقى نصفُ التغيير مدفوعًا ونصفُه على جهازٍ وحده.
 */
describe('طبقةُ الرسم', () => {
    it('بصمتُها هي المسجَّلة — وإلا فالمزامنة لم تُشغَّل', () => {
        const recorded = readFileSync(resolve(ROOT, 'RENDERER_HASH'), 'utf8').trim();

        expect(rendererHash(SITE).hash, 'شغّل: node scripts/sync-renderer.mjs').toBe(recorded);
    });

    it('نسخة أبعاد مطابقةٌ حرفًا', () => {
        expect(rendererHash(MIRROR).hash).toBe(rendererHash(SITE).hash);
    });

    it('كلُّ نوعٍ يبنيه أبعاد له رسمٌ هنا', () => {
        const used = new Set<string>();

        for (const doc of [store, profile]) {
            for (const page of doc.pages) for (const s of page.sections) used.add(s.type);
        }

        expect([...used].filter((t) => !KNOWN_TYPES.includes(t))).toEqual([]);
    });
});
