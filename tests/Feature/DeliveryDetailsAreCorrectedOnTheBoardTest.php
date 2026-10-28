<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\JournalEntry;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\Permissions;
use App\Support\PrepSheet;
use App\Support\PrintJobs;
use App\Support\SectionActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «تعديل تفاصيل التسليم» من لوحة التجهيز — بفعلٍ باسمه، ولا مالَ فيه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الفعلُ `customerInvoice.preparation.edit` باسمه: لصاحب النشاط والمدير
 *     بدورهما، ولا يُورَث مع قسم اللوحة، ولا يمنحه ترحيلُ الصلاحيات.
 *   - الموظّفُ المخوَّل يعدّل مهمّةً سُدّدت عبر Paymob وظهرت في اللوحة؛ وغيرُ
 *     المخوَّل يفتح اللوحة ولا يرى الزرّ ويُردّ بـ٤٠٣.
 *   - لا بندَ ولا سعرَ ولا ضريبةَ ولا تحصيلَ ولا قيدَ يتغيّر — ولو أُرسل.
 *   - ما سُلّم لا يُعدَّل، ومتجرٌ لا يبلغ مهمّةَ غيره، وفرعٌ لا يُنقل إليه ولا منه
 *     إلّا ممّن يعمل فيه.
 *   - اللوحةُ والفاتورةُ والأوراقُ الثلاث تقرأ الجديد؛ والورقةُ التي خرجت تُكتب
 *     لها ورقةٌ جديدة، والمنتظِرةُ تتبع فرعَها.
 */
