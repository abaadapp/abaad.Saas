<?php

namespace Tests\Feature;

use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\PrintJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TillsAndPaidInvoices;
use Tests\TestCase;

/**
 * فتحُ حوار الطباعة ليس ورقًا — و«طُبعت» تُقال عمّا خرج وحده.
 *
 * المتصفّحُ يفتح حوارَه أو ملفَّ الـPDF، ولا يقول أطَبع الكاشيرُ أم ألغى.
 * فكان الصندوقُ يردّ «نجحت» فتُكتب الورقةُ «مطبوعة» ولم يخرج منها شيء.
 *
 * ═══ ما يُحرس ═══
 *
 *   - «طُبعت» لطابعة الشبكة في تطبيق أبعاد وحدها (`native`)، أو لإنسانٍ يقولها.
 *   - وما فُتح حوارُه (`page`·`share`·`browser`·`system`) «طُلب»: لا يُطبع وحده
 *     مرّةً أخرى، ويُسأل عنه الكاشيرُ «خرجت؟» — ولو أُعيد تحميلُ الصفحة.
 *   - «لم تخرج» تُعيدها إلى طابور صندوقها لتُطبع بضغطةٍ أخرى، لا وحدها.
 *   - ولا يجيب عنها إلّا صندوقُها، ولا يعود ما قد يكون خرج إلّا بيد.
 *   - واللوحةُ تقول «خرجت» عمّا طُبع بيدٍ منها — فلا يطبعه الصندوق بعدُ.
 *   - والورقتان اليدويّتان لمن يجهّز وحده، وسندُ التسليم لا يُكتب في الطابور.
 *   - ولا مال ولا ضريبةَ ولا مخزونَ يتحرّك بشيءٍ من هذا كلّه.
 */
class AnOpenedPrintDialogIsNotAPrintedPaperTest extends TestCase
{
    use RefreshDatabase;
    use TillsAndPaidInvoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTills();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** بطاقةُ المهمّة في اللوحة — ورقتُها كما تُرسل */
    private function boardPrint(int $prepId): ?array
    {
        $cards = $this->actingAs($this->owner)->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props']['orders'];

        return collect($cards)->firstWhere('key', 'customer-invoice:'.$prepId)['print'] ?? null;
    }

    /* ============================== الحوار ============================== */

    public static function dialogs(): array
    {
        return [
            'حوارٌ في الصفحة' => ['page'],
            'قائمةُ المشاركة' => ['share'],
            'لسانُ PDF' => ['browser'],
            'حوارُ النظام في التطبيق' => ['system'],
            'طريقٌ لا يُعرف' => ['carrier-pigeon'],
            'بلا طريق' => [null],
        ];
    }

    #[DataProvider('dialogs')]
    public function test_a_dialog_or_a_file_is_requested_not_printed(?string $via): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($till);

