<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoicePreparation;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\PosTerminal;
use App\Support\PrintJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «فاتورة عميل — نسخة داخلية» تنتظر صندوقَها — وتُطبع مرّةً واحدة.
 *
 * الخادمُ لا يبلغ طابعةَ المحلّ: الورقةُ تُكتب مهمّةً في طابور، والصندوقُ
 * المعيَّن يحجزها ويطبعها ويقول ما جرى (`PrintJobs`).
 *
 * ═══ ما يُحرس ═══
 *
 *   - مهمّةٌ واحدةٌ للورقة، ولا يحجزها صندوقان.
 *   - صندوقٌ غيرُ معيَّن، أو من متجرٍ آخر، لا يرى شيئًا ولا يحجز.
 *   - صندوقٌ مغلق: الورقةُ تنتظر ولا تضيع، ويستلمها صندوقُ الفرع حين يُعيَّن.
 *   - فشلٌ لم يُطبع منه شيء يعود إلى الطابور حتى حدّه؛ وما قد يكون خرج
 *     منه، أو حجزٌ لم يُردّ عليه، لا يُعاد وحده.
 *   - والورقةُ تُنزَّل لمن حجزها وحده.
 *
 * والطابعةُ نفسُها لا تُقاس هنا: ما يخرج منها يُرى على جهازٍ حقيقيّ.
 */
