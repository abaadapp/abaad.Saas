<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «إلغاء تحصيل عميل» فعلٌ باسمه — لا قسمُ «المالية» ولا فعلُ التحصيل.
 *
 * ═══ ما كان ═══
 *
 * `customerPayments.cancel` محسوبٌ على «المالية» ولا يسأل غيرَها: من فتح
 * القسمَ ليقرأ الأرصدة كان يُلغي سندَ قبضٍ — فيعود الدَّينُ على العميل ويُعكس
 * قيدُ الصندوق — بطلبٍ مباشر، ولو لم يُرسَم له زرّ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الأدوار: المالكُ ومديرُ الفرع يُلغيان؛ المحاسبُ والبائعُ والكاشيرُ وأمينُ المخزن لا.
 *   - القائمةُ اليدويّة: «المالية» والتحصيلُ معًا لا يكفيان؛ والفعلُ بالاسم يكفي.
 *   - المردودُ ٤٠٣ قبل السبب، ولا يتغيّر تحصيلٌ ولا قيدٌ ولا رصيد.
 *   - المقبولُ يكتب السببَ ومن ألغى، ويعكس القيد، ويُسجَّل في السجلّ.
 *   - الزرُّ في سند القبض لمن يقبله الخادم وحده.
 *   - لا يُورَث بالقسم ولا تمنحه هجرة، ومتجرٌ آخر لا يبلغ سندًا هنا.
 */
