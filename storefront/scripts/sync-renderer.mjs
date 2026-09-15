#!/usr/bin/env node
import { copyFileSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { rendererHash } from './renderer-hash.mjs';

/**
 * نسخُ طبقة الرسم إلى أبعاد.
 *
 * الاتّجاه واحدٌ دائمًا: من هنا إلى هناك. العارض هو المصدر لأنّه هو الذي
 * يُرى فعلًا، والمعاينة صورةٌ منه — لا العكس.
 *
 * والاثنان في مستودعٍ واحد منذ أن انتقل العارض إلى `storefront/`، فالوجهةُ
 * الافتراضية جذرُ المستودع ولا تُكتب بيد.
 *
 *   node scripts/sync-renderer.mjs [مسار أبعاد]
 */
const source = new URL('../src/site', import.meta.url).pathname;
/* وجهتُها جذرُ أبعاد — وهو المجلّد الذي يحوي `storefront/` نفسَه */
const saas = process.argv[2] ?? new URL('../..', import.meta.url).pathname;
const target = join(saas, 'resources/js/Pages/Admin/Website/preview/renderer');

const { files, hash } = rendererHash(source);

rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });

for (const file of files) {
    copyFileSync(join(source, file), join(target, file));
}

const stamp = `${hash}\n`;

writeFileSync(join(source, '..', '..', 'RENDERER_HASH'), stamp);
writeFileSync(join(target, 'RENDERER_HASH'), stamp);

console.log(`نُسخ ${files.length} ملفًّا إلى ${target}`);
console.log(`البصمة: ${hash}`);

// وملفٌّ زائدٌ في الوجهة يعني نسخةً قديمة بقيت — والحذف أعلاه يمنعه
const extra = readdirSync(target).filter((f) => f !== 'RENDERER_HASH' && !files.includes(f));

if (extra.length) {
    console.error(`ملفّاتٌ لا أصل لها: ${extra.join(', ')}`);
    process.exit(1);
}
