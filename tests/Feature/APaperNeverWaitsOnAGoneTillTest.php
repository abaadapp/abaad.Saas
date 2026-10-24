<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use App\Models\User;
use App\Support\PrintJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TillsAndPaidInvoices;
use Tests\TestCase;

/**
 * ورقةٌ لا تعلق على صندوقٍ ذهب — وتبقى في فرعها ومتجرها.
 *
 * الورقةُ تُوجَّه حين تُكتب إلى أوّل صندوقٍ معيَّنٍ في فرعها. ثمّ يُلغى ذلك
 * الصندوق، أو يُحذف سجلُّه، أو يُنقل إلى فرعٍ آخر، أو تُرفع عنه طابعتُه أو
 * تُعطَّل — فكانت الورقةُ تنتظره أبدًا: لا يحجزها غيرُه، وزرُّ الإعادة لا
 * يعمل على ما ينتظر.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المنتظِرُ يُوجَّه إلى صندوقٍ معيَّنٍ في **فرعها نفسِه** (`PrintJobs::release`)،
 *     أو يبقى لفرعها كلِّه — ويحجزه صندوقُ فرعها إن بقي يشير إلى من ذهب.
 *   - صندوقٌ حيٌّ معيَّن يحتفظ بورقته: لا يسحبها جارُه في الفرع.
 *   - ولا تُسحب ورقةٌ يطبعها صندوقٌ الآن، ولا يُعاد وحده ما قد يكون خرج.
 *   - والإعادةُ بيدٍ تُوجَّه إلى صندوقٍ ما زال يستلم، لا إلى الذي ذهب.
 *   - ولا يبلغ ورقُ متجرٍ صندوقَ جاره، ولا تفتح بصمةُ صندوق الجار طابورَه هنا.
 *   - وإغلاقُ التجهيز بعد السداد يُلغي المنتظِر ويوقف كلَّ صندوق — ولا يمسّ مالًا.
 *   - وكلُّ توجيهٍ يُكتب في سجلّ المهمّة.
 */
