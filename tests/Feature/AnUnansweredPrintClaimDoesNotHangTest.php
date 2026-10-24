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
}
