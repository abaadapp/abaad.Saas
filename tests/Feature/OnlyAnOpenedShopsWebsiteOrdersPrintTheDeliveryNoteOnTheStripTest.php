<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DeliveryPaper;
use App\Support\DocumentTemplates;
use App\Support\OrderStatus;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * سندُ التسليم على الشريط — لمن، ولأيّ طلب.
 *
 * ═══ القاعدة ═══
 *
 * مفتاحٌ على النشاط (`businesses.thermal_delivery_note_enabled`) مغلقٌ لكلّ
 * متجرٍ افتراضًا، يفتحه مديرُ المنصّة من شاشة النشاط. ومن فُتح له يطبع سندَ
 * طلبات **موقعه** (`orders.channel = website`) على شريط الصندوق — زرًّا
 * واحدًا «طباعة سند التسليم» بلا زرّ A4. وكلُّ ما سواه — متجرٌ مغلق، أو طلبُ
 * صندوقٍ، أو طلبٌ بلا قناة — سندُه A4 كما كان، وبابُ الشريط يردّه بـ404
 * ولو كُتب الرابطُ باليد. والعكسُ كذلك: طلبُ موقعٍ في متجرٍ فُتح له سندُه
 * الشريطُ وحده، وبابا ورقة A4 يردّانه بـ404 — ولا يُمسّ غيرُ سند التسليم
 * (الفاتورةُ والإيصال).
 *
 * والسؤالُ واحدٌ للزرّ وللباب (`DeliveryPaper::paperFor`): الشاشةُ ترسم ما
 * قاله الخادم، والبابُ يسأل السؤالَ نفسَه.
 */
