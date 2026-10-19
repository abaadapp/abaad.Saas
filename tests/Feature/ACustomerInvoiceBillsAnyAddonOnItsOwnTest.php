<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * فاتورةُ العميل اليدويّة تفوتر أيَّ إضافةٍ نشطةٍ للمتجر — بندًا وحدَها.
 *
 * ═══ ما كان ═══
 *
 * الكتالوجُ في شاشة الإنشاء منتجاتٌ وحدها. ومن أراد أن يفوتر «شوكولاتة»
 * بلا بوكيه كتبها بندًا يدويًّا بثمنٍ يكتبه بيده: لا هويّةَ للبند ولا ثمنَ
 * من الكتالوج.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - الكتالوجُ كلُّ إضافات المتجر النشطة: مداها «الكلّ» و«المختارة» والمملوكةُ
 *     لمنتج — لا الموقوفة، ولا إضافاتُ متجرٍ آخر، ولا تكلفةَ ولا مخزون.
 *   - الإضافةُ بندٌ مستقلّ: بلا منتج، ومع منتجٍ وبندٍ يدويّ كلٌّ في سطره.
 *   - الثمنُ والاسمُ من القاعدة لا من المتصفّح، وإضافةُ متجرٍ آخر تُردّ.
 *   - الضريبةُ شاملةً ومضافة، والمعاينةُ والمحفوظةُ والطباعةُ ورقةٌ واحدة.
 *   - اللقطةُ تبقى بعد تغيير الإضافة أو إيقافها أو حذفها.
 *   - لا رفَّ ينقص، ولا تكلفةَ تُقيَّد، والقيدُ مرّةً واحدة.
 */
