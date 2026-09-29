<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\Product;
use App\Models\User;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\OrderStatus;
use App\Support\Pdf;
use App\Support\SalesChannel;
use App\Support\WebsiteConfirmPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «طباعة تلقائية عند تأكيد طلب الموقع» — خيارٌ لكلّ طابعة، ومطفأٌ لمن لم يختره.
 *
 * ═══ وما يُحرَس هنا (الخادم) ═══
 *
 * - الخيارُ يُحفظ للطابعة المقصودة وحدها، ولا يحمله غيرُ الطابعة، ولا يبلغ
 *   متجرٌ طابعةَ جاره، ولا يصل الشاشةَ من طابعةٍ مُعطَّلة.
 * - الخادمُ يقول «أُكّد الآن» لطلب الموقع حين يكون النقلُ «جديد ← مؤكّد» بعينه،
 *   من البابين كليهما — ولا يقولها لطلب الصندوق، ولا لنقلٍ آخر، ولا لنقلٍ رُفض،
 *   ولا في الطلب الذي يليه.
 * - والورقةُ التي تُفتح هي الشريطُ الحراريّ بعرض طابعة الصندوق — لا A4 —
 *   من الأبواب الثلاثة، ومنها بابُ اللوحة لمن خُصّص له التجهيزُ وحده.
 *
 * وفتحُ النافذة وتوجيهُها وإغلاقُها في المتصفّح — حارسُها في
 * `tests/js/a-website-order-prints-when-it-is-confirmed.test.tsx`.
 */
