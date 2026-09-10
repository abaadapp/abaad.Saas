import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * ورقةٌ لا تُعيد ترتيب نفسها على كلّ تمريرة.
 *
 * إطارُ المعاينة يحمل مستندًا كاملًا — جدولَ أصنافٍ وحواشيَ وتوقيعًا. وارتفاعُه
 * إن عُلِّق على `dvh` تغيّر مع انطواء شريط المتصفّح على الهاتف واللوح، فيُعاد
 * حسابُ الورقة إطارًا بعد إطارٍ ما دام الإصبع ينزل. و`svh` قيمةٌ ثابتة لا
 * يمسّها التمرير.
 *
 * والحارسُ يقرأ النصّ لأنّ jsdom لا يرسم: لا ارتفاعَ يُقاس فيه ولا وحدةَ
 * عرضٍ تُحسب. فما يُحرس هو ما يُكتب.
 */
const SCREENS = [
    'resources/js/Pages/Admin/Purchases/Create.tsx',
    'resources/js/Pages/Admin/CustomerInvoices/Create.tsx',
    'resources/js/Pages/Admin/Settings/TemplateEditor.tsx',
];

describe('ارتفاع إطار المعاينة', () => {
    it.each(SCREENS)('لا يُقاس بـdvh في %s', (path) => {
        const src = readFileSync(path, 'utf8');
        const frame = src.slice(src.indexOf('<iframe'));

        expect(frame).toContain('svh');
        expect(frame).not.toContain('dvh');
    });

    it.each(SCREENS)('يبقى للإطار ارتفاعٌ مصرَّحٌ به في %s', (path) => {
        const src = readFileSync(path, 'utf8');
        const frame = src.slice(src.indexOf('<iframe'));

        /* إطارٌ بلا ارتفاع يُرسم ١٥٠ بكسلًا افتراضيّةً — شريطٌ لا ورقة */
        expect(frame).toMatch(/h-\[\d+svh\]/);
    });

    it.each(SCREENS)('ولا تُنفَّذ نصوصُ الورقة في %s', (path) => {
        const src = readFileSync(path, 'utf8');
        const frame = src.slice(src.indexOf('<iframe'));

        expect(frame).toContain('sandbox=""');
    });
});
