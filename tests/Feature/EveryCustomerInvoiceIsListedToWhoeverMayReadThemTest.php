<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * قسمُ «فواتير العملاء» يعرض فواتيرَ النشاط كلَّها لمن يملك قسمَها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا يُسأل من كتب الفاتورةَ ولا من سجّل دفعتَها ولا جهازُه ولا فرعُ جلسته:
 *     مالكٌ وموظّفان يقرؤون القائمةَ نفسَها.
 *   - وكلُّ حالٍ فيها: مسودّةٌ وصادرةٌ وملغاة، ومدفوعةٌ وجزئيّةٌ وغيرُ مدفوعة،
 *     بتجهيزٍ وبلا تجهيز — والمرشّحاتُ ترشّح ولا تُسقط.
 *   - والتجهيزُ لا يُخرج الفاتورةَ من قسمها: تبقى فيه قبل ظهور المهمّة وبعده
 *     وبعد تسليمها.
 *   - والقسمان مستقلّان: من يقرأ الفواتيرَ لا يفتح اللوحةَ بذلك، ومن يجهّز لا
 *     يفتح الفواتير.
 *   - ولا فاتورةَ من متجرٍ آخر في قائمةٍ ولا عددٍ ولا صفحةٍ ولا بحث.
 */
class EveryCustomerInvoiceIsListedToWhoeverMayReadThemTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private Branch $second;

    private User $owner;

    private User $accountant;

    private User $seller;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->customer] = $this->open('ورود الخليج');
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        $this->second = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        // موظّفان بصلاحيّتين مختلفتين: دورٌ يكتب ويحصّل، وقائمةٌ مخصَّصةٌ تكتب ولا تحصّل
        $this->accountant = $this->user('المحاسب', 'accountant');
        $this->seller = $this->user('البائع', 'sales', ['orders', 'customers', Permissions::CUSTOMER_INVOICE_ISSUE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** @return array{0: Business, 1: User, 2: Customer} */
    private function open(string $name): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();

        return [
            $shop,
            $this->user('مالك '.$name, 'admin', null, $shop),
            Customer::create(['business_id' => $shop->id, 'name' => 'عميل '.$name, 'phone' => '91234567', 'allow_credit_sales' => true]),
        ];
    }

    private function user(string $name, string $role, ?array $permissions = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'email' => uniqid('i').'@invoices.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** فاتورةٌ من شاشتها بهذا المستخدم */
    private function write(User $by, array $over = []): CustomerInvoice
    {
        $this->actingAs($by)->post(route('admin.customerInvoices.store'), array_merge([
            'customer_id' => $this->customer->id, 'issue' => true, 'payment_method' => CustomerInvoices::CREDIT,
            'items' => [['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 100]],
        ], $over))->assertSessionHasNoErrors();

        return CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function prepare(): array
    {
        return ['preparation' => [
            'enabled' => true, 'fulfillment_type' => 'pickup', 'branch_id' => $this->branch->id,
            'scheduled_for' => '2026-11-25 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
        ]];
    }

    private function pay(User $by, CustomerInvoice $invoice, float $amount): void
    {
        $this->actingAs($by)->post(route('admin.customerPayments.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(), 
            'customer_id' => $invoice->customer_id, 'amount' => $amount, 'method' => 'نقدي', 'customer_invoice_id' => $invoice->id,
        ])->assertSessionHasNoErrors();
    }

    private function props(User $by, array $query = [], array $session = []): array
    {
        return $this->actingAs($by)->withSession($session + ['current_branch' => null])
            ->get(route('admin.customerInvoices.index', $query + ['per_page' => 100]))->assertOk()->viewData('page')['props'];
    }

    /** معرّفاتُ ما في القائمة — لهذا المستخدم */
    private function ids(User $by, array $query = [], array $session = []): array
    {
        return collect($this->props($by, $query, $session)['invoices'])->pluck('id')->sort()->values()->all();
    }

    /** كلُّ الأحوال — كتبها الثلاثةُ وسجّل دفعاتها اثنان */
    private function everyState(): array
    {
        $x = [
            'draft' => $this->write($this->seller, ['issue' => false]),
            'unpaid' => $this->write($this->owner),
            'partial' => $this->write($this->seller),
            'paid' => $this->write($this->accountant),
            'paidAtIssue' => $this->write($this->accountant, ['payment_method' => 'نقدي']),
            'cancelled' => $this->write($this->owner),
            'prepWaiting' => $this->write($this->seller, $this->prepare()),
            'prepOnBoard' => $this->write($this->owner, $this->prepare()),
            'prepDelivered' => $this->write($this->accountant, $this->prepare()),
        ];
        $this->pay($this->accountant, $x['partial'], 30);
        $this->pay($this->owner, $x['paid'], 100);
        $this->pay($this->accountant, $x['prepOnBoard'], 100);
        $this->pay($this->owner, $x['prepDelivered'], 100);
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.cancel', $x['cancelled']->id), ['reason' => 'طلب العميل'])->assertSessionHasNoErrors();
        $done = CustomerInvoicePreparation::where('customer_invoice_id', $x['prepDelivered']->id)->firstOrFail();
        foreach ([OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::PICKED_UP] as $to) {
            $this->actingAs($this->owner)->post(route('admin.preparation.invoiceMove', $done->id), ['status' => $to])->assertSessionHasNoErrors();
        }

        return array_map(fn (CustomerInvoice $i) => (int) $i->id, $x);
    }

    /* ============================== القائمة ============================== */

    /** ١ و٢ و٣ — الثلاثةُ يقرؤون القائمةَ نفسَها، وفيها كلُّ حال */
    public function test_the_owner_and_two_employees_read_the_same_whole_list(): void
    {
        $all = collect($this->everyState())->sort()->values()->all();

        foreach ([$this->owner, $this->accountant, $this->seller] as $who) {
            $this->assertSame($all, $this->ids($who), 'قائمةُ «'.$who->name.'»');
            $this->assertSame(count($all), $this->props($who)['pagination']['total'] ?? null, 'والعددُ عددُ ما يُعرض');
        }
    }

    /** والمرشّحاتُ ترشّح للجميع سواءً — ولا تُسقط حالًا */
    public function test_each_filter_gives_the_same_answer_to_all_three(): void
    {
        $x = $this->everyState();
        $wanted = [
            'مسودة' => [['status' => CustomerInvoice::DRAFT], ['draft']],
            'ملغاة' => [['status' => CustomerInvoice::CANCELLED], ['cancelled']],
            'صادرة' => [['status' => CustomerInvoice::ISSUED], ['unpaid', 'partial', 'paid', 'paidAtIssue', 'prepWaiting', 'prepOnBoard', 'prepDelivered']],
            'مدفوعة' => [['state' => 'مدفوعة'], ['paid', 'paidAtIssue', 'prepOnBoard', 'prepDelivered']],
            'جزئيًا' => [['state' => 'مدفوعة جزئيًا'], ['partial']],
            'غير مدفوعة' => [['state' => 'غير مدفوعة'], ['unpaid', 'prepWaiting']],
        ];

        foreach ($wanted as $name => [$query, $keys]) {
            $ids = collect($keys)->map(fn ($k) => $x[$k])->sort()->values()->all();
            foreach ([$this->owner, $this->accountant, $this->seller] as $who) {
                $this->assertSame($ids, $this->ids($who, $query), $name.' — «'.$who->name.'»');
            }
        }
    }

    /** ٣ — ولا تُقصّ بفرع الجلسة ولا بجهاز الصندوق ولا بإسناد الفرع ولا بحذف من كتبها */
    public function test_no_session_till_branch_or_author_hides_an_invoice(): void
    {
        $all = collect($this->everyState())->sort()->values()->all();

        foreach ([null, $this->branch->id, $this->second->id] as $session) {
            $this->assertSame($all, $this->ids($this->seller, [], ['current_branch' => $session]));
        }

        $this->activatePosDevice($this->shop->id, $this->second->id);
        $this->assertSame($all, $this->ids($this->accountant));

        $this->seller->branches()->sync([$this->second->id]);
        $this->assertSame($all, $this->ids($this->seller->fresh()), 'القسمُ مستنداتُ النشاط — لا تُقصّ بإسناد الفرع');

        $this->accountant->delete();
        $this->assertSame($all, $this->ids($this->seller->fresh()), 'ومن غادر تبقى فواتيرُه');
    }

    /** ويفتحها غيرُ كاتبها — وصفحتُها وورقتُها لا تسأل من كتب */
    public function test_anyone_with_the_section_opens_what_another_wrote(): void
    {
        $x = $this->everyState();

        foreach ([$this->owner, $this->accountant, $this->seller] as $who) {
            foreach ($x as $id) {
                $this->actingAs($who)->get(route('admin.customerInvoices.show', $id))->assertOk();
            }
        }
        $this->actingAs($this->seller)->get(route('admin.customerInvoices.pdf', $x['paid']))->assertOk();
    }

    /* ============================== والتجهيز ============================== */

    /** ٤ — وفاتورةُ التجهيز في قسمها قبل ظهور المهمّة وبعده وبعد التسليم */
    public function test_a_prepared_invoice_never_leaves_its_section(): void
    {
        $invoice = $this->write($this->seller, $this->prepare());
        $listed = fn () => collect([$this->owner, $this->accountant, $this->seller])
            ->every(fn (User $u) => in_array($invoice->id, $this->ids($u), true));

        $this->assertTrue($listed(), 'قبل السداد');

        $this->pay($this->accountant, $invoice, 100);
        $prep = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->firstOrFail();
        $this->assertNotNull($prep->activated_at);
        $this->assertTrue($listed(), 'وهي على اللوحة');

        foreach ([OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::PICKED_UP] as $to) {
            $this->actingAs($this->owner)->post(route('admin.preparation.invoiceMove', $prep->id), ['status' => $to])->assertSessionHasNoErrors();
            $this->assertTrue($listed(), 'والمهمّةُ «'.$to.'»');
        }
        $this->assertSame('مدفوعة', collect($this->props($this->seller)['invoices'])->firstWhere('id', $invoice->id)['state']);
    }

    /** ٦ — والعاديّةُ في قسمها وحده: لا مهمّةَ لها ولا بطاقةَ ولو سُدّدت */
    public function test_a_plain_invoice_is_listed_and_never_sent_to_the_board(): void
    {
        $plain = $this->write($this->seller);
        $this->pay($this->accountant, $plain, 100);

        $this->assertContains($plain->id, $this->ids($this->owner));
        $this->assertSame(0, CustomerInvoicePreparation::count());
        $board = $this->actingAs($this->owner)->get(route('admin.preparation.index'))->viewData('page')['props'];
        $this->assertSame([], $board['orders']);
        $this->assertSame(0, $board['counts']['all']);
    }

    /* ============================== الصلاحيّتان ============================== */

    /** ٥ — قسمان مستقلّان: لا يُفتح أحدُهما بالآخر */
    public function test_invoices_and_the_board_are_two_permissions(): void
    {
        $this->everyState();
        $bench = $this->user('المجهّز', 'cashier', ['preparation']);
        $reader = $this->user('قارئ الفواتير', 'sales', ['orders']);

        $this->actingAs($bench)->get(route('admin.preparation.index'))->assertOk();
        $this->actingAs($bench)->get(route('admin.customerInvoices.index'))->assertForbidden();
        $this->actingAs($bench)->get(route('admin.customerInvoices.sales'))->assertForbidden();

        $this->actingAs($reader)->get(route('admin.customerInvoices.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.preparation.index'))->assertForbidden();
        $this->assertCount(9, $this->ids($reader));

        // ومن قُرئت له لم يُمنح إصدارًا ولا تحصيلًا
        $this->actingAs($reader)->post(route('admin.customerInvoices.store'), ['customer_id' => $this->customer->id, 'issue' => true, 'payment_method' => CustomerInvoices::CREDIT, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])->assertForbidden();
    }

    /* ============================== العزل ============================== */

    /** ٧ — متجران: لا صفَّ ولا عددَ ولا صفحةَ ولا نتيجةَ بحثٍ تعبر */
    public function test_no_invoice_crosses_between_shops(): void
    {
        $mine = $this->everyState();
        [$other, $theirOwner, $theirCustomer] = $this->open('الجار');
        $theirs = CustomerInvoices::issue(CustomerInvoices::create($other->id, $theirCustomer, ['issued_at' => '2026-11-24'], [
            ['description' => 'باقة الجار', 'quantity' => 1, 'unit_price' => 40],
        ]));

        foreach ([$this->owner, $this->accountant, $this->seller] as $who) {
            $this->assertNotContains($theirs->id, $this->ids($who));
            $this->assertSame(count($mine), $this->props($who)['pagination']['total']);
            $this->assertNotContains($theirs->id, $this->ids($who, ['q' => 'الجار']));
            $this->assertNotContains($theirs->id, $this->ids($who, ['customer_id' => $theirCustomer->id]));
            $this->actingAs($who)->get(route('admin.customerInvoices.show', $theirs->id))->assertNotFound();
            $this->actingAs($who)->get(route('admin.customerInvoices.pdf', $theirs->id))->assertNotFound();
        }

        $this->assertSame([$theirs->id], $this->ids($theirOwner));
        $this->actingAs($theirOwner)->get(route('admin.customerInvoices.show', $mine['paid']))->assertNotFound();
        $this->actingAs($theirOwner)->post(route('admin.customerPayments.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(), 
            'customer_id' => $this->customer->id, 'amount' => 1, 'method' => 'نقدي', 'customer_invoice_id' => $mine['unpaid'],
        ])->assertSessionHasErrors('customer_id');
    }

    /** وبيعةُ الصندوق ليست فاتورةَ عميل — لا تختلط القائمتان */
    public function test_a_till_sale_is_not_listed_as_a_customer_invoice(): void
    {
        $invoice = $this->write($this->owner);
        Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'number' => 'INV-T1', 'status' => OrderStatus::COMPLETED,
            'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->assertSame([$invoice->id], $this->ids($this->seller));
    }
}
