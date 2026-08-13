<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Support\Shifts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوردية التي نسيها الكاشير.
 *
 * كان الفتح يُرجع الوردية القائمة أيًّا كان عمرها. فمن ذهب إلى بيته بلا
 * إقفال تبقى ورديتُه مفتوحة، وكاشير الصباح يبيع في درج الأمس ولا يعلم:
 * مبيعات يومين في وردية واحدة، ودرجٌ لا يطابق شيئًا، ورقابةٌ تموت بصمت
 * والشاشة تبدو سليمة.
 *
 * وأخطر ما في الإصلاح ليس الإقفال بل ما يُكتب في خانة الفرق: صفرٌ يعني
 * «طابق الدرج» — فيُبرّئ نقصًا لم يعدّ أحدٌ ليعرفه، ويفسد كلّ متوسّطٍ
 * يُحسب بعده. فالمجهول يبقى مجهولًا.
 */
class AbandonedShiftTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 100, 'active' => true,
        ]);
    }

    private function openShift(string $openedAt, float $float = 20): Shift
    {
        $this->actingAs($this->owner);
        $shift = Shifts::open($float, $this->owner->id);
        $shift->update(['opened_at' => \Illuminate\Support\Carbon::parse($openedAt)]);

        return $shift->fresh();
    }

    public function test_opening_after_an_abandoned_shift_starts_a_clean_one(): void
    {
        $yesterday = $this->openShift(now()->subHours(30)->toDateTimeString());

        $today = Shifts::open(15, $this->owner->id);

        $this->assertNotSame($yesterday->id, $today->id, 'ورث كاشيرُ اليوم درجَ الأمس');
        $this->assertSame(Shift::CLOSED, $yesterday->fresh()->status);
        $this->assertSame(Shift::OPEN, $today->status);
        $this->assertSame(15.0, (float) $today->opening_balance);
    }

    public function test_a_fresh_shift_is_not_stolen_from_its_owner(): void
    {
        // ساعتان ليستا نسيانًا: من يفتح ثانيةً في اليوم نفسه يجد ورديته
        $morning = $this->openShift(now()->subHours(2)->toDateTimeString());

        $again = Shifts::open(50, $this->owner->id);

        $this->assertSame($morning->id, $again->id);
        $this->assertSame(Shift::OPEN, $morning->fresh()->status);
    }

    public function test_an_abandoned_shift_keeps_its_difference_unknown(): void
    {
        /*
         * أهمّ ما في الملفّ: صفرٌ في خانة الفرق يقول «طابق الدرج» عن درجٍ
         * لم يفتحه أحد.
         */
        $shift = $this->openShift(now()->subHours(30)->toDateTimeString());

        Shifts::open(15, $this->owner->id);
        $closed = $shift->fresh();

        $this->assertNull($closed->actual_balance, 'كُتب معدودٌ لم يعدّه أحد');
        $this->assertNull($closed->difference, 'كُتب فرقٌ صفر عن درجٍ لم يُعدّ');
        $this->assertSame(Shift::BY_SYSTEM, $closed->closed_kind);
        $this->assertTrue($closed->closedWithoutCount());
    }

    public function test_the_expected_cash_is_still_frozen_on_an_abandoned_shift(): void
    {
        // ما بيع في الوردية معلومٌ ولو لم يُعدّ الدرج — والمتوقّع يُحفظ
        $shift = $this->openShift(now()->subHours(30)->toDateTimeString(), 20);

        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000001',
            'branch_id' => $this->branch->id, 'shift_id' => $shift->id,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => 'مكتمل',
            'subtotal' => 30, 'discount' => 0, 'tax' => 0, 'total' => 30,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        Shifts::open(10, $this->owner->id);

        $this->assertSame(50.0, (float) $shift->fresh()->expected_balance);
        $this->assertSame(30.0, (float) $shift->fresh()->cash_sales);
    }

    public function test_the_scheduled_sweep_closes_what_no_one_reopened(): void
    {
        /*
         * الفتح وحده لا يكفي: من لم يفتح غدًا تبقى ورديتُه مفتوحةً أسبوعًا
         * تُجمَع فيها مبيعات أيّام.
         */
        $shift = $this->openShift(now()->subHours(40)->toDateTimeString());

        $this->artisan('shifts:auto-close')->assertSuccessful();

        $this->assertSame(Shift::CLOSED, $shift->fresh()->status);
        $this->assertNull($shift->fresh()->difference);
    }

    public function test_the_sweep_leaves_a_running_shift_alone(): void
    {
        $shift = $this->openShift(now()->subHours(3)->toDateTimeString());

        $this->artisan('shifts:auto-close')->assertSuccessful();

        $this->assertSame(Shift::OPEN, $shift->fresh()->status);
    }

    public function test_a_dry_run_closes_nothing(): void
    {
        $shift = $this->openShift(now()->subHours(40)->toDateTimeString());

        $this->artisan('shifts:auto-close --dry-run')->assertSuccessful();

        $this->assertSame(Shift::OPEN, $shift->fresh()->status);
    }

    public function test_the_ceiling_is_the_merchants_to_set(): void
    {
        // متجرٌ يعمل ورديتين طويلتين يرفع السقف، وآخر يخفضه
        Setting::create(['business_id' => $this->business->id, 'key' => 'shift_max_hours', 'value' => '4']);

        $shift = $this->openShift(now()->subHours(5)->toDateTimeString());

        $this->artisan('shifts:auto-close')->assertSuccessful();

        $this->assertSame(Shift::CLOSED, $shift->fresh()->status);
    }

    public function test_a_zero_ceiling_is_refused(): void
    {
        // سقفٌ صفر يعني إقفال كل وردية لحظة فتحها — فيُردّ إلى الافتراضي
        Setting::create(['business_id' => $this->business->id, 'key' => 'shift_max_hours', 'value' => '0']);

        $this->assertSame(Shifts::DEFAULT_MAX_HOURS, Shifts::maxHours($this->business->id));
    }



    public function test_a_counted_close_still_records_its_difference(): void
    {
        // الحماية ليست تعطيلًا: الإقفال العاديّ يبقى كما كان
        $shift = $this->openShift(now()->toDateTimeString(), 20);

        Shifts::close($shift, 18.5);
        $closed = $shift->fresh();

        $this->assertSame(Shift::BY_COUNT, $closed->closed_kind);
        $this->assertSame(18.5, (float) $closed->actual_balance);
        $this->assertSame(-1.5, (float) $closed->difference);
        $this->assertFalse($closed->closedWithoutCount());
    }


}
