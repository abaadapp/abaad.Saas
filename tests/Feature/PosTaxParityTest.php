<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الضريبة على شاشة الكاشير هي الضريبة في الفاتورة.
 *
 * الخادم يحتسبها سطرًا سطرًا بنسبة كل صنف؛ والشاشة كانت تضرب السلّة كلَّها
 * بنسبةٍ واحدة. فما يقرؤه الزبون قبل الدفع غير ما يُخصم منه بعده — وهو
 * أسوأ خلافٍ في نقطة بيع: لا أحد يراجع فاتورةً وافقَ على مجموعها.
 *
 * وهذه الاختبارات تحرس ما تستطيع حراسته من طرف الخادم: أن النسبة التي
 * تُعطى للشاشة هي النسبة التي سيحتسب بها الخادم.
 */
class PosTaxParityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    private function product(string $name, ?float $tax): Product
    {
        $cat = Category::firstOrCreate(['business_id' => $this->business->id, 'name' => 'عام']);

        return Product::create([
            'business_id' => $this->business->id, 'category_id' => $cat->id,
            'name' => $name, 'sku' => strtoupper(substr(md5($name), 0, 8)),
            'price' => 100, 'cost' => 50, 'quantity' => 10, 'alert_qty' => 1,
            'active' => true, 'tax' => $tax,
        ]);
    }

    private function rateOnScreen(string $name): float
    {
        $row = collect(Demo::products())->firstWhere('name', $name);
        $this->assertNotNull($row, "الصنف {$name} لا يصل إلى الشاشة");

        return (float) $row['tax'];
    }

    private function rateOnServer(Product $p): float
    {
        return \App\Support\Vat::rateFor($p, $this->business->id);
    }

    public function test_a_product_with_no_rate_of_its_own_shows_the_shop_rate_not_zero(): void
    {
        // `(float) null` كانت تكتب صفرًا حيث يحتسب الخادم نسبة المتجر
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        $p = $this->product('بلا نسبة', null);

        $this->assertSame(5.0, $this->rateOnScreen('بلا نسبة'));
        $this->assertSame($this->rateOnServer($p), $this->rateOnScreen('بلا نسبة'));
    }

    public function test_a_product_with_its_own_rate_keeps_it_on_the_screen(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        $p = $this->product('معفى', 0.0);

        $this->assertSame(0.0, $this->rateOnScreen('معفى'));
        $this->assertSame($this->rateOnServer($p), $this->rateOnScreen('معفى'));
    }

    public function test_raising_the_shop_rate_does_not_split_the_screen_from_the_charge(): void
    {
        /*
         * هذا هو الخلاف الذي كان قائمًا فعلًا: أصنافٌ تحمل نسبةً مكتوبة —
         * وهي حال كل صنفٍ في القاعدة اليوم — ثم تُرفع نسبة المتجر. فالشاشة
         * تقرأ نسبة المتجر الجديدة والخادم يبقى على نسبة الصنف.
         */
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '10']);
        $p = $this->product('صنف بنسبة مكتوبة', 5.0);

        $this->assertSame($this->rateOnServer($p), $this->rateOnScreen('صنف بنسبة مكتوبة'));
        $this->assertSame(5.0, $this->rateOnScreen('صنف بنسبة مكتوبة'));
    }

    public function test_turning_vat_off_zeroes_the_screen_too(): void
    {
        // الإطفاء يسبق كل نسبة — نسبة المتجر ونسبة الصنف معًا
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $p = $this->product('صنف', 15.0);

        $this->assertSame(0.0, $this->rateOnScreen('صنف'));
        $this->assertSame($this->rateOnServer($p), $this->rateOnScreen('صنف'));
    }

    public function test_every_product_on_the_screen_carries_the_rate_the_server_will_use(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '7.5']);
        $made = [
            $this->product('أ', null),
            $this->product('ب', 0.0),
            $this->product('ج', 15.0),
            $this->product('د', 5.0),
        ];

        foreach ($made as $p) {
            $this->assertSame(
                $this->rateOnServer($p),
                $this->rateOnScreen($p->name),
                "نسبة الصنف «{$p->name}» على الشاشة تخالف ما يحتسبه الخادم",
            );
        }
    }
    public function test_the_disabled_switch_reaches_a_product_that_carries_its_own_rate(): void
    {
        /*
         * `Vat::rateFor` كانت تتجاهل الإطفاء لصنفٍ يحمل نسبةً مكتوبة: تُرجع
         * `rate()` صفرًا، ثم `taxRate()` لا تقرأ ذلك الصفر أصلًا فتُرجع
         * نسبة الصنف. ولم يكن لها مُنادٍ يومها — والخطأ الذي لا يُنادى اليوم
         * يُنادى غدًا.
         */
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $p = $this->product('صنف بنسبة', 15.0);

        $this->assertSame(0.0, \App\Support\Vat::rateFor($p, $this->business->id));
    }

    public function test_the_pos_line_math_and_the_screen_agree_on_a_mixed_cart(): void
    {
        /*
         * سلّةٌ فيها معفًى ومحمّل: النسبة الواحدة كانت تجعل الشاشة تقول رقمًا
         * والفاتورة رقمًا آخر. هنا يُقارَن ما يُعطى للشاشة بما يحتسبه الخادم
         * لكل بند.
         */
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        $exempt = $this->product('خبز', 0.0);
        $taxed = $this->product('عصير', 5.0);

        $screen = collect(Demo::products())->keyBy('name');

        $this->assertSame(0.0, (float) $screen['خبز']['tax']);
        $this->assertSame(5.0, (float) $screen['عصير']['tax']);

        // ولا يُخلط بينهما: نسبةٌ واحدة للسلّة كانت تُعطي 5٪ على الخبز أيضًا
        $this->assertNotSame((float) $screen['خبز']['tax'], (float) $screen['عصير']['tax']);
        $this->assertSame(\App\Support\Vat::rateFor($exempt, $this->business->id), (float) $screen['خبز']['tax']);
        $this->assertSame(\App\Support\Vat::rateFor($taxed, $this->business->id), (float) $screen['عصير']['tax']);
    }
}
