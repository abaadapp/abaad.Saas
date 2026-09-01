<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * الإعداد يُطبَّق حيث وُعِد، ولا يتسرّب إلى متجرٍ غير صاحبه.
 *
 * وشاشة الإعدادات أخطرُ شاشةٍ في اللوحة: مقبضٌ واحد فيها يقلب حساب كل
 * فاتورةٍ تُطبع بعده، وكلَّ رقمٍ في تقرير. فالمطلوب منها اثنان — أن يصل
 * الإعداد إلى موضعه، وألّا يصل إلى موضعٍ ليس له.
 */
class SettingsHoldTheirGroundTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 500, 'alert_qty' => 1, 'active' => true,
        ]);

        Demo::flushCurrency();
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }

    private function sell(array $extra = [])
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('s', true),
            ], $extra));
    }

    /* ============ العملة: ذاكرةٌ ساكنة لا تخدم جارًا ============ */

    public function test_one_shops_currency_never_reaches_another(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirUser = User::create([
            'business_id' => $neighbour->id, 'name' => 'الجار', 'email' => 'n@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Currency::create(['business_id' => $neighbour->id, 'code' => 'AED', 'name' => 'درهم', 'symbol' => 'د.إ', 'rate' => 1, 'is_base' => true, 'active' => true]);

        /*
         * ترتيبٌ بعينه يكشف العطب — وهو ترتيبُ عاملِ طابورٍ يعالج متجرين
         * بالتتابع: الأوّل يُنسّق مبلغًا (فتُملأ ذاكرة العرض باسمه)، ثمّ
         * يُقرأ للثاني أساسُه (فيتبدّل مفتاح الذاكرة إلى اسمه) — فتصير
         * ذاكرةُ العرض القديمة «صالحة» لصاحبٍ لم تُملأ له.
         */
        $this->actingAs($this->owner);
        Demo::displayCurrency();

        $this->actingAs($theirUser);
        Demo::baseCurrency();

        $this->assertSame(
            'AED',
            Demo::displayCurrency()['code'],
            'خُدم متجرٌ بعملة متجرٍ سبقه في العمليّة نفسها',
        );
    }

    public function test_the_shape_of_the_money_follows_the_shop_that_set_it(): void
    {
        $this->actingAs($this->owner);
        $this->set('currency', 'AED');
        $this->set('decimals', '2');
        Demo::flushCurrency();

        $this->assertStringContainsString('12.50', Demo::money(12.5));
        $this->assertSame(2, Demo::baseCurrency()['decimals']);
    }

    /* ============ حدود الباقة: لا يُلتفّ عليها بحذفٍ واستعادة ============ */

    private function cappedAtOneBranch(): void
    {
        $plan = Plan::create(['name' => 'أساسية', 'monthly_price' => 1, 'yearly_price' => 10, 'max_branches' => 1, 'max_employees' => 5, 'max_products' => 50]);
        $this->business->update(['plan_id' => $plan->id]);
    }

    public function test_a_second_branch_is_refused_at_the_cap(): void
    {
        $this->cappedAtOneBranch();

        $this->actingAs($this->owner)->post(route('admin.branches.store'), ['name' => 'صلالة'])
            ->assertSessionHasErrors('name');
    }

    public function test_the_cap_is_not_walked_around_by_deleting_and_restoring(): void
    {
        /*
         * السقفُ يُفرَض عند الإنشاء وحده، والاستعادة إنشاءٌ في أثره: يُحذف
         * الفرع، ويُفتح غيرُه في مكانه، ثمّ يُضغط «تراجع» — فيصير فرعان
         * لباقةٍ بيعت بفرعٍ واحد. والحدّ الذي يُلتفّ عليه بضغطتين ليس حدًّا.
         */
        $spare = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->cappedAtOneBranch();

        $this->actingAs($this->owner);
        $spare->delete();

        $this->post(route('admin.branches.restore', $spare->id))->assertSessionHasErrors();

        $this->assertSame(1, Branch::where('business_id', $this->business->id)->count());
    }

    /* ============ الفروع: اسمٌ يُميّز، ونصحٌ يُمكن العمل به ============ */

    public function test_two_branches_may_not_carry_the_same_name(): void
    {
        $this->actingAs($this->owner)->post(route('admin.branches.store'), ['name' => 'الرئيسي'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_branch_holding_stock_is_refused_with_advice_that_exists(): void
    {
        $other = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $other->id,
            'product_id' => $this->product->id, 'quantity' => 6,
        ]);

        $res = $this->actingAs($this->owner)->delete(route('admin.branches.destroy', $other->id));

        $res->assertSessionHasErrors('branch');
        $message = (string) session('errors')->first('branch');

        /*
         * والنصيحة تُسمّي بابًا موجودًا.
         *
         * كانت تقول «انقلها إلى فرعٍ آخر» ولا نقلَ في النظام، فيبحث التاجر
         * عن زرٍّ ليس موجودًا ثمّ يظنّ العطب في بصره. ثمّ صارت تدلّه على
         * حركتين يدويّتين — بابٌ أعرج لا وثيقةَ تربط طرفيه. واليوم للنقل
         * سندُه، فتُسمّيه الرسالة باسمه.
         */
        $this->assertStringContainsString('النقل بين الفروع', $message, 'النصيحة تُسمّي الشاشة التي تُنفَّذ منها');
        $this->assertStringContainsString($other->name, $message, 'ولا تقول «فرعٌ ما» — تسمّي الفرع وكميّته');
    }

    public function test_the_screen_the_advice_names_is_actually_there(): void
    {
        /*
         * حارسٌ على النصيحة نفسها — كان يقول «لا نقلَ بعد» ويسقط يوم يُبنى.
         * وقد بُني، فصار يقول العكس: نصيحةٌ تُحيل إلى مسارٍ محذوف أسوأ من
         * نصيحةٍ لا تُحيل إلى شيء، لأنّ الأولى تبدو صحيحةً حتى تُجرَّب.
         */
        $this->assertTrue(Route::has('admin.inventory.transfers'));

        $this->actingAs($this->owner)->get(route('admin.inventory.transfers'))->assertOk();
    }

    /* ============ الإعداد يصل إلى موضعه، ولا يكسر ما قبله ============ */

    public function test_turning_the_tax_off_empties_it_everywhere_at_once(): void
    {
        $this->set('vat_enabled', '0');

        $this->sell()->assertOk();
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(0.0, round((float) $order->tax, 3), 'ضريبةٌ جُبيت من متجرٍ أطفأها');

        $this->actingAs($this->owner);
        $summary = Demo::reportSummary('all');
        $this->assertSame(0.0, round((float) $summary['tax'], 3), 'وتُقرّ في التقرير وهي لم تُجبَ');
    }

    public function test_the_invoice_prefix_opens_a_new_run_without_colliding(): void
    {
        $this->set('inv_prefix', 'AAA-');
        $this->sell()->assertOk();
        $first = Order::latest('id')->firstOrFail()->number;

        $this->set('inv_prefix', 'BBB-');
        $this->set('inv_start', '500');
        $this->sell()->assertOk();
        $second = Order::latest('id')->firstOrFail()->number;

        $this->assertSame('AAA-000001', $first);
        $this->assertSame('BBB-000500', $second, 'البادئة الجديدة تبدأ من رقم التاجر لا من عدّاد القديمة');
        $this->assertSame(2, Order::distinct('number')->count('number'), 'رقمان متطابقان في دفترٍ واحد');
    }

    public function test_a_payment_method_the_merchant_switched_off_is_refused_at_the_till(): void
    {
        $this->set('pay_card', '0');

        $this->sell(['payment_method' => 'بطاقة'])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::count());
    }

    public function test_switching_every_method_off_does_not_stop_the_till(): void
    {
        // حجبُ وسيلةٍ إعدادٌ، وإيقافُ الصندوق عطل
        $this->set('pay_cash', '0');
        $this->set('pay_card', '0');
        $this->set('pay_transfer', '0');

        $this->sell()->assertOk();
    }

    public function test_a_prefix_with_a_wildcard_never_reaches_the_counter(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['inv_prefix' => 'IN%-'])
            ->assertSessionHasErrors('inv_prefix');
    }

    public function test_a_key_the_screen_does_not_offer_is_not_written(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'shop_name' => 'متجري', 'whatsapp_own_allowed' => '1', 'plan_id' => '99',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('settings', ['business_id' => $this->business->id, 'key' => 'whatsapp_own_allowed']);
        $this->assertDatabaseMissing('settings', ['business_id' => $this->business->id, 'key' => 'plan_id']);
    }

    /* ============ العزل ============ */

    public function test_a_neighbours_branch_cannot_be_switched_into(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعهم']);

        $this->actingAs($this->owner)->get(route('admin.branch.switch', $theirs->id))->assertNotFound();
    }

    public function test_settings_are_written_to_the_shop_that_asked(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['vat_rate' => '9'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('settings', ['business_id' => $neighbour->id, 'key' => 'vat_rate']);
        $this->assertDatabaseHas('settings', ['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '9']);
    }

    public function test_the_shop_profile_lands_in_the_business_row_not_in_settings(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'shop_name' => 'ورد مسقط', 'phone' => '90000000',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ورد مسقط', $this->business->fresh()->name);
        $this->assertDatabaseMissing('settings', ['business_id' => $this->business->id, 'key' => 'shop_name']);
    }
}