class APaidCustomerInvoicePrintsOnceAtItsTillTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $branch;

    private Branch $elsewhere;

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 09:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $this->other->forceFill(['customer_invoice_preparation_enabled' => true])->save();

        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوير']);
        $this->elsewhere = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع صحار']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@till.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** صندوقٌ في فرعٍ — وطابعتُه تستلم فواتيرَ العملاء إن طُلب */
    private function till(?Branch $branch = null, bool $designated = true, ?Business $shop = null): PosDevice
    {
        $shop ??= $this->shop;
        $raw = Str::random(64);
        $device = PosDevice::create([
            'business_id' => $shop->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'name' => 'صندوق '.Str::random(4),
            'token_hash' => hash('sha256', $raw),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);
        $this->tokens[$device->id] = $raw;

        $device->peripherals()->create([
            'business_id' => $shop->id, 'name' => 'طابعة الكاشير', 'type' => PosPeripheral::PRINTER,
            'connection' => 'network', 'address' => '192.168.1.50', 'port' => 9100, 'paper_width' => 80,
            'active' => true, 'auto_print_customer_invoices' => $designated,
        ]);

        return $device;
    }

    /** الطلبُ صادرٌ من هذا الصندوق — بصمتُه في كوكيه */
    private function at(PosDevice $device, ?User $user = null)
    {
        // و`withCredentials`: طلبُ JSON في الاختبار لا يحمل الكوكي بدونها — كما لا يحمله `fetch` بلا `credentials`
        return $this->actingAs($user ?? $this->owner)
            ->withCredentials()
            ->withCookie(PosTerminal::COOKIE, $device->id.'|'.$this->tokens[$device->id]);
    }

    /** فاتورةٌ تُسدَّد فتظهر مهمّتُها — وتُكتب ورقةُ صندوقها */
    private function paidTask(?Branch $branch = null): CustomerInvoicePreparation
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 40],
        ]));
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => ($branch ?? $this->branch)->id,
            'scheduled_for' => '2026-10-19 15:00', 'recipient_name' => 'سالم', 'recipient_phone' => '91234567',
        ], $this->owner->id);
        CustomerPayments::record($this->shop->id, $this->customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2026-10-19'], [$invoice->id => (float) $invoice->total]);

        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        return $task;
    }

    private function claim(PosDevice $device): ?array
    {
        return $this->at($device)->postJson(route('pos.printJobs.claim'))->assertOk()->json('job');
    }

    private function ack(PosDevice $device, int $id, array $body): array
    {
        return $this->at($device)->postJson(route('pos.printJobs.ack', $id), $body)->assertOk()->json();
    }

    /* ============================== الحجز ============================== */

    public function test_the_designated_till_claims_the_paper_once_and_says_it_printed(): void
    {
        $till = $this->till();
        $task = $this->paidTask();

        $job = PrintJob::sole();
        $this->assertSame(PrintJob::PENDING, $job->status);
        $this->assertSame($till->id, (int) $job->pos_device_id, 'لصندوق الفرع المعيَّن');
        $this->assertSame($task->id, (int) $job->subject_id);

        $this->assertSame(['designated' => true, 'waiting' => 1], $this->at($till)->getJson(route('pos.printJobs.index'))->assertOk()->json());

        $claimed = $this->claim($till);
        $this->assertSame($job->id, $claimed['id']);
        $this->assertSame($task->invoice->number, $claimed['number']);
        $this->assertSame(PrintJob::CLAIMED, $job->fresh()->status);

        // والورقةُ نفسُها لمن حجزها: PDF الشريط
        $this->at($till)->get(route('pos.printJobs.pdf', $job->id))->assertOk()->assertHeader('content-type', 'application/pdf');

        // ولا يحجزها مرّتين
        $this->assertNull($this->claim($till));

        $this->ack($till, $job->id, ['ok' => true, 'via' => 'native']);
        $job->refresh();
        $this->assertSame(PrintJob::PRINTED, $job->status);
        $this->assertSame('native', $job->via);
        $this->assertNotNull($job->printed_at);
        $this->assertSame('طُبعت على طابعة الصندوق', PrintJobs::label($job));

        // ولا ورقةَ ثانيةً بعد الطباعة
        $this->assertNull($this->claim($till));
        $this->assertSame(1, PrintJob::count());
    }

    public function test_two_tills_never_share_one_paper(): void
    {
        $first = $this->till();
        $second = $this->till();
        // ورقةٌ للفرع كلّه: لا صندوقَ بعينه كان معيَّنًا حين كُتبت
        $this->paidTask();
        PrintJob::query()->update(['pos_device_id' => null]);

        $a = $this->claim($first);
        $b = $this->claim($second);

        $this->assertNotNull($a);
        $this->assertNull($b, 'صندوقٌ ثانٍ لا يحجز ما حجزه الأوّل');

        // ولا يكتب على حجز غيره، ولا ينزّل ورقته
        $this->assertFalse($this->ack($second, $a['id'], ['ok' => true])['recorded']);
        $this->at($second)->get(route('pos.printJobs.pdf', $a['id']))->assertNotFound();
        $this->assertSame(PrintJob::CLAIMED, PrintJob::find($a['id'])->status);
    }

    /**
     * والسباقُ نفسُه: صندوقٌ آخر يحجزها بين أن تُقرأ وأن تُكتب.
     *
     * الحجزُ شرطٌ في UPDATE (`status = pending`)، لا قراءةٌ ثمّ كتابة: من قرأها
     * معلَّقةً ثمّ سبقه غيرُه لا يكتب فوق حجزه.
     */
    public function test_a_till_that_loses_the_race_does_not_take_the_paper(): void
    {
        $mine = $this->till();
        $theirs = $this->till();
        $this->paidTask();
        PrintJob::query()->update(['pos_device_id' => null]);
        $job = PrintJob::sole();

        $raced = false;
        DB::listen(function ($q) use (&$raced, $job, $theirs) {
            if (! $raced && str_contains($q->sql, 'select "id" from "print_jobs"')) {
                $raced = true;
                DB::table('print_jobs')->where('id', $job->id)
                    ->update(['status' => PrintJob::CLAIMED, 'claimed_by_device_id' => $theirs->id, 'claim_expires_at' => now()->addMinutes(3)]);
            }
        });

        $this->assertNull(PrintJobs::claim($mine), 'من سُبق لا يحجز');
        $this->assertTrue($raced, 'السباقُ وقع فعلًا');
        $this->assertSame($theirs->id, (int) $job->fresh()->claimed_by_device_id);
    }

    public function test_a_till_without_a_designated_printer_or_from_another_shop_claims_nothing(): void
    {
        $this->paidTask();
        $plain = $this->till(null, false);
        $foreign = $this->till(null, true, $this->other);

        foreach ([$plain, $foreign] as $device) {
            $this->assertNull($this->claim($device));
            $this->assertSame(0, $this->at($device)->getJson(route('pos.printJobs.index'))->json('waiting'));
        }
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status);
    }

    /** وصندوقٌ مغلقٌ أو لم يُعيَّن بعد — الورقةُ تنتظر ولا تضيع */
    public function test_with_no_till_the_paper_waits_and_the_branch_till_takes_it_later(): void
    {
        $this->paidTask();

        $job = PrintJob::sole();
        $this->assertNull($job->pos_device_id);
        $this->assertSame(PrintJob::PENDING, $job->status);
        $this->assertSame('بانتظار الطباعة على الصندوق', PrintJobs::label($job));

        // ساعاتٌ تمضي ولا شيءَ يُسقطها
        Carbon::setTestNow(now()->addHours(6));
        PrintJobs::expireClaims($this->shop->id);
        $this->assertSame(PrintJob::PENDING, $job->fresh()->status);

        // وصندوقُ فرعٍ آخر لا يأخذها؛ وصندوقُ فرعها يأخذها حين يُعيَّن
        $this->assertNull($this->claim($this->till($this->elsewhere)));
        $this->assertSame($job->id, $this->claim($this->till())['id']);
    }

    /* ============================== الفشل ============================== */

    public function test_a_failure_that_printed_nothing_returns_to_the_queue_until_its_limit(): void
    {
        $till = $this->till();
        $this->paidTask();

        foreach (range(1, PrintJobs::MAX_ATTEMPTS) as $attempt) {
            $job = $this->claim($till);
            $this->assertNotNull($job, "المحاولة {$attempt}");
            $this->ack($till, $job['id'], ['ok' => false, 'error' => 'PRINTER_UNREACHABLE', 'may_have_printed' => false]);
        }

        $job = PrintJob::sole();
        $this->assertSame(PrintJob::FAILED, $job->status, 'بعد حدّها ينتظر إنسانًا');
        $this->assertSame(PrintJobs::MAX_ATTEMPTS, $job->attempts);
        $this->assertNull($this->claim($till));
    }

    public function test_a_paper_that_may_have_printed_is_not_printed_again_by_itself(): void
    {
        $till = $this->till();
        $this->paidTask();

        $job = $this->claim($till);
        $this->ack($till, $job['id'], ['ok' => false, 'error' => 'SEND_FAILED', 'may_have_printed' => true]);

        $this->assertSame(PrintJob::FAILED, PrintJob::sole()->status);
        $this->assertNull($this->claim($till));
    }

    /** وحجزٌ لم يُردّ عليه في مهلته — قد يكون خرج: «فشل» لا عودةٌ صامتة */
    public function test_a_claim_left_unanswered_fails_instead_of_printing_twice(): void
    {
        $till = $this->till();
        $other = $this->till();
        $this->paidTask();
        PrintJob::query()->update(['pos_device_id' => null]);

        $this->assertNotNull($this->claim($till));

        Carbon::setTestNow(now()->addSeconds(PrintJobs::CLAIM_SECONDS + 1));
        $this->assertNull($this->claim($other), 'لا يأخذها صندوقٌ آخر بعد المهلة');

        $job = PrintJob::sole();
        $this->assertSame(PrintJob::FAILED, $job->status);
        $this->assertStringContainsString('لم يؤكّد الصندوق', (string) $job->last_error);
    }

    /** ومن يجهّز يُعيدها إلى الطابور بعد أن نظر في الطابعة — لا وهي تُطبع */
    public function test_the_board_requeues_a_failed_paper_but_not_one_being_printed(): void
    {
        $till = $this->till();
        $task = $this->paidTask();

        $job = $this->claim($till);
        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSame(PrintJob::CLAIMED, PrintJob::sole()->status, 'لا تُعاد وهي تُطبع');

        $this->ack($till, $job['id'], ['ok' => false, 'may_have_printed' => true]);
        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'success');

        $requeued = PrintJob::sole();
        $this->assertSame(PrintJob::PENDING, $requeued->status);
        $this->assertSame($this->owner->id, (int) $requeued->requeued_by);
        $this->assertSame($job['id'], $this->claim($till)['id']);
    }

    /** والطباعةُ اليدويّة من اللوحة لا تكتب ورقةً ثانيةً في الطابور */
    public function test_a_manual_reprint_from_the_board_does_not_queue_a_second_paper(): void
    {
        $task = $this->paidTask();

        $this->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertOk();
        $this->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertOk();

        $this->assertSame(1, PrintJob::count());
    }

    /** وصندوقُ متجرٍ آخر لا ينزّل ورقةً ولا يكتب عليها — ولو عرف رقمها */
    public function test_a_till_of_another_shop_cannot_download_a_paper(): void
    {
        $till = $this->till();
        $this->paidTask();
        $job = $this->claim($till);

        $foreign = $this->till(null, true, $this->other);
        $this->at($foreign)->get(route('pos.printJobs.pdf', $job['id']))->assertNotFound();
        $this->at($foreign)->postJson(route('pos.printJobs.ack', $job['id']), ['ok' => true])->assertNotFound();
        $this->assertSame(PrintJob::CLAIMED, PrintJob::sole()->status);
    }

    /** وصندوقٌ بلا بصمةٍ لا يحجز ولا ينزّل */
    public function test_a_browser_that_is_not_a_till_sees_no_queue(): void
    {
        $this->till();
        $this->paidTask();
        $job = PrintJob::sole();

        $this->actingAs($this->owner)->postJson(route('pos.printJobs.claim'))->assertOk()->assertExactJson(['job' => null]);
        $this->actingAs($this->owner)->getJson(route('pos.printJobs.index'))->assertOk()->assertExactJson(['designated' => false, 'waiting' => 0]);
        $this->assertContains($this->actingAs($this->owner)->get(route('pos.printJobs.pdf', $job->id))->status(), [302, 404]);
        $this->assertSame(PrintJob::PENDING, $job->fresh()->status);
    }
}