class OnlyAnOpenedShopsWebsiteOrdersPrintTheDeliveryNoteOnTheStripTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'RIBBON TEST', 'type' => 'Flowers', 'status' => 'نشط', 'city' => 'Muscat']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'Main']);
    }

    private function open(bool $on = true, ?Business $shop = null): void
    {
        ($shop ?? $this->shop)->forceFill(['thermal_delivery_note_enabled' => $on])->save();
    }

    private function order(?string $channel, string $number, ?Business $shop = null): Order
    {
        $shop ??= $this->shop;
        $order = Order::create([
            'business_id' => $shop->id, 'branch_id' => $shop->is($this->shop) ? $this->branch->id : null,
            'number' => $number, 'channel' => $channel, 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'customer_name' => 'Buyer', 'employee_name' => 'Cashier',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 20,
            'ordered_at' => now(), 'scheduled_for' => now()->addDay(), 'fulfillment_type' => 'delivery',
            'recipient_name' => 'Sara', 'recipient_phone' => '+968 9123 4567', 'delivery_address' => 'Al Khuwair',
        ]);
        OrderItem::create(['order_id' => $order->id, 'name' => 'Roses', 'price' => 20, 'quantity' => 1, 'total' => 20]);

        return $order->load('items');
    }

    private function user(string $email, string $role = 'admin', ?array $sections = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $email, 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $sections,
        ]);
    }

    private function boss(): User
    {
        return User::firstOrCreate(['email' => 'boss@abaadapp.om'], [
            'business_id' => null, 'name' => 'مدير المنصة', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** ما تقوله صفحةُ الطلب: أيُّ سندٍ يُعرض */
    private function orderPage(User $user, Order $order): string
    {
        $paper = null;
        $this->actingAs($user)->get(route('admin.orders.show', $order->number))->assertOk()
            ->assertInertia(function ($page) use (&$paper) {
                $paper = $page->toArray()['props']['deliveryNote'] ?? null;

                return $page;
            });

        return (string) $paper;
    }

    /** وما تقوله لوحةُ التجهيز عن البطاقة نفسِها */
    private function board(User $user, Order $order): string
    {
        $paper = null;
        $this->actingAs($user)->get(route('admin.preparation.index'))->assertOk()
            ->assertInertia(function ($page) use (&$paper, $order) {
                foreach ($page->toArray()['props']['orders'] as $card) {
                    if ($card['number'] === $order->number) {
                        $paper = $card['delivery_paper'];
                    }
                }

                return $page;
            });

        return (string) $paper;
    }

    /** البابان كلاهما — صفحةُ الطلب ولوحةُ التجهيز */
    private function doors(User $user, Order $order): array
    {
        return [
            'orders' => $this->actingAs($user)->get(route('admin.orders.deliveryNoteThermal', $order->number)),
            'preparation' => $this->actingAs($user)->get(route('admin.preparation.deliveryNoteThermal', $order->number)),
        ];
    }

    /** وبابا ورقة A4 — من الموضعين نفسيهما */
    private function a4Doors(User $user, Order $order): array
    {
        return [
            'orders A4' => $this->actingAs($user)->get(route('admin.orders.deliveryNote', $order->number)),
            'preparation A4' => $this->actingAs($user)->get(route('admin.preparation.deliveryNote', $order->number)),
        ];
    }

    private function assertA4Prints(User $user, Order $order): void
    {
        foreach ($this->a4Doors($user, $order) as $door => $res) {
            $res->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('content-type'), $door);
        }
    }

    /* ═══════════ المفتاح ═══════════ */

    public function test_the_flag_is_off_for_every_shop_until_the_platform_opens_it(): void
    {
        $fresh = Business::create(['name' => 'نشاط جديد', 'type' => 'عام', 'status' => 'نشط']);
        $this->assertFalse((bool) $fresh->fresh()->thermal_delivery_note_enabled);
        $this->assertFalse(DeliveryPaper::thermalOn($fresh->id));
        $this->assertFalse(DeliveryPaper::thermalOn($this->shop->id));
        // ومتجرٌ له حسابُ مالك — شاشةُ النشاط تطلب بياناتِه لمتجرٍ بلا حساب
        $this->user('owner@abaadapp.om');

        // مديرُ المنصّة يفتحه لهذا وحدَه — من شاشة النشاط
        $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط',
            'thermal_delivery_note_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(DeliveryPaper::thermalOn($this->shop->id));
        $this->assertFalse(DeliveryPaper::thermalOn($fresh->id), 'فتحُه لمتجرٍ فتحه لآخر');
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $this->shop->id, 'description' => 'فتح سند التسليم الحراري لطلبات الموقع: '.$this->shop->name]);

        // وشاشةُ التعديل تعرض المحفوظ
        $this->actingAs($this->boss())->get(route('super-admin.businesses.edit', $this->shop->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('business.thermal_delivery_note_enabled', true)->etc());

        // وحفظٌ لاسمٍ بلا المفتاح لا يُطفئه
        $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => 'اسمٌ جديد', 'type' => $this->shop->type, 'status' => 'نشط',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(DeliveryPaper::thermalOn($this->shop->id));

        // ويُغلقه
        $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => 'اسمٌ جديد', 'type' => $this->shop->type, 'status' => 'نشط',
            'thermal_delivery_note_enabled' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertFalse(DeliveryPaper::thermalOn($this->shop->id));

        /*
         * وقيمةٌ فارغة — FormData تكتب `null` نصًّا فارغًا يصير null — تُقرأ
         * «مغلق» منطقًا، لا عمودًا فارغًا يُرفض أو يُقرأ بما اتّفق.
         */
        $this->shop->forceFill(['thermal_delivery_note_enabled' => true])->save();
        $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => 'اسمٌ جديد', 'type' => $this->shop->type, 'status' => 'نشط',
            'thermal_delivery_note_enabled' => '',
        ])->assertSessionHasNoErrors();
        $this->assertFalse(DeliveryPaper::thermalOn($this->shop->id));
        $this->assertFalse((bool) Business::whereKey($this->shop->id)->value('thermal_delivery_note_enabled'));
    }

    /** والتاجرُ لا يفتحه لنفسه — ولا بحمولةٍ يكتبها بيده */
    public function test_a_merchant_cannot_open_it_for_itself(): void
    {
        $owner = $this->user('owner@abaadapp.om');

        $res = $this->actingAs($owner)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط',
            'thermal_delivery_note_enabled' => '1',
        ]);

        $this->assertNotSame(200, $res->status());
        $this->assertFalse(DeliveryPaper::thermalOn($this->shop->id));
    }

    /* ═══════════ مغلق: A4 وحدَه، كما كان ═══════════ */

    public function test_a_shop_without_the_flag_keeps_the_a4_note_and_no_strip(): void
    {
        $owner = $this->user('owner@abaadapp.om');
        $web = $this->order(SalesChannel::WEBSITE, 'WEB-000001');

        $this->assertSame(DeliveryPaper::A4, $this->orderPage($owner, $web));
        $this->assertSame(DeliveryPaper::A4, $this->board($owner, $web));

        foreach ($this->doors($owner, $web) as $door => $res) {
            $res->assertNotFound();
        }

        // وورقتُه A4 تخرج كما كانت — من البابين
        $this->assertA4Prints($owner, $web);
    }

    /* ═══════════ مفتوح: طلبُ الموقع على الشريط ═══════════ */

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    #[DataProvider('widths')]
    public function test_an_opened_shops_website_order_prints_on_the_strip(int $mm): void
    {
        $this->open();
        DocumentTemplates::save($this->shop->id, 'sale', ['strip' => $mm.'mm']);
        $owner = $this->user('owner@abaadapp.om');
        $web = $this->order(SalesChannel::WEBSITE, 'WEB-000002');

        // الشاشتان تقولان «الشريط» — فلا يُعرض زرُّ A4
        $this->assertSame(DeliveryPaper::THERMAL, $this->orderPage($owner, $web));
        $this->assertSame(DeliveryPaper::THERMAL, $this->board($owner, $web));

        foreach ($this->doors($owner, $web) as $door => $res) {
            $res->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('content-type'), $door);
            $pages = PdfText::pages((string) $res->getContent());
            $this->assertCount(1, $pages, $door);
            $this->assertSame((float) $mm, $pages[0]['width'], "{$door}: the strip is the till's width");
        }

        // وورقةُ A4 لسنده تُردّ من البابين — سندُه الشريطُ وحده
        foreach ($this->a4Doors($owner, $web) as $door => $res) {
            $res->assertNotFound();
        }

        // ولا يُمسّ غيرُ سند التسليم: الفاتورةُ A4 وإيصالُ الصندوق يخرجان كما كانا
        foreach (['admin.orders.pdf', 'admin.orders.receipt'] as $paper) {
            $res = $this->actingAs($owner)->get(route($paper, $web->number));
            $res->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('content-type'), $paper);
        }
    }

    /** وطلبُ الصندوق في المتجر نفسِه يبقى على ورقته — والبابُ يردّ الرابطَ المكتوب باليد */
    public function test_an_opened_shops_till_order_keeps_its_a4_note(): void
    {
        $this->open();
        $owner = $this->user('owner@abaadapp.om');
        $pos = $this->order(SalesChannel::POS, 'INV-000003');

        $this->assertSame(DeliveryPaper::A4, $this->orderPage($owner, $pos));
        $this->assertSame(DeliveryPaper::A4, $this->board($owner, $pos));

        foreach ($this->doors($owner, $pos) as $door => $res) {
            $res->assertNotFound();
        }

        $this->assertA4Prints($owner, $pos);
    }

    /** وكلُّ قناةٍ غيرُ الموقع — طلبٌ قديمٌ بلا قناة، أو قناةٌ لا يعرفها النظام */
    public function test_any_other_channel_is_refused_at_the_door(): void
    {
        $this->open();
        $owner = $this->user('owner@abaadapp.om');

        foreach ([null, SalesChannel::UNKNOWN, 'whatsapp', 'WEBSITE'] as $k => $channel) {
            $order = $this->order($channel, 'OTHER-00000'.$k);

            $this->assertSame(DeliveryPaper::A4, DeliveryPaper::paperFor($order), (string) $channel);
            foreach ($this->doors($owner, $order) as $door => $res) {
                $res->assertNotFound();
            }
            // وورقتُه A4 كما كانت
            $this->assertA4Prints($owner, $order);
        }
    }

    /* ═══════════ من يطبع ═══════════ */

    public function test_preparation_staff_print_it_and_others_do_not(): void
    {
        $this->open();
        $web = $this->order(SalesChannel::WEBSITE, 'WEB-000004');

        // من مُنح لوحةَ التجهيز وحدَها يطبع من بابها — ولا يبلغ بابَ المبيعات
        $preparer = $this->user('prep@abaadapp.om', 'employee', ['dashboard', 'preparation']);
        $this->actingAs($preparer)->get(route('admin.preparation.deliveryNoteThermal', $web->number))->assertOk();
        $this->actingAs($preparer)->get(route('admin.preparation.deliveryNote', $web->number))->assertNotFound();
        $this->actingAs($preparer)->get(route('admin.orders.deliveryNoteThermal', $web->number))->assertForbidden();

        // ومن مُنح المبيعاتِ يطبع من صفحة الطلب
        $sales = $this->user('sales@abaadapp.om', 'employee', ['dashboard', 'orders']);
        $this->actingAs($sales)->get(route('admin.orders.deliveryNoteThermal', $web->number))->assertOk();

        // ومن لا يفتح أيًّا من الشاشتين لا يطبع شيئًا
        $stranger = $this->user('x@abaadapp.om', 'employee', ['dashboard', 'products']);
        foreach ($this->doors($stranger, $web) as $door => $res) {
            $res->assertForbidden();
        }

        // ومن لم يدخل لا يبلغه
        auth()->logout();
        $this->get(route('admin.orders.deliveryNoteThermal', $web->number))->assertRedirect();
        $this->get(route('admin.preparation.deliveryNoteThermal', $web->number))->assertRedirect();
    }

    /** ومتجرٌ آخر — ولو فُتح له المفتاحُ — لا يبلغ سندَ هذا المتجر */
    public function test_another_shop_cannot_reach_it(): void
    {
        $this->open();
        $web = $this->order(SalesChannel::WEBSITE, 'WEB-000005');

        $other = Business::create(['name' => 'BLOOM', 'type' => 'Flowers', 'status' => 'نشط']);
        $this->open(true, $other);
        $stranger = $this->user('other@abaadapp.om', 'admin', null, $other);

        foreach ($this->doors($stranger, $web) + $this->a4Doors($stranger, $web) as $door => $res) {
            $res->assertNotFound();
        }
    }

    /* ═══════════ الرابط ═══════════ */

    /**
     * والزرُّ يُنادي البابَ برقم الطلب — ما تُرسله الشاشتان بعينه.
     *
     * صفحةُ الطلب تُرسل `order.id` ولوحةُ التجهيز `number`، وكلاهما رقمُ الطلب
     * لا معرّفُ الصفّ: رابطٌ يُبنى بالمعرّف يُردّ بـ404.
     */
    public function test_the_buttons_call_the_door_with_the_order_number(): void
    {
        $this->open();
        $owner = $this->user('owner@abaadapp.om');
        $web = $this->order(SalesChannel::WEBSITE, 'WEB-000006');

        $sent = null;
        $this->actingAs($owner)->get(route('admin.orders.show', $web->number))
            ->assertInertia(function ($page) use (&$sent) {
                $sent = $page->toArray()['props']['order']['id'];

                return $page;
            });
        $this->assertSame($web->number, $sent);

        $boardSent = null;
        $this->actingAs($owner)->get(route('admin.preparation.index'))
            ->assertInertia(function ($page) use (&$boardSent) {
                $boardSent = $page->toArray()['props']['orders'][0]['number'];

                return $page;
            });
        $this->assertSame($web->number, $boardSent);

        foreach (['admin.orders.deliveryNoteThermal' => '/admin/orders/WEB-000006/delivery-note/thermal',
            'admin.preparation.deliveryNoteThermal' => '/admin/preparation/WEB-000006/delivery-note/thermal'] as $name => $path) {
            $url = route($name, $sent);
            $this->assertSame($path, parse_url($url, PHP_URL_PATH));
            $this->assertSame($name, app('router')->getRoutes()->match(Request::create($url, 'GET'))->getName());
            $this->actingAs($owner)->get($url)->assertOk();
        }

        // ومعرّفُ الصفّ ليس رقمَ الطلب
        $this->actingAs($owner)->get(route('admin.orders.deliveryNoteThermal', $web->id))->assertNotFound();
    }
}