class APaperNeverWaitsOnAGoneTillTest extends TestCase
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

    /** ما كُتب عن ورق الصناديق — بترتيبه */
    private function trail(): string
    {
        return ActivityLog::where('subject_type', 'customer_invoice_preparation')
            ->where('description', 'like', 'ورقة الصندوق%')
            ->orderBy('id')->pluck('description')->implode("\n");
    }

    private function printerPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'طابعة الكاشير', 'type' => PosPeripheral::PRINTER, 'connection' => 'network',
            'address' => '192.168.1.50', 'port' => 9100, 'paper_width' => 80,
            'active' => true, 'auto_print_customer_invoices' => true,
        ], $over);
    }

    private function boss(): User
    {
        return User::firstOrCreate(['email' => 'boss@abaadapp.om'], [
            'business_id' => null, 'name' => 'مدير المنصة', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function toggle(bool $on): void
    {
        $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط',
            'customer_invoice_preparation_enabled' => $on ? '1' : '0',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner);
    }

    /* ============================== صندوقٌ ذهب ============================== */

    public function test_a_revoked_till_hands_its_waiting_paper_to_another_designated_till_of_its_branch(): void
    {
        $first = $this->till();
        $second = $this->till();
        $this->paidTask();
        $job = PrintJob::sole();
        $this->assertSame($first->id, (int) $job->pos_device_id);

        // صندوقٌ معيَّنٌ حيٌّ يحتفظ بورقته — ولو كان مغلقًا الآن
        $this->assertNull($this->claim($second), 'لا يُسحب ورقُ صندوقٍ ما زال يستلم');

        $this->delete(route('admin.devices.revoke', $first->id))->assertRedirect();

        $job->refresh();
        $this->assertSame(PrintJob::PENDING, $job->status);
        $this->assertSame($second->id, (int) $job->pos_device_id, 'وُجّهت إلى صندوقٍ معيَّنٍ في فرعها');
        $this->assertStringContainsString('لم يعد صندوق «'.$first->name.'» يستلمها — وُجّهت إلى «'.$second->name.'» في فرعها', $this->trail());

        $this->assertNull($this->claim($first), 'والملغى لا يحجز شيئًا');
        $this->assertSame($job->id, $this->claim($second)['id']);
        $this->assertSame(1, PrintJob::count(), 'ورقةٌ واحدة — لا نسخةٌ ثانية');
    }

    public function test_a_deleted_till_leaves_its_paper_to_the_next_till_designated_in_its_branch(): void
    {
        $gone = $this->till();
        $this->paidTask();

        $this->delete(route('admin.devices.revoke', $gone->id));
        $this->delete(route('admin.devices.destroy', $gone->id))->assertRedirect();
        $this->assertNull(PosDevice::find($gone->id));

        $job = PrintJob::sole();
        $this->assertSame(PrintJob::PENDING, $job->status);
        $this->assertNull($job->pos_device_id, 'لا صندوقَ في فرعها بعد — تنتظر ولا تضيع');
        $this->assertStringContainsString('تنتظر صندوقًا يُعيَّن في فرعها', $this->trail());

        $this->assertSame(['designated' => true, 'waiting' => 0, 'awaiting' => []], $this->queue($this->till($this->elsewhere)), 'ولا يأخذها فرعٌ آخر');
        $this->assertSame($job->id, $this->claim($this->till())['id']);
    }

    public function test_a_till_moved_to_another_branch_carries_none_of_its_old_branch_papers(): void
    {
        $moving = $this->till();
        $staying = $this->till();
        $this->paidTask();
        $job = PrintJob::sole();

        $this->put(route('admin.devices.update', $moving->id), ['name' => $moving->name, 'branch_id' => $this->elsewhere->id, 'confirm_move' => true])->assertRedirect();
        $this->assertSame($staying->id, (int) $job->fresh()->pos_device_id);

        // وأُعيد تفعيلُه في فرعه الجديد — لا يحمل ورقَ فرعه القديم
        $moving->refresh()->forceFill(['status' => PosDevice::ACTIVE, 'token_hash' => hash('sha256', $this->tokens[$moving->id])])->save();
        $this->assertNull($this->claim($moving));
        $this->assertSame($job->id, $this->claim($staying)['id']);
    }

    /**
     * وما فات الشاشةَ (صفٌّ كُتب مباشرةً، أو بابٌ يُضاف غدًا) لا يعلق كذلك:
     * الصندوقُ الذي يحجز يسأل «أما زال صندوقُها يستلمها؟».
     */
    public function test_a_paper_still_pointing_at_a_till_that_left_its_branch_is_taken_by_its_branch_not_by_that_till(): void
    {
        $moved = $this->till();
        $this->paidTask();
        DB::table('pos_devices')->where('id', $moved->id)->update(['branch_id' => $this->elsewhere->id]);
        $job = PrintJob::sole();
        $this->assertSame($moved->id, (int) $job->pos_device_id);

        $this->assertNull($this->claim($moved->fresh()), 'لا يحملها إلى فرعه الجديد ولو أشارت إليه');

        $here = $this->till();
        $this->assertSame($job->id, $this->claim($here)['id']);
        $this->assertSame($here->id, (int) $job->fresh()->pos_device_id);
        $this->assertStringContainsString('صندوقُها لم يعد يستلمها — حجزها صندوق «'.$here->name.'» في فرعها', $this->trail());
    }

    public function test_a_paper_pointing_at_a_till_whose_printer_quietly_lost_the_option_is_taken_by_its_branch(): void
    {
        $quiet = $this->till();
        $this->paidTask();
        DB::table('pos_peripherals')->where('pos_device_id', $quiet->id)->update(['auto_print_customer_invoices' => false]);

        $this->assertNull($this->claim($this->till($this->elsewhere)), 'فرعٌ آخر لا يأخذها');
        $this->assertSame(PrintJob::sole()->id, $this->claim($this->till())['id']);
    }

    /* ============================== الطابعة ============================== */

    public static function printerChanges(): array
    {
        return [
            'رُفع عنها الخيار' => ['unassigned'],
            'عُطّلت' => ['switched_off'],
            'حُذفت' => ['removed'],
        ];
    }

    #[DataProvider('printerChanges')]
    public function test_a_printer_taken_off_customer_invoices_releases_its_tills_papers(string $how): void
    {
        $first = $this->till();
        $second = $this->till();
        $this->paidTask();
        $printer = $first->peripherals()->sole();

        match ($how) {
            'unassigned' => $this->put(route('admin.devices.peripherals.update', ['device' => $first->id, 'id' => $printer->id]), $this->printerPayload(['auto_print_customer_invoices' => false])),
            'switched_off' => $this->put(route('admin.devices.peripherals.update', ['device' => $first->id, 'id' => $printer->id]), $this->printerPayload(['active' => false])),
            'removed' => $this->delete(route('admin.devices.peripherals.destroy', ['device' => $first->id, 'id' => $printer->id])),
        };

        $job = PrintJob::sole();
        $this->assertSame($second->id, (int) $job->pos_device_id);
        $this->assertNull($this->claim($first));
        $this->assertSame($job->id, $this->claim($second)['id']);
    }

    public function test_editing_a_printer_that_still_serves_keeps_its_paper(): void
    {
        $first = $this->till();
        $this->till();
        $this->paidTask();
        $printer = $first->peripherals()->sole();

        $this->put(route('admin.devices.peripherals.update', ['device' => $first->id, 'id' => $printer->id]), $this->printerPayload(['name' => 'طابعة المنضدة']));

        $this->assertSame($first->id, (int) PrintJob::sole()->pos_device_id);
        $this->assertStringNotContainsString('لم يعد صندوق', $this->trail());
    }

    /* ============================== ما لا يُمسّ ============================== */

    public function test_a_paper_being_printed_is_not_reassigned_and_once_failed_is_requeued_to_a_till_that_still_serves(): void
    {
        $printing = $this->till();
        $spare = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($printing);

        $this->delete(route('admin.devices.revoke', $printing->id));
        $row = PrintJob::sole();
        $this->assertSame(PrintJob::CLAIMED, $row->status, 'لا تُسحب ورقةٌ يطبعها صندوقٌ الآن');
        $this->assertSame($printing->id, (int) $row->claimed_by_device_id);
        $this->assertNull($this->claim($spare));

        // انقطع ولم يقل شيئًا: «فشل» — ولا تُعاد وحدها ولو بقي في الفرع صندوقٌ يستلم
        Carbon::setTestNow(now()->addSeconds(PrintJobs::CLAIM_SECONDS + 1));
        $this->assertNull($this->claim($spare));
        $this->assertSame(PrintJob::FAILED, $row->fresh()->status);

        // ومن يجهّز يُعيدها — إلى صندوقٍ ما زال يستلم، لا إلى الذي ذهب
        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'success');
        $row->refresh();
        $this->assertSame(PrintJob::PENDING, $row->status);
        $this->assertSame($spare->id, (int) $row->pos_device_id);
        $this->assertSame($this->owner->id, (int) $row->requeued_by);
        $this->assertSame($job['id'], $this->claim($spare)['id']);
        $this->assertStringContainsString('أُعيدت إلى طابور صندوق «'.$spare->name.'»', $this->trail());
    }

    public function test_a_failed_paper_of_a_removed_till_stays_failed_until_a_person_sends_it_to_its_branch(): void
    {
        $only = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($only);
        $this->ack($only, $job['id'], ['ok' => false, 'error' => 'SEND_FAILED', 'may_have_printed' => true]);

        $this->delete(route('admin.devices.revoke', $only->id));
        $this->assertSame(PrintJob::FAILED, PrintJob::sole()->status, 'ما قد يكون خرج لا يُعاد وحده — ولو ذهب صندوقُه');
        $replacement = $this->till();
        $this->assertNull($this->claim($replacement), 'ولا يأخذه صندوقٌ جديدٌ وحده');

        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'success');
        $row = PrintJob::sole();
        $this->assertSame(PrintJob::PENDING, $row->status);
        $this->assertSame($replacement->id, (int) $row->pos_device_id, 'يُعاد إلى صندوقٍ يستلم في فرعها — لا إلى الذي ذهب');
        $this->assertNull($this->claim($this->till($this->elsewhere)));
        $this->assertSame($job['id'], $this->claim($replacement)['id']);
    }

    public function test_requeue_with_no_till_left_in_the_branch_leaves_the_paper_for_the_next_one(): void
    {
        $only = $this->till();
        $task = $this->paidTask();
        $job = $this->claim($only);
        $this->ack($only, $job['id'], ['ok' => false, 'may_have_printed' => true]);
        $this->delete(route('admin.devices.revoke', $only->id));

        $this->post(route('admin.preparation.invoiceRequeue', $task->id))->assertSessionHas('toast.type', 'success');

        $this->assertNull(PrintJob::sole()->pos_device_id);
        $this->assertStringContainsString('أُعيدت إلى الطابور — تنتظر صندوقًا يُعيَّن في فرعها', $this->trail());
    }

    /* ============================== السباق ============================== */

    /** صندوقان يحجزان ورقةَ صندوقٍ ذهب في اللحظة نفسِها — يأخذها أحدُهما */
    public function test_two_tills_racing_for_a_paper_of_a_gone_till_take_it_once(): void
    {
        $gone = $this->till();
        $mine = $this->till();
        $theirs = $this->till();
        $this->paidTask();
        DB::table('pos_peripherals')->where('pos_device_id', $gone->id)->update(['auto_print_customer_invoices' => false]);
        $job = PrintJob::sole();

        $raced = false;
        DB::listen(function ($q) use (&$raced, $job, $theirs) {
            if (! $raced && str_contains($q->sql, 'select "id", "pos_device_id" from "print_jobs"')) {
                $raced = true;
                DB::table('print_jobs')->where('id', $job->id)->update([
                    'status' => PrintJob::CLAIMED, 'pos_device_id' => $theirs->id,
                    'claimed_by_device_id' => $theirs->id, 'claim_expires_at' => now()->addMinutes(3),
                ]);
            }
        });

        $this->assertNull(PrintJobs::claim($mine), 'من سُبق لا يحجز');
        $this->assertTrue($raced);
        $this->assertSame($theirs->id, (int) $job->fresh()->claimed_by_device_id);
    }

    /**
     * وورقةٌ وُجّهت إلى صندوقٍ آخر بين أن تُقرأ وأن تُكتب — لا تُؤخذ من تحته.
     *
     * الكتابةُ مشروطةٌ بصندوقها كما قُرئ، لا بحالها وحدها.
     */
    public function test_a_paper_retargeted_between_the_read_and_the_write_is_not_taken_from_its_new_till(): void
    {
        $gone = $this->till();
        $mine = $this->till();
        $theirs = $this->till();
        $this->paidTask();
        DB::table('pos_peripherals')->where('pos_device_id', $gone->id)->update(['auto_print_customer_invoices' => false]);
        $job = PrintJob::sole();

        $raced = false;
        DB::listen(function ($q) use (&$raced, $job, $theirs) {
            if (! $raced && str_contains($q->sql, 'select "id", "pos_device_id" from "print_jobs"')) {
                $raced = true;
                DB::table('print_jobs')->where('id', $job->id)->update(['pos_device_id' => $theirs->id]);
            }
        });

        $this->assertNull(PrintJobs::claim($mine));
        $this->assertTrue($raced);
        $this->assertSame(PrintJob::PENDING, $job->fresh()->status);
        $this->assertSame($theirs->id, (int) $job->fresh()->pos_device_id);
    }

    /* ============================== المتجر ============================== */

    public function test_a_neighbours_till_is_never_a_target_and_its_cookie_opens_nothing_here(): void
    {
        $mine = $this->till();
        // صندوقُ الجار — مكتوبٌ على فرعٍ من هذا المتجر عمدًا: ليُقاس شرطُ المتجر وحده
        $neighbour = $this->till(null, true, $this->other);
        $task = $this->paidTask();
        $this->paidTask($this->branch, $this->other);
        $theirs = PrintJob::where('business_id', $this->other->id)->sole();

        $this->delete(route('admin.devices.revoke', $mine->id));
        $this->assertNull(PrintJob::where('business_id', $this->shop->id)->sole()->pos_device_id, 'لا يُوجَّه ورقُ متجرٍ إلى صندوق جاره');

        // موظّفُ هذا المتجر يحمل بصمةَ صندوق الجار: لا يرى طابورَه ولا يحجز منه ولا ينزّل
        $this->assertSame(['designated' => false, 'waiting' => 0, 'awaiting' => []], $this->queue($neighbour));
        $this->assertNull($this->claim($neighbour));
        $this->at($neighbour)->get(route('pos.printJobs.pdf', $theirs->id))->assertNotFound();
        $this->at($neighbour)->postJson(route('pos.printJobs.confirm', $theirs->id), ['printed' => true])->assertNotFound();
        $this->assertSame(PrintJob::PENDING, $theirs->fresh()->status);

        // ولوحةُ الجار لا تُعيد ورقةَ هذا المتجر ولا تؤكّدها
        $stranger = User::create(['business_id' => $this->other->id, 'name' => 'الجار', 'email' => 'n@till.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($stranger)->post(route('admin.preparation.invoicePrintConfirm', $task->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.preparation.invoiceRequeue', $task->id))->assertNotFound();
        $this->assertSame(PrintJob::PENDING, PrintJob::where('business_id', $this->shop->id)->sole()->status);
    }

    /* ============================== المفتاح ============================== */

    public function test_turning_the_flag_off_cancels_waiting_papers_stops_every_till_and_keeps_the_money(): void
    {
        $till = $this->till();
        $spare = $this->till();
        DB::table('pos_peripherals')->where('pos_device_id', $spare->id)->update(['auto_print' => true, 'auto_print_website_confirm' => true]);
        $this->paidTask();
        $second = $this->paidTask();
        $printing = $this->claim($till);
        $waiting = PrintJob::where('subject_id', $second->id)->sole();

        $money = fn () => [
            CustomerPayment::count(),
            round((float) CustomerPayment::sum('amount'), 3),
            CustomerInvoice::query()->get()->map(fn ($i) => [$i->status, round((float) $i->total, 3), round($i->outstanding(), 3)])->all(),
            JournalEntry::count(),
        ];
        $before = $money();

        $this->toggle(false);

        $this->assertSame(PrintJob::CANCELLED, $waiting->fresh()->status, 'المنتظِرُ يُلغى');
        $this->assertNotNull($waiting->fresh()->cancelled_at);
        $this->assertSame(PrintJob::CLAIMED, PrintJob::find($printing['id'])->status, 'وما يُطبع الآن لا يُمسّ');
        $this->assertStringContainsString('أُلغيت قبل أن تُطبع — أُغلق التجهيز بعد السداد لهذا النشاط', $this->trail());

        // ولا صندوقَ يستلم — ولو بقيت صفحتُه مفتوحةً تسأل
        $this->assertSame(['designated' => false, 'waiting' => 0, 'awaiting' => []], $this->queue($spare));
        $this->assertNull($this->claim($spare));
        $this->at($till)->get(route('pos.printJobs.pdf', $printing['id']))->assertNotFound();

        // والمحجوزُ قبل الإغلاق يُكتب ما جرى له — ولا يعود إلى الطابور
        $this->ack($till, $printing['id'], ['ok' => false, 'error' => 'DOWNLOAD_FAILED', 'may_have_printed' => false]);
        $this->assertSame(PrintJob::CANCELLED, PrintJob::find($printing['id'])->status);

        // وصفحةُ الصندوق لا تسأل أصلًا — وطباعةُ البيع وطلبِ الموقع كما كانتا
        $this->at($spare)->get(route('admin.devices.index'))->assertInertia(fn ($p) => $p
            ->where('context.peripherals.0.autoPrintCustomerInvoices', false)
            ->where('context.peripherals.0.autoPrint', true)
            ->where('context.peripherals.0.autoPrintWebsite', true));

        $this->assertSame($before, $money(), 'إغلاقُه لا يمسّ دفعًا ولا فاتورةً ولا قيدًا');

        // وفتحُه من جديد لا يُحيي ورقًا أُلغي
        Carbon::setTestNow(now()->addDay());
        $this->toggle(true);
        $this->assertNull($this->claim($spare));
        $this->assertSame([PrintJob::CANCELLED, PrintJob::CANCELLED], PrintJob::orderBy('id')->pluck('status')->all());
        $this->assertSame($before, $money());

        // ويُعيده إنسانٌ إن لزم
        $this->post(route('admin.preparation.invoiceRequeue', $second->id))->assertSessionHas('toast.type', 'success');
        $this->assertSame($waiting->id, $this->claim($till)['id']);
    }
}
