import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * ورقةُ المعاينة — مقاسُها ثابت، ولا تُنفَّذ نصوصُها.
 *
 * ═══ ولمَ صار الحارسُ على مكوّنٍ واحد ═══
 *
 * كانت ثلاثُ شاشاتٍ تكتب إطارَها بيدها، فكان يُحرَس ثلاثًا. وصرن يقرأن
 * `Components/PaperFrame` — فالقاعدةُ تُحرَس حيث تُكتب مرّةً واحدة، ولا
 * تُنسى في الرابعة حين تُضاف.
 *
 * والحارسُ يقرأ النصّ لأنّ jsdom لا يرسم: لا ارتفاعَ يُقاس فيه ولا وحدةَ
 * عرضٍ تُحسب. فما يُحرس هو ما يُكتب.
 */
const FRAME = 'resources/js/Components/PaperFrame.tsx';

const SCREENS = [
    'resources/js/Pages/Admin/Purchases/Create.tsx',
    'resources/js/Pages/Admin/CustomerInvoices/Create.tsx',
    'resources/js/Pages/Admin/Settings/TemplateEditor.tsx',
];

const frame = () => readFileSync(FRAME, 'utf8');

describe('ورقة المعاينة', () => {
    it.each(SCREENS)('%s ترسم ورقتَها بالمكوّن لا بإطارٍ من عندها', (path) => {
        const src = readFileSync(path, 'utf8');

        expect(src).toContain('<PaperFrame');
        /* وإطارٌ ثانٍ في الشاشة يعني قاعدتين للورقة الواحدة */
        expect(src).not.toContain('<iframe');
    });

    /**
     * ولا يُنفَّذ شيءٌ من الورقة في اللوحة.
     *
     * `allow-same-origin` وحدها تفتح القراءة — وبها يُقاس الارتفاع فتُعرف
     * الصفحات. و`allow-scripts` معها تفتح الكتابة في اللوحة نفسِها، وهي
     * التركيبةُ التي تُبطل العزلَ من أصله.
     */
    it('لا تُنفَّذ نصوصُ الورقة', () => {
        /*
         * وتُقرأ القيمةُ نفسُها لا الملفُّ كلُّه: التعليقُ الذي يشرح لماذا
         * لا يُمنح `allow-scripts` يحوي الكلمةَ — فحارسٌ يبحث عنها في النصّ
         * يسقط على شرحِ نفسِه.
         */
        const sandbox = frame().match(/sandbox="([^"]*)"/);

        expect(sandbox).not.toBeNull();
        expect(sandbox?.[1]).toBe('allow-same-origin');
    });

    /**
     * والنافذةُ لا تُقاس بـdvh.
     *
     * ارتفاعٌ معلَّقٌ على `dvh` يتغيّر مع انطواء شريط المتصفّح على الهاتف،
     * فيُعاد حسابُ الورقة إطارًا بعد إطارٍ ما دام الإصبع ينزل. و`svh` قيمةٌ
     * ثابتة لا يمسّها التمرير.
     */
    it('نافذةُ العرض بـsvh لا dvh', () => {
        expect(frame()).toContain('svh');
        expect(frame()).not.toContain('dvh');

        for (const path of SCREENS) {
            expect(readFileSync(path, 'utf8')).not.toMatch(/viewport="[^"]*dvh/);
        }
    });

    /**
     * وللورقة مقاسٌ حقيقيّ لا صندوقٌ بعرض العمود.
     *
     * كان الإطارُ مستطيلًا عريضًا يتغيّر شكلُه بحجم النافذة، فيضبط التاجر
     * قالبَه على شكلٍ لا وجود له: ترويسةٌ ممدودةٌ على عرضٍ لن تُطبع عليه،
     * ولا حدَّ يقول أين تنتهي الصفحة الأولى.
     */
    it('تُرسم بمقاس الورقة الحقيقيّ ثمّ تُصغَّر', () => {
        const src = frame();

        /* والتصغيرُ تحويلٌ بصريّ: تضييقُ الإطار يُعيد التخطيط فيكذب */
        expect(src).toContain('transform: `scale(');
        expect(src).toContain("transformOrigin: 'top left'");
        expect(src).toContain('PX_PER_MM = 96 / 25.4');
    });

    /**
     * ومقاساتُ الشاشة هي مقاساتُ الخادم — رقمًا رقمًا.
     *
     * الشاشةُ ترسم الورقة بالبكسل والخادمُ يبنيها بالمليمتر. ولو افترق
     * الجدولان لرأى التاجر ورقةً في المعاينة وخرجت من الطابعة غيرُها —
     * وهو العطبُ الذي وُلد منه `Document\PaperSize` أصلًا. فيُقرأ الملفّان
     * ويُقارَن ما فيهما.
     */
    it('جدولُ المقاسات نسخةٌ مطابقة لسجلّ الخادم', () => {
        const php = readFileSync('app/Support/Document/PaperSize.php', 'utf8');
        const ts = frame();

        for (const [key, w, h] of [
            ['A4', 210, 297],
            ['A5', 148, 210],
            ['80mm', 80, null],
            ['58mm', 58, null],
        ] as const) {
            expect(ts).toContain(`{ width: ${w}, height: ${h === null ? 'null' : h} }`);
            expect(php).toContain(`'width' => ${w}.0`);

            if (h !== null) {
                expect(php).toContain(`'height' => ${h}.0`);
            }

            expect(php).toContain(`= '${key}'`);
        }
    });

    /** ولا تُكبَّر فوق حجمها: شريطُ ٥٨ مم ممدودًا يخرج بمقاسٍ لا يُطبع */
    it('لا تُكبَّر الورقة فوق حجمها الطبيعيّ', () => {
        expect(frame()).toContain('Math.min(1, avail / paperWidth)');
    });
});
