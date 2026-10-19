<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\ItemAddons;
use App\Support\Ledger;
use App\Support\Lexicon;
use App\Support\OrderStatus;
use App\Support\PrepSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * اسمُ الصنف والإضافة كما كتبه التاجر — لا يُترجم له عند الحفظ.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * كان كلُّ حفظٍ لصنفٍ أو إضافة — نموذجُ الصنف، والإضافةُ السريعة، وإضافةٌ
 * تُنشأ مع تركيب الصنف — يملأ الاسمَ الإنجليزيَّ من المعجم إن تُرك فارغًا
 * (`Lexicon::fill`): «باقة ورد أحمر» ← «Red rose bouquet». فيقرأ الكاشيرُ
 * والإيصالُ الإنجليزيّ ولقطةُ البيع اسمًا تجاريًّا لم يكتبه التاجر، ولا عمودَ
 * يفرّقه عمّا كتبه بيده، ومن مسحه عاد إليه عند الحفظ التالي.
 *
 * ═══ وما يُحرَس (قرار المالك، مراجعة #131) ═══
 *
 *   - صنفٌ وإضافةٌ جديدان بلا اسمٍ إنجليزيّ يبقيان بلا اسمٍ إنجليزيّ — في
 *     كلّ باب حفظ — ولو عرف المعجمُ كلَّ لفظ. ويُعرضان بالعربيّة كما كُتبا.
 *   - وما كتبه التاجرُ بيده يُحفظ كما كتبه، ويُعدَّل ويُمسح بيده.
 *   - والقديمُ المخزَّن — كتبه التاجر أو ملأه المعجمُ قبلُ — يبقى ولا يُمسح.
 *   - والبحثُ يجد الصنفَ بالاسمين.
 *   - ولقطاتُ البيع والفاتورة والطباعةُ التاريخيّة لا يمسّها تعديلُ الكتالوج.
 *
 * والأقسامُ والمقاساتُ ألفاظٌ عامّة يبقى لها المعجم (`LexiconTest`).
 */
class AShopsProductNamesAreNeverTranslatedForItTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الاسم', 'type' => 'محل ورود', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@names.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        // المعجمُ يعرف هذه الألفاظ — فالامتناعُ قرارٌ لا عجز
        $this->assertSame('Red rose bouquet', Lexicon::translate('باقة ورد أحمر'));
        $this->assertNotNull(Lexicon::translate('تغليف'));

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $this->shop->id, 'name' => 'باقة ورد أحمر', 'price' => 15, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 1,
        ]);
    }

    /** حمولةُ نموذج الصنف كما يرسلها — والاسمُ الإنجليزيّ فيها دائمًا، ولو فارغًا */
    private function form(Product $product, array $over = []): array
    {
        return array_merge([
            'name' => $product->name, 'name_en' => (string) $product->name_en, 'price' => (float) $product->price,
            'cost' => (float) $product->cost, 'quantity' => (int) $product->quantity,
        ], $over);
    }

    /* ═══════════════════════ الصنف ═══════════════════════ */

    public function test_a_new_product_without_an_english_name_keeps_none_and_shows_as_written(): void
    {
        $this->post(route('admin.products.store'), ['name' => 'باقة ورد أحمر', 'price' => 15])->assertSessionHasNoErrors();

        $product = Product::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertNull($product->name_en);

        $this->withSession(['locale' => 'en'])->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.0.label', 'باقة ورد أحمر'));
    }

    public function test_a_new_product_keeps_the_english_name_its_merchant_wrote(): void
    {
        $this->post(route('admin.products.store'), ['name' => 'باقة ورد أحمر', 'name_en' => 'Signature Red', 'price' => 15])->assertSessionHasNoErrors();

        $this->assertSame('Signature Red', Product::where('business_id', $this->shop->id)->value('name_en'));
    }

    public function test_editing_a_product_keeps_its_stored_english_name_and_never_refills_a_cleared_one(): void
    {
        // قديمٌ ملأه المعجمُ قبل القرار — يبقى كما خُزّن
        $old = $this->product(['name_en' => 'Red rose bouquet']);
        $this->put(route('admin.products.update', $old->id), $this->form($old, ['price' => 18]))->assertSessionHasNoErrors();
        $this->assertSame('Red rose bouquet', $old->fresh()->name_en);
        $this->assertEqualsWithDelta(18, (float) $old->fresh()->price, 0.0001);

        // وما مسحه التاجرُ يبقى ممسوحًا — لا يعود إليه من المعجم
        $this->put(route('admin.products.update', $old->id), $this->form($old->fresh(), ['name_en' => '']))->assertSessionHasNoErrors();
        $this->assertNull($old->fresh()->name_en);

        // وقديمٌ بلا اسمٍ إنجليزيّ يُحفظ فلا يُترجم له
        $bare = $this->product(['name' => 'باقة ورد أحمر صغيرة', 'sku' => 'BARE-1']);
        $this->put(route('admin.products.update', $bare->id), $this->form($bare, ['price' => 16]))->assertSessionHasNoErrors();
        $this->assertNull($bare->fresh()->name_en);
    }

    public function test_a_product_is_found_by_either_name(): void
    {
        $product = $this->product(['name' => 'باقة الربيع', 'name_en' => 'Spring Bouquet']);

        foreach (['spring', 'الربيع'] as $q) {
            $this->get(route('admin.products.index', ['q' => $q]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('products.0.id', $product->id));
        }
    }

    /* ═══════════════════════ الإضافة ═══════════════════════ */

    public function test_a_new_addon_keeps_no_invented_english_name_and_keeps_a_written_one(): void
    {
        $bare = $this->postJson(route('admin.products.addons.store'), ['name' => 'تغليف', 'price' => 1])->assertOk()->json('addon.value');
        $this->assertNull(Addon::findOrFail($bare)->name_en);

        $named = $this->postJson(route('admin.products.addons.store'), ['name' => 'شوكولاتة', 'name_en' => 'Belgian Chocolate', 'price' => 3])->assertOk()->json('addon.value');
        $this->assertSame('Belgian Chocolate', Addon::findOrFail($named)->name_en);
    }

    public function test_editing_an_addon_keeps_its_stored_english_name_and_never_refills_a_cleared_one(): void
    {
        $old = Addon::create(['business_id' => $this->shop->id, 'name' => 'تغليف', 'name_en' => 'Wrapping', 'price' => 1, 'active' => true]);

        $this->putJson(route('admin.products.addons.update', $old->id), ['name' => 'تغليف', 'price' => 1.5])->assertOk();
        $this->assertSame('Wrapping', $old->fresh()->name_en, 'لم يُرسَل فلم يُمسّ');

        $this->putJson(route('admin.products.addons.update', $old->id), ['name' => 'تغليف', 'name_en' => '', 'price' => 1.5])->assertOk();
        $this->assertNull($old->fresh()->name_en);
    }

    /** والإضافةُ التي تُنشأ مع تركيب الصنف — البابُ الثالث — كذلك */
    public function test_an_addon_born_with_a_products_composition_is_not_translated_either(): void
    {
        $this->post(route('admin.products.store'), [
            'name' => 'باقة الحفل', 'price' => 20,
            'composition' => ['new_addons' => [['name' => 'تغليف', 'price' => 1, 'private' => false]]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Addon::where('business_id', $this->shop->id)->where('name', 'تغليف')->value('name_en'));
    }

    /* ═══════════════════════ التاريخ والطباعة ═══════════════════════ */

    /**
     * لقطاتُ البيع والفاتورة لا يمسّها تعديلُ الكتالوج — ولا ما يُطبع منها.
     *
     * طلبٌ بِيعت فيه باقةٌ بشوكولاتتين، وفاتورةُ عميلٍ منه. ثمّ يُعاد حفظُ
     * الصنف والإضافة في الكتالوج بأسماءٍ أخرى ويُمسح اسمُهما الإنجليزيّ:
     * صفوفُ البيع والفاتورة حرفًا بحرف، والإيصالُ الإنجليزيّ وورقةُ التجهيز
     * وصفحةُ الفاتورة كما كانت.
     */
    public function test_sales_invoices_and_their_papers_keep_their_snapshots(): void
    {
        $bouquet = $this->product(['name' => 'باقة ورد', 'name_en' => 'Rose Bouquet', 'price' => 25]);
        $chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'price' => 3.5, 'active' => true]);
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-'.uniqid(), 'customer_id' => $customer->id,
            'status' => OrderStatus::CONFIRMED, 'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 32, 'total' => 32, 'ordered_at' => now(), 'fulfillment_type' => 'pickup',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $bouquet->id, 'name' => 'باقة ورد',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 32, 'addons_total' => 7,
        ]);
        OrderItemAddon::create(['order_item_id' => $item->id, 'addon_id' => $chocolate->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'unit_price' => 3.5, 'quantity' => 2, 'total' => 7]);
        $invoice = CustomerInvoices::create($this->shop->id, $customer, ['issued_at' => '2026-10-20'], [
            ['product_id' => $bouquet->id, 'description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 25, 'details' => ['label_en' => 'Rose Bouquet']],
            ['addon_id' => $chocolate->id, 'description' => 'شوكولاتة', 'quantity' => 2, 'unit_price' => 3.5],
        ]);

        $rows = fn () => [
            DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('order_item_addons')->where('order_item_id', $item->id)->get()->map(fn ($r) => (array) $r)->all(),
            CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)->orderBy('id')->get()->map->getAttributes()->all(),
            DB::table('customer_invoices')->where('id', $invoice->id)->first(['subtotal', 'tax_total', 'total']),
        ];
        $papers = function () use ($order, $invoice) {
            app()->setLocale('en');
            $fresh = $order->fresh('items.addons');
            $out = [
                ItemAddons::of($fresh->items->first())['rows'][0]['name'],
                PdfController::saleHtml($this->shop->id, $fresh, true)['html'],
                PrepSheet::forOrder($fresh, []),
            ];
            app()->setLocale('ar');

            return $out;
        };
        $invoicePage = fn () => $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props']['invoice']['items'];

        [$before, $printed, $shown] = [$rows(), $papers(), $invoicePage()];
        $this->assertSame('Chocolate', $printed[0]);

        // الكتالوجُ يتبدّل: أسماءٌ أخرى، والإنجليزيُّ يُمسح
        $this->put(route('admin.products.update', $bouquet->id), $this->form($bouquet, ['name' => 'باقة كبرى', 'name_en' => '']))->assertSessionHasNoErrors();
        $this->putJson(route('admin.products.addons.update', $chocolate->id), ['name' => 'شوكولاتة فاخرة', 'name_en' => '', 'price' => 4])->assertOk();
        $this->assertNull($bouquet->fresh()->name_en);
        $this->assertNull($chocolate->fresh()->name_en);

        $this->assertEquals($before, $rows(), 'صفوفُ البيع والفاتورة لم تُمسّ');
        $this->assertSame($printed, $papers(), 'الإيصالُ وورقةُ التجهيز كما طُبعا');
        $this->assertSame($shown, $invoicePage(), 'وصفحةُ الفاتورة كما كانت');
    }
}
