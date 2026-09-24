<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الرفُّ لا يُفرَغ مرّتين — وصفُّ التوزيع لا يُنشأ مرّتين.
 *
 * ═══ العطبُ الأوّل: الحارسُ يقرأ قبل أن يكتب ═══
 *
 * شاشةُ تعديلات المخزون — وأختُها حركةُ المخزون — كانت تقرأ دفترَ الفرع،
 * ثمّ تقارن، ثمّ تخصم، في ثلاث جُمل. وتلفان يقعان معًا على رصيدٍ عشرة
 * يقرآن «عشرة» كلاهما فيمرّان. قِستُها: **ناقصَ ستّة** في الفرع وفي
 * إجماليّ الشركة، وسطرا تعديلٍ كلاهما ‎−٨، ولا رسالةَ ردٍّ لأحدهما.
 *
 * والتعليقُ فوق الحارس نفسِه يقول ما يُفسده الرقمُ السالب: «قيمةُ المخزون
 * تصير سالبة، و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطةُ البيع تبيع ما
 * ليس عندها».
 *
 * ═══ العطبُ الثاني: صفُّ التوزيع يُنشأ مرّتين ═══
 *
 * `ensureAllocated` تسأل «هل للمنتج صفُّ فرع؟» ثمّ تُنشئ. وبين السؤال
 * والإنشاء يتّسع الوقتُ لغيرك، فيصطدم الثاني بقيد التفرّد.
 *
 * وعلى SQLite يفشل الأمرُ وحدَه؛ **وعلى PostgreSQL — وهي قاعدةُ الإنتاج —
 * يُجهض المعاملةَ كلَّها**. وهذه تُنادى من داخل معاملة البيع ومعاملة اعتماد
 * الاستلام ومعاملة الجرد: فالاصطدامُ كان يُسقط البيعةَ نفسَها.
 *
 * ═══ ولمَ الشرطُ في القاعدة لا قفلُ صفّ ═══
 *
 * قاعدةُ الفحص SQLite لا صفوفَ تُقفل فيها، فحارسٌ بالقفل وحدَه لا يُقاس
 * عليه اختبارٌ ولا تُقتل له طفرة. والشرطُ في `where` يُنفَّذ في القاعدة مع
 * الكتابة نفسِها — واحدٌ على القاعدتين، ومقيسٌ على كلتيهما.
 */
class AShelfIsNotDrawnDownTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $main;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->other = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** صنفٌ رصيدُه كلُّه في صلالة، والرئيسيُّ صفر */
    private function rose(int $inOther = 10): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 10, 'cost' => 4, 'quantity' => $inOther, 'alert_qty' => 5, 'active' => true,
        ]);

        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->other->id,
            'product_id' => $p->id, 'quantity' => $inOther]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->main->id,
            'product_id' => $p->id, 'quantity' => 0]);

        return $p;
    }

    private function damage(Product $p, int $qty, ?Branch $at = null)
    {
        return $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => ($at ?? $this->other)->id,
            'product_id' => $p->id,
            'quantity_delta' => $qty,
            'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ]);
    }

    private function bookOf(Product $p, Branch $b): int
    {
        return (int) BranchStock::where('branch_id', $b->id)->where('product_id', $p->id)->value('quantity');
    }

    private function total(Product $p): int
    {
        return (int) DB::table('products')->where('id', $p->id)->value('quantity');
    }

    /**
     * زميلٌ يُتلف ثمانيةً في الثغرة التي كانت بين القياس والكتابة.
     *
     * والبابُ هو البابُ نفسُه — لا كتابةٌ في القاعدة من تحت الشاشة.
     *
     * والثغرةُ موضعُها معروف: `bookOf` تُنادي `books` فتقرأ `branch_stocks`
     * بـ`business_id`، ثمّ يعود الرقمُ فيُقارَن فيُكتب. فالزميلُ يعمل لحظةَ
     * تمام تلك القراءة — وهو أضيقُ ما تكون الثغرة.
     *
     * **وبعد الإصلاح لا يقع هناك شيء: ذلك السؤالُ لا يُسأل أصلًا في مسار
     * النجاح** — القياسُ والكتابةُ صارا جملةً واحدة في القاعدة، فلا موضعَ
     * بينهما يُدسّ فيه عملُ غيرك. فهذا الملفّ يقيس سقوطَ الحارس القديم، لا
     * يشترط أن يعمل الزميلُ بعد الإصلاح.
     */
    private function someoneElseDamagesFirst(Product $p, int $qty): void
    {
        $this->raceOn(fn () => $this->damage($p, $qty));
    }

    /** يُشغّل عملَ الزميل لحظةَ تمام قراءة دفتر الفروع ثمّ ينصرف */
    private function raceOn(callable $colleague): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired, $colleague) {
            if ($fired || ! str_contains($q->sql, 'branch_stocks') || ! str_contains($q->sql, 'business_id')) {
                return;
            }
            $fired = true;
            $colleague();
        });
    }

    public function test_two_hands_at_once_do_not_drive_the_branch_below_zero(): void
    {
        $p = $this->rose(10);

        $this->someoneElseDamagesFirst($p, 8);
        $this->damage($p, 8);

        $this->assertGreaterThanOrEqual(0, $this->bookOf($p, $this->other),
            'ستّةَ عشرَ خرجت من عشرة: الرصيدُ كان يبلغ ‎−٦');
    }

    public function test_the_company_total_never_goes_below_zero_either(): void
    {
        $p = $this->rose(10);

        $this->someoneElseDamagesFirst($p, 8);
        $this->damage($p, 8);

        $this->assertGreaterThanOrEqual(0, $this->total($p));
    }

    public function test_only_one_of_the_two_hands_draws(): void
    {
        $p = $this->rose(10);

        $this->someoneElseDamagesFirst($p, 8);
        $this->damage($p, 8);

        $this->assertLessThanOrEqual(1, StockAdjustment::count(),
            'كانتا تُكتبان كلتاهما ‎−٨ على رصيدٍ عشرة — ولا رسالةَ ردٍّ لأحدهما');
    }

    /** والردُّ يقول ما بقي في الرفّ الآن — لا ما كان قبل أن يخصم زميلُه */
    public function test_the_refused_hand_is_told_what_is_left_now(): void
    {
        $p = $this->rose(10);

        $this->damage($p, 8)->assertSessionHasNoErrors();
        $this->damage($p, 8)->assertSessionHasErrors('quantity_delta');

        $this->assertStringContainsString('2', (string) session('errors')->first('quantity_delta'));
    }

    /** والخصمُ نفسُه: مقدارٌ واحد يمرّ مرّةً على الرصيد نفسِه لا مرّتين */
    public function test_the_same_balance_is_not_drawn_twice(): void
    {
        $p = $this->rose(10);

        $this->assertTrue(BranchStock::draw($this->other->id, $p->id, 8));
        $this->assertFalse(BranchStock::draw($this->other->id, $p->id, 8));

        $this->assertSame(2, $this->bookOf($p, $this->other));
    }

    /** وآخرُ ما في الرفّ يُتلف: الحدُّ صفرٌ لا واحد */
    public function test_the_last_one_on_the_shelf_may_be_written_off(): void
    {
        $p = $this->rose(10);

        $this->damage($p, 10)->assertSessionHasNoErrors();

        $this->assertSame(0, $this->bookOf($p, $this->other));
        $this->assertSame(0, $this->total($p));
        $this->assertSame(1, StockAdjustment::count());
    }

    /** وصفرٌ ليس خصمًا: من زاد أو لم يُغيّر لا يُردّ */
    public function test_nothing_to_draw_is_never_a_refusal(): void
    {
        $p = $this->rose(0);

        $this->assertTrue(BranchStock::draw($this->other->id, $p->id, 0));
        $this->assertSame(0, $this->bookOf($p, $this->other));
    }

    public function test_the_books_stay_balanced_after_the_race(): void
    {
        $p = $this->rose(10);

        $this->someoneElseDamagesFirst($p, 8);
        $this->damage($p, 8);

        $this->assertSame(
            $this->total($p),
            (int) BranchStock::where('product_id', $p->id)->sum('quantity'),
            'الثابت: مجموع الفروع = كمية المنتج'
        );
    }

    public function test_a_damage_within_the_balance_still_passes(): void
    {
        $p = $this->rose(10);

        $this->damage($p, 3)->assertSessionHasNoErrors();

        $this->assertSame(7, $this->bookOf($p, $this->other));
        $this->assertSame(7, $this->total($p));
    }

    public function test_a_damage_beyond_the_balance_is_refused(): void
    {
        $p = $this->rose(10);

        $this->damage($p, 11)->assertSessionHasErrors('quantity_delta');

        $this->assertSame(10, $this->bookOf($p, $this->other));
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_a_damage_in_a_branch_that_holds_none_is_refused(): void
    {
        $p = $this->rose(10);

        $this->damage($p, 1, $this->main)->assertSessionHasErrors('quantity_delta');

        $this->assertSame(0, $this->bookOf($p, $this->main));
        $this->assertSame(10, $this->total($p));
    }

    public function test_an_addition_still_reaches_the_shelf(): void
    {
        $p = $this->rose(10);

        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->main->id, 'product_id' => $p->id, 'quantity_delta' => 5,
            'reason' => 'تصحيح', 'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(5, $this->bookOf($p, $this->main));
        $this->assertSame(15, $this->total($p));
    }

    /** البابُ الشقيق — حركةُ المخزون — يحرس بالقاعدة نفسِها */
    public function test_the_manual_movement_door_is_guarded_by_the_same_rule(): void
    {
        $p = $this->rose(10);

        $issue = fn () => $this->actingAs($this->owner)->post(route('admin.inventory.store'), [
            'product_id' => $p->id, 'branch_id' => $this->other->id,
            'type' => 'صرف', 'quantity' => 8,
        ]);

        $this->raceOn($issue);
        $issue();

        $this->assertGreaterThanOrEqual(0, $this->bookOf($p, $this->other));
        $this->assertGreaterThanOrEqual(0, $this->total($p));
    }

    /* ═══════════ صفُّ التوزيع الأوّل ═══════════ */

    /** منتجٌ لا صفَّ فرعٍ له — الحالةُ التي يقع فيها الاصطدام */
    private function undistributed(int $quantity = 10): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ لم يُوزَّع',
            'price' => 10, 'cost' => 4, 'quantity' => $quantity, 'alert_qty' => 5, 'active' => true,
        ]);

        $this->assertSame(0, BranchStock::where('product_id', $p->id)->count());

        return $p;
    }

    /** زميلٌ يوزّع الصنفَ بين سؤال `ensureAllocated` «هل له توزيع؟» وإنشائها */
    private function someoneElseAllocatesFirst(Product $p, int $quantity): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired, $p, $quantity) {
            if ($fired || ! str_contains($q->sql, 'branch_stocks') || ! str_contains($q->sql, 'exists')) {
                return;
            }
            $fired = true;
            DB::table('branch_stocks')->insert([
                'business_id' => $this->business->id, 'branch_id' => $this->main->id,
                'product_id' => $p->id, 'quantity' => $quantity,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_a_collision_on_the_first_allocation_is_swallowed(): void
    {
        $p = $this->undistributed(10);

        $this->someoneElseAllocatesFirst($p, 10);

        BranchStock::ensureAllocated($this->business->id, $p->id, 10);

        $this->assertSame(1, BranchStock::where('product_id', $p->id)->count(),
            'صفٌّ واحد — ومن سبقنا كتب الرقمَ نفسَه');
    }

    public function test_a_collision_on_the_first_allocation_does_not_kill_the_sale(): void
    {
        $p = $this->undistributed(10);

        $this->someoneElseAllocatesFirst($p, 10);

        DB::transaction(fn () => StockLedger::move(
            $this->business->id, $this->main->id, [$p->id => -3], 'بيع'
        ));

        $this->assertSame(7, $this->bookOf($p, $this->main), 'البيعةُ مرّت ولم يُجهضها اصطدامُ التوزيع');
        $this->assertSame(7, $this->total($p));
    }

    /* ═══════════ وما ترسله الشاشة ═══════════ */

    /** الشاشةُ تُعطى رصيدَ كلّ فرع — لا إجماليَّ الشركة وحدَه */
    public function test_the_screen_is_given_each_branchs_balance(): void
    {
        $p = $this->rose(10);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.inventory.adjustments'))
            ->viewData('page')['props'];

        $row = collect($props['products'])->firstWhere('value', $p->id);

        $this->assertSame(10, (int) $row['quantity'], 'الإجماليّ كما كان');
        $this->assertSame(10, (int) $row['stock'][$this->other->id], 'ورصيدُ صلالة');
        $this->assertSame(0, (int) $row['stock'][$this->main->id], 'ورصيدُ الرئيسيّ صفر');
    }

    /** وصنفٌ لم يُوزَّع بعدُ رصيدُه كلُّه في الفرع الأوّل — قاعدةُ `books` */
    public function test_an_undistributed_product_is_shown_in_the_first_branch(): void
    {
        $p = $this->undistributed(12);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.inventory.adjustments'))
            ->viewData('page')['props'];

        $row = collect($props['products'])->firstWhere('value', $p->id);

        $this->assertSame(12, (int) $row['stock'][$this->main->id]);
        $this->assertArrayNotHasKey($this->other->id, $row['stock']);
    }

    public function test_the_allocation_still_happens_when_no_one_races(): void
    {
        $p = $this->undistributed(10);

        BranchStock::ensureAllocated($this->business->id, $p->id, 10);

        $this->assertSame(10, $this->bookOf($p, $this->main),
            'الرصيدُ غيرُ الموزَّع يُنسب إلى الفرع الرئيسيّ');
    }
}
