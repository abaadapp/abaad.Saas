<?php

namespace Tests\Feature;

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
 * خطُّ حال الطلب — يقول من أين تحرّك فعلًا، لا من أين ظنّت الشاشة.
 *
 * ═══ العطب ═══
 *
 * البابان — لوحةُ التجهيز وصفحةُ الطلب — يقرآن الحالَ من نسخةِ المنادي:
 *
 *     $from = $order->status;              // قبل القفل
 *     OrderTransition::apply($order, $to); // يقرأ الحالَ من جديدٍ مقفلةً
 *     Activity::log('status', "من «{$from}» إلى «{$to}»");
 *
 * وبين القراءتين فرجة. واللوحةُ معروضةٌ على كلّ شاشات المحلّ في وقتٍ واحد
 * ولا تتحدّث من نفسها إلّا كلّ عشرين ثانية — فالفرجةُ ليست نظريّة.
 *
 * ═══ والحارسُ يصمد والسطرُ يكذب ═══
 *
 * `OrderTransition` تقرأ الحالَ مقفلةً وتقيس عليها — فلا يقع نقلٌ ممنوع،
 * والمالُ والمخزون سليمان. لكنّ السطرَ يُكتب بالحال القديمة.
 *
 * مثالُه بحروفه: الطلبُ «جديد» على شاشتين. يضغط الأوّل «قيد التجهيز»
 * فينتقل. والثاني بطاقتُه قديمةٌ تقول «جديد»، فيضغط «مؤكّد» — وهو انتقالٌ
 * مشروعٌ من «قيد التجهيز» فيقع. ويُكتب: «من «جديد» إلى «مؤكّد»».
 *
 * فيقرأ صاحبُ المحلّ سطرين متتاليين كلاهما يبدأ من «جديد»، ولا يرى أنّ
 * الطلبَ رجع من الطاولة إلى «مؤكّد». وهذا الخطُّ هو ما يُرجَع إليه حين
 * يُسأل «متى بُدئ تجهيزُ هذا الطلب ومن بدأه» — ونافذةُ التفاصيل تعرضه
 * بوصفه ما جرى.
 *
 * وهي عينُ العلّة التي صُحّحت في صرف الرواتب: ما بعد المعاملة يُكتب بما
 * قُرئ قبلها.
 */
class ALineSaysWhereTheOrderReallyStoodTest extends TestCase
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

    private function order(string $status = OrderStatus::PENDING): Order
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

    /**
     * زميلٌ يسبقنا بين قراءة المتحكّم وقراءة القفل.
     *
     * ═══ ولمَ لا خيطان ═══
     *
     * السباقُ يُقاس محسومًا لا مرجوًّا: يُنصَت لأوّل استعلامٍ يقرأ الطلبَ
     * برقمه — وهو قراءةُ المتحكّم قبل القفل — فتُبدَّل الحالُ من تحته. وما
     * بعده يقرأ ما قرأه الزميلُ الأسرع.
     *
     * و`DB::table` لا النموذج: كتابةٌ خامٌ لا توقظ أحداثًا ولا تُغيّر شيئًا
     * سوى العمود.
     */
    private function someoneElseMovesItFirst(Order $order, string $to): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired, $order, $to) {
            if ($fired || ! str_contains($q->sql, 'orders') || ! str_contains($q->sql, 'number')) {
                return;
            }
            $fired = true;

            DB::table('orders')->where('id', $order->id)->update(['status' => $to]);
        });
    }

    /** آخرُ سطرٍ كُتب في سجلّ النشاط للحال */
    private function lastLine(Order $order): ?string
    {
        return DB::table('activity_logs')
            ->where('subject_type', 'order')->where('subject_id', $order->id)
            ->where('action', 'status')->orderByDesc('id')->value('description');
    }

    /* ─────────────── لوحة التجهيز ─────────────── */

    public function test_the_board_writes_the_place_the_order_really_left(): void
    {
        $order = $this->order(OrderStatus::PENDING);
        $this->someoneElseMovesItFirst($order, OrderStatus::PREPARING);

        // بطاقتُنا ما زالت تقول «جديد»، و«مؤكّد» مشروعةٌ من «قيد التجهيز» أيضًا
        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status, 'النقلُ نفسُه لم يقع');

        $line = $this->lastLine($order);
        $this->assertStringContainsString(OrderStatus::PREPARING, (string) $line, 'السطرُ لا يقول من أين تحرّك فعلًا');
        $this->assertStringNotContainsString(OrderStatus::PENDING, (string) $line, 'السطرُ يقول حالًا تجاوزها غيرُنا');
    }

    /* ─────────────── وصفحةُ الطلب ─────────────── */

    public function test_the_order_screen_writes_it_too(): void
    {
        $order = $this->order(OrderStatus::PENDING);
        $this->someoneElseMovesItFirst($order, OrderStatus::PREPARING);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertSessionHasNoErrors();

        $line = $this->lastLine($order);
        $this->assertStringContainsString(OrderStatus::PREPARING, (string) $line);
        $this->assertStringNotContainsString(OrderStatus::PENDING, (string) $line);
    }

    /* ─────────────── وبلا سباقٍ لا يتغيّر شيء ─────────────── */

    /**
     * والحالُ العاديّة تُكتب كما كانت تُكتب.
     *
     * فإصلاحٌ يقرأ الحالَ **بعد** النقل يجعل كلّ سطرٍ يقول «من «جاهز» إلى
     * «جاهز»» — وهو أعمى بقدر الأوّل.
     */
    public function test_a_quiet_move_still_names_both_ends(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::READY])
            ->assertSessionHasNoErrors();

        $line = (string) $this->lastLine($order);

        $this->assertStringContainsString(OrderStatus::PREPARING, $line, 'المنطلقُ سقط من السطر');
        $this->assertStringContainsString(OrderStatus::READY, $line, 'الوجهةُ سقطت من السطر');
        $this->assertStringContainsString($order->number, $line, 'رقمُ الطلب سقط من السطر');
    }

    public function test_the_order_screen_names_both_ends_too(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::READY])
            ->assertSessionHasNoErrors();

        $line = (string) $this->lastLine($order);

        $this->assertStringContainsString(OrderStatus::PREPARING, $line);
        $this->assertStringContainsString(OrderStatus::READY, $line);
    }

    /* ─────────────── ونقلٌ مرفوضٌ لا يترك أثرًا ─────────────── */

    /** ومن رُدّ لا يُكتب له سطر: خطٌّ يحمل ما لم يقع أسوأُ من خطٍّ ناقص */
    public function test_a_refused_move_writes_no_line(): void
    {
        $order = $this->order(OrderStatus::CANCELLED);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::READY])
            ->assertSessionHasErrors('status');

        $this->assertNull($this->lastLine($order), 'كُتب سطرٌ لنقلٍ لم يقع');
    }

    /**
     * ومن سبقه غيرُه إلى حالٍ لا يُنتقل منها يُردّ — ولا يُكتب له سطر.
     *
     * وهذا هو المقياسُ الذي يُبقي الحارسَ على القفل: لو قُرئت الحالُ مرّةً
     * واحدةً قبله لَمرّ هذا النقل.
     */
    public function test_a_race_into_a_closed_order_is_refused_and_silent(): void
    {
        $order = $this->order(OrderStatus::PENDING);
        $this->someoneElseMovesItFirst($order, OrderStatus::CANCELLED);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertNull($this->lastLine($order));
    }
}
