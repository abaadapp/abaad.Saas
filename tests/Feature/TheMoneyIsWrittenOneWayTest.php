<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\Money;
use App\Support\PublicDocument;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المبلغُ الواحد يُكتب مرّةً واحدة — في اللوحة والورقة والمتجر والرابط.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * كان قرارُ «كيف يُكتب المبلغ» مكتوبًا في خمسة مواضع، وأربعةٌ منها تُثبّت
 * ثلاثَ منازلَ عشرية و«ر.ع» في الشفرة. والنظامُ يعرف عملةَ التاجر ويحفظها
 * — صفٌّ في `currencies`، ومنازلُ في الإعدادات، وموضعٌ للرمز.
 *
 * فتاجرٌ في دبي عملتُه الدرهم كانت:
 *
 *   • لوحتُه تكتب   →  ‏5.25 د.إ
 *   • وفاتورتُه تكتب →  ‏5.250 ر.ع
 *
 * منزلةٌ زائدةٌ وعملةُ بلدٍ آخر، على الورقة التي يرسلها باسمه إلى جهةٍ
 * خارجية. وهو لا يظهر في اختبارٍ يُكتب بالريال العمانيّ — إذ يوافق
 * المثبَّتُ الصوابَ مصادفةً — ولا في مراجعةٍ بالعين لأنّ الرقم «يبدو
 * صحيحًا». فيُكتب هذا الملفّ بعملةٍ **ليست** الافتراضية عمدًا.
 */
class TheMoneyIsWrittenOneWayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'متجر دبي', 'type' => 'محل ورود', 'city' => 'دبي', 'status' => 'نشط',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** عملةٌ ليست الافتراضية — الدرهمُ منزلتان، والريالُ ثلاث */
    private function dirham(): void
    {
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'AED', 'name' => 'درهم',
            'symbol' => 'د.إ', 'rate' => 1.0, 'is_base' => true, 'active' => true,
        ]);
    }

    private function order(float $total = 5.25): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'branch' => 'الفرع الرئيسي', 'number' => 'INV-000001', 'status' => 'مكتمل',
            'customer_name' => 'زبون', 'employee_name' => 'كاشير', 'payment_method' => 'نقدي',
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة ورد',
            'price' => $total, 'quantity' => 1, 'total' => $total,
        ]);

        return $order->load('items');
    }

    private function values(): array
    {
        return DocumentTemplates::settings($this->business->id, 'sale');
    }

    /* ═══════════════ الورقةُ تكتب بعملة صاحبها ═══════════════ */

    /**
     * الورقةُ واللوحةُ تكتبان المبلغَ نفسَه — حرفًا بحرف.
     *
     * وهو الاختبارُ الذي لو كان قائمًا لما وقع العطب: كلُّ اختباراتِ الورقة
     * كُتبت بالريال العمانيّ، فوافق المثبَّتُ الصوابَ ومرّت كلُّها.
     */
    public function test_the_paper_writes_what_the_dashboard_writes(): void
    {
        $this->dirham();
        $this->actingAs($this->owner);

        $paper = DocumentPaper::forSale($this->order());
        $grand = collect($paper['totals'])->firstWhere('grand', true)['value'];

        $this->assertSame(
            Demo::moneyBase(5.25),
            $grand,
            'الورقةُ تكتب غيرَ ما تكتب اللوحةُ للمبلغ نفسِه',
        );
        $this->assertSame('5.25 د.إ', $grand);
    }

    /**
     * ولا «ر.ع» ولا ثلاثُ منازلَ على ورقة تاجرٍ عملتُه غيرُها.
     *
     * ونُفحص الورقةُ المرسومة نفسُها لا البيانُ وحده: مُغلَقُ صيغةٍ في قالبٍ
     * واحد يكفي لإعادة العطب من بابٍ آخر.
     */
    public function test_no_paper_carries_a_currency_that_is_not_the_merchants(): void
    {
        $this->dirham();
        $this->actingAs($this->owner);

        $order = $this->order();

        $papers = [
            'الورقة' => DocumentRenderer::saleSheet($this->business->id, $order, $this->values()),
            'الشريط' => DocumentRenderer::saleStrip($this->business->id, $order, $this->values(), 80),
        ];

        foreach ($papers as $what => $html) {
            $body = preg_replace('/<!--.*?-->/s', '', $html);

            $this->assertStringNotContainsString('ر.ع', $body, $what.' تحمل عملةً ليست عملةَ التاجر');
            $this->assertStringNotContainsString('5.250', $body, $what.' تكتب منزلةً زائدة');
            $this->assertStringContainsString('د.إ', $body, $what.' لا تحمل عملةَ التاجر');
        }
    }

    /**
     * ومنازلُ التاجر تُطاع — لا منازلُ عملته وحدها.
     *
     * حقلُ «المنازل العشرية» في الإعدادات كان يُحفظ ولا يقرؤه أحدٌ على الورق.
     */
    public function test_the_merchant_chosen_decimals_reach_the_paper(): void
    {
        $this->dirham();
        Setting::create(['business_id' => $this->business->id, 'key' => 'decimals', 'value' => '0']);
        $this->actingAs($this->owner);

        $paper = DocumentPaper::forSale($this->order(5.0));
        $grand = collect($paper['totals'])->firstWhere('grand', true)['value'];

        $this->assertSame('5 د.إ', $grand, 'الورقةُ لا تقرأ منازلَ التاجر');
    }

    /* ═══════════════ ولا قاعدةَ ثانيةً في المستودع ═══════════════ */

    /**
     * كلُّ من يكتب مالًا يمرّ بـ`Money` — لا `number_format` في مسار المستند.
     *
     * وهذا هو الحارسُ الذي يمنع عودةَ العطب: من يضيف عمودًا إلى الورقة غدًا
     * يكتب `number_format($v, 3)` بحسن نيّة، فيعود المثبَّت من حيث لا يُنتظر.
     */
    public function test_the_document_path_holds_no_second_rule_for_money(): void
    {
        $path = [
            'app/Support/DocumentPaper.php',
            'app/Http/Controllers/PublicDocumentController.php',
            'resources/views/documents/v1',
            'resources/views/public/paper.blade.php',
        ];

        $files = [];

        foreach ($path as $p) {
            $full = base_path($p);

            if (is_dir($full)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full)) as $f) {
                    if (! $f->isDir()) {
                        $files[] = $f->getPathname();
                    }
                }

                continue;
            }

            $files[] = $full;
        }

        $guilty = [];

        foreach ($files as $file) {
            // الشرحُ ليس شفرة: تعليقٌ يروي العطبَ يذكر «ر.ع» بالضرورة
            $source = preg_replace('/\{\{--.*?--\}\}|\/\*.*?\*\//s', '', (string) file_get_contents($file));

            foreach (['ر.ع', "__('ر.ع')"] as $needle) {
                if (str_contains($source, $needle)) {
                    $guilty[] = basename($file).' → عملةٌ مثبَّتة';
                    break;
                }
            }

            /*
             * والكمّيةُ ليست مالًا: «3» لا «3.000»، ولها تنسيقُها.
             * فيُفحص ما يُنسَّق بثلاث منازلَ ثمّ يُلحق برمزٍ أو يُسمّى مبلغًا.
             */
            if (preg_match('/number_format\([^)]*,\s*3\s*\)/', $source)) {
                $guilty[] = basename($file).' → ثلاثُ منازلَ مثبَّتة';
            }
        }

        $this->assertSame([], array_unique($guilty), "قاعدةٌ ثانيةٌ لكتابة المال:\n".implode("\n", array_unique($guilty)));
    }

    /* ═══════════════ والرابطُ العامّ يقرأ البيانَ نفسَه ═══════════════ */

    /**
     * ما يقرؤه الزبون من الرمز هو ما طبعه التاجر — لا مستندٌ ثانٍ.
     *
     * وكانت صفحةُ الرابط تجمع المستندَ بيدها: تقرأ أعمدةَ الطلب وتقرّر أيَّ
     * سطرٍ يُطبع. فسطرٌ يُضاف إلى الورقة لا يبلغ الزبون، والعكس.
     */
    public function test_the_public_link_reads_the_same_document(): void
    {
        $this->dirham();
        $this->actingAs($this->owner);

        $order = $this->order();
        $token = PublicDocument::token($order);

        $paper = DocumentPaper::forSale($order->fresh('items'));
        $grand = collect($paper['totals'])->firstWhere('grand', true)['value'];

        $page = $this->get('/i/'.$token);
        $page->assertOk();

        $page->assertSee($grand, false);
        $page->assertDontSee('ر.ع', false);
    }

    /* ═══════════════ والمتجرُ ثالثُهم ═══════════════ */

    /** المتجرُ يكتب ما تكتبه اللوحة — ويطيع موضعَ الرمز */
    public function test_the_storefront_writes_what_the_dashboard_writes(): void
    {
        $this->dirham();
        Setting::create(['business_id' => $this->business->id, 'key' => 'symbol_pos', 'value' => 'before']);
        $this->actingAs($this->owner);

        $currency = Storefront::currency($this->business);

        $this->assertSame(Demo::moneyBase(5.25), Storefront::amount(5.25, $currency));
        $this->assertSame('د.إ 5.25', Storefront::amount(5.25, $currency), 'موضعُ الرمز لا يُطاع في المتجر');
    }

    /** والريالُ العمانيُّ يبقى ثلاثَ منازلَ — لا يُكسر ما كان صحيحًا */
    public function test_the_omani_rial_keeps_its_three_places(): void
    {
        $this->actingAs($this->owner);

        $this->assertSame('5.250 ر.ع', Money::format(5.25, Money::of($this->business->id)));
        $this->assertSame(3, Money::decimals(['code' => 'OMR']));
        $this->assertSame(2, Money::decimals(['code' => 'AED']));
    }
}
