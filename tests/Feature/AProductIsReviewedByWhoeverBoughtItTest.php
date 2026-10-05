<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\ReviewInvite;
use App\Support\Store\ProductReviews;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * رأيٌ في صنفٍ بعينه — ممّن اشتراه، عن البند الذي اشتراه.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) الرأيُ يُكتب عن **بندٍ من طلب الرمز** وحده، وصنفُه من البند لا من
 *    المتصفّح. وبندٌ واحدٌ له رأيٌ واحد (بالقيد)، وبندٌ آخر يبقى مفتوحًا،
 *    والصنفُ في طلبٍ آخر يُكتب فيه رأيٌ آخر.
 * ٢) صفحةُ الصنف تقرأ المنشورَ من آراء **هذا الصنف** وحده: المعلّقُ والمرفوضُ
 *    لا يمسّان معدّلًا ولا عددًا ولا توزيعًا، ورأيُ الطلب لا يُنسب لصنف.
 *    و«شراء موثّق» و«ردّ المتجر» يظهران، وحذفُ الردّ يُغيّبه.
 * ٣) آراءُ الطلب القديمة باقيةٌ كما هي — نوعُها «order» ورأيٌ واحدٌ لكلّ طلب.
 * ٤) لا متجرَ يمسّ آراءَ متجرٍ آخر: لا من اللوحة ولا من رابط الدعوة.
 */
