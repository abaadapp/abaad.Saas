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
use App\Support\ReviewInvite;
use App\Support\Website\Preview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الزبونُ يكتب رأيَه بيده — وما كان له بابٌ قبل هذا.
 *
 * ═══ العطبُ الذي وُضع له هذا الملفّ ═══
 *
 * في النظام شاشةُ «تقييمات العملاء» كاملةٌ: تُنشَر وتُرفَض ويُردّ عليها،
 * وتخرج على موقع التاجر. وطريقُ دخولها **واحد**: أن يكتبها التاجرُ عن
 * زبائنه من زرّ «تسجيل تقييم». فمتجرٌ ينتظر آراءَ زبائنه ينتظر ما لا يصل —
 * لا لأنّهم صامتون، بل لأنّ لا أحدَ يستطيع الكتابة.
 *
 * وهذا الملفّ يحرس البابَ الجديد من أربعِ جهات: أن يُفتح لمن اشترى وحده،
 * وأن يُكتب فيه رأيٌ واحدٌ لكلّ طلب، وأن يصل معلَّقًا لا منشورًا، وأن يكون
 * الاسمُ الذي يخرج على الموقع هو الاسمَ الذي أذِن به صاحبُ المحلّ.
 */
class TheCustomerWritesHisOwnReviewTest extends TestCase
{
    use RefreshDatabase;

    /** عدّادُ أرقام الطلبات — يتسلسل ولا يتصادم */
    private static int $sequence = 0;

    private Business $business;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'أحمد المعمري', 'phone' => '99112233',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function order(string $status = OrderStatus::DELIVERED, array $extra = []): Order
    {
        return Order::create($extra + [
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            /*
             * والاسمُ مكتوبٌ في الطلب **أيضًا**.
             *
             * وبدونه كانت الحالةُ تمرّ على طفرةٍ تنسخ الاسمَ إلى التقييم:
             * `customer_name` فارغٌ فيردّ الناسخُ فارغًا كالأصل، فتُختبر
             * قاعدةٌ بحالةٍ لا تفرّق بين تطبيقها وخرقها.
             */
            'customer_name' => 'أحمد المعمري',
            /*
             * ورقمٌ يتسلسل لا يُقرَع — الطلباتُ تتفرّد بأرقامها في متجرها.
             *
             * كان `'INV-000'.rand(100, 999)`: تسعُمئة قيمةٍ يُسحب منها مرارًا
             * في الملفّ الواحد، فيقع التصادمُ ويسقط الاختبارُ بانتهاك التفرّد
             * — لا لعطبٍ في المنتج بل لقرعة. سقطت بها CI على بصمةٍ لا تمسّها.
             *
             * وسقوطٌ عشوائيّ أسوأ من سقوطٍ ثابت: يُقرأ عطبًا في تغييرٍ بريء،
             * أو — وهو أسوأ — يُعتاد فيُتجاهَل يومَ يصدق.
             */
            'number' => sprintf('INV-%06d', ++self::$sequence),
            'status' => $status,
            'is_held' => false,
            'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25,
            'ordered_at' => now(),
        ]);
    }

    /* ---------------------------- البابُ يُفتح ---------------------------- */

    public function test_the_customer_of_a_delivered_order_can_open_the_form(): void
    {
        $order = $this->order();
        $token = ReviewInvite::token($order);

        $this->assertNotNull($token, 'لا رمزَ لطلبٍ سُلّم — فلا بابَ يُرسَل');

        $page = $this->get(route('review.write', $token));

        $page->assertOk();
        $page->assertSee('متجر الورد');
        $page->assertSee($order->number);
        // ونموذجٌ يُكتب فيه، لا صفحةُ شكرٍ قبل أن يُكتب شيء
        $page->assertSee('name="rating"', false);
    }

    public function test_a_written_review_lands_pending_and_belongs_to_the_order(): void
    {
        $order = $this->order();
        $token = ReviewInvite::token($order);

        $this->post(route('review.submit', $token), [
            'rating' => 5, 'comment' => 'الورد وصل طازجًا',
        ])->assertRedirect(route('review.write', $token));

        $review = Review::firstOrFail();

        $this->assertSame((int) $this->business->id, (int) $review->business_id);
        $this->assertSame((int) $order->id, (int) $review->order_id);
        $this->assertSame((int) $this->customer->id, (int) $review->customer_id);
        $this->assertSame(5, (int) $review->rating);
        $this->assertSame('الورد وصل طازجًا', $review->comment);
        // معلَّقًا — لا يظهر على الموقع حتى يقرأه صاحبُ المحلّ ويأذن
        $this->assertSame('معلّق', $review->status);
        // والاسمُ لا يُنسخ: يُقرأ من صفّ العميل، فيتبعه إن صُحّح
        $this->assertNull($review->author_name);
    }