        $this->ack($till, $job['id'], ['ok' => true, 'via' => $via]);

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::REQUESTED, $row->status);
        $this->assertNull($row->printed_at, 'لا يُقال «طُبعت» عن حوار');
        $this->assertNotNull($row->requested_at);
        $this->assertSame('فُتح حوار الطباعة على الصندوق — لم يُؤكَّد خروج الورقة', PrintJobs::label($row));

        // ولا تُطبع وحدها مرّةً ثانية — لا على صندوقها ولا على غيره، ولا يُنزَّل ملفُّها
        $this->assertNull($this->claim($till));
        $other = $this->till();
        $this->assertNull($this->claim($other));

        if ($via === 'browser') {
            /*
             * إلّا للّسان الذي فُتح في الضغطة: ردُّه يسبق طلبَ الملفّ أحيانًا،
             * فيصله في مهلة الحجز — لصندوقه وحده (`PrintJobController::justOpenedInATab`).
             */
            $this->at($till)->get(route('pos.printJobs.pdf', $row->id))->assertOk();
            $this->at($other)->get(route('pos.printJobs.pdf', $row->id))->assertNotFound();
            $this->travel(PrintJobs::CLAIM_SECONDS + 1)->seconds();
        }

        $this->at($till)->get(route('pos.printJobs.pdf', $row->id))->assertNotFound();

        // ويُسأل عنها الكاشير — من الخادم، فيبقى السؤالُ بعد إعادة التحميل
        $this->assertSame(['designated' => true, 'waiting' => 0, 'awaiting' => [['id' => $row->id, 'number' => $task->invoice->number]]], $this->queue($till));
    }

    public function test_only_a_network_print_from_the_app_is_printed_by_itself(): void
    {
        $till = $this->till();
        $this->paidTask();
        $job = $this->claim($till);

        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'native']);

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PRINTED, $row->status);
        $this->assertNotNull($row->printed_at);
        $this->assertNull($row->confirmed_by);
        $this->assertSame('طُبعت على طابعة الصندوق', PrintJobs::label($row));
        $this->assertSame([], $this->queue($till)['awaiting']);
    }

    public function test_the_cashier_says_it_came_out(): void
    {
        $till = $this->till();
        $this->paidTask();
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'page']);

        $this->assertTrue($this->answer($till, $job['id'], true)['recorded']);

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PRINTED, $row->status);
        $this->assertSame($this->owner->id, (int) $row->confirmed_by);
        $this->assertNotNull($row->confirmed_at);
        $this->assertSame('أُكّد خروج الورقة', PrintJobs::label($row));
        $this->assertSame(['designated' => true, 'waiting' => 0, 'awaiting' => []], $this->queue($till));
        $this->assertNull($this->claim($till));
    }

    /** ألغى الكاشيرُ الحوار، أو لم تطبع الطابعة — تعود لتُطبع بضغطةٍ أخرى */
    public function test_a_cancelled_dialog_goes_back_to_its_till_for_another_click_not_by_itself(): void
    {
        $till = $this->till();
        $this->till();
        $this->paidTask();
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'page']);

        $this->assertTrue($this->answer($till, $job['id'], false)['recorded']);

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PENDING, $row->status);
        $this->assertSame($till->id, (int) $row->pos_device_id, 'إلى طابور صندوقها');
        $this->assertNull($row->requested_at);
        $this->assertNull($row->printed_at);
        $this->assertSame('قال الصندوق إن الورقة لم تخرج.', $row->last_error);
        $this->assertSame(['designated' => true, 'waiting' => 1, 'awaiting' => []], $this->queue($till));

        // والورقةُ نفسُها في الضغطة التالية — لا نسخةٌ ثانية
        $this->assertSame($job['id'], $this->claim($till)['id']);
        $this->assertSame(1, PrintJob::count());
    }

    public function test_only_the_till_that_opened_the_dialog_answers_for_it(): void
    {
        $till = $this->till();
        $other = $this->till();
        $this->paidTask();
        $waiting = $this->paidTask();
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'page']);

        // ولا يُسأل عنها غيرُه — الكاشيرُ الذي لم يفتح حوارَها لا يعرف أخرجت
        $this->assertSame([], $this->queue($other)['awaiting']);
        $this->assertFalse($this->answer($other, $job['id'], true)['recorded']);
        $this->assertFalse($this->answer($other, $job['id'], false)['recorded']);
        $this->assertSame(PrintJob::REQUESTED, PrintJob::find($job['id'])->status);

        // ولا يُجاب عن ورقةٍ لم يُفتح حوارُها — تنتظر أو تُطبع
        $pending = PrintJob::where('subject_id', $waiting->id)->sole();
        $this->assertFalse($this->answer($till, $pending->id, true)['recorded']);
        $this->assertSame(PrintJob::PENDING, $pending->fresh()->status);

        // ولا صندوقُ متجرٍ آخر
        $neighbour = $this->till(null, true, $this->other);
        $this->at($neighbour)->postJson(route('pos.printJobs.confirm', $job['id']), ['printed' => true])->assertNotFound();
        $this->assertSame(PrintJob::REQUESTED, PrintJob::find($job['id'])->status);
    }

    /* ============================== ما قد يكون خرج ============================== */

    public function test_an_ambiguous_paper_waits_for_a_person_and_never_goes_out_by_itself(): void
    {
        $till = $this->till();
        $spare = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => false, 'error' => 'SEND_FAILED', 'may_have_printed' => true]);

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::FAILED, $row->status);

        // لا صندوقُها ولا غيرُه، ولا مهلةٌ تمضي، ولا صندوقٌ يُلغى
        $this->assertNull($this->claim($till));
        $this->assertNull($this->claim($spare));
        Carbon::setTestNow(now()->addHours(3));
        PrintJobs::expireClaims($this->shop->id);
        PrintJobs::release($till);
        CustomerInvoicePreparations::reconcile();
        $this->assertSame(PrintJob::FAILED, $row->fresh()->status);
        $this->assertSame(['confirmable' => true, 'requeueable' => true], array_intersect_key($this->boardPrint($task->id), ['confirmable' => 1, 'requeueable' => 1]));

        // وإنسانٌ نظر فرآها خرجت — فلا تُعاد
        $this->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertSessionHas('toast.type', 'success');
        $this->assertSame(PrintJob::PRINTED, $row->fresh()->status);
        $this->assertNull($this->claim($till));
    }

    /* ============================== اللوحة ============================== */

    public function test_the_board_confirms_a_paper_printed_by_hand_so_the_till_skips_it(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $this->assertSame(['confirmable' => true, 'requeueable' => false], array_intersect_key($this->boardPrint($task->id), ['confirmable' => 1, 'requeueable' => 1]));

        // طُبعت بزرّ اللوحة — ثمّ قيل «خرجت»
        $this->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertOk();
        $this->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertSessionHas('toast.type', 'success');

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PRINTED, $row->status);
        $this->assertSame($this->owner->id, (int) $row->confirmed_by);
        $this->assertNull($this->claim($till), 'لا يطبعها الصندوقُ بعد أن خرجت بيد');
        $this->assertSame(0, $this->queue($till)['waiting']);
        $this->assertSame(['confirmable' => false, 'requeueable' => false], array_intersect_key($this->boardPrint($task->id), ['confirmable' => 1, 'requeueable' => 1]));
    }

    public function test_the_board_neither_confirms_nor_requeues_a_paper_being_printed_or_already_out(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($till);

        $this->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertSessionHas('toast.type', 'danger');
        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSame(PrintJob::CLAIMED, PrintJob::sole()->status, 'ما يُطبع الآن لا يُمسّ');
        $this->assertSame(['confirmable' => false, 'requeueable' => false], array_intersect_key($this->boardPrint($task->id), ['confirmable' => 1, 'requeueable' => 1]));

        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'native']);
        $printedAt = PrintJob::sole()->printed_at;

        $this->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertSessionHas('toast.type', 'danger');
        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'danger');
        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PRINTED, $row->status, 'ما خرج لا يُعاد إلى طابورٍ يطبع وحده');
        $this->assertNull($row->confirmed_by);
        $this->assertEquals($printedAt, $row->printed_at);
    }

    public function test_the_board_sends_back_a_dialog_nobody_confirmed(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'browser']);

        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'success');

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PENDING, $row->status);
        $this->assertNull($row->requested_at);
        $this->assertSame([], $this->queue($till)['awaiting']);
        $this->assertSame($job['id'], $this->claim($till)['id']);
    }

    /* ============================== لا نسخةَ ثانية ============================== */

    /**
     * كلُّ ما يجري وحده — نبضاتُ صندوقين، وانقضاءُ المهل، والمطابقة، وإلغاءُ
     * صندوق — لا يُرسل الورقةَ إلى طابعةٍ مرّةً ثانية. الحجزُ الثاني بقرار إنسان.
     */
    public function test_nothing_but_a_person_sends_one_paper_to_a_printer_twice(): void
    {
        $till = $this->till();
        $spare = $this->till();
        $task = $this->paidTask();

        $tick = function () use ($till, $spare) {
            foreach (range(1, 3) as $i) {
                $this->assertNull($this->claim($till));
                $this->assertNull($this->claim($spare));
            }
            Carbon::setTestNow(now()->addMinutes(10));
            PrintJobs::expireClaims($this->shop->id);
            CustomerInvoicePreparations::reconcile();
        };

        $first = $this->claim($till);
        $this->ack($till, $first['id'], ['ok' => true, 'via' => 'page']);
        $tick();
        $this->assertSame(PrintJob::REQUESTED, PrintJob::sole()->status);

        // الكاشيرُ: لم تخرج — ثمّ يضغط «طباعة» مرّةً ثانية
        $this->answer($till, $first['id'], false);
        $second = $this->claim($till);
        $this->assertSame($first['id'], $second['id']);
        $this->ack($till, $second['id'], ['ok' => true, 'via' => 'native']);
        $tick();

        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PRINTED, $row->status);
        $this->assertSame(2, $row->attempts, 'حجزان: الأوّلُ وما طلبه إنسانٌ بعده');
        $timeline = collect($this->getJson(route('admin.preparation.invoiceTimeline', $task->id))->json('events'))->pluck('text');
        $this->assertSame(2, $timeline->filter(fn ($t) => str_contains($t, 'حجزها صندوق'))->count());
    }

    public function test_every_step_of_the_paper_is_in_the_tasks_timeline(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'page']);
        $this->answer($till, $job['id'], false);
        $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'native']);

        $texts = collect($this->getJson(route('admin.preparation.invoiceTimeline', $task->id))->json('events'))->pluck('text')->values();
        $expected = [
            'كُتبت ورقة «فاتورة عميل — نسخة داخلية»',
            'حجزها صندوق «'.$till->name.'»',
            'فُتح حوار طباعتها على صندوق «'.$till->name.'»',
            'إنّها لم تخرج — عادت إلى الطابور',
            'حجزها صندوق «'.$till->name.'»',
            'طبعها صندوق «'.$till->name.'» على طابعته',
        ];

        $at = -1;
        foreach ($expected as $step) {
            $found = $texts->search(fn ($t, $i) => $i > $at && str_contains($t, $step));
            $this->assertNotFalse($found, 'في السجلّ بترتيبه: '.$step);
            $at = $found;
        }
        $this->assertStringContainsString($task->invoice->number, (string) $texts->first(fn ($t) => str_contains($t, 'طبعها صندوق')));
    }

    /* ============================== الورقتان اليدويّتان ============================== */

    public function test_the_manual_papers_open_to_the_board_alone_and_the_delivery_note_never_queues(): void
    {
        $task = $this->paidTask();

        $bench = User::create([
            'business_id' => $this->shop->id, 'name' => 'المجهِّز', 'email' => 'bench@till.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['preparation'],
        ]);
        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'الكاشير', 'email' => 'cash@till.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['pos'],
        ]);

        foreach (['invoiceDeliveryNote', 'invoiceInternalPdf'] as $paper) {
            $this->actingAs($bench)->get(route('admin.preparation.'.$paper, $task->id))->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->actingAs($cashier)->get(route('admin.preparation.'.$paper, $task->id))->assertForbidden();
        }
        $this->actingAs($cashier)->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.preparation.invoiceRequeue', $task->id))->assertForbidden();
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status);

        // سندُ التسليم بيدٍ وحدها — لا ورقةَ له في الطابور ولو طُبع مرّات
        $this->actingAs($bench)->get(route('admin.preparation.invoiceDeliveryNote', $task->id))->assertOk();
        $this->assertSame([PrintJob::KIND_INVOICE_INTERNAL], PrintJob::pluck('kind')->all());

        // ومن يجهّز يقول «خرجت» عمّا طبعه بيده
        $this->actingAs($bench)->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertSessionHas('toast.type', 'success');
        $this->assertSame($bench->id, (int) PrintJob::sole()->confirmed_by);
    }

    /* ============================== لا مالَ يتحرّك ============================== */

    public function test_printing_answering_requeueing_and_reassigning_touch_no_money_tax_or_stock(): void
    {
        $till = $this->till();
        $spare = $this->till();
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'بوكيه', 'price' => 50, 'cost' => 10, 'quantity' => 7]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['product_id' => $product->id, 'description' => 'بوكيه', 'quantity' => 2, 'unit_price' => 50],
        ]));
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->branch->id,
            'scheduled_for' => '2026-10-19 15:00', 'recipient_name' => 'سالم', 'recipient_phone' => '91234567',
        ], $this->owner->id);
        CustomerPayments::record($this->shop->id, $this->customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2026-10-19'], [$invoice->id => (float) $invoice->total]);
        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        $books = fn () => [
            'orders' => Order::count(),
            'invoices' => CustomerInvoice::count(),
            'invoice' => CustomerInvoice::whereKey($invoice->id)->first(['status', 'subtotal', 'tax_total', 'total'])->toArray(),
            'outstanding' => round(CustomerInvoice::find($invoice->id)->outstanding(), 3),
            'entries' => JournalEntry::count(),
            'lines' => [JournalLine::count(), round((float) JournalLine::sum('debit'), 3), round((float) JournalLine::sum('credit'), 3)],
            'payments' => [CustomerPayment::count(), round((float) CustomerPayment::sum('amount'), 3)],
            'movements' => InventoryMovement::count(),
            'stock' => (float) Product::whereKey($product->id)->value('quantity'),
            'revenue' => round(Ledger::balance($this->shop->id, 'sales'), 3),
            'vat' => round(Ledger::balance($this->shop->id, 'tax_payable'), 3),
        ];
        $before = $books();
        $this->assertGreaterThan(0, (float) $before['invoice']['tax_total'], 'فاتورةٌ عليها ضريبة — ليُقاس أنّها لا تتحرّك');

        // حياةُ الورقة كلُّها
        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => true, 'via' => 'page']);
        $this->answer($till, $job['id'], false);
        $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => false, 'error' => 'SEND_FAILED', 'may_have_printed' => true]);
        $this->delete(route('admin.devices.revoke', $till->id));
        $this->post(route('admin.preparation.invoiceRequeue', $task->id));
        $this->claim($spare);
        $this->ack($spare, $job['id'], ['ok' => true, 'via' => 'native']);
        $this->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertOk();
        $this->get(route('admin.preparation.invoiceDeliveryNote', $task->id))->assertOk();
        PrintJobs::cancelPending($this->shop->id);

        $this->assertSame(PrintJob::PRINTED, PrintJob::sole()->status);
        $this->assertSame($before, $books(), 'لا بيعَ ولا قيدَ ولا دفعةَ ولا ضريبةَ ولا حركةَ مخزون');
    }
}
