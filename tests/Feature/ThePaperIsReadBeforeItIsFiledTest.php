<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\PaperSize;
use App\Support\Document\Theme;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\Paper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * الورقةُ تُقرأ قبل أن تُحفظ — وما يحرسه هذا الملفّ هو قابليّتُها للقراءة.
 *
 * وهي الأشياءُ التي لا يكشفها فتحُ فاتورةٍ واحدة بالعين: من يجرّب بصنفين
 * عربيّين لا يرى ما يقع لاسمٍ إنجليزيّ في ورقةٍ عربيّة، ومن يطبع فاتورةً
 * من صفحةٍ لا يرى الصفحةَ الثانية حين تسقط من الحزمة.
 */
class ThePaperIsReadBeforeItIsFiledTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
            'address' => 'شارع السلطان قابوس، الخوير', 'phone' => '+968 9123 4567', 'email' => 'sales@zohoor.om',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع الخوير']);

        $u = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($u);
    }

    private function order(array $names = ['باقة ورد']): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'branch' => 'فرع الخوير', 'number' => 'INV-2026-000412', 'status' => 'مكتمل',
            'customer_name' => 'شركة الواحة', 'employee_name' => 'سالم', 'payment_method' => 'بطاقة',
            'subtotal' => 12 * count($names), 'tax' => 0, 'total' => 12 * count($names),
            'ordered_at' => now(),
        ]);

        foreach ($names as $n) {
            OrderItem::create(['order_id' => $order->id, 'name' => $n, 'price' => 12, 'quantity' => 1, 'total' => 12]);
        }

        return $order->load('items');
    }

    private function sheet(?Order $order = null, array $over = []): string
    {
        $v = DocumentTemplates::settings($this->business->id, 'sale');

        /* وA4 صراحةً: افتراضيُّ «sale» شريطٌ حراريّ، ويُوجَّه في المتحكّم لا هنا */
        return DocumentRenderer::saleSheet(
            $this->business->id,
            $order ?? $this->order(),
            $over + ['paper' => PaperSize::A4] + $v,
        );
    }

    /* ═══════════ الطرفان متقابلان ═══════════ */

    /**
     * الورقةُ تحمل البائعَ والمشتري معًا — لا المشتريَ وحده.
     *
     * وكانت بياناتُ البائع خمسةَ أسطرٍ رماديّةٍ في ركن الترويسة، ومقابلَها
     * نصفُ سطرٍ خالٍ تحت «فاتورة إلى». وجهةٌ تراجع الورقةَ تبحث عن عنوان
     * البائع ورقمه الضريبيّ حيث تجدهما في كلّ فاتورة: كتلةً معنونة.
     */
    public function test_the_paper_names_both_sides(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);

        $html = $this->sheet(null, ['show_vat_no' => true]);

        $this->assertStringContainsString(__('البائع'), $html, 'الورقةُ لا تعرّف بائعَها');
        $this->assertStringContainsString(__('فاتورة إلى'), $html, 'الورقةُ لا تعرّف مشتريَها');
        $this->assertStringContainsString('شارع السلطان قابوس', $html);
        $this->assertStringContainsString('OM1100234567', $html);

        /* والاسمُ لا يتكرّر: الترويسةُ قالته مرّةً */
        $this->assertSame(1, substr_count($html, 'زهور الخليج'), 'اسمُ المتجر مكرَّرٌ على الورقة');
    }

    /* ═══════════ نصُّ البشر يُقرأ كما كُتب ═══════════ */

    /**
     * نصٌّ بلغةٍ غير لغة الورقة يحمل اتّجاهَه هو.
     *
     * اسمُ صنفٍ إنجليزيٍّ في ورقةٍ عربيّة — أو ملاحظةٌ عربيّةٌ في ورقةٍ
     * إنجليزيّة — تنقلب علاماتُ ترقيمه فتخرج النقطةُ إلى أوّل السطر.
     * و`dir="auto"` لا يحلّها: mpdf لا يعرفه.
     */
    public function test_text_carries_its_own_direction(): void
    {
        $this->assertSame('rtl', Paper::dirOf('يرجى التسليم قبل الظهر.'));
        $this->assertSame('ltr', Paper::dirOf('Premium Gift Box — صندوق'));
        $this->assertSame('ltr', Paper::dirOf('INV-2026-000412'));

        /* ورقمٌ عربيٌّ وحده محايد: لا يقلب سطرًا ليس فيه حرف */
        $this->assertSame('rtl', Paper::dirOf('٢٠٢٦'), 'في ورقةٍ عربيّة يتبع الورقة');

        $html = $this->sheet($this->order(['Premium Gift Box', 'باقة ورد']));

        $this->assertStringContainsString('dir="ltr"', $html, 'الاسمُ الإنجليزيُّ لا يحمل اتّجاهَه');
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    /**
     * والاتّجاهُ لا يجرّ المحاذاةَ معه.
     *
     * `dir="rtl"` على خليّةٍ يصحّح ترتيبَ حروفها **ويزيحها إلى يمينها**،
     * فيخرج عمودُ الأصناف مبعثرًا: عربيُّه في طرفٍ وإنجليزيُّه في آخر.
     */
    public function test_direction_does_not_drag_alignment_with_it(): void
    {
        $html = $this->sheet($this->order(['Premium Gift Box']));

        $this->assertMatchesRegularExpression(
            '/<td class="bidi" dir="ltr">/',
            $html,
            'خليّةُ الاسم لا تفصل المحاذاةَ عن الاتّجاه',
        );
        $this->assertStringContainsString('.bidi { text-align: right;', $html, 'المحاذاةُ لا تتبع الورقة');
    }

    /* ═══════════ ولا فراغَ ميّت ولا قيمةَ خاوية ═══════════ */

    /**
     * حقلٌ بلا قيمةٍ لا يُطبع عنوانًا فارغًا.
     *
     * «الفرع: —» و«الموظف: —» سطران يشغلان مكانًا ولا يقولان شيئًا، ويجعلان
     * من يقرأ يظنّ أنّ بياناتٍ سقطت.
     */
    public function test_an_empty_field_leaves_no_hole(): void
    {
        $bare = Business::create(['name' => 'مخبز الحيّ', 'type' => '', 'city' => '', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $bare->id, 'name' => '']);

        $order = Order::create([
            'business_id' => $bare->id, 'branch_id' => $branch->id, 'branch' => '',
            'number' => 'INV-000007', 'status' => 'مكتمل', 'customer_name' => '',
            'employee_name' => '', 'payment_method' => 'نقدي',
            'subtotal' => 0.9, 'tax' => 0, 'total' => 0.9, 'ordered_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'name' => 'خبز', 'price' => 0.3, 'quantity' => 3, 'total' => 0.9]);

        $html = DocumentRenderer::saleSheet($bare->id, $order->load('items'), DocumentTemplates::settings($bare->id, 'sale'));

        $this->assertStringNotContainsString('>—<', $html, 'الورقةُ تطبع شرطةً مكانَ قيمةٍ غائبة');
        /* و«الفرع» تقع داخل «المجموع الفرعي» — فيُفحص العنوانُ كاملًا لا الحرف */
        $this->assertStringNotContainsString('>'.__('الفرع').'<', $html, 'عنوانُ الفرع يُطبع بلا فرع');
        $this->assertStringNotContainsString('>'.__('الموظف').'<', $html, 'عنوانُ الموظف يُطبع بلا موظف');
        $this->assertStringContainsString('مخبز الحيّ', $html, 'الورقةُ الأدنى لا تحمل اسمَ متجرها');
    }

    /**
     * وشريطُ التعريف أعمدةٌ لا قائمةٌ بنصف سطرٍ خالٍ.
     *
     * وأربعةٌ في السطر لا أكثر: ستّةٌ تجعل كلَّ عمودٍ بعرض ثلاثة سنتيمترات
     * فينكسر «وسيلة الدفع» سطرين ويلتصق بجاره.
     */
    public function test_the_meta_strip_is_columns_not_a_list(): void
    {
        $html = $this->sheet();

        $this->assertStringContainsString('table.metastrip', $html);
        $this->assertStringNotContainsString('class="meta"', $html, 'بقيت قائمةُ العمودين القديمة');

        preg_match_all('/<td style="width:([0-9.]+)%">\s*<div class="eyebrow"/', $html, $m);

        $this->assertNotEmpty($m[1], 'الشريطُ لا يعلن عرضَ خلاياه');

        foreach ($m[1] as $w) {
            $this->assertGreaterThanOrEqual(25.0, (float) $w, 'عمودٌ أضيقُ من ربع السطر — أكثرُ من أربعةٍ في السطر');
        }
    }

    /* ═══════════ والصفحةُ الثانية تُعرَف إن سقطت ═══════════ */

    /**
     * كلُّ صفحةٍ تحمل ما يعرّفها — رقمَ الورقة واسمَ متجرها.
     *
     * وصفحةٌ تحمل «٢ / ٣» وحدها لا تقول لأيّ حزمةٍ تعود، وهو ما يقع في
     * مكاتب المحاسبة حين تُفكّ الحزمةُ وتُصوَّر.
     */
    public function test_every_page_says_which_document_it_belongs_to(): void
    {
        /*
         * وورقةُ البيع الافتراضيّة شريطٌ حراريّ — والشريطُ لا صفحاتِ له.
         *
         * فيُضبط المتجر على A4 كما يفعل تاجرٌ يطبع فواتيرَ لا إيصالات.
         * ومفتاحُها مسطَّحٌ بلا اسم النوع لأنّها سبقت سجلَّ القوالب.
         */
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);

        $names = [];

        for ($i = 1; $i <= 60; $i++) {
            $names[] = 'صنف رقم '.$i;
        }

        $pdf = $this->get(route('admin.orders.pdf', $this->order($names)->number));
        $pdf->assertOk();

        $raw = $pdf->getContent();

        $this->assertStringStartsWith('%PDF', $raw, 'الناتجُ ليس ملفَّ PDF');

        $pages = substr_count($raw, '/Type /Page') - substr_count($raw, '/Type /Pages');
        $this->assertGreaterThan(1, $pages, 'الفاتورةُ لم تتجاوز صفحةً واحدة، فلا يُختبر ما يقع عند الثانية');

        /*
         * والسياقُ في التذييل: رقمُ الورقة واسمُ متجرها على كلّ صفحة.
         *
         * ويُفحص في مجرى المحتوى مضغوطًا — نصُّ الـPDF لا يُقرأ حرفًا حرفًا،
         * لكنّ المحرّك يُدرج التذييلَ مرّةً لكلّ صفحة. فيكفي أن يُبنى
         * الاستدعاءُ صحيحًا، وهو ما يحرسه `MpdfDriver`.
         */
        $this->assertSame(
            'INV-2026-000412 · زهور الخليج',
            (new ReflectionMethod(PdfController::class, 'context'))
                ->invoke(null, 'INV-2026-000412', $this->business->id),
            'سياقُ التذييل لا يحمل رقمَ الورقة واسمَ متجرها',
        );
    }

    /* ═══════════ والرموزُ في موضعٍ واحد ═══════════ */

    /** إيقاعُ الأقسام من رمزٍ واحد — لا رقمٍ مكتوبٍ في عشر قواعد */
    public function test_the_rhythm_of_the_page_has_one_home(): void
    {
        $this->assertArrayHasKey('section_gap', Theme::GEOMETRY);

        $html = $this->sheet();
        $gap = Theme::GEOMETRY['section_gap'];

        $this->assertStringContainsString('margin-bottom: '.round($gap, 2).'pt', $html);

        /* ولا يُخفَّض الخطُّ الأساس «لأنّ الأقلَّ أنيق»: عشرُ نقاطٍ أدنى ما يُقرأ مطبوعًا */
        $this->assertGreaterThanOrEqual(10.0, Theme::GEOMETRY['text_base']);
    }
}
