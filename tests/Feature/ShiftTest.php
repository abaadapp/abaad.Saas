<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Support\Shifts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وردية الصندوق.
 *
 * جدول الورديات موجود منذ أوّل هجرة، والنموذج موجود، ومستورَدٌ في
 * PosController — ولا سطر واحد يكتب فيه أو يقرأ منه. سقالةٌ تبدو ميزةً.
 *
 * والغرض واحد: أن يُطابَق ما في الدرج بما يجب أن يكون فيه. والمبيعات وحدها
 * لا تفعل ذلك — بيعةٌ بالبطاقة لا تضع في الدرج ريالًا.
 */
class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->activatePosDevice($this->business->id, $this->branch->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'quantity' => 100, 'active' => true,
        ]);

        // بلا ضريبة: الحساب هنا عن الدرج لا عن الضريبة، وإقحامها يجعل
        // فشل الاختبار غامضًا بين الاثنين
        \App\Models\Setting::create([
            'business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '0',
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }

    private function sell(string $method = 'نقدي', int $qty = 1)
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => $qty]],
            'payment_method' => $method,
        ]);
    }

    /** يُشغَّل المنع صراحةً — وهو مطفأ افتراضيًّا */
    private function requireShift(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'require_open_shift'],
            ['value' => '1'],
        );
    }

    /* ----------------------- مفتاح المنع ----------------------- */

    /**
     * البيع لا يُمنع افتراضيًّا.
     *
     * منعُ البيع أخطر تصرّف في نقطة بيع: خللٌ واحد — كاشير لم يفتح، أو وردية
     * على فرعٍ آخر — يوقف المحل ولا يجد صاحبه ما يفعله. فالوردية تعمل وتُحسب
     * وتُقفل من أوّل يوم، ولا تمنع شيئًا حتى يقرّر صاحب النشاط ذلك.
     */
    public function test_selling_is_not_blocked_by_default(): void
    {
        $this->sell()->assertOk();

        $this->assertSame(1, Order::where('is_held', false)->count());
    }

    public function test_the_sell_screen_opens_by_default(): void
    {
        $this->get(route('pos.index'))->assertOk();
    }

    /* --------------------------- الفتح --------------------------- */

    public function test_no_selling_before_a_shift_is_open(): void
    {
        $this->requireShift();

        $this->sell()->assertStatus(409)->assertJson(['shift_required' => true]);

        $this->assertSame(0, Order::count(), 'مرّت بيعة خارج كل درج');
    }

    /** والشاشة نفسها تقود إلى الفتح بدل أن تدع الكاشير يجمع سلّة تُرفض */
    public function test_the_sell_screen_redirects_to_the_shift(): void
    {
        $this->requireShift();

        $this->get(route('pos.index'))->assertRedirect(route('pos.shift'));

        Shifts::open(20);

        $this->get(route('pos.index'))->assertOk();
    }

    public function test_opening_records_the_float_and_the_branch(): void
    {
        $this->post(route('pos.shift.open'), ['opening_balance' => '20.500'])->assertRedirect();

        $shift = Shift::firstOrFail();
        $this->assertSame('20.500', $shift->opening_balance);
        $this->assertSame($this->branch->id, $shift->branch_id);
        $this->assertTrue($shift->isOpen());
    }

    /**
     * درجٌ واحد، ووردية واحدة.
     *
     * جهازان يفتحان في اللحظة نفسها كانا سيُنشئان ورديتين للدرج الواحد،
     * فتنقسم مبيعات اليوم بينهما ولا يطابق أيٌّ منهما ما في الدرج.
     */
    public function test_a_second_open_does_not_create_a_second_shift(): void
    {
        $first = Shifts::open(20);
        $second = Shifts::open(50);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Shift::count());
        $this->assertSame('20.000', $first->fresh()->opening_balance, 'دُهس الرصيد الابتدائي');
    }

    /* ------------------------ النسبة والحساب ------------------------ */

    public function test_a_sale_is_attributed_to_the_open_shift(): void
    {
        $shift = Shifts::open(0);

        $this->sell()->assertOk();

        $this->assertSame($shift->id, Order::firstOrFail()->shift_id);
    }

    /**
     * البطاقة لا تضع في الدرج ريالًا.
     *
     * هذا هو جوهر الشاشة: من يعتمد على إجمالي المبيعات يجد درجه ناقصًا كل
     * يوم بمقدار ما بيع بالبطاقة، ويظنّ أن كاشيره يسرق.
     */
    public function test_only_cash_counts_towards_the_drawer(): void
    {
        $shift = Shifts::open(20);

        $this->sell('نقدي');      // 10
        $this->sell('بطاقة');     // 10 — لا تدخل الدرج
        $this->sell('نقدي');      // 10

        $totals = Shifts::totals($shift);
        $this->assertSame(30.0, $totals['sales'], 'إجمالي المبيعات');
        $this->assertSame(20.0, $totals['cash'], 'النقد وحده');
        $this->assertSame(40.0, Shifts::expectedCash($shift), 'الابتدائي + النقد');
    }

    /* ---------------------- سحب وإيداع نقدي ---------------------- */

    /**
     * الدرج فيه أكثر من مبيعات نقدية.
     *
     * يُخرَج منه نقدٌ لدفع مورّد، ويأخذ صاحب المحل مبلغًا. وحسابٌ يجهل ذلك
     * يقول «نقص ٥٠» أوّل مرّة يُسحب فيها خمسون لسائق — فتصرخ الشاشة كل يوم
     * بلا سبب، ويتوقّف الجميع عن تصديقها، فيمرّ النقص الحقيقي وسط الضجيج.
     */
    public function test_cash_taken_out_lowers_what_the_drawer_should_hold(): void
    {
        $shift = Shifts::open(100);
        $this->sell('نقدي'); // +10

        $this->post(route('pos.shift.move'), [
            'type' => 'out', 'amount' => '50', 'reason' => 'دفعة لمورّد',
        ])->assertRedirect();

        $this->assertSame(60.0, Shifts::expectedCash($shift->fresh()));
    }

    public function test_cash_put_in_raises_it(): void
    {
        $shift = Shifts::open(100);

        $this->post(route('pos.shift.move'), ['type' => 'in', 'amount' => '25', 'reason' => 'فكّة']);

        $this->assertSame(125.0, Shifts::expectedCash($shift->fresh()));
    }

    /** والسبب إلزامي: مبلغٌ بلا سبب لا يُراجَع ولا يُسأل عنه أحد */
    public function test_a_movement_without_a_reason_is_refused(): void
    {
        Shifts::open(100);

        $this->post(route('pos.shift.move'), ['type' => 'out', 'amount' => '50'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, \App\Models\ShiftMovement::count());
    }

    public function test_a_movement_needs_an_open_shift(): void
    {
        $this->post(route('pos.shift.move'), ['type' => 'out', 'amount' => '50', 'reason' => 'x'])
            ->assertSessionHasErrors('amount');
    }

    /**
     * ولا يُسحب أكثر ممّا في الدرج.
     *
     * المتوقّع السالب لا معنى له، وقبولُه يفتح بابًا لتغطية نقصٍ بسحبٍ وهمي.
     */
    public function test_you_cannot_take_out_more_than_the_drawer_holds(): void
    {
        $shift = Shifts::open(20);

        $this->post(route('pos.shift.move'), ['type' => 'out', 'amount' => '50', 'reason' => 'مبالغ فيه'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, \App\Models\ShiftMovement::count());
        $this->assertSame(20.0, Shifts::expectedCash($shift->fresh()));
    }

    /** والإيداع لا حدّ له — إضافةُ نقدٍ إلى الدرج ممكنة دائمًا */
    public function test_putting_cash_in_has_no_ceiling(): void
    {
        $shift = Shifts::open(0);

        $this->post(route('pos.shift.move'), ['type' => 'in', 'amount' => '9999', 'reason' => 'إيداع'])
            ->assertSessionHasNoErrors();

        $this->assertSame(9999.0, Shifts::expectedCash($shift->fresh()));
    }

    /** والحركات تدخل حساب الإقفال، فلا يظهر فرقٌ لا وجود له */
    public function test_a_withdrawal_does_not_look_like_a_shortage_at_close(): void
    {
        $shift = Shifts::open(100);
        $this->sell('نقدي'); // +10
        $this->post(route('pos.shift.move'), ['type' => 'out', 'amount' => '50', 'reason' => 'مورّد']);

        // في الدرج فعلًا 60 — وهو الصحيح
        $this->post(route('pos.shift.close'), ['counted' => '60'])->assertRedirect();

        $closed = $shift->fresh();
        $this->assertSame('0.000', $closed->difference, 'ظهر نقصٌ وهو سحبٌ مسجَّل');
        $this->assertSame('50.000', $closed->expenses, 'لم يُحفظ مجموع السحب');
    }

    /* -------------------------- الإقفال -------------------------- */

    public function test_closing_records_the_difference(): void
    {
        $shift = Shifts::open(20);
        $this->sell('نقدي'); // المتوقّع 30

        $this->post(route('pos.shift.close'), ['counted' => '28.500', 'note' => 'نقص'])->assertRedirect();

        $closed = $shift->fresh();
        $this->assertSame('30.000', $closed->expected_balance);
        $this->assertSame('28.500', $closed->actual_balance);
        $this->assertSame('-1.500', $closed->difference);
        $this->assertSame('نقص', $closed->note);
        $this->assertFalse($closed->isOpen());
    }

    public function test_a_matching_drawer_shows_no_difference(): void
    {
        Shifts::open(20);
        $this->sell('نقدي');

        $this->post(route('pos.shift.close'), ['counted' => '30']);

        $this->assertSame('0.000', Shift::firstOrFail()->difference);
    }

    /**
     * الفرق يُجمَّد لحظة الإقفال.
     *
     * لو حُسب عند العرض، لغيّرت بيعةٌ تُعدَّل بعد شهر «الفرق» بأثر رجعي —
     * فيصير سجلّ الوردية يكذب على قارئه بعد أن أُغلق الباب عليه.
     */
    public function test_the_closed_figures_do_not_move_afterwards(): void
    {
        $shift = Shifts::open(0);
        $this->sell('نقدي');
        $this->post(route('pos.shift.close'), ['counted' => '10']);

        Order::firstOrFail()->update(['total' => 999]);

        $closed = $shift->fresh();
        $this->assertSame('10.000', $closed->expected_balance);
        $this->assertSame('0.000', $closed->difference);
        $this->assertSame(10.0, Shifts::expectedCash($closed));
    }

    public function test_selling_stops_again_once_closed(): void
    {
        $this->requireShift();
        Shifts::open(0);
        $this->post(route('pos.shift.close'), ['counted' => '0']);

        $this->sell()->assertStatus(409);
    }

    /* ------------------------ العزل والسريّة ------------------------ */

    public function test_a_neighbours_shift_is_not_mine(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Shift::create([
            'business_id' => $other->id, 'branch_id' => $this->branch->id,
            'opened_at' => now(), 'opening_balance' => 500, 'status' => Shift::OPEN,
        ]);

        $this->assertNull(Shifts::current(), 'قُرئت وردية متجر آخر');
    }

    /**
     * العدّ أعمى: الكاشير يُدخل ما عدّه ولا تصله الأرقام.
     *
     * من يرى المتوقّع قبل العدّ يميل — بلا قصدٍ غالبًا — إلى كتابته بدل ما
     * عدّه، فيختفي النقص ولا يُكتشف أبدًا.
     */
    public function test_the_cashier_is_not_told_the_expected_amount(): void
    {
        Shifts::open(20);
        $this->sell('نقدي');

        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $this->actingAs($cashier);
        session(['current_branch' => $this->branch->id]);

        $this->get(route('pos.shift'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.expected', null)
                ->where('shift.opening_balance', null)
                ->where('shift.byMethod', null)
                ->where('showsAmounts', false));
    }

    /** ويراها صاحب النشاط كاملة */
    public function test_the_owner_sees_the_figures(): void
    {
        Shifts::open(20);
        $this->sell('نقدي');

        $this->get(route('pos.shift'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shift.expected', 30)->where('showsAmounts', true));
    }

    /* -------------------------- المراجعة -------------------------- */



    /**
     * شاشة المقبوضات تسأل عن وردية بعينها.
     *
     * كانت تعرض آخر ٣٠ فاتورة للفرع بلا حدٍّ زمني — وهي شاشة تقفيل صندوق،
     * فرقمها لا يطابق الدرج أبدًا.
     */
    public function test_the_payments_screen_is_scoped_to_the_open_shift(): void
    {
        // بيعة وردية أمس
        $old = Shifts::open(0);
        $this->sell('نقدي');
        $this->post(route('pos.shift.close'), ['counted' => '10']);

        // وردية اليوم
        Shifts::open(0);
        $this->sell('نقدي');
        $this->sell('نقدي');

        $this->get(route('pos.payments'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('receipts', 2));

        $this->assertSame(1, Order::where('shift_id', $old->id)->count());
    }
}
