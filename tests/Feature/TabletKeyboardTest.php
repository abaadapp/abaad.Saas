<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * النظام على الآيباد — وما تفعله لوحةُ المفاتيح به.
 *
 * العطبُ الذي جاء هذا الحارس ليمنع عودتَه أنّ لوحة المفاتيح على الآيباد **لا
 * تُقلّص صفحة الويب**: `100dvh` تبقى كما هي، فتحسب الواجهة أنّها تملك الشاشة
 * كلّها بينما نصفُها السفليّ مغطّى. وفي ذلك النصف يقع زرُّ الدفع، وحقلُ
 * الكوبون، وزرُّ «تأكيد الدفع». وشاشةُ البيع `overflow-hidden`، فلا يستطيع
 * الكاشير حتى أن يمرّرها ليصل إليها — يفتح الحقل فيختفي الزرّ، ولا مخرج إلّا
 * إغلاق اللوحة.
 *
 * ثمّ خرج التصحيح من الصندوق إلى اللوحة: أكثرُ ما يُكتب هناك يُكتب في نافذةٍ
 * منبثقة موسّطةٍ رأسيًّا، فزرُّ الحفظ تحت اللوحة كزرّ الدفع سواء.
 *
 * وهذه الحالات تفحص الشيفرة المصدرية لا الشاشة: لا متصفّحَ في الاختبارات
 * يفتح لوحةَ مفاتيح. فتُثبَّت القواعدُ التي تُبنى عليها المعالجة — وأيُّ
 * رجوعٍ عنها يسقط هنا قبل أن يصل إلى صندوقٍ في محلّ.
 */
class TabletKeyboardTest extends TestCase
{
    private function read(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    /* ------------------------ لوحة المفاتيح ------------------------ */

    /** المقاس يُقرأ من `visualViewport` — وهو الشيء الوحيد الذي يعرف باللوحة */
    public function test_the_keyboard_height_is_measured_and_published(): void
    {
        $source = $this->read('resources/js/lib/keyboard.ts');

        $this->assertStringContainsString('visualViewport', $source);
        $this->assertStringContainsString("setProperty('--kb'", $source);
    }

    /**
     * وشاشةُ البيع تُقلَّص بمقداره.
     *
     * `h-dvh` وحدها كانت تترك زرَّ الدفع تحت اللوحة بلا طريقٍ إليه.
     */
    public function test_the_selling_screen_shrinks_by_the_keyboard(): void
    {
        $layout = $this->read('resources/js/Layouts/PosLayout.tsx');

        $this->assertStringContainsString('useOnScreenKeyboard', $layout);
        $this->assertStringContainsString('h-[calc(100dvh-var(--kb,0px))]', $layout);
        $this->assertStringNotContainsString("'h-dvh overflow-hidden'", $layout, 'الشاشة عادت تُبنى على الارتفاع الكامل');
    }

    /**
     * والنافذة المنبثقة كذلك: تُرفع بنصف اللوحة ويُقصّ سقفُها بمقدارها.
     *
     * نافذةُ الدفع موسّطةٌ رأسيًّا، فبلا هذا يبقى نصفُها السفليّ — وفيه زرُّ
     * التأكيد — تحت اللوحة لحظةَ يكتب الكاشير المبلغ المدفوع.
     */
    public function test_dialogs_are_centred_on_what_is_visible(): void
    {
        $dialog = $this->read('resources/js/Components/ui/dialog.tsx');

        $this->assertStringContainsString('top-[calc(50%-var(--kb,0px)/2)]', $dialog);
        $this->assertStringContainsString('max-h-[calc(100dvh-var(--kb,0px)-1.5rem)]', $dialog);
    }

    /**
     * ولا نافذةَ تكتب سقفها بنفسها.
     *
     * `max-h-[90dvh]` مكتوبةً في نافذةٍ تتجاوز القاعدةَ المشتركة: تُقاس على
     * الشاشة كلّها فتعود إلى ما تحت اللوحة، ولا يظهر ذلك إلّا على جهازٍ لوحيّ.
     */
    public function test_no_dialog_writes_its_own_ceiling(): void
    {
        $guilty = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'tsx') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! str_contains($source, '<DialogContent')) {
                continue;
            }

