<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\PaperSize;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الورقةُ على الشاشة تُرسم بخطٍّ معروف — لا بخطّ الجهاز الذي يفتحها.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * القالبُ يقول `font-family: xbriyaz, 'IBM Plex Sans Arabic', sans-serif`.
 * وmpdf يجد الأوّل مضمّنًا في المكتبة فيُثبّته في الـPDF. والمتصفّحُ لا يجده
 * — ليس خطَّ وِب — ولا يجد الثاني: لا سطرَ في النظام كان يُحمّله. فيسقط إلى
 * `sans-serif`، أي إلى خطّ نظام التشغيل: SF Arabic على ماك، وSegoe UI على
 * ويندوز، وما اتّفق على لينكس.
 *
 * فالمعاينةُ لا تطابق المطبوع، **ولا تطابق نفسَها** بين تاجرٍ وتاجر. ومن
 * يضبط عرضَ عمودٍ على جهازه يضبطه على خطٍّ لا يراه غيرُه ولا تطبعه الطابعة.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 *  ١. أنّ الورقةَ تُعلن الخطَّ وتشير إلى ملفّاتٍ **قائمة** — إعلانٌ إلى ملفٍّ
 *     محذوف يسقط صامتًا إلى خطّ النظام، وهو العطبُ نفسُه بلا أثر.
 *  ٢. أنّ الإعلانَ داخل `@media screen` — فmpdf يقرأ وسيطَ `mpdf` وحده، ولو
 *     خرج الإعلانُ من الكتلة لحاول المحرّكُ جلبَ ملفٍّ عند كلّ طباعة.
 *  ٣. أنّ خطَّ الـPDF لم يتبدّل: `xbriyaz` يبقى أوّلَ الأسرة.
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

    /** وخطُّ الـPDF لم يتبدّل: `xbriyaz` يبقى أوّلَ الأسرة */
    public function test_the_engine_font_is_unchanged(): void
    {
        $this->assertStringContainsString(
            "font-family: xbriyaz, 'IBM Plex Sans Arabic', sans-serif",
            $this->sheet(),
        );
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
