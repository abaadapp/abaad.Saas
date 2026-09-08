<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ الكوبونات تقول ما سيفعله الصندوق — لا ما في العمود.
 *
 * ═══ شارةٌ خضراء لكودٍ يُردّ ═══
 *
 * `Coupon::isValid` تشترط ثلاثًا: مفعَّل، وغير منتهٍ، ولم يُستنفد. والشاشة
 * كانت تقرأ اثنتين: تُظهر «منتهٍ» لمن مضى تاريخه، و«فعّال» لكلّ ما عداه —
 * فكودٌ حدُّه خمسون استُخدم خمسين مرّةً يُقرأ **فعّالًا** في اللوحة ويُردّ
 * عند الصندوق: «انتهت مرات استخدام الكوبون». والتاجر يقرأ الشارة الخضراء
 * فيظنّ العطبَ في الكاشير أو في الكود الذي طبعه على اللافتة.
 *
 * وبطاقةُ «كوبونات فعّالة» فوقها كانت تعدّ عمود `active` وحده: تقول «٤» وفي
 * الصندوق يعمل واحد.
 *
 * ═══ وشرائحُ العملاء تُرسل ولا تُقرأ ═══
 *
 * الصفحة كانت تحمل معها `segments`: **اسمَ كلّ عميلٍ ورقمَ هاتفه ومجموعَ
 * إنفاقه** — ولا سطرَ في الشاشة يقرؤها. حمولةٌ تُحسب باستعلامين ومسحِ جدول
 * العملاء كلِّه في كلّ فتحة، وتُقرأ من مصدر الصفحة.
 *
 * و«التسويق» قسمٌ غير «العملاء»: موظّفٌ مُنح الكوبونات ولم يُمنح العملاء كان
 * يقرأ أرقامهم كلَّها من صفحةٍ لا تعرض منها شيئًا.
 */
class ACouponSaysWhatTheRegisterWillDoTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function coupon(array $over = []): Coupon
    {
        return Coupon::create(array_merge([
            'business_id' => $this->business->id,
            'code' => 'SAVE'.mt_rand(1000, 9999),
            'type' => 'نسبة', 'value' => 10, 'min_order' => 0,
            'max_uses' => null, 'used_count' => 0, 'expires_at' => null, 'active' => true,
        ], $over));
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return $this->get(route('admin.marketing.coupons'))->viewData('page')['props'];
    }

    /* ------------------------------ الشارة ------------------------------ */

    public function test_an_exhausted_coupon_says_so(): void
    {
        $this->coupon(['code' => 'DONE', 'max_uses' => 5, 'used_count' => 5]);

        $row = collect($this->props()['coupons'])->firstWhere('code', 'DONE');

        $this->assertFalse($row['usable'], 'كودٌ استُنفد يُقرأ صالحًا في الشاشة');
    }

    public function test_a_live_coupon_is_usable(): void
    {
        $this->coupon(['code' => 'LIVE', 'max_uses' => 5, 'used_count' => 4]);

        $row = collect($this->props()['coupons'])->firstWhere('code', 'LIVE');

        $this->assertTrue($row['usable']);
    }

    public function test_a_stopped_coupon_is_not_usable(): void
    {
        $this->coupon(['code' => 'OFF', 'active' => false]);

        $this->assertFalse(collect($this->props()['coupons'])->firstWhere('code', 'OFF')['usable']);
    }

    public function test_an_expired_coupon_is_not_usable(): void
    {
        $this->coupon(['code' => 'OLD', 'expires_at' => now()->subDay()]);

        $this->assertFalse(collect($this->props()['coupons'])->firstWhere('code', 'OLD')['usable']);
    }

    /** ومن ينتهي اليوم يعمل اليوم كلّه — انظر `Coupon::endsAt` */
    public function test_a_coupon_that_ends_today_still_works_today(): void
    {
        $this->coupon(['code' => 'TODAY', 'expires_at' => today()]);

        $this->assertTrue(collect($this->props()['coupons'])->firstWhere('code', 'TODAY')['usable']);
    }

    /**
     * والشاشةُ ترسم الشارة — لا تصلها الحقيقةُ لتُطرح.
     *
     * والفحص على الشرط المكتوب في الشجرة لا على ورود الكلمة في الملفّ:
     * أوّلُ صياغةٍ لهذا الاختبار كانت تبحث عن «استُنفد» في النصّ، فمرّت على
     * **تعليقٍ** فوق الشارة يذكرها — ونجا متحوّلٌ رفع الشارةَ نفسها.
     */
    public function test_the_screen_draws_the_exhausted_badge(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Admin/Marketing/Coupons.tsx'));

        $this->assertStringContainsString(') : c.exhausted ? (', $source);
        $this->assertMatchesRegularExpression("/<Badge[^>]*>\{t\('استُنفد'\)\}/u", $source);
        $this->assertStringContainsString('{c.usable ? t(', $source);
    }

    /* ------------------------------ البطاقة ------------------------------ */

    public function test_the_active_card_counts_only_what_works(): void
    {
        $this->coupon(['code' => 'A']);                                          // يعمل
        $this->coupon(['code' => 'B', 'active' => false]);                       // موقوف
        $this->coupon(['code' => 'C', 'expires_at' => now()->subDay()]);         // منتهٍ
        $this->coupon(['code' => 'D', 'max_uses' => 2, 'used_count' => 2]);      // مستنفَد

        $stats = $this->props()['stats'];

        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['active'], 'البطاقة تعدّ كوبوناتٍ لا يقبلها الصندوق');
    }

    /**
     * والبطاقةُ والصندوقُ يقرآن التعريف نفسه.
     *
     * تعريفان لعبارةٍ واحدة يفترقان عند أوّل تعديل — فتقول اللوحة عددًا
     * وتعرض نقطةُ البيع غيرَه.
     */
    public function test_the_card_and_the_register_agree(): void
    {
        $this->coupon(['code' => 'A']);
        $this->coupon(['code' => 'B', 'max_uses' => 1, 'used_count' => 1]);
        $this->coupon(['code' => 'C', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'D', 'active' => false]);

        $this->assertSame(
            count(Demo::activeCoupons()),
            $this->props()['stats']['active'],
            'ما تعدّه اللوحة ليس ما يعرضه الصندوق',
        );
    }

    /* --------------------------- ما لا يُرسل --------------------------- */

    public function test_the_page_carries_no_customer_phone_book(): void
    {
        Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567',
        ]);

        $props = $this->props();

        $this->assertArrayNotHasKey('segments', $props, 'الصفحة ما زالت تحمل شرائح العملاء');
    }

    /** والفحص على الحمولة كلّها لا على المفتاح: رقمٌ يمرّ بأيّ اسمٍ يمرّ */
    public function test_no_phone_number_reaches_the_coupons_page(): void
    {
        Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567',
        ]);

        $payload = json_encode($this->props(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('91234567', $payload);
    }

    /** ولا تبقى الدالّةُ بلا مُنادٍ تمسح جدول العملاء لمن يفتح الصفحة */
    public function test_the_segment_reader_is_gone(): void
    {
        $this->assertFalse(
            method_exists(Demo::class, 'marketingSegment'),
            'الدالّة باقيةٌ بلا مُنادٍ — وفرعٌ لا يقرؤه أحدٌ يُرفع',
        );
    }
}
