<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * نقطة البيع بعد رفع الوردية — كاملةً، لا موضع الحذف وحده.
 *
 * الحذف يترك أثرَين لا يظهران في شاشة الحذف نفسها: مسارٌ يُنادى من زرٍّ بقي
 * في شاشةٍ أخرى فيرفع Ziggy خطأً يُبيّض الصفحة كلَّها، ودالّةٌ فقدت وسيطها
 * فتسقط بخمسمئة عند أوّل بيعة. فالفحص هنا يمشي على **كلّ** صفحات نقطة البيع
 * — بحساب صاحب النشاط وبحساب الكاشير — ويبيع بيعةً حقيقية من أوّلها.
 *
 * وأخصُّ ما يُفحص: أنّ البيع **لا يُمنع** بعد اليوم. كان مفتاح
 * `require_open_shift` يحبس الصندوق، وقد يبقى مرفوعًا في قاعدة متجرٍ رفعه —
 * فلو بقي له قارئ لَوقف صندوقُه بلا شاشةٍ يُطفئه منها.
 */
class PosWithoutShiftsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true,
        ]);

        $this->activatePosDevice($this->business->id);
    }

    private function sell(string $method = 'نقدي')
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => $method,
            'client_uuid' => uniqid('e', true),
        ]);
    }

    /* --------------------------- لا حاجز --------------------------- */

    public function test_the_register_opens_and_sells_with_no_shift_anywhere(): void
    {
        $this->actingAs($this->cashier)->get(route('pos.index'))->assertOk();

        $this->sell()->assertOk();

        $order = Order::where('business_id', $this->business->id)->where('is_held', false)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->shift_id, 'العمود بقي في الجدول للتاريخ، ولا يُكتب فيه بعد اليوم');
    }

    /**
     * والمفتاح المرفوع من قبلُ لا يحبس أحدًا.
     *
     * صفٌّ باقٍ في `settings` عند متجرٍ ضبطه يوم كانت الوردية تعمل. ولو بقي
     * له قارئٌ واحد لَوقف الصندوق صباحًا بلا سببٍ ظاهر ولا مقبضٍ يُطفئه.
     */
    public function test_an_old_raised_gate_no_longer_blocks_the_sale(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'require_open_shift', 'value' => '1',
        ]);

        $this->actingAs($this->cashier)->get(route('pos.index'))->assertOk();
        $this->sell()->assertOk();
    }

    /* ------------------------- الشاشات كلّها ------------------------- */

    /**
     * كلُّ صفحةٍ في نقطة البيع تُفتح — بلا خمسمئة وبلا تحويلٍ إلى مفقود.
     *
     * والقائمة تُبنى من جدول المسارات لا تُكتب باليد: مسارٌ يُضاف غدًا يدخل
     * الفحص من تلقائه، وقائمةٌ يدوية كانت ستنسى التالي.
     */
    public function test_every_pos_screen_opens_for_the_owner(): void
    {
        $skip = ['pos.setup', 'pos.currency.switch', 'pos.orders.resume', 'pos.receipts.show', 'pos.receipt.pdf', 'pos.order-details'];

        foreach ($this->posScreens($skip) as $name => $uri) {
            $this->actingAs($this->owner)->get('/'.ltrim($uri, '/'))
                ->assertOk("الصفحة «{$name}» لا تُفتح");
        }
    }

    /**
     * والكاشير كذلك — إلّا المدفوعات: شاشةٌ مالية تُقرأ منها حصيلةُ الصندوق،
     * ويُمنع منها من يبيع. والمنع يُفحص لأنّه قرار: لو انفتحت له لَقرأ
     * مقبوضات اليوم كلَّها.
     */
    public function test_every_pos_screen_opens_for_the_cashier_except_the_money(): void
    {
        $skip = ['pos.setup', 'pos.currency.switch', 'pos.orders.resume', 'pos.receipts.show', 'pos.receipt.pdf', 'pos.order-details'];

        foreach ($this->posScreens($skip) as $name => $uri) {
            $response = $this->actingAs($this->cashier)->get('/'.ltrim($uri, '/'));

            if ($name === 'pos.payments') {
                $response->assertForbidden();

                continue;
            }

            $response->assertOk("الصفحة «{$name}» لا تُفتح للكاشير");
        }
    }

    /** مسارات العرض في نقطة البيع — بلا وسائط، فتُطلب كما هي */
    private function posScreens(array $skip): array
    {
        $screens = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'pos.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($name, $skip, true) || str_contains($route->uri(), '{')) {
                continue;
            }

            $screens[$name] = $route->uri();
        }

        return $screens;
    }

    /* --------------------------- المقبوضات --------------------------- */

    /**
     * المقبوضات صارت مدى **اليوم** بعد أن كانت مدى الوردية.
     *
     * والحدُّ يجب أن يُقصّ فعلًا: لو بقيت تعرض «آخر ٥٠٠ فاتورة» لَقرأ صاحبها
     * مجموع أسبوعٍ وهو يظنّه اليوم، فيطابقه بما في الدرج ولا يطابق.
     */
    public function test_the_payments_screen_shows_today_not_a_mixed_week(): void
    {
        $this->actingAs($this->owner);
        $this->sell();

        Order::where('business_id', $this->business->id)->where('is_held', false)
            ->latest('id')->first()->update(['ordered_at' => now()->subDays(3)]);

        $this->sell();

        $receipts = $this->actingAs($this->owner)->get(route('pos.payments'))
            ->assertOk()->viewData('page')['props']['receipts'];

        $this->assertCount(1, $receipts, 'فاتورة الأمس دخلت مقبوضات اليوم');
    }

    /* --------------------------- لا أثر --------------------------- */

    /** ولا زرَّ يقود إلى مسارٍ رُفع: Ziggy ترفع خطأً يُبيّض الصفحة كلَّها */
    public function test_no_screen_still_points_at_a_removed_route(): void
    {
        $guilty = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), 'pos.shift')) {
                $guilty[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $guilty, 'زرٌّ يُنادي مسار وردية مرفوع');
    }
}
