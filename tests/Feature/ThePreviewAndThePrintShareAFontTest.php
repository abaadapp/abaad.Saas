<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\PaperSize;
use App\Support\Document\Pdf\MpdfDriver;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الورقةُ على الشاشة وعلى الورق بخطٍّ واحد — لا بخطّين متقاربين.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * القالبُ كان يقول `font-family: xbriyaz, 'IBM Plex Sans Arabic', sans-serif`،
 * وmpdf يُثبّت `xbriyaz` — خطٌّ عربيٌّ يأتي مع المكتبة. والمتصفّحُ لا يجده
 * — ليس خطَّ وِب — فيسقط إلى `sans-serif`، أي إلى خطّ نظام التشغيل: SF
 * Arabic على ماك، وSegoe UI على ويندوز، وما اتّفق على لينكس.
 *
 * فعولج نصفُ العطب أوّلًا: حُمِّلت مقاطعُ «IBM Plex Sans Arabic» للمتصفّح،
 * فصارت المعاينةُ واحدةً على كلّ جهاز — **ولا تزال غيرَ المطبوع**. خطّان
 * مختلفان: مقاساتُ حروفهما تختلف، فينكسر السطرُ الطويل عند كلمةٍ على الشاشة
 * وعند أخرى على الورق، ويرى التاجر ورقةً ويطبع غيرَها.
 *
 * ═══ وعولج النصفُ الثاني ببناء الخطّ للمحرّك من مقاطع المتصفّح ═══
 *
 * `scripts/build-document-font.py` يدمج `public/fonts/ibmpsa-*.woff2` في
 * TTF لكلّ وزن، وmpdf يقرؤهما من `resources/fonts`. فالحروفُ **ذاتُها
 * بالمقاسات ذاتها** في المحرّكين.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 *  ١. أنّ الورقةَ تُعلن الخطَّ للمتصفّح وتشير إلى ملفّاتٍ **قائمة**.
 *  ٢. أنّ الإعلانَ داخل `@media screen` — فmpdf يقرأ وسيطَ `mpdf` وحده، ولو
 *     خرج الإعلانُ لحاول المحرّكُ جلبَ ملفٍّ عند كلّ طباعة.
 *  ٣. أنّ ملفَّي المحرّك قائمان ويحملان جداولَ وصلِ الحروف العربية.
 *  ٤. أنّ الـPDF يُضمِّن «IBM Plex» فعلًا — لا اسمًا في إعدادٍ لا يصل.
 *  ٥. أنّ سجلَّ خطوط المكتبة لم يُمحَ بإضافة خطِّنا.
 */
class ThePreviewAndThePrintShareAFontTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);
        $this->actingAs($user);

        $this->order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'number' => 'INV-000001', 'status' => 'مكتمل', 'customer_name' => 'شركة الواحة',
            'employee_name' => 'سالم', 'payment_method' => 'نقدي',
            'subtotal' => 12.5, 'tax' => 0.625, 'total' => 13.125, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $this->order->id, 'name' => 'باقة ورد', 'price' => 12.5, 'quantity' => 1, 'total' => 12.5,
        ]);
        $this->order->load('items');
    }

    private function sheet(): string
    {
        $v = DocumentTemplates::settings((int) $this->business->id, 'sale');
        $v['paper'] = PaperSize::A4;

        return DocumentRenderer::saleSheet((int) $this->business->id, $this->order, $v);
    }

    /** الورقةُ تحمل إعلانَ الخطّ، وكلُّ ملفٍّ تشير إليه موجودٌ فعلًا */
    public function test_the_paper_declares_a_font_whose_files_exist(): void
    {
        $html = $this->sheet();

        $this->assertStringContainsString('@font-face', $html, 'الورقةُ لا تُعلن خطًّا للمتصفّح');
        $this->assertStringContainsString("font-family: 'IBM Plex Sans Arabic'", $html);

        preg_match_all("#url\('(/fonts/[^']+\.woff2)'\)#", $html, $m);
        $this->assertNotEmpty($m[1], 'إعلانُ الخطّ بلا ملفّ');

        foreach (array_unique($m[1]) as $path) {
            $this->assertFileExists(public_path(ltrim($path, '/')), "ملفُّ الخطّ مفقود: {$path}");
        }
    }

    /**
     * والإعلانُ داخل `@media screen` — لا يبلغ المحرّك.
     *
     * mpdf يقرأ `CSSselectMedia => 'mpdf'` فيتجاهل `screen` و`print` معًا.
     * ولو خرج الإعلانُ من الكتلة لحاول جلبَ ملفٍّ عند كلّ ورقةٍ تُطبع.
     */
    public function test_the_declaration_lives_only_in_the_screen_block(): void
    {
        $html = $this->sheet();

        $screen = strpos($html, '@media screen');
        $face = strpos($html, '@font-face');

        $this->assertNotFalse($screen);
        $this->assertNotFalse($face);
        $this->assertGreaterThan($screen, $face, 'إعلانُ الخطّ خارج كتلة الشاشة');

        // ولا إعلانَ ثانٍ قبل الكتلة
        $this->assertFalse(
            strpos(substr($html, 0, $screen), '@font-face'),
            'إعلانُ خطٍّ يسبق كتلة الشاشة — يبلغ المحرّك',
        );
    }

    /**
     * وملفّا المحرّك قائمان — وفيهما ما تتّصل به الحروف.
     *
     * إعدادٌ يشير إلى ملفٍّ محذوف يرفع استثناءً عند أوّل طباعة، لا عند
     * النشر. وملفٌّ بلا `GSUB` يخرج الكلمةَ العربيّة حروفًا منفصلة: «س ل ا م»
     * بدل «سلام» — وهو عطبٌ يُرى بالعين ولا يُمسك بأيّ فحصٍ نصّيّ.
     */
    public function test_the_engine_font_files_exist_and_can_join_arabic(): void
    {
        foreach (['IBMPlexSansArabic-Regular.ttf', 'IBMPlexSansArabic-Bold.ttf'] as $file) {
            $path = resource_path('fonts/'.$file);

            $this->assertFileExists($path, "ملفُّ خطّ المحرّك مفقود: {$file} — شغّل scripts/build-document-font.py");

            $blob = file_get_contents($path);

            $this->assertStringContainsString('GSUB', $blob, "الخطّ {$file} بلا جداول وصلٍ للحروف");
            $this->assertGreaterThan(50_000, strlen($blob), "الخطّ {$file} أصغرُ من أن يحمل العربيّة");
        }
    }

    /**
     * والـPDF يُضمِّن «IBM Plex» فعلًا — لا اسمًا في إعدادٍ لا يصل.
     *
     * ═══ ولمَ يُقاس الملفُّ نفسُه ═══
     *
     * `default_font` اسمٌ يُكتب، و«يعمل» لا تعني «وصل»: خطٌّ لا تجده المكتبةُ
     * تسقط إلى بديلٍ تختاره بنفسها بلا كلمة. والحقيقةُ الوحيدةُ التي لا
     * تكذب هي ما بين دفّتي الملفّ المطبوع.
     */
    public function test_the_printed_file_embeds_the_font_it_names(): void
    {
        $pdf = Pdf::a4($this->sheet(), 'probe')->getContent();

        preg_match_all('#/BaseFont\s*/([A-Za-z0-9+\-_]+)#', $pdf, $m);
        $fonts = implode(' ', array_unique($m[1]));

        $this->assertStringContainsString('IBMPlexSansArabic', $fonts, "الورقةُ خرجت بخطٍّ آخر: {$fonts}");
        $this->assertStringNotContainsString('XBRiyaz', $fonts, 'خطُّ المكتبة القديم لا يزال يُطبع');
    }

    /**
     * وإضافةُ خطِّنا لا تمحو سجلَّ خطوط المكتبة.
     *
     * ═══ وهذا عطبٌ وقع فعلًا وأنا أقيس ═══
     *
     * `Mpdf::initFontConfig` تكتب `$config + $defaults`، والجمعُ في PHP يُبقي
     * مفتاحَ اليسار كاملًا. فمصفوفةُ `fontdata` المُمرَّرة كانت تمحو السجلَّ
     * كلَّه: طلبتُ `xbriyaz` صراحةً فخرج الـPDF بخطِّنا — لأنّه الوحيدُ
     * الباقي. يعمل، لكن بالصدفة: أيُّ ورقةٍ تسمّي خطًّا آخر تسقط إليه صامتةً.
     */
    public function test_adding_our_font_does_not_wipe_the_library_registry(): void
    {
        $registry = self::registry();

        $this->assertArrayHasKey(MpdfDriver::FONT, $registry, 'خطُّنا ليس في السجلّ');
        $this->assertArrayHasKey('xbriyaz', $registry, 'إضافةُ خطِّنا محت سجلَّ المكتبة');
        $this->assertArrayHasKey('dejavusans', $registry, 'إضافةُ خطِّنا محت سجلَّ المكتبة');
    }

    /** سجلُّ الخطوط كما يبنيه السائق — من بابه هو لا بنسخةٍ هنا */
    private static function registry(): array
    {
        $fonts = new \ReflectionMethod(MpdfDriver::class, 'fonts');
        $fonts->setAccessible(true);

        return $fonts->invoke(null)['fontdata'];
    }

    /** والشريطُ الحراريُّ مثلُها — معاينتُه متصفّحٌ أيضًا */
    public function test_the_thermal_strip_carries_it_too(): void
    {
        $v = DocumentTemplates::settings((int) $this->business->id, 'sale');
        $html = DocumentRenderer::saleStrip((int) $this->business->id, $this->order, $v, 80);

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString('/fonts/ibmpsa-', $html);
    }
}
