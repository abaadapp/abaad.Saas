<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\ReportData;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * من لم يُجب لا يجبي — الضريبةُ مطفأةٌ ما لم تُفعَّل صراحةً.
 *
 * ═══ ما كان ═══
 *
 * `Vat::enabled` تقرأ غيابَ `vat_enabled` تفعيلًا: متجرٌ لم يفتح إعداداته
 * يجبي ٥٪ من زبائنه، ويُثبت ضريبةَ مدخلاتٍ على كلّ سند مورّد — ولا رقمَ
 * ضريبيَّ له. وقع في متجرٍ حقيقيّ: أيّامُه الأولى كلُّها حتى أطفأها صاحبُه.
 * وشاشةُ التهيئة تعرض الغيابَ «مسجَّلًا»، فحفظُ اسم المتجر يكتبها مفعّلة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - متجرٌ بلا صفٍّ: مطفأة — في الصندوق، وفي النسبة، وفي نسبة الصنف الخاصّة،
 *     وفي الورقة (لا رقمَ ضريبيًّا يُطبع).
 *   - و`'1'` وحدها تفعّل: كلُّ قيمةٍ أخرى مخزّنة — فارغةً أو نصًّا أو `null`
 *     في الصفّ — مطفأة.
 *   - والتفعيلُ والإطفاءُ الصريحان يبقيان كما اختارهما التاجر.
 *   - ومفتاحُ متجرٍ لا يصل غيرَه، ولا يُفعّلها افتراضُ المنصّة للنسبة.
 *   - وما كُتب من ضريبةٍ قبل اليوم يبقى كما كُتب: الطلبُ القديم وإقرارُه.
 *   - وشاشةُ التهيئة تعرض ما يحسبه النظام.
 */
