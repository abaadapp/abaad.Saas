<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * متجرٌ كان يجبي الضريبةَ بالغياب يبقى يجبيها — والجديدُ يبدأ بلا ضريبة.
 *
 * ═══ ما يُحرس ═══
 *
 * قبل `Vat::DEFAULT_OFF_FROM` كان غيابُ `vat_enabled` «مفعّلة». وفي الإنتاج
 * متجرٌ بلا جوابٍ صريح جبى ضريبتَه على أكثر من ألف بيعة، وآخرُ سجّل بنفسه ولم
 * يُجب. فتغييرُ الافتراض لا يمسّهما:
 *
 *   - المتجرُ القائم قبله بلا صفٍّ: مفعّلة — في الصندوق وفي الشاشتين.
 *   - وحفظُ اسمه أو عملته لا يكتب عنه «مطفأة»، ولا يكتب عنه شيئًا.
 *   - والجوابُ الصريح هو الحكم في الحالين: `'0'` تُطفئ القديم، و`'1'` تفعّل الجديد.
 *   - والمُنشأ بعده بلا صفٍّ: مطفأة. ولا يستعير متجرٌ افتراضَ متجرٍ آخر.
 */
class AShopThatChargedVatBeforeTheDefaultChangedStillDoesTest extends TestCase
{
    use RefreshDatabase;

    private Business $old;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old = $this->shop('ورود قائمة', Carbon::parse(Vat::DEFAULT_OFF_FROM)->subDays(30));
        $this->owner = User::create([
            'business_id' => $this->old->id, 'name' => 'المالك', 'email' => 'old-vat@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    /** متجرٌ أُنشئ في لحظةٍ بعينها — كما يكتب التطبيقُ طوابعَه */
    private function shop(string $name, Carbon $createdAt): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $shop->forceFill(['created_at' => $createdAt])->save();
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Product::create(['business_id' => $shop->id, 'name' => 'باقة', 'price' => 100, 'cost' => 60, 'quantity' => 500, 'active' => true]);

        return $shop->fresh();
    }

    private function sell(): Order
    {
        $branch = Branch::where('business_id', $this->old->id)->firstOrFail();
        $this->activatePosDevice($this->old->id, $branch->id);
        session(['pos_cashier_id' => $this->owner->id, 'current_branch' => $branch->id]);

        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => Product::where('business_id', $this->old->id)->value('id'), 'name' => 'باقة', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->old->id)->latest('id')->firstOrFail();
    }

    private function stored(Business $shop): ?string
    {
        return Setting::where('business_id', $shop->id)->where('key', 'vat_enabled')->value('value');
    }

    public function test_an_existing_shop_without_any_vat_setting_keeps_charging_it(): void
    {
        $this->assertNull($this->stored($this->old));
        $this->assertTrue(Vat::enabled($this->old->id));
        $this->assertSame(5.0, Vat::rate($this->old->id));

        $order = $this->sell();

        $this->assertSame(5.0, (float) $order->tax, 'أُطفئت ضريبةُ متجرٍ قائم بتغيير الافتراض');
        $this->assertSame(105.0, (float) $order->total);
    }

    public function test_both_screens_show_it_on_and_saving_the_name_writes_nothing_about_it(): void
    {
        $settings = $this->get(route('admin.settings.index'))->assertOk()->viewData('page')['props']['settings'];
        $this->assertSame('1', $settings['vat_enabled'], 'الشاشةُ تعرضها مطفأة والنظامُ يجبيها — أوّلُ حفظٍ يكتبها مطفأة');
        $this->assertTrue($this->get(route('admin.setup.index'))->assertOk()->viewData('page')['props']['shop']['vat_enabled']);

        $this->post(route('admin.settings.update'), ['shop_name' => 'ورود قائمة'])->assertSessionHasNoErrors();

        $this->assertTrue(Vat::enabled($this->old->id));
        $this->assertNull($this->stored($this->old), 'كُتب عن التاجر جوابٌ لم يُعطه');
    }

    public function test_an_explicit_answer_decides_for_an_existing_shop_too(): void
    {
        Setting::create(['business_id' => $this->old->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->assertFalse(Vat::enabled($this->old->id));
        $this->assertSame(0.0, (float) $this->sell()->tax);
    }

    public function test_a_shop_created_after_the_change_starts_off_until_it_turns_it_on(): void
    {
        $new = $this->shop('ورود جديدة', Carbon::parse(Vat::DEFAULT_OFF_FROM)->addSecond());
        $this->assertFalse(Vat::enabled($new->id));
        $this->assertSame(0.0, Vat::rate($new->id));

        Setting::create(['business_id' => $new->id, 'key' => 'vat_enabled', 'value' => '1']);
        $this->assertTrue(Vat::enabled($new->id));
    }

    public function test_the_change_is_read_in_the_apps_own_time_and_lends_nothing_across_shops(): void
    {
        // اللحظةُ نفسُها بتوقيت التطبيق: من أُنشئ قبلها بثانيةٍ قائم، ومن أُنشئ عندها جديد
        $edge = Carbon::parse(Vat::DEFAULT_OFF_FROM, config('app.timezone'));
        $before = $this->shop('قبلها بثانية', $edge->copy()->subSecond());
        $at = $this->shop('عندها', $edge->copy());

        $this->assertTrue(Vat::enabled($before->id));
        $this->assertFalse(Vat::enabled($at->id));
        // ولا يمسّ جوابُ متجرٍ متجرًا آخر
        Setting::create(['business_id' => $before->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->assertTrue(Vat::enabled($this->old->id));
        $this->assertFalse(Vat::enabled($at->id));
    }
}
