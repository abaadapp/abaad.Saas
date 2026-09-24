<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مرفقُ كرت الهدية على لوحة التجهيز — بابٌ يفتحه من يكتب الكرت.
 *
 * ═══ العطب ═══
 *
 * البطاقةُ على اللوحة تعرض رابطَ الملفّ الذي رفعه الزبون — صورةً بخطّ يده
 * أو نصًّا مكتوبًا — وهو كلُّ ما يُكتب على الكرت. ورابطُها كان
 * `admin.orders.giftcard`: اسمٌ يُشتقّ منه قسمُ «المبيعات».
 *
 * والقسمُ يُقاس من اسم المسار قبل أن يصل النداءُ إلى المتحكّم
 * (`CheckAbility`). فمن مُنح التجهيزَ وحده — وهو من يقف عند الطاولة — يفتح
 * لوحته ٢٠٠، ويضغط المرفقَ فيُردّ ٤٠٣.
 *
 * وفي `OrderAttachmentController::giftCard` حارسٌ مكتوبٌ يقول صراحةً إنّ
 * «الشاشتين كلتيهما تكفيان»: `allows('orders') || allows('preparation')`.
 * وهو لا يُبلَغ أصلًا — الوسيطُ ردّ قبله. فالنيّةُ مكتوبةٌ والبابُ مغلق.
 *
 * وهي عينُ العلّة التي صُحّحت في سندِ التسليم: نسخةٌ من المسار باسم
 * `preparation.*` فيتبع قسمَ من يجهّز. والورقةُ والملفُّ كلاهما ممّا يراه
 * على بطاقته أصلًا — نصُّ الكرت نفسُه معروضٌ فوق الرابط.
 *
 * ═══ والبديلُ المرفوض ═══
 *
 * أن يُمنح المجهِّزُ «المبيعات» ليقرأ كرتًا: فتُفتح له الفواتيرُ
 * والإجماليّاتُ وأرباحُ المحلّ — وهي عينُ ما فُصل قسمُ التجهيز ليمنعه.
 */
class ThePrepBenchOpensTheCardItWritesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);
    }

    /** موظّفٌ بأقسامٍ معدودةٍ بأعيانها — لا بدورٍ يحمل معه غيرَها */
    private function staff(string $email, array $sections): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => $email,
            'password' => bcrypt('password1'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => $sections,
        ]);
    }

    private function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::PREPARING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 25,
        ]);

        return $order;
    }

    /** طلبٌ معه ملفُّ كرتٍ موضوعٌ على القرص الخاصّ فعلًا */
    private function orderWithCard(array $extra = []): Order
    {
        $path = 'gift-cards/'.uniqid().'.png';
        Storage::disk('local')->put($path, 'PNGDATA');

        return $this->order(array_merge([
            'card_file' => $path,
            'card_file_name' => 'خطّي.png',
        ], $extra));
    }

    /** الرابطُ كما ترسله اللوحةُ نفسُها — لا كما يُكتب هنا بالحدس */
    private function cardLinkOnBoard(User $user, Order $order): ?string
    {
        $cards = $this->actingAs($user)->get(route('admin.preparation.index'))
            ->assertOk()->viewData('page')['props']['orders'];

        $card = collect($cards)->firstWhere('number', $order->number);
        $this->assertNotNull($card, 'الطلبُ ليس على اللوحة أصلًا — فرابطُه لا يُقاس');

        return $card['card_file'] ?? null;
    }

    /* ─────────────── الباب ─────────────── */

    /**
     * من يجهّز يفتح الملفَّ الذي يكتب منه — من الرابط الذي تعطيه لوحتُه.
     *
     * وهذا هو القياسُ كلُّه: لا يُفتح رابطٌ يُكتب في الاختبار، بل الذي ترسله
     * البطاقةُ إلى الشاشة. فلو بقي رابطُ «المبيعات» سقط هنا بـ٤٠٣.
     */
    public function test_the_florist_opens_the_card_from_the_link_his_board_gives_him(): void
    {
        $prep = $this->staff('prep@abaad.om', ['dashboard', 'preparation']);
        $order = $this->orderWithCard();

        $link = $this->cardLinkOnBoard($prep, $order);
        $this->assertNotNull($link, 'اللوحةُ لم ترسل رابطَ المرفق أصلًا');

        $this->actingAs($prep)->get($link)->assertOk();
    }

    /**
     * ورابطُ البطاقة يتبع قسمَ اللوحة لا قسمَ المبيعات.
     *
     * تُقاس الوجهةُ نفسُها لا جوابُها: من يقرأ هذا الاختبار يعرف لمَ لا يكفي
     * أن يُفتح الباب اليوم — القسمُ يُشتقّ من الاسم، ومن أعاد الرابطَ إلى
     * `orders.*` أعاد العطبَ كما كان.
     */
    public function test_the_board_points_at_a_door_its_own_section_opens(): void
    {
        $prep = $this->staff('prep@abaad.om', ['dashboard', 'preparation']);
        $link = $this->cardLinkOnBoard($prep, $this->orderWithCard());

        $route = app('router')->getRoutes()->match(Request::create($link, 'GET'));

        $this->assertSame(
            'preparation',
            Permissions::sectionFromRoute($route->getName()),
            'رابطُ المرفق على اللوحة يتبع قسمًا غيرَ قسمها: '.$route->getName(),
        );
    }

    /** وشاشةُ المبيعات تُبقي بابَها — المحاسبُ يراجع الطلب ولا يدخل التجهيز */
    public function test_the_sales_screen_keeps_its_own_door(): void
    {
        $sales = $this->staff('sales@abaad.om', ['dashboard', 'orders']);
        $order = $this->orderWithCard();

        $this->actingAs($sales)->get(route('admin.orders.giftcard', $order->id))->assertOk();
    }

    /* ─────────────── وما لا يُفتح ─────────────── */

    /** ومن لا يفتح أيًّا من الشاشتين لا يقرأ رسالةَ أحد */
    public function test_a_stranger_to_both_screens_reads_nothing(): void
    {
        $stranger = $this->staff('x@abaad.om', ['dashboard', 'products']);
        $order = $this->orderWithCard();

        $this->actingAs($stranger)->get(route('admin.preparation.giftcard', $order->id))->assertForbidden();
        $this->actingAs($stranger)->get(route('admin.orders.giftcard', $order->id))->assertForbidden();
    }

    /** وزائرٌ بلا حساب يُردّ إلى الدخول — لا إلى الملفّ */
    public function test_a_guest_is_sent_to_the_door(): void
    {
        $this->get(route('admin.preparation.giftcard', $this->orderWithCard()->id))->assertRedirect();
    }

    /**
     * وطلبُ الجار لا يُقرأ ولو كان من يقرأ مجهِّزًا في متجره.
     *
     * والحصرُ بالمتجر لا بالرقم: رقمٌ يُزاد واحدًا في شريط العنوان هو أسهلُ
     * ما يُجرَّب.
     */
    public function test_a_neighbours_card_is_not_read(): void
    {
        $order = $this->orderWithCard();

        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = User::create([
            'business_id' => $neighbour->id, 'name' => 'جار', 'email' => 'jar@abaad.om',
            'password' => bcrypt('password1'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['dashboard', 'preparation'],
        ]);

        $this->actingAs($theirs)->get(route('admin.preparation.giftcard', $order->id))->assertNotFound();
    }

    /** وطلبٌ بلا مرفقٍ لا يُرسل رابطًا ولا يردّ ملفًّا */
    public function test_an_order_without_a_file_offers_no_link(): void
    {
        $prep = $this->staff('prep@abaad.om', ['dashboard', 'preparation']);
        $order = $this->order();

        $this->assertNull($this->cardLinkOnBoard($prep, $order), 'رابطٌ لملفٍّ لا وجودَ له');

        $this->actingAs($prep)->get(route('admin.preparation.giftcard', $order->id))->assertNotFound();
    }

    /** وصفٌّ يحمل مسارًا لا ملفَّ تحته يُردّ ٤٠٤ لا يُخدَم فارغًا */
    public function test_a_row_whose_file_vanished_is_not_served(): void
    {
        $prep = $this->staff('prep@abaad.om', ['dashboard', 'preparation']);
        $order = $this->orderWithCard();

        Storage::disk('local')->delete($order->card_file);

        $this->actingAs($prep)->get(route('admin.preparation.giftcard', $order->id))->assertNotFound();
    }
}
