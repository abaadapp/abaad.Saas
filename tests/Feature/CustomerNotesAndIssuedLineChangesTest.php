<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\NotesAndEdits;
use App\Support\OrderCorrection;
use App\Support\PublicDocument;
use App\Support\Receivables;
use App\Support\SalesChannel;
use App\Support\Store\GiftCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * ملاحظاتُ العميل وتعديلُ أصناف الفاتورة بعد صدورها — لنشاطٍ فُتحت له الميزة وحده.
 *
 * المفتاحُ عمودٌ على النشاط (`businesses.order_notes_and_edits_enabled`،
 * `NotesAndEdits::on`) لا اسمٌ ولا رقمٌ في الكود. ومتجرٌ لم تُفتح له يبقى كما
 * كان: لا خانةَ في موقعه، ولا بابَ في الخادم.
 */
class CustomerNotesAndIssuedLineChangesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $plain;

    private Branch $branch;

    private User $owner;

    private Product $rose;

    private Product $lily;

    private Product $card;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-10 10:00:00');
        $this->app->setLocale('ar');
        Storage::fake('local');

        [$this->shop, $this->branch, $this->owner, $this->rose] = $this->business('ribbon', true);
        [$this->plain] = $this->business('plain', false);

        $this->lily = Product::create(['business_id' => $this->shop->id, 'name' => 'زنبق', 'name_en' => 'Lily',
            'price' => 30, 'cost' => 12, 'quantity' => 5, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        BranchStock::ensureAllocated($this->shop->id, $this->lily->id, 5);

        $this->card = Product::create([
            'business_id' => $this->shop->id, 'name' => GiftCard::PRODUCT_NAME, 'name_en' => 'Gift card',
            'price' => 1.5, 'cost' => 0, 'quantity' => 0, 'alert_qty' => 0, 'tracks_stock' => false,
            'is_gift_card' => true, 'active' => true, 'published' => true,
        ]);

        config([
            'storefront.ribbon_gift_card_product_businesses' => [$this->shop->id],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: Branch, 2: User, 3: Product} */
    private function business(string $slug, bool $on): array
    {
        $shop = Business::create([
            'name' => strtoupper($slug), 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '9689525906'.($on ? '6' : '7'),
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'order_notes_and_edits_enabled' => $on,
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::create(['business_id' => $shop->id, 'key' => 'loyalty_enabled', 'value' => '0']);

        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك '.$slug,
            'email' => $slug.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        $rose = Product::create(['business_id' => $shop->id, 'name' => 'باقة ورد', 'name_en' => 'Rose bouquet',
            'price' => 20, 'cost' => 8, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true]);
        BranchStock::ensureAllocated($shop->id, $rose->id, 100);

        return [$shop, $branch, $owner, $rose];
    }

    private function checkout(array $items, array $extra = [], string $slug = 'ribbon', string $lang = 'ar')
    {
        return $this->postJson('/s/'.$slug.'/checkout?lang='.$lang, [
            'items' => $items,
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'Maryam', 'phone' => '96899110001',
            'date' => '2027-03-12', 'slot' => '9 ص – 12 م',
        ] + $extra);
    }

    private function lastOrder(?Business $shop = null): Order
    {
        return Order::where('business_id', ($shop ?? $this->shop)->id)->orderByDesc('id')->firstOrFail();
    }

    /** بيعةُ صندوقٍ مدفوعة — كما يكتبها `PosController`: بندٌ ومخزونٌ ومعاملةٌ وقيد */
    private function posSale(int $qty = 2, string $status = 'مدفوع', ?Customer $customer = null): Order
    {
        $total = round(20 * $qty * 1.05, 3);
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'branch' => 'الخوير',
            'number' => 'INV-'.(Order::count() + 1), 'customer_id' => $customer?->id, 'customer_name' => $customer?->name ?? 'زبون',
            'channel' => SalesChannel::POS, 'payment_method' => 'نقدي', 'payment_status' => $status,
            'subtotal' => 20 * $qty, 'discount' => 0, 'tax' => round(20 * $qty * 0.05, 3), 'total' => $total,
            'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);
        $order->items()->create(['product_id' => $this->rose->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => $qty, 'total' => 20 * $qty, 'addons_total' => 0]);
        $this->rose->decrement('quantity', $qty);
        BranchStock::adjust($this->shop->id, $this->branch->id, $this->rose->id, -$qty);
        Transaction::create(['business_id' => $this->shop->id, 'order_id' => $order->id, 'reference' => $order->number,
            'description' => 'مبيعات', 'kind' => Transaction::SALE, 'method' => 'نقدي', 'type' => 'دخل',
            'amount' => $total, 'tax_amount' => $order->tax, 'occurred_at' => now()]);
        Books::recordSale($order->fresh('items'));

        return $order->fresh();
    }

    private function employee(array $permissions): User
    {
        return User::create(['business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => uniqid().'@abaad.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => $permissions]);
    }

    private function liveSaleEntries(Order $order): int
    {
        return Books::liveEntriesFor($order)->where('source', Books::SALE)->count();
    }

    /* ═══════════════ النطاق: المفتاحُ لا الاسم ═══════════════ */

    public function test_the_flag_is_a_business_column_and_another_shop_stays_as_it_was(): void
    {
        $this->assertTrue(NotesAndEdits::on($this->shop->id));
        $this->assertFalse(NotesAndEdits::on($this->plain->id));

        $page = $this->get('/s/ribbon/p/'.$this->rose->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-product-note"', $page);

        $plainRose = Product::where('business_id', $this->plain->id)->firstOrFail();
        $plain = $this->get('/s/plain/p/'.$plainRose->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('rb-product-note', $plain);
        $this->assertStringNotContainsString('rb-order-notes', $this->get('/s/plain/checkout')->assertOk()->getContent());
    }

    public function test_a_shop_without_the_feature_keeps_no_note_even_if_one_is_sent(): void
    {
        $plainRose = Product::where('business_id', $this->plain->id)->firstOrFail();

        $this->checkout([['id' => $plainRose->id, 'qty' => 1, 'note' => 'No wrapping']], ['notes' => 'Call me'], 'plain')->assertOk();

        $order = $this->lastOrder($this->plain);
        $this->assertNull($order->notes);
        $this->assertNull($order->items()->first()->note);
    }

    public function test_the_backend_closes_the_doors_for_a_shop_without_the_feature(): void
    {
        $order = Order::create(['business_id' => $this->plain->id, 'number' => 'P-1', 'status' => 'مكتمل',
            'is_held' => false, 'payment_status' => 'مدفوع', 'total' => 10, 'ordered_at' => now()]);
        $owner = User::where('business_id', $this->plain->id)->firstOrFail();
        $plainRose = Product::where('business_id', $this->plain->id)->firstOrFail();

        $this->actingAs($owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $plainRose->id, 'qty' => 1, 'reason' => 'إضافة',
        ])->assertNotFound();
        $this->actingAs($owner)->post(route('pos.orders.items.store', $order->number), [
            'product_id' => $plainRose->id, 'qty' => 1, 'reason' => 'إضافة',
        ])->assertNotFound();
        $this->assertSame(0, $order->items()->count());
    }

    /* ═══════════════ ملاحظةُ المنتج في الموقع ═══════════════ */

    public function test_the_product_note_is_a_free_textarea_labelled_in_the_site_language(): void
    {
        $ar = $this->get('/s/ribbon/p/'.$this->rose->id.'?lang=ar')->assertOk()->getContent();
        $this->assertStringContainsString('ملاحظة المنتج (اختياري)', $ar);

        $box = $this->between($ar, 'data-testid="rb-product-note-box"', '</div>');
        $this->assertStringContainsString('<textarea', $box);
        foreach (['<select', 'type="radio"', 'type="checkbox"', '<option'] as $choice) {
            $this->assertStringNotContainsString($choice, $box, 'خيارٌ جاهزٌ في خانة الملاحظة');
        }

        $en = $this->get('/s/ribbon/p/'.$this->rose->id.'?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('Product note (optional)', $en);
        $this->assertStringNotContainsString('ملاحظة المنتج (اختياري)', $en);
    }

    public function test_the_gift_card_page_keeps_its_own_box_and_no_product_note(): void
    {
        $page = $this->get('/s/ribbon/p/'.$this->card->id)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="rb-card-note"', $page);
        $this->assertStringNotContainsString('data-testid="rb-product-note"', $page);
    }

    public function test_an_english_product_note_is_kept_on_its_line(): void
    {
        $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => 'No plastic wrapping, 2 ribbons!']])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('No plastic wrapping, 2 ribbons!', $order->items()->first()->note);
        // والملاحظةُ للبند لا للطلب
        $this->assertNull($order->notes);
        $this->assertNull($order->internal_notes);
    }

    public function test_an_arabic_or_non_english_product_note_is_refused_in_the_site_language(): void
    {
        $ar = $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => 'بدون تغليف بلاستيك']])->assertStatus(422);
        $this->assertSame(['يرجى كتابة الملاحظة باللغة الإنجليزية فقط.'], $ar->json('errors.items'));

        $en = $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => 'ورد أبيض فقط']], [], 'ribbon', 'en')->assertStatus(422);
        $this->assertSame(['Please enter your note in English only.'], $en->json('errors.items'));

        $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => 'Café crème']])->assertStatus(422);
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());

        // والأرقامُ الهنديّة تُردّ إلى لاتينيّة قبل الفحص (`Numerals`) — رقمٌ لا حرف
        $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => 'Call at ٥']])->assertOk();
        $this->assertSame('Call at 5', $this->lastOrder()->items()->first()->note);
    }

    public function test_a_product_note_longer_than_500_is_refused(): void
    {
        $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => str_repeat('a', 501)]])->assertStatus(422);
        $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => str_repeat('a', 500)]])->assertOk();
    }

    public function test_two_notes_on_the_same_product_stay_two_lines_in_the_cart_and_the_order(): void
    {
        $items = [
            ['id' => $this->rose->id, 'qty' => 1, 'note' => 'White wrapping'],
            ['id' => $this->rose->id, 'qty' => 2, 'note' => 'No wrapping'],
        ];

        $quote = $this->postJson('/s/ribbon/quote', ['items' => $items])->assertOk()->json();
        $this->assertSame(['White wrapping', 'No wrapping'], array_column($quote['lines'], 'note'));

        $this->checkout($items)->assertOk();
        $lines = $this->lastOrder()->items()->orderBy('id')->get();
        $this->assertSame([['White wrapping', 1], ['No wrapping', 2]], $lines->map(fn ($i) => [$i->note, (int) $i->quantity])->all());
    }

    /* ═══════════════ ملاحظاتُ الطلب في الإتمام ═══════════════ */

    public function test_the_order_notes_box_is_labelled_in_the_site_language(): void
    {
        $ar = $this->get('/s/ribbon/checkout?lang=ar')->assertOk()->getContent();
        $this->assertStringContainsString('ملاحظات الطلب (اختياري)', $ar);
        $box = $this->between($ar, 'data-testid="rb-order-notes-box"', '</div>');
        $this->assertStringContainsString('<textarea', $box);
        $this->assertStringNotContainsString('<select', $box);

        $en = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('Order notes (optional)', $en);
    }

    public function test_english_order_notes_land_in_orders_notes_only(): void
    {
        $this->checkout([['id' => $this->rose->id, 'qty' => 1]], ['notes' => 'Please call before delivery'])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('Please call before delivery', $order->notes);
        $this->assertNull($order->internal_notes);
        $this->assertSame('9 ص – 12 م', $order->delivery_notes);
        $this->assertNull($order->items()->first()->note);
    }

    public function test_arabic_order_notes_are_refused_and_too_long_ones_too(): void
    {
        $r = $this->checkout([['id' => $this->rose->id, 'qty' => 1]], ['notes' => 'الرجاء الاتصال قبل التوصيل'])->assertStatus(422);
        $this->assertSame(['يرجى كتابة الملاحظة باللغة الإنجليزية فقط.'], $r->json('errors.notes'));

        $en = $this->checkout([['id' => $this->rose->id, 'qty' => 1]], ['notes' => 'اتصل'], 'ribbon', 'en')->assertStatus(422);
        $this->assertSame(['Please enter your note in English only.'], $en->json('errors.notes'));

        $this->checkout([['id' => $this->rose->id, 'qty' => 1]], ['notes' => str_repeat('a', 1001)])->assertStatus(422);
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    /* ═══════════════ كرتُ الهدية لا يتغيّر ═══════════════ */

    public function test_the_gift_card_message_stays_free_text_in_any_language(): void
    {
        $this->checkout([
            ['id' => $this->card->id, 'qty' => 1, 'note' => 'كل عام وأنتِ بخير'],
            ['id' => $this->rose->id, 'qty' => 1, 'note' => 'White flowers only'],
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('كل عام وأنتِ بخير', $order->card_message);
        $lines = $order->items()->orderBy('id')->get()->keyBy('product_id');
        $this->assertSame('كل عام وأنتِ بخير', $lines[$this->card->id]->note);
        $this->assertSame('White flowers only', $lines[$this->rose->id]->note);

        // والورقةُ تطبع ملاحظةَ المنتج وتحجب رسالةَ الكرت كما كانت
        $this->actingAs($this->owner);
        $a4 = PdfController::saleHtml($this->shop->id, $order->fresh('items'))['html'];
        $this->assertStringContainsString('White flowers only', $a4);
        $this->assertStringNotContainsString('كل عام وأنتِ بخير', $a4);
    }

    /* ═══════════════ العرض للمحلّ: كلُّ ملاحظةٍ باسمها ═══════════════ */

    public function test_the_order_screen_receives_each_note_in_its_own_place(): void
    {
        $this->checkout([
            ['id' => $this->rose->id, 'qty' => 1, 'note' => 'No plastic wrapping'],
            ['id' => $this->card->id, 'qty' => 1, 'note' => 'Happy birthday'],
        ], ['notes' => 'Please call before delivery'])->assertOk();
        $order = $this->lastOrder();
        $order->update(['internal_notes' => 'VIP — زبونة دائمة']);

        $props = $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))->assertOk()
            ->viewData('page')['props'];

        $items = collect($props['order']['items'])->keyBy('product_id');
        $this->assertSame('No plastic wrapping', $items[$this->rose->id]['note']);
        $this->assertFalse($items[$this->rose->id]['card_line']);
        $this->assertTrue($items[$this->card->id]['card_line']);
        $this->assertSame('Please call before delivery', $props['order']['notes']);
        $this->assertSame('VIP — زبونة دائمة', $props['order']['internal_notes']);
        $this->assertSame('Happy birthday', $props['order']['card_message']);
        $this->assertNotNull($props['lineEdit']);
    }

    public function test_the_paper_names_the_product_note_and_the_order_notes_and_never_prints_internal_notes(): void
    {
        $this->checkout([['id' => $this->rose->id, 'qty' => 1, 'note' => 'No plastic wrapping']], ['notes' => 'Please call before delivery'])->assertOk();
        $order = $this->lastOrder();
        $order->update(['internal_notes' => 'SECRET-INTERNAL-NOTE']);

        $this->actingAs($this->owner);
        $a4 = PdfController::saleHtml($this->shop->id, $order->fresh('items'))['html'];
        $strip = PdfController::saleHtml($this->shop->id, $order->fresh('items'), thermal: true)['html'];
        $public = $this->get((string) PublicDocument::url($order->fresh()))->assertOk()->getContent();

        foreach (['a4' => $a4, 'strip' => $strip] as $name => $html) {
            $this->assertStringContainsString('ملاحظة المنتج', $html, $name);
            $this->assertStringContainsString('No plastic wrapping', $html, $name);
            $this->assertStringContainsString('ملاحظات الطلب', $html, $name);
            $this->assertStringContainsString('Please call before delivery', $html, $name);
        }

        foreach (['a4' => $a4, 'strip' => $strip, 'public' => $public] as $name => $html) {
            $this->assertStringNotContainsString('SECRET-INTERNAL-NOTE', $html, 'ملاحظةٌ داخليّة على ورقة العميل: '.$name);
        }

        $done = $this->get('/s/ribbon/done/'.$order->number)->getContent();
        $this->assertStringNotContainsString('SECRET-INTERNAL-NOTE', $done);
    }

    public function test_another_shops_paper_is_printed_as_before(): void
    {
        $plainRose = Product::where('business_id', $this->plain->id)->firstOrFail();
        $order = Order::create(['business_id' => $this->plain->id, 'number' => 'P-9', 'status' => 'مكتمل', 'is_held' => false,
            'payment_status' => 'مدفوع', 'subtotal' => 20, 'total' => 20, 'notes' => 'ORDER-NOTE-X', 'ordered_at' => now()]);
        $order->items()->create(['product_id' => $plainRose->id, 'name' => 'باقة ورد', 'price' => 20, 'quantity' => 1, 'total' => 20, 'note' => 'بلا سكر']);

        $this->actingAs(User::where('business_id', $this->plain->id)->firstOrFail());
        $strip = PdfController::saleHtml($this->plain->id, $order->fresh('items'), thermal: true)['html'];
        $a4 = PdfController::saleHtml($this->plain->id, $order->fresh('items'))['html'];

        $this->assertStringContainsString('— بلا سكر', $strip);
        $this->assertStringContainsString('بلا سكر', $a4);
        foreach (['strip' => $strip, 'a4' => $a4] as $name => $html) {
            $this->assertStringNotContainsString('ملاحظة المنتج', $html, $name);
            $this->assertStringNotContainsString('ORDER-NOTE-X', $html, $name);
        }
    }

    /* ═══════════════ إضافةُ صنف ═══════════════ */

    public function test_adding_a_product_prices_it_on_the_server_and_moves_stock_tax_total_transaction_and_ledger(): void
    {
        $order = $this->posSale(2, 'مدفوع');
        $roseBefore = (int) $this->rose->fresh()->quantity;

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'price' => 0.001, 'note' => 'Tall vase',
            'reason' => 'الزبون أضاف زنبقة', 'settle' => 'collected',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $line = $order->items()->where('product_id', $this->lily->id)->firstOrFail();
        // السعرُ من القاعدة لا من الطلب
        $this->assertEquals(30, (float) $line->price);
        $this->assertSame('Tall vase', $line->note);
        $this->assertSame(4, (int) $this->lily->fresh()->quantity);
        $this->assertSame($roseBefore, (int) $this->rose->fresh()->quantity);

        // (٤٠ + ٣٠) × ١٫٠٥
        $this->assertEquals(70, (float) $order->subtotal);
        $this->assertEquals(3.5, (float) $order->tax);
        $this->assertEquals(73.5, (float) $order->total);
        $this->assertEquals(73.5, (float) Transaction::where('order_id', $order->id)->sum('amount'));

        // قيدٌ حيٌّ واحد للبيعة، والإيرادُ بلا ضريبة
        $this->assertSame(1, $this->liveSaleEntries($order));
        $this->assertSame(70.0, Ledger::netRevenue($this->shop->id, now()->startOfDay()));
        $this->assertSame(73.5, round(Ledger::balance($this->shop->id, 'cash'), 3));

        $edit = OrderEdit::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(OrderEdit::ADD_LINE, $edit->kind);
        $this->assertEquals(42, (float) $edit->order_total_before);
        $this->assertEquals(73.5, (float) $edit->order_total_after);
        $this->assertSame('الزبون أضاف زنبقة', $edit->reason);
        $this->assertSame($this->owner->id, (int) $edit->user_id);
    }

    public function test_a_website_order_takes_an_added_product_through_the_same_door(): void
    {
        $this->checkout([['id' => $this->rose->id, 'qty' => 1]])->assertOk();
        $order = $this->lastOrder();

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'أضاف بالهاتف',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('غير مدفوع', $order->payment_status);
        $this->assertEquals(52.5, (float) $order->total);
        // غيرُ المدفوع ذمّةٌ كلُّه — بلا سؤالٍ عن الفرق
        $this->assertSame(52.5, round(Ledger::balance($this->shop->id, 'receivable'), 3));
        $this->assertSame(1, $this->liveSaleEntries($order));
    }

    public function test_a_paid_invoice_never_takes_the_difference_as_paid_by_itself(): void
    {
        $order = $this->posSale(2, 'مدفوع');

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة',
        ])->assertSessionHasErrors('line');
        $this->assertSame(1, $order->items()->count(), 'أُضيف الصنف بلا قرارٍ في الفرق');

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة', 'settle' => 'due',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEquals(31.5, (float) $order->balance_due);
        $this->assertSame('مدفوع', $order->payment_status);
        // الدرجُ لم يدخله إلّا ما دُفع، والفرقُ ذمّة
        $this->assertEquals(42, (float) Transaction::where('order_id', $order->id)->sum('amount'));
        $this->assertSame(42.0, round(Ledger::balance($this->shop->id, 'cash'), 3));
        $this->assertSame(31.5, round(Ledger::balance($this->shop->id, 'receivable'), 3));
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);

        // وتحصيلُه لاحقًا قيدٌ بيومه
        Carbon::setTestNow('2027-03-11 09:00:00');
        $this->actingAs($this->owner)->post(route('admin.orders.balance.collect', $order->number), [
            'payment_method' => 'نقدي', 'reason' => 'دفع الفرق',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $order->fresh()->balance_due);
        $this->assertSame(73.5, round(Ledger::balance($this->shop->id, 'cash'), 3));
        $this->assertSame(0.0, round(Ledger::balance($this->shop->id, 'receivable'), 3));
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('kind', Transaction::ORDER_BALANCE)
            ->whereDate('occurred_at', '2027-03-11')->count());
        // والقيدُ بيوم التحصيل — لا يُدخل في صندوقِ يومٍ أُقفل مالًا لم يكن فيه
        $this->assertSame('2027-03-11', JournalEntry::where('source', OrderCorrection::BALANCE_SOURCE)
            ->firstOrFail()->entry_date->toDateString());
    }

    public function test_a_card_paid_website_order_is_never_charged_again(): void
    {
        Http::fake();
        $this->checkout([['id' => $this->rose->id, 'qty' => 1]])->assertOk();
        $order = $this->lastOrder();
        $order->update(['payment_status' => 'مدفوع', 'payment_method' => 'بطاقة']);
        $intent = StorePaymentIntent::forceCreate(['business_id' => $this->shop->id, 'order_id' => $order->id,
            'reference' => 'RB-1', 'amount' => 21, 'status' => 'paid', 'payload' => []]);

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة', 'settle' => 'due',
        ])->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertEquals(21, (float) $intent->fresh()->amount);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertEquals(31.5, (float) $order->fresh()->balance_due);
    }

    /* ═══════════════ الاستبدال ═══════════════ */

    public function test_replacing_returns_the_old_stock_takes_the_new_and_never_rewrites_the_old_line(): void
    {
        $order = $this->posSale(2, 'مدفوع');
        $old = $order->items()->firstOrFail();
        $roseBefore = (int) $this->rose->fresh()->quantity;

        $this->actingAs($this->owner)->post(route('admin.orders.items.replace', [$order->number, $old->id]), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'غيّر رأيه', 'settle' => 'refunded',
        ])->assertSessionHasNoErrors();

        $this->assertNull($old->fresh(), 'غُيّر صنفُ البند القديم في مكانه بدل حذفه');
        $new = $order->items()->firstOrFail();
        $this->assertSame($this->lily->id, (int) $new->product_id);
        $this->assertSame($roseBefore + 2, (int) $this->rose->fresh()->quantity);
        $this->assertSame(4, (int) $this->lily->fresh()->quantity);
        $this->assertEquals(31.5, (float) $order->fresh()->total);
        $this->assertSame(1, $this->liveSaleEntries($order));
        $this->assertSame(30.0, Ledger::netRevenue($this->shop->id, now()->startOfDay()));

        $edit = OrderEdit::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(OrderEdit::REPLACE_LINE, $edit->kind);
        $this->assertSame(2, (int) $edit->qty_before);
        $this->assertSame(1, (int) $edit->qty_after);
        $this->assertStringContainsString('باقة ورد', (string) $edit->value_before);
        $this->assertStringContainsString('زنبق', (string) $edit->value_after);
    }

    public function test_a_lower_paid_total_is_not_refunded_without_the_staff_saying_so(): void
    {
        $order = $this->posSale(2, 'مدفوع');
        $old = $order->items()->firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.orders.items.replace', [$order->number, $old->id]), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'غيّر رأيه',
        ])->assertSessionHasErrors('line');

        $this->assertNotNull($old->fresh());
    }

    public function test_a_replacement_that_fails_leaves_the_invoice_and_the_shelf_untouched(): void
    {
        $order = $this->posSale(2, 'مدفوع');
        $old = $order->items()->firstOrFail();
        $roseBefore = (int) $this->rose->fresh()->quantity;
        $entries = JournalEntry::count();

        // الزنبقُ خمسٌ على الرفّ — والمطلوبُ تسع
        $this->actingAs($this->owner)->post(route('admin.orders.items.replace', [$order->number, $old->id]), [
            'product_id' => $this->lily->id, 'qty' => 9, 'reason' => 'غيّر رأيه', 'settle' => 'collected',
        ])->assertSessionHasErrors('line');

        $this->assertNotNull($old->fresh());
        $this->assertSame(2, (int) $old->fresh()->quantity);
        $this->assertSame($roseBefore, (int) $this->rose->fresh()->quantity);
        $this->assertSame(5, (int) $this->lily->fresh()->quantity);
        $this->assertEquals(42, (float) $order->fresh()->total);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(0, OrderEdit::where('order_id', $order->id)->count());
    }

    /* ═══════════════ ملاحظةُ المنتج بعد الإصدار ═══════════════ */

    public function test_editing_a_product_note_touches_nothing_but_the_note_and_its_trace(): void
    {
        $order = $this->posSale(2, 'مدفوع');
        $item = $order->items()->firstOrFail();
        $entries = JournalEntry::count();
        $stock = (int) $this->rose->fresh()->quantity;

        // والموظّفُ يكتب بما شاء — «بالإنجليزيّة وحدها» قاعدةُ الموقع
        $this->actingAs($this->owner)->put(route('admin.orders.items.note', [$order->number, $item->id]), [
            'note' => 'بدون بطاقة', 'reason' => 'طلب الزبون',
        ])->assertSessionHasNoErrors();

        $this->assertSame('بدون بطاقة', $item->fresh()->note);
        $this->assertEquals(42, (float) $order->fresh()->total);
        $this->assertSame($stock, (int) $this->rose->fresh()->quantity);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertEquals(42, (float) Transaction::where('order_id', $order->id)->sum('amount'));

        $edit = OrderEdit::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(OrderEdit::NOTE, $edit->kind);
        $this->assertNull($edit->value_before);
        $this->assertSame('بدون بطاقة', $edit->value_after);
    }

    public function test_a_gift_card_line_keeps_its_message_out_of_the_note_editor(): void
    {
        $this->checkout([['id' => $this->card->id, 'qty' => 1, 'note' => 'Happy birthday']])->assertOk();
        $order = $this->lastOrder();
        $line = $order->items()->firstOrFail();

        $this->actingAs($this->owner)->put(route('admin.orders.items.note', [$order->number, $line->id]), [
            'note' => 'x', 'reason' => 'تجربة',
        ])->assertSessionHasErrors('line');

        $this->assertSame('Happy birthday', $line->fresh()->note);

        // ولا يُستبدل من هنا — رسالتُه تضيع مع بنده
        $this->actingAs($this->owner)->post(route('admin.orders.items.replace', [$order->number, $line->id]), [
            'product_id' => $this->rose->id, 'qty' => 1, 'reason' => 'تجربة',
        ])->assertSessionHasErrors('line');
        $this->assertNotNull($line->fresh());
    }

    /* ═══════════════ الصلاحية ═══════════════ */

    public function test_order_edit_allows_and_its_absence_refuses_on_the_server(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');
        $without = $this->employee(['pos', 'orders']);
        $with = $this->employee(['pos', 'orders', 'order.edit']);

        $payload = ['product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة'];

        $this->actingAs($without)->post(route('admin.orders.items.store', $order->number), $payload)
            ->assertSessionHasErrors('permission');
        $this->actingAs($without)->put(route('admin.orders.items.note', [$order->number, $order->items()->first()->id]), ['note' => 'x', 'reason' => 'سبب'])
            ->assertSessionHasErrors('permission');
        $this->assertSame(1, $order->items()->count());

        $this->actingAs($with)->post(route('admin.orders.items.store', $order->number), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, $order->items()->count());
    }

    public function test_the_screen_hides_the_buttons_from_who_cannot_edit(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');

        $props = fn (User $u) => $this->actingAs($u)->get(route('admin.orders.show', $order->number))->viewData('page')['props']['lineEdit'];

        $this->assertSame(['lines' => false, 'notes' => false], array_intersect_key($props($this->employee(['orders'])), ['lines' => 1, 'notes' => 1]));
        $owner = $props($this->owner);
        $this->assertTrue($owner['lines']);
        $this->assertNotEmpty($owner['catalog']);
        $this->assertNotContains($this->card->id, array_column($owner['catalog'], 'id'));
    }

    /* ═══════════════ القيود القائمة ═══════════════ */

    public function test_the_same_day_rule_holds_for_add_and_replace(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');
        Carbon::setTestNow('2027-03-11 09:00:00');

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة',
        ])->assertSessionHasErrors('line');
        $this->assertSame(1, $order->items()->count());

        // والملاحظةُ نصٌّ لا مال — لا تُقفل بانتهاء اليوم
        $this->actingAs($this->owner)->put(route('admin.orders.items.note', [$order->number, $order->items()->first()->id]), [
            'note' => 'Deliver after 4pm', 'reason' => 'اتصل الزبون',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_filed_vat_period_is_never_rewritten(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_filed_through', 'value' => now()->toDateString()]);

        $this->expectException(RuntimeException::class);
        $this->actingAs($this->owner);
        OrderCorrection::addLine($order, ['product_id' => $this->lily->id, 'qty' => 1], 'إضافة', 'collected');
    }

    public function test_an_order_with_a_customer_invoice_is_corrected_by_credit_note_not_here(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة']);
        $invoice = CustomerInvoice::create(['business_id' => $this->shop->id, 'customer_id' => $customer->id, 'number' => 'CI-1',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->toDateString(), 'subtotal' => 21, 'total' => 21]);
        $invoice->orders()->attach($order->id);

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة',
        ])->assertSessionHasErrors('line');
    }

    public function test_another_shop_and_another_branch_cannot_reach_the_order(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');
        $intruder = User::create(['business_id' => $this->plain->id, 'name' => 'دخيل', 'email' => 'x@x.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->plain->update(['order_notes_and_edits_enabled' => true]);

        $this->actingAs($intruder)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة',
        ])->assertNotFound();

        $other = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);
        $this->actingAs($this->owner)->withSession(['current_branch' => $other->id])
            ->post(route('admin.orders.items.store', $order->number), [
                'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة',
            ])->assertNotFound();

        $this->assertSame(1, $order->items()->count());
    }

    public function test_the_pos_door_works_too(): void
    {
        $order = $this->posSale(1, 'غير مدفوع');

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->post(route('pos.orders.items.store', $order->number), [
                'product_id' => $this->lily->id, 'qty' => 1, 'reason' => 'إضافة',
            ])->assertSessionHasNoErrors();

        $this->assertSame(2, $order->items()->count());
    }

    public function test_loyalty_follows_the_new_total(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'loyalty_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'loyalty_earn_rate', 'value' => '1']);
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'مريم', 'points' => 21]);
        $order = $this->posSale(1, 'غير مدفوع', $customer);
        $order->update(['points_earned' => 21]);

        $this->actingAs($this->owner);
        OrderCorrection::addLine($order->fresh(), ['product_id' => $this->lily->id, 'qty' => 1], 'إضافة');

        $this->assertSame(52, (int) $order->fresh()->points_earned);
        $this->assertSame(52, (int) $customer->fresh()->points);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'لم يوجد: '.$from);
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end - $start);
    }
}
