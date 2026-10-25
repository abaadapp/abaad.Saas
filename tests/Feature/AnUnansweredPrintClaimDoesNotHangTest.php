<?php

namespace Tests\Feature;

use App\Models\PrintJob;
use App\Support\PrintJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TillsAndPaidInvoices;
use Tests\TestCase;

/**
 * حجزٌ لم يُردّ عليه لا يبقى «يطبعها الصندوق الآن» أبدًا.
 *
 * كانت المهلةُ تُفحص عند الحجز التالي وحده (`PrintJobs::claim`). وصندوقُ
 * المتصفّح لا يحجز إلّا بضغطة، وزرُّها لا يظهر إلّا وفي الطابور ورقة — فورقةٌ
 * أُغلق لسانُها بعد الحجز كانت تعلق: الصندوقُ لا يعدّها، واللوحةُ ترفض
 * إعادتها وتأكيدَها لأنّها «محجوزة».
 */
class AnUnansweredPrintClaimDoesNotHangTest extends TestCase
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

    public function test_the_tills_poll_fails_a_claim_whose_time_ran_out(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $this->assertNotNull($this->claim($till));

        $this->travel(PrintJobs::CLAIM_SECONDS + 1)->seconds();

        // السؤالُ الدوريّ وحده — لا حجزَ بعده
        $this->assertSame(0, $this->queue($till)['waiting']);
        $this->assertSame(PrintJob::FAILED, PrintJob::sole()->status);

        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceRequeue', $task->id))
            ->assertSessionHas('toast.type', 'success');
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status);
    }

    public function test_the_board_can_requeue_it_without_any_till_asking_first(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $this->assertNotNull($this->claim($till));

        $this->travel(PrintJobs::CLAIM_SECONDS + 1)->seconds();

        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceRequeue', $task->id))
            ->assertSessionHas('toast.type', 'success');
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status);
    }

    public function test_a_claim_still_within_its_time_is_left_alone(): void
    {
        $till = $this->till();
        $task = $this->paidTask();
        $this->assertNotNull($this->claim($till));

        $this->travel(PrintJobs::CLAIM_SECONDS - 10)->seconds();
        $this->queue($till);

        $this->assertSame(PrintJob::CLAIMED, PrintJob::sole()->status);
        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceRequeue', $task->id))
            ->assertSessionHas('toast.type', 'danger');
    }

    /**
     * وورقةٌ لم يستلمها صندوقٌ في يوم — فرعٌ بلا صندوقٍ معيَّن، أو صندوقٌ مطفأ:
     * كانت «تنتظر» أبدًا ثمّ تخرج وحدَها بعد عطلة. الآن «لم تُطبع» بسببها،
     * ويُعيدها إنسانٌ من اللوحة — ولم يُطبع منها شيء.
     */
    public function test_a_paper_no_till_picked_up_in_a_day_fails_with_its_reason(): void
    {
        $this->till(designated: false);
        $task = $this->paidTask();
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status);

        $this->travel(PrintJobs::PENDING_HOURS - 1)->hours();
        $this->actingAs($this->owner)->get(route('admin.preparation.index'))->assertOk();
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status, 'فشلت قبل مهلتها');

        $this->travel(2)->hours();
        $this->actingAs($this->owner)->get(route('admin.preparation.index'))->assertOk();
        $job = PrintJob::sole();
        $this->assertSame(PrintJob::FAILED, $job->status);
        $this->assertStringContainsString('لم يستلمها صندوق', (string) $job->last_error);
        $this->assertNull($job->printed_at);

        $this->actingAs($this->owner)->post(route('admin.preparation.invoiceRequeue', $task->id))
            ->assertSessionHas('toast.type', 'success');
        $this->assertSame(PrintJob::PENDING, PrintJob::sole()->status);
    }
}
