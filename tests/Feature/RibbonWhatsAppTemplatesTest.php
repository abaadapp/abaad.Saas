<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\WhatsAppTemplateMapping;
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عقد RIBBON مع واتساب: أربع رسائل طلب فقط، وبالأسماء التي أنشأها سعود.
 */
class RibbonWhatsAppTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Business $ribbon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ribbon = Business::create([
            'name' => 'RIBBON Flower Lounge',
            'type' => 'محل ورود',
            'status' => 'نشط',
        ]);

        config(['whatsapp.ribbon_businesses' => [$this->ribbon->id]]);
    }

    public function test_ribbon_has_exactly_the_four_requested_templates(): void
    {
        // صفٌّ قديم يثبت أن إعادة التهيئة تنظّف ما لم يعد ضمن اتفاق سعود.
        WhatsAppTemplateMapping::create([
            'scope_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $this->ribbon->id,
            'event_type' => WhatsAppEvent::ORDER_DELIVERED,
            'template_name' => 'abaad_order_delivered',
            'language_code' => 'ar',
            'enabled' => true,
        ]);

        WhatsAppTemplates::seedBusinessDefaults($this->ribbon->id, 'ar');

        $rows = WhatsAppTemplateMapping::where('scope_type', WhatsAppMode::OWNER_BUSINESS)
            ->where('business_id', $this->ribbon->id)
            ->pluck('template_name', 'event_type')
            ->all();

        $this->assertSame([
            WhatsAppEvent::ORDER_RECEIVED => 'ribbon_order_received',
            WhatsAppEvent::ORDER_CONFIRMED => 'ribbon_order_confirmed',
            WhatsAppEvent::ORDER_READY => 'ribbon_order_ready',
            WhatsAppEvent::ORDER_OUT_FOR_DELIVERY => 'ribbon_order_out_for_delivery',
        ], $rows);

        $this->assertArrayNotHasKey(WhatsAppEvent::ORDER_DELIVERED, $rows);
        $this->assertArrayNotHasKey(WhatsAppEvent::INVOICE_DUE_SOON, $rows);
        $this->assertArrayNotHasKey(WhatsAppEvent::INVOICE_OVERDUE, $rows);
        $this->assertArrayNotHasKey(WhatsAppEvent::INVOICE_PAID, $rows);
    }

    public function test_ribbon_order_states_map_to_only_those_four_events(): void
    {
        $id = (int) $this->ribbon->id;

        $this->assertSame(WhatsAppEvent::ORDER_RECEIVED, WhatsAppEvent::forBusinessStatus($id, OrderStatus::PENDING));
        $this->assertSame(WhatsAppEvent::ORDER_CONFIRMED, WhatsAppEvent::forBusinessStatus($id, OrderStatus::CONFIRMED));
        $this->assertSame(WhatsAppEvent::ORDER_READY, WhatsAppEvent::forBusinessStatus($id, OrderStatus::READY));
        $this->assertSame(WhatsAppEvent::ORDER_OUT_FOR_DELIVERY, WhatsAppEvent::forBusinessStatus($id, OrderStatus::OUT_FOR_DELIVERY));

        $this->assertNull(WhatsAppEvent::forBusinessStatus($id, OrderStatus::DELIVERED));
        $this->assertFalse(WhatsAppEvent::allowedForBusiness($id, WhatsAppEvent::INVOICE_PAID));
    }

    public function test_ribbon_templates_send_no_body_variables(): void
    {
        $order = new Order(['number' => 'RIB-1001']);

        $this->assertSame([], WhatsAppTemplates::variables($this->ribbon, $order, WhatsAppMode::BUSINESS_OWN));
        $this->assertSame(
            ['RIBBON Flower Lounge', 'RIB-1001'],
            WhatsAppTemplates::variables($this->ribbon, $order, WhatsAppMode::ABAAD_SHARED),
        );
    }

    public function test_ribbon_waits_for_meta_approval_before_a_template_is_usable(): void
    {
        WhatsAppTemplates::seedBusinessDefaults($this->ribbon->id, 'ar');

        $this->assertNull(
            WhatsAppTemplates::resolve($this->ribbon, WhatsAppEvent::ORDER_READY, WhatsAppMode::BUSINESS_OWN),
        );

        WhatsAppTemplateMapping::where('business_id', $this->ribbon->id)
            ->where('event_type', WhatsAppEvent::ORDER_READY)
            ->update(['meta_status' => WhatsAppTemplates::APPROVED]);

        $this->assertSame(
            'ribbon_order_ready',
            WhatsAppTemplates::resolve(
                $this->ribbon,
                WhatsAppEvent::ORDER_READY,
                WhatsAppMode::BUSINESS_OWN,
            )?->template_name,
        );
    }

    public function test_other_shops_keep_the_existing_default_cycle(): void
    {
        $other = Business::create([
            'name' => 'متجر آخر',
            'type' => 'محل ورود',
            'status' => 'نشط',
        ]);

        $this->assertNull(WhatsAppEvent::forBusinessStatus((int) $other->id, OrderStatus::PENDING));
        $this->assertSame(
            WhatsAppEvent::ORDER_DELIVERED,
            WhatsAppEvent::forBusinessStatus((int) $other->id, OrderStatus::DELIVERED),
        );
    }
}
