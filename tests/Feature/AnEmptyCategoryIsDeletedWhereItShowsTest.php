<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StoreHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * القسمُ الفارغ يُحذف من المحرّر — والقسمُ الذي يشير إليه صنفٌ لا يُحذف.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * «خدمات» و«عروض» من تهيئة النشاط بقيتا في قائمة سعود فارغتين، ولا بابَ
 * لحذف قسم. والخطرُ في الباب الجديد أن يُفرغ عمودَ صنفٍ قائم
 * (`nullOnDelete`) فيعود الصنفُ يومًا بلا قسم — مطفأً كان أو محذوفًا في السلّة.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - الفارغُ يُحذف، ويُسجَّل في النشاط.
 *   - فيه صنفٌ مطفأ أو غيرُ منشور أو في السلّة: يُردّ بسببه بالعربيّة ولا يُمسّ.
 *   - قسمُ متجرٍ آخر: ٤٠٤. وصنفُ متجرٍ آخر مربوطٌ به خطأً: يُردّ ولا يُمسّ صفُّه.
 *   - قسمٌ محذوفٌ كان اختصارًا في رأس المتجر: الرفُّ يفتح بلا عطب.
 */
class AnEmptyCategoryIsDeletedWhereItShowsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->shop('RIBBON', 'ribbon');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
    }

    private function shop(string $name, string $slug): Business
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    private function product(Business $shop, Category $category, array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $shop->id, 'name' => 'صنف', 'price' => 10, 'category_id' => $category->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function destroy(Category $category)
    {
        return $this->actingAs($this->owner)->deleteJson(route('admin.products.categories.destroy', $category->id));
    }

    public function test_an_empty_category_is_deleted_and_logged(): void
    {
        $services = Category::create(['business_id' => $this->shop->id, 'name' => 'خدمات']);

        $this->destroy($services)->assertOk()->assertJson(['ok' => true, 'id' => $services->id]);

        $this->assertNull(Category::find($services->id));
        $this->assertTrue(DB::table('activity_logs')->where('description', 'حذف قسم «خدمات»')->exists(), 'الحذفُ لم يُسجَّل');
    }

    /**
     * والمخفيُّ صنفٌ قائم: مطفأ، أو غيرُ منشور، أو في السلّة — كلٌّ يمنع الحذف.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function hiddenProducts(): array
    {
        return [
            'مطفأ' => [['active' => false], false],
            'غير منشور' => [['published' => false], false],
            'في السلّة' => [[], true],
        ];
    }

    #[DataProvider('hiddenProducts')]
    public function test_a_category_with_any_product_is_refused_and_untouched(array $over, bool $trashed): void
    {
        $offers = Category::create(['business_id' => $this->shop->id, 'name' => 'عروض']);
        $product = $this->product($this->shop, $offers, $over);
        if ($trashed) {
            $product->delete();
        }

        $this->destroy($offers)->assertStatus(422)->assertJsonPath(
            'errors.category.0',
            'لا تُحذف فئةٌ فيها منتجات — حتّى المطفأة أو غير المنشورة أو المحذوفة. انقلها إلى فئةٍ أخرى أوّلًا.',
        );

        $this->assertNotNull(Category::find($offers->id));
        $this->assertSame($offers->id, (int) DB::table('products')->where('id', $product->id)->value('category_id'), 'الصنفُ فقد قسمَه');
    }

    public function test_another_shops_category_is_not_found(): void
    {
        $other = $this->shop('متجر آخر', 'other');
        $theirs = Category::create(['business_id' => $other->id, 'name' => 'فئتهم']);

        $this->destroy($theirs)->assertNotFound();

        $this->assertNotNull(Category::find($theirs->id));
    }

    /** وصنفُ متجرٍ آخر مربوطٌ بقسمي خطأً — لا يُفرَغ عمودُه من هنا */
    public function test_a_stray_product_of_another_shop_still_blocks_and_stays(): void
    {
        $mine = Category::create(['business_id' => $this->shop->id, 'name' => 'فارغة عندي']);
        $other = $this->shop('متجر آخر', 'other');
        $stray = $this->product($other, $mine);

        $this->destroy($mine)->assertStatus(422);

        $this->assertSame($mine->id, (int) DB::table('products')->where('id', $stray->id)->value('category_id'));
    }

    /** والقسمُ المحذوفُ كان اختصارًا في رأس المتجر — فالرفُّ يفتح ويتخطّاه */
    public function test_a_deleted_shortcut_category_leaves_the_shop_working(): void
    {
        $roses = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        $this->product($this->shop, $roses, ['name' => 'باقة الربيع']);
        $empty = Category::create(['business_id' => $this->shop->id, 'name' => 'عروض']);
        MarketingSettings::save($this->shop->id, 'website', [StoreHeader::SHORTCUTS => $empty->id.','.$roses->id]);

        $this->destroy($empty)->assertOk();

        $this->get('/s/ribbon/shop')->assertOk()->assertSee('باقة الربيع');
        $this->get('/s/ribbon')->assertOk();
    }
}
