<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\DismissedNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\AlertMetrics;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بطاقات اللوحة الاختيارية، وحذف التنبيهات من الجرس.
 *
 * البطاقات كانت قائمةً ثابتة يُخفى منها ولا يُضاف إليها. والتنبيه كان له
 * مسار حذفٍ لا يستدعيه إلا شاشة الإعدادات — فالتاجر يرى تنبيهًا انتهى أمره
 * في الجرس ولا سبيل له إلى إزالته من حيث يراه.
 */
class DashboardCardsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    /* ---------------------- البطاقات الاختيارية ---------------------- */

    public function test_the_dashboard_offers_the_report_metrics_as_cards(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('statCatalog', count(\App\Models\CustomAlert::METRICS)));
    }

    /**
     * المصدر واحد: نفس مقاييس التنبيهات المخصّصة.
     *
     * قائمةٌ ثانية للّوحة كانت ستفترق عن الأولى عند أوّل تعديل — يقول التنبيه
     * رقمًا وتقول البطاقة غيره، ولا يعرف التاجر أيّهما يصدّق.
     */
    public function test_the_catalog_matches_the_alert_metrics_exactly(): void
    {
        $catalog = collect(AlertMetrics::catalog($this->business->id))->pluck('key')->sort()->values();
        $metrics = collect(array_keys(\App\Models\CustomAlert::METRICS))->sort()->values();

        $this->assertEquals($metrics, $catalog);
    }

    /** والقيمة محسوبة لا صفرًا معروضًا */
    public function test_a_card_carries_a_real_number(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 10, 'quantity' => 1, 'alert_qty' => 5, 'active' => true,
        ]);

        $card = collect(AlertMetrics::catalog($this->business->id))->firstWhere('key', 'low_stock_products');

        $this->assertSame('1', $card['value']);
    }

    /** وكلّ بطاقة تقود إلى قسمها — البطاقة سؤال وقسمها الجواب */
    public function test_each_card_links_to_its_section(): void
    {
        $card = collect(AlertMetrics::catalog($this->business->id))->firstWhere('key', 'dormant_customers');

        $this->assertSame(route('admin.customers.index'), $card['url']);
    }

    /**
     * ولا تقود إلى قسمٍ لا يملكه صاحبها.
     *
     * رابطٌ يقود إلى 403 أسوأ من بطاقة بلا رابط: يبدو بابًا وهو حائط.
     */
    public function test_a_card_has_no_link_to_a_section_the_user_cannot_open(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'permissions' => ['pos'],
        ]);
        $this->actingAs($cashier);

        $card = collect(AlertMetrics::catalog($this->business->id))->firstWhere('key', 'dormant_customers');

        $this->assertNull($card['url']);
    }

    /* ----------------------- حذف التنبيهات ----------------------- */

    public function test_dismissing_one_notification_removes_it_from_the_bell(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'خالد']);
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'customer_name' => 'خالد',
            'status' => 'جديد', 'is_held' => false, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $before = Demo::allNotifications();
        $this->assertNotEmpty($before, 'لا تنبيه لنختبر حذفه');
        $key = $before[0]['key'];

        $this->postJson(route('admin.notifications.dismiss'), ['key' => $key])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertNotContains($key, array_column(Demo::allNotifications(), 'key'));
    }

    public function test_clearing_removes_them_all(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'customer_name' => 'نقدي',
            'status' => 'جديد', 'is_held' => false, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);
        $this->assertNotEmpty(Demo::allNotifications());

        $this->postJson(route('admin.notifications.clear'))->assertOk();

        $this->assertEmpty(Demo::allNotifications());
    }

    /** والحذف لصاحبه وحده: زميلي لا يفقد تنبيهه لأنّي حذفتُ تنبيهي */
    public function test_dismissal_belongs_to_the_user_who_dismissed(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'customer_name' => 'نقدي',
            'status' => 'جديد', 'is_held' => false, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);
        $key = Demo::allNotifications()[0]['key'];

        $this->postJson(route('admin.notifications.dismiss'), ['key' => $key])->assertOk();

        $manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);
        $this->actingAs($manager);

        $this->assertContains($key, array_column(Demo::allNotifications(), 'key'));
        $this->assertSame(1, DismissedNotification::count());
    }
}
