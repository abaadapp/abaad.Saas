<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ التقييمات: تُعدّ ولا تُحمّل، وما يُنشر للعامّة يُقيَّد.
 *
 * ═══ أربعةُ أرقامٍ بجدولٍ كامل ═══
 *
 * البطاقات الأربع فوق الشاشة كانت تُحسب بـ`Review::…->get()`: كلُّ تقييمٍ في
 * المتجر يُبنى نموذجًا في الذاكرة ليُعدّ. والصفحة تحته مُرقَّمةٌ بعشرين —
 * فالجدول يُمسح كاملًا لأجل أربعة أعداد، ويكبر الثمن مع كلّ تقييمٍ يصل.
 *
 * ═══ ونشرٌ بلا أثر ═══
 *
 * التسجيلُ يُقيَّد والحذفُ يُقيَّد، والنشرُ والرفضُ والردّ لا. وهي أخطر
 * الثلاثة: النشرُ يُخرج كلامَ زبونٍ إلى واجهة المتجر، والردُّ يكتب باسم
 * المحلّ ردًّا يقرؤه كلّ زائر — ولا يقول السجلُّ من فعلها ولا متى.
 */
class TheReviewsScreenCountsAndSignsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function review(string $status = 'معلّق', int $rating = 5): Review
    {
        return Review::create([
            'business_id' => $this->business->id,
            'author_name' => 'زائر', 'rating' => $rating, 'comment' => 'جميل', 'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return $this->get(route('admin.marketing.reviews'))->viewData('page')['props']['summary'];
    }

    /* ------------------------------ الأعداد ------------------------------ */

    public function test_the_four_numbers_are_right(): void
    {
        $this->review('معلّق');
        $this->review('منشور', 4);
        $this->review('منشور', 5);
        $this->review('مرفوض', 1);

        $s = $this->summary();

        $this->assertSame(4, $s['count']);
        $this->assertSame(1, $s['pending']);
        $this->assertSame(2, $s['published']);
        // المعدّل على المنشور وحده: المعلّق لم يُقرأ بعد، والمرفوض لا رأيَ له
        $this->assertSame(4.5, $s['average']);
    }

    public function test_a_shop_with_no_reviews_reads_zero_not_a_crash(): void
    {
        $s = $this->summary();

        $this->assertSame(0, $s['count']);
        $this->assertSame(0.0, $s['average']);
    }

    /** ولا تُحتسب في المعدّل نجومُ ما لم يُنشر */
    public function test_pending_stars_do_not_move_the_average(): void
    {
        $this->review('منشور', 5);
        $this->review('معلّق', 1);

        $this->assertSame(5.0, $this->summary()['average']);
    }

    /** ولا تقرأ الشاشةُ تقييماتِ جارها */
    public function test_a_neighbours_reviews_are_not_counted(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Review::create([
            'business_id' => $other->id, 'author_name' => 'زائر',
            'rating' => 1, 'comment' => 'سيّئ', 'status' => 'منشور',
        ]);
        $this->review('منشور', 5);

        $s = $this->summary();
        $this->assertSame(1, $s['count']);
        $this->assertSame(5.0, $s['average']);
    }

    /**
     * والأعدادُ لا تُبنى نماذجَ في الذاكرة.
     *
     * القياسُ على ما هو العطب: عددُ الصفوف التي تصير نماذجَ في الطلب الواحد.
     * والصفحة مرقَّمةٌ بعشرين، فستّون تقييمًا يجب أن تُبنى منها عشرون —
     * لا ستّون. وعدُّ الاستعلامات لا يكشف هذا: `get()` استعلامٌ واحد يحمل
     * الجدول كلَّه.
     */
    public function test_the_summary_does_not_hydrate_the_whole_table(): void
    {
        foreach (range(1, 60) as $i) {
            $this->review('منشور');
        }

        $hydrated = 0;
        Review::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });

        $this->get(route('admin.marketing.reviews'))->assertSuccessful();

        $this->assertLessThanOrEqual(25, $hydrated,
            "الشاشة بنت {$hydrated} نموذجًا لصفحةٍ تعرض عشرين");
    }

    /* ------------------------------ السجلّ ------------------------------ */

    private function lastLog(): ?ActivityLog
    {
        return ActivityLog::orderByDesc('id')->first();
    }

    public function test_publishing_a_review_is_logged(): void
    {
        $review = $this->review('معلّق');

        $this->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'منشور'])
            ->assertSessionHasNoErrors();

        $log = $this->lastLog();
        $this->assertNotNull($log, 'النشر لم يُقيَّد');
        $this->assertStringContainsString('منشور', $log->description);
    }

    public function test_rejecting_a_review_is_logged(): void
    {
        $review = $this->review('معلّق');

        $this->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'مرفوض']);

        $this->assertStringContainsString('مرفوض', (string) $this->lastLog()?->description);
    }

    public function test_replying_is_logged(): void
    {
        $review = $this->review('معلّق');

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'شكرًا لك'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->lastLog(), 'الردّ باسم المحلّ لم يُقيَّد');
    }

    /** والقيدُ يشير إلى التقييم نفسه لا إلى الهواء */
    public function test_the_log_line_points_at_the_review(): void
    {
        $review = $this->review('معلّق');

        $this->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'منشور']);

        $this->assertSame($review->id, (int) $this->lastLog()?->subject_id);
    }
}
