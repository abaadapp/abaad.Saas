<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Customers;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فاتورةُ العميل تُوجد برقم هاتفه — كما كُتب أو بأيّ شكلٍ آخر.
 *
 * كانت قائمةُ «فواتير العملاء» تبحث برقم الفاتورة وأمر الشراء واسم العميل،
 * ومنتقي العميل في الفاتورة الجديدة بالاسم وحده. ومن يتّصل يُعرف برقمه: «فاتورة
 * 9123 4567 كم بقي عليها؟». والرقمُ مخزَّنٌ كما كتبه صاحبه («+968 9123 4567»،
 * «92223333»، «968-2444-5555»)، فيُطابَق مطبَّعًا (`Customers::phoneKeys`)،
 * كاملًا أو جزءًا، بمفتاح عُمان أو بدونه، بالهاتف أو رقم التواصل — ومن عملاء
 * هذا المتجر وحده.
 */
class AnInvoiceIsFoundByItsCustomersPhoneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $ministry;

    private Customer $twin;

    private Customer $company;

    /** @var array<string, CustomerInvoice> */
    private array $inv = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'Asia/Muscat'));
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->business('ورود الفواتير', 'owner@invoice-phone.om');
        [$other, $otherOwner] = $this->business('متجر الجار', 'other@invoice-phone.om');

        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'name_en' => 'Ministry of Culture', 'phone' => '+968 9123 4567']);
        // اسمٌ مثلُه برقمٍ آخر — الرقمُ يفرّقهما
        $this->twin = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '93330000']);
        $this->company = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة النور', 'phone' => '92223333', 'contact_phone' => '968-2444-5555']);
        // والرقمُ نفسُه في متجر الجار — لا تُرى فاتورتُه من هنا
        $stranger = Customer::create(['business_id' => $other->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567']);

        $this->inv['unpaid'] = $this->invoice($this->ministry, 100, 'PO-77');
        $this->inv['partial'] = $this->invoice($this->ministry, 50);
        CustomerPayments::record($this->shop->id, $this->ministry, 20,
            ['method' => 'نقدي', 'occurred_at' => today()->toDateString(), 'auto_allocate' => false], [$this->inv['partial']->id => 20], $this->owner->id);
        $this->inv['paid'] = $this->invoice($this->ministry, 30);
        CustomerPayments::record($this->shop->id, $this->ministry, 30,
            ['method' => 'نقدي', 'occurred_at' => today()->toDateString(), 'auto_allocate' => false], [$this->inv['paid']->id => 30], $this->owner->id);
        $this->inv['twin'] = $this->invoice($this->twin, 10);
        $this->inv['company'] = $this->invoice($this->company, 40);
        $this->inv['stranger'] = $this->invoice($stranger, 70, null, $other, $otherOwner);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'صاحب '.$name, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function invoice(Customer $customer, float $total, ?string $po = null, ?Business $shop = null, ?User $by = null): CustomerInvoice
    {
        $shop ??= $this->shop;
        $by ??= $this->owner;
        $draft = CustomerInvoices::create($shop->id, $customer, ['issued_at' => today()->toDateString(), 'po_number' => $po], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $total, 'tax_rate' => 0],
        ], $by->id);

        return CustomerInvoices::issue($draft, $by->id)->fresh();
    }

    /** @return array{ids: list<int>, props: array} */
    private function search(array $query): array
    {
        $props = $this->get(route('admin.customerInvoices.index', $query))->assertOk()->viewData('page')['props'];

        return ['ids' => collect($props['invoices'])->pluck('id')->sort()->values()->all(), 'props' => $props];
    }

    /** @param list<string> $keys */
    private function ids(array $keys): array
    {
        return collect($keys)->map(fn ($k) => $this->inv[$k]->id)->sort()->values()->all();
    }

    private const MINISTRY = ['unpaid', 'partial', 'paid'];

    /* ═════════════ الاسم ═════════════ */

    public function test_the_arabic_name_finds_both_customers_who_share_it_and_the_phone_tells_them_apart(): void
    {
        $found = $this->search(['q' => 'وزارة الثقافة']);

        $this->assertSame($this->ids([...self::MINISTRY, 'twin']), $found['ids']);
        $phones = collect($found['props']['invoices'])->pluck('customer_phone', 'id');
        $this->assertSame('+968 9123 4567', $phones[$this->inv['unpaid']->id]);
        $this->assertSame('93330000', $phones[$this->inv['twin']->id]);
    }

    public function test_the_english_name_finds_its_invoices_in_any_case(): void
    {
        $this->assertSame($this->ids(self::MINISTRY), $this->search(['q' => 'ministry of'])['ids']);
    }

    /* ═════════════ الهاتف ═════════════ */

    public function test_the_phone_finds_its_invoices_however_it_is_written(): void
    {
        foreach (['91234567', '+968 9123 4567', '+96891234567', '00968 9123-4567', '9123 4567', '٩١٢٣٤٥٦٧'] as $typed) {
            $this->assertSame($this->ids(self::MINISTRY), $this->search(['q' => $typed])['ids'], "«{$typed}»");
        }
    }

    public function test_part_of_the_phone_is_enough(): void
    {
        $this->assertSame($this->ids(self::MINISTRY), $this->search(['q' => '4567'])['ids']);
        $this->assertSame($this->ids(self::MINISTRY), $this->search(['q' => '9123'])['ids']);
    }

    public function test_the_contact_phone_finds_the_company_too(): void
    {
        foreach (['24445555', '+968 2444 5555', '2444'] as $typed) {
            $this->assertSame($this->ids(['company']), $this->search(['q' => $typed])['ids'], "«{$typed}»");
        }

        $this->assertSame('92223333', collect($this->search(['q' => '2444'])['props']['invoices'])->first()['customer_phone']);
    }

    public function test_another_shops_customer_with_the_same_phone_is_never_shown(): void
    {
        $this->assertNotContains($this->inv['stranger']->id, $this->search(['q' => '91234567'])['ids']);
        $this->assertNotContains($this->inv['stranger']->id, $this->search(['q' => 'وزارة'])['ids']);
    }

    /* ═════════════ ما كان يُبحث به يبقى ═════════════ */

    public function test_the_invoice_number_and_purchase_order_still_find_and_are_not_read_as_phones(): void
    {
        $this->assertSame($this->ids(['unpaid']), $this->search(['q' => $this->inv['unpaid']->number])['ids']);
        $this->assertSame($this->ids(['unpaid']), $this->search(['q' => 'PO-77'])['ids']);
        $this->assertNull(Customers::phoneNeedle((string) $this->inv['unpaid']->number), 'رقمُ فاتورةٍ قُرئ هاتفًا');
    }

    /* ═════════════ الفلاتر والصفحات ═════════════ */

    public function test_a_phone_search_keeps_the_payment_state_and_status_filters(): void
    {
        $this->assertSame($this->ids(['partial']), $this->search(['q' => '91234567', 'state' => 'مدفوعة جزئيًا'])['ids']);
        $this->assertSame($this->ids(['paid']), $this->search(['q' => '91234567', 'state' => 'مدفوعة'])['ids']);
        $this->assertSame($this->ids(['unpaid']), $this->search(['q' => '91234567', 'state' => 'غير مدفوعة'])['ids']);
        $this->assertSame([], $this->search(['q' => '91234567', 'status' => CustomerInvoice::CANCELLED])['ids']);
        $this->assertSame($this->ids(self::MINISTRY), $this->search(['q' => '91234567', 'customer_id' => $this->ministry->id])['ids']);
        $this->assertSame([], $this->search(['q' => '91234567', 'customer_id' => $this->company->id])['ids']);
    }

    public function test_a_phone_search_pages_through_every_matching_invoice(): void
    {
        // أصغرُ صفحةٍ عشرون (`PER_PAGE`) — فثلاثٌ وعشرون فاتورةً صفحتان
        for ($i = 0; $i < 20; $i++) {
            $this->invoice($this->ministry, 5 + $i);
        }

        $first = $this->search(['q' => '+968 9123 4567', 'per_page' => 20]);
        $this->assertCount(20, $first['ids']);
        $this->assertSame(23, $first['props']['pagination']['total']);
        $this->assertSame('+968 9123 4567', $first['props']['filters']['q']);

        $second = $this->search(['q' => '+968 9123 4567', 'per_page' => 20, 'page' => 2]);
        $this->assertCount(3, $second['ids']);
        $this->assertSame([], array_intersect($first['ids'], $second['ids']));
    }

    /* ═════════════ الفاتورة الجديدة ═════════════ */

    public function test_the_new_invoice_screen_sends_each_customers_phone_keys_from_its_shop_only_and_creates_no_one(): void
    {
        $before = Customer::count();
        $customers = collect($this->get(route('admin.customerInvoices.create'))->assertOk()->viewData('page')['props']['customers']);

        $this->assertSame(3, $customers->count(), 'عميلُ متجرٍ آخر في منتقي الفاتورة');
        $ministry = $customers->firstWhere('id', $this->ministry->id);
        $this->assertSame('Ministry of Culture', $ministry['name_en']);
        $this->assertContains('96891234567', $ministry['phone_keys']);
        $this->assertContains('96824445555', $customers->firstWhere('id', $this->company->id)['phone_keys'], 'رقمُ التواصل غاب عن المفاتيح');
        // وبياناتُه التي تملأ الورقة كما كانت
        $this->assertSame('+968 9123 4567', $ministry['phone']);

        $this->search(['q' => '99998888']);
        $this->assertSame($before, Customer::count(), 'البحثُ أنشأ عميلًا');
    }

    /* ═════════════ ما يُعدّ رقمًا ═════════════ */

    public function test_what_counts_as_a_phone_to_search_by(): void
    {
        $this->assertSame('96891234567', Customers::phoneNeedle('+968 9123-4567'));
        $this->assertSame('96891234567', Customers::phoneNeedle('00968 91234567'));
        $this->assertSame('9123', Customers::phoneNeedle('٩١٢٣'));
        $this->assertSame('4567', Customers::phoneNeedle('(4567)'));
        $this->assertNull(Customers::phoneNeedle('CINV-000123'));
        $this->assertNull(Customers::phoneNeedle('وزارة'));
        $this->assertNull(Customers::phoneNeedle('12'));

        $this->assertSame(['96891234567', '91234567'], Customers::phoneKeys('91234567'));
        $this->assertSame(['96824445555'], Customers::phoneKeys(null, '968-2444-5555'));
    }
}
