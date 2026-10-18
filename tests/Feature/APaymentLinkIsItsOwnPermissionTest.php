<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JobTitle;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «إنشاء روابط الدفع» فعلٌ باسمه — لا «المالية» ولا التحصيل.
 *
 * ═══ ما كان ═══
 *
 * الرابطُ يُطلب من «المالية ← روابط الدفع» وبفعل التحصيل. فالبائعُ الذي يُصدر
 * فاتورةً ويرسل رابطَ دفعها كان يُمنح الماليّةَ كلَّها — البنوكَ والتسوياتِ
 * وقيودَ اليوم — وتسجيلَ تحصيلٍ نقديٍّ بلا مالٍ في يده.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الأدوارُ كما كانت: المالكُ والمديرُ والمحاسبُ يطلبون، والبائعُ لا يُمنحه وحده.
 *   - الموظّفُ يُمنحه بالاسم: يطلب لفاتورته وعميله بلا «المالية»، ولا يُلغي رابطًا،
 *     ولا يسجّل تحصيلًا، ولا يبلغ إعدادات Paymob ولا التسويات ولا البنوك.
 *   - والإصدارُ مع الرابط يحتاج الفعلين؛ وكلٌّ منهما يبقى لصاحبه وحده.
 *   - والنزعُ يردّ؛ ومن كانت قائمتُه تملك الطلبَ أمس تملكه اليوم (الهجرة) —
 *     ومن لم تكن لا تملكه.
 *   - و«صلاحيات الموظفين» تعرضه باسمه وتفرّقه عن «تسجيل تحصيل من عميل».
 *   - ومتجرٌ آخر لا يبلغه شيءٌ من هذا.
 */
class APaymentLinkIsItsOwnPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = $this->user('admin');
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api-shop', 'mode' => PaymentGateway::TEST,
        ]);

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'shorten_url' => 'https://paymob.link/abc', 'expires_at' => '2026-10-26T23:59:00', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function user(string $role, ?array $permissions = null, ?Business $shop = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'موظّف '.$n, 'email' => 'u'.$n.'@perm.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** بائعٌ خُصّصت صلاحياته: يرى الفواتيرَ والعملاء — ولا «مالية» */
    private function seller(array $actions): User
    {
        return $this->user('sales', ['dashboard', 'orders', 'customers', ...$actions]);
    }

    private function invoice(): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50],
        ]));
    }

    private function form(array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id, 'issued_at' => '2026-10-19', 'payment_method' => 'آجل', 'issue' => true,
            'payment_link' => true, 'form_token' => 'f-'.uniqid(),
            'items' => [['description' => 'باقة ورد', 'quantity' => 2, 'unit_price' => 50]],
        ], $over);
    }

    /* ============================== الأدوار ============================== */

    public static function roles(): array
    {
        return [
            'المالك' => ['admin', true],
            'المدير' => ['manager', true],
            'المحاسب' => ['accountant', true],
            'البائع' => ['sales', false],
            'الكاشير' => ['cashier', false],
            'أمين المخزن' => ['inventory', false],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_keeps_what_it_could_do_before(string $role, bool $may): void
    {
        $this->assertSame($may, Permissions::allowsAction($role, Permissions::PAYMENT_LINK_CREATE));

        // وكان الطلبُ قبلُ يحتاج «المالية» والتحصيلَ معًا — والأدوارُ نفسُها تملكه اليوم
        $before = Permissions::allows($role, 'finance') && Permissions::allowsAction($role, Permissions::CUSTOMER_PAYMENT_CREATE);
        $this->assertSame($before, $may, 'لا يُقطع عن دورٍ ولا يُوسَّع به دور');

        $user = $this->user($role);
        $invoice = $this->invoice();
        $res = $this->actingAs($user)->post(route('admin.customerInvoices.paymentLink', $invoice->id));

        $may ? $res->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success') : $this->assertContains($res->status(), [302, 403]);
        $this->assertSame($may ? 1 : 0, CustomerPaymentLink::count());
    }

    /* ============================== منحٌ بالاسم ============================== */

    public function test_a_seller_granted_the_link_alone_asks_for_payment_without_finance(): void
    {
        $seller = $this->seller([Permissions::CUSTOMER_INVOICE_ISSUE, Permissions::PAYMENT_LINK_CREATE]);
        $this->actingAs($seller);

        // يُصدر ويطلب في ضغطة
        $this->post(route('admin.customerInvoices.store'), $this->form())
            ->assertSessionHasNoErrors()->assertSessionHas('toast.msg', 'تم إصدار الفاتورة وإنشاء رابط الدفع');
        $this->assertSame(1, CustomerPaymentLink::count());

        // ويطلب لفاتورةٍ قائمةٍ ولعميلٍ من صفحتيهما
        $this->post(route('admin.customerInvoices.paymentLink', $this->invoice()->id))->assertSessionHasNoErrors();
        $this->post(route('admin.customers.paymentLink', $this->customer->id), ['amount' => 15])->assertSessionHasNoErrors();
        $this->assertSame(3, CustomerPaymentLink::count());

        // وصفحةُ الفاتورة تعرض له الطلبَ — لا الإلغاءَ ولا إعدادَ Paymob
        $panel = $this->get(route('admin.customerInvoices.show', CustomerInvoice::first()->id))->assertOk()->viewData('page')['props']['paymentLinks'];
        $this->assertTrue($panel['can']);
        $this->assertFalse($panel['can_cancel']);
        $this->assertNull($panel['settings_url']);

        $this->assertSame(0, CustomerPayment::count(), 'الطلبُ لا يكتب تحصيلًا');
    }

    public function test_the_link_grant_opens_no_finance_no_collection_no_cancel_and_no_paymob_settings(): void
    {
        $seller = $this->seller([Permissions::PAYMENT_LINK_CREATE]);
        $invoice = $this->invoice();
        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice, null, null, $this->owner->id);
        $this->actingAs($seller);

        $this->get(route('admin.finance.paymentLinks'))->assertForbidden();
        $this->get(route('admin.finance.index'))->assertForbidden();
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->customer->id, 'customer_invoice_id' => $invoice->id])->assertForbidden();
        $this->post(route('admin.finance.paymentLinks.cancel', $link->id))->assertForbidden();
        $this->post(route('admin.finance.paymobSettlements.store'), ['gross' => 10, 'fee' => 1, 'net' => 9, 'settled_on' => '2026-10-19'])->assertForbidden();
        $this->post(route('admin.customerPayments.store'), ['customer_id' => $this->customer->id, 'amount' => 10, 'method' => 'نقدي'])->assertForbidden();
        $this->get(route('admin.integrations.paymob'))->assertForbidden();

        $this->assertSame(CustomerPaymentLink::PENDING, $link->fresh()->status);
        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_issuing_with_a_link_needs_both_actions_and_each_keeps_its_own(): void
    {
        // يطلب ولا يُصدر: الإصدارُ مع الرابط يُردّ — والطلبُ لفاتورةٍ صادرة يبقى له
        $asker = $this->seller([Permissions::PAYMENT_LINK_CREATE]);
        $this->actingAs($asker)->post(route('admin.customerInvoices.store'), $this->form())->assertForbidden();
        $this->assertSame(0, CustomerInvoice::count());
        $this->post(route('admin.customerInvoices.paymentLink', $this->invoice()->id))->assertSessionHasNoErrors();

        // يُصدر ولا يطلب: الإصدارُ العاديّ له، والرابطُ معه يُردّ
        $issuer = $this->seller([Permissions::CUSTOMER_INVOICE_ISSUE]);
        $this->actingAs($issuer)->post(route('admin.customerInvoices.store'), $this->form())->assertForbidden();
        $this->post(route('admin.customerInvoices.store'), $this->form(['payment_link' => false]))->assertSessionHasNoErrors();
        $this->post(route('admin.customerInvoices.paymentLink', CustomerInvoice::latest('id')->first()->id))->assertForbidden();
        $this->post(route('admin.customers.paymentLink', $this->customer->id), ['amount' => 5])->assertForbidden();

        $this->assertSame(2, CustomerInvoice::count());
        $this->assertSame(1, CustomerPaymentLink::count());

        // وشاشتاه تقولان ذلك: لا زرَّ يُردّ عنه
        $this->assertFalse($this->get(route('admin.customerInvoices.create'))->viewData('page')['props']['may']['payment_link']);
        $this->actingAs($asker);
        $this->assertTrue($this->get(route('admin.customerInvoices.create'))->viewData('page')['props']['may']['payment_link']);
    }

    public function test_taking_the_grant_away_closes_the_door(): void
    {
        $seller = $this->seller([Permissions::CUSTOMER_INVOICE_ISSUE, Permissions::PAYMENT_LINK_CREATE]);
        $invoice = $this->invoice();

        $seller->forceFill(['permissions' => ['dashboard', 'orders', 'customers', Permissions::CUSTOMER_INVOICE_ISSUE]])->save();

        $this->actingAs($seller->fresh())->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertForbidden();
        $this->post(route('admin.customerInvoices.store'), $this->form())->assertForbidden();
        $this->assertSame(0, CustomerPaymentLink::count());
    }

    /** و«المالية» مع التحصيل لا تكفي وحدها اليوم — الطلبُ فعلٌ يُمنح */
    public function test_finance_and_collection_without_the_link_grant_no_longer_ask(): void
    {
        $clerk = $this->user('sales', ['dashboard', 'orders', 'customers', 'finance', Permissions::CUSTOMER_PAYMENT_CREATE]);

        $this->actingAs($clerk)->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->customer->id, 'customer_invoice_id' => $this->invoice()->id])->assertForbidden();
        $this->assertSame(0, CustomerPaymentLink::count());
    }

    /* ============================== الهجرة ============================== */

    /**
     * من كانت قائمتُه تطلب أمس تطلب اليوم — ومن لم تكن لا تطلب.
     *
     * أمس = «المالية» والتحصيلُ معًا. ومن يتبع دورَه لا يُمسّ صفُّه.
     */
    public function test_the_upgrade_keeps_yesterdays_askers_and_adds_no_one(): void
    {
        $asker = $this->user('sales', ['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CREATE]);
        $financeOnly = $this->user('sales', ['dashboard', 'finance']);
        $collectOnly = $this->user('sales', ['dashboard', 'orders', Permissions::CUSTOMER_PAYMENT_CREATE]);
        $byRole = $this->user('accountant');
        $neighbour = $this->user('sales', ['dashboard', 'finance'], $this->other);

        $migration = require database_path('migrations/2026_10_19_100200_a_payment_link_is_asked_by_its_own_permission.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CREATE, Permissions::PAYMENT_LINK_CREATE], $asker->fresh()->permissions);
        $this->assertSame(['dashboard', 'finance'], $financeOnly->fresh()->permissions);
        $this->assertSame(['dashboard', 'orders', Permissions::CUSTOMER_PAYMENT_CREATE], $collectOnly->fresh()->permissions);
        $this->assertNull(DB::table('users')->where('id', $byRole->id)->value('permissions'), 'من يتبع دورَه لا يُمسّ');
        $this->assertTrue($byRole->may(Permissions::PAYMENT_LINK_CREATE));
        $this->assertSame(['dashboard', 'finance'], $neighbour->fresh()->permissions);

        $this->actingAs($asker->fresh())->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->customer->id, 'customer_invoice_id' => $this->invoice()->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerPaymentLink::count());

        $migration->down();
        $this->assertSame(['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CREATE], $asker->fresh()->permissions);
    }

    /* ============================== صلاحيات الموظفين ============================== */

    public function test_the_staff_screen_offers_it_by_name_apart_from_recording_a_collection(): void
    {
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'role' => 'sales']);
        $employee = User::create([
            'business_id' => $this->shop->id, 'name' => 'سالم', 'email' => 'salem@perm.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'job_title' => 'بائع', 'status' => 'نشط',
        ]);

        $props = $this->actingAs($this->owner)->get(route('admin.employees.edit', $employee->id))->assertOk()->viewData('page')['props'];
        $this->assertSame('إنشاء روابط الدفع', $props['actions'][Permissions::PAYMENT_LINK_CREATE]);
        $this->assertSame('تسجيل تحصيل من عميل', $props['actions'][Permissions::CUSTOMER_PAYMENT_CREATE]);
        $this->assertStringContainsString('لا يسجّل تحصيلًا ولا يفتح شاشات المالية', $props['actionHints'][Permissions::PAYMENT_LINK_CREATE]);
        $this->assertStringContainsString('غيرُ «إنشاء روابط الدفع»', $props['actionHints'][Permissions::CUSTOMER_PAYMENT_CREATE]);

        // ولا يُمنح بالدور للبائع — يُمنح بالاسم من هنا
        $this->assertFalse($employee->may(Permissions::PAYMENT_LINK_CREATE));

        $this->put(route('admin.employees.update', $employee->id), [
            'name' => 'سالم', 'job_title' => 'بائع', 'manual_permissions' => true,
            'permissions' => ['dashboard', 'orders', 'customers', Permissions::CUSTOMER_INVOICE_ISSUE, Permissions::PAYMENT_LINK_CREATE],
        ])->assertSessionHasNoErrors();

        $fresh = $employee->fresh();
        $this->assertTrue($fresh->may(Permissions::PAYMENT_LINK_CREATE));
        $this->assertFalse($fresh->may(Permissions::CUSTOMER_PAYMENT_CREATE));
        $this->assertFalse($fresh->allows('finance'));

        $this->actingAs($fresh)->post(route('admin.customerInvoices.paymentLink', $this->invoice()->id))->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerPaymentLink::count());
    }

    /** ومن لا يملكه لا يمنحه — المديرُ يمنح ما يملك */
    public function test_only_who_holds_it_may_grant_it(): void
    {
        $this->assertContains(Permissions::PAYMENT_LINK_CREATE, Permissions::grantable($this->user('manager')));
        $this->assertNotContains(Permissions::PAYMENT_LINK_CREATE, Permissions::grantable($this->user('sales', ['dashboard', 'employees'])));
    }

    /* ============================== متجرٌ آخر ============================== */

    public function test_another_shop_neither_reaches_these_links_nor_gains_the_grant(): void
    {
        $neighbourSeller = $this->user('sales', null, $this->other);
        $this->assertFalse($neighbourSeller->may(Permissions::PAYMENT_LINK_CREATE), 'لا يُمنح لبائع متجرٍ آخر بالدور');

        $neighbourOwner = $this->user('admin', null, $this->other);
        $invoice = $this->invoice();

        $this->actingAs($neighbourOwner)->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertNotFound();
        $this->post(route('admin.customers.paymentLink', $this->customer->id), ['amount' => 5])->assertNotFound();
        $this->assertSame(0, CustomerPaymentLink::count());
    }
}
