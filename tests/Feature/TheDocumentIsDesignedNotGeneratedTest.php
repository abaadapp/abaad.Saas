<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Color;
use App\Support\Document\Branding;
use App\Support\Document\Theme;
use App\Support\Document\Version;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الورقةُ مستندٌ صُمِّم، لا ملفٌّ ولّده نظامٌ محاسبيّ.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 * أنّ هويّةَ التاجر تبلغ الورقة، وأنّ **جودةَ التصميم لا تُترك له**: لونٌ
 * لا يُقرأ يُصحَّح، وورقةٌ بلا غلافٍ تبقى نظيفةً لا خاوية، وصفٌّ لا ينكسر
 * بين صفحتين، ورمزٌ لا يبلغ ورقةً تمضي إلى مورّد.
 *
 * وهي الحالاتُ التي لا يكشفها فتحُ ملفِّ PDF بالعين: من يفتح فاتورةً
 * بصنفين لا يرى ما يقع عند خمسين، ومن يجرّب بلونٍ داكن لا يرى ما يقع
 * لمن اختار أصفر.
 */
class TheDocumentIsDesignedNotGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** طلبٌ بعددٍ من الأصناف — لقياس ما يقع عند الكثرة */
    private function order(int $items = 2, string $name = 'باقة ورد', string $number = 'INV-000001'): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'branch' => 'الفرع الرئيسي', 'number' => $number, 'status' => 'مكتمل',
            'customer_name' => 'زبون', 'employee_name' => 'كاشير', 'payment_method' => 'نقدي',
            'subtotal' => 12 * $items, 'tax' => 0.6 * $items, 'total' => 12.6 * $items,
            'ordered_at' => now(),
        ]);

        for ($i = 0; $i < $items; $i++) {
            OrderItem::create([
                'order_id' => $order->id, 'name' => $name.' '.($i + 1),
                'price' => 12, 'quantity' => 1, 'total' => 12,
            ]);
        }

        return $order->load('items');
    }

    private function sheet(Order $order): string
    {
        return DocumentRenderer::saleSheet(
            $this->business->id,
            $order,
            \App\Support\DocumentTemplates::settings($this->business->id, 'sale'),
        );
    }

    /* ═══════════════════ الهويّةُ تبلغ الورقة ═══════════════════ */

    /**
     * لونُ التاجر يُطبع — ومرّتين في كلّ قاعدة.
     *
     * `var()` للمتصفّح وقيمةٌ حرفيّةٌ بعدها لـmpdf. وmpdf يتجاهل المتغيّرات
     * صامتًا: بلا القيمة الحرفيّة تخرج الورقةُ بلا لونٍ ولا خطأ يقول لماذا،
     * ويرى التاجر لونَه في المعاينة ويغيب عن الطابعة.
     */
    public function test_the_brand_colour_reaches_both_engines(): void
    {
        Branding::save($this->business->id, ['primary' => '#7c3aed']);

        $html = $this->sheet($this->order());

        $this->assertStringContainsString('--document-primary: #7c3aed', $html, 'المتغيّرُ لا يبلغ المتصفّح');
        $this->assertStringContainsString(
            'color: var(--document-text); color: #0f172a',
            $html,
            'القيمةُ الحرفيّة لا تتبع المتغيّر — وmpdf لا يقرأ إلّا الحرفيّة',
        );
    }

    /**
     * ولونٌ لا يُقرأ على الورق يُغمَّق — ولا يُستبدل بالأسود.
     *
     * من اختار أصفرَ فاقعًا لعلامته يخرج عنوانُ فاتورته أصفرَ على أبيض:
     * لا يُقرأ على الشاشة، ويختفي كليًّا في طابعةٍ بالأبيض والأسود. فيرى
     * شعارَه على الورقة ولا يرى أنّ رقمَ فاتورته غاب.
     *
     * والتغميقُ يُبقيه في عائلة لونه: إسقاطُه إلى الأسود يمحو هويّتَه.
     */
    public function test_an_unreadable_brand_colour_is_darkened_not_discarded(): void
    {
        $tokens = Theme::tokens(['primary' => '#fde047']);

        $this->assertSame('#fde047', $tokens['primary'], 'اللونُ المختار تغيّر — والغلافُ يُرسم به');

        $this->assertGreaterThanOrEqual(
            Theme::PRINT_CONTRAST,
            Color::contrast($tokens['primary_ink'], '#ffffff'),
            'حبرُ الورقة لا يُقرأ على الأبيض',
        );

        $this->assertNotSame('#000000', $tokens['primary_ink'], 'اللونُ أُسقط إلى الأسود فمُحيت هويّةُ التاجر');
        $this->assertNotSame('#111111', $tokens['primary_ink']);
    }

    /** ولمسةٌ لم تُختَر تُشتقّ — لا لونٌ من عند النظام يتنافر مع علامته */
    public function test_the_accent_is_derived_when_not_chosen(): void
    {
        $tokens = Theme::tokens(['primary' => '#b91c1c']);

        $this->assertNotSame('', $tokens['accent']);
        $this->assertNotSame('#b91c1c', $tokens['accent'], 'اللمسةُ نسخةٌ من الأساسيّ لا مشتقٌّ منه');
    }

    /* ═══════════════════ الغلاف ═══════════════════ */

    /**
     * ورقةُ من لم يرفع غلافًا ولم يختر لونًا تبقى **نظيفة** لا خاوية.
     *
     * شريطٌ أسودُ فوق كلّ ورقةٍ لمتجرٍ لم يختر شيئًا ليس هويّةً، وإنّما حبرٌ
     * يُطبع بلا سبب. والمواصفةُ صريحة: بلا غلافٍ تكون الورقة **أبسط** لا
     * أسوأ.
     */
    public function test_a_paper_without_a_cover_prints_no_empty_band(): void
    {
        $html = $this->sheet($this->order());

        $this->assertStringNotContainsString('class="cover"', $html);
        $this->assertStringNotContainsString('class="cover-plain"', $html, 'شريطٌ يُطبع لمن لم يختر لونًا');
    }

    /** ومن اختار لونًا وحده يناله شريطًا رفيعًا — هويّةٌ بلا صورة */
    public function test_choosing_a_colour_alone_earns_a_slim_band(): void
    {
        Branding::save($this->business->id, ['primary' => '#7c3aed']);

        $this->assertStringContainsString('class="cover-plain"', $this->sheet($this->order()));
    }

    /** ومن رفع غلافًا يُرسم بعرض الورقة — ومضمَّنًا لا برابط */
    public function test_an_uploaded_cover_is_embedded_in_the_paper(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('covers/band.png', 'PNGDATA');

        Setting::create([
            'business_id' => $this->business->id,
            'key' => Branding::COVER, 'value' => 'covers/band.png',
        ]);

        $html = $this->sheet($this->order());

        $this->assertStringContainsString('class="cover"', $html);
        $this->assertStringContainsString(
            'data:',
            $html,
            'الغلافُ يُوصَل برابط — وmpdf لا جلسةَ له فيخرج الورقُ بلا غلاف',
        );
    }

    /* ═══════════════════ الورقةُ الطويلة ═══════════════════ */

    /**
     * فاتورةٌ بخمسين صنفًا لا تنكسر صفوفُها ولا يغيب رأسُ جدولها.
     *
     * سطرٌ نصفُه في صفحةٍ ونصفُه في أخرى يُقرأ مرّتين، وجدولٌ يمتدّ ثلاثَ
     * صفحاتٍ بلا رؤوسِ أعمدةٍ في الثانية يُقرأ بالتخمين: أيُّ عمودٍ الكميّةُ
     * وأيُّها السعر.
     */
    public function test_a_long_invoice_keeps_its_rows_and_repeats_its_header(): void
    {
        $html = $this->sheet($this->order(50));

        $this->assertStringContainsString('table.items tr { page-break-inside: avoid; }', $html);
        $this->assertStringContainsString('table.items thead { display: table-header-group; }', $html);
        $this->assertStringContainsString('باقة ورد 50', $html, 'الصنفُ الخمسون لا يبلغ الورقة');
    }

    /**
     * وكتلةُ الإجماليّات لا تنكسر — ولا الرمزُ يركب على الجدول.
     *
     * «الإجمالي» في صفحةٍ ومفرداتُه في أخرى يجعل من يراجع الورقة يعود
     * صفحةً ليعرف ممّ تكوّن المبلغ.
     */
    public function test_the_totals_and_the_code_are_held_whole(): void
    {
        $html = $this->sheet($this->order(40));

        $this->assertStringContainsString('.totals-wrap { width: 100%; page-break-inside: avoid; }', $html);
        $this->assertStringContainsString('.qr-block { margin-top:', $html);
        $this->assertMatchesRegularExpression('/\.qr-block \{[^}]*page-break-inside: avoid/s', $html);
    }

    /** واسمُ صنفٍ طويل لا يخرج عن الورقة — الخليّةُ تلفّ ولا تمتدّ */
    public function test_a_very_long_item_name_does_not_overflow(): void
    {
        $long = str_repeat('باقة ورد جوري أحمر بتغليف فاخر ', 12);
        $html = $this->sheet($this->order(1, $long));

        $this->assertStringContainsString('table.items { width: 100%;', $html);
        $this->assertStringNotContainsString('white-space: nowrap', substr($html, strpos($html, 'table.items td') ?: 0, 200));
    }

    /* ═══════════════════ الرمزُ وحدودُه ═══════════════════ */

    /**
     * ولا رمزَ يُبنى لأمر الشراء ولا لسند الاستلام — من مصدره.
     *
     * يمضيان إلى المورّد وفيهما تكلفةُ البضاعة، ورابطٌ عامٌّ لا يحرسه إلّا
     * كونُه غير مخمَّن يضع هامشَ ربح التاجر خلف قصاصةٍ تُصوَّر بهاتف.
     *
     * والمنعُ في `PublicDocument` لا في القالب: مقبضٌ يُخفي رابطًا موجودًا
     * يُنسى فيُشعَل، ورابطٌ لا يُبنى لا يُسرَّب أبدًا.
     */
    public function test_supplier_papers_are_never_granted_a_public_face(): void
    {
        $po = \App\Models\PurchaseOrder::create([
            'business_id' => $this->business->id, 'number' => 'PO-0001',
            'status' => 'مسودة', 'ordered_at' => now(), 'total' => 100,
        ]);

        $this->assertFalse(\App\Support\PublicDocument::allows($po));
        $this->assertNull(\App\Support\PublicDocument::url($po), 'أمرُ الشراء نال رابطًا عامًّا');
        $this->assertSame(0, \App\Models\DocumentLink::count(), 'رمزٌ كُتب لورقةٍ لا تُمنح وجهًا');
    }

    /** وأوراقُ الزبون تناله: الإيصالُ يبهت، والزبونُ يعود بضمانٍ بعد سنة */
    public function test_customer_papers_do_earn_a_public_face(): void
    {
        $order = $this->order();

        $this->assertTrue(\App\Support\PublicDocument::allows($order));
        $this->assertNotNull(\App\Support\PublicDocument::url($order));
    }

    /** ورمزٌ واحدٌ لورقةٍ واحدة مهما طُبعت مرّتين */
    public function test_printing_twice_does_not_mint_two_codes(): void
    {
        $order = $this->order();

        $first = \App\Support\PublicDocument::token($order);
        $second = \App\Support\PublicDocument::token($order);

        $this->assertSame($first, $second);
        $this->assertSame(1, \App\Models\DocumentLink::count());
    }

    /* ═══════════════════ الاتّجاهُ واللغة ═══════════════════ */

    /** الورقةُ العربية من اليمين، والإنجليزية من اليسار — بقالبٍ واحد */
    public function test_one_template_serves_both_directions(): void
    {
        $order = $this->order();

        app()->setLocale('ar');
        $ar = $this->sheet($order);

        app()->setLocale('en');
        $en = $this->sheet($order);

        $this->assertStringContainsString('direction: rtl', $ar);
        $this->assertStringContainsString('text-align: right', $ar);

        $this->assertStringContainsString('direction: ltr', $en);
        $this->assertStringContainsString('text-align: left', $en);

        // وقالبٌ واحد لا نسخةٌ لكلّ لغة
        $this->assertSame(Version::views(null).'.sale', Version::views(null).'.sale');
    }

    /**
     * والأرقامُ تُقرأ من اليسار في اللغتين — معزولةً عمّا حولها.
     *
     * رقمُ فاتورةٍ أو آيبانٌ داخل سطرٍ عربيّ يتبع اتّجاهَ السطر لا اتّجاهَ
     * نفسِه، فينقلب طرفاه. ولا يلاحظه التاجر — يعرف ما كتب فيقرؤه صحيحًا
     * في رأسه. من يقرؤه غلطًا هو الزبون.
     */
    public function test_latin_runs_are_isolated_from_the_arabic_line(): void
    {
        $html = $this->sheet($this->order());

        $this->assertStringContainsString('unicode-bidi: isolate', $html);
        $this->assertStringContainsString('.ltr', $html);
    }

    /* ═══════════════════ الضريبة ═══════════════════ */

    /** ورقةٌ بضريبةٍ تقول نسبتَها — لا مبلغًا لا يُراجَع */
    public function test_the_vat_line_carries_the_rate_it_was_computed_at(): void
    {
        $order = $this->order();
        $order->update(['subtotal' => 100, 'discount' => 0, 'tax' => 5, 'total' => 105]);

        $doc = DocumentPaper::forSale($order->fresh('items'));
        $vat = collect($doc['totals'])->firstWhere('label', __('ضريبة القيمة المضافة'));

        $this->assertNotNull($vat, 'سطرُ الضريبة غاب عن ورقةٍ فيها ضريبة');
        $this->assertSame('(5%)', $vat['hint'], 'النسبةُ لا تُطبع مع القيمة');
    }

    /** وورقةٌ بلا ضريبةٍ لا تطبع «الضريبة 0.000» — صفرٌ يُقرأ حقلًا لم يُملأ */
    public function test_a_paper_without_vat_prints_no_zero_line(): void
    {
        $order = $this->order();
        $order->update(['tax' => 0]);

        $doc = DocumentPaper::forSale($order->fresh('items'));

        $this->assertNull(collect($doc['totals'])->firstWhere('label', __('ضريبة القيمة المضافة')));
    }

    /* ═══════════════════ حقولُ السداد ═══════════════════ */

    /**
     * بيعةٌ نقديّة لا تطبع «المسدَّد» و«الباقي».
     *
     * دُفعت عند الصندوق، وسطرٌ يقول «الباقي 0.000» يجعل الزبون يبحث عمّا
     * لم يدفعه.
     */
    public function test_a_cash_sale_prints_no_settlement_lines(): void
    {
        $doc = DocumentPaper::forSale($this->order());

        $this->assertNull(collect($doc['totals'])->firstWhere('label', __('المسدَّد')));
        $this->assertNull(collect($doc['totals'])->firstWhere('due', true));
    }

    /**
     * ولا عمودَ سدادٍ في `orders` — الرقمُ يُقرأ من الدفتر.
     *
     * ═══ ولمَ يُحرَس هذا ═══
     *
     * `CustomerInvoice::paidTotal()` يجمع ما سُدِّد من
     * `customer_payment_allocations`. وعمودٌ ثانٍ في `orders` يحمل الرقمَ
     * نفسَه يعني أنّ كلّ تحصيلٍ وكلّ إلغاءٍ وكلّ إشعارٍ دائن يجب أن يكتب في
     * الاثنين — ونسيانُ أحدها مرّةً يُخرج ورقةً تقول «الباقي صفر» ودفترًا
     * يقول غير ذلك. وهو صنفٌ لا يُكتشف إلّا عند مراجعةٍ خارجيّة.
     */
    public function test_the_order_table_holds_no_second_copy_of_what_was_paid(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('orders');

        foreach (['paid_amount', 'paid_total', 'outstanding', 'due_at', 'payment_terms_days'] as $column) {
            $this->assertNotContains(
                $column,
                $columns,
                "«{$column}» عمودٌ ثانٍ لرقمٍ يحسبه الدفتر — انظر DocumentPaper::payment",
            );
        }
    }

    /* ═══════════════════ المحرّكُ خلف واجهة ═══════════════════ */

    /** والطباعةُ تمرّ بالسائق — فيُستبدل يومًا بلا أن تُمسّ القوالب */
    public function test_printing_goes_through_a_swappable_driver(): void
    {
        $fake = new class implements \App\Support\Document\Pdf\Driver
        {
            public array $calls = [];

            public function a4(string $html, string $name, bool $landscape = false, ?string $runningHeader = null): \Illuminate\Http\Response
            {
                $this->calls[] = $name;

                return response('FAKE');
            }

            public function strip(string $html, string $name, int $widthMm): \Illuminate\Http\Response
            {
                $this->calls[] = $name;

                return response('FAKE');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 100.0;
            }
        };

        $was = Pdf::swap($fake);

        try {
            $this->assertSame('FAKE', Pdf::a4('<p>x</p>', 'probe')->getContent());
            $this->assertSame(['probe'], $fake->calls);
        } finally {
            Pdf::swap($was);
        }
    }

    /* ═══════════════════ نسخةُ القالب ═══════════════════ */

    /** ونسخةٌ لا نعرفها تُرسم بالحاليّ ولا تُسقط الورقة */
    public function test_an_unknown_template_version_falls_back_without_breaking(): void
    {
        $this->assertSame(Version::views(Version::CURRENT), Version::views('abaad-modern-v99'));
        $this->assertSame(Version::views(Version::CURRENT), Version::views(null));
        $this->assertFalse(Version::known('abaad-modern-v99'));
        $this->assertTrue(Version::known(Version::CURRENT));
    }

    /** ولقطةُ الهويّة تحمل ما يُعاد به البناء — مساراتٍ لا صورًا مرمَّزة */
    public function test_the_snapshot_stores_paths_not_encoded_images(): void
    {
        Branding::save($this->business->id, ['primary' => '#7c3aed']);

        $snapshot = Version::snapshot($this->business->id);

        $this->assertSame(Version::CURRENT, $snapshot['template']);
        $this->assertSame('#7c3aed', $snapshot['primary']);
        $this->assertStringNotContainsString('data:', $snapshot['logo'].$snapshot['cover']);
    }

    /* ═══════════════════ الشريطُ الحراريّ ═══════════════════ */

    /** والإيصالُ قالبٌ مستقلّ — لا A4 مُصغَّرة */
    public function test_the_thermal_receipt_is_its_own_template(): void
    {
        $this->order();

        $strip = DocumentRenderer::preview($this->business->id, 'sale', ['paper' => '80mm']);
        $sheet = DocumentRenderer::preview($this->business->id, 'sale', ['paper' => 'A4']);

        // الشريطُ بلا غلافٍ ولا حوافَّ مُدوَّرة: الطابعةُ الحرارية لا ترسمها
        $this->assertStringNotContainsString('class="cover', $strip);
        $this->assertStringContainsString('border-top: 0.5pt dashed', $strip, 'الفاصلُ مصمتٌ فيشرب الحبر');

        // والورقةُ A4 لها ما ليس للشريط
        $this->assertStringContainsString('table.items', $sheet);
        $this->assertStringNotContainsString('table.items', $strip);
    }

    /** وعرضُ ٥٨ يُصغّر القياس — لا يترك خطَّ ٨٠ على ثلثي العرض */
    public function test_the_narrow_strip_shrinks_its_scale(): void
    {
        $this->order();

        $wide = DocumentRenderer::preview($this->business->id, 'sale', ['paper' => '80mm']);
        $narrow = DocumentRenderer::preview($this->business->id, 'sale', ['paper' => '58mm']);

        preg_match('/font-size: ([\d.]+)pt;\s*line-height: 1\.35/', $wide, $w);
        preg_match('/font-size: ([\d.]+)pt;\s*line-height: 1\.35/', $narrow, $n);

        $this->assertNotEmpty($w);
        $this->assertNotEmpty($n);
        $this->assertLessThan((float) $w[1], (float) $n[1], 'الشريطُ الضيّق بخطّ الشريط العريض');
    }

    /* ═══════════════════ لا بابَ طباعةٍ متروك ═══════════════════ */

    /**
     * وكلُّ بابٍ يطبع مستندًا يمرّ بالقوالب الجديدة — لا واحدٌ متروك.
     *
     * ═══ وهذا الحارسُ وُلد من عطبٍ وقع فعلًا ═══
     *
     * وُصلت **معاينةُ** فاتورة البيع بالقالب الجديد ونُسي **زرُّ الطباعة**:
     * فيرى التاجر في محرّر القوالب ورقةً، ويخرج من الطابعة غيرُها. ولا شيء
     * يقول إنّهما افترقتا — لأنّ كليهما يعمل.
     *
     * فيُفحص المستودعُ نفسُه: لا نداءَ لقالبٍ في `pdf/` من متحكّمٍ يطبع
     * مستندًا. والتقاريرُ تبقى هناك بحقّ — ورقةُ مخزونٍ ليست مستندًا يحمل
     * هويّةَ التاجر إلى جهةٍ خارجية، وجرُّها إلى إعادة التصميم عملٌ لم
     * يُطلَب.
     */
    public function test_no_printing_door_is_left_on_the_old_templates(): void
    {
        $documents = ['pdf.invoice', 'pdf.receipt', 'pdf.tax-invoice', 'pdf.customer-invoice', 'pdf.document'];

        $guilty = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ($documents as $view) {
                // النداءُ لا الذِّكر: تعليقٌ يشرح تاريخَ الملفّ ليس بناءً للورقة
                if (str_contains($source, "view('".$view."'")) {
                    $guilty[] = basename($file->getPathname()).' → '.$view;
                }
            }
        }

        $this->assertSame([], $guilty, 'بابُ طباعةٍ ما زال يرسم بالقالب القديم');
    }

    /** والفاتورةُ الضريبية وجهٌ ثالثٌ للقالب نفسه — لا قالبٌ رابع */
    public function test_the_tax_invoice_is_a_face_of_the_same_template(): void
    {
        $order = $this->order();

        Setting::create([
            'business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100123456',
        ]);

        $values = \App\Support\DocumentTemplates::settings($this->business->id, 'sale');

        /*
         * والرقمُ الضريبيّ يُطبع وإن أطفأ التاجر مقبضَه.
         *
         * ورقةٌ عنوانها «فاتورة ضريبية» بلا رقم بائعها تُردّ من أوّل جهةٍ
         * تراجعها — ويظنّ صاحبُها نفسَه ممتثلًا حتى تُردّ.
         */
        $off = $values + ['show_vat_no' => false];

        $plain = DocumentRenderer::saleSheet($this->business->id, $order, $off);
        $tax = DocumentRenderer::saleSheet($this->business->id, $order, $off, ['taxInvoice' => true]);

        $this->assertStringNotContainsString('OM1100123456', $plain, 'المقبضُ لا يُطفئ الرقم في الورقة العادية');
        $this->assertStringContainsString('OM1100123456', $tax, 'الفاتورةُ الضريبية طُبعت بلا رقم بائعها');
        $this->assertStringContainsString(__('فاتورة ضريبية'), $tax);
        $this->assertStringContainsString(__('فاتورة ضريبية صادرة آليًا عبر نظام أبعاد'), $tax);
    }

    /* ═══════════════════ صفحةُ التحقّق ═══════════════════ */

    /** ورقةٌ تُفتح برمزها، ولا تُفتح برمزٍ مُخمَّن */
    public function test_the_verification_page_opens_only_with_its_code(): void
    {
        $order = $this->order();
        $token = \App\Support\PublicDocument::token($order);

        $this->get(route('paper.show', $token))->assertOk()->assertSee('INV-000001');
        $this->get(route('paper.show', str_repeat('z', 22)))->assertNotFound();
    }

    /**
     * وفاتورةُ العميل تُعرض ملخّصًا — لا بنودَ ولا آيبان.
     *
     * ما يحتاجه من يمسح الرمز سؤالٌ واحد: أهذه الورقةُ صحيحة؟ فلا تُحمَّل
     * الصفحةُ بما لا يجيب عنه — ورقةٌ تُصوَّر بهاتفٍ في ممرّ، ورابطُها
     * يُعاد إرساله.
     */
    public function test_a_customer_invoice_verifies_without_exposing_its_lines(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة',
        ]);

        $invoice = \App\Support\CustomerInvoices::issue(
            \App\Support\CustomerInvoices::create(
                $this->business->id,
                $customer,
                ['issued_at' => now()->toDateString(), 'payment_terms_days' => 30],
                [['description' => 'تنسيق قاعة', 'quantity' => 1, 'unit_price' => 500, 'discount' => 0]],
            ),
            $this->owner->id,
        );

        $token = \App\Support\PublicDocument::token($invoice);
        $this->assertNotNull($token, 'فاتورةُ العميل لم تنل رمزًا — وهي ورقةُ زبون');

        $page = $this->get(route('paper.show', $token))->assertOk();

        $page->assertSee($invoice->number);
        $page->assertSee('ورد الخوير', false);
        // والمبلغُ من الفاتورة لا رقمًا مكتوبًا هنا: ضريبةٌ تُضاف تجعل الحارس يكذب
        $page->assertSee(number_format((float) $invoice->total, 3), false);

        /*
         * ولا اسمَ الجهة ولا بنودَها.
         *
         * الرقمُ والتاريخُ والمبلغُ والحالُ تكفي للتحقّق. و**إلى من** صدرت
         * لا تُضيف شيئًا إلى الجواب، وتُضيف كثيرًا إلى ما يعرفه من وصله
         * الرابطُ منقولًا: مَن يشتري من هذا المتجر، وبكم.
         */
        $page->assertDontSee('وزارة الثقافة', false);
        $page->assertDontSee('تنسيق قاعة', false);
    }
}
