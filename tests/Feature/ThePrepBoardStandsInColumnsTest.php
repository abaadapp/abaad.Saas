<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PreparationController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أعمدةُ لوحة التجهيز — تغطيةٌ لا حدْس.
 *
 * ═══ ولمَ يُحرَس هذا ═══
 *
 * `PreparationController::COLUMNS` تجميعُ عرضٍ يُكتب باليد. وحالةٌ حيّةٌ لا
 * عمودَ لها تسقط من العرض العموديّ: البطاقةُ موجودةٌ في الحمولة ولا تُرسم في
 * عمود. و«تعذّر التوصيل» بالذات — طلبٌ رجع من الطريق هو أحوجُ ما على اللوحة
 * إلى أن يُرى، وهو أوّلُ ما يسقط من قائمةٍ تُكتب بالحدس.
 *
 * وملفُّ المتحكّم يقول «التغطيةُ محروسة. انظر `ThePrepBoardStandsInColumnsTest`»
 * — ولم يكن لهذا الاسم ملفّ. فالوعدُ مكتوبٌ والحارسُ غائب، وهو أسوأُ من لا
 * وعد: من يضيف حالةً يقرأ أنّ ثمّة ما يُنبّهه فلا يفحص بنفسه.
 *
 * ═══ وما تقيسه هذه الاختبارات ═══
 *
 * الحياةُ تُقاس من `OrderStatus` لا تُنسخ هنا: قائمةٌ ثانيةٌ تُكتب في الحارس
 * تجعله يوافق نفسَه ويخالف النظام.
 */
class ThePrepBoardStandsInColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password1'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** ما لم يُغلق — من `OrderStatus` نفسِها لا من قائمةٍ تُكتب هنا */
    private function liveStatuses(): array
    {
        return array_values(array_diff(OrderStatus::ALL, OrderStatus::CLOSED));
    }

    private function order(string $status): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'status' => $status, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 25,
        ]);

        return $order;
    }

    private function props(array $query = []): array
    {
        return $this->get(route('admin.preparation.index', $query))
            ->assertOk()->viewData('page')['props'];
    }

    /* ─────────────── التغطية ─────────────── */

    /** كلُّ حالةٍ حيّةٍ لها عمودٌ واحد — لا صفرٌ ولا اثنان */
    public function test_every_living_status_stands_in_exactly_one_column(): void
    {
        foreach ($this->liveStatuses() as $status) {
            $in = array_keys(array_filter(
                PreparationController::COLUMNS,
                fn (array $statuses) => in_array($status, $statuses, true),
            ));

            $this->assertCount(
                1,
                $in,
                "«{$status}» في ".count($in).' عمودٍ — والبطاقةُ تسقط من العرض أو تُرسم مرّتين',
            );
        }
    }

    /** ولا عمودَ لحالةٍ مغلقة: عمودٌ لا تصله بطاقةٌ أبدًا يُقرأ «لا شيء اليوم» */
    public function test_no_column_waits_for_a_closed_order(): void
    {
        foreach (PreparationController::COLUMNS as $key => $statuses) {
            foreach ($statuses as $status) {
                $this->assertNotContains(
                    $status,
                    OrderStatus::CLOSED,
                    "عمود «{$key}» يعِد بحالةٍ مغلقة لا تصل اللوحةَ أصلًا: {$status}",
                );
            }
        }
    }

    /** و«أخرى» ليست عمودًا مكتوبًا — تُركَّب حين يظهر ما لا يُعرف */
    public function test_the_rest_column_is_not_declared(): void
    {
        $this->assertArrayNotHasKey('other', PreparationController::COLUMNS);
    }

    /* ─────────────── والشاشةُ تسمّي ما يصلها ─────────────── */

    /**
     * ولكلّ عمودٍ اسمٌ في الشاشة — ومعه «أخرى».
     *
     * الخريطةُ تصل من الخادم، والشاشةُ تترجم مفتاحَها باسمٍ مكتوب. فمفتاحٌ بلا
     * اسمٍ يُرسم رأسُه `out_for_delivery` فوق بطاقاتٍ عربيّة.
     */
    public function test_the_screen_has_a_name_for_every_column(): void
    {
        $tsx = file_get_contents(base_path('resources/js/Pages/Admin/Preparation/Index.tsx'));
        $this->assertNotFalse($tsx, 'تعذّرت قراءةُ ملفّ الشاشة — فتسميتُها لا تُقاس');

        preg_match('/COLUMN_LABELS:\s*Record<string,\s*string>\s*=\s*\{(.*?)\};/s', $tsx, $m);
        $this->assertNotEmpty($m, 'لم تُقرأ أسماءُ الأعمدة في الشاشة أصلًا — فالقياسُ معطوب');

        foreach ([...array_keys(PreparationController::COLUMNS), 'other'] as $key) {
            $this->assertMatchesRegularExpression(
                '/\b'.preg_quote($key, '/').'\s*:/',
                $m[1],
                "عمود «{$key}» يصل الشاشةَ بلا اسمٍ يُرسم",
            );
        }
    }

    /* ─────────────── وما تفعله اللوحةُ فعلًا ─────────────── */

    /**
     * وكلُّ حالةٍ حيّةٍ تصل اللوحةَ وتُعدّ في عمودها.
     *
     * والقياسُ من الطرفين: البطاقةُ في الحمولة، وعدّادُ عمودها واحد. فحارسٌ
     * يقيس الثابتَ وحده يمرّ على استعلامٍ يُسقط الحالةَ قبل أن تُصنَّف.
     */
    public function test_a_card_of_every_living_status_reaches_its_column(): void
    {
        $numbers = [];
        foreach ($this->liveStatuses() as $status) {
            $numbers[$status] = $this->order($status)->number;
        }

        $props = $this->props();
        $onBoard = collect($props['orders'])->keyBy('number');

        foreach ($numbers as $status => $number) {
            $this->assertTrue($onBoard->has($number), "طلبٌ حالُه «{$status}» لم يصل اللوحة");
        }

        foreach (PreparationController::COLUMNS as $key => $statuses) {
            $this->assertSame(
                count($statuses),
                $props['columnCounts'][$key] ?? null,
                "عدّادُ عمود «{$key}» لا يطابق ما وُضع فيه",
            );
        }

        // ولا شيءَ يسقط في «أخرى» ما دامت التغطيةُ تامّة
        $this->assertSame(0, $props['columnCounts']['other'] ?? null);
    }

    /**
     * وحالٌ لا يعرفها هذا الملفّ تُعدّ في «أخرى» ولا تُبتلع.
     *
     * قاعدةٌ قديمةٌ فيها حالٌ رُفعت من النظام: البطاقةُ تبقى على اللوحة
     * ويُقال إنّها هناك — لا تُطرح من العدّ فيقرأ من يجهّز «١٢» وتحته ١٣.
     */
    public function test_a_status_this_file_does_not_know_is_counted_apart(): void
    {
        $order = $this->order(OrderStatus::PREPARING);
        // كتابةٌ خام: النموذجُ لا يقبل حالًا مخترَعة، والقاعدةُ فيها ما فيها
        DB::table('orders')->where('id', $order->id)->update(['status' => 'حالٌ منسيّة']);

        $props = $this->props();

        $this->assertSame(1, $props['columnCounts']['other'] ?? null, 'الحالُ المجهولة ابتُلعت من العدّ');
        $this->assertCount(1, $props['orders'], 'وبطاقتُها سقطت من الحمولة');
    }
}
