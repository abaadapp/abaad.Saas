<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\PaymentGateway;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\OrderStatus;
use App\Support\Paymob\Amount;
use App\Support\Permissions;
use App\Support\PrepSheet;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TillsAndPaidInvoices;
use Tests\TestCase;

/**
 * من الفاتورة إلى الباب — «تعديل تفاصيل التسليم» بالأبواب التي يمرّ بها الناس.
 *
 * ═══ ما يجري ═══
 *
 * التاجرُ يُصدر فاتورةً بطلب تجهيز ورابطِ Paymob من نموذجها، وPaymob تُشعر
 * بالسداد كاملًا موقَّعًا، فتظهر المهمّةُ في اللوحة، ويطبع صندوقُ الفرع ورقتَها.
 * والموظّفُ يفتح اللوحة بقسمها فلا يجد الزرّ، حتى يمنحه المالكُ الفعلَ من
 * ملفّه. ثمّ يعدّل الموعدَ والمستلِمَ وهاتفَه والعنوانَ والمناسبةَ والهديّةَ
 * والبطاقةَ والفرع — ولا يُنادى هنا شيءٌ من الداخل إلّا للقراءة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - ما يُطبع بعدها يقول الجديد، والورقةُ التي خرجت تُكتب لها ورقةٌ في صندوق
 *     الفرع الجديد وحده — لا في القديم، ولا مرّتين.
 *   - لا مهمّةَ ثانية ولا تفعيلَ ثانٍ، ولا يتغيّر مبلغٌ ولا تحصيلٌ ولا قيد.
 *   - المسلَّمةُ تُردّ، ومتجرٌ آخر وفرعٌ لا يعمل فيه الموظّفُ لا يبلغانها.
 *   - الفعلُ وحده لا يفتح اللوحة، ولا يمنح فعلًا ماليًّا.
 *   - والورقةُ التي فشلت ثمّ نُقلت مهمّتُها تُعاد إلى صندوق الفرع الجديد.
 *
 * والطابعةُ نفسُها لا تُختبر هنا: الصندوقُ يُحاكى بأبوابه (`claim`·`ack`).
 */
