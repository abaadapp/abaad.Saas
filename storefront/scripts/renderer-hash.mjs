#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * بصمةُ طبقة الرسم.
 *
 * الطبقة نسختان — واحدةٌ هنا وواحدةٌ في أبعاد معاينةً — والبصمة هي ما يمنع
 * افتراقهما: من عدّل ملفًّا في أحد الطرفين ولم يزامن يسقط اختبارُ ذلك الطرف
 * فورًا، فيعرف قبل أن يشتكي تاجرٌ أنّ ما رآه في المعاينة ليس ما نُشر.
 */
export function rendererHash(dir) {
    const files = readdirSync(dir).filter((f) => /\.(tsx?|css)$/.test(f)).sort();
    const hash = createHash('sha256');

    for (const file of files) {
        hash.update(file);
        hash.update('\0');
        hash.update(readFileSync(join(dir, file)));
        hash.update('\0');
    }

    return { hash: hash.digest('hex').slice(0, 16), files };
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const dir = process.argv[2] ?? new URL('../src/site', import.meta.url).pathname;
    const { hash, files } = rendererHash(dir);

    console.log(`${hash}  (${files.length} ملفًّا)`);
}