class ACustomerPaymentIsCancelledOnlyByItsOwnPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Customer $customer;

    private CustomerInvoice $invoice;

    private CustomerPayment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الشاطئ', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);
        $this->invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50],
        ]));
        $this->payment = CustomerPayments::record($this->shop->id, $this->customer, 60, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$this->invoice->id => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $permissions = null, ?Business $shop = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'موظّف '.$n, 'email' => 'c'.$n.'@cancel.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** ما يقرؤه المحاسبُ بعد شهر: السندُ، والقيود، والباقي على الفاتورة */
    private function books(): array
    {
        $p = $this->payment->fresh();

        return [
            $p->cancelled_at, $p->cancelled_by, $p->cancellation_reason,
            DB::table('journal_entries')->where('business_id', $this->shop->id)->count(),
            $this->invoice->fresh()->outstanding(),
        ];
    }

    private function cancel(User $as, array $body = ['reason' => 'سُجّل مرّتين'])
    {
        return $this->actingAs($as)->post(route('admin.customerPayments.cancel', $this->payment->id), $body);
    }

    /* ============================== الأدوار ============================== */

    public static function roles(): array
    {
        return [
            'المالك' => ['admin', true],
            'مدير الفرع' => ['manager', true],
            'المحاسب' => ['accountant', false],
            'البائع' => ['sales', false],
            'الكاشير' => ['cashier', false],
            'أمين المخزن' => ['inventory', false],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_cancels_a_collection_only_as_its_defaults_say(string $role, bool $may): void
    {
        $this->assertSame($may, Permissions::allowsAction($role, Permissions::CUSTOMER_PAYMENT_CANCEL));

        $before = $this->books();
        $response = $this->cancel($this->user($role));

        if (! $may) {
            $response->assertForbidden();
            $this->assertSame($before, $this->books(), 'المردودُ لا يمسّ سندًا ولا قيدًا ولا رصيدًا');

            return;
        }

        $response->assertSessionHasNoErrors()->assertRedirect();
        $p = $this->payment->fresh();
        $this->assertNotNull($p->cancelled_at);
        $this->assertSame('سُجّل مرّتين', $p->cancellation_reason);
        $this->assertEqualsWithDelta((float) $this->invoice->fresh()->total, $this->invoice->fresh()->outstanding(), 0.001, 'الدَّينُ عاد كما كان');
    }

    public function test_the_accountant_role_still_records_but_does_not_cancel(): void
    {
        $accountant = $this->user('accountant');

        $this->assertTrue($accountant->may(Permissions::CUSTOMER_PAYMENT_CREATE));
        $this->assertTrue($accountant->allows('finance'));
        $this->assertFalse($accountant->may(Permissions::CUSTOMER_PAYMENT_CANCEL));
    }

    /* ============================== القائمةُ اليدويّة ============================== */

    public function test_the_finance_section_and_recording_collections_do_not_cancel_one(): void
    {
        $clerk = $this->user('accountant', ['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CREATE]);
        $before = $this->books();

        $this->cancel($clerk)->assertForbidden();
        $this->assertSame($before, $this->books());
    }

    public function test_a_refused_request_is_refused_before_its_reason_is_read(): void
    {
        $clerk = $this->user('accountant', ['dashboard', 'finance']);

        $this->cancel($clerk, [])->assertForbidden()->assertSessionDoesntHaveErrors('reason');
    }

    public function test_an_employee_granted_the_action_by_name_cancels_with_a_reason_kept_for_audit(): void
    {
        $clerk = $this->user('accountant', ['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CANCEL]);
        $entries = DB::table('journal_entries')->where('business_id', $this->shop->id)->count();

        // والسببُ مطلوب: بلا سببٍ يُردّ ولا يُلغى شيء
        $this->cancel($clerk, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertNull($this->payment->fresh()->cancelled_at);

        $this->cancel($clerk)->assertSessionHasNoErrors();

        $p = $this->payment->fresh();
        $this->assertNotNull($p->cancelled_at);
        $this->assertSame($clerk->id, (int) $p->cancelled_by);
        $this->assertSame('سُجّل مرّتين', $p->cancellation_reason);
        $this->assertSame($entries + 1, DB::table('journal_entries')->where('business_id', $this->shop->id)->count(), 'قيدٌ عاكس لا محوُ القيد');
        $this->assertTrue(ActivityLog::where('subject_type', 'customer_payment')->where('subject_id', $p->id)->exists());
    }

    public function test_withdrawing_the_grant_closes_the_door_on_the_next_request(): void
    {
        $clerk = $this->user('accountant', ['dashboard', 'finance', Permissions::CUSTOMER_PAYMENT_CANCEL]);
        $clerk->forceFill(['permissions' => ['dashboard', 'finance']])->save();

        $this->cancel($clerk->fresh())->assertForbidden();
        $this->assertNull($this->payment->fresh()->cancelled_at);
    }

    public function test_no_section_and_no_migration_grants_it_silently(): void
    {
        foreach (Permissions::LEGACY_SECTION_ACTIONS as $section => $actions) {
            $this->assertNotContains(Permissions::CUSTOMER_PAYMENT_CANCEL, $actions, "القسم «{$section}» لا يورّثه");
        }
        $this->assertNotContains(
            Permissions::CUSTOMER_PAYMENT_CANCEL,
            Permissions::withLegacyActions(['finance', 'orders', Permissions::CUSTOMER_PAYMENT_CREATE]),
        );

        $migrations = implode("\n", array_map('file_get_contents', glob(database_path('migrations/*.php'))));
        $this->assertStringNotContainsString('customerPayment.cancel', $migrations, 'لا هجرة توسّع القوائم اليدويّة به');
    }

    /* ============================== الشاشة ============================== */

    public function test_the_receipt_draws_the_cancel_button_only_for_whoever_the_server_accepts(): void
    {
        foreach ([[$this->user('admin'), true], [$this->user('accountant'), false]] as [$user, $can]) {
            $this->actingAs($user)->get(route('admin.customerPayments.show', $this->payment->id))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/CustomerInvoices/ReceiptShow')->where('can.cancel', $can));
        }
    }

    public function test_the_employee_screen_names_it_apart_from_recording_a_collection(): void
    {
        $this->assertSame('إلغاء تحصيل عميل', Permissions::ACTIONS[Permissions::CUSTOMER_PAYMENT_CANCEL]);
        $this->assertArrayHasKey(Permissions::CUSTOMER_PAYMENT_CANCEL, Permissions::actionHints());

        app()->setLocale('en');
        $this->assertSame('Cancel a customer collection', Permissions::actionLabels()[Permissions::CUSTOMER_PAYMENT_CANCEL]);
    }

    /* ============================== المتجر الآخر ============================== */

    public function test_another_shops_owner_cannot_reach_this_receipt(): void
    {
        $this->cancel($this->user('admin', null, $this->other))->assertNotFound();
        $this->assertNull($this->payment->fresh()->cancelled_at);
    }
}
