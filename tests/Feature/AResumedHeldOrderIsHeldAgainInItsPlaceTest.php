<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ‏طلبٌ معلّقٌ يُستأنف ثمّ يُعلَّق من جديد — يبقى طلبًا واحدًا برقمه.
 *
 * ═══ ما كان ═══
 *
 * «تعليق» سلّةٍ مستأنفة يكتب طلبًا معلّقًا جديدًا (`PosController::hold`)،
 * ويبقى الأصليّ كما هو. فطلبُ الزبون الواحد يصير طلبين في «الطلبات المعلّقة»،
 * يُستكمل كلٌّ منهما فاتورة: بضاعةٌ تُخصم مرّتين، وزبونٌ يُطالَب مرّتين.
 *
 * ═══ ما صار ═══
 *
 * التعليقُ يحمل معرّفَ المستأنف (`resume_id`)، فتُكتب الأصنافُ على الطلب نفسِه
 * برقمه ومعرّفه. وإن لم يبقَ معلّقًا (دُفع أو حُذف) يُردّ ولا يُكتب شيء.
 * ولا يُقرأ إلّا طلبُ متجر الجلسة وفرعِها.
 */
class AResumedHeldOrderIsHeldAgainInItsPlaceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private Product $rose;

    private Product $lily;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->rose = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);
        $this->lily = Product::create([
            'business_id' => $this->business->id, 'name' => 'زنبق', 'price' => 7.5, 'cost' => 3,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);

        $this->actingAs($owner);
        session(['current_branch' => $this->branch->id]);
    }

    private function hold(array $items, array $extra = [])
    {
        return $this->postJson(route('pos.hold'), array_merge(['items' => $items, 'kind' => 'hold'], $extra));
    }

    private function line(Product $p, int $qty): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'qty' => $qty];
    }

    private function heldNow()
    {
        return Order::where('business_id', $this->business->id)->where('is_held', true)->get();
    }

    /** ‏يُعلَّق، ويُستأنف، ويُعلَّق ثانيةً بتعديل — والطلبُ واحدٌ برقمه ومعرّفه، وأصنافُه الجديدة وحدها */
    public function test_holding_a_resumed_order_again_writes_it_in_its_place(): void
    {
        $first = $this->hold([$this->line($this->rose, 1)])->assertOk();
        $held = $this->heldNow()->sole();
        $this->assertSame($held->number, $first->json('number'));

        // ‏الاستئنافُ لا يمحوه — يبقى في القائمة حتى يُدفع
        $this->get(route('pos.orders.resume', $held->id))->assertRedirect(route('pos.index'))
            ->assertSessionHas('resume_cart', fn ($cart) => $cart['id'] === $held->id && $cart['number'] === $held->number);
        $this->assertSame([$held->id], $this->heldNow()->pluck('id')->all());

        $again = $this->hold([$this->line($this->rose, 3), $this->line($this->lily, 2)], ['resume_id' => $held->id])->assertOk();

        $this->assertSame($held->number, $again->json('number'), 'تغيّر رقمُ الطلب');
        $this->assertSame($held->id, $again->json('id'), 'تغيّر معرّفُ الطلب');
        $this->assertSame([$held->id], $this->heldNow()->pluck('id')->all(), 'صار الطلبُ الواحد طلبين معلّقين');

        $fresh = $held->fresh('items');
        $this->assertSame(
            [[$this->rose->id, 3], [$this->lily->id, 2]],
            $fresh->items->sortBy('id')->map(fn ($i) => [$i->product_id, (int) $i->quantity])->values()->all(),
            'بقيت أصنافُه القديمة أو لم تُكتب الجديدة',
        );
        $this->assertEquals(45.0, (float) $fresh->total);
        $this->assertSame($held->ordered_at->toDateTimeString(), $fresh->ordered_at->toDateTimeString(), 'تغيّر وقتُ الطلب الأصليّ');

        // ‏ولا بيعةَ ولا مخزون: التعليقُ ليس بيعًا
        $this->assertSame(0, Order::where('is_held', false)->count());
        $this->assertSame(100, (int) $this->rose->fresh()->quantity);
    }

    /** ‏«حفظ» على المستأنف كـ«تعليق»: الطلبُ نفسُه بحالته الجديدة، والرقمُ لا يتغيّر */
    public function test_saving_a_resumed_order_keeps_its_number(): void
    {
        $this->hold([$this->line($this->rose, 1)])->assertOk();
        $held = $this->heldNow()->sole();

        $this->hold([$this->line($this->rose, 2)], ['resume_id' => $held->id, 'kind' => 'save'])->assertOk();

        $fresh = $this->heldNow()->sole();
        $this->assertSame($held->id, $fresh->id);
        $this->assertSame($held->number, $fresh->number);
        $this->assertSame('محفوظ', $fresh->status);
    }

    /** ‏ثمّ يُدفع: فاتورةٌ واحدة، والمعلّقُ يذهب — ولا يبقى منه شيء يُدفع ثانيةً */
    public function test_a_resumed_order_held_again_then_paid_is_one_sale(): void
    {
        $this->hold([$this->line($this->rose, 1)])->assertOk();
        $held = $this->heldNow()->sole();
        $this->hold([$this->line($this->rose, 2)], ['resume_id' => $held->id])->assertOk();

        $this->postJson(route('pos.checkout'), [
            'items' => [$this->line($this->rose, 2)], 'payment_method' => 'نقدي',
            'resume_id' => $held->id, 'client_uuid' => 'held-once',
        ])->assertOk();

        $this->assertCount(0, $this->heldNow(), 'بقي المعلّقُ بعد دفعه');
        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(98, (int) $this->rose->fresh()->quantity);
    }

    /** ‏دُفع من جهازٍ آخر، ثمّ عُلّق هنا: يُردّ ولا يُكتب طلبٌ جديدٌ بأصناف طلبٍ دُفع */
    public function test_holding_again_after_it_was_paid_elsewhere_is_refused(): void
    {
        $this->hold([$this->line($this->rose, 1)])->assertOk();
        $held = $this->heldNow()->sole();
        $this->postJson(route('pos.checkout'), [
            'items' => [$this->line($this->rose, 1)], 'payment_method' => 'نقدي', 'resume_id' => $held->id,
        ])->assertOk();

        $this->hold([$this->line($this->rose, 1)], ['resume_id' => $held->id])
            ->assertStatus(422)->assertJsonValidationErrors('resume_id');

        $this->assertCount(0, $this->heldNow(), 'كُتب طلبٌ معلّقٌ جديدٌ لطلبٍ دُفع');
        $this->assertSame(1, Order::where('is_held', false)->count());
    }

    /** ‏وحُذف من جهازٍ آخر: يُردّ كذلك — لا يعود الطلبُ المحذوف بلا علم من حذفه */
    public function test_holding_again_after_it_was_discarded_is_refused(): void
    {
        $this->hold([$this->line($this->rose, 1)])->assertOk();
        $held = $this->heldNow()->sole();
        $this->delete(route('pos.orders.discard', $held->id));

        $this->hold([$this->line($this->rose, 1)], ['resume_id' => $held->id])->assertStatus(422);

        $this->assertCount(0, $this->heldNow());
    }

    /** ‏ومعرّفُ طلبِ متجرٍ آخر لا يُكتب عليه — ولا يُكتب بسببه طلبٌ هنا */
    public function test_a_neighbours_held_order_is_not_written_over(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $neighbour = Order::create([
            'business_id' => $theirs->id, 'number' => 'HOLD-JAR', 'customer_name' => 'زبونهم',
            'status' => 'معلّق', 'is_held' => true, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->hold([$this->line($this->rose, 4)], ['resume_id' => $neighbour->id])->assertStatus(422);

        $this->assertEquals(5.0, (float) $neighbour->fresh()->total, 'كُتب على طلب الجار');
        $this->assertSame(0, $neighbour->items()->count());
        $this->assertCount(0, $this->heldNow());
    }

    /** ‏ولا طلبُ فرعٍ آخر — القائمةُ تُخفيه، والكتابةُ لا تصله */
    public function test_another_branchs_held_order_is_not_written_over(): void
    {
        $salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        session(['current_branch' => $salalah->id]);
        $this->hold([$this->line($this->rose, 1)])->assertOk();
        $there = $this->heldNow()->sole();

        session(['current_branch' => $this->branch->id]);
        $this->hold([$this->line($this->rose, 4)], ['resume_id' => $there->id])->assertStatus(422);

        $this->assertSame([$there->id], $this->heldNow()->pluck('id')->all());
        $this->assertSame(1, (int) $there->fresh('items')->items->sole()->quantity);
    }

    /** ‏ولا يُمسّ ما ليس معلّقًا: فاتورةٌ مدفوعة لا تصير معلّقةً بمعرّفها */
    public function test_a_paid_invoice_is_not_turned_back_into_a_held_order(): void
    {
        $paid = $this->postJson(route('pos.checkout'), [
            'items' => [$this->line($this->rose, 1)], 'payment_method' => 'نقدي',
        ])->assertOk();
        $invoice = Order::where('number', $paid->json('invoice'))->sole();

        $this->hold([$this->line($this->rose, 5)], ['resume_id' => $invoice->id])->assertStatus(422);

        $this->assertFalse((bool) $invoice->fresh()->is_held);
        $this->assertSame(1, (int) $invoice->fresh('items')->items->sole()->quantity);
        $this->assertCount(0, $this->heldNow());
    }

    /**
     * ‏كتبه الخادمُ ولم يصل الردّ، فأُعيد الإرسال: الفاتورةُ الأولى — لا رفضُ «أُتمّت».
     *
     * المعلّقُ ذهب مع البيعة الأولى. فلو سُئل عنه قبل المعرّف لَردّ الخادمُ
     * البيعةَ نفسَها «أُتمّت من جهازٍ آخر»، والكاشيرُ يظنّها لم تُكتب فيبيعها ثانية.
     */
    public function test_a_resumed_sale_sent_again_after_a_lost_reply_is_the_first_invoice(): void
    {
        $this->hold([$this->line($this->rose, 1)])->assertOk();
        $held = $this->heldNow()->sole();
        $sale = ['items' => [$this->line($this->rose, 1)], 'payment_method' => 'نقدي', 'resume_id' => $held->id, 'client_uuid' => 'lost-reply'];

        $first = $this->postJson(route('pos.checkout'), $sale)->assertOk();
        $again = $this->postJson(route('pos.checkout'), $sale)->assertOk();

        $this->assertSame($first->json('invoice'), $again->json('invoice'));
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(99, (int) $this->rose->fresh()->quantity);
    }
}
