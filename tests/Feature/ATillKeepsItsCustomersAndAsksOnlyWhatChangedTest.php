<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\PosDevice;
use App\Models\Setting;
use App\Models\User;
use App\Support\Pos\TillCustomers;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReadsTheTillsCustomers;
use Tests\TestCase;

/**
 * زبائنُ الصندوق: لا يأتون مع الصفحة، ويصل الجهازَ ما تغيّر منهم وحده.
 *
 * - الصفحةُ بلا قائمة، ومعها متجرُ نسختها (`customersScope`).
 * - الصفُّ ما يقرؤه الصندوق — لا بريدَ ولا رقمَ ضريبيًّا ولا عنوانَ ولا سجلَّ مشتريات.
 * - جهازٌ يعرف البصمات لا يصله شيء؛ وعميلٌ معدَّل أو جديد أو محذوف يُرجع دلوَه وحده.
 * - والسياقُ يتغيّر بإعداد المتجر والصفُّ لم يُلمس — فالفرقُ بالمحتوى لا بالتاريخ.
 * - ومتجرٌ آخر لا يُرى: لا في المزامنة ولا في سؤال المختار.
 */
class ATillKeepsItsCustomersAndAsksOnlyWhatChangedTest extends TestCase
{
    use ReadsTheTillsCustomers;
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@till.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@till.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $raw = str_repeat('t', 64);
        $device = PosDevice::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id, 'name' => 'صندوق',
            'token_hash' => hash('sha256', $raw), 'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]);
        $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '9'.random_int(1000000, 9999999),
        ], $over));
    }

    private function sync(array $have = []): array
    {
        return $this->actingAs($this->cashier)
            ->postJson(route('pos.tillCustomers.sync'), ['have' => (object) $have])
            ->assertOk()->json();
    }

    public function test_the_till_page_carries_no_customer_list_only_whose_copy_it_is(): void
    {
        $this->customer();

        $page = $this->actingAs($this->cashier)->get(route('pos.index'))->assertOk()->viewData('page');

        $this->assertSame('Pos/Index', $page['component']);
        $this->assertArrayNotHasKey('customers', $page['props'], 'عادت القائمةُ كاملةً مع الصفحة');
        $this->assertSame($this->shop->id, $page['props']['customersScope']);
    }

    public function test_a_row_carries_what_the_till_reads_and_nothing_of_the_record(): void
    {
        // ‏والملاحظةُ مُطفأةٌ عن الصندوق في هذا المتجر — فلا تصله ولا يصله سياقٌ فارغ
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'show_customer_notes_in_pos'], ['value' => '0']);
        $this->customer([
            'name' => 'ليلى', 'name_en' => 'Layla', 'email' => 'l@x.om', 'tax_number' => 'OM123', 'address' => 'مسقط',
            'notes' => 'سرّ داخليّ', 'language' => 'en', 'points' => 40,
        ]);

        $data = $this->sync();
        $row = collect($data['rows'])->flatten(1)->first();

        $this->assertSame($this->shop->id, $data['business']);
        $this->assertSame(['id', 'name', 'name_en', 'phone', 'points', 'language'], array_keys($row));
        $this->assertSame('Layla', $row['name_en']);
        $this->assertSame(40, $row['points']);
        $this->assertSame('en', $row['language']);
        $this->assertStringNotContainsString('سرّ داخليّ', json_encode($data, JSON_UNESCAPED_UNICODE), 'ملاحظةٌ أُطفئت عن الصندوق وصلته');
        $this->assertStringNotContainsString('OM123', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_device_that_knows_every_bucket_gets_nothing_again(): void
    {
        foreach (range(1, 30) as $i) {
            $this->customer(['name' => 'عميل '.$i]);
        }

        $first = $this->sync();
        $this->assertSame(30, $first['count']);
        $this->assertCount(30, collect($first['rows'])->flatten(1));

        $again = $this->sync($first['buckets']);
        $this->assertSame([], $again['rows'], 'نُزّلت القائمةُ ثانيةً ولم يتغيّر شيء');
        $this->assertSame($first['buckets'], $again['buckets']);
    }

    public function test_a_changed_new_or_deleted_customer_returns_its_own_bucket_only(): void
    {
        $all = collect(range(1, 40))->map(fn ($i) => $this->customer(['name' => 'عميل '.$i]));
        $first = $this->sync();

        // ‏نقاطٌ تُكتب بعد بيعة (increment كما يكتبها الولاء)
        $paid = $all[7];
        $paid->increment('points', 15);
        $changed = $this->sync($first['buckets']);
        $this->assertSame([TillCustomers::bucketOf($paid->id)], array_map('intval', array_keys($changed['rows'])));
        $this->assertSame(15, collect($changed['rows'][TillCustomers::bucketOf($paid->id)])->firstWhere('id', $paid->id)['points']);

        // ‏وعميلٌ جديد يصل في دلوه
        $new = $this->customer(['name' => 'جديد']);
        $added = $this->sync($changed['buckets']);
        $this->assertContains($new->id, collect($added['rows'])->flatten(1)->pluck('id'));

        // ‏ومحذوفٌ يغيب: دلوُه يعود بدونه، أو يغيب الدلوُ كلُّه فيُمحى من الجهاز
        $gone = $all[3];
        $gone->delete();
        $after = $this->sync($added['buckets']);
        $bucket = TillCustomers::bucketOf($gone->id);
        $this->assertNotContains($gone->id, collect($after['rows'])->flatten(1)->pluck('id'));
        $this->assertTrue(
            ! array_key_exists($bucket, $after['buckets']) || array_key_exists($bucket, $after['rows']),
            'المحذوفُ باقٍ في الجهاز: دلوُه لم يُرجَع ولم يُمحَ',
        );
    }

    public function test_a_setting_that_changes_what_the_cashier_is_told_reaches_the_device(): void
    {
        $c = $this->customer(['notes' => 'يفضّل واتساب']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'show_customer_notes_in_pos'], ['value' => '0']);
        $first = $this->sync();
        $this->assertArrayNotHasKey('context', collect($first['rows'])->flatten(1)->firstWhere('id', $c->id));

        // ‏الصفُّ لم يُلمس — والإعدادُ فتح الملاحظة
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'show_customer_notes_in_pos'], ['value' => '1']);
        $after = $this->sync($first['buckets']);

        $row = collect($after['rows'])->flatten(1)->firstWhere('id', $c->id);
        $this->assertNotNull($row, 'تغيّر ما يُقال للكاشير ولم يصل الجهاز');
        $this->assertSame('يفضّل واتساب', $row['context']['note']);
    }

    public function test_another_shop_is_never_seen_in_the_sync_or_the_chosen_customer(): void
    {
        $mine = $this->customer(['name' => 'زبوني']);
        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'زبونهم', 'phone' => '91234567', 'notes' => 'سرّهم', 'alert_type' => 'block', 'alert_reason' => 'سرّهم']);

        $data = $this->sync();
        $ids = collect($data['rows'])->flatten(1)->pluck('id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertStringNotContainsString('سرّهم', json_encode($data, JSON_UNESCAPED_UNICODE));

        // ‏وبصماتُ جهازٍ مزوّرة لا تكشف شيئًا: الجوابُ متجرُ الجلسة وحده
        $forged = $this->sync([TillCustomers::bucketOf($theirs->id) => 'x']);
        $this->assertNotContains($theirs->id, collect($forged['rows'])->flatten(1)->pluck('id'));

        $this->actingAs($this->cashier)->getJson(route('pos.tillCustomers.show', $theirs->id))->assertNotFound();
        $this->actingAs($this->cashier)->getJson(route('pos.tillCustomers.show', $mine->id))
            ->assertOk()->assertJsonPath('customer.id', $mine->id)->assertJsonPath('customer.name', 'زبوني');
    }

    public function test_the_chosen_customer_is_asked_fresh_and_a_deleted_one_is_gone(): void
    {
        $c = $this->customer(['points' => 10]);
        $c->increment('points', 5);

        $this->actingAs($this->cashier)->getJson(route('pos.tillCustomers.show', $c->id))
            ->assertOk()->assertJsonPath('customer.points', 15);

        $c->delete();
        $this->actingAs($this->cashier)->getJson(route('pos.tillCustomers.show', $c->id))->assertNotFound();
    }

    public function test_only_someone_at_this_till_may_ask(): void
    {
        $c = $this->customer();

        $this->postJson(route('pos.tillCustomers.sync'), ['have' => (object) []])->assertUnauthorized();
        $this->getJson(route('pos.tillCustomers.show', $c->id))->assertUnauthorized();

        // ‏وموظّفٌ لا يملك نقطة البيع لا يقرأ زبائنها
        $this->cashier->forceFill(['permissions' => ['orders']])->save();
        $this->actingAs($this->cashier->fresh())->postJson(route('pos.tillCustomers.sync'), ['have' => (object) []])->assertForbidden();
    }

    public function test_a_shop_of_thousands_is_read_in_a_fixed_number_of_queries(): void
    {
        $rows = [];
        foreach (range(1, 1200) as $i) {
            $rows[] = ['business_id' => $this->shop->id, 'name' => 'عميل '.$i, 'phone' => '9'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'notes' => $i % 10 === 0 ? 'ملاحظة' : null, 'birth_day' => $i % 7 === 0 ? 5 : null, 'birth_month' => $i % 7 === 0 ? 6 : null,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $this->actingAs($this->cashier);
        DB::enableQueryLog();
        $data = $this->postJson(route('pos.tillCustomers.sync'), ['have' => (object) []])->assertOk()->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1200, $data['count']);
        $this->assertLessThan(60, $queries, "{$queries} استعلامًا لمزامنة ١٢٠٠ عميل — عاد سؤالٌ لكلّ عميل");
    }

    public function test_the_pos_customers_screen_sends_its_columns_only(): void
    {
        $this->customer(['email' => 'l@x.om', 'tax_number' => 'OM999', 'address' => 'صحار']);

        $rows = $this->actingAs($this->owner)->get(route('pos.customers'))->assertOk()->viewData('page')['props']['customers'];

        $this->assertSame(
            ['id', 'name', 'name_en', 'label', 'phone', 'email', 'orders', 'total_spent', 'last_invoice', 'last_invoice_total', 'points', 'context'],
            array_keys($rows[0]),
        );
        $this->assertStringNotContainsString('OM999', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }
}