            if (preg_match('/<DialogContent[^>]*max-h-\[(?!calc\(100dvh-var)/s', $source)) {
                $guilty[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $guilty, 'نافذةٌ تقيس سقفها على الشاشة لا على المرئيّ');
    }

    /* ------------------------ لمسٌ بلا تكبير ------------------------ */

    /**
     * سفاري يُكبّر الصفحة عند التركيز على حقلٍ خطُّه أصغر من ١٦ بكسل — ولا
     * يُرجعها. وحقولُ النظام `text-sm` أي ١٤، فكانت كلُّ ضغطةٍ على حقل بحثٍ
     * تُكبّر الشاشة وتترك الكاشير يجرّها بإصبعه ليجد زرَّ الدفع.
     */
    public function test_a_touch_keyboard_never_zooms_the_page_in(): void
    {
        $css = $this->read('resources/css/app.css');

        $this->assertMatchesRegularExpression(
            '/@media \(pointer: coarse\)\s*\{[^@]*font-size:\s*16px/s',
            $css,
            'حقلٌ أصغر من ١٦ بكسل على اللمس — سفاري يُكبّر الصفحة ولا يُرجعها',
        );
    }

    /**
     * ولوحةُ النشاط تقرأ اللوحة كما تقرؤها نقطة البيع.
     *
     * أكثرُ ما يُكتب في اللوحة يُكتب في نافذةٍ منبثقة — منتجٌ وقيدٌ وموظّف —
     * وهي موسّطةٌ رأسيًّا. وتصحيحُ النوافذ لا يعمل بلا من يقيس اللوحة: بلا
     * `--kb` تُقرأ صفرًا فتبقى النافذةُ حيث كانت، وزرُّ الحفظ تحتها.
     */
    public function test_the_admin_panel_measures_the_keyboard_too(): void
    {
        $layout = $this->read('resources/js/Layouts/AdminLayout.tsx');

        $this->assertStringContainsString('useOnScreenKeyboard', $layout);
    }

    /** ورسالةُ «تم الحفظ» تقع في القاع نفسه، فتُرفع فوق اللوحة */
    public function test_the_toast_is_lifted_above_the_keyboard(): void
    {
        foreach (['resources/js/Layouts/AdminLayout.tsx', 'resources/js/Layouts/PosLayout.tsx'] as $path) {
            $this->assertStringContainsString(
                "bottom: 'calc(24px + var(--kb, 0px))'",
                $this->read($path),
                basename($path).': الرسالة تُدفن تحت لوحة المفاتيح',
            );
        }
    }

    /**
     * ولا `100vh` في النظام كلّه.
     *
     * على الآيباد تقيس شاشةً بشريط سفاري مطويًّا، فيتغيّر الارتفاع عند أوّل
     * تمرير وتقفز الواجهة تحت الإصبع — وأوّلُ ما يقع ذلك عليه شاشةُ الدخول.
     */
    public function test_nothing_is_built_on_the_old_viewport_unit(): void
    {
        $guilty = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (str_contains($source, 'min-h-screen') || str_contains($source, '100vh')) {
                $guilty[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $guilty, 'شاشةٌ مبنيّةٌ على vh — تقفز على الآيباد');
    }

    /** والحوافّ الآمنة لا تُقرأ بلا `viewport-fit=cover` */
    public function test_the_page_declares_the_full_viewport(): void
    {
        $this->assertStringContainsString(
            'viewport-fit=cover',
            $this->read('resources/views/app.blade.php'),
        );
    }

    /**
     * والآيباد رأسيًّا عمودان لا عمود.
     *
     * عرضُه ٨٢٠ بكسل، فكان يقع تحت حدّ `lg` فتصير السلّة تحت المنتجات في
     * شاشةٍ لا تُمرَّر — فلا يراها الكاشير ولا زرَّ دفعه إلّا إذا أدار الجهاز.
     */
    public function test_the_cart_stands_beside_the_products_on_a_portrait_tablet(): void
    {
        $screen = $this->read('resources/js/Pages/Pos/Index.tsx');

        $this->assertStringContainsString('md:flex-row', $screen);
        $this->assertStringNotContainsString('lg:flex-row', $screen, 'الحدّ عاد إلى ١٠٢٤ فيسقط الآيباد الرأسيّ');
    }
}
