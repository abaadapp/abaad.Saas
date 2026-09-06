<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\OrderTransition;
use App\Support\ReportData;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ورقةٌ سُلِّمت إلى جهةٍ حكوميّة لا يُعاد كتابتُها.
 *
 * الإقرارُ يُبنى من `Order::scopeSold`، وهي تستثني الملغى. فإلغاءُ فاتورةٍ من
 * ربعٍ قُدِّم يُنقص إيرادَه وضريبتَه **بعد تسليم الورقة** — يفتح التاجر تقريرَه
 * بعد شهرين فيجد رقمًا غير الذي قدّمه، ولا شيء يقول لماذا. ولم يكن في النظام
 * قفلُ فترةٍ إطلاقًا.
 *
 * والحدُّ يقوله التاجرُ بنفسه ولا يُخمَّن: الرُّبعُ عند من تبدأ سنتُه المالية في
 * يوليو غيرُه عند سواه.
 *
 * وما بعد القفل يُصحَّح بمستنده — إشعارُ دائنٍ في فترةٍ مفتوحة — لا بمحو الأصل.
 */
class AFiledReturnIsNotRewrittenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    /**
     * فاتورةٌ مفتوحةُ الحال — وهي التي تُلغى فعلًا.
     *
     * و«مكتمل» نهايةٌ لا يُنتقل منها (`OrderStatus::NEXT`)، فالإلغاءُ لا يبلغها
     * من باب الحالات أصلًا. لكنّ الطلب غيرَ المكتمل **يُعدّ بيعًا في الإقرار**
     * (`Order::scopeSold` تستثني الملغى والمعلَّق وحدهما) — فطلبٌ من ربعٍ
     * قُدِّم يُلغى اليوم يُنقص ضريبةَ ذلك الربع بعد تسليم ورقته.
     */
    private function sale(string $when, float $tax = 5): Order
    {
        return Order::create([
            'business_id' => $this->bid(), 'number' => 'S'.uniqid(),
            'status' => OrderStatus::READY, 'payment_status' => 'مدفوع', 'is_held' => false,
            'subtotal' => 100, 'discount' => 0, 'tax' => $tax, 'delivery_fee' => 0,
            'total' => 100 + $tax, 'payment_method' => 'نقدي',
            'ordered_at' => now()->parse($when),
        ]);
    }

    private function filedThrough(string $date): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->bid(), 'key' => 'vat_filed_through'],
            ['value' => $date],
        );
    }

    /* ============================== القفل ============================== */

    public function test_an_invoice_inside_a_filed_period_is_not_cancelled(): void
    {
        $order = $this->sale(now()->subMonths(4)->toDateTimeString());
        $this->filedThrough(now()->subMonths(2)->toDateString());

        $this->expectException(RuntimeException::class);

        OrderCorrection::cancel($order);
    }

    public function test_the_return_keeps_the_number_that_was_filed(): void
    {
        // وهذا هو الأثرُ لا الرسالة: رقمٌ سُلِّم لا يتغيّر بعد تسليمه
        $this->sale(now()->subMonths(4)->toDateTimeString(), tax: 5);
        $this->filedThrough(now()->subMonths(2)->toDateString());

        $before = ReportData::vat($this->bid(), ['range' => 'year'])['summary']['output'];

        try {
            OrderCorrection::cancel(Order::firstOrFail());
        } catch (RuntimeException) {
            // مقصود
        }

        $this->assertSame($before, ReportData::vat($this->bid(), ['range' => 'year'])['summary']['output']);
        $this->assertSame(5.0, round((float) $before, 3));
    }

    public function test_an_invoice_after_the_filed_day_is_still_cancelled(): void
    {
        /*
         * والقفلُ لا يمتدّ إلى ما لم يُقدَّم: فاتورةُ اليوم أولى ما يُلغى،
         * وقفلٌ يبتلعها يترك التاجر بلا وسيلة إصلاحٍ أصلًا.
         */
        $order = $this->sale(now()->toDateTimeString());
        $this->filedThrough(now()->subMonth()->toDateString());

        OrderCorrection::cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_the_day_itself_is_inside_the_filed_period(): void
    {
        // «قُدِّم حتّى ٣١ مارس» تشمل الحادي والثلاثين، لا ما قبله وحده
        $day = now()->subMonths(2)->startOfDay();
        $order = $this->sale($day->copy()->addHours(14)->toDateTimeString());
        $this->filedThrough($day->toDateString());

        $this->expectException(RuntimeException::class);

        OrderCorrection::cancel($order);
    }

    public function test_a_shop_that_filed_nothing_locks_nothing(): void
    {
        $order = $this->sale(now()->subYear()->toDateTimeString());

        $this->assertNull(Vat::filedThrough($this->bid()));

        OrderCorrection::cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_neighbours_lock_does_not_close_this_shop(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create([
            'business_id' => $other->id, 'key' => 'vat_filed_through',
            'value' => now()->toDateString(),
        ]);

        $order = $this->sale(now()->subMonths(6)->toDateTimeString());

        OrderCorrection::cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    /* ========================== الطريقُ من الشاشة ========================== */

    public function test_the_screen_says_why_instead_of_five_hundred(): void
    {
        /*
         * `OrderTransition::apply` تردّ رسالةً تُعرض. واستثناءٌ يخرج منها
         * يصير خمسمئةً على موظّفٍ ضغط زرًّا مشروعًا.
         */
        $order = $this->sale(now()->subMonths(4)->toDateTimeString());
        $this->filedThrough(now()->subMonths(2)->toDateString());

        $error = OrderTransition::apply($order, OrderStatus::CANCELLED);

        $this->assertNotNull($error, 'مرّ الإلغاء من باب الحالات');

        // والرسالةُ تقول أيَّ فترةٍ أُقفلت — لا «تعذّر» وحدها. والتاريخُ لا يُترجَم
        $this->assertStringContainsString(
            now()->subMonths(2)->toDateString(),
            $error,
            'رسالةٌ لا تقول أيُّ إقرارٍ يمنع',
        );
        $this->assertSame(OrderStatus::READY, $order->fresh()->status);
    }

    public function test_a_completed_invoice_never_reaches_cancellation_by_status(): void
    {
        /*
         * «مكتمل» نهايةٌ في `OrderStatus::NEXT`. فبابُ الحالات لا يبلغه، وما
         * بعده تصحيحُ فاتورةٍ لا انتقالُ حال — والتصحيحُ محدودٌ بيومه أصلًا
         * (انظر AnIssuedInvoiceClosesTest).
         */
        $order = $this->sale(now()->toDateTimeString());
        $order->update(['status' => OrderStatus::COMPLETED]);

        $error = OrderTransition::apply($order, OrderStatus::CANCELLED);

        $this->assertNotNull($error);
        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
    }

    /* ============================ ضبطُ الحدّ ============================ */

    public function test_the_owner_sets_the_lock_from_the_settings_screen(): void
    {
        $date = now()->subMonth()->toDateString();

        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['vat_filed_through' => $date])
            ->assertSessionHasNoErrors();

        $this->assertSame($date, optional(Vat::filedThrough($this->bid()))->toDateString());
    }

    public function test_a_period_that_has_not_ended_cannot_be_filed(): void
    {
        // قفلُ الغد يمنع إلغاء فاتورةِ اليوم — وهي أولى ما يُلغى
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['vat_filed_through' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('vat_filed_through');

        $this->assertNull(Vat::filedThrough($this->bid()));
    }

    public function test_the_lock_can_be_lifted_again(): void
    {
        // خطأٌ في التاريخ يُصحَّح: قفلٌ لا يُفتح يحبس التاجر على غلطته
        $this->filedThrough(now()->subMonth()->toDateString());

        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['vat_filed_through' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull(Vat::filedThrough($this->bid()));
    }
}
