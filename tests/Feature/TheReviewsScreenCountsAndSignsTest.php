<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\Website\MerchantData;
use App\Support\Website\Preview;
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

    /* ══════════════ ردٌّ لا يقرؤه أحد ══════════════ */

    /**
     * الردُّ على تقييمٍ مرفوضٍ لا يُقال عنه «نُشر».
     *
     * المرفوضُ محجوبٌ عن الموقع وردُّه محجوبٌ معه. وكانت الشاشة تردّ توستًا
     * أخضرَ يقول «نُشر الردّ» — فيطمئنّ صاحبُ المحلّ إلى أنّه أجاب زبونًا
     * غاضبًا، والزبون لم يرَ حرفًا ولن يرى.
     */
    public function test_a_reply_on_a_rejected_review_is_not_called_published(): void
    {
        $review = $this->review('مرفوض');

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'نعتذر'])
            ->assertSessionHasNoErrors();

        $toast = session('toast');

        $this->assertSame('warning', $toast['type'], 'قيل «نُشر» عن ردٍّ محجوب');
        $this->assertStringContainsString('لن يقرأه أحد', (string) $toast['msg']);
    }

    /**
     * والمرفوضُ يبقى مرفوضًا — لا يُنشر من طرفٍ خفيّ.
     *
     * رفضُه قرارٌ اتّخذه صاحبُه بيده، وقلبُه بردٍّ يُخرج إلى واجهة المتجر
     * كلامًا حُجب عمدًا.
     */
    public function test_replying_does_not_quietly_publish_a_rejected_review(): void
    {
        $review = $this->review('مرفوض');

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'نعتذر']);

        $this->assertSame('مرفوض', $review->fresh()->status);
        $this->assertSame('نعتذر', $review->fresh()->reply, 'ضاع الردُّ فيُكتب مرّتين');
    }

    /** والردُّ على معلَّقٍ ينشره ويُقال ذلك */
    public function test_a_reply_on_a_pending_review_publishes_it(): void
    {
        $review = $this->review('معلّق');

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'شكرًا']);

        $this->assertSame('منشور', $review->fresh()->status);
        $this->assertSame('success', session('toast')['type']);
    }

    /* ══════════════ ما يُعرض لا ما يُنشر ══════════════ */

    /**
     * نجومٌ بلا كلامٍ ليست شهادةً تُعرض — والمواضعُ الثلاثة تقول ذلك معًا.
     *
     * ═══ وما كان يقع ═══
     *
     * بانِي المواقع يعدّ كلَّ منشور، وشرطُ عرضِ القسم يقرأ كلَّ منشور،
     * والرسمُ يعرض المنشورَ **الذي له تعليق**. فمتجرٌ نشر خمسةَ تقييماتٍ
     * بنجومٍ بلا كلام يرى «٥ تقييمات» ويُعرض عليه قسمُ الآراء، ثمّ تخرج
     * صفحتُه وفيه لا شيء — ولا يعرف لماذا: العدّادُ يقول خمسة.
     */
    public function test_a_starred_review_without_words_is_not_offered_as_a_testimonial(): void
    {
        Review::create([
            'business_id' => $this->business->id,
            'author_name' => 'زائر', 'rating' => 5, 'comment' => null, 'status' => 'منشور',
        ]);

        $this->assertFalse(
            MerchantData::available($this->business->id)['reviews'],
            'عُرض قسمُ الآراء على متجرٍ لا شهادةَ فيه',
        );
        $this->assertSame(0, $this->shown(), 'عُدَّ ما لا يُعرض');
    }

    /** وتقييمٌ منشورٌ له كلامٌ تقوله الثلاثةُ معًا */
    public function test_a_published_review_with_words_agrees_everywhere(): void
    {
        $this->review('منشور');

        $this->assertTrue(MerchantData::available($this->business->id)['reviews']);
        $this->assertSame(1, $this->shown());
    }

    /**
     * ولا موضعَ ثالثٌ يعيد كتابة الشرط بيده.
     *
     * «فحصان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدهما» — وقد افترقا فعلًا.
     * فالشرطُ في `Review::scopeShowable` وحدَه، ومن نسخه هنا يسقط.
     */
    public function test_no_reader_rewrites_the_showable_rule(): void
    {
        $files = [
            base_path('app/Support/Website/MerchantData.php'),
            base_path('app/Support/Website/Preview.php'),
            base_path('app/Http/Controllers/Admin/Website/BuilderController.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                "Review::where('business_id', \$businessId)->where('status'",
                $source,
                basename($file).' يعيد كتابة شرط العرض بيده',
            );
            $this->assertStringContainsString('showable()', $source, basename($file).' لا يقرأ من المصدر الواحد');
        }
    }

    /** كم شهادةً تُعرض فعلًا في الصفحة */
    private function shown(): int
    {
        return count((new \ReflectionMethod(Preview::class, 'reviews'))
            ->invoke(null, $this->business->id, 6));
    }

    /* ══════════════ الترتيب يطابق ما يُقرأ ══════════════ */

    /**
     * ترتيبُ «المُقيِّم» يقرأ الاسمَ المعروض لا العمودَ الخام.
     *
     * الشاشةُ تعرض اسمَ العميل المسجَّل إن كان، و`author_name` **فارغٌ**
     * حينها. فكان الترتيبُ يصفّ أسماءً ظاهرةً بفراغاتٍ خلفها.
     */
    public function test_sorting_by_reviewer_follows_the_shown_name(): void
    {
        /*
         * والاسمان مقلوبان قصدًا: المسجَّلُ يأتي آخرًا بحروفه، والزائرُ أوّلًا.
         *
         * فلو رُتّب على `author_name` الخام لَتقدّم المسجَّلُ — عمودُه فارغٌ
         * والفراغُ يتقدّم. ولولا القلبُ لَمرّ الحارسُ على الحالين معًا.
         */
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'ياسر', 'phone' => '91110000',
        ]);

        Review::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);
        Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 4, 'comment' => 'جيّد', 'status' => 'منشور',
        ]);

        $names = collect($this->rows(['sort' => 'author', 'dir' => 'asc']))->pluck('author')->all();

        $this->assertSame(['أحمد', 'ياسر'], $names, 'رُتّبت أسماءٌ ظاهرةٌ بعمودٍ فارغ');
    }

    /** والبحثُ يجد اسمَ العميل المسجَّل — وهو ما يقرؤه الباحث في العمود */
    public function test_search_finds_the_registered_customer_name(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سميرة', 'phone' => '91110000',
        ]);

        Review::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);

        $this->assertCount(1, $this->rows(['q' => 'سميرة']));
    }

    /* ------------------- ردٌّ لا يقرؤه أحد يُقال ما هو ------------------- */

    /**
     * ونجومٌ بلا كلامٍ تحجب الردَّ كما يحجبه الرفض.
     *
     * عولج المرفوضُ وبقي هذا: قسمُ الآراء لا يعرض إلّا ما فيه تعليق، فردٌّ
     * على تقييمٍ بلا تعليقٍ لا يقرؤه أحدٌ أبدًا — والشاشةُ كانت تقول «نُشر
     * الردّ» خضراءَ. فيطمئنّ صاحبُ المحلّ إلى أنّه أجاب زبونًا ولم يُجب.
     */
    public function test_a_reply_on_a_wordless_review_is_not_called_published(): void
    {
        $review = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 5, 'comment' => null, 'status' => 'معلّق',
        ]);

        $toast = $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'شكرًا لك'])
            ->getSession()->get('toast');

        $this->assertSame('warning', $toast['type'], 'صُبغ أخضرَ ردٌّ لا يقرؤه أحد');
        $this->assertStringContainsString('نجومٌ بلا كلام', $toast['msg'], 'لم يُقل السببُ بحرفه');
        $this->assertSame(0, $this->shown(), 'خرجت شهادةٌ بلا نصّ');
    }

    /** وردٌّ على تقييمٍ منشورٍ فيه كلام: أخضرُ — وهو الحقّ */
    public function test_a_reply_that_will_be_read_is_called_published(): void
    {
        $review = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 5, 'comment' => 'خدمة ممتازة', 'status' => 'منشور',
        ]);

        $toast = $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'شكرًا لك'])
            ->getSession()->get('toast');

        $this->assertSame('success', $toast['type']);
        $this->assertSame('نُشر الردّ', $toast['msg']);
    }

    /**
     * و«أيقرأ أحدٌ هذا الردّ؟» يُسأل من الشرط الذي يرسم به الموقعُ صفحتَه.
     *
     * كان مكتوبًا بيدٍ: `$review->status === 'مرفوض'`. فلمّا صار للحجب سببٌ
     * ثانٍ — نجومٌ بلا كلام — عرفه الموقعُ ولم يعرفه هذا. وفحصان لسؤالٍ
     * واحد يفترقان يوم يُبدَّل أحدُهما.
     */
    public function test_the_reply_toast_reads_the_showable_rule(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/Admin/Marketing/ReviewController.php')
        );

        $this->assertStringContainsString('showable()', $source, 'لا يقرأ من المصدر الواحد');
        $this->assertStringNotContainsString(
            "\$hidden = \$review->status === 'مرفوض'",
            $source,
            'أُعيدت كتابةُ شرط العرض بيده',
        );
    }

    /* ---------------------------- محوُ الردّ ---------------------------- */

    /**
     * ونصٌّ موقَّعٌ باسم المحلّ على واجهته يجب أن يُمحى.
     *
     * كان `reply` مطلوبًا، فلا سبيل إلى إزالته إلّا بكتابة نصٍّ آخر مكانه.
     * وهو كلامٌ يقرؤه كلّ زائر، يُكتب في لحظة غضبٍ أو قبل تمامه أو بخطأ في
     * اسم — وصاحبُه لا يملك سحبَه.
     */
    public function test_a_reply_can_be_taken_back(): void
    {
        $review = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 2, 'comment' => 'تأخّر الطلب', 'status' => 'منشور',
            'reply' => 'ردٌّ كُتب بالغلط', 'replied_at' => now(),
        ]);

        $toast = $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => ''])
            ->getSession()->get('toast');

        $review->refresh();

        $this->assertNull($review->reply, 'بقي الردُّ بعد محوه');
        $this->assertNull($review->replied_at, 'بقي ختمُ الردّ بلا ردّ');
        $this->assertSame('warning', $toast['type']);
        $this->assertSame('حُذف الردّ', $toast['msg']);
    }

    /**
     * والمحوُ لا يسحب التقييمَ من الموقع.
     *
     * الردُّ إذنٌ بالنشر ضمنًا، وسحبُه ليس سحبًا للإذن. وتقييمٌ يختفي لأنّ
     * صاحبَ المحلّ محا تعليقَه عليه يُخفي كلامَ زبونٍ بفعلٍ لم يقصده.
     */
    public function test_taking_back_a_reply_does_not_unpublish_the_review(): void
    {
        $review = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 2, 'comment' => 'تأخّر الطلب', 'status' => 'منشور',
            'reply' => 'نعتذر', 'replied_at' => now(),
        ]);

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => '   ']);

        $this->assertSame('منشور', $review->fresh()->status);
        $this->assertSame(1, $this->shown(), 'سُحب تقييمٌ من الموقع بمحو ردٍّ عليه');
    }

    /** ومحوٌ يُقيَّد: نصٌّ عامٌّ يُرفع عن واجهة المتجر يُعرف رافعُه */
    public function test_taking_back_a_reply_is_logged(): void
    {
        $review = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 3, 'comment' => 'جيد', 'status' => 'منشور', 'reply' => 'شكرًا',
        ]);

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => '']);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => 'review', 'subject_id' => $review->id,
            'description' => 'حذف ردَّه على تقييم',
        ]);
    }

    /* --------------------- ونشرُ نجومٍ بلا كلامٍ يُقال --------------------- */

    public function test_publishing_a_wordless_review_says_where_it_goes(): void
    {
        $review = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 5, 'comment' => '', 'status' => 'معلّق',
        ]);

        $toast = $this->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'منشور'])
            ->getSession()->get('toast');

        $this->assertStringContainsString('لا تعليق فيه', $toast['msg'], 'نُشرت نجومٌ بلا خبرٍ عن مصيرها');

        // ونشرُ ما فيه كلامٍ يبقى كما كان
        $spoken = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'سالم',
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'معلّق',
        ]);

        $this->assertSame(
            'صار التقييم منشور',
            $this->post(route('admin.marketing.reviews.status', $spoken->id), ['status' => 'منشور'])
                ->getSession()->get('toast')['msg'],
        );
    }

    /* ------------------ ومن كتب التقييم يُقرأ في الشاشة ------------------ */

    /**
     * شهادةُ زبونٍ كتبها بيده ليست كشهادةٍ كتبها صاحبُ المحلّ عن نفسه.
     *
     * وصارتا تقعان معًا منذ فُتح بابُ الرأي (`ReviewInvite`)، والشاشةُ كانت
     * تعرضهما سواءً — فيضيع الفرقُ الذي هو كلُّ قيمة الباب.
     */
    public function test_the_screen_tells_who_wrote_the_review(): void
    {
        $order = Order::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'INV-000900', 'status' => OrderStatus::DELIVERED,
            'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        Review::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'author_name' => 'زبون', 'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);
        Review::create([
            'business_id' => $this->business->id,
            'author_name' => 'سجّله التاجر', 'rating' => 5, 'comment' => 'جيد', 'status' => 'منشور',
        ]);

        $rows = collect($this->rows([]))->keyBy('author');

        $this->assertTrue($rows['زبون']['byCustomer'], 'لم يُوسَم ما كتبه الزبون بيده');
        $this->assertFalse($rows['سجّله التاجر']['byCustomer'], 'وُسم ما سجّله التاجر كأنّه من زبون');
    }

    /* ------------------- وقائمةُ العملاء لا تُبتر صامتة ------------------- */

    /**
     * `limit(500)` كانت تُسقط ما بعدها بلا كلمة.
     *
     * فيبحث التاجر عن عميلٍ يعرف أنّه مسجَّلٌ عنده فلا يجده في القائمة،
     * ويظنّها كلَّ ما لديه. وهو العطبُ نفسُه الذي دُفع ثمنُه في شاشة فواتير
     * العملاء مرّة.
     */
    public function test_a_truncated_customer_list_says_so(): void
    {
        $props = $this->get(route('admin.marketing.reviews'))->viewData('page')['props'];

        $this->assertFalse($props['customersCapped'], 'قيل إنّ القائمة بُترت ولم تُبتر');

        for ($i = 1; $i <= 501; $i++) {
            Customer::create([
                'business_id' => $this->business->id,
                'name' => 'عميل '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'phone' => '9'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            ]);
        }

        $props = $this->get(route('admin.marketing.reviews'))->viewData('page')['props'];

        $this->assertTrue($props['customersCapped'], 'بُترت القائمةُ صامتة');
        $this->assertCount(500, $props['customers'], 'خرج السقفُ عن حدّه');
    }

    /** والشاشةُ تقول السببين قبل الكتابة — لا يُقرآن بعد الحفظ وحده */
    public function test_the_screen_warns_before_the_reply_is_written(): void
    {
        /*
         * حارسٌ يقرأ مصدرًا — ضعيفٌ، وهو ما يمكن هنا: الملاحظةُ تُرسم داخل
         * نافذةٍ لا تُركَّب في jsdom إلّا بـ`AdminLayout` كلِّه. وما تحته —
         * أنّ الخادم يردّ السببين مكتوبَين — محروسٌ بما فوقه.
         */
        $screen = (string) file_get_contents(resource_path('js/Pages/Admin/Marketing/Reviews.tsx'));

        $this->assertStringContainsString('التقييم مرفوضٌ ومحجوب', $screen);
        $this->assertStringContainsString('نجومٌ بلا كلام', $screen);
        $this->assertStringContainsString("t('حذف الردّ')", $screen, 'لا مقبضَ يمحو ردًّا');
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $params): array
    {
        return $this->get(route('admin.marketing.reviews', $params))
            ->assertOk()->viewData('page')['props']['reviews'];
    }
}