class DeliveryDetailsAreCorrectedOnTheBoardTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 09:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود التسليم', 'type' => 'عام', 'status' => 'نشط', 'phone' => '24000000']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $this->other->forceFill(['customer_invoice_preparation_enabled' => true])->save();

        $this->north = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوير']);
        $this->south = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع صحار']);
        $this->owner = $this->user('admin', 'owner@details.om');
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم الحارثي', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function user(string $role, string $email, ?array $permissions = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $role.' '.$email, 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** من يجهّز ويملك تعديلَ بيانات التسليم باسمه */
    private function preparer(string $email = 'prep@details.om', bool $mayEdit = true): User
    {
        return User::where('email', $email)->first() ?? $this->user('delivery', $email, array_values(array_filter([
            'dashboard', 'preparation', $mayEdit ? Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT : null,
        ])));
    }

    /** فاتورةٌ صادرةٌ سُدّدت عبر Paymob ومهمّتُها ظاهرة في اللوحة */
    private function task(?Business $shop = null, ?Customer $customer = null, ?Branch $branch = null): CustomerInvoicePreparation
    {
        $shop ??= $this->shop;
        $customer ??= $this->customer;
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($shop->id, $customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة ورد أبيض فاخرة', 'quantity' => 2, 'unit_price' => 50, 'discount' => 5],
        ]));

        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'delivery',
            'branch_id' => ($branch ?? ($shop->is($this->shop) ? $this->north : Branch::create(['business_id' => $shop->id, 'name' => 'فرع الجار'])))->id,
            'scheduled_for' => '2026-10-19 16:00',
            'recipient_name' => 'مريم البلوشية',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير، شارع 45، فيلا 12',
            'delivery_notes' => 'الاتصال قبل الوصول',
            'internal_notes' => 'تغليف أبيض وذهبي',
        ], $this->owner->id);

        CustomerPayments::record($shop->id, $customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2026-10-19'], [$invoice->id => (float) $invoice->total]);
        [$activated] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        return $activated;
    }

    /** النموذجُ كما يملؤه الزرّ — الحاليُّ ومعه ما تغيّر */
    private function form(CustomerInvoicePreparation $task, array $over = []): array
    {
        $now = CustomerInvoicePreparations::formData($task->fresh());
        unset($now['branch'], $now['card_identity_confirmed']);

        return ['preparation' => array_merge($now, $over)];
    }

    private function edit(CustomerInvoicePreparation $task, array $over, ?User $by = null, array $extra = [])
    {
        return $this->actingAs($by ?? $this->preparer())
            ->post(route('admin.preparation.invoiceDetails', $task->id), array_merge($this->form($task, $over), $extra));
    }

    private function card(CustomerInvoicePreparation $task, ?User $user = null): ?array
    {
        $props = $this->actingAs($user ?? $this->owner)->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props'];

        return collect($props['orders'])->firstWhere('key', 'customer-invoice:'.$task->id);
    }

    /** المالُ كما هو — الفاتورةُ وبنودُها وتحصيلُها والدفتر */
    private function money(CustomerInvoice $invoice): array
    {
        $invoice = $invoice->fresh('items');

        return [
            'invoice' => [(float) $invoice->subtotal, (float) $invoice->discount_total, (float) $invoice->tax_total, (float) $invoice->total, $invoice->status, (int) $invoice->revision],
            'items' => $invoice->items->map(fn ($i) => [$i->description, (float) $i->quantity, (float) $i->unit_price, (float) $i->discount, (float) $i->tax_amount, (float) $i->line_total])->all(),
            'paid' => $invoice->paidTotal(),
            'payments' => DB::table('customer_payments')->where('business_id', $invoice->business_id)->count(),
            'allocations' => DB::table('customer_payment_allocations')->count(),
            'entries' => JournalEntry::where('business_id', $invoice->business_id)->count(),
        ];
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private const CHANGED = [
        'scheduled_for' => '2026-10-20T18:30',
        'recipient_name' => 'نورة الكندية',
        'recipient_phone' => '99112233',
        'delivery_address' => 'صحار، الحي التجاري، بناية 7',
        'delivery_notes' => 'سلّم للحارس',
        'internal_notes' => 'ورد أحمر بدل الأبيض',
        'is_gift' => true,
        'occasion_type' => 'other',
        'occasion_text' => 'تخرّج',
        'card_message' => 'مبارك التخرّج يا نورة',
        'sender_name' => 'خالد',
        'hide_sender' => false,
    ];

    /* ============================== الصلاحية ============================== */

    public function test_the_action_is_its_own_name_held_by_owner_and_manager_and_shown_with_the_board(): void
    {
        $key = Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT;
        $this->assertSame('customerInvoice.preparation.edit', $key);
        $this->assertSame('تعديل بيانات التجهيز والتسليم', Permissions::actionLabels()[$key]);

        foreach (array_keys(Permissions::MAP) as $role) {
            $u = new User(['role' => $role, 'permissions' => null]);
            $this->assertSame(in_array($role, ['admin', 'manager'], true), $u->may($key), $role);
        }

        // يُرى في شاشة صلاحيات الموظف تحت «لوحة التجهيز»
        $group = collect(SectionActions::groups())->firstWhere('key', 'preparation');
        $this->assertContains($key, $group['actions']);

        // ولا يُورَث مع القسم: ترحيلُ الصلاحيات لا يمنحه لمن يملك اللوحة
        $this->assertNotContains($key, SectionActions::withActionsOf(['dashboard', 'preparation', 'orders']));
        $this->assertArrayNotHasKey($key, SectionActions::SPLIT);
    }

    public function test_without_the_action_the_board_opens_but_the_edit_is_neither_offered_nor_accepted(): void
    {
        $task = $this->task();
        $plain = $this->preparer('plain@details.om', mayEdit: false);

        $props = $this->actingAs($plain)->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props'];
        $this->assertNull($props['invoiceDetails']);
        $this->assertNull(collect($props['orders'])->firstWhere('key', 'customer-invoice:'.$task->id)['details']);

        $this->edit($task, ['recipient_name' => 'متسلّل'], $plain)->assertForbidden();
        $this->assertSame('مريم البلوشية', $task->fresh()->recipient_name);

        // والمخوَّلُ يرى الزرَّ بما يملؤه، وفروعَه
        $props = $this->actingAs($this->preparer())->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame([$this->north->id, $this->south->id], array_column($props['invoiceDetails']['options']['branches'], 'id'));
        $this->assertSame('مريم البلوشية', collect($props['orders'])->firstWhere('key', 'customer-invoice:'.$task->id)['details']['recipient_name']);
    }

    /* ============================== التعديل ============================== */

    public function test_an_authorised_employee_corrects_a_paymob_paid_task_and_every_paper_reads_it(): void
    {
        $task = $this->task();
        $invoice = $task->invoice;
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMOB, $task->activation_source);
        $before = $this->money($invoice);

        $this->edit($task, self::CHANGED + ['branch_id' => $this->south->id])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('toast');

        $fresh = $task->fresh();
        $this->assertSame('نورة الكندية', $fresh->recipient_name);
        $this->assertSame('99112233', $fresh->recipient_phone);
        $this->assertSame((int) $this->south->id, (int) $fresh->branch_id);
        $this->assertSame('2026-10-20 18:30', $fresh->scheduled_for->format('Y-m-d H:i'));
        $this->assertSame('تخرّج', $fresh->occasion_text);
        $this->assertTrue((bool) $fresh->is_gift);
        $this->assertSame(OrderStatus::CONFIRMED, $fresh->status, 'التعديلُ لا ينقل المهمّة');

        // المالُ كما كان — لا بندَ ولا ضريبةَ ولا تحصيلَ ولا قيد
        $this->assertSame($before, $this->money($invoice));

        // اللوحة
        $card = $this->card($task);
        $this->assertSame('نورة الكندية', $card['recipient']);
        $this->assertSame('صحار، الحي التجاري، بناية 7', $card['address']);
        $this->assertSame('فرع صحار', $card['branch']);
        $this->assertSame('2026-10-20 18:30', $card['scheduled_for']);

        // صفحةُ الفاتورة
        $data = $this->actingAs($this->owner)->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertOk()->viewData('page')['props']['preparation']['data'];
        $this->assertSame('نورة الكندية', $data['recipient_name']);
        $this->assertSame('فرع صحار', $data['branch']);

        // والأوراقُ الثلاث تُبنى من الجديد — لا يبقى فيها القديم
        $invoice = $invoice->fresh('items');
        $delivery = self::text(CustomerInvoicePreparations::deliveryHtml($fresh, $invoice, 80));
        $internal = self::text(CustomerInvoicePreparations::internalHtml($fresh, $invoice, 80));
        $sheet = PrepSheet::forInvoiceTask($fresh->load('branch'), $invoice);

        foreach ([$delivery, $internal] as $paper) {
            $this->assertStringContainsString('نورة الكندية', $paper);
            $this->assertStringContainsString('صحار، الحي التجاري، بناية 7', $paper);
            $this->assertStringNotContainsString('مريم البلوشية', $paper);
            $this->assertStringNotContainsString('فيلا 12', $paper);
        }
        $this->assertStringContainsString('مبارك التخرّج يا نورة', $delivery);
        $this->assertSame('نورة الكندية', $sheet['recipient']['name']);
        $this->assertSame('فرع صحار', $sheet['branch']);

        // ومن عدّل وماذا — في سجلّ المهمّة
        $line = ActivityLog::where('subject_type', 'customer_invoice_preparation')->where('subject_id', $task->id)
            ->where('action', 'updated')->latest('id')->value('description');
        $this->assertStringContainsString('المستلم', $line);
        $this->assertStringContainsString('الفرع', $line);
        $events = $this->actingAs($this->owner)->getJson(route('admin.preparation.invoiceTimeline', $task->id))->json('events');
        $this->assertTrue(collect($events)->contains(fn ($e) => str_contains($e['text'], 'عدّل تجهيز الفاتورة')));
    }

    public function test_money_sent_with_the_form_is_never_read(): void
    {
        $task = $this->task();
        $invoice = $task->invoice;
        $before = $this->money($invoice);

        $this->edit($task, ['recipient_name' => 'نورة', 'total' => 1, 'tax_total' => 0, 'unit_price' => 1], extra: [
            'total' => 1, 'subtotal' => 1, 'tax_total' => 0, 'discount_total' => 0, 'status' => CustomerInvoice::DRAFT,
            'items' => [['description' => 'مجانًا', 'quantity' => 1, 'unit_price' => 0]],
            'customer_id' => Customer::create(['business_id' => $this->shop->id, 'name' => 'آخر', 'phone' => '92223333'])->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('نورة', $task->fresh()->recipient_name);
        $this->assertSame($before, $this->money($invoice));
        $this->assertSame((int) $this->customer->id, (int) $invoice->fresh()->customer_id);
    }

    public function test_nothing_changed_writes_nothing(): void
    {
        $task = $this->task();
        $logs = ActivityLog::where('subject_type', 'customer_invoice_preparation')->where('action', 'updated')->count();

        $this->edit($task, [])->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'info');

        $this->assertSame($logs, ActivityLog::where('subject_type', 'customer_invoice_preparation')->where('action', 'updated')->count());
        $this->assertSame(1, PrintJob::where('subject_id', $task->id)->count());
    }

    /* ============================== ما يُردّ ============================== */

    public function test_a_delivered_task_is_not_edited(): void
    {
        $task = $this->task();
        $task->forceFill(['status' => OrderStatus::DELIVERED])->save();

        $this->edit($task, ['recipient_name' => 'بعد التسليم'])
            ->assertSessionHasErrors(['preparation' => __('سُلّمت هذه المهمّة — لا تُعدَّل بعد التسليم.')]);
        $this->assertSame('مريم البلوشية', $task->fresh()->recipient_name);
    }

    public function test_a_task_not_yet_on_the_board_is_not_edited_from_it(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 20],
        ]));
        $waiting = CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->north->id, 'scheduled_for' => '2026-10-19 16:00',
            'recipient_name' => 'سالم', 'recipient_phone' => '99887766',
        ], $this->owner->id);

        $this->edit($waiting, ['recipient_name' => 'من اللوحة'])->assertNotFound();
        $this->assertSame('سالم', $waiting->fresh()->recipient_name);
    }

    public function test_another_shops_task_is_not_found(): void
    {
        $theirs = $this->task($this->other, Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '93334444']));

        $this->edit($theirs, ['recipient_name' => 'من متجرٍ آخر'])->assertNotFound();
        $this->assertSame('مريم البلوشية', $theirs->fresh()->recipient_name);
    }

    public function test_a_branch_employee_moves_no_task_into_or_out_of_a_branch_they_do_not_work_at(): void
    {
        $task = $this->task();
        $local = $this->preparer('north@details.om');
        $local->branches()->sync([$this->north->id]);

        $this->actingAs($local)->withSession(['current_branch' => $this->north->id])
            ->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['branch_id' => $this->south->id]))
            ->assertSessionHasErrors('preparation.branch_id');
        $this->assertSame((int) $this->north->id, (int) $task->fresh()->branch_id);

        // وفي فرعه يعدّل
        $this->actingAs($local)->withSession(['current_branch' => $this->north->id])
            ->post(route('admin.preparation.invoiceDetails', $task->id), $this->form($task, ['recipient_name' => 'نورة']))
            ->assertSessionHasNoErrors();
        $this->assertSame('نورة', $task->fresh()->recipient_name);

        // ومهمّةُ فرعٍ آخر لا تُبلغ من لوحة فرعه
        $south = $this->task(branch: $this->south);
        $this->actingAs($local)->withSession(['current_branch' => $this->north->id])
            ->post(route('admin.preparation.invoiceDetails', $south->id), $this->form($south, ['recipient_name' => 'نورة']))
            ->assertNotFound();

        // ولا يُعرض في نموذجه فرعٌ يُردّ عنه
        $props = $this->actingAs($local)->withSession(['current_branch' => $this->north->id])
            ->get(route('admin.preparation.index'))->viewData('page')['props'];
        $this->assertSame([$this->north->id], array_column($props['invoiceDetails']['options']['branches'], 'id'));
    }

    public function test_the_fulfilment_type_does_not_change_under_a_status_that_cannot_hold_it(): void
    {
        $task = $this->task();
        $task->forceFill(['status' => OrderStatus::OUT_FOR_DELIVERY])->save();

        $this->edit($task, ['fulfillment_type' => 'pickup'])->assertSessionHasErrors('preparation.fulfillment_type');
        $this->assertSame('delivery', $task->fresh()->fulfillment_type);

        // وما تحت حالٍ تصلح للنوعين يتبدّل
        $task->forceFill(['status' => OrderStatus::READY])->save();
        $this->edit($task, ['fulfillment_type' => 'pickup'])->assertSessionHasNoErrors();
        $this->assertSame('pickup', $task->fresh()->fulfillment_type);
        $this->assertNull($task->fresh()->delivery_address, 'الاستلامُ لا يحمل عنوانًا');
    }

    public function test_a_card_that_reveals_a_hidden_sender_is_printed_only_when_confirmed(): void
    {
        $task = $this->task();
        $over = ['is_gift' => true, 'sender_name' => 'خالد', 'hide_sender' => true, 'card_message' => 'مع حبّ خالد'];

        $this->edit($task, $over)->assertSessionHasErrors('preparation.card_message');
        $this->assertNull($task->fresh()->card_message);

        $this->edit($task, $over + ['card_identity_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('مع حبّ خالد', $task->fresh()->card_message);
    }

    /* ============================== الأوراقُ التي خرجت ============================== */

    public function test_a_printed_paper_gets_a_new_one_and_a_waiting_one_follows_its_branch(): void
    {
        $task = $this->task();
        $first = PrintJobs::forPreparation($task);
        $this->assertSame(PrintJob::PENDING, $first->status);

        // تنتظر: تُطبع ببيانات ساعتها — وتتبع المهمّةَ إلى فرعها الجديد، بلا ورقةٍ ثانية
        $this->edit($task, ['branch_id' => $this->south->id])->assertSessionHasNoErrors();
        $this->assertSame(1, PrintJob::where('subject_id', $task->id)->count());
        $this->assertSame((int) $this->south->id, (int) $first->fresh()->branch_id);

        // خرجت: ورقةٌ جديدةٌ لطابور الصندوق، والأولى باقيةٌ بحالها
        $first->forceFill(['status' => PrintJob::PRINTED, 'printed_at' => now()])->save();
        $this->edit($task, ['recipient_name' => 'نورة الكندية'])->assertSessionHasNoErrors();

        $latest = PrintJobs::forPreparation($task->fresh());
        $this->assertNotSame($first->id, $latest->id);
        $this->assertSame(PrintJob::PENDING, $latest->status);
        $this->assertSame(PrintJobs::invoiceKey($task).':2', $latest->dedupe_key);
        $this->assertSame((int) $this->south->id, (int) $latest->branch_id);
        $this->assertSame(PrintJob::PRINTED, $first->fresh()->status);
        $this->assertSame('pending', $this->card($task)['print']['status']);

        // وتعديلٌ ثانٍ والجديدةُ تنتظر — لا ثالثة
        $this->edit($task, ['recipient_phone' => '99554433'])->assertSessionHasNoErrors();
        $this->assertSame(2, PrintJob::where('subject_id', $task->id)->count());
    }

    public function test_the_invoice_page_door_reprints_a_printed_paper_too(): void
    {
        $task = $this->task();
        PrintJobs::forPreparation($task)->forceFill(['status' => PrintJob::PRINTED, 'printed_at' => now()])->save();

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.preparation.save', $task->customer_invoice_id), $this->form($task, ['recipient_name' => 'نورة']))
            ->assertSessionHasNoErrors();

        $this->assertSame('نورة', $task->fresh()->recipient_name);
        $this->assertSame(2, PrintJob::where('subject_id', $task->id)->count());
        $this->assertSame(PrintJob::PENDING, PrintJobs::forPreparation($task->fresh())->status);
    }
}
