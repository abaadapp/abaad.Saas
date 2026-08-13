<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ضغطاتٌ توفّر يومًا من العمل.
 *
 * كان إدخال قميصٍ بأربعة مقاسات يعني ملء عشرة حقول أربع مرّات، وجردُ عشرين
 * صنفًا أربعين نقرة، ورفعُ أسعار قسمٍ خمسةً بالمئة فتحَ كل صنفٍ على حدة.
 */
class ProductActionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $main;

    private Branch $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->other = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'قميص',
            'price' => 10, 'cost' => 6, 'quantity' => 20, 'alert_qty' => 5, 'active' => true,
            'sku' => 'SH-'.uniqid(), 'barcode' => '628'.random_int(1000000000, 9999999999),
        ], $over));
    }

    /* ------------------------------- النسخ ------------------------------- */

    public function test_a_copy_keeps_the_details_and_takes_new_codes(): void
    {
        $source = $this->product(['price' => 12.5, 'cost' => 7, 'discount' => 10]);

        $this->post(route('admin.products.duplicate', $source->id))->assertRedirect();

        $copy = Product::where('id', '!=', $source->id)->latest('id')->first();

        $this->assertSame(12.5, (float) $copy->price);
        $this->assertSame(10.0, (float) $copy->discount);
        $this->assertNotSame($source->sku, $copy->sku, 'نُسخ الرمز فتكرّر');
        $this->assertNotSame($source->barcode, $copy->barcode, 'نُسخ الباركود فصار صنفان بباركود واحد');
    }

    public function test_a_copy_starts_with_no_stock(): void
    {
        // نسخُ الرصيد يخلق بضاعةً لا وجود لها على الرفّ
        $source = $this->product(['quantity' => 40]);

        $this->post(route('admin.products.duplicate', $source->id));

        $this->assertSame(0, (int) Product::where('id', '!=', $source->id)->latest('id')->value('quantity'));
    }

    public function test_another_stores_product_cannot_be_copied(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'سرّهم', 'price' => 5, 'cost' => 3, 'quantity' => 1, 'active' => true,
        ]);

        $this->post(route('admin.products.duplicate', $theirs->id))->assertNotFound();
        $this->assertSame(1, Product::count());
    }

    /* --------------------------- التعديل السريع --------------------------- */

    public function test_a_quick_price_edit_saves(): void
    {
        $p = $this->product();

        $this->patch(route('admin.products.quick', $p->id), ['price' => 13.25])
            ->assertSessionHasNoErrors();

        $this->assertSame(13.25, (float) $p->fresh()->price);
    }

    public function test_a_quick_quantity_edit_keeps_the_books_balanced(): void
    {
        $p = $this->product(['quantity' => 20]);
        BranchStock::adjust($this->business->id, $this->main->id, $p->id, 12);
        BranchStock::adjust($this->business->id, $this->other->id, $p->id, 8);

        $this->patch(route('admin.products.quick', $p->id), ['quantity' => 30]);

        $this->assertSame(30, (int) $p->fresh()->quantity);
        $this->assertSame(30, (int) BranchStock::where('product_id', $p->id)->sum('quantity'), 'اختلّ توازن الفروع');
    }

    public function test_a_negative_quick_edit_is_refused(): void
    {
        $p = $this->product();

        $this->patch(route('admin.products.quick', $p->id), ['quantity' => -5])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(20, (int) $p->fresh()->quantity);
    }

    /* -------------------------- الإجراء الجماعي -------------------------- */

    public function test_bulk_deactivate_touches_only_the_chosen(): void
    {
        $a = $this->product();
        $b = $this->product();

        $this->post(route('admin.products.bulk'), ['action' => 'deactivate', 'ids' => [$a->id]]);

        $this->assertFalse((bool) $a->fresh()->active);
        $this->assertTrue((bool) $b->fresh()->active);
    }

    public function test_bulk_price_change_rounds_to_three_decimals(): void
    {
        $p = $this->product(['price' => 9.999]);

        $this->post(route('admin.products.bulk'), ['action' => 'price', 'ids' => [$p->id], 'percent' => 5]);

        $this->assertSame(10.499, (float) $p->fresh()->price);
    }

    public function test_a_typo_percent_is_refused(): void
    {
        // «٥٠٠» بدل «٥» تمسح تسعيرة متجر
        $p = $this->product();

        $this->post(route('admin.products.bulk'), ['action' => 'price', 'ids' => [$p->id], 'percent' => 5000])
            ->assertSessionHasErrors('percent');

        $this->assertSame(10.0, (float) $p->fresh()->price);
    }

    public function test_bulk_delete_goes_to_the_trash_not_the_void(): void
    {
        $p = $this->product();

        $this->post(route('admin.products.bulk'), ['action' => 'delete', 'ids' => [$p->id]]);

        $this->assertNull(Product::find($p->id));
        $this->assertTrue(Product::withTrashed()->find($p->id)->trashed());
    }

    public function test_a_bulk_action_never_reaches_another_store(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'سرّهم', 'price' => 5, 'cost' => 3, 'quantity' => 1, 'active' => true,
        ]);

        $this->post(route('admin.products.bulk'), ['action' => 'deactivate', 'ids' => [$theirs->id]]);

        $this->assertTrue((bool) $theirs->fresh()->active);
    }

    public function test_a_category_from_another_store_is_refused(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirCat = Category::create(['business_id' => $other->id, 'name' => 'أقسامهم']);
        $p = $this->product();

        $this->post(route('admin.products.bulk'), [
            'action' => 'category', 'ids' => [$p->id], 'category_id' => $theirCat->id,
        ]);

        $this->assertNull($p->fresh()->category_id);
    }

    /* ---------------------------- البحث والفرز ---------------------------- */

    public function test_the_search_finds_a_scanned_barcode(): void
    {
        // من اعتاد الماسح يمرّره هنا، فكان لا يجد شيئًا ويُدخل الصنف ثانيةً
        $p = $this->product(['barcode' => '6289999999999']);

        $rows = $this->get(route('admin.products.index', ['q' => '6289999999999']))
            ->assertOk()->viewData('page')['props']['products'];

        $this->assertCount(1, $rows);
        $this->assertSame($p->id, $rows[0]['id']);
    }

    public function test_dead_stock_lists_what_has_not_sold_in_ninety_days(): void
    {
        $moving = $this->product(['name' => 'يدور']);
        $stale = $this->product(['name' => 'راكد']);
        $old = $this->product(['name' => 'بيع قديمًا']);

        $recent = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000001',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => 'مكتمل',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10,
            'is_held' => false, 'ordered_at' => now()->subDays(3),
        ]);
        OrderItem::create(['order_id' => $recent->id, 'product_id' => $moving->id, 'name' => 'يدور', 'price' => 10, 'quantity' => 1, 'total' => 10]);

        $ancient = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000002',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => 'مكتمل',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10,
            'is_held' => false, 'ordered_at' => now()->subDays(200),
        ]);
        OrderItem::create(['order_id' => $ancient->id, 'product_id' => $old->id, 'name' => 'بيع قديمًا', 'price' => 10, 'quantity' => 1, 'total' => 10]);

        $rows = $this->get(route('admin.products.index', ['stock' => 'راكد']))
            ->assertOk()->viewData('page')['props']['products'];

        $names = array_map(fn ($r) => $r['name'], $rows);

        $this->assertContains('راكد', $names);
        $this->assertContains('بيع قديمًا', $names, 'بيعةٌ عمرها مئتا يوم ليست حركة');
        $this->assertNotContains('يدور', $names);
    }
}
