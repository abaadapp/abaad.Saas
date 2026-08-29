<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يعبر طلبٌ حدود متجره ولا فرعه.
 *
 * الرقم يصل من شريط العنوان، وترقيمُ الفواتير يبدأ من واحدٍ عند كلّ تاجر —
 * فرقمُ متجرٍ آخر ليس تخمينًا بعيدًا بل أوّل ما يُجرَّب. والحقول الجديدة
 * تُوسّع ما يُسرَّب لو انفتح الباب: اسم المستلِم ورقمُه وعنوان بيته.
 *
 * والفحص على الأبواب كلّها لا على العرض وحده: القراءة، والتعديل، ونقل
 * الحالة، ولوحة التجهيز، وأفعالها.
 */
class OrderIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $his;

    private User $me;

    private Order $hisOrder;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->mine, $this->me] = $this->shop('متجري', 'me@abaad.om');
        [$this->his, $hisOwner] = $this->shop('متجر الجار', 'him@abaad.om');

        $this->hisOrder = Order::create([
            'business_id' => $this->his->id,
            'branch_id' => Branch::where('business_id', $this->his->id)->value('id'),
            'number' => 'INV-0001', 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHour(),
            'recipient_name' => 'سارة سرّيّة', 'recipient_phone' => '99887766',
            'delivery_address' => 'بيت الجار، شارع ٣',
            'card_message' => 'رسالةٌ خاصّة',
        ]);

        $this->actingAs($this->me);
    }

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $email): array
    {
        $b = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        $u = User::create([
            'business_id' => $b->id, 'name' => 'مالك '.$name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$b, $u];
    }

    /* --------------------------- عزل المستأجرين --------------------------- */

    public function test_i_cannot_view_his_order(): void
    {
        $this->get(route('admin.orders.show', $this->hisOrder->number))->assertNotFound();
    }

    public function test_i_cannot_edit_his_order(): void
    {
        $this->put(route('admin.orders.details.update', $this->hisOrder->number), [
            'recipient_name' => 'اسمٌ دسسته',
        ])->assertNotFound();

        $this->assertSame('سارة سرّيّة', $this->hisOrder->fresh()->recipient_name);
    }

    public function test_i_cannot_move_his_order(): void
    {
        $this->post(route('admin.orders.status', $this->hisOrder->number), [
            'status' => OrderStatus::CANCELLED,
        ])->assertNotFound();

        $this->assertSame(OrderStatus::CONFIRMED, $this->hisOrder->fresh()->status);
    }

    /** ولا تصل بيانات مستلِمه إليّ من لوحة التجهيز */
    public function test_his_order_is_not_on_my_board(): void
    {
        $props = $this->get(route('admin.preparation.index'))->viewData('page')['props'];

        $this->assertSame([], $props['orders']);
        $this->assertSame(0, $props['counts']['all']);
    }

    public function test_i_cannot_act_on_his_order_from_the_board(): void
    {
        $this->post(route('admin.preparation.move', $this->hisOrder->number), [
            'status' => OrderStatus::READY,
        ])->assertNotFound();

        $this->assertSame(OrderStatus::CONFIRMED, $this->hisOrder->fresh()->status);
    }

    /**
     * ولا يُسرَّب اسم مستلِمه ولا رقمُه ولا عنوانه في أيّ ردّ.
     *
     * الفحص على نصّ الرد كلّه لا على حقلٍ بعينه: تسريبٌ في رسالة خطأ أو في
     * حقلٍ منسيّ تسريبٌ كامل.
     */
    public function test_nothing_of_his_recipient_leaks(): void
    {
        foreach ([
            fn () => $this->get(route('admin.orders.show', $this->hisOrder->number)),
            fn () => $this->get(route('admin.preparation.index')),
            fn () => $this->post(route('admin.orders.status', $this->hisOrder->number), ['status' => OrderStatus::READY]),
        ] as $call) {
            $body = $call()->getContent();

            foreach (['سارة سرّيّة', '99887766', 'بيت الجار', 'رسالةٌ خاصّة'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "تسرّب «{$secret}» إلى متجرٍ آخر");
            }
        }
    }

    /* ------------------------------ عزل الفروع ------------------------------ */

    /**
     * من يعمل على فرعٍ بعينه لا يُحرّك طلبات فرعٍ لا يراه.
     *
     * الفرع يُقرأ من الجلسة كما تقرؤه شاشة المبيعات — لا مما يصل في الطلب.
     */
    public function test_a_branch_scoped_user_sees_only_his_branch(): void
    {
        $second = Branch::create(['business_id' => $this->mine->id, 'name' => 'فرع القرم']);
        $mainId = Branch::where('business_id', $this->mine->id)->orderBy('id')->value('id');

        $here = $this->mineOrder($mainId, 'INV-M1');
        $there = $this->mineOrder($second->id, 'INV-M2');

        session(['current_branch' => $mainId]);

        $board = array_column(
            $this->get(route('admin.preparation.index'))->viewData('page')['props']['orders'],
            'number'
        );
        $this->assertSame([$here->number], $board);

        $this->get(route('admin.orders.show', $there->number))->assertSuccessful();
        // العرض يمرّ (شاشة الطلب لا تُقيَّد بالفرع)، والفعل لا يمرّ
        $this->post(route('admin.preparation.move', $there->number), ['status' => OrderStatus::READY])
            ->assertNotFound();
        $this->post(route('admin.orders.status', $there->number), ['status' => OrderStatus::READY])
            ->assertNotFound();
    }

    private function mineOrder(int $branchId, string $number): Order
    {
        return Order::create([
            'business_id' => $this->mine->id, 'branch_id' => $branchId,
            'number' => $number, 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
            'scheduled_for' => now()->addHour(),
        ]);
    }

    /* ------------------------------ الصلاحيات ------------------------------ */

    /** لوحة التجهيز قسمٌ يُمنح — ومن لم يُمنحه لا يفتحها بكتابة عنوانها */
    public function test_the_board_is_closed_to_whoever_was_not_granted_it(): void
    {
        $staff = User::create([
            'business_id' => $this->mine->id, 'name' => 'موظف', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
            'permissions' => ['dashboard', 'orders'],
        ]);

        $this->actingAs($staff)->get(route('admin.preparation.index'))->assertForbidden();
    }

    public function test_the_board_opens_for_whoever_was_granted_it(): void
    {
        $staff = User::create([
            'business_id' => $this->mine->id, 'name' => 'مجهّز', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
            'permissions' => ['preparation'],
        ]);

        $this->actingAs($staff)->get(route('admin.preparation.index'))->assertSuccessful();
    }

    /** وصلاحيةُ التجهيز وحدها لا تفتح شاشة المبيعات وإجماليّاتها */
    public function test_preparation_alone_does_not_open_the_sales_screen(): void
    {
        $staff = User::create([
            'business_id' => $this->mine->id, 'name' => 'مجهّز', 'email' => 'p2@abaad.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
            'permissions' => ['preparation'],
        ]);

        $this->actingAs($staff)->get(route('admin.orders.index'))->assertForbidden();
    }

    /** والضيف لا يصل إلى شيءٍ من هذا */
    public function test_a_guest_reaches_nothing(): void
    {
        auth()->logout();

        $this->get(route('admin.preparation.index'))->assertRedirect(route('login'));
        $this->post(route('admin.preparation.move', 'INV-0001'), ['status' => OrderStatus::READY])
            ->assertRedirect(route('login'));
    }
}
