<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «حذف» البيعة لصاحب النشاط وحده — وهو إلغاؤها ماليًّا لا محوُ صفّها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الزرُّ (`mayDelete`) لـ`role === 'admin'` وحده: لا المدير ولا غيره.
 *   - والمسارُ يردّ كلَّ من سواه بـ403 — ولو كان قسمُ «المبيعات» مفتوحًا له.
 *   - والحذفُ `OrderCorrection::cancel`: الصفُّ باقٍ بحالة «ملغي»، والمخزونُ
 *     يعود، ومعاملةُ الصندوق تزول، وقيودُ الدفتر تُعكس ولا تُمحى.
 *   - وبيعةُ متجرٍ آخر لا تُبلغ، وفاتورةٌ في إقرارٍ قُدِّم تُردّ ولا تتغيّر.
 */
class TheOwnerDeletesASaleByCancellingItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);

        Ledger::seedChart($this->business->id);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** بيعةٌ حقيقيّة من نقطة البيع: مخزونٌ وصندوقٌ وقيود */
    private function sell(): Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 3]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->business->id)->where('is_held', false)->latest('id')->firstOrFail();
    }

    /** موظّفٌ بقسم «المبيعات» مفتوحًا — فيصل المسارَ ولا يمنعه إلّا الخادم */
    private function staff(string $role): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف '.$role, 'email' => $role.'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => array_values(array_unique([...Permissions::roleGrants($role), 'orders'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function listProps(User $user): array
    {
        return $this->actingAs($user)->get(route('admin.orders.index'))->assertOk()->viewData('page')['props'];
    }

    private function entries(Order $order)
    {
        return JournalEntry::where('sourceable_type', Order::class)->where('sourceable_id', $order->id)->get();
    }

    /* ═══════════════ ١ · لمن الزرّ ═══════════════ */

    public function test_the_owner_sees_the_delete_button(): void
    {
        $this->sell();

        $this->assertTrue($this->listProps($this->owner)['mayDelete']);
    }

    public function test_a_manager_does_not_see_it(): void
    {
        $this->sell();

        $this->assertFalse($this->listProps($this->staff('manager'))['mayDelete'], 'isAdmin() تشمل المدير — والزرّ لصاحب النشاط وحده');
    }

    public function test_every_role_but_the_owner_is_refused_with_403(): void
    {
        $order = $this->sell();
        $stock = (int) $this->product->fresh()->quantity;

        foreach (Roles::STAFF as $role) {
            $this->actingAs($this->staff($role))
                ->delete(route('admin.orders.destroy', $order->number))
                ->assertForbidden();
        }

        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status, 'لم يُلغِها أحد');
        $this->assertSame($stock, (int) $this->product->fresh()->quantity);
    }

    /* ═══════════════ ٢ · الحذفُ إلغاءٌ لا محو ═══════════════ */

    public function test_the_owner_deletes_a_sale_by_cancelling_it(): void
    {
        $order = $this->sell();
        $this->assertSame(97, (int) $this->product->fresh()->quantity);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertCount(2, $this->entries($order));

        $this->actingAs($this->owner)
            ->from(route('admin.orders.index'))
            ->delete(route('admin.orders.destroy', $order->number))
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHas('toast.type', 'success');

        $this->assertNotNull(Order::find($order->id), 'الصفُّ محي — والحذفُ إلغاءٌ لا delete()');
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'المخزونُ لم يعد');
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count(), 'معاملةُ الصندوق باقية لبيعةٍ لم تقع');

        $entries = $this->entries($order);
        $this->assertCount(4, $entries, 'قيدٌ مُحي بدل أن يُعكس');
        $this->assertSame(2, $entries->whereNotNull('reversed_at')->count());
        $this->assertSame(2, $entries->whereNotNull('reverses_id')->count());
    }

    public function test_a_cancelled_sale_offers_no_delete(): void
    {
        $order = $this->sell();
        $this->delete(route('admin.orders.destroy', $order->number));

        $row = collect($this->listProps($this->owner)['orders'])->firstWhere('id', $order->number);

        $this->assertSame(OrderStatus::CANCELLED, $row['status'], 'الشاشةُ تُخفي الزرَّ بهذه الحال');

        // وطلبٌ ثانٍ لا يعكس شيئًا مرّتين
        $this->delete(route('admin.orders.destroy', $order->number));
        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertCount(4, $this->entries($order));
    }

    /* ═══════════════ ٣ · ولا يُبلغ ما ليس له ═══════════════ */

    public function test_another_shops_sale_cannot_be_deleted(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $theirs = Order::create([
            'business_id' => $other->id, 'number' => 'INV-OTHER-1', 'customer_name' => 'زبون',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => OrderStatus::COMPLETED,
            'subtotal' => 50, 'discount' => 0, 'tax' => 0, 'total' => 50, 'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->delete(route('admin.orders.destroy', $theirs->number))
            ->assertNotFound();

        $this->assertSame(OrderStatus::COMPLETED, $theirs->fresh()->status);
    }

    /* ═══════════════ ٤ · والإقرارُ المقدَّم لا يُعاد كتابتُه ═══════════════ */

    public function test_a_sale_inside_a_filed_vat_return_is_refused_and_untouched(): void
    {
        $order = $this->sell();
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_filed_through'],
            ['value' => now()->toDateString()],
        );

        $this->actingAs($this->owner)
            ->from(route('admin.orders.index'))
            ->delete(route('admin.orders.destroy', $order->number))
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHas('toast.type', 'danger')
            ->assertSessionHasErrors('order');

        $this->assertStringContainsString('إقرارٍ ضريبيّ', (string) session('toast.msg'), 'رسالةُ الحارس نفسُها تصل');
        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
        $this->assertSame(97, (int) $this->product->fresh()->quantity);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertCount(2, $this->entries($order), 'ولا قيدَ عكسٍ كُتب');
    }
}