class AShopThatNeverAnsweredChargesNoVatTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود الخوير', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 100, 'cost' => 60, 'quantity' => 500, 'active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    private function set(?string $value, ?Business $shop = null): void
    {
        Setting::updateOrCreate(
            ['business_id' => ($shop ?? $this->business)->id, 'key' => 'vat_enabled'],
            ['value' => $value],
        );
    }

    private function sell(): Order
    {
        $branch = Branch::where('business_id', $this->business->id)->first();
        $this->activatePosDevice($this->business->id, $branch->id);
        session(['pos_cashier_id' => $this->owner->id, 'current_branch' => $branch->id]);

        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /* ============================ متجرٌ لم يُجب ============================ */

    public function test_a_new_shop_without_any_vat_setting_charges_nothing(): void
    {
        $this->assertFalse(Vat::enabled($this->business->id));
        $this->assertSame(0.0, Vat::rate($this->business->id));

        $order = $this->sell();

        $this->assertSame(0.0, (float) $order->tax, 'غيابُ المفتاح قُرئ تفعيلًا');
        $this->assertSame(100.0, (float) $order->total);
    }

    /** والصنفُ ذو النسبة الخاصّة لا يجبي وحده في متجرٍ لم يُفعّلها */
    public function test_a_product_with_its_own_rate_is_off_too(): void
    {
        $this->product->update(['tax' => 10]);

        $this->assertSame(0.0, Vat::rateFor($this->product->fresh(), $this->business->id));
        $this->assertSame(0.0, (float) $this->sell()->tax);
    }

    /** والورقةُ لا تحمل رقمًا ضريبيًّا لمتجرٍ لا يجبي */
    public function test_the_paper_carries_no_tax_number(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);

        $this->assertSame(['rate' => 0.0, 'number' => ''], Demo::vatSettings($this->business->id));
    }

    /** وافتراضُ المنصّة للنسبة يُطبَّق على من فعّل — لا يُفعّل من لم يُفعّل */
    public function test_the_platform_default_rate_does_not_switch_it_on(): void
    {
        Setting::create(['business_id' => null, 'key' => 'vat_rate', 'value' => '5']);

        $this->assertFalse(Vat::enabled($this->business->id));
        $this->assertSame(0.0, Vat::rate($this->business->id));
    }

    /* ============================ ما يُخزَّن ============================ */

    /**
     * كلُّ ما في الصفّ غيرُ `'1'` مطفأ — ولا يُخمَّن معنى نصٍّ غريب.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function notOn(): array
    {
        return [
            'null في الصفّ' => [null],
            'فارغ' => [''],
            'صفر' => ['0'],
            'false' => ['false'],
            'true نصًّا' => ['true'],
            'on' => ['on'],
            'yes' => ['yes'],
            'اثنان' => ['2'],
            'مسافةٌ قبله' => [' 1'],
            'مسافةٌ بعده' => ['1 '],
            'صفرٌ قبله' => ['01'],
        ];
    }

    #[DataProvider('notOn')]
    public function test_only_one_stored_value_switches_it_on(?string $stored): void
    {
        $this->set($stored);

        $this->assertFalse(Vat::enabled($this->business->id), 'قيمةٌ مخزّنةٌ غيرُ «1» قُرئت تفعيلًا: '.var_export($stored, true));
        $this->assertSame(0.0, Vat::rate($this->business->id));
    }

    public function test_an_explicit_on_still_charges(): void
    {
        $this->set('1');
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->assertTrue(Vat::enabled($this->business->id));
        $this->assertSame(5.0, (float) $this->sell()->tax);
    }

    /** ورقمٌ يكتبه سائقُ القاعدة رقمًا لا نصًّا يُقرأ كما يُقرأ النصّ */
    public function test_a_numeric_one_written_by_the_driver_is_on(): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => 1]);

        $this->assertTrue(Vat::enabled($this->business->id));
    }

    public function test_an_explicit_off_stays_off(): void
    {
        $this->set('0');

        $this->assertFalse(Vat::enabled($this->business->id));
        $this->assertSame(0.0, (float) $this->sell()->tax);
    }

    /* ============================ العزل ============================ */

    public function test_one_shops_switch_does_not_reach_another(): void
    {
        $registered = Business::create(['name' => 'متجر مسجَّل', 'type' => 'عام', 'status' => 'نشط']);
        $stopped = Business::create(['name' => 'متجر أطفأها', 'type' => 'عام', 'status' => 'نشط']);
        $this->set('1', $registered);
        $this->set('0', $stopped);

        $this->assertTrue(Vat::enabled($registered->id));
        $this->assertFalse(Vat::enabled($stopped->id));
        $this->assertFalse(Vat::enabled($this->business->id), 'تفعيلُ متجرٍ آخر وصل إلى من لم يُجب');
    }

    /* ============================ ما كُتب قبل اليوم ============================ */

    /**
     * طلبٌ جُبيت ضريبتُه قبل الإصلاح يبقى بضريبته — في صفّه وفي إقراره.
     *
     * الإصلاحُ يقول ما يُجبى من الآن، ولا يُعيد حسابَ ما جُبي.
     */
    public function test_tax_already_written_on_an_old_sale_is_not_touched(): void
    {
        $old = Order::create([
            'business_id' => $this->business->id, 'number' => 'S-OLD', 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'subtotal' => 100, 'discount' => 0, 'tax' => 5,
            'delivery_fee' => 0, 'total' => 105, 'ordered_at' => now(),
        ]);

        $this->sell();

        $this->assertSame(5.0, (float) $old->fresh()->tax);
        $this->assertSame(105.0, (float) $old->fresh()->total);
        $this->assertSame(5.0, round((float) ReportData::vat($this->business->id, ['range' => 'month'])['summary']['output'], 3));
    }

    /* ============================ الشاشة ============================ */

    public function test_the_setup_screen_shows_what_the_system_charges(): void
    {
        $shop = fn () => $this->get(route('admin.setup.index'))->assertOk()->viewData('page')['props']['shop'];

        $this->assertFalse($shop()['vat_enabled'], 'التهيئةُ تعرض «مسجَّلًا» لمن لم يُجب — وحفظُ الاسم يكتبها مفعّلة');

        $this->set('1');
        $this->assertTrue($shop()['vat_enabled']);
    }

    /** وحفظُ اسم المتجر وحده لا يكتب في الضريبة شيئًا */
    public function test_saving_the_shop_name_alone_leaves_vat_off(): void
    {
        $this->post(route('admin.settings.update'), ['shop_name' => 'ورود الخوير'])->assertSessionHasNoErrors();

        $this->assertFalse(Vat::enabled($this->business->id));
        $this->assertNull(Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->value('value'));
    }
}