    /**
     * ورأيٌ واحدٌ لكلّ طلب.
     *
     * زبونٌ يضغط «إرسال» ضغطتين على شبكةٍ بطيئة لم يُخطئ — ولا يُحسب رأيُه
     * مرّتين في المعدّل.
     */
    public function test_a_second_submission_writes_nothing_and_does_not_shout(): void
    {
        $order = $this->order();
        $token = ReviewInvite::token($order);

        $this->post(route('review.submit', $token), ['rating' => 5, 'comment' => 'ممتاز']);
        $this->post(route('review.submit', $token), ['rating' => 1, 'comment' => 'سيّئ'])
            ->assertRedirect(route('review.write', $token));

        $this->assertSame(1, Review::count(), 'كُتب رأيان عن طلبٍ واحد');
        $this->assertSame(5, (int) Review::firstOrFail()->rating);
    }

    /**
     * وبعد الكتابة تُعرض الشكرُ لا النموذج — ولو فُتح الرابط بعد شهر.
     *
     * والرأيُ يُكتب هنا في القاعدة رأسًا لا بطلبٍ سابق: `done` تُومَض في
     * الجلسة بعد الإرسال، فطلبٌ يتلوه يقرؤها ويكتم النموذج **وإن لم يكن
     * في القاعدة شيء**. فتُقاس القاعدةُ وحدَها.
     */
    public function test_the_form_is_gone_once_the_review_is_in(): void
    {
        $order = $this->order();
        $token = ReviewInvite::token($order);

        Review::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'customer_id' => $this->customer->id, 'rating' => 4, 'status' => 'معلّق',
        ]);

        $page = $this->get(route('review.write', $token));

        $page->assertOk();
        $page->assertDontSee('name="rating"', false);
        $page->assertSee('وصلنا رأيك — شكرًا لك');
    }

    /**
     * ولا رمزان لطلبٍ واحد حين يُفتح من صندوقين معًا.
     *
     * النموذجُ في الذاكرة يقول «بلا رمز» وقد صار له رمزٌ في القاعدة — وهو
     * حالُ الصندوق الثاني حرفًا بحرف. فالكتابةُ مشروطةٌ بالفراغ، ثمّ يُقرأ
     * ما استقرّ: الرابطان واحد، ولا يبطل رابطٌ وُزّع.
     */
    public function test_a_second_hand_does_not_overwrite_a_token_already_given(): void
    {
        $order = $this->order();
        $given = str_repeat('Z', 22);

        // صفٌّ كُتب رمزُه بعد أن قُرئ النموذج — والنموذجُ في اليد قديم
        Order::whereKey($order->id)->update(['review_token' => $given]);

        $this->assertSame($given, ReviewInvite::token($order), 'أُبطل رابطٌ وُزّع بالفعل');
        $this->assertSame($given, $order->fresh()->review_token);
    }

    /* --------------------------- ولمن لم يشترِ --------------------------- */

    /** لا رمزَ لطلبٍ لم يبلغ صاحبَه — وما لا يُبنى لا يُسرَّب */
    public function test_no_link_is_built_before_the_experience_happened(): void
    {
        foreach ([OrderStatus::PENDING, OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::CANCELLED] as $status) {
            $order = $this->order($status);

            $this->assertNull(ReviewInvite::token($order), "بُني رمزٌ لطلبٍ حالُه «{$status}»");
            $this->assertNull($order->fresh()->review_token);
        }
    }

    /** وثلاثُ حالاتٍ تُفتح: ما بلغ يدَ الزبون */
    public function test_delivered_picked_up_and_completed_all_open(): void
    {
        foreach ([OrderStatus::DELIVERED, OrderStatus::PICKED_UP, OrderStatus::COMPLETED] as $status) {
            $this->assertNotNull(
                ReviewInvite::token($this->order($status)),
                "لم يُبنَ رمزٌ لطلبٍ حالُه «{$status}»",
            );
        }
    }

    /**
     * وطلبٌ رُدَّ إلى التجهيز بعد تسليمه يُغلق بابُه.
     *
     * الرمزُ يبقى في الصفّ — والشرطُ يُقاس عند الفتح لا عند البناء.
     */
    public function test_a_rolled_back_order_closes_its_door(): void
    {
        $order = $this->order();
        $token = ReviewInvite::token($order);

        $order->forceFill(['status' => OrderStatus::PREPARING])->save();

        $this->get(route('review.write', $token))->assertNotFound();
        $this->post(route('review.submit', $token), ['rating' => 5])->assertNotFound();
        $this->assertSame(0, Review::count());
    }

    public function test_a_token_that_means_nothing_is_not_a_door(): void
    {
        $this->get('/r/'.str_repeat('a', 22))->assertNotFound();
        $this->post('/r/'.str_repeat('a', 22), ['rating' => 5])->assertNotFound();
        $this->assertSame(0, Review::count());
    }

    /** والرمزُ لا يُبنى مرّتين: من فتح الطلبَ مرّتين وزّع رابطًا واحدًا */
    public function test_the_token_is_stable(): void
    {
        $order = $this->order();

        $first = ReviewInvite::token($order);
        $second = ReviewInvite::token($order->fresh());

        $this->assertSame($first, $second);
    }

    /* ------------------------------ ما يُكتب ------------------------------ */

    public function test_stars_outside_one_to_five_write_nothing(): void
    {
        $order = $this->order();
        $token = ReviewInvite::token($order);

        foreach ([0, 6, -1] as $bad) {
            $this->post(route('review.submit', $token), ['rating' => $bad])
                ->assertSessionHasErrors('rating');
        }

        $this->post(route('review.submit', $token), ['comment' => 'بلا نجوم'])
            ->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::count());
    }

    /** ونجومٌ بلا كلامٍ تُقبل — وهي رقمٌ في المعدّل لا شهادةٌ تُعرض */
    public function test_stars_without_words_are_accepted(): void
    {
        $order = $this->order();

        $this->post(route('review.submit', ReviewInvite::token($order)), ['rating' => 4]);

        $this->assertSame(4, (int) Review::firstOrFail()->rating);
        $this->assertNull(Review::firstOrFail()->comment);
    }

    /** وطلبٌ بلا عميلٍ مسجَّل: يُنسب الرأيُ إلى الاسم المكتوب في الطلب */
    public function test_a_walk_in_order_signs_with_the_name_on_it(): void
    {
        $order = $this->order(OrderStatus::PICKED_UP, [
            'customer_id' => null, 'customer_name' => 'سالم',
        ]);

        $this->post(route('review.submit', ReviewInvite::token($order)), ['rating' => 5, 'comment' => 'شكرًا']);

        $review = Review::firstOrFail();
        $this->assertSame('سالم', $review->author_name);
        $this->assertNull($review->customer_id);
    }

    /** والأثرُ يُكتب في سجلّ المتجر — وكاتبُه «زائر» لا آخرُ من دخل */
    public function test_the_trail_belongs_to_the_shop_and_names_no_employee(): void
    {
        $order = $this->order();

        $this->post(route('review.submit', ReviewInvite::token($order)), ['rating' => 5, 'comment' => 'ممتاز']);

        $log = ActivityLog::where('subject_type', 'review')->firstOrFail();

        $this->assertSame((int) $this->business->id, (int) $log->business_id);
        $this->assertNull($log->user_id);
        $this->assertStringContainsString($order->number, $log->description);
    }

    /* --------------------------- وما يخرج للعامّة --------------------------- */

    /** ما وصل من الباب لا يظهر على الموقع حتى يأذن صاحبُ المحلّ */
    public function test_nothing_written_here_reaches_the_site_unpublished(): void
    {
        $order = $this->order();

        $this->post(route('review.submit', ReviewInvite::token($order)), ['rating' => 5, 'comment' => 'رائع']);

        $this->assertSame([], $this->shown(), 'خرج رأيٌ لم يأذن به صاحبُ المحلّ');
    }

    /**
     * وحين يأذن: يخرج بالاسم الذي قرأه وأذِن به — لا بـ«عميل».
     *
     * `author_name` فارغٌ لكلّ رأيٍ لعميلٍ مسجَّل، وكان الموقع يقرؤه خامًا
     * ويكتب «عميل» مكانه. فيرى التاجر «أحمد المعمري» في شاشته ويضغط «انشر»،
     * ثمّ يفتح موقعَه فيجد شهادةً موقّعةً بـ«عميل» — وصفحةٌ كلُّ شهاداتها
     * كذلك تُقرأ كما تُقرأ الشهادةُ المخترَعة.
     */
    public function test_the_site_signs_with_the_name_the_owner_approved(): void
    {
        $order = $this->order();
        $this->post(route('review.submit', ReviewInvite::token($order)), ['rating' => 5, 'comment' => 'رائع']);

        $this->actingAs($this->owner)->post(
            route('admin.marketing.reviews.status', Review::firstOrFail()->id),
            ['status' => 'منشور'],
        );

        $shown = $this->shown();

        $this->assertCount(1, $shown);
        $this->assertSame('أحمد المعمري', $shown[0]['author']);
        $this->assertSame(5, $shown[0]['rating']);
    }

    /* ---------------------------- ويدُ التاجر ---------------------------- */

    /** الزرُّ يُجهّز رسالةً فيها الرابط — ولا يقول «أُرسل» */
    public function test_the_button_prepares_a_message_carrying_the_link(): void
    {
        $order = $this->order();

        $toast = $this->actingAs($this->owner)
            ->post(route('admin.orders.reviewInvite', $order->number))
            ->assertRedirect()
            ->getSession()->get('toast');

        // ولا أخضر: لم يخرج حرفٌ إلى أحد — «طمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب»
        $this->assertSame('info', $toast['type']);
        $this->assertStringContainsString('wa.me/96899112233', $toast['link']['url']);
        $this->assertStringContainsString(
            rawurlencode((string) ReviewInvite::url($order->fresh())),
            $toast['link']['url'],
            'جُهِّزت رسالةٌ بلا رابطِ الرأي فيها',
        );
    }

    public function test_the_button_refuses_before_delivery(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $toast = $this->actingAs($this->owner)
            ->post(route('admin.orders.reviewInvite', $order->number))
            ->getSession()->get('toast');

        $this->assertSame('danger', $toast['type']);
        $this->assertNull($order->fresh()->review_token, 'بُني رمزٌ لطلبٍ لم يُسلَّم');
    }

    /** وحين يكون الرأيُ قد وصل: يُقال ذلك ولا تُجهَّز دعوةٌ ثانية */
    public function test_the_screen_says_the_review_arrived(): void
    {
        $order = $this->order();
        $this->post(route('review.submit', ReviewInvite::token($order)), ['rating' => 5]);

        $state = $this->actingAs($this->owner)
            ->get(route('admin.orders.show', $order->number))
            ->viewData('page')['props']['storeReview'];

        $this->assertTrue($state['written']);
        $this->assertFalse($state['show'], 'عُرض زرُّ دعوةٍ لطلبٍ وصل رأيُه');
        $this->assertNotNull($state['reason']);

        $toast = $this->post(route('admin.orders.reviewInvite', $order->number))->getSession()->get('toast');
        $this->assertSame('info', $toast['type']);
        $this->assertArrayNotHasKey('link', $toast, 'جُهِّزت دعوةٌ ثانيةٌ لمن كتب');
    }

    /** وقبل التسليم لا زرَّ ولا سبب: السؤالُ لم يحِن بعد */
    public function test_no_button_is_offered_before_delivery(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $state = $this->actingAs($this->owner)
            ->get(route('admin.orders.show', $order->number))
            ->viewData('page')['props']['storeReview'];

        $this->assertFalse($state['show']);
        $this->assertNull($state['reason']);
    }

    /* ------------------------------ الجارُ ------------------------------ */

    /** ورأيٌ عن طلبِ جارٍ لا يدخل دفترَ متجري */
    public function test_a_neighbours_order_writes_into_its_own_book(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $other->id, 'name' => 'فرعه']);

        $theirs = Order::create([
            'business_id' => $other->id, 'branch_id' => $branch->id,
            'number' => 'INV-000777', 'status' => OrderStatus::DELIVERED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->post(route('review.submit', ReviewInvite::token($theirs)), ['rating' => 1, 'comment' => 'سيّئ']);

        $this->assertSame((int) $other->id, (int) Review::firstOrFail()->business_id);
        $this->assertSame(0, Review::where('business_id', $this->business->id)->count());

        // ولا يُجهّز جارُه دعوةً عن طلبِ غيره
        $this->actingAs($this->owner)
            ->post(route('admin.orders.reviewInvite', $theirs->number))
            ->assertNotFound();
    }

    /**
     * والقيدُ في القاعدة هو ما يمنع الرأيَ الثاني — لا فحصٌ في متحكّم.
     *
     * يُقاس من دون المرور بالمتحكّم أصلًا: نقرتان متزامنتان تمرّان على أيّ
     * فحصٍ يسبق الكتابة فتجيبان «لا» معًا، والذي يردّ الثانية هو الفهرس.
     */
    public function test_the_database_itself_refuses_a_second_review_for_one_order(): void
    {
        $order = $this->order();

        $row = fn (int $rating) => [
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'rating' => $rating, 'comment' => 'كلام', 'status' => 'معلّق',
        ];

        Review::create($row(5));

        $this->expectException(UniqueConstraintViolationException::class);
        Review::create($row(1));
    }

    /** وصفٌّ بلا طلبٍ لا يصطدم بصفٍّ بلا طلب: ما يُسجّله التاجر بيده يبقى كثيرًا */
    public function test_reviews_without_an_order_do_not_collide(): void
    {
        foreach (range(1, 3) as $i) {
            Review::create([
                'business_id' => $this->business->id, 'author_name' => 'زائر '.$i,
                'rating' => 5, 'comment' => 'جميل', 'status' => 'معلّق',
            ]);
        }

        $this->assertSame(3, Review::count());
    }

    /**
     * وقراءةُ الرابط مرّتين لا تكتب في الطلب مرّتين.
     *
     * الرمزُ يُقرأ إن وُجد: بلا ذلك كان كلُّ نداءٍ على `token()` يُصدر
     * `update` على صفّ الطلب ثمّ يعيد قراءته — كتابةٌ على طريق قراءة.
     */
    public function test_reading_an_existing_link_writes_nothing(): void
    {
        $order = $this->order();
        ReviewInvite::token($order);

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $writes++;
            }
        });

        ReviewInvite::token($order->fresh());

        $this->assertSame(0, $writes, 'كُتب في صفّ الطلب لقراءةِ رابطٍ قائم');
    }

    /* ------------------------ فحصٌ واحدٌ لسؤالٍ واحد ------------------------ */

    /**
     * و«بلغ الطلبُ يدَ الزبون؟» يُسأل من موضعٍ واحد.
     *
     * ثلاثةُ مواضعَ تسأله: زرُّ تقييم Google، وحالُه في الشاشة، ودعوةُ الرأي.
     * وكانت القائمةُ مكتوبةً بيدٍ في كلٍّ منها — فحالٌ رابعٌ يُضاف يومًا
     * يُضاف في اثنين ويُنسى الثالث، فيُدعى زبونٌ إلى تقييم طلبٍ لم يصله.
     */
    public function test_no_one_writes_the_fulfilled_list_by_hand(): void
    {
        $files = [
            app_path('Http/Controllers/Admin/OrderDetailController.php'),
            app_path('Http/Controllers/Admin/PageController.php'),
            app_path('Support/ReviewInvite.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            $this->assertStringContainsString(
                'OrderStatus::fulfilled(',
                $source,
                basename($file).': لا يقرأ المصدرَ الواحد',
            );

            $this->assertDoesNotMatchRegularExpression(
                '/OrderStatus::DELIVERED\s*,\s*\n?\s*OrderStatus::PICKED_UP/',
                $source,
                basename($file).': أُعيدت كتابةُ القائمة بيد',
            );
        }
    }

    /**
     * آراءٌ كما تخرج على الموقع.
     *
     * تُقرأ من `Preview::reviews` نفسِها التي يرسمها الموقع — لا من استعلامٍ
     * ثانٍ يشبهها ويفترق عنها عند أوّل إصلاح.
     *
     * @return list<array<string, mixed>>
     */
    private function shown(): array
    {
        $method = new \ReflectionMethod(Preview::class, 'reviews');
        $method->setAccessible(true);

        return $method->invoke(null, (int) $this->business->id, 12);
    }
}
