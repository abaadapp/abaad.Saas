<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\JobTitle;
use App\Models\NotificationState;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo;
use App\Support\GoodsReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التنبيهُ يتبع القسمَ الذي يشير إليه — لا نسخةً منه.
 *
 * ═══ ما يُقاس هنا ═══
 *
 * حالاتُ الإنجاز بُنيت على أنّ **غيابَ المفتاح دليلُ الحلّ**. وهذا صحيحٌ ما
 * دام المصدرُ يكفّ عن إنتاجه ساعةَ يُعالَج السجلُّ في قسمه. فالحارسُ هنا
 * يعتمد ورقةَ استلامٍ **بمسارها الحقيقيّ** ويبدّل حالةَ طلبٍ، ثمّ يسأل
 * الجرس — فلو نُسخت يومًا قاعدةٌ من قسمٍ إلى نظام الإشعارات لَافترقت هنا.
 *
 * ولا يُعدَّل في هذا الملفّ قسمٌ آخر: يُقرأ ويُنادى ما هو قائم.
 */
class TheBellFollowsTheSectionsItPointsAtTest extends TestCase
{
    use RefreshDatabase;

    private Business $biz;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->biz = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'email' => 'shop@abaad.om',
        ]);
        Branch::create(['business_id' => $this->biz->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->biz->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_column(Demo::notificationFeed()['items'], 'key');
    }

    /* ─────────── المشتريات ─────────── */

    public function test_a_receipt_notice_ends_when_the_receipt_is_approved(): void
    {
        $supplier = Supplier::create(['business_id' => $this->biz->id, 'name' => 'مورّد']);
        $note = GoodsReceiptNote::create([
            'business_id' => $this->biz->id, 'supplier_id' => $supplier->id,
            'number' => 'GRN-1', 'status' => GoodsReceipts::PENDING, 'received_at' => now(),
        ]);

        $key = 'grn-'.$note->id;
        $this->assertContains($key, $this->keys(), 'ورقةٌ معلَّقةٌ ولا تنبيهَ لها');

        $this->postJson(route('admin.notifications.open'), ['key' => $key])->assertOk();

        /* و«تم» مرفوضةٌ ما دامت معلَّقة — ولا تُعتمد الورقةُ نيابةً عن أحد */
        $this->postJson(route('admin.notifications.done'), ['key' => $key])->assertStatus(409);
        $this->assertSame(
            GoodsReceipts::PENDING,
            $note->fresh()->status,
            'ضغطةُ «تم» اعتمدت ورقةً — والزرُّ لا يفعل شيئًا في قسمٍ آخر',
        );

        /* والاعتمادُ يقع في قسمه، بمنطقه هو */
        GoodsReceipts::approve($note->fresh(), $this->owner);

        $this->assertNotContains($key, $this->keys(), 'اعتُمدت الورقةُ وبقي الجرسُ يقول «تنتظر»');
        $this->assertNotNull(
            NotificationState::where('notif_key', $key)->sole()->resolved_at,
            'زال سببُه وبقيت دورتُه مفتوحة',
        );
    }

    /* ─────────── المبيعات ─────────── */

    public function test_an_order_notice_ends_when_the_order_leaves_the_queue(): void
    {
        $order = Order::create([
            'business_id' => $this->biz->id, 'number' => 'S-1', 'status' => 'جديد',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $key = 'order-'.$order->number;
        $this->assertContains($key, $this->keys());

        $this->postJson(route('admin.notifications.open'), ['key' => $key])->assertOk();
        $this->postJson(route('admin.notifications.done'), ['key' => $key])->assertStatus(409);

        /* جُهِّز الطلبُ في قسمه */
        $order->forceFill(['status' => 'مكتمل'])->save();

        /*
         * والضغطةُ تسبق الاستطلاع: التاجرُ يجهّز الطلبَ ثمّ يضغط «تم» قبل
         * أن ينبض الجرسُ. فالمفتاحُ غائبٌ ودورتُه ما زالت مفتوحة، فيُغلق
         * باسمه ووقته. ولو سبقه الاستطلاعُ لَأُغلق وحدَه — وهو محروسٌ في
         * `ANoticeIsNotDoneUntilTheProblemIsTest`.
         */
        $this->postJson(route('admin.notifications.done'), ['key' => $key])
            ->assertOk()->assertJsonPath('outcome', 'done');

        $this->assertNotContains($key, $this->keys(), 'خرج الطلبُ من الطابور وبقي تنبيهُه');
    }

    /* ─────────── المخزون: الفرعُ والإجماليّ ─────────── */

    public function test_the_shelf_notice_reads_the_business_total_as_it_always_did(): void
    {
        /*
         * ═══ قيدٌ موثَّقٌ لا عطبٌ أُصلح ═══
         *
         * `Product::scopeNeedsStockAlert` تقيس `products.quantity` — مجموعَ
         * النشاط لا رصيدَ الفرع. فمتجرٌ له فرعان، في أحدهما صفرٌ وفي الآخر
         * خمسون، لا يُنبَّه: الإجماليُّ فوق الحدّ.
         *
         * وهذا سلوكُ قسم المخزون لا سلوكُ الجرس، وتبديلُه تعديلٌ في قسمٍ
         * آخر. فيُقاس هنا كما هو ليبقى الفرقُ مكتوبًا ومعلومًا — ومن غيّره
         * يومًا رأى هذا الحارسَ يسقط فعرف أنّه غيّر ما يقرؤه الجرس.
         */
        $second = Branch::create(['business_id' => $this->biz->id, 'name' => 'صلالة']);

        $p = Product::create([
            'business_id' => $this->biz->id, 'name' => 'صنف موزّع',
            'price' => 5, 'cost' => 2, 'quantity' => 50, 'alert_qty' => 10,
        ]);

        BranchStock::create([
            'business_id' => $this->biz->id, 'branch_id' => $second->id,
            'product_id' => $p->id, 'quantity' => 0,
        ]);

        $this->assertNotContains(
            'low-'.$p->id,
            $this->keys(),
            'الجرسُ صار يقرأ رصيدَ الفرع — وهذا تغييرٌ في قسم المخزون',
        );
    }

    /* ─────────── الموظّفون ─────────── */

    public function test_a_clerks_bell_and_his_log_stay_inside_what_he_may_open(): void
    {
        $clerk = User::create([
            'business_id' => $this->biz->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['orders'],
        ]);

        $p = Product::create([
            'business_id' => $this->biz->id, 'name' => 'صنف نادر',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 10,
        ]);
        Order::create([
            'business_id' => $this->biz->id, 'number' => 'S-2', 'status' => 'جديد',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->actingAs($clerk);

        $keys = $this->keys();
        $this->assertContains('order-S-2', $keys, 'لا يرى ما يملك فتحَه');
        $this->assertNotContains('low-'.$p->id, $keys, 'يرى ما لا يفتحه');

        /* وسجلُّه سجلُّه — لا صفَّ فيه عن نشاطٍ أو قسمٍ ليس له */
        $this->getJson(route('admin.notifications.history'))
            ->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_a_neighbours_log_is_not_readable_from_here(): void
    {
        $other = Business::create([
            'name' => 'محلُّ الجار', 'type' => 'عام', 'status' => 'نشط', 'email' => 'jar@abaad.om',
        ]);
        $theirUser = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'j@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        NotificationState::create([
            'business_id' => $other->id, 'user_id' => $theirUser->id,
            'notif_key' => 'low-999', 'cycle' => 1, 'label' => 'سرُّ الجار',
            'done_at' => now(), 'resolved_at' => now(),
        ]);

        $this->getJson(route('admin.notifications.history'))
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertDontSee('سرُّ الجار');
    }
}