class AWebsiteOrderPrintsWhenItIsConfirmedAtTheTillTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private PosDevice $device;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        // هذا المتصفّح صندوقُ المحلّ — وكوكيه يرافق كلَّ طلبٍ بعده
        $this->device = $this->activatePosDevice($this->shop->id);
        $this->actingAs($this->owner);
    }

    /* ═══════════════════════ الخيار على الطابعة ═══════════════════════ */

    public function test_the_option_is_off_for_every_printer_that_never_chose_it(): void
    {
        // طابعةٌ أُضيفت قبل الخيار — صفٌّ لا يذكره أصلًا
        $old = $this->printer(['name' => 'طابعة قديمة']);
        $this->assertFalse((bool) DB::table('pos_peripherals')->where('id', $old->id)->value('auto_print_website_confirm'));

        // وطابعةٌ تُضاف اليوم بلا ذكره
        $this->post(route('admin.devices.peripherals.store', $this->device->id), [
            'name' => 'طابعة جديدة', 'type' => PosPeripheral::PRINTER, 'connection' => 'usb',
        ])->assertRedirect();

        $this->assertFalse((bool) DB::table('pos_peripherals')->where('name', 'طابعة جديدة')->value('auto_print_website_confirm'));
    }

    public function test_it_is_saved_for_the_printer_it_was_set_on_and_no_other(): void
    {
        $counter = $this->printer(['name' => 'طابعة الكاونتر']);
        $back = $this->printer(['name' => 'طابعة المخزن']);

        $this->put(route('admin.devices.peripherals.update', [$this->device->id, $counter->id]), [
            'name' => 'طابعة الكاونتر', 'type' => PosPeripheral::PRINTER, 'connection' => 'usb',
            'auto_print_website_confirm' => true,
        ])->assertRedirect();

        $this->assertTrue((bool) $counter->fresh()->auto_print_website_confirm);
        $this->assertFalse((bool) $back->fresh()->auto_print_website_confirm, 'انتقل الخيارُ إلى طابعةٍ لم يُضبط عليها');
        // والخياران مستقلّان: «بعد البيع» لم يُشتقّ منه
        $this->assertFalse((bool) $counter->fresh()->auto_print);
    }

    public function test_the_two_auto_prints_are_independent(): void
    {
        $p = $this->printer();

        $this->put(route('admin.devices.peripherals.update', [$this->device->id, $p->id]), [
            'name' => $p->name, 'type' => PosPeripheral::PRINTER, 'connection' => 'usb',
            'auto_print' => true, 'auto_print_website_confirm' => false,
        ]);
        $this->assertTrue((bool) $p->fresh()->auto_print);
        $this->assertFalse((bool) $p->fresh()->auto_print_website_confirm);

        $this->put(route('admin.devices.peripherals.update', [$this->device->id, $p->id]), [
            'name' => $p->name, 'type' => PosPeripheral::PRINTER, 'connection' => 'usb',
            'auto_print' => false, 'auto_print_website_confirm' => true,
        ]);
        $this->assertFalse((bool) $p->fresh()->auto_print);
        $this->assertTrue((bool) $p->fresh()->auto_print_website_confirm);
    }

    public function test_only_a_printer_carries_the_option(): void
    {
        $this->post(route('admin.devices.peripherals.store', $this->device->id), [
            'name' => 'ماسح', 'type' => PosPeripheral::SCANNER, 'connection' => 'usb',
            'auto_print_website_confirm' => true,
        ])->assertRedirect();

        $this->assertFalse((bool) DB::table('pos_peripherals')->where('name', 'ماسح')->value('auto_print_website_confirm'));

        // وطابعةٌ صارت درجًا تُسقطه
        $p = $this->printer(['auto_print_website_confirm' => true]);
        $this->put(route('admin.devices.peripherals.update', [$this->device->id, $p->id]), [
            'name' => $p->name, 'type' => PosPeripheral::DRAWER, 'connection' => 'usb',
            'auto_print_website_confirm' => true,
        ]);
        $this->assertFalse((bool) $p->fresh()->auto_print_website_confirm);
    }

    public function test_a_shop_cannot_switch_it_on_for_its_neighbours_printer(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعه']);
        $theirDevice = PosDevice::create([
            'business_id' => $neighbour->id, 'branch_id' => $branch->id, 'name' => 'صندوقه',
            'token_hash' => hash('sha256', 'theirs'), 'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]);
        $theirs = $theirDevice->peripherals()->create([
            'business_id' => $neighbour->id, 'name' => 'طابعته', 'type' => PosPeripheral::PRINTER,
            'connection' => 'usb', 'paper_width' => 80,
        ]);

        $this->put(route('admin.devices.peripherals.update', [$theirDevice->id, $theirs->id]), [
            'name' => 'طابعته', 'type' => PosPeripheral::PRINTER, 'connection' => 'usb',
            'auto_print_website_confirm' => true,
        ])->assertNotFound();

        $this->assertFalse((bool) $theirs->fresh()->auto_print_website_confirm);
    }

    /* ═══════════════════ ما يصل الشاشةَ من الصندوق ═══════════════════ */

    public function test_the_screen_learns_the_option_from_this_registers_active_printer(): void
    {
        $this->printer(['name' => 'تطبع', 'auto_print_website_confirm' => true]);

        $this->get(route('admin.orders.index'))->assertInertia(fn ($page) => $page
            ->where('context.peripherals.0.name', 'تطبع')
            ->where('context.peripherals.0.autoPrintWebsite', true)
            ->where('context.peripherals.0.autoPrint', false));
    }

    public function test_a_disabled_printer_is_not_used(): void
    {
        $this->printer(['name' => 'معطوبة', 'active' => false, 'auto_print_website_confirm' => true]);

        $this->get(route('admin.orders.index'))->assertInertia(fn ($page) => $page
            ->where('context.peripherals', []));
    }

    public function test_a_register_of_another_shop_does_not_print_this_shops_orders(): void
    {
        /*
         * متصفّحٌ ما زال مربوطًا بصندوق متجرٍ آخر، ودخله موظّفٌ من هذا المتجر.
         * ملحقاتُ ذلك الصندوق تصل كما كانت — والخيارُ وحده يُطفأ عليها.
         */
        $other = Business::create(['name' => 'متجر سابق', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعه']);
        $foreign = $this->activatePosDevice($other->id);
        $foreign->peripherals()->create([
            'business_id' => $other->id, 'name' => 'طابعة الجار', 'type' => PosPeripheral::PRINTER,
            'connection' => 'usb', 'paper_width' => 80, 'auto_print_website_confirm' => true,
        ]);

        $this->get(route('admin.orders.index'))->assertInertia(fn ($page) => $page
            ->where('context.peripherals.0.autoPrintWebsite', false));
    }

    /* ═══════════════ الخادمُ يقول «أُكّد الآن» — ولمن ═══════════════ */

    /** @return iterable<string, array{string}> */
    public static function doors(): iterable
    {
        yield 'شاشة الطلب' => ['admin.orders.status'];
        yield 'لوحة التجهيز' => ['admin.preparation.move'];
    }

    #[DataProvider('doors')]
    public function test_confirming_a_new_website_order_tells_the_screen_to_print(string $door): void
    {
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::PENDING);

        $this->post(route($door, $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas(WebsiteConfirmPrint::FLASH, $order->number);

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    #[DataProvider('doors')]
    public function test_the_signal_reaches_the_screen_once_and_never_again(string $door): void
    {
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::PENDING);

        // والردُّ الذي يلي النقلَ يحملها في `flash.websiteConfirmed`
        $this->followingRedirects()
            ->from(route('admin.orders.show', $order->number))
            ->post(route($door, $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertInertia(fn ($page) => $page->where('flash.websiteConfirmed', $order->number));

        // وإعادةُ التحميل، أو فتحُ الطلب ثانيةً، أو التحديثُ الدوريّ — لا تحملها
        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page->where('flash.websiteConfirmed', null));
        $this->get(route('admin.preparation.index'))
            ->assertInertia(fn ($page) => $page->where('flash.websiteConfirmed', null));
    }

    /** @return iterable<string, array{string, ?string, string, string}> */
    public static function notThisPress(): iterable
    {
        foreach (['admin.orders.status', 'admin.preparation.move'] as $door) {
            yield "{$door}: طلب الصندوق جديد ← مؤكّد" => [$door, SalesChannel::POS, OrderStatus::PENDING, OrderStatus::CONFIRMED];
            yield "{$door}: طلب قديم بلا قناة جديد ← مؤكّد" => [$door, null, OrderStatus::PENDING, OrderStatus::CONFIRMED];
            yield "{$door}: الموقع جديد ← قيد التجهيز" => [$door, SalesChannel::WEBSITE, OrderStatus::PENDING, OrderStatus::PREPARING];
            yield "{$door}: الموقع مؤكّد ← قيد التجهيز" => [$door, SalesChannel::WEBSITE, OrderStatus::CONFIRMED, OrderStatus::PREPARING];
            yield "{$door}: الموقع قيد التجهيز ← مؤكّد" => [$door, SalesChannel::WEBSITE, OrderStatus::PREPARING, OrderStatus::CONFIRMED];
            yield "{$door}: الموقع قيد التجهيز ← جاهز" => [$door, SalesChannel::WEBSITE, OrderStatus::PREPARING, OrderStatus::READY];
            yield "{$door}: الموقع جديد ← ملغي" => [$door, SalesChannel::WEBSITE, OrderStatus::PENDING, OrderStatus::CANCELLED];
        }
    }

    #[DataProvider('notThisPress')]
    public function test_no_other_move_tells_the_screen_to_print(string $door, ?string $channel, string $from, string $to): void
    {
        $order = $this->order($channel, $from);

        $this->post(route($door, $order->number), ['status' => $to])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing(WebsiteConfirmPrint::FLASH);

        // والنقلُ نفسُه تمّ — الذي تغيّر هو أن لا طباعة عليه
        $this->assertSame($to, $order->fresh()->status);
    }

    #[DataProvider('doors')]
    public function test_a_refused_move_tells_the_screen_nothing(string $door): void
    {
        // «جديد ← جاهز» ليس في NEXT — يُردّ، ولا يُطبع شيء
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::PENDING);

        $this->post(route($door, $order->number), ['status' => OrderStatus::READY])
            ->assertSessionHasErrors('status')
            ->assertSessionMissing(WebsiteConfirmPrint::FLASH);

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    #[DataProvider('doors')]
    public function test_confirming_twice_prints_once(string $door): void
    {
        /*
         * ضغطتان على «مؤكّد» — أو زميلان أكّدا الطلبَ نفسَه معًا.
         *
         * والثانيةُ يقرأ النقلُ «من» فيها «مؤكّد» لا «جديد» — أيًّا كان ما تفعله
         * بها `OrderTransition` — فلا ومضةَ ثانية، ولا ورقةَ ثانية.
         */
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::PENDING);

        $this->post(route($door, $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertSessionHas(WebsiteConfirmPrint::FLASH, $order->number);

        $this->post(route($door, $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertSessionMissing(WebsiteConfirmPrint::FLASH);

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    /* ═══════════════════════ الورقةُ التي تُفتح ═══════════════════════ */

    /** @return iterable<string, array{string}> */
    public static function receiptDoors(): iterable
    {
        yield 'من الطلبات' => ['admin.orders.receipt'];
        yield 'من نقطة البيع' => ['pos.receipt.pdf'];
        yield 'من لوحة التجهيز' => ['admin.preparation.receipt'];
    }

    #[DataProvider('receiptDoors')]
    public function test_the_page_opened_is_the_thermal_strip_at_the_registers_width(string $door): void
    {
        $this->printer(['paper_width' => 58, 'auto_print_website_confirm' => true]);
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::CONFIRMED);

        $seen = $this->papersPrinted(fn () => $this->get(route($door, $order->number))->assertOk());

        $this->assertSame([['strip', 58]], $seen, 'لم تُفتح الورقةُ الحراريّة بعرض طابعة الصندوق');
    }

    /* ═══════════════ من خُصّص له «لوحة التجهيز» وحدها ═══════════════ */

    /**
     * يؤكّد طلبَ الموقع من اللوحة، ويطبع إيصالَه من باب اللوحة — ولا يُمنح
     * بذلك «الطلبات» ولا «نقطة البيع».
     *
     * كانت الشاشةُ تختار البابَ من صلاحيّاته: «الطلبات» ثمّ «نقطة البيع». فمن
     * لا يملك أيًّا منهما يؤكّد ولا يطبع. والبابُ الآن باسم اللوحة
     * (`admin.preparation.receipt`) فيتبع قسمَها.
     */
    public function test_a_preparation_only_employee_confirms_and_prints_without_orders_or_pos(): void
    {
        $this->printer(['paper_width' => 58, 'auto_print_website_confirm' => true]);
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::PENDING);
        $bench = $this->employee(['preparation']);

        $this->assertTrue($bench->allows('preparation'));
        $this->assertFalse($bench->allows('orders'));
        $this->assertFalse($bench->allows('pos'));

        $this->actingAs($bench);

        // يؤكّد — والخادمُ يقول «اطبع»
        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::CONFIRMED])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas(WebsiteConfirmPrint::FLASH, $order->number);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);

        // ويطبع من باب اللوحة — الشريطُ الحراريّ بعرض طابعة الصندوق
        $seen = $this->papersPrinted(fn () => $this->get(route('admin.preparation.receipt', $order->number))->assertOk());
        $this->assertSame([['strip', 58]], $seen);

        // ولا يبلغ بابَي «الطلبات» و«نقطة البيع»
        $this->get(route('admin.orders.receipt', $order->number))->assertForbidden();
        $this->get(route('pos.receipt.pdf', $order->number))->assertForbidden();

        // والطباعةُ لم تمنحه شيئًا: صلاحيّاتُه كما كُتبت
        $this->assertSame(['preparation'], $bench->fresh()->permissions);
    }

    public function test_the_boards_receipt_needs_the_board(): void
    {
        $order = $this->order(SalesChannel::WEBSITE, OrderStatus::CONFIRMED);

        // «الطلبات» وحدها لا تفتح بابَ اللوحة — ولها بابُها
        $this->actingAs($this->employee(['orders']))
            ->get(route('admin.preparation.receipt', $order->number))
            ->assertForbidden();
    }

    public function test_the_boards_receipt_opens_only_an_order_on_the_board(): void
    {
        $this->actingAs($this->employee(['preparation']));

        // أُغلق — نزل عن اللوحة
        $closed = $this->order(SalesChannel::WEBSITE, OrderStatus::CLOSED[0]);
        $this->get(route('admin.preparation.receipt', $closed->number))->assertNotFound();

        // بيعةُ منضدة — لا تنفيذَ لها ولا موعد، فليست على اللوحة
        $counter = $this->order(SalesChannel::POS, OrderStatus::CONFIRMED, ['fulfillment_type' => null]);
        $this->get(route('admin.preparation.receipt', $counter->number))->assertNotFound();

        // طلبُ متجرٍ آخر
        $other = Business::create(['name' => 'الجار', 'type' => 'محل ورود', 'status' => 'نشط']);
        $theirs = Order::create([
            'business_id' => $other->id, 'number' => 'NB-1', 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 5, 'total' => 5,
            'ordered_at' => now(), 'channel' => SalesChannel::WEBSITE, 'fulfillment_type' => 'pickup',
        ]);
        $this->get(route('admin.preparation.receipt', $theirs->number))->assertNotFound();
    }

    /* ═══════════════════════════ أدوات ═══════════════════════════ */

    private function printer(array $over = []): PosPeripheral
    {
        return $this->device->peripherals()->create(array_merge([
            'business_id' => $this->shop->id, 'name' => 'طابعة الصندوق', 'type' => PosPeripheral::PRINTER,
            'connection' => 'usb', 'paper_width' => 80,
        ], $over));
    }

    /** موظّفٌ بصلاحيّاتٍ يدويّة — والقائمةُ حصرٌ لا زيادة */
    private function employee(array $permissions): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => uniqid().'@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /**
     * ما أُخرج من أوراق — محرّكٌ مزيّف يسجّل الوجهَ والعرض ولا يرسم شيئًا.
     *
     * @return list<array{string, ?int}>
     */
    private function papersPrinted(callable $request): array
    {
        $seen = [];
        $fake = new class($seen) implements PdfDriver
        {
            public function __construct(private array &$seen) {}

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->seen[] = ['sheet', null];

                return new Response('A4');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                $this->seen[] = ['strip', $widthMm];

                return new Response('strip');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 100.0;
            }
        };

        $was = Pdf::swap($fake);

        try {
            $request();
        } finally {
            Pdf::swap($was);
        }

        return $seen;
    }

    /** طلبُ الموقع على اللوحة كما يصلها: له نوعُ تنفيذ — انظر `scopeAwaitingPreparation` */
    private function order(?string $channel, string $status, array $over = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id,
            'branch_id' => Branch::where('business_id', $this->shop->id)->value('id'),
            'number' => 'INV-'.uniqid(), 'status' => $status, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'channel' => $channel, 'fulfillment_type' => 'pickup',
        ], $over));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 25,
        ]);

        return $order;
    }
}