class APaymobPaidTaskIsCorrectedFromTheBoardEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use TillsAndPaidInvoices;

    private int $tx = 9100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTills();
        Carbon::setTestNow('2026-10-19 10:00:00');

        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'shorten_url' => 'https://paymob.link/abc',
                'expires_at' => '2026-10-26T23:59:00', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مجهّز', 'role' => 'delivery']);
    }

    /* ============================== أدوات ============================== */

    /** ① التاجرُ يُصدر الفاتورةَ بطلب تجهيزٍ ورابطِ دفعٍ من نموذجها */
    private function issueWithPreparationAndLink(): CustomerInvoice
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'issued_at' => '2026-10-19',
            'payment_method' => 'آجل',
            'issue' => true,
            'payment_link' => true,
            'form_token' => 'form-'.uniqid(),
            'items' => [['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50]],
            'preparation' => [
                'enabled' => true,
                'fulfillment_type' => 'delivery',
                'branch_id' => $this->branch->id,
                'scheduled_for' => '2026-10-19 16:00',
                'recipient_name' => 'مريم البلوشية',
                'recipient_phone' => '99887766',
                'delivery_address' => 'الخوير، شارع 45، فيلا 12',
                'delivery_notes' => 'الاتصال قبل الوصول',
            ],
        ])->assertSessionHasNoErrors();

        return CustomerInvoice::where('business_id', $this->shop->id)->sole();
    }

    /** ② Paymob تُشعر بالسداد كاملًا — موقَّعًا بمفتاح المتجر */
    private function paymobPays(CustomerPaymentLink $link): void
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-19T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ];

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    /** مهمّةٌ دُفع رابطُها وظهرت في اللوحة — بالأبواب نفسِها */
    private function paidTaskThroughTheDoors(): CustomerInvoicePreparation
    {
        $invoice = $this->issueWithPreparationAndLink();
        $this->paymobPays(CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole());

        return CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->sole();
    }

    /** من يجهّز — يتبع دورَه كما يعمل الموظّفون اليوم */
    private function bench(string $email = 'bench@prep.om', array $branches = []): User
    {
        $u = User::create([
            'business_id' => $this->shop->id, 'name' => 'المجهّز '.$email, 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'delivery', 'job_title' => 'مجهّز', 'status' => 'نشط',
        ]);
        if ($branches !== []) {
            $u->branches()->sync($branches);
        }

        return $u;
    }

    /** ③ المالكُ يمنح الفعلَ باسمه من ملفّ الموظّف — مع ما كان يفتحه */
    private function grant(User $u): void
    {
        $this->actingAs($this->owner)->put(route('admin.employees.update', $u->id), [
            'name' => $u->name, 'job_title' => 'مجهّز', 'manual_permissions' => 1,
            'permissions' => [...Permissions::roleGrants('delivery'), Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT],
        ])->assertSessionHasNoErrors();
    }

    private function board(User $u, ?int $branch = null): array
    {
        $req = $this->actingAs($u);
        if ($branch) {
            $req = $req->withSession(['current_branch' => $branch]);
        }

        return $req->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props'];
    }

    private function card(array $props, CustomerInvoicePreparation $task): ?array
    {
        return collect($props['orders'])->firstWhere('key', 'customer-invoice:'.$task->id);
    }

    private function form(CustomerInvoicePreparation $task, array $over = []): array
    {
        $now = CustomerInvoicePreparations::formData($task->fresh());
        unset($now['branch'], $now['card_identity_confirmed']);

        return ['preparation' => array_merge($now, $over)];
    }

    private function money(CustomerInvoice $invoice): array
    {
        $invoice = $invoice->fresh('items');

        return [
            [(float) $invoice->subtotal, (float) $invoice->tax_total, (float) $invoice->total, $invoice->status, (int) $invoice->customer_id],
            $invoice->items->map(fn ($i) => [$i->description, (float) $i->quantity, (float) $i->unit_price, (float) $i->tax_amount, (float) $i->line_total])->all(),
            $invoice->paidTotal(),
            CustomerPayment::where('business_id', $this->shop->id)->count(),
            DB::table('customer_payment_allocations')->count(),
            CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->pluck('status')->all(),
            JournalEntry::where('business_id', $this->shop->id)->count(),
        ];
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /* ============================== الطريق كلّه ============================== */

    public function test_from_the_invoice_to_the_door(): void
    {
        $north = $this->till($this->branch);
        $south = $this->till($this->elsewhere);

        // ① الفاتورةُ ورابطُها — والمهمّةُ لم تظهر قبل السداد
        $invoice = $this->issueWithPreparationAndLink();
        $link = CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole();
        $waiting = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertNull($waiting->activated_at);
        $this->assertNull($this->card($this->board($this->owner), $waiting), 'لا تظهر قبل السداد');

        // ② Paymob تُشعر — مرّتين كما تفعل — فتظهر مرّةً، وورقتُها لصندوق الخوير
        $this->paymobPays($link);
        $task = $waiting->fresh();
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMOB, $task->activation_source);
        $this->assertSame(OrderStatus::CONFIRMED, $task->status);
        $job = PrintJob::where('subject_id', $task->id)->sole();
        $this->assertSame((int) $north->id, (int) $job->pos_device_id);

        // والصندوقُ يطبعها
        $this->assertSame($job->id, $this->claim($north)['id']);
        $this->ack($north, $job->id, ['ok' => true, 'via' => 'native']);
        $this->assertSame(PrintJob::PRINTED, $job->fresh()->status);

        $activatedAt = $task->activated_at->toDateTimeString();
        $before = $this->money($invoice);

        // ③ الموظّفُ يفتح اللوحة بقسمها — ولا زرّ، ولا يُقبل منه تعديل
        $bench = $this->bench();
        $props = $this->board($bench);
        $this->assertNotNull($this->card($props, $task), 'يرى المهمّة ليجهّزها');
        $this->assertNull($this->card($props, $task)['details']);
        $this->assertNull($props['invoiceDetails']);
        $this->actingAs($bench)->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'قبل المنح']))
            ->assertForbidden();

        // والمالكُ يمنحه — فيظهر الزرُّ بما يملؤه وبفروعه
        $this->grant($bench);
        $props = $this->board($bench->fresh());
        $this->assertSame('مريم البلوشية', $this->card($props, $task)['details']['recipient_name']);
        $this->assertNotNull($props['invoiceDetails']['options']['branches']);

        // ④ يعدّل الموعدَ والمستلِمَ والهاتفَ والعنوانَ والمناسبةَ والهديّةَ والبطاقةَ — وينقلها إلى صحار
        $this->actingAs($bench->fresh())->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, [
            'scheduled_for' => '2026-10-20T18:30',
            'recipient_name' => 'نورة الكندية',
            'recipient_phone' => '99112233',
            'delivery_address' => 'صحار، الحي التجاري، بناية 7',
            'is_gift' => true,
            'occasion_type' => 'other',
            'occasion_text' => 'تخرّج',
            'card_message' => 'مبارك التخرّج يا نورة',
            'sender_name' => 'خالد',
            'branch_id' => $this->elsewhere->id,
        ]))->assertSessionHasNoErrors()
            ->assertSessionHas('toast.msg', __('حُفظت بيانات التسليم — وكُتبت ورقة داخلية جديدة لطابور الصندوق، فالمطبوعة قبل التعديل لم تعد صحيحة.'));

        // ⑤ اللوحةُ وصفحةُ الفاتورة تقرآن الجديد فورًا
        $card = $this->card($this->board($this->owner), $task);
        $this->assertSame('نورة الكندية', $card['recipient']);
        $this->assertSame('99112233', $card['recipient_phone']);
        $this->assertSame('صحار، الحي التجاري، بناية 7', $card['address']);
        $this->assertSame('2026-10-20 18:30', $card['scheduled_for']);
        $this->assertSame('فرع صحار', $card['branch']);
        $this->assertSame('مبارك التخرّج يا نورة', $card['card_message']);
        $page = $this->actingAs($this->owner)->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props']['preparation']['data'];
        $this->assertSame('نورة الكندية', $page['recipient_name']);

        // والأوراقُ التي تُطبع بعده: سندُ التسليم والنسخةُ الداخليّة وورقةُ التجهيز
        $fresh = $task->fresh();
        $papers = [
            self::text(CustomerInvoicePreparations::deliveryHtml($fresh, $invoice->fresh('items'), 80)),
            self::text(CustomerInvoicePreparations::internalHtml($fresh, $invoice->fresh('items'), 80)),
        ];
        foreach ($papers as $paper) {
            $this->assertStringContainsString('نورة الكندية', $paper);
            $this->assertStringNotContainsString('مريم البلوشية', $paper);
            $this->assertStringNotContainsString('فيلا 12', $paper);
        }
        $this->assertStringContainsString('مبارك التخرّج يا نورة', $papers[0]);
        $this->assertSame('نورة الكندية', PrepSheet::forInvoiceTask($fresh->load('branch'), $invoice->fresh('items'))['recipient']['name']);
        $this->actingAs($this->owner)->get(route('admin.preparation.invoiceDeliveryNote', $task->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.preparation.invoiceSheet', $task->id))->assertOk();

        // ⑥ ورقةُ الصندوق: التي خرجت باقيةٌ بحالها، والجديدةُ لصندوق صحار وحده
        $this->assertSame(PrintJob::PRINTED, $job->fresh()->status);
        $this->assertSame(2, PrintJob::where('subject_id', $task->id)->count());
        $this->assertNull($this->claim($north), 'الخوير لا يطبع ورقةَ مهمّةٍ نُقلت عنه');
        $second = $this->claim($south);
        $this->assertNotNull($second);
        $this->assertNotSame($job->id, $second['id']);
        $this->at($south)->get(route('pos.printJobs.pdf', $second['id']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->ack($south, $second['id'], ['ok' => true, 'via' => 'native']);
        $this->assertNull($this->claim($south), 'ولا ثالثة');

        // ⑦ مهمّةٌ واحدة، وتفعيلٌ واحد، والمالُ كما كان
        $this->assertSame(1, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMOB, $fresh->activation_source);
        $this->assertSame($activatedAt, $fresh->activated_at->toDateTimeString());
        $this->paymobPays($link->fresh());
        $this->assertSame(1, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame($before, $this->money($invoice), 'تغيّر مبلغٌ أو تحصيلٌ أو قيد');

        // ⑧ ثمّ تُسلَّم — فلا تُعدَّل بعدها
        foreach ([OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED] as $to) {
            $this->actingAs($this->owner)->post(route('admin.preparation.invoiceMove', $task->id), ['status' => $to])->assertSessionHasNoErrors();
        }
        $this->actingAs($bench->fresh())->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'بعد التسليم']))
            ->assertSessionHasErrors(['preparation' => __('سُلّمت هذه المهمّة — لا تُعدَّل بعد التسليم.')]);
        $this->assertSame('نورة الكندية', $task->fresh()->recipient_name);
        $this->assertSame($before, $this->money($invoice));
    }

    /* ============================== من لا يبلغها ============================== */

    public function test_another_shop_and_a_branch_the_employee_does_not_work_at_never_reach_the_task(): void
    {
        $task = $this->paidTaskThroughTheDoors();

        // موظّفٌ في متجر الجار يملك الفعل — لا يجد المهمّة
        $neighbour = User::create([
            'business_id' => $this->other->id, 'name' => 'مجهّز الجار', 'email' => 'n@prep.om', 'password' => bcrypt('x'),
            'role' => 'delivery', 'status' => 'نشط', 'permissions' => ['dashboard', 'preparation', Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT],
        ]);
        $this->actingAs($neighbour)->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'من الجار']))
            ->assertNotFound();

        // وموظّفٌ في فرع صحار وحده — مهمّةُ الخوير ليست على لوحته، ولا يسحبها إليه
        $southOnly = $this->bench('south@prep.om', [$this->elsewhere->id]);
        $this->grant($southOnly);
        $this->assertNull($this->card($this->board($southOnly->fresh(), $this->elsewhere->id), $task));
        $this->actingAs($southOnly->fresh())->withSession(['current_branch' => $this->elsewhere->id])
            ->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'من صحار']))
            ->assertNotFound();

        // ولا من لوحة الخوير وهو لا يعمل فيه
        $this->actingAs($southOnly->fresh())->withSession(['current_branch' => $this->branch->id])
            ->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['branch_id' => $this->elsewhere->id]))
            ->assertSessionHasErrors('preparation.branch_id');

        $this->assertSame('مريم البلوشية', $task->fresh()->recipient_name);
        $this->assertSame((int) $this->branch->id, (int) $task->fresh()->branch_id);
    }

    /* ============================== الفعلُ وحده ============================== */

    public function test_the_action_alone_opens_no_board_and_grants_no_money(): void
    {
        $task = $this->paidTaskThroughTheDoors();

        // بلا قسم «لوحة التجهيز»: لا لوحة، ولا تعديل
        $alone = User::create([
            'business_id' => $this->shop->id, 'name' => 'الفعل وحده', 'email' => 'alone@prep.om', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['dashboard', Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT],
        ]);
        $this->actingAs($alone)->get(route('admin.preparation.index'))->assertForbidden();
        $this->actingAs($alone)->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'بلا لوحة']))
            ->assertForbidden();

        // والمنحُ يضيف فعلًا واحدًا — لا إصدارَ ولا تعديلَ فاتورةٍ ولا إشعارَ ولا تحصيل
        $bench = $this->bench();
        $before = Permissions::roleGrants('delivery');
        $this->grant($bench);
        $this->assertSame([Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT], array_values(array_diff($bench->fresh()->permissions, $before)));
        foreach ([Permissions::CUSTOMER_INVOICE_ISSUE, Permissions::CUSTOMER_INVOICE_CANCEL, Permissions::CUSTOMER_CREDIT_NOTE,
            Permissions::CUSTOMER_PAYMENT_CREATE, Permissions::PAYMENT_LINK_CREATE, 'order.edit'] as $money) {
            $this->assertFalse($bench->fresh()->may($money), $money);
        }

        // ومتجرٌ لم يُفتح له التجهيزُ بعد السداد: لا باب — ولو للمالك
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => false])->save();
        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'مغلق']))
            ->assertNotFound();
        $this->assertSame('مريم البلوشية', $task->fresh()->recipient_name);
    }

    /* ============================== ورقةٌ فشلت ============================== */

    /**
     * ورقةٌ لم تخرج على صندوق الخوير، ثمّ نُقلت المهمّةُ إلى صحار — فتُعاد هناك.
     *
     * كانت المنتظِرةُ وحدها تتبع المهمّة، فالتي فشلت تبقى على فرعها القديم:
     * «إعادة الطباعة» من اللوحة تُرسلها إلى صندوق الخوير، والمستلِمُ والعنوانُ
     * صارا لصحار.
     */
    public function test_a_failed_paper_follows_its_task_and_is_resent_to_the_new_branch(): void
    {
        $north = $this->till($this->branch);
        $south = $this->till($this->elsewhere);
        $task = $this->paidTaskThroughTheDoors();
        $job = PrintJob::where('subject_id', $task->id)->sole();

        $this->claim($north);
        $this->ack($north, $job->id, ['ok' => false, 'error' => 'انقطع الاتصال بالطابعة', 'may_have_printed' => true]);
        $this->assertSame(PrintJob::FAILED, $job->fresh()->status);

        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['branch_id' => $this->elsewhere->id]))
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame(PrintJob::FAILED, $job->status, 'لا تُعاد وحدها');
        $this->assertSame((int) $this->elsewhere->id, (int) $job->branch_id);
        $this->assertSame(1, PrintJob::where('subject_id', $task->id)->count(), 'لا ورقةَ ثانية لما لم يخرج');

        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'success');
        $this->assertNull($this->claim($north), 'لا تعود إلى صندوق الخوير');
        $this->assertSame($job->id, $this->claim($south)['id']);
    }
}
