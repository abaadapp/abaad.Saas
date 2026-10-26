<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\SalesChannel;
use App\Support\Store\StoreBranches;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * طلبُ الموقع يُنفَّذ من الفرع الذي يخدمه — لا من أوّل الفروع دائمًا.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) متجرٌ بفرعٍ واحد، ومتجرٌ بفروعٍ لم يضبط شيئًا: كما كان — أوّلُ الفروع.
 * ٢) فرعُ طلبات الموقع يختاره صاحبُ المتجر: منه يُنسب الطلبُ ويُخصم رفُّه،
 *    ولا يتبدّل فرعُ طلبٍ سابق.
 * ٣) فرعُ الاستلام يختاره الزبون حين يُفتح له: من فروع المتجر الحيّة وحدها
 *    (لا محذوفٌ ولا فرعُ متجرٍ آخر)، ويُحكم على رفّه هو.
 * ٤) دفعةُ البطاقة تُكتب من الفرع الذي فُحص رفُّه قبل البنك — وإن حُذف بعدها
 *    فمن فرع طلبات الموقع، لا مالٌ بلا طلب.
 * ٥) الإعدادُ لا يقبل فرعًا ليس من فروع المتجر الحيّة.
 */
class AWebsiteOrderLeavesFromTheBranchThatServesItTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private Branch $khuwair;

    private Branch $muscat;

    private Branch $sohar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00');
        app()->setLocale('ar');

        $this->openRibbon();
        $this->bankOpens();
        $this->khuwair = $this->shop->branches()->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** فرعان حيّان وثالثٌ محذوف — ورفُّ الورد موزَّعٌ عليها */
    private function branches(int $khuwair = 3, int $muscat = 5): void
    {
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط', 'address' => 'الغبرة']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $this->sohar->delete();

        $this->rose->update(['quantity' => $khuwair + $muscat]);
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->khuwair->id, 'product_id' => $this->rose->id, 'quantity' => $khuwair]);
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'product_id' => $this->rose->id, 'quantity' => $muscat]);
    }

    private function shelf(Branch $branch): int
    {
        return (int) BranchStock::where('branch_id', $branch->id)->where('product_id', $this->rose->id)->value('quantity');
    }

    private function placed(array $over = []): Order
    {
        $this->checkout($over)->assertOk()->assertJsonPath('ok', true);

        return Order::latest('id')->firstOrFail();
    }

    /* ═══════════ ١ — كما كان لمن لم يضبط شيئًا ═══════════ */

    public function test_a_single_branch_shop_works_as_before_even_with_the_choice_on(): void
    {
        $this->settings([StoreBranches::CHOICE => '1']);
        $this->assertSame([], StoreBranches::forPage($this->shop->id), 'عُرض اختيارٌ لمتجرٍ بفرعٍ واحد');

        $pickup = $this->placed();
        $delivery = $this->placed(['fulfil' => 'delivery', 'address' => 'الخوير ٣٣', 'phone' => '96899110002']);

        $this->assertSame([$this->khuwair->id, $this->khuwair->id], [(int) $pickup->branch_id, (int) $delivery->branch_id]);
        $this->assertSame('الخوير', $pickup->branch);
        $this->assertSame(SalesChannel::WEBSITE, $pickup->channel);
    }

    public function test_a_shop_with_branches_that_set_nothing_keeps_its_first_branch_and_no_choice(): void
    {
        $this->branches();
        $this->assertSame([], StoreBranches::forPage($this->shop->id));

        // ولو أُرسل فرعٌ بيده: الاختيارُ مطفأ، فلا يُقرأ
        $order = $this->placed([StoreBranches::FIELD => $this->muscat->id]);
        $this->assertSame($this->khuwair->id, (int) $order->branch_id);
        $this->assertSame([2, 5], [$this->shelf($this->khuwair), $this->shelf($this->muscat)]);

        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="rb-pickup-branch"', $html);
    }

    /* ═══════════ ٢ — فرعُ طلبات الموقع ═══════════ */

    public function test_the_online_orders_branch_takes_the_order_and_its_stock_and_old_orders_stay(): void
    {
        $this->branches();
        $before = $this->placed();
        $this->assertSame($this->khuwair->id, (int) $before->branch_id);

        $this->settings([StoreBranches::DEFAULT => (string) $this->muscat->id]);
        $order = $this->placed(['fulfil' => 'delivery', 'address' => 'الغبرة ٤', 'phone' => '96899110003']);

        $this->assertSame([$this->muscat->id, 'مسقط'], [(int) $order->branch_id, $order->branch]);
        $this->assertSame([2, 4], [$this->shelf($this->khuwair), $this->shelf($this->muscat)], 'خُصم الرفُّ من فرعٍ آخر');
        // والطلبُ السابقُ على فرعه
        $this->assertSame($this->khuwair->id, (int) $before->fresh()->branch_id);

        // وفرعٌ حُذف بعد ضبطه يعود إلى أوّل الفروع — لا طلبَ بلا فرع
        $this->muscat->delete();
        $this->assertSame($this->khuwair->id, StoreBranches::default($this->shop->id)?->id);
    }

    /* ═══════════ ٣ — فرعُ الاستلام يختاره الزبون ═══════════ */

    public function test_the_customer_picks_a_live_pickup_branch_of_this_shop_and_its_shelf_pays(): void
    {
        $this->branches();
        $this->settings([StoreBranches::CHOICE => '1']);

        $this->assertSame([$this->khuwair->id, $this->muscat->id], array_column(StoreBranches::forPage($this->shop->id), 'id'));
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-pickup-branch"', $html);
        $this->assertStringContainsString('مسقط — الغبرة', $html);
        $this->assertStringNotContainsString('صحار', $html, 'عُرض فرعٌ محذوف');

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $foreign = Branch::create(['business_id' => $other->id, 'name' => 'فرع الجار']);

        // بلا فرع، أو بفرعٍ محذوف، أو بفرع متجرٍ آخر: يُردّ ولا يُكتب شيء
        foreach ([null, $this->sohar->id, $foreign->id, 'x'] as $asked) {
            $this->checkout([StoreBranches::FIELD => $asked])->assertStatus(422)->assertJsonValidationErrors([StoreBranches::FIELD]);
        }
        $this->assertSame(0, Order::count());
        $this->assertSame([3, 5], [$this->shelf($this->khuwair), $this->shelf($this->muscat)]);

        $order = $this->placed([StoreBranches::FIELD => $this->muscat->id]);
        $this->assertSame([$this->muscat->id, 'مسقط'], [(int) $order->branch_id, $order->branch]);
        $this->assertSame([3, 4], [$this->shelf($this->khuwair), $this->shelf($this->muscat)]);

        // والتوصيلُ من فرع طلبات الموقع — لا يُقرأ له فرعُ استلام
        $delivered = $this->placed(['fulfil' => 'delivery', 'address' => 'الخوير ٣٣', 'phone' => '96899110004', StoreBranches::FIELD => $this->muscat->id]);
        $this->assertSame($this->khuwair->id, (int) $delivered->branch_id);
    }

    public function test_the_shelf_is_judged_at_the_chosen_branch_not_at_the_shop_total(): void
    {
        $this->branches(khuwair: 3, muscat: 0);
        $this->settings([StoreBranches::CHOICE => '1']);

        // في الشركة ثلاث، وفي مسقط لا شيء — فلا يُباع من مسقط
        $this->checkout([StoreBranches::FIELD => $this->muscat->id])->assertStatus(422);
        $this->assertSame(0, Order::count());

        $order = $this->placed([StoreBranches::FIELD => $this->khuwair->id]);
        $this->assertSame($this->khuwair->id, (int) $order->branch_id);
        $this->assertSame([2, 0], [$this->shelf($this->khuwair), $this->shelf($this->muscat)]);
    }

    /* ═══════════ ٤ — البطاقةُ تُكتب من الفرع الذي فُحص قبل البنك ═══════════ */

    public function test_a_card_payment_is_written_at_the_branch_checked_before_the_bank(): void
    {
        $this->branches();
        $this->settings([StoreBranches::CHOICE => '1']);

        $this->checkout(['pay' => 'card', StoreBranches::FIELD => $this->muscat->id])->assertOk()->assertJsonPath('ok', true);
        $intent = StorePaymentIntent::sole();
        $this->assertSame($this->muscat->id, (int) ($intent->payload[WebCheckout::BRANCH] ?? 0));

        $this->paid($intent);
        $order = Order::sole();
        $this->assertSame([$this->muscat->id, 'مدفوع', OrderStatus::PENDING], [(int) $order->branch_id, $order->payment_status, $order->status]);
        $this->assertSame(4, $this->shelf($this->muscat));
    }

    public function test_a_branch_deleted_while_the_customer_pays_still_gets_the_paid_order_written(): void
    {
        $this->branches();
        $this->settings([StoreBranches::CHOICE => '1']);

        $this->checkout(['pay' => 'card', StoreBranches::FIELD => $this->muscat->id])->assertOk();
        $intent = StorePaymentIntent::sole();
        $this->muscat->delete();

        $this->paid($intent);
        $order = Order::sole();
        $this->assertSame($this->khuwair->id, (int) $order->branch_id, 'المالُ قُبض ولم يُكتب طلبُه من فرعٍ حيّ');
        $this->assertFalse($intent->fresh()->strayPayment());
    }

    /* ═══════════ ٥ — الإعدادُ يقبل فروع المتجر الحيّة وحدها ═══════════ */

    public function test_the_setting_accepts_only_a_live_branch_of_this_shop(): void
    {
        $this->branches();
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $foreign = Branch::create(['business_id' => $other->id, 'name' => 'فرع الجار']);
        $this->actingAs($this->owner);

        foreach ([$foreign->id, $this->sohar->id] as $bad) {
            $this->post(route('admin.marketing.store.save'), [StoreBranches::DEFAULT => $bad])->assertSessionHasErrors(StoreBranches::DEFAULT);
        }
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')[StoreBranches::DEFAULT]);

        $this->post(route('admin.marketing.store.save'), [StoreBranches::DEFAULT => $this->muscat->id, StoreBranches::CHOICE => true])->assertSessionHasNoErrors();
        MarketingSettings::forget($this->shop->id);
        $saved = MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame([(string) $this->muscat->id, '1'], [$saved[StoreBranches::DEFAULT], $saved[StoreBranches::CHOICE]]);
    }
}
