<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكودُ يُجرَّب في السلّة قبل الدفع — وهذا ما يقوله للكاشير.
 *
 * `pos.coupon.apply` بابٌ يمسّ المال ولا اختبارَ واحدًا يذكره. وهو **عرضٌ
 * لا التزام**: الدفع يعيد التحقق ويحسب الخصم من جديد تحت قفلٍ على الصفّ
 * (انظر `checkout`)، فلا يشتري أحدٌ بخصمٍ ألّفه بيده في المتصفّح.
 *
 * لكنّ العرضَ إن كذب أسوأ من غيابه: يقول للكاشير «طُبّق» أمام الزبون ثم
 * يرفضه الدفع — أو يعِد بخصمٍ ثمّ تخرج الفاتورة بغيره.
 *
 * وأخطر ما يُحرَس هنا أنّ كود الجار لا يُقرأ: رقمٌ يُخمَّن في حقلٍ نصّيّ
 * يمنح خصمَ متجرٍ آخر، ولا يظهر ذلك في أيّ سجلّ.
 */
class PosCouponPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الكود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@coupon.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + [
            'business_id' => $this->business->id,
            'code' => 'WARD10',
            'type' => 'نسبة',
            'value' => 10,
            'min_order' => 0,
            'active' => true,
            'used_count' => 0,
        ]);
    }

    private function try(string $code, float $subtotal = 100)
    {
        return $this->actingAs($this->cashier)
            ->postJson(route('pos.coupon.apply'), ['code' => $code, 'subtotal' => $subtotal]);
    }

    /* ------------------------------ ما يُقبل ------------------------------ */

    public function test_a_valid_code_returns_the_discount_it_will_give(): void
    {
        $this->coupon();

        $this->try('WARD10', 200)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'WARD10')
            ->assertJsonPath('discount', 20);
    }

    /** يُكتب بحروفٍ صغيرة على لوحةٍ مستعجلة — والكود واحد */
    public function test_the_code_is_read_regardless_of_case_and_spaces(): void
    {
        $this->coupon();

        $this->try('  ward10  ')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_fixed_amount_coupon_gives_its_amount(): void
    {
        $this->coupon(['code' => 'FIVE', 'type' => 'مبلغ', 'value' => 5]);

        $this->try('FIVE', 40)->assertOk()->assertJsonPath('discount', 5);
    }

    /* ------------------------------ ما يُرفض ------------------------------ */

    public function test_an_unknown_code_is_refused(): void
    {
        $this->try('LAYSA')->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_a_stopped_coupon_is_refused(): void
    {
        $this->coupon(['active' => false]);

        $this->try('WARD10')->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $this->coupon(['expires_at' => now()->subDay()]);

        $this->try('WARD10')->assertStatus(422)->assertJsonPath('ok', false);
    }

    /** ينتهي «اليوم» يعني آخر اليوم لا أوّله — عرضُ يومٍ واحد يجب أن يعمل يومه */
    public function test_a_coupon_ending_today_still_works_today(): void
    {
        $this->coupon(['expires_at' => now()]);

        $this->try('WARD10')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_used_up_coupon_is_refused(): void
    {
        $this->coupon(['max_uses' => 3, 'used_count' => 3]);

        $this->try('WARD10')->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_a_basket_under_the_minimum_is_refused(): void
    {
        $this->coupon(['min_order' => 50]);

        $this->try('WARD10', 20)->assertStatus(422)->assertJsonPath('ok', false);
    }

    /* ------------------------------- الجار ------------------------------- */

    public function test_a_neighbours_code_is_not_found_here(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'status' => 'نشط']);
        Coupon::create([
            'business_id' => $other->id, 'code' => 'JAAR', 'type' => 'نسبة',
            'value' => 90, 'min_order' => 0, 'active' => true, 'used_count' => 0,
        ]);

        $this->try('JAAR')->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_a_guest_cannot_probe_codes(): void
    {
        $this->coupon();

        $this->postJson(route('pos.coupon.apply'), ['code' => 'WARD10', 'subtotal' => 100])
            ->assertStatus(401);
    }

    /* ------------------------ شاشة إعدادات الصندوق ------------------------ */

    public function test_the_cashier_settings_screen_opens(): void
    {
        $this->actingAs($this->cashier)->get(route('pos.settings'))->assertOk();
    }
}
