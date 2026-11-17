<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomerPhone;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رقمُ العميل العُمانيّ ثماني خانات — قاعدةٌ واحدة في كلّ باب، وتنبيهٌ يقرأ ولا يكتب.
 *
 * الحالاتُ المشتركة مع الشاشة في `tests/fixtures/customer-phone-cases.json`
 * (ويقرؤها `tests/js/customer-phone.test.ts`).
 */
class AnOmaniCustomerPhoneIsEightDigitsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();
        config(['whatsapp.ribbon_businesses' => [], 'whatsapp.invoice_task_notices_businesses' => []]);

        $this->shop = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        foreach ([$this->shop, $this->other] as $b) {
            Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
            Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال',
                'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        }

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@phones.test',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private static function cases(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/customer-phone-cases.json')), true);
    }

    /** عميلٌ قديمٌ كما في القاعدة — بلا المرور بأيّ قاعدةٍ ولا حدث */
    private function legacy(Business $shop, string $name, ?string $phone, ?string $contact = null): int
    {
        return (int) DB::table('customers')->insertGetId([
            'business_id' => $shop->id, 'name' => $name, 'phone' => $phone, 'contact_phone' => $contact,
            'language' => 'ar', 'points' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function add(array $data)
    {
        return $this->actingAs($this->owner)->post(route('admin.customers.store'), $data + ['language' => 'ar', 'name' => 'سالم']);
    }

    /* ═════════════ القاعدة ═════════════ */

    public function test_every_shared_case_is_read_as_the_screen_reads_it(): void
    {
        foreach (self::cases()['problem'] as $c) {
            $label = $c['why'].' — '.json_encode($c['raw'], JSON_UNESCAPED_UNICODE);
            $this->assertSame($c['expect'], CustomerPhone::problem($c['raw']), 'المحفوظ: '.$label);
            $this->assertSame($c['strict'] ?? $c['expect'], CustomerPhone::problem($c['raw'], strict: true), 'الجديد: '.$label);
        }
        foreach (self::cases()['status'] as $c) {
            $this->assertSame($c['expect'], CustomerPhone::status($c['phone'], $c['contact'], $c['name']), json_encode($c, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_the_refusal_says_eight_digits_in_arabic_and_english(): void
    {
        $this->assertSame('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.', CustomerPhone::refusal('9123456'));
        $this->assertSame('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.', CustomerPhone::refusal('+968912345678'));
        $this->assertNull(CustomerPhone::refusal(''), 'الفراغ مقبولٌ كما كان');
        $this->assertNull(CustomerPhone::refusal('+971501234567'));

        app()->setLocale('en');
        $this->assertSame('Omani phone numbers must contain exactly 8 digits.', CustomerPhone::refusal('9123456'));
    }

    /* ═════════════ شاشة العملاء ═════════════ */

    public function test_adding_a_customer_takes_eight_digits_in_any_written_form_and_refuses_the_rest(): void
    {
        foreach (['91234567', '+968 9223 4567', '00968-93234567', '٩٤٢٣٤٥٦٧', '9523 4567'] as $i => $phone) {
            $this->add(['name' => 'عميل '.$i, 'phone' => $phone])->assertSessionHasNoErrors();
        }
        $this->assertSame('+968 9223 4567', Customer::where('name', 'عميل 1')->value('phone'), 'الرقمُ يُحفظ كما كُتب');

        foreach (['9123456' => 'سبع', '912345678' => 'تسع', '9123abcd' => 'حروف', '+968 968 96234567' => 'مفتاحٌ مكرّر'] as $phone => $why) {
            $this->add(['phone' => $phone])->assertSessionHasErrors('phone');
        }
        $this->add(['phone' => '9123456'])->assertSessionHasErrors(['phone' => 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.']);

        // والدوليُّ بمفتاح دولته لا يُفرض عليه الثماني
        $this->add(['name' => 'Ravi', 'phone' => '+971 50 123 4567'])->assertSessionHasNoErrors();
        $this->add(['name' => 'John', 'phone' => '+44 20 7946 0958'])->assertSessionHasNoErrors();

        $this->assertSame(7, Customer::where('business_id', $this->shop->id)->count());
    }

    public function test_an_old_short_number_does_not_stop_editing_the_name_but_a_new_number_is_checked(): void
    {
        $id = $this->legacy($this->shop, 'قديم', '9123456');
        $put = fn (array $d) => $this->actingAs($this->owner)
            ->put(route('admin.customers.update', $id), $d + ['name' => 'قديم', 'language' => 'ar', 'phone' => '9123456']);

        $put(['name' => 'قديم المحدَّث', 'address' => 'مسقط'])->assertSessionHasNoErrors();
        $this->assertSame('قديم المحدَّث', Customer::find($id)->name);
        $this->assertSame('9123456', Customer::find($id)->phone, 'لم يُغيَّر الرقمُ القديم');

        $put(['phone' => '912345'])->assertSessionHasErrors(['phone' => 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.']);
        $this->assertSame('9123456', Customer::find($id)->phone);

        $put(['phone' => '91234567'])->assertSessionHasNoErrors();
        $this->assertSame('91234567', Customer::find($id)->phone);
    }

    /* ═════════════ التنبيهُ والمرشِّح ═════════════ */

    public function test_the_notice_counts_only_this_shops_customers_and_falls_after_a_correction(): void
    {
        $short = $this->legacy($this->shop, 'ناقص', '9123456');
        $this->legacy($this->shop, 'زائد', '912345678');
        $this->legacy($this->shop, 'بلا رقم', null);
        $this->legacy($this->shop, 'حروف', '9123-abc');
        $this->legacy($this->shop, 'صالح', '92345678');
        $this->legacy($this->shop, 'صالح بمسافة', '9334 5678');
        $this->legacy($this->shop, 'دوليّ', '+971501234567');
        $this->legacy($this->shop, 'دوليٌّ قديمٌ بلا +', '971501234567');
        $this->legacy($this->shop, 'عميل نقدي', null);
        $this->legacy($this->other, 'جارٌ ناقص', '123');
        $this->legacy($this->other, 'جارٌ بلا رقم', null);

        $this->assertSame(4, CustomerPhone::reviewCount($this->shop->id));
        $this->assertSame(2, CustomerPhone::reviewCount($this->other->id));

        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertInertia(fn ($p) => $p->where('customerPhoneReview', 4));

        $page = $this->actingAs($this->owner)->get(route('admin.customers.index', ['missing' => 'phone']));
        $page->assertInertia(fn ($p) => $p->where('phoneReview', 4)->has('customers', 4));
        $rows = collect($page->viewData('page')['props']['customers'])->pluck('phone_status', 'name');
        $this->assertSame(['حروف' => 'invalid', 'بلا رقم' => 'missing', 'زائد' => 'invalid', 'ناقص' => 'incomplete'], $rows->all());
        $this->assertFalse($rows->has('جارٌ ناقص'), 'عميلُ متجرٍ آخر');

        // والقائمةُ كاملةً: الصالحُ بلا حالة
        $all = collect($this->actingAs($this->owner)->get(route('admin.customers.index'))->viewData('page')['props']['customers']);
        $this->assertNull($all->firstWhere('name', 'صالح بمسافة')['phone_status']);

        // التصحيحُ يُنقص التنبيهَ لحظتَه
        $this->actingAs($this->owner)->put(route('admin.customers.update', $short), [
            'name' => 'ناقص', 'language' => 'ar', 'phone' => '91234567',
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, CustomerPhone::reviewCount($this->shop->id));
    }

    public function test_the_notice_vanishes_when_nothing_needs_review_and_is_not_offered_to_whom_cannot_open_customers(): void
    {
        $this->legacy($this->shop, 'صالح', '92345678');
        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertInertia(fn ($p) => $p->where('customerPhoneReview', 0));

        $this->legacy($this->shop, 'ناقص', '9123');
        CustomerPhone::forget($this->shop->id);
        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'cashier@phones.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['dashboard', 'pos'],
        ]);
        $this->actingAs($cashier)->get(route('admin.dashboard'))
            ->assertInertia(fn ($p) => $p->where('customerPhoneReview', 0));
    }

    public function test_checking_never_writes_a_customer_row(): void
    {
        $id = $this->legacy($this->shop, 'ناقص', '٩١٢٣ ٤٥٦');
        $before = (array) DB::table('customers')->where('id', $id)->first();

        CustomerPhone::reviewCount($this->shop->id);
        $this->actingAs($this->owner)->get(route('admin.customers.index', ['missing' => 'phone']))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame($before, (array) DB::table('customers')->where('id', $id)->first());
    }

    public function test_thousands_of_customers_are_counted_in_one_query_without_loading_them(): void
    {
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['business_id' => $this->shop->id, 'name' => 'عميل '.$i, 'language' => 'ar', 'points' => 0,
                'phone' => $i % 100 === 0 ? '91234' : sprintf('9%07d', $i), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        DB::enableQueryLog();
        $started = microtime(true);
        $count = CustomerPhone::reviewCount($this->shop->id);
        $elapsed = microtime(true) - $started;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(30, $count);
        $this->assertLessThanOrEqual(2, $queries, 'استعلامٌ واحد للعدّ (وقراءةُ الحفظ)');
        $this->assertLessThan(2.0, $elapsed);

        // والمرشِّحُ يرقّم في القاعدة
        $this->actingAs($this->owner)->get(route('admin.customers.index', ['missing' => 'phone']))
            ->assertInertia(fn ($p) => $p->has('customers', 10)->where('pagination.total', 30));
    }

    /* ═════════════ الرقمُ اختياريّ، والفارغُ في التنبيه ═════════════ */

    public function test_an_empty_phone_is_saved_from_every_door_and_listed_as_missing(): void
    {
        $this->add(['name' => 'بلا رقم من الشاشة', 'phone' => ''])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->postJson(route('pos.customers.store'), ['name' => 'بلا رقم من الصندوق', 'language' => 'ar'])->assertOk();
        $this->actingAs($this->owner)->post('/admin/customer-invoices/customers', ['name' => 'بلا رقم من الفاتورة', 'language' => 'ar'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Customer::where('business_id', $this->shop->id)->whereNull('phone')->count()
            + Customer::where('business_id', $this->shop->id)->where('phone', '')->count());
        $this->assertSame(3, CustomerPhone::reviewCount($this->shop->id));

        $rows = collect($this->actingAs($this->owner)->get(route('admin.customers.index', ['missing' => 'phone']))
            ->viewData('page')['props']['customers'])->pluck('phone_status', 'name');
        $this->assertSame(['missing'], $rows->unique()->values()->all());
        $this->assertCount(3, $rows);
    }

    public function test_a_customer_without_a_phone_or_with_an_old_short_one_still_buys_at_the_till(): void
    {
        Ledger::seedChart($this->shop->id);
        $branch = Branch::where('business_id', $this->shop->id)->firstOrFail();
        $product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        foreach ([null, '9123'] as $i => $phone) {
            $customer = Customer::find($this->legacy($this->shop, 'زبون '.$i, $phone));

            $this->actingAs($this->owner)->withSession(['current_branch' => $branch->id])->postJson(route('pos.checkout'), [
                'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => 1]],
                'payment_method' => 'نقدي',
                'customer_id' => $customer->id,
                'client_uuid' => 'phone-sale-'.$i,
            ])->assertOk();

            $this->assertSame(1, Order::where('customer_id', $customer->id)->count(), 'البيعُ لا يُمنع برقمٍ مفقودٍ أو قديم');
            $this->assertSame($phone, $customer->fresh()->phone);
        }
    }

    /* ═════════════ بلا حقل دولة: لا يمرّ عُمانيٌّ زائدٌ على أنّه دوليّ ═════════════ */

    public function test_a_long_number_without_a_country_mark_is_refused_new_but_an_old_one_is_not_flagged(): void
    {
        $this->add(['phone' => '9123456789'])->assertSessionHasErrors([
            'phone' => 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام. وإن كان الرقم لدولةٍ أخرى فاكتب + أو 00 قبل مفتاح دولته، مثل +971501234567.',
        ]);
        $this->add(['phone' => '971501234567'])->assertSessionHasErrors('phone');
        $this->add(['phone' => '+91234567'])->assertSessionHasErrors([
            'phone' => 'هذا رقمٌ عُمانيٌّ بلا مفتاحه — اكتبه +968 ثمّ الأرقام الثمانية، أو اكتب الرقم الدولي كاملًا بمفتاح دولته.',
        ]);
        $this->add(['phone' => '0091234567'])->assertSessionHasErrors('phone');

        // والصحيحُ من الدول الأخرى يُقبل بعلامته — ومعه 968 بلا +
        foreach (['+971 50 123 4567', '00966501234567', '+973 3612 3456', '+44 20 7946 0958', '+50012345', '96893234567'] as $i => $phone) {
            $this->add(['name' => 'دوليّ '.$i, 'phone' => $phone])->assertSessionHasNoErrors();
        }

        // والمحفوظُ قبلُ بلا علامة لا يُخطَّأ بلا يقين، والعُمانيُّ بلا مفتاحه يُعدّ
        $this->legacy($this->shop, 'دوليٌّ قديم', '971501234567');
        $this->legacy($this->shop, 'عُمانيٌّ بلا مفتاحه', '+91234567');
        $this->legacy($this->shop, 'محلّيٌّ أجنبيّ', '0501234567');
        $this->assertSame(2, CustomerPhone::reviewCount($this->shop->id));
    }

    /* ═════════════ الأبوابُ الأخرى ═════════════ */

    public function test_the_till_and_the_invoice_screen_refuse_a_short_number(): void
    {
        $this->actingAs($this->owner)->postJson(route('pos.customers.store'), [
            'name' => 'زبون', 'phone' => '9123456', 'language' => 'ar',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone' => 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.']);

        $this->actingAs($this->owner)->postJson(route('pos.customers.store'), [
            'name' => 'زبون', 'phone' => '+968 9123 4567', 'language' => 'ar',
        ])->assertOk();

        $this->actingAs($this->owner)->post('/admin/customer-invoices/customers', [
            'name' => 'شركة', 'phone' => '912345678', 'language' => 'ar',
        ])->assertSessionHasErrors(['phone' => 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.']);

        $this->actingAs($this->owner)->post('/admin/customer-invoices/customers', [
            'name' => 'شركة', 'phone' => '92345678', 'language' => 'ar',
        ])->assertSessionHasNoErrors();
    }

    public function test_import_skips_a_new_short_number_and_still_updates_an_old_customer_by_his_number(): void
    {
        $this->legacy($this->shop, 'قديم', '9123456');

        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "الاسم,الهاتف,العنوان,اللغة\nجديد ناقص,912345,مسقط,العربية\nجديد صالح,92345678,صحار,العربية\nقديم,9123456,نزوى,العربية\n");
        $this->actingAs($this->owner)->post(route('admin.customers.import.upload'), [
            'file' => new UploadedFile($path, 'عملاء.csv', 'text/csv', null, true),
        ])->assertRedirect();
        $this->actingAs($this->owner)->post(route('admin.customers.import.confirm'))->assertRedirect();

        $this->assertFalse(Customer::where('name', 'جديد ناقص')->exists());
        $this->assertTrue(Customer::where('name', 'جديد صالح')->exists());
        $this->assertSame('نزوى', Customer::where('name', 'قديم')->value('address'), 'رقمُه القديم لم يمنع تحديثه');
        $this->assertSame('9123456', Customer::where('name', 'قديم')->value('phone'));
    }

    public function test_a_shop_registered_tomorrow_gets_the_rule_with_no_setting(): void
    {
        $fresh = Business::create(['name' => 'متجر جديد', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $fresh->id, 'name' => 'الرئيسي']);
        $owner = User::create([
            'business_id' => $fresh->id, 'name' => 'صاحب الجديد', 'email' => 'fresh@phones.test',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->assertSame(0, CustomerPhone::reviewCount($fresh->id));
        $this->actingAs($owner)->post(route('admin.customers.store'), ['name' => 'أوّل عميل', 'phone' => '123', 'language' => 'ar'])
            ->assertSessionHasErrors('phone');
        $this->actingAs($owner)->post(route('admin.customers.store'), ['name' => 'أوّل عميل', 'phone' => '91234567', 'language' => 'ar'])
            ->assertSessionHasNoErrors();
    }
}
