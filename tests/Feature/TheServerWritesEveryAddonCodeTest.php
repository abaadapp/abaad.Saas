<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\BusinessAddon;
use App\Models\SubscriptionAddon;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * رمزُ الإضافة يكتبه الخادم — لا يكتبه صاحبُ المنصّة ولا يقبله من طلب.
 *
 * ═══ ما كان ═══
 *
 * نموذجُ الإضافة يطلب «الرمز» بحروفٍ لاتينيّةٍ صغيرة (`pos`)، ويردّ ما لا
 * يطابق القاعدة. فصار الرمزُ يُولَّد (`Catalog::newCode`) ولا يُقرأ من الطلب.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. إضافةٌ بلا رمزٍ تُنشأ برمزٍ فريدٍ يتّسع له العمود.
 *   2. اسمان عربيّان متطابقان: إضافتان برمزين.
 *   3. إعادةُ التسمية لا تمسّ الرمز، ورمزٌ يُرسل في الطلب لا يُقرأ.
 *   4. رموزُ الإضافات السابقة كما هي، وطلباتُها تعمل بها.
 *   5. إضافةٌ جديدةٌ برمزها المولَّد تُطلب وتُعتمد وتُفوتَر وتسري بسدادها.
 *   6. الفهرسُ الفريد باقٍ في القاعدة.
 */
class TheServerWritesEveryAddonCodeTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlans();
    }

    private function fields(array $over = []): array
    {
        return $over + [
            'name' => 'تدريب الموظفين', 'pricing' => 'paid', 'unit' => 'store',
            'monthly_price' => 2.5, 'yearly_price' => 25, 'for_sale' => true, 'for_new_merchants' => true,
        ];
    }

    public function test_an_addon_is_created_without_a_code_and_gets_a_unique_one_that_fits_the_column(): void
    {
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), $this->fields())->assertSessionHasNoErrors();
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), $this->fields())->assertSessionHasNoErrors();

        $codes = SubscriptionAddon::where('name', 'تدريب الموظفين')->pluck('code');
        $this->assertCount(2, $codes, 'اسمان متطابقان لا يصنعان إضافتين');
        $this->assertCount(2, $codes->unique(), 'رمزان متطابقان');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^addon_[0-9a-z]{26}$/', $code);
            $this->assertLessThanOrEqual(Catalog::CODE_MAX, strlen($code));
            // ولا يُشتقّ من الاسم العربيّ
            $this->assertStringNotContainsString('تدريب', $code);
        }

        // والنموذجُ بلا حقل رمز — ومن الكود بلا رمزٍ يُولَّد كذلك
        $this->assertMatchesRegularExpression('/^addon_[0-9a-z]{26}$/', SubscriptionAddon::create($this->fields(['name' => 'من الكود']))->code);
    }

    public function test_a_code_sent_over_http_is_never_read_on_create_or_update(): void
    {
        $this->actingAs($this->platform)->postJson(route('super-admin.addons.store'), $this->fields(['code' => 'loyalty']))->assertRedirect();
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), $this->fields(['name' => 'دعم', 'code' => 'Not A Code!']))->assertSessionHasNoErrors();

        $this->assertSame(1, SubscriptionAddon::where('code', 'loyalty')->count(), 'أُخذ رمزُ إضافةٍ أخرى');
        $this->assertSame(0, SubscriptionAddon::where('code', 'Not A Code!')->count());

        $added = SubscriptionAddon::where('name', 'دعم')->sole();
        $code = $added->code;

        // وإعادةُ التسمية — ورمزٌ مرسلٌ معها — لا تمسّ الرمز
        $this->actingAs($this->platform)->put(route('super-admin.addons.update', $added->id), $this->fields(['name' => 'دعم بأولوية', 'name_en' => 'Priority support', 'code' => 'hijack']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['دعم بأولوية', 'Priority support', $code], [$added->fresh()->name, $added->fresh()->name_en, $added->fresh()->code]);
    }

    public function test_existing_codes_stay_and_their_orders_still_work(): void
    {
        $before = SubscriptionAddon::orderBy('id')->pluck('code', 'id')->all();
        $this->assertContains('pos', $before);

        // تعديلُ اسم نقطة البيع لا يمسّ رمزها
        $pos = $this->addon('pos');
        $this->actingAs($this->platform)->put(route('super-admin.addons.update', $pos->id), [
            'name' => 'الكاشير', 'capability' => Catalog::POS, 'pricing' => 'paid', 'unit' => 'store',
            'monthly_price' => 9.9, 'yearly_price' => 99, 'for_sale' => true, 'for_new_merchants' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame($before, SubscriptionAddon::orderBy('id')->pluck('code', 'id')->all());

        // ومتجرُ الإضافات يطلبها برمزها القديم كما كان
        $this->launch();
        [$business, $owner] = $this->shop('basic', 'v1@shop.om');
        $this->actingAs($owner)->post(route('admin.subscription.addons.request'), ['code' => 'pos', 'cycle' => 'monthly'])->assertSessionHasNoErrors();
        $this->assertSame(1, AddonOrder::where('business_id', $business->id)->where('subscription_addon_id', $pos->id)->count());
    }

    public function test_a_new_addon_with_a_generated_code_is_requested_approved_invoiced_and_opened_by_payment(): void
    {
        $this->launch();
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), $this->fields())->assertSessionHasNoErrors();
        $added = SubscriptionAddon::where('name', 'تدريب الموظفين')->sole();
        $this->actingAs($this->platform)->put(route('super-admin.addons.plans', $added->id), [
            'plans' => [(string) $this->plans['basic']->id => Catalog::ELIGIBLE],
        ])->assertSessionHasNoErrors();

        [$business, $owner] = $this->shop('basic', 'buyer@shop.om');
        $this->actingAs($owner)->get(route('admin.subscription.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('may_request', true)
                ->where('available', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === $added->code && $r['monthly_price'] === 2.5)));

        $this->actingAs($owner)->post(route('admin.subscription.addons.request'), ['code' => $added->code, 'cycle' => 'monthly'])->assertSessionHasNoErrors();
        // وضغطةٌ ثانية لا تصنع طلبًا ثانيًا
        $this->actingAs($owner)->post(route('admin.subscription.addons.request'), ['code' => $added->code, 'cycle' => 'monthly']);
        $order = AddonOrder::where('business_id', $business->id)->sole();

        AddonOrders::approve($order, null, null);
        $this->assertSame(AddonOrders::INVOICED, $order->fresh()->status);
        $this->assertSame(0, BusinessAddon::where('business_id', $business->id)->count(), 'سرت قبل السداد');

        $this->pay($order);
        $this->pay($order);
        $this->assertSame(1, BusinessAddon::where('business_id', $business->id)->where('subscription_addon_id', $added->id)->active()->count());
    }

    public function test_the_unique_index_still_guards_the_code_column(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        SubscriptionAddon::create($this->fields(['code' => 'pos', 'name' => 'نسخة']));
    }
}
