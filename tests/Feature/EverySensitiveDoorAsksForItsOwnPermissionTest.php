<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Cheques;
use App\Support\CustomerFlags;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\Permissions;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * الأبوابُ الثانية — حيث كان القسمُ وحده يحرس فعلًا له اسم.
 *
 * وجدها تدقيقُ الصلاحيّات (2026-10-20): فعلٌ يحرسه اسمُه في بابه الأوّل،
 * وبابٌ ثانٍ بالأثر نفسِه يسأل القسمَ وحده. فيُحرس كلُّ بابٍ هنا بما يُحرس
 * به أخوه — ولا يُقطع عن صاحب الدور ما كان يفعله.
 *
 *   - G2 «ملغي» من صفحة الطلب: `preparation.cancel` كلوحة التجهيز.
 *   - G3 سطرُ الراتب: لا يكتب أحدٌ راتبَه بيده إلّا صاحبُ النشاط.
 *   - G4 رفعُ حظر البيع: `customer.block_override` كتجاوزه عند الصندوق.
 *   - G5 ضبطُ الموقع من «التسويق»: `website.configure` حين يتغيّر العنوانُ أو النشرُ أو اللون.
 *   - G6 ورقتا سند المورّد والاستلام، وسجلُّ المشتريات: بفعلَي قراءتهما.
 *   - G7 فروعُ الموظّف نفسِه: لا يوسّعها لنفسه.
 *   - G8 «المالك» من دورُه سلطتُه — لا صفٌّ دورُه admin ضُيّق.
 *   - G9 القبضُ عند إصدار الفاتورة: `customerPayment.create`.
 *   - G10 صرفُ الشيك: `customerPayment.create`. وارتدادُه يعكس قيدَ القبض
 *     كإلغاء السند، فبـ`customerPayment.cancel` (مراجعة #131).
 */
class EverySensitiveDoorAsksForItsOwnPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $north;

    private Branch $south;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الساحل', 'type' => 'محل ورود', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        $this->north = Branch::create(['business_id' => $this->shop->id, 'name' => 'الشمال']);
        $this->south = Branch::create(['business_id' => $this->shop->id, 'name' => 'الجنوب']);
        $this->owner = $this->user('admin', null, ['basic_salary' => 900]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الخليج', 'phone' => '92222222', 'allow_credit_sales' => true]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $permissions = null, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'business_id' => $this->shop->id, 'name' => 'موظّف '.$n, 'email' => 'd'.$n.'@doors.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ], $extra));
    }

    /* ============================== G2 ============================== */

    private function order(): Order
    {
        return Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->north->id, 'number' => 'INV-'.uniqid(),
            'status' => OrderStatus::CONFIRMED, 'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);
    }

    public function test_a_salesperson_cannot_cancel_a_sale_from_its_page_and_is_not_offered_to(): void
    {
        $order = $this->order();
        $seller = $this->user('sales');

        $this->actingAs($seller)->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::CANCELLED])->assertForbidden();
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);

        $this->actingAs($seller)->get(route('admin.orders.show', $order->number))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.next_statuses', fn ($next) => ! collect($next)->contains(OrderStatus::CANCELLED)));

        // ومن مُنح الإلغاءَ باسمه يُعرض له — كالمالك والمدير بدورهما
        $this->assertTrue(Permissions::allowsAction('manager', Permissions::PREPARATION_CANCEL));
        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn (Assert $page) => $page->where('order.next_statuses', fn ($next) => collect($next)->contains(OrderStatus::CANCELLED)));
    }

    /* ============================== G3 ============================== */

    public function test_nobody_but_the_owner_writes_their_own_payroll_line(): void
    {
        $manager = $this->user('manager', null, ['basic_salary' => 500]);
        $this->post(route('admin.payroll.store'), ['period' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = PayrollRun::where('business_id', $this->shop->id)->firstOrFail();
        $mine = PayrollLine::where('payroll_run_id', $run->id)->where('user_id', $manager->id)->firstOrFail();
        $owners = PayrollLine::where('payroll_run_id', $run->id)->where('user_id', $this->owner->id)->firstOrFail();
        $raise = ['basic' => 5000, 'allowances' => 0, 'overtime' => 0, 'deductions' => 0];

        $this->actingAs($manager)->put(route('admin.payroll.lines.update', $mine->id), $raise)->assertForbidden();
        $this->assertEqualsWithDelta(500, (float) $mine->fresh()->basic, 0.001, 'لم يتغيّر راتبُه');

        // ويكتب سطرَ غيره كما كان، والمالكُ يكتب كلَّ سطر
        $this->actingAs($manager)->put(route('admin.payroll.lines.update', $owners->id), ['basic' => 900, 'allowances' => 10, 'overtime' => 0, 'deductions' => 0])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('admin.payroll.lines.update', $mine->id), ['basic' => 550, 'allowances' => 0, 'overtime' => 0, 'deductions' => 0])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(550, (float) $mine->fresh()->basic, 0.001);
    }

    /* ============================== G4 ============================== */

    public function test_lifting_a_sale_block_asks_for_the_override_and_setting_one_does_not(): void
    {
        $clerk = $this->user('sales', ['dashboard', 'customers']);
        $blocked = ['alert_enabled' => true, 'alert_type' => CustomerFlags::BLOCK, 'alert_reason' => 'شيكات مرتجعة'];

        // وضعُ الحظر لمن يفتح القسم — تنبيهٌ لا مال
        $this->actingAs($clerk)->post(route('admin.customers.internal', $this->customer->id), $blocked)->assertSessionHasNoErrors();
        $this->assertSame(CustomerFlags::BLOCK, $this->customer->fresh()->alert_type);

        $this->actingAs($clerk)->post(route('admin.customers.internal', $this->customer->id), ['alert_enabled' => false])->assertForbidden();
        $this->assertSame(CustomerFlags::BLOCK, $this->customer->fresh()->alert_type, 'الحظرُ باقٍ');

        $this->actingAs($this->owner)->post(route('admin.customers.internal', $this->customer->id), ['alert_enabled' => false])->assertSessionHasNoErrors();
        $this->assertNull($this->customer->fresh()->alert_type);
    }

    /* ============================== G5 ============================== */

    public function test_marketing_staff_save_their_texts_but_do_not_reconfigure_the_site(): void
    {
        $marketer = $this->user('sales', ['dashboard', 'marketing']);

        $this->actingAs($marketer)->post(route('admin.marketing.store.save'), ['store_headline' => 'ورودٌ لكلّ مناسبة'])->assertSessionHasNoErrors();
        $this->assertSame('ورودٌ لكلّ مناسبة', MarketingSettings::group($this->shop->id, 'website')['store_headline'] ?? null);

        $this->actingAs($marketer)->post(route('admin.marketing.store.save'), ['site_slug' => 'my-roses'])->assertForbidden();
        $this->actingAs($marketer)->post(route('admin.marketing.store.save'), ['store_theme' => collect(Storefront::THEMES)->keys()->last()])->assertForbidden();
        $this->actingAs($marketer)->post(route('admin.marketing.website.save'), ['site_domain' => 'roses.om'])->assertForbidden();
        $this->assertNull($this->shop->fresh()->site_slug);

        // وصاحبُ الضبط يضبطه كما كان
        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), ['site_slug' => 'my-roses'])->assertSessionHasNoErrors();
        $this->assertSame('my-roses', $this->shop->fresh()->site_slug);
    }

    /* ============================== G6 ============================== */

    public function test_supplier_papers_and_debts_follow_their_read_actions(): void
    {
        $buyer = $this->user('inventory', ['dashboard', 'purchases']);

        $this->actingAs($buyer)->get(route('admin.purchases.invoices.pdf', 999999))->assertForbidden();
        $this->actingAs($buyer)->get(route('admin.inventory.receipts.pdf', 999999))->assertForbidden();
        // ومن يفتح «المخزون» بلا قراءة سندات الاستلام — يُردّ بالفعل لا بالقسم
        $this->actingAs($this->user('inventory', ['dashboard', 'inventory']))->get(route('admin.inventory.receipts.pdf', 999999))->assertForbidden();
        $this->actingAs($this->user('inventory', ['dashboard', 'inventory', Permissions::RECEIPT_VIEW]))->get(route('admin.inventory.receipts.pdf', 999999))->assertNotFound();
        $this->actingAs($buyer)->get(route('admin.purchases.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.outstanding', null));

        // ومن يقرأ السندات يبلغ البابَ — ويُردّ بـ٤٠٤ على سندٍ لا وجود له
        $this->actingAs($this->owner)->get(route('admin.purchases.invoices.pdf', 999999))->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.purchases.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.outstanding', fn ($v) => is_numeric($v)));
    }

    /* ============================== G7 ============================== */

    public function test_an_employee_does_not_widen_their_own_branches(): void
    {
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير فرع', 'role' => 'manager']);
        $manager = $this->user('manager', null, ['job_title' => 'مدير فرع']);
        $manager->branches()->sync([$this->north->id]);
        $payload = ['name' => $manager->name, 'email' => $manager->email, 'role' => 'manager', 'job_title' => 'مدير فرع'];

        $this->actingAs($manager)->put(route('admin.employees.update', $manager->id), $payload + ['branches' => [$this->north->id, $this->south->id]])
            ->assertSessionHasErrors('branches');
        $this->assertSame([$this->north->id], $manager->branches()->pluck('branches.id')->map(fn ($i) => (int) $i)->all());

        // والحفظُ بفروعه كما هي يمرّ — والمالكُ يضبطها له
        $this->actingAs($manager)->put(route('admin.employees.update', $manager->id), $payload + ['branches' => [$this->north->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('admin.employees.update', $manager->id), $payload + ['branches' => [$this->north->id, $this->south->id]])->assertSessionHasNoErrors();
        $this->assertCount(2, $manager->branches()->get());
    }

    /* ============================== G8 ============================== */

    public function test_a_narrowed_admin_row_is_not_the_owner_when_deleting_a_sale(): void
    {
        $order = $this->order();
        $partner = $this->user('admin', ['dashboard', 'orders']);

        $this->assertFalse(Permissions::isOwner($partner));
        $this->actingAs($partner)->delete(route('admin.orders.destroy', $order->number), ['reason' => 'خطأ'])->assertForbidden();
        $this->assertNotNull($order->fresh());
    }

    /* ============================== G9 ============================== */

    public function test_issuing_a_paid_invoice_records_a_collection_only_for_whoever_records_them(): void
    {
        $issuer = $this->user('sales', ['dashboard', 'orders', Permissions::CUSTOMER_INVOICE_ISSUE]);
        $form = [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-10-20', 'issue' => true,
            'items' => [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 20]],
        ];

        $this->actingAs($issuer)->post(route('admin.customerInvoices.store'), $form + ['payment_method' => 'نقدي'])->assertForbidden();
        $this->assertSame(0, CustomerInvoice::count());

        // و«آجل» يُصدرها كما كان
        $this->actingAs($issuer)->post(route('admin.customerInvoices.store'), $form + ['payment_method' => CustomerInvoices::CREDIT])->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerInvoice::count());
    }

    /* ============================== G10 ============================== */

    /** فاتورةٌ بخمسين، منها عشرون بشيكٍ تحت التحصيل */
    private function pendingCheque(): array
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-20'], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 50],
        ]));
        $cheque = CustomerPayments::record($this->shop->id, $this->customer, 20, ['method' => 'شيك', 'occurred_at' => '2026-10-20', 'cheque_due_at' => '2026-10-25'], [$invoice->id => 20]);
        $this->assertSame(Cheques::PENDING, $cheque->cheque_status);

        return [$invoice, $cheque];
    }

    public function test_a_cheque_is_cleared_by_whoever_records_collections(): void
    {
        [, $cheque] = $this->pendingCheque();

        $reader = $this->user('accountant', ['dashboard', 'finance']);
        $this->actingAs($reader)->post(route('admin.finance.cheques.clear', $cheque->id), ['cleared_on' => '2026-10-25'])->assertForbidden();
        $this->assertSame(Cheques::PENDING, $cheque->fresh()->cheque_status);

        // والمحاسبُ بدوره يسجّل التحصيل — فيكتب تمامَه
        $this->actingAs($this->user('accountant'))->post(route('admin.finance.cheques.clear', $cheque->id), ['cleared_on' => '2026-10-25'])->assertSessionHasNoErrors();
        $this->assertSame(Cheques::CLEARED, $cheque->fresh()->cheque_status);
    }

    /**
     * والارتدادُ إلغاءٌ باسمٍ آخر — فلمن يملك إلغاءَ التحصيل وحده.
     *
     * يعكس قيدَ القبض ويُعيد العشرين على العميل كما يفعل `customerPayments.cancel`.
     * فمن يسجّل التحصيل ولا يُلغيه — بدوره أو باسمه — يُردّ ولا يتغيّر شيء.
     */
    public function test_a_cheque_is_bounced_only_by_whoever_may_cancel_a_collection(): void
    {
        [$invoice, $cheque] = $this->pendingCheque();
        $books = fn () => [
            $cheque->fresh()->cheque_status,
            round($invoice->fresh()->paidTotal(), 3),
            DB::table('journal_entries')->where('business_id', $this->shop->id)->count(),
        ];
        $before = $books();
        $this->assertSame(20.0, $before[1]);

        foreach ([
            'يقرأ «المالية»' => $this->user('accountant', ['dashboard', 'finance']),
            'المحاسبُ بدوره' => $this->user('accountant'),
            'يسجّل التحصيل باسمه' => $this->user('sales', ['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CREATE]),
        ] as $who => $user) {
            $this->assertTrue($who === 'يقرأ «المالية»' || $user->may(Permissions::CUSTOMER_PAYMENT_CREATE), $who);
            $this->actingAs($user)->post(route('admin.finance.cheques.bounce', $cheque->id), ['reason' => 'رصيدٌ لا يكفي'])->assertForbidden();
            $this->assertSame($before, $books(), $who.': لا حالَ ولا ذمّةَ ولا قيد');
        }

        // ومن مُنح الإلغاءَ باسمه يسجّل الارتداد — فيعكس القيدَ وتعود العشرون
        $this->actingAs($this->user('accountant', ['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CANCEL]))
            ->post(route('admin.finance.cheques.bounce', $cheque->id), ['reason' => 'رصيدٌ لا يكفي'])->assertSessionHasNoErrors();
        $this->assertSame([Cheques::BOUNCED, 0.0, $before[2] + 1], $books());

        // ومديرُ الفرع بدوره كذلك
        [, $second] = $this->pendingCheque();
        $this->actingAs($this->user('manager'))->post(route('admin.finance.cheques.bounce', $second->id), ['reason' => 'توقيعٌ لا يطابق'])->assertSessionHasNoErrors();
        $this->assertSame(Cheques::BOUNCED, $second->fresh()->cheque_status);
    }

    /** والشاشةُ ترسم كلَّ زرٍّ لمن يقبله الخادم — من الفعل نفسه */
    public function test_the_cheque_screen_is_told_each_action_by_its_own_name(): void
    {
        $this->pendingCheque();

        $this->actingAs($this->user('accountant'))->get(route('admin.finance.cheques'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.mayActions', fn ($a) => collect($a)->contains(Permissions::CUSTOMER_PAYMENT_CREATE)
                    && ! collect($a)->contains(Permissions::CUSTOMER_PAYMENT_CANCEL)));
    }
}
