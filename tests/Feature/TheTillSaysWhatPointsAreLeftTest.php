<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصندوقُ يقول ما بقي من نقاط الزبون — لا ما كان قبل الخصم.
 *
 * ═══ العطب ═══
 *
 * قائمةُ العملاء تصل الصندوقَ مرّةً حين تُفتح الشاشة، ولا تُجلب بعد البيع:
 * `onSynced` تُعيد المنتجات وحدها. فالكاشير يستبدل ثلاثمئة نقطةٍ من خمسمئة،
 * وتبقى بطاقةُ الولاء أمامه تقول «نقاط العميل: ٥٠٠».
 *
 * وليس العطبُ رقمًا معروضًا خطأً وحده: البيعةُ التالية تُرسَل بنقاطٍ لا وجود
 * لها، فيردّها الخادم بـ«تغيّر رصيد نقاط العميل — أعد احتساب الاستبدال»،
 * ويقف الكاشير أمام رفضٍ لا يفهم سببه والزبون واقف.
 *
 * ═══ والعلاج ═══
 *
 * الفاتورةُ تردّ الرصيد. رقمٌ واحدٌ يُصحّح الشاشة في الحال، أرخصُ من جلب
 * قائمة العملاء كلِّها بعد كلّ بيعة — وأصدقُ منها: ما في القاعدة هو ما
 * يُستبدَل به غدًا.
 */
class TheTillSaysWhatPointsAreLeftTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Customer $customer;

    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '90000000',
            'language' => 'ar', 'points' => 500,
        ]);

        $this->p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 1000, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->set('loyalty_enabled', '1');
        $this->set('loyalty_redeem_max_pct', '50');
        $this->set('loyalty_redeem_min', '100');
        $this->set('loyalty_earn_rate', '0');
        $this->set('vat_enabled', '0');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }

    /** @return array<string, mixed> */
    private function sell(array $extra = [], int $qty = 1): array
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->p->id, 'name' => $this->p->name, 'qty' => $qty]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('t', true),
            ], $extra))->assertOk()->json();
    }

    /* ═══════════ الرصيدُ يعود مع الفاتورة ═══════════ */

    /** استبدلَ ثلاثمئة من خمسمئة — فيُقال له إنّ مئتين بقيت */
    public function test_the_sale_says_what_is_left_after_redeeming(): void
    {
        $body = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 300]);

        $this->assertSame(300, $body['points_redeemed']);
        $this->assertSame(200, $body['points_balance']);
        $this->assertSame(200, (int) $this->customer->refresh()->points);
    }

    /**
     * والمكتسبُ يدخل الرصيد المردود — لا يُقال رقمٌ ثمّ يجده غيرَه.
     *
     * الخصمُ والاكتساب يقعان في البيعة نفسِها: مئةُ ريالٍ ينزل عنها ثلاثةٌ
     * (ثلاثمئة نقطة)، فيكسب عن سبعةٍ وتسعين — ويبقى ٥٠٠ − ٣٠٠ + ٩٧.
     */
    public function test_what_he_earns_is_inside_the_balance(): void
    {
        $this->set('loyalty_earn_rate', '1');

        $body = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 300]);

        $this->assertSame(300, $body['points_redeemed']);
        $this->assertSame(97, $body['points_earned']);
        $this->assertSame(297, $body['points_balance']);
        $this->assertSame(297, (int) $this->customer->refresh()->points);
    }

    /**
     * وبيعةٌ لا تستبدل شيئًا تردّ الرصيد كما هو — لا تسكت عنه.
     *
     * والكاشير يقرأ رصيدَ زبونه بعد كلّ فاتورة، استبدل أو لم يستبدل.
     */
    public function test_a_sale_without_redemption_still_says_the_balance(): void
    {
        $body = $this->sell(['customer_id' => $this->customer->id]);

        $this->assertSame(0, $body['points_redeemed']);
        $this->assertSame(500, $body['points_balance']);
    }

    /** وبيعتان متتاليتان: كلٌّ منهما تقول الرصيدَ الصحيح بعدها */
    public function test_two_sales_in_a_row_each_say_the_true_balance(): void
    {
        $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 200]);
        $body = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 200]);

        $this->assertSame(100, $body['points_balance']);
        $this->assertSame(100, (int) $this->customer->refresh()->points);
    }

    /* ═══════════ ولا يُقال رقمٌ لا معنى له ═══════════ */

    /**
     * وزبونٌ نقديٌّ بلا حساب لا رصيدَ يُقال عنه — و`null` ليست صفرًا.
     *
     * صفرٌ يُعرض في ورقة النجاح يُقرأ «نقاطُك نفدت»، ولا نقاطَ له أصلًا.
     */
    public function test_a_walk_in_customer_is_told_nothing_about_points(): void
    {
        $body = $this->sell();

        $this->assertSame(0, $body['points_earned']);
        $this->assertSame(0, $body['points_redeemed']);
        $this->assertNull($body['points_balance']);
    }

    /**
     * وبرنامجٌ مُطفأ يردّ رصيدَ الزبون كما هو — لا صفرًا.
     *
     * ولا رقمَ يُعرض أصلًا: بطاقةُ الولاء مخفيّةٌ في الصندوق حين يُطفأ
     * البرنامج، فورقةٌ تعرض رصيدًا تقول ما لا تقوله الشاشة — ونقاطُه في
     * حسابه كما هي تنتظر أن يُشغّله صاحبُ المحلّ.
     */
    public function test_a_switched_off_programme_does_not_erase_his_points(): void
    {
        $this->set('loyalty_enabled', '0');

        $body = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 300]);

        $this->assertSame(0, $body['points_redeemed']);
        $this->assertNull($body['points_balance'], 'برنامجٌ مُطفأ لا رصيدَ يُقال عنه');
        $this->assertSame(500, (int) $this->customer->refresh()->points);
    }

    /**
     * ═══ والسقفُ يُقال في الرصيد لا يُخفى ═══
     *
     * طلبَ الكاشيرُ خمسمئة والسقفُ نصفُ الفاتورة — فخُصم خمسون ريالًا أي
     * خمسة آلاف نقطة… لا: الرصيدُ خمسمئة، فيُؤخذ منه ما لا يتجاوزه.
     */
    public function test_more_than_he_owns_is_not_taken(): void
    {
        $body = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 5000]);

        $this->assertSame(500, $body['points_redeemed']);
        $this->assertSame(0, $body['points_balance']);
        $this->assertSame(0, (int) $this->customer->refresh()->points);
    }
}
