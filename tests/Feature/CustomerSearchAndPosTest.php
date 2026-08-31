<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بحث العملاء، والتحقّق من المدخلات، والطريق بين العميل والفاتورة.
 *
 * وأخطر ما هنا لا يظهر عندنا إطلاقًا: الاختبارات تجري على SQLite والإنتاج
 * على PostgreSQL، والفرق بينهما أنّ الثانية تفرّق بين `Ahmed` و`ahmed`.
 */
class CustomerSearchAndPosTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001',
        ], $over));
    }

    /** @return list<string> أسماء ما تعرضه الشاشة */
    private function found(string $term): array
    {
        $names = [];
        $this->get(route('admin.customers.index', ['q' => $term]))
            ->assertInertia(function ($p) use (&$names) {
                $names = array_column($p->toArray()['props']['customers'], 'name');
            });

        return $names;
    }

    /* --------------------------- البحث --------------------------- */

    /**
     * الحارس الحقيقيّ للحرف الكبير — لأنّ SQLite لا تفرّق فلا تكشفه.
     *
     * والإنتاج على PostgreSQL: `'Ahmed' LIKE '%ahmed%'` لا يعيد شيئًا،
     * و`ILIKE` يعيده. فمن بحث عن عميلٍ يعرف أنّه موجود لا يجده فيضيفه
     * ثانيةً — سجلّان لشخصٍ واحد ونقاطُ ولائه بينهما.
     */
    public function test_the_operator_is_case_blind_on_the_engine_production_runs(): void
    {
        $this->assertSame('ilike', Search::operatorFor('pgsql'));
        $this->assertSame('like', Search::operatorFor('sqlite'));
        $this->assertSame('like', Search::operatorFor('mysql'));
    }

    /**
     * وحارسٌ على المصدر — لأنّ الاختبار وحده لا يكشفه.
     *
     * الاختبارات تجري على SQLite، وهي لا تفرّق بين الحرفين. فبحثٌ مكتوبٌ
     * بـ`like` صريحةً يمرّ هنا أخضرَ ويصل إلى الإنتاج أعمى. ولا سبيل إلى
     * كشفه إلا بقراءة المصدر: لا مُعامِلَ مكتوبًا بيده في موضع القرار.
     */
    public function test_no_screen_filter_writes_the_operator_by_hand(): void
    {
        $source = file_get_contents(base_path('app/Support/ListFilters.php'));

        $this->assertStringNotContainsString("'like'", $source);
        $this->assertStringContainsString('Search::like()', $source);
    }

    public function test_search_finds_by_the_english_name(): void
    {
        $this->customer(['name' => 'سالم', 'name_en' => 'Salim']);
        $this->customer(['name' => 'مريم', 'phone' => '90000002']);

        $this->assertSame(['سالم'], $this->found('Salim'));
    }

    public function test_search_finds_by_phone_and_by_email(): void
    {
        $this->customer(['name' => 'سالم', 'phone' => '91234567', 'email' => 'salim@x.om']);
        $this->customer(['name' => 'مريم', 'phone' => '90000002']);

        $this->assertSame(['سالم'], $this->found('1234'));
        $this->assertSame(['سالم'], $this->found('salim@x.om'));
    }

    public function test_search_does_not_reach_the_neighbours_shop(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'status' => 'نشط']);
        Customer::create(['business_id' => $other->id, 'name' => 'سالم الغريب', 'phone' => '99999999']);
        $this->customer(['name' => 'سالم']);

        $this->assertSame(['سالم'], $this->found('سالم'));
    }

    public function test_a_deleted_customer_is_not_in_the_results(): void
    {
        $this->customer(['name' => 'سالم'])->delete();

        $this->assertSame([], $this->found('سالم'));
    }

    /* ---------------------- التحقّق من المدخلات ---------------------- */

    public function test_a_branch_of_another_shop_is_refused_not_stored(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);

        $this->post(route('admin.customers.store'), [
            'name' => 'سالم', 'phone' => '90000009', 'branch_id' => $theirBranch->id,
        ])->assertSessionHasNoErrors();

        // فرعٌ ليس من فروع المتجر لا يُكتب — ولو كُتب لظهر عميلٌ تحت فرعٍ لا يملكه
        $this->assertNull(Customer::where('phone', '90000009')->value('branch_id'));
    }

    public function test_the_branch_of_this_shop_is_kept(): void
    {
        $this->post(route('admin.customers.store'), [
            'name' => 'سالم', 'phone' => '90000010', 'branch_id' => $this->branch->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->branch->id, Customer::where('phone', '90000010')->value('branch_id'));
    }

    public function test_a_malformed_email_is_refused(): void
    {
        $this->post(route('admin.customers.store'), ['name' => 'سالم', 'email' => 'ليس بريدًا'])
            ->assertSessionHasErrors('email');
    }

    public function test_an_email_longer_than_the_column_is_refused_not_thrown(): void
    {
        $long = str_repeat('a', 300).'@x.om';

        $this->post(route('admin.customers.store'), ['name' => 'سالم', 'email' => $long])
            ->assertSessionHasErrors('email');
    }

    public function test_a_nameless_customer_is_refused(): void
    {
        $this->post(route('admin.customers.store'), ['phone' => '90000011'])
            ->assertSessionHasErrors('name');
    }

    /* ------------------ العميل عند الصندوق ------------------ */

    private function sell(array $extra = [])
    {
        session(['current_branch' => $this->branch->id]);
        $this->openShiftFor($this->business->id, $this->branch->id);

        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ], $extra));
    }

    /**
     * والمحذوف لا يُلتقط عند الصندوق — لا بهاتفه ولا باسمه.
     *
     * ولو التُقط لأضيفت نقاط بيعةٍ اليوم إلى صفٍّ مخفيّ لا تعرضه شاشة، ولظهرت
     * فاتورةٌ جديدة معلّقةً بعميلٍ حذفه صاحب المتجر عمدًا.
     */
    public function test_a_deleted_customer_is_never_picked_up_by_the_till(): void
    {
        $gone = $this->customer(['name' => 'أحمد', 'phone' => '93333333', 'points' => 500]);
        $gone->delete();

        $this->sell(['customer' => 'أحمد', 'customer_phone' => '93333333'])->assertOk();

        $order = Order::where('is_held', false)->firstOrFail();
        $this->assertNull($order->customer_id);
        $this->assertSame(500, (int) $gone->fresh()->points);
    }

    public function test_the_till_refuses_a_number_held_by_a_deleted_customer(): void
    {
        $this->customer(['name' => 'أحمد', 'phone' => '94444444'])->delete();

        $this->post(route('pos.customers.store'), ['name' => 'أحمد', 'phone' => '94444444'])
            ->assertSessionHasErrors('phone');
    }

    public function test_the_till_still_adds_a_free_number(): void
    {
        $this->post(route('pos.customers.store'), ['name' => 'مريم', 'phone' => '95555555'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['phone' => '95555555', 'business_id' => $this->business->id]);
    }
}
