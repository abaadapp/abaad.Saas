<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمةُ المبيعات تعرض ما تُرشِّح به — وصفحةُ الطلب تتصرّف حيث تُرسَم.
 *
 * ثلاثةُ أعطابٍ في قسمٍ واحد، جامعُها أنّ الشاشة تَعِد بما لا تُنجزه:
 *
 * ١ — **موعدُ التسليم**: يُرسَل إلى الشاشة، وله مفتاحُ فرز، وله أربعةُ
 *     مُرشِّحات — ولا عمودَ له. فمن رشّح «متأخّر» رأى صفوفًا لا يُميّزها عن
 *     غيرها شيء: لا متى كان يجب أن تخرج ولا كم تأخّرت. والفرزُ بالموعد
 *     مفتاحٌ ميت، ونوعُ التنفيذ حقلٌ يُرسَل ولا يُقرأ.
 *
 * ٢ — **اسمُ العميل**: القائمة وحدها كانت تقرأ الاسم العربيّ، وصفحةُ الطلب
 *     والورقةُ والتصدير تقرأ الاسمين. فبالإنجليزية يقرأ التاجر «عدي» في
 *     الصفّ و«Oday» حين يفتحه — عميلٌ واحد باسمين على شاشتين.
 *
 * ٣ — **طلبُ فرعٍ آخر**: صفحتُه تُفتح كاملةً بأزرارها وهو ليس في قائمة
 *     فرعك، فيضغط التاجر «جاهز» فتُردّ صفحةُ ٤٠٤ بلا كلمة. **وتصحيحُ
 *     الفاتورة كان يمرّ**: كميّةٌ تُكتب من جديد ورصيدُ فرعٍ آخر يتحرّك —
 *     أخطرُ الأبواب كان أوسعَها.
 */
class TheSalesListShowsWhatItFiltersByTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'بوكيه', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
    }

    private function order(Branch $b, array $over = []): Order
    {
        static $n = 0;
        $n++;

        return Order::create(array_merge([
            'business_id' => $this->shop->id, 'branch_id' => $b->id, 'branch' => $b->name,
            'number' => 'INV-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'customer_name' => 'عدي', 'customer_name_en' => 'Oday',
            'employee_name' => 'نورة', 'subtotal' => 10, 'tax' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'status' => OrderStatus::COMPLETED, 'is_held' => false, 'ordered_at' => now(),
        ], $over));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $query = []): array
    {
        return $this->get(route('admin.orders.index', $query))->viewData('page')['props']['orders'];
    }

    private function at(Branch $b): void
    {
        $this->get(route('admin.branch.switch', $b->id));
    }

    /* ══════════════ ١ · ما يُرشَّح به يُعرض ══════════════ */

    /**
     * الصفُّ يحمل موعدَه ووسمَ تأخّره واسمَ تنفيذه.
     *
     * ونوعُ التنفيذ باسمه لا برمزه: `delivery` ليست كلمةً تُقرأ في صفٍّ عربيّ.
     */
    public function test_a_row_carries_its_appointment(): void
    {
        $this->order($this->muscat, [
            'status' => OrderStatus::PREPARING,
            'scheduled_for' => now()->subHours(3),
            'fulfillment_type' => 'delivery',
        ]);

        $row = $this->rows()[0];

        $this->assertNotSame('—', $row['scheduled']);
        $this->assertTrue($row['late']);
        $this->assertSame('توصيل', $row['fulfillment']);
    }

    /** وبيعةُ المنضدة لا موعدَ لها — تُقال شرطةً لا تُخترَع */
    public function test_a_counter_sale_has_no_appointment(): void
    {
        $this->order($this->muscat);

        $row = $this->rows()[0];

        $this->assertSame('—', $row['scheduled']);
        $this->assertFalse($row['late']);
        $this->assertNull($row['fulfillment']);
    }

    /**
     * وما يُرشَّح «متأخّرًا» هو بعينه ما يُوسَم متأخّرًا — لا أوسعُ ولا أضيق.
     *
     * وهذا هو الحارس الذي يمنع الافتراق: القاعدة مكتوبةٌ مرّتين — استعلامًا
     * في `ListFilters::orders` وحكمًا في `Order::isLate` — فتُقارن المجموعتان
     * على أربعِ حالاتٍ تفرّق بينهما لو اختلفتا.
     */
    public function test_what_is_filtered_late_is_what_is_marked_late(): void
    {
        $this->order($this->muscat, ['status' => OrderStatus::PREPARING, 'scheduled_for' => now()->subDay()]);
        $this->order($this->muscat, ['status' => OrderStatus::PREPARING, 'scheduled_for' => now()->addDay()]);
        // موعدٌ مضى لكنّ الطلب أُغلق: سُلّم أمس، فليس متأخّرًا اليوم
        $this->order($this->muscat, ['status' => OrderStatus::DELIVERED, 'scheduled_for' => now()->subDay()]);
        // وملغًى مضى موعدُه: مغلقٌ كذلك
        $this->order($this->muscat, ['status' => OrderStatus::CANCELLED, 'scheduled_for' => now()->subDay()]);
        // وبيعةُ منضدةٍ بلا موعد
        $this->order($this->muscat);

        $filtered = array_column($this->rows(['when' => 'overdue']), 'id');
        $marked = array_column(array_filter($this->rows(), fn ($r) => $r['late']), 'id');

        sort($filtered);
        sort($marked);

        $this->assertSame($filtered, $marked, 'ما يُرشَّح متأخّرًا غيرُ ما يُوسَم متأخّرًا');
        $this->assertCount(1, $filtered);
    }

    /**
     * والعمودُ مرسومٌ في الشاشة.
     *
     * حارسٌ يقرأ مصدرًا — ويُقال إنّه ضعيف: وجودُ عمودٍ في جدولٍ تركيبُ صفحةٍ
     * لا سلوكُ مكوّن. وما تحته — الحمولةُ والوسمُ والاتّفاقُ مع المُرشِّح —
     * محروسٌ بما فوقه.
     */
    public function test_the_column_is_drawn(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Orders/Index.tsx'));

        $this->assertStringContainsString("key: 'scheduled'", $screen);
        $this->assertStringContainsString("header: 'موعد التسليم'", $screen);
    }

    /** والملفُّ يتبع المُرشِّح: من رشّح «متأخّر» وصدّر يجد المواعيد في ملفّه */
    public function test_the_file_carries_the_appointment_too(): void
    {
        $this->order($this->muscat, [
            'status' => OrderStatus::PREPARING,
            'scheduled_for' => now()->subDay()->setTime(9, 0),
            'fulfillment_type' => 'delivery',
        ]);

        $csv = $this->get(route('admin.export.orders', ['when' => 'overdue']))->streamedContent();

        $this->assertStringContainsString('موعد التسليم', $csv);
        $this->assertStringContainsString(now()->subDay()->format('Y-m-d').' 09:00', $csv);
        $this->assertStringContainsString('توصيل', $csv);

        // والورقتان الأخريان تُبنيان ولا تسقطان بعمودٍ جديد
        $this->get(route('admin.orders.xlsx'))->assertOk();
        $this->get(route('admin.orders.exportPdf'))->assertOk();
    }

    /* ══════════════ ٢ · العميلُ الواحد باسمٍ واحد ══════════════ */

    /** القائمةُ وصفحةُ الطلب تقولان اسمًا واحدًا — بأيّ لغةٍ قُرئتا */
    public function test_the_list_and_the_page_name_the_same_customer(): void
    {
        $order = $this->order($this->muscat);

        foreach (['ar' => 'عدي', 'en' => 'Oday'] as $locale => $expected) {
            session(['locale' => $locale]);

            $inList = $this->rows()[0]['customer'];
            $inPage = $this->get(route('admin.orders.show', $order->number))
                ->viewData('page')['props']['order']['customer'];

            $this->assertSame($expected, $inList, "القائمة بلغة {$locale}");
            $this->assertSame($inList, $inPage, "القائمة وصفحةُ الطلب افترقتا بلغة {$locale}");
        }
    }

    /* ══════════════ ٣ · طلبُ فرعٍ آخر يُقرأ ولا يُكتب ══════════════ */

    /** الصفحةُ تُفتح — ولا تُغلَق: يصلها التاجر من البحث ومن التنبيهات */
    public function test_another_branchs_order_still_opens(): void
    {
        $order = $this->order($this->salalah);
        $this->at($this->muscat);

        $this->get(route('admin.orders.show', $order->number))->assertOk();
        $this->assertCount(0, $this->rows(), 'ظهر في قائمة فرعٍ ليس فرعَه');
    }

    /** وتقول لمَ لا تُدار: اسمُ فرعِه لا صمتٌ ولا ٤٠٤ بعد الضغط */
    public function test_the_page_names_the_branch_it_belongs_to(): void
    {
        $order = $this->order($this->salalah);
        $this->at($this->muscat);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page
                ->where('otherBranch.id', $this->salalah->id)
                ->where('otherBranch.name', 'صلالة')
                // ولا يُعرض له قلمُ الفاتورة: بابٌ لا يُفتح لا يُرسم
                ->where('invoiceEdit.can', false));
    }

    /** وطلبُ فرعك يُدار بلا لافتة */
    public function test_your_own_branchs_order_is_not_locked(): void
    {
        $order = $this->order($this->muscat);
        $this->at($this->muscat);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page->where('otherBranch', null));
    }

    /** و«كل الفروع» يرى المتجر كلَّه ويتصرّف فيه */
    public function test_all_branches_locks_nothing(): void
    {
        $order = $this->order($this->salalah);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page->where('otherBranch', null));
    }

    /**
     * ولا تُصحَّح فاتورةُ فرعٍ آخر — وهذا هو الثقب الذي كان مفتوحًا.
     *
     * كميّةٌ تُكتب من جديد، ورصيدُ صلالة يتحرّك، ومعاملةٌ ماليّة تُصحَّح —
     * من فرعٍ لا يراه صاحبُه ولا يظهر له في قائمته.
     */
    public function test_another_branchs_invoice_is_not_rewritten(): void
    {
        $order = $this->order($this->salalah, ['subtotal' => 30, 'total' => 30]);
        $item = $order->items()->create([
            'product_id' => $this->rose->id, 'name' => 'بوكيه',
            'price' => 10, 'cost' => 4, 'quantity' => 3, 'total' => 30,
        ]);
        BranchStock::ensureAllocated($this->shop->id, $this->rose->id, 50);
        $before = BranchStock::bookOf($this->shop->id, $this->rose->id, $this->salalah->id);

        $this->at($this->muscat);

        $this->put(route('admin.orders.items.update', [$order->number, $item->id]), [
            'quantity' => 1, 'reason' => 'من فرعٍ آخر',
        ])->assertNotFound();

        $this->assertSame(3, (int) $item->fresh()->quantity);
        $this->assertSame($before, BranchStock::bookOf($this->shop->id, $this->rose->id, $this->salalah->id));
        $this->assertSame(0, OrderEdit::where('order_id', $order->id)->count());
    }

    /** ولا وسيلةُ دفعها — وهي التي كانت تمرّ بلا حارسِ مخزونٍ يصدّها مصادفةً */
    public function test_another_branchs_payment_method_is_not_rewritten(): void
    {
        $order = $this->order($this->salalah);
        $this->at($this->muscat);

        $this->put(route('admin.orders.payment.update', $order->number), [
            'payment_method' => 'بطاقة', 'reason' => 'من فرعٍ آخر',
        ])->assertNotFound();

        $this->assertSame('نقدي', $order->fresh()->payment_method);
    }

    /** وفاتورةُ فرعك تُصحَّح كما كانت — الحدُّ على الفرع لا على الفعل */
    public function test_your_own_branchs_invoice_is_still_corrected(): void
    {
        $order = $this->order($this->muscat, ['subtotal' => 30, 'total' => 30]);
        $item = $order->items()->create([
            'product_id' => $this->rose->id, 'name' => 'بوكيه',
            'price' => 10, 'cost' => 4, 'quantity' => 3, 'total' => 30,
        ]);
        BranchStock::ensureAllocated($this->shop->id, $this->rose->id, 50);

        $this->at($this->muscat);

        $this->put(route('admin.orders.items.update', [$order->number, $item->id]), [
            'quantity' => 1, 'reason' => 'أدخلتُ الكمية خطأً',
        ])->assertRedirect();

        $this->assertSame(1, (int) $item->fresh()->quantity);
    }

    /**
     * والشاشةُ تُخفي أفعالَها حين يكون الطلب من فرعٍ آخر.
     *
     * حارسٌ يقرأ مصدرًا — ضعيفٌ كأخيه: إخفاءُ أزرارٍ في ترويسة صفحةٍ لا يُثبَت
     * في jsdom إلّا بتركيب `AdminLayout` كلِّه. وما تحته — أنّ الخادم يردّ
     * كلَّ فعلٍ منها — محروسٌ بما فوقه.
     */
    public function test_the_screen_hides_what_the_server_will_refuse(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Orders/Show.tsx'));

        $this->assertStringContainsString('const actionable = !otherBranch;', $screen);

        /*
         * وسبعةُ مواضعَ تكتب: الإرسال، وإبلاغ الحالة، وطلب التقييم، وزرُّ
         * ورقة التفاصيل، وقلمُها داخل بطاقتها، و«أضِف التفاصيل» لطلبٍ بلا
         * ورقة، وأزرارُ نقل الحالة. وقلمُ الفاتورة ثامنٌ يُقاس في الخادم
         * (`invoiceEdit.can`) لا بهذا الحكم.
         */
        $this->assertSame(7, substr_count($screen, '{actionable &&'));
    }
}
