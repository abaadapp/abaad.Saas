<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomAlert;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\AlertMetrics;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * العميل الراكد، والتنبيهات التي يعرّفها صاحب النشاط.
 *
 * التنبيهات كانت محصورة في ثلاثة أنواع مكتوبة في الكود، فأي شيء آخر يهمّ
 * التاجر لا سبيل لمراقبته إلا بتعديل الكود.
 */
class CustomAlertsTest extends TestCase
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

    private function customerWithOrderAt(string $name, $when): Customer
    {
        $c = Customer::create(['business_id' => $this->business->id, 'name' => $name]);
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-' . $c->id,
            'customer_id' => $c->id, 'customer_name' => $name, 'status' => 'مكتمل',
            'is_held' => false, 'subtotal' => 10, 'total' => 10, 'ordered_at' => $when,
        ]);

        return $c;
    }

    private function alertTexts(): array
    {
        return array_column(Demo::allNotifications(), 'text');
    }

    /* ------------------------- العميل الراكد ------------------------- */

    public function test_a_customer_who_stopped_buying_is_flagged(): void
    {
        $this->customerWithOrderAt('خالد', now()->subDays(90));

        $this->assertStringContainsString('خالد', implode(' | ', $this->alertTexts()));
    }

    public function test_a_recent_customer_is_not_flagged(): void
    {
        $this->customerWithOrderAt('مريم', now()->subDays(3));

        $this->assertStringNotContainsString('مريم', implode(' | ', $this->alertTexts()));
    }

    /**
     * من لم يشترِ قطّ ليس راكدًا بل لم يبدأ. خلطهما يُغرق الجرس بأسماء لا
     * معنى لمتابعتها — وهو ما يجعل التاجر يتوقّف عن قراءة التنبيهات أصلًا.
     */
    public function test_a_customer_who_never_bought_is_not_dormant(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'زائر']);

        $this->assertStringNotContainsString('زائر', implode(' | ', $this->alertTexts()));
    }

    public function test_the_dormancy_window_is_configurable(): void
    {
        $this->customerWithOrderAt('سالم', now()->subDays(20));
        $this->assertStringNotContainsString('سالم', implode(' | ', $this->alertTexts()));

        Setting::create(['business_id' => $this->business->id, 'key' => 'dormant_customer_days', 'value' => '10']);

        $this->assertStringContainsString('سالم', implode(' | ', $this->alertTexts()));
    }

    public function test_a_neighbours_dormant_customer_is_not_mine(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $c = Customer::create(['business_id' => $other->id, 'name' => 'عميل الجار']);
        Order::create([
            'business_id' => $other->id, 'number' => 'INV-JAR', 'customer_id' => $c->id,
            'customer_name' => 'عميل الجار', 'status' => 'مكتمل', 'is_held' => false,
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now()->subDays(200),
        ]);

        $this->assertStringNotContainsString('عميل الجار', implode(' | ', $this->alertTexts()));
    }

    /* ------------------------ القواعد المخصّصة ------------------------ */

    public function test_a_rule_fires_only_when_its_condition_holds(): void
    {
        $this->post(route('admin.alerts.store'), [
            'type' => 'rule', 'metric' => 'pending_orders', 'operator' => '>',
            'threshold' => 0, 'message' => 'طلبات متراكمة', 'section' => 'orders',
        ])->assertRedirect();

        $this->assertStringNotContainsString('طلبات متراكمة', implode(' | ', $this->alertTexts()));

        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-P', 'customer_name' => 'نقدي',
            'status' => 'جديد', 'is_held' => false, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->assertStringContainsString('طلبات متراكمة', implode(' | ', $this->alertTexts()));
    }

    public function test_a_disabled_rule_stays_silent(): void
    {
        $alert = CustomAlert::create([
            'business_id' => $this->business->id, 'type' => 'rule', 'metric' => 'pending_orders',
            'operator' => '>', 'threshold' => -1, 'message' => 'دائمًا', 'section' => 'orders', 'active' => true,
        ]);
        $this->assertStringContainsString('دائمًا', implode(' | ', $this->alertTexts()));

        $this->put(route('admin.alerts.update', $alert->id), ['active' => 0]);

        $this->assertStringNotContainsString('دائمًا', implode(' | ', $this->alertTexts()));
    }

    /* -------------------------- التذكيرات -------------------------- */

    public function test_a_reminder_appears_only_after_its_time(): void
    {
        $this->post(route('admin.alerts.store'), [
            'type' => 'reminder', 'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'message' => 'جرد المخزون', 'section' => 'inventory',
        ])->assertRedirect();

        $this->assertStringNotContainsString('جرد المخزون', implode(' | ', $this->alertTexts()));

        CustomAlert::where('business_id', $this->business->id)->update(['due_at' => now()->subMinute()]);

        $this->assertStringContainsString('جرد المخزون', implode(' | ', $this->alertTexts()));
    }

    /* ------------------------ التحقّق والعزل ------------------------ */

    public function test_a_rule_without_a_metric_is_refused(): void
    {
        $this->post(route('admin.alerts.store'), [
            'type' => 'rule', 'message' => 'بلا شرط', 'section' => 'orders',
        ])->assertSessionHasErrors(['metric', 'operator', 'threshold']);
    }

    public function test_a_reminder_without_a_date_is_refused(): void
    {
        $this->post(route('admin.alerts.store'), [
            'type' => 'reminder', 'message' => 'بلا موعد', 'section' => 'orders',
        ])->assertSessionHasErrors('due_at');
    }

    /** المقياس قائمة مغلقة: لا يصل شيء من إدخال المستخدم إلى قاعدة البيانات كشرط */
    public function test_an_unknown_metric_is_refused(): void
    {
        $this->post(route('admin.alerts.store'), [
            'type' => 'rule', 'metric' => 'DROP TABLE orders', 'operator' => '>',
            'threshold' => 1, 'message' => 'x', 'section' => 'orders',
        ])->assertSessionHasErrors('metric');
    }

    public function test_one_business_cannot_delete_anothers_alert(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = CustomAlert::create([
            'business_id' => $other->id, 'type' => 'reminder', 'due_at' => now(),
            'message' => 'تنبيه الجار', 'section' => 'orders', 'active' => true,
        ]);

        $this->delete(route('admin.alerts.destroy', $theirs->id))->assertNotFound();

        $this->assertDatabaseHas('custom_alerts', ['id' => $theirs->id]);
    }

    /** كل مقياس معلَن يجب أن يُحسب فعلًا — مقياسٌ يرجع صفرًا دائمًا قاعدةٌ ميّتة */
    public function test_every_declared_metric_is_computable(): void
    {
        foreach (array_keys(CustomAlert::METRICS) as $metric) {
            $this->assertIsFloat(
                AlertMetrics::value($metric, $this->business->id),
                "المقياس {$metric} لا يُحسب",
            );
        }
    }
}