class AProductIsReviewedByWhoeverBoughtItTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    private Business $shop;

    private User $owner;

    private Customer $customer;

    private Product $rose;

    private Product $tulip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'o@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'أحمد المعمري', 'phone' => '95259066']);

        $this->rose = $this->product($this->shop, 'باقة ورد');
        $this->tulip = $this->product($this->shop, 'توليب');
    }

    private function product(Business $shop, string $name): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'price' => 20, 'cost' => 8,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /**
     * @param  list<Product>  $products
     */
    private function order(array $products, string $status = OrderStatus::DELIVERED, ?Business $shop = null): Order
    {
        $shop ??= $this->shop;

        $order = Order::create([
            'business_id' => $shop->id,
            'branch_id' => Branch::where('business_id', $shop->id)->value('id'),
            'customer_id' => $shop->is($this->shop) ? $this->customer->id : null,
            'customer_name' => 'أحمد المعمري',
            'number' => sprintf('INV-%06d', ++self::$sequence),
            'status' => $status, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20 * count($products), 'total' => 20 * count($products), 'ordered_at' => now(),
        ]);

        foreach ($products as $p) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
                'price' => 20, 'cost' => 8, 'quantity' => 1, 'total' => 20,
            ]);
        }

        return $order;
    }

    private function item(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->firstOrFail();
    }

    private function write(Order $order, array $fields): TestResponse
    {
        return $this->post(route('review.submit', ReviewInvite::token($order) ?? 'none'), $fields);
    }

    private function productPage(Product $product): string
    {
        return (string) $this->get('/s/ribbon/p/'.$product->id)->assertOk()->getContent();
    }

    /** رأيُ صنفٍ يُكتب في القاعدة مباشرةً — لاختبار ما يُعرض */
    private function productReview(Product $product, int $rating, string $status = 'منشور', array $extra = []): Review
    {
        $order = $this->order([$product]);

        return Review::create($extra + [
            'business_id' => $this->shop->id, 'type' => Review::TYPE_PRODUCT,
            'customer_id' => $this->customer->id, 'order_id' => $order->id,
            'order_item_id' => $this->item($order, $product)->id, 'product_id' => $product->id,
            'rating' => $rating, 'comment' => 'رأيٌ بـ'.$rating, 'status' => $status,
        ]);
    }

    /* ═══════════ ١) الكتابة ═══════════ */

    public function test_a_customer_reviews_an_item_they_bought_and_the_product_comes_from_the_item(): void
    {
        $order = $this->order([$this->rose, $this->tulip]);
        $item = $this->item($order, $this->tulip);

        // ويُرسل معه صنفًا آخر — لا يُقرأ
        $this->write($order, ['order_item_id' => $item->id, 'product_id' => $this->rose->id, 'rating' => 4, 'comment' => 'توليب جميل'])
            ->assertRedirect(route('review.write', ReviewInvite::token($order)));

        $review = Review::sole();
        $this->assertSame(Review::TYPE_PRODUCT, $review->type);
        $this->assertSame((int) $item->id, (int) $review->order_item_id);
        $this->assertSame((int) $this->tulip->id, (int) $review->product_id);
        $this->assertSame((int) $order->id, (int) $review->order_id);
        $this->assertSame((int) $this->shop->id, (int) $review->business_id);
        $this->assertSame('معلّق', $review->status);
        $this->assertTrue($review->verifiedPurchase());
    }

    public function test_the_invite_page_lists_each_item_with_its_own_form(): void
    {
        $order = $this->order([$this->rose, $this->tulip]);
        $rose = $this->item($order, $this->rose);
        $tulip = $this->item($order, $this->tulip);

        $page = $this->get(route('review.write', ReviewInvite::token($order)))->assertOk();
        $page->assertSee('data-testid="review-item-'.$rose->id.'"', false);
        $page->assertSee('data-testid="review-item-'.$tulip->id.'"', false);
        $page->assertSee('name="order_item_id" value="'.$rose->id.'"', false);
        $page->assertSee('name="order_item_id" value="'.$tulip->id.'"', false);
    }

    public function test_an_item_outside_the_order_is_refused(): void
    {
        $order = $this->order([$this->rose]);
        $another = $this->order([$this->tulip]);

        $this->write($order, ['order_item_id' => $this->item($another, $this->tulip)->id, 'rating' => 5])->assertNotFound();
        $this->write($order, ['order_item_id' => 999999, 'rating' => 5])->assertNotFound();
        $this->write($order, ['order_item_id' => 'abc', 'rating' => 5])->assertSessionHasErrors('order_item_id');

        $this->assertSame(0, Review::count());
    }

    public function test_one_item_takes_one_review(): void
    {
        $order = $this->order([$this->rose]);
        $item = $this->item($order, $this->rose);

        $this->write($order, ['order_item_id' => $item->id, 'rating' => 5, 'comment' => 'أوّل']);
        $this->write($order, ['order_item_id' => $item->id, 'rating' => 1, 'comment' => 'ثانٍ'])
            ->assertRedirect(route('review.write', ReviewInvite::token($order)));

        $this->assertSame(1, Review::count());
        $this->assertSame(5, (int) Review::sole()->rating);
    }

    public function test_the_database_itself_refuses_a_second_review_of_one_item(): void
    {
        $order = $this->order([$this->rose]);
        $item = $this->item($order, $this->rose);
        $row = [
            'business_id' => $this->shop->id, 'type' => Review::TYPE_PRODUCT, 'order_id' => $order->id,
            'order_item_id' => $item->id, 'product_id' => $this->rose->id, 'rating' => 5, 'status' => 'معلّق',
        ];
        Review::create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        Review::create($row);
    }

    public function test_two_items_of_one_order_are_reviewed_each_on_its_own(): void
    {
        $order = $this->order([$this->rose, $this->tulip]);
        $rose = $this->item($order, $this->rose);
        $tulip = $this->item($order, $this->tulip);

        $this->write($order, ['order_item_id' => $rose->id, 'rating' => 5]);

        // ورأيٌ واحدٌ لا يُغلق الباقي
        $this->assertFalse(ReviewInvite::written($order));
        $page = $this->get(route('review.write', ReviewInvite::token($order)))->assertOk();
        $page->assertSee('name="order_item_id" value="'.$tulip->id.'"', false);
        $page->assertDontSee('name="order_item_id" value="'.$rose->id.'"', false);
        $page->assertSee('وصلنا رأيك في هذا الصنف');

        $this->write($order, ['order_item_id' => $tulip->id, 'rating' => 4]);

        $this->assertSame(2, Review::where('order_id', $order->id)->count());
        $this->assertTrue(ReviewInvite::written($order));
        $this->get(route('review.write', ReviewInvite::token($order)))->assertOk()
            ->assertSee('وصلنا رأيك — شكرًا لك')->assertDontSee('name="rating"', false);
    }

    public function test_the_same_product_bought_again_is_reviewed_again(): void
    {
        $first = $this->order([$this->rose]);
        $again = $this->order([$this->rose]);

        $this->write($first, ['order_item_id' => $this->item($first, $this->rose)->id, 'rating' => 5]);
        $this->write($again, ['order_item_id' => $this->item($again, $this->rose)->id, 'rating' => 3]);

        $this->assertSame(2, Review::where('product_id', $this->rose->id)->where('type', Review::TYPE_PRODUCT)->count());
    }

    /**
     * ورأيُ الطلب الذي كُتب قبل آراء الأصناف لا يُغلق بنودَه — ولا يُنسب لأحدها.
     */
    public function test_an_old_order_review_does_not_close_the_items(): void
    {
        $order = $this->order([$this->rose]);
        $old = Review::create([
            'business_id' => $this->shop->id, 'order_id' => $order->id,
            'customer_id' => $this->customer->id, 'rating' => 4, 'comment' => 'خدمة ممتازة', 'status' => 'منشور',
        ]);

        $this->assertSame(Review::TYPE_ORDER, $old->fresh()->type);
        $this->assertNull($old->fresh()->product_id);
        $this->assertFalse(ReviewInvite::written($order));

        $this->write($order, ['order_item_id' => $this->item($order, $this->rose)->id, 'rating' => 5])->assertRedirect();
        $this->assertSame(2, Review::where('order_id', $order->id)->count());
    }

    /** ودعوةٌ لطلبٍ لم يُسلَّم أو أُعيد قبل التسليم لا تفتح بندًا — شروطُها كما كانت */
    public function test_the_invite_rules_still_hold_for_items(): void
    {
        $order = $this->order([$this->rose]);
        $token = ReviewInvite::token($order);
        $item = $this->item($order, $this->rose);

        $order->forceFill(['status' => OrderStatus::PREPARING])->save();
        $this->get(route('review.write', $token))->assertNotFound();
        $this->post(route('review.submit', $token), ['order_item_id' => $item->id, 'rating' => 5])->assertNotFound();

        $order->forceFill(['status' => OrderStatus::DELIVERED, 'is_held' => true])->save();
        $this->post(route('review.submit', $token), ['order_item_id' => $item->id, 'rating' => 5])->assertNotFound();

        $this->assertNull(ReviewInvite::token($this->order([$this->rose], OrderStatus::PENDING)));
        $this->post(route('review.submit', 'not-a-token'), ['order_item_id' => $item->id, 'rating' => 5])->assertNotFound();
        $this->assertSame(0, Review::count());
    }

    /* ═══════════ ٢) صفحةُ الصنف ═══════════ */

    public function test_a_product_without_published_reviews_shows_no_reviews_section(): void
    {
        $this->productReview($this->rose, 5, 'معلّق');

        $html = $this->productPage($this->rose);
        $this->assertStringNotContainsString('rb-product-reviews', $html);
        $this->assertStringNotContainsString('rb-rating-summary', $html);
    }

    public function test_one_published_review_shows_with_its_verified_mark(): void
    {
        $this->productReview($this->rose, 4);

        $html = $this->productPage($this->rose);
        $this->assertStringContainsString('data-testid="rb-product-reviews"', $html);
        $this->assertStringContainsString('data-testid="rb-rating-summary"', $html);
        $this->assertStringContainsString('رأيٌ بـ4', $html);
        $this->assertStringContainsString('أحمد المعمري', $html);
        $this->assertStringContainsString('data-testid="rb-review-verified"', $html);
        $this->assertStringContainsString('شراء موثّق', $html);
    }

    /** المعلّقُ والمرفوضُ لا يُعرضان ولا يمسّان المعدّلَ ولا العددَ ولا التوزيع */
    public function test_hidden_reviews_touch_no_number_and_are_not_listed(): void
    {
        $this->productReview($this->rose, 5);
        $this->productReview($this->rose, 3);
        $this->productReview($this->rose, 1, 'مرفوض');
        $this->productReview($this->rose, 1, 'معلّق');

        $stats = ProductReviews::for($this->shop->id, $this->rose->id);
        $this->assertSame(4.0, $stats['average']);
        $this->assertSame(2, $stats['count']);
        $this->assertSame([5 => 1, 4 => 0, 3 => 1, 2 => 0, 1 => 0], $stats['distribution']);
        $this->assertCount(2, $stats['list']);

        $html = $this->productPage($this->rose);
        $this->assertStringNotContainsString('رأيٌ بـ1', $html);
        $this->assertSame(2, substr_count($html, 'data-testid="rb-review"'));
        $this->assertStringContainsString('عدد التقييمات: 2', $html);
    }

    /** ونجومٌ بلا كلامٍ تُحتسب في الأرقام ولا تُعرض في القائمة — كقسم الآراء */
    public function test_stars_without_words_count_but_are_not_listed(): void
    {
        $this->productReview($this->rose, 5);
        $this->productReview($this->rose, 3, 'منشور', ['comment' => null]);

        $stats = ProductReviews::for($this->shop->id, $this->rose->id);
        $this->assertSame(2, $stats['count']);
        $this->assertSame(4.0, $stats['average']);
        $this->assertCount(1, $stats['list']);
    }

    public function test_a_products_page_never_carries_another_products_reviews(): void
    {
        $this->productReview($this->rose, 5);
        $this->productReview($this->tulip, 2);

        $this->assertSame(1, ProductReviews::for($this->shop->id, $this->rose->id)['count']);
        $html = $this->productPage($this->rose);
        $this->assertStringContainsString('رأيٌ بـ5', $html);
        $this->assertStringNotContainsString('رأيٌ بـ2', $html);
    }

    /** ورأيُ الطلب — ولو سُجّل باليد بصنفٍ — لا يدخل صفحةَ الصنف */
    public function test_an_order_review_never_lands_on_a_product_page(): void
    {
        $order = $this->order([$this->rose]);
        Review::create([
            'business_id' => $this->shop->id, 'order_id' => $order->id, 'product_id' => $this->rose->id,
            'rating' => 1, 'comment' => 'رأيُ طلب', 'status' => 'منشور',
        ]);

        $this->assertSame(0, ProductReviews::for($this->shop->id, $this->rose->id)['count']);
        $this->assertStringNotContainsString('رأيُ طلب', $this->productPage($this->rose));
    }

    public function test_the_store_reply_shows_and_leaves_when_deleted(): void
    {
        $review = $this->productReview($this->rose, 5);

        $this->actingAs($this->owner)->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'شكرًا لك يا أحمد'])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $html = $this->productPage($this->rose);
        $this->assertStringContainsString('data-testid="rb-review-reply"', $html);
        $this->assertStringContainsString('شكرًا لك يا أحمد', $html);
        $this->assertStringContainsString('ردّ المتجر', $html);
        // ولا اسمَ موظّفٍ كتبه
        $this->assertStringNotContainsString('سعود', strip_tags(substr($html, (int) strpos($html, 'rb-review-reply'), 600)));

        $this->actingAs($this->owner)->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => '']);
        auth()->logout();

        $html = $this->productPage($this->rose);
        $this->assertStringNotContainsString('rb-review-reply', $html);
        $this->assertStringNotContainsString('شكرًا لك يا أحمد', $html);
    }

    /** وحجبُه من اللوحة يُخرجه من الصفحة ومن أرقامها */
    public function test_rejecting_from_the_screen_takes_it_off_the_page(): void
    {
        $review = $this->productReview($this->rose, 5);
        $this->assertSame(1, ProductReviews::for($this->shop->id, $this->rose->id)['count']);

        $this->actingAs($this->owner)->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'مرفوض']);

        $this->assertSame(0, ProductReviews::for($this->shop->id, $this->rose->id)['count']);
    }

    /** والأرقامُ باستعلامين لا يكبران بعدد الآراء */
    public function test_the_page_asks_twice_however_many_reviews(): void
    {
        foreach (range(1, 6) as $i) {
            $this->productReview($this->rose, 1 + $i % 5);
        }

        DB::enableQueryLog();
        ProductReviews::for($this->shop->id, $this->rose->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // التجميعُ، والقائمةُ، وأصحابُها
        $this->assertLessThanOrEqual(3, $queries);
    }

    /* ═══════════ ٣) القديمُ باقٍ ═══════════ */

    /**
     * صفٌّ كُتب بلا نوع — كما كُتب كلُّ ما قبل الترحيل — يُقرأ رأيَ طلب، ولا
     * صنفَ له، ويبقى رأيًا واحدًا لكلّ طلب.
     */
    public function test_an_order_review_stays_an_order_review_one_per_order(): void
    {
        $order = $this->order([$this->rose]);
        $id = DB::table('reviews')->insertGetId([
            'business_id' => $this->shop->id, 'order_id' => $order->id, 'rating' => 5,
            'comment' => 'قديم', 'status' => 'منشور', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('reviews')->where('id', $id)->first();
        $this->assertSame(Review::TYPE_ORDER, $row->type);
        $this->assertNull($row->order_item_id);
        $this->assertNull($row->product_id);
        $this->assertFalse(Review::find($id)->verifiedPurchase());

        // وما زال في قسم آراء المتجر حيث كان
        $this->assertStringContainsString('قديم', (string) $this->get('/s/ribbon')->assertOk()->getContent());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('reviews')->insert([
            'business_id' => $this->shop->id, 'order_id' => $order->id, 'rating' => 1,
            'status' => 'معلّق', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * ═══ وقسمُ «آراء العملاء» في الرئيسية لآراء الطلب وحدها ═══
     *
     * رأيُ الصنف المنشور يظهر على صفحة صنفه ولا يظهر في الرئيسية. وقواعدُ
     * العرض كما هي: المعلّقُ لا يظهر، والنجومُ بلا كلامٍ لا تُعرض شهادة.
     * وقرّاءُ القسم الآخرون (المحرّر والبانِي) يقرؤون القاعدةَ نفسَها.
     */
    public function test_the_store_testimonials_carry_order_reviews_only(): void
    {
        $order = $this->order([]);
        Review::create([
            'business_id' => $this->shop->id, 'order_id' => $order->id, 'customer_id' => $this->customer->id,
            'rating' => 5, 'comment' => 'خدمةُ المحلّ رائعة', 'status' => 'منشور',
        ]);
        Review::create([
            'business_id' => $this->shop->id, 'order_id' => $this->order([])->id,
            'rating' => 4, 'comment' => 'رأيُ طلبٍ معلّق', 'status' => 'معلّق',
        ]);
        $this->productReview($this->rose, 4, 'منشور', ['comment' => 'الباقةُ نفسُها جميلة']);

        $home = (string) $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-sec-reviews"', $home);
        $this->assertStringContainsString('خدمةُ المحلّ رائعة', $home);
        $this->assertStringNotContainsString('الباقةُ نفسُها جميلة', $home, 'رأيُ صنفٍ ظهر في آراء المتجر');
        $this->assertStringNotContainsString('رأيُ طلبٍ معلّق', $home);

        $this->assertStringContainsString('الباقةُ نفسُها جميلة', $this->productPage($this->rose));

        // ومتجرٌ ليس له إلّا آراءُ أصنافٍ لا شهادةَ له في القسم — ولا عدّادٌ يقول غيرَ ذلك
        Review::where('type', Review::TYPE_ORDER)->delete();
        $this->assertFalse(Review::where('business_id', $this->shop->id)->testimonial()->exists());
        $this->assertStringNotContainsString('rb-sec-reviews', (string) $this->get('/s/ribbon')->getContent());
        $this->assertSame(1, ProductReviews::for($this->shop->id, $this->rose->id)['count']);
    }

    /** وطلبٌ بلا صنفٍ يُقيَّم يُكتب فيه رأيُه الواحد كما كان */
    public function test_an_order_without_products_keeps_its_single_form(): void
    {
        $order = $this->order([]);

        $page = $this->get(route('review.write', ReviewInvite::token($order)))->assertOk();
        $page->assertSee('name="rating"', false);
        $page->assertDontSee('name="order_item_id"', false);

        $this->write($order, ['rating' => 5, 'comment' => 'تجربة جميلة']);
        $this->assertSame(Review::TYPE_ORDER, Review::sole()->type);
        $this->assertTrue(ReviewInvite::written($order));
    }

    /* ═══════════ ٤) كلُّ متجرٍ في كتابه ═══════════ */

    public function test_another_shop_cannot_touch_these_reviews_from_its_screen(): void
    {
        $review = $this->productReview($this->rose, 5, 'معلّق');

        $neighbour = Business::create(['name' => 'الجار', 'type' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعه']);
        $them = User::create([
            'business_id' => $neighbour->id, 'name' => 'الجار', 'email' => 'n@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($them)->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'منشور'])->assertNotFound();
        $this->actingAs($them)->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'دخيل'])->assertNotFound();
        $this->actingAs($them)->delete(route('admin.marketing.reviews.destroy', $review->id))->assertNotFound();

        $fresh = $review->fresh();
        $this->assertSame('معلّق', $fresh->status);
        $this->assertNull($fresh->reply);

        // ولا يراه في قائمته
        $listed = $this->actingAs($them)->get(route('admin.marketing.reviews'))->assertOk()->viewData('page')['props']['reviews'];
        $this->assertSame([], $listed);
    }

    public function test_an_invite_cannot_review_another_shops_item(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعه']);
        $theirs = $this->order([$this->product($neighbour, 'صنفُ الجار')], shop: $neighbour);

        $mine = $this->order([$this->rose]);

        $this->write($mine, ['order_item_id' => OrderItem::where('order_id', $theirs->id)->value('id'), 'rating' => 1])->assertNotFound();
        $this->assertSame(0, Review::count());
    }

    /* ═══════════ اللوحة ═══════════ */

    public function test_the_screen_names_the_product_and_the_order_and_filters_by_type(): void
    {
        $product = $this->productReview($this->rose, 5, 'معلّق');
        $order = $this->order([]);
        Review::create(['business_id' => $this->shop->id, 'order_id' => $order->id, 'rating' => 4, 'status' => 'معلّق']);

        $rows = collect($this->actingAs($this->owner)->get(route('admin.marketing.reviews'))->assertOk()
            ->viewData('page')['props']['reviews'])->keyBy('id');

        $row = $rows[$product->id];
        $this->assertSame('product', $row['kind']);
        $this->assertSame('باقة ورد', $row['product']);
        $this->assertSame(Order::find($product->order_id)->number, $row['order']);
        $this->assertTrue($row['verified']);

        $only = $this->actingAs($this->owner)->get(route('admin.marketing.reviews', ['type' => 'product']))
            ->viewData('page')['props']['reviews'];
        $this->assertSame([$product->id], array_column($only, 'id'));

        $orders = $this->actingAs($this->owner)->get(route('admin.marketing.reviews', ['type' => 'order']))
            ->viewData('page')['props']['reviews'];
        $this->assertNotContains($product->id, array_column($orders, 'id'));
    }
}