class ACustomerInvoiceBillsAnyAddonOnItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private Branch $branch;

    private User $owner;

    private Customer $hotel;

    private Product $bouquet;

    private Product $shelf;

    private Addon $chocolate;

    private Addon $card;

    private Addon $ribbon;

    private Addon $stopped;

    private Addon $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الفاتورة', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@addons.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->hotel = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'فندق الساحل', 'phone' => '91234567', 'allow_credit_sales' => true,
        ]);

        $this->bouquet = Product::create(['business_id' => $this->shop->id, 'name' => 'بوكيه ورد', 'price' => 20, 'cost' => 8, 'quantity' => 10]);
        // ما تأكله الشوكولاتة من الرفّ — لو كانت الفاتورةُ تبيع من المخزن لَنقص
        $this->shelf = Product::create(['business_id' => $this->shop->id, 'name' => 'علبة شوكولاتة', 'price' => 2, 'cost' => 1.2, 'quantity' => 50]);

        $this->chocolate = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'price' => 3,
            'active' => true, 'scope' => Addon::SCOPE_ALL,
            'inventory_product_id' => $this->shelf->id, 'inventory_quantity' => 1,
        ]);
        // «المختارة»: تُعرض في الصندوق مع منتجٍ أُذن له وحده
        $this->card = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'بطاقة إهداء', 'price' => 1, 'active' => true, 'scope' => Addon::SCOPE_SELECTED,
        ]);
        DB::table('product_addons')->insert([
            'business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'addon_id' => $this->card->id,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // ومملوكةٌ لمنتجٍ بعينه
        $this->ribbon = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'شريطة ذهبية', 'price' => 0.5, 'active' => true, 'product_id' => $this->bouquet->id,
        ]);
        $this->stopped = Addon::create(['business_id' => $this->shop->id, 'name' => 'دبّ صغير', 'price' => 2, 'active' => false]);
        $this->theirs = Addon::create(['business_id' => $this->neighbour->id, 'name' => 'شوكولاتة الجار', 'price' => 9, 'active' => true]);
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => $value]);
    }

    /** بندُ إضافةٍ كما ترسله الشاشة — والثمنُ والاسمُ يُرسلان ولا يُصدَّقان */
    private function addonLine(Addon $addon, float $qty = 1, ?float $price = null, ?string $description = null): array
    {
        return [
            'product_id' => '', 'addon_id' => $addon->id,
            'description' => $description ?? $addon->name,
            'quantity' => $qty, 'unit_price' => $price ?? (float) $addon->price,
            'discount' => 0,
        ];
    }

    private function save(array $items, bool $issue = false)
    {
        return $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->hotel->id,
            'payment_method' => 'آجل',
            'issue' => $issue,
            'items' => $items,
        ]);
    }

    private function only(): CustomerInvoice
    {
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->count());

        return CustomerInvoice::where('business_id', $this->shop->id)->with('items')->firstOrFail();
    }

    /** سطورُ جدول البنود في الورقة — للمقابلة بين المعاينة والطباعة */
    private function rows(string $html): string
    {
        $this->assertSame(1, preg_match('/<table class="items">.*?<tbody>(.*?)<\/tbody>/su', $html, $m), 'لا جدولَ بنودٍ في الورقة');

        return trim((string) preg_replace('/\s+/u', ' ', $m[1]));
    }

    /** المبلغُ رقمًا — SQLite يردّ «3» وPostgreSQL «3.000» للعمود نفسه */
    private function n(mixed $value): float
    {
        return round((float) $value, 3);
    }

    /* ═══════════ ١ · الكتالوج ═══════════ */

    public function test_the_catalog_offers_every_active_addon_of_the_shop_and_nothing_else(): void
    {
        $props = $this->get(route('admin.customerInvoices.create'))->assertOk()->viewData('page')['props'];
        $ids = collect($props['addons'])->pluck('id')->sort()->values()->all();

        // «الكلّ» و«المختارة» والمملوكةُ لمنتج — بلا منتجٍ يُختار قبلها
        $this->assertSame(collect([$this->chocolate->id, $this->card->id, $this->ribbon->id])->sort()->values()->all(), $ids);
        $this->assertNotContains($this->stopped->id, $ids, 'إضافةٌ موقوفةٌ عُرضت');
        $this->assertNotContains($this->theirs->id, $ids, 'إضافةُ متجرٍ آخر عُرضت');
        $this->assertFalse($props['addons_truncated']);

        // وما يُعرض للاختيار وحده — لا تكلفةَ ولا صنفَ مخزونٍ ولا كميّتَه
        foreach ($props['addons'] as $row) {
            $this->assertSame(['id', 'name', 'name_en', 'price'], array_keys($row));
        }

        $chocolate = collect($props['addons'])->firstWhere('id', $this->chocolate->id);
        $this->assertSame(['name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'price' => 3.0], [
            'name' => $chocolate['name'], 'name_en' => $chocolate['name_en'], 'price' => $chocolate['price'],
        ]);
    }

    /* ═══════════ ٢ · بندٌ مستقلّ ═══════════ */

    public function test_an_addon_is_billed_alone_without_any_product(): void
    {
        $this->save([$this->addonLine($this->chocolate)])->assertSessionHasNoErrors();

        $item = $this->only()->items->sole();
        $this->assertNull($item->product_id);
        $this->assertSame($this->chocolate->id, (int) $item->addon_id);
        $this->assertSame('شوكولاتة', $item->description);
        $this->assertSame(3.0, $this->n($item->unit_price));
    }

    public function test_a_product_addons_and_a_manual_line_stay_independent_lines(): void
    {
        $this->save([
            ['product_id' => $this->bouquet->id, 'addon_id' => '', 'description' => 'بوكيه ورد', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0],
            $this->addonLine($this->chocolate, 2),
            $this->addonLine($this->card),
            ['product_id' => '', 'addon_id' => '', 'description' => 'تغليف خاص', 'quantity' => 1, 'unit_price' => 1.5, 'discount' => 0],
        ])->assertSessionHasNoErrors();

        $invoice = $this->only();
        $kinds = $invoice->items->sortBy('sort_order')->map(fn (CustomerInvoiceItem $i) => [
            $i->description,
            $i->product_id === null ? null : (int) $i->product_id,
            $i->addon_id === null ? null : (int) $i->addon_id,
            $this->n($i->quantity),
            $this->n($i->unit_price),
        ])->values()->all();

        $this->assertSame([
            ['بوكيه ورد', $this->bouquet->id, null, 1.0, 20.0],
            ['شوكولاتة', null, $this->chocolate->id, 2.0, 3.0],
            ['بطاقة إهداء', null, $this->card->id, 1.0, 1.0],
            ['تغليف خاص', null, null, 1.0, 1.5],
        ], $kinds);

        // ٢٠ + ٦ + ١ + ١٫٥ — والضريبةُ مضافةٌ بنسبة المتجر
        $this->assertSame([28.5, 1.425, 29.925], [$this->n($invoice->subtotal), $this->n($invoice->tax_total), $this->n($invoice->total)]);
    }

    /* ═══════════ ٣ · الثمنُ من القاعدة ═══════════ */

    public function test_the_addon_price_and_name_come_from_the_catalog_not_the_browser(): void
    {
        $forged = $this->addonLine($this->chocolate, 1, 0.001, 'هدية مجانية');

        $html = $this->postJson(route('admin.customerInvoices.preview'), ['items' => [$forged]])->assertOk()->json('html');
        $this->assertStringContainsString('شوكولاتة', $this->rows($html));
        $this->assertStringContainsString('3.000', $this->rows($html));
        $this->assertStringNotContainsString('هدية مجانية', $html);

        $this->save([$forged])->assertSessionHasNoErrors();

        $item = $this->only()->items->sole();
        $this->assertSame(3.0, $this->n($item->unit_price), 'ثمنٌ أرسلته أدواتُ المتصفّح كُتب على إضافةٍ من الكتالوج');
        $this->assertSame('شوكولاتة', $item->description);
    }

    public function test_an_addon_of_another_shop_or_a_stopped_one_is_refused(): void
    {
        foreach ([$this->theirs, $this->stopped] as $addon) {
            $this->save([$this->addonLine($addon)])->assertSessionHasErrors('items');
        }

        $this->assertSame(0, CustomerInvoice::count());

        // والمعاينةُ لا تُرسم بإضافة الجار اسمًا ولا ثمنًا
        $html = $this->postJson(route('admin.customerInvoices.preview'), ['items' => [$this->addonLine($this->theirs)]])->json('html');
        $this->assertStringNotContainsString('شوكولاتة الجار', $html);

        // والخدمةُ نفسُها تردّ إضافةَ متجرٍ آخر — لا الشاشةُ وحدها
        $this->expectException(RuntimeException::class);
        CustomerInvoices::create($this->shop->id, $this->hotel, [], [
            ['addon_id' => $this->theirs->id, 'description' => 'شوكولاتة', 'quantity' => 1, 'unit_price' => 3],
        ]);
    }

    public function test_a_line_is_a_product_or_an_addon_never_both(): void
    {
        $line = $this->addonLine($this->chocolate);
        $line['product_id'] = $this->bouquet->id;

        $this->save([$line])->assertSessionHasErrors('items.0.addon_id');
        $this->assertSame(0, CustomerInvoice::count());
    }

    /* ═══════════ ٤ · الضريبة ═══════════ */

    public function test_vat_exclusive_adds_its_tax_on_top_of_the_addon(): void
    {
        $this->set('vat_rate', '5');
        $this->set('tax_mode', 'exclusive');

        $this->save([$this->addonLine($this->chocolate)])->assertSessionHasNoErrors();

        $invoice = $this->only();
        $this->assertSame([3.0, 0.15, 3.15], [$this->n($invoice->subtotal), $this->n($invoice->tax_total), $this->n($invoice->total)]);
        $this->assertSame(3.15, $this->n($invoice->items->sole()->line_total));
    }

    public function test_vat_inclusive_takes_its_tax_out_of_the_addon(): void
    {
        $this->set('vat_rate', '5');
        $this->set('tax_mode', 'inclusive');

        $this->save([$this->addonLine($this->chocolate)])->assertSessionHasNoErrors();

        $invoice = $this->only();
        // ثلاثةٌ يدفعها العميل، منها ضريبتُها — لا ثلاثةٌ وضريبةٌ فوقها
        $this->assertSame([2.857, 0.143, 3.0], [$this->n($invoice->subtotal), $this->n($invoice->tax_total), $this->n($invoice->total)]);
        $this->assertSame(3.0, $this->n($invoice->items->sole()->line_total));
    }

    /* ═══════════ ٥ · ورقةٌ واحدة ═══════════ */

    public function test_the_preview_the_saved_invoice_and_the_pdf_show_the_same_lines(): void
    {
        $items = [
            ['product_id' => $this->bouquet->id, 'addon_id' => '', 'description' => 'بوكيه ورد', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0],
            $this->addonLine($this->chocolate, 2),
            $this->addonLine($this->card),
        ];

        $preview = $this->rows($this->postJson(route('admin.customerInvoices.preview'), ['items' => $items])->json('html'));
        $this->save($items, issue: true)->assertSessionHasNoErrors();
        $printed = $this->rows(PdfController::customerInvoiceHtml($this->shop->id, $this->only()));

        $this->assertSame($preview, $printed, 'المعاينةُ قالت سطورًا والطباعةُ غيرَها');
        $this->assertStringContainsString('شوكولاتة', $printed);
        // سطرٌ واحدٌ للإضافة: اسمُها ثمّ كميّتُها ثمّ ثمنُها من الكتالوج
        // وخليّةُ البيان قد تحمل تفاصيلَ البند تحت اسمه (`InvoiceLineDetails::rows`) — فالفراغُ قبل إغلاقها لا يُعدّ
        $this->assertMatchesRegularExpression('/شوكولاتة(<\/div>)?\s*<\/td> <td class="num">2<\/td> <td class="amt"><span dir="ltr">[^<]*3\.000/u', $printed);
    }

    /* ═══════════ ٦ · اللقطة ═══════════ */

    public function test_an_issued_invoice_keeps_its_addon_when_the_addon_changes_stops_or_goes(): void
    {
        $this->save([$this->addonLine($this->chocolate)], issue: true)->assertSessionHasNoErrors();
        $invoice = $this->only();
        $before = $this->rows(PdfController::customerInvoiceHtml($this->shop->id, $invoice));

        $this->chocolate->update(['name' => 'شوكولاتة فاخرة', 'price' => 9, 'active' => false]);

        $invoice = $invoice->fresh('items');
        $item = $invoice->items->sole();
        $this->assertSame(['شوكولاتة', 3.0, 3.15], [$item->description, $this->n($item->unit_price), $this->n($invoice->total)]);
        $this->assertSame($before, $this->rows(PdfController::customerInvoiceHtml($this->shop->id, $invoice)));

        // وحذفُها يُفرّغ المعرّف ولا يحذف البند
        $this->chocolate->delete();
        $item = $item->fresh();
        $this->assertNotNull($item, 'حذفُ الإضافة حذف بندَ فاتورةٍ صدرت');
        $this->assertNull($item->addon_id);
        $this->assertSame('شوكولاتة', $item->description);
    }

    /** وورقةٌ كُتبت قبل الكتالوج تبقى كما كُتبت — لا تُربط بإضافةٍ تشبهها اسمًا */
    public function test_an_invoice_written_before_the_addon_catalog_is_left_as_it_was(): void
    {
        $old = CustomerInvoices::create($this->shop->id, $this->hotel, [], [
            ['description' => 'شوكولاتة', 'quantity' => 1, 'unit_price' => 2.5],
        ]);

        $this->save([$this->addonLine($this->chocolate)])->assertSessionHasNoErrors();

        $item = $old->fresh('items')->items->sole();
        $this->assertNull($item->addon_id);
        $this->assertSame(2.5, $this->n($item->unit_price));
        $this->assertSame(2.625, $this->n($old->fresh()->total));
    }

    /* ═══════════ ٧ · لا مخزون ولا تكلفة، والقيدُ مرّة ═══════════ */

    public function test_an_invoiced_addon_moves_no_stock_and_posts_no_cost_once(): void
    {
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'product_id' => $this->shelf->id, 'quantity' => 50]);
        $branchStock = fn () => (string) BranchStock::where('product_id', $this->shelf->id)->value('quantity');
        $stockBefore = $branchStock();

        $this->save([
            ['product_id' => $this->bouquet->id, 'addon_id' => '', 'description' => 'بوكيه ورد', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0],
            $this->addonLine($this->chocolate, 3),
        ], issue: true)->assertSessionHasNoErrors();

        $invoice = $this->only();
        CustomerInvoices::issue($invoice, $this->owner->id); // إصدارٌ ثانٍ لا يكتب قيدًا ثانيًا

        // الرفُّ كما كان — الإضافةُ مربوطةٌ بصنفٍ مخزنيّ ولم ينقص منه شيء
        $this->assertSame(50.0, (float) $this->shelf->fresh()->quantity);
        $this->assertSame(10.0, (float) $this->bouquet->fresh()->quantity);
        $this->assertSame($stockBefore, $branchStock());
        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_adjustments')->count());

        $entries = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)
            ->with('lines.account')->get();
        $this->assertCount(1, $entries, 'قيدان لفاتورةٍ واحدة');

        $by = $entries->sole()->lines->mapWithKeys(fn ($l) => [
            $l->account->system_key => round((float) $l->debit - (float) $l->credit, 3),
        ])->sortKeys()->all();

        // إيرادٌ وضريبةٌ وذمّة — لا تكلفةَ ولا مخزون، ولا حسابَ «إضافات»
        $this->assertSame(['receivable' => 30.45, 'sales' => -29.0, 'tax_payable' => -1.45], $by);
    }
}
