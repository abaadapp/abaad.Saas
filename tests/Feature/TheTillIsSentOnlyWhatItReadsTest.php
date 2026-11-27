<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ‏الصندوقُ يُرسَل إليه ما يقرؤه وحده من الأصناف — وما يقرؤه هو هو حرفًا.
 *
 * ═══ ما كان ═══
 *
 * شاشةُ الصندوق تأخذ صفَّ الصنف المشترك (`Demo::products`) كما هو: ومعه أربعةُ
 * حقولٍ لا يقرؤها (`published`، `cat_label`، `description_pos_enabled`،
 * `description_web_enabled`)، واسمٌ معروضٌ يساوي الاسمَ نفسَه في كلّ صنفٍ عربيّ.
 * ألفا صنفٍ = نحو 1.3MB في كلّ فتحٍ للصندوق وكلّ إعادة تحميلٍ للأصناف.
 *
 * ═══ ما صار ═══
 *
 * `Demo::tillProducts` يبني الصفَّ نفسَه بالاستعلامات نفسِها ثمّ يُسقط منه ما
 * لا يقرؤه الصندوق (`Demo::forTill`)، والاسمَ المعروض حيث يساوي الاسم حرفًا —
 * والصندوقُ يقرأ غائبَه الاسم (`lib/till-products.ts`). فالسعرُ والضريبةُ والمخزونُ
 * والمقاساتُ والإضافاتُ وأهليّةُ البيع لا تتغيّر قيمةً. والقائمةُ المشتركة
 * (شاشةُ المشتريات وما يبحث بالمعرّف) كما كانت.
 *
 * ويقابله في Vitest `the-till-reads-the-same-products-from-fewer-bytes`.
 */
class TheTillIsSentOnlyWhatItReadsTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_FOR_TILL = ['published', 'cat_label', 'description_pos_enabled', 'description_web_enabled'];

    private Business $shop;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@rose.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $flowers = Category::create(['business_id' => $this->shop->id, 'name' => 'ورود', 'name_en' => 'Flowers']);
        $make = fn (array $a) => Product::create($a + ['business_id' => $this->shop->id, 'price' => 10, 'cost' => 4, 'quantity' => 20, 'alert_qty' => 2, 'active' => true]);

        // ‏ضريبةُ المتجر، وصفرٌ محدَّد، ونسبةٌ محدَّدة — ثلاثُ قواعد لا تُخلط
        $make(['name' => 'باقة تتبع المتجر', 'category_id' => $flowers->id, 'tax' => null, 'sku' => 'R-1', 'barcode' => '6290000000011']);
        $make(['name' => 'باقة بلا ضريبة', 'name_en' => 'Zero bouquet', 'tax' => 0, 'price' => 12.345]);
        $make(['name' => 'باقة بخمسة', 'tax' => 5, 'discount' => 1.5, 'price' => 7.777]);
        // ‏مقاسات، وإضافاتٌ مسموحة، ووصفة
        $sized = $make(['name' => 'باقة بمقاسات', 'name_en' => 'Sized bouquet']);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $sized->id, 'name' => 'صغير', 'price' => 15, 'active' => true]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $sized->id, 'name' => 'كبير', 'name_en' => 'Large', 'price' => 30.5, 'active' => true]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $sized->id, 'name' => 'موقوف', 'price' => 99, 'active' => false]);
        $card = Addon::create(['business_id' => $this->shop->id, 'name' => 'بطاقة', 'price' => 1.25, 'active' => true]);
        Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 3.5, 'active' => true]);
        $card->update(['scope' => Addon::SCOPE_SELECTED]);
        DB::table('product_addons')->insert(['business_id' => $this->shop->id, 'product_id' => $sized->id, 'addon_id' => $card->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $rose = $make(['name' => 'وردة', 'quantity' => 100]);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $sized->id, 'component_product_id' => $rose->id, 'quantity' => 6, 'wastage_percent' => 0]);
        // ‏موقوف، وللموقع وحده، وخدمةٌ لا تُعدّ، ووصفٌ مفتوحٌ للصندوق ووصفٌ مغلقٌ عنه
        $make(['name' => 'باقة موقوفة', 'active' => false]);
        $make(['name' => 'باقة الموقع', 'pos_enabled' => false, 'published' => true]);
        $make(['name' => 'تغليف', 'tracks_stock' => false, 'quantity' => 0]);
        $make(['name' => 'باقة موصوفة', 'description' => 'ورد أحمر طازج', 'description_en' => 'Fresh red roses', 'description_pos_enabled' => true]);
        $make(['name' => 'باقة وصفها للموقع', 'description' => 'للموقع وحده', 'description_pos_enabled' => false, 'description_web_enabled' => true]);
        // ‏ورصيدُ فرعين مختلف
        foreach (Product::where('business_id', $this->shop->id)->get() as $i => $p) {
            BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'product_id' => $p->id, 'quantity' => 10 + $i]);
            BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->salalah->id, 'product_id' => $p->id, 'quantity' => $i % 3]);
        }

        // ‏ومتجرٌ آخر بصنفٍ يحمل الاسمَ نفسَه
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        Product::create(['business_id' => $other->id, 'name' => 'باقة تتبع المتجر', 'price' => 1, 'cost' => 1, 'quantity' => 5, 'active' => true]);
    }

    private function in(Branch $branch, string $locale = 'ar'): void
    {
        $this->actingAs($this->owner);
        $this->activatePosDevice($this->shop->id, $branch->id);
        session(['current_branch' => $branch->id, 'locale' => $locale]);
        app()->setLocale($locale);
    }

    /** @return array<int, array<string, mixed>> */
    private function tillPage(): array
    {
        return $this->tillProductsOf($this->get(route('pos.index')));
    }

    public function test_the_till_gets_the_shared_rows_without_only_what_it_does_not_read_in_every_branch_and_language(): void
    {
        foreach ([[$this->muscat, 'ar'], [$this->salalah, 'ar'], [$this->muscat, 'en']] as [$branch, $locale]) {
            $this->in($branch, $locale);
            $till = $this->tillPage();
            $shared = Demo::products($branch->id);

            $this->assertCount(count($shared), $till, "{$branch->name}/{$locale}: صنفٌ زاد أو نقص");
            $this->assertSame(array_column($shared, 'id'), array_column($till, 'id'), 'الترتيبُ تغيّر');

            foreach ($shared as $i => $row) {
                $sent = $till[$i];
                $dropped = array_diff(array_keys($row), array_keys($sent));
                $expected = self::NOT_FOR_TILL;
                if ($row['label'] === $row['name']) {
                    $expected[] = 'label';
                }
                $this->assertEqualsCanonicalizing($expected, array_values($dropped), "«{$row['name']}»: سقط غيرُ ما لا يقرؤه الصندوق");

                /*
                 * ‏وما زاد سعرُ البيع وحده، للمخصوم وحده — من الدالّة التي يبيع بها
                 * الخادم (`TheTillSellsADiscountedProductAtTheServersPriceTest`).
                 */
                $product = Product::findOrFail($row['id']);
                $added = array_values(array_diff(array_keys($sent), array_keys($row)));
                $this->assertSame($product->discountRate() > 0 ? ['sale_price'] : [], $added, "«{$row['name']}»: زاد غيرُ سعر البيع");
                if ($added) {
                    $this->assertSame($product->sellingPrice(), $sent['sale_price']);
                }

                // ‏وما بقي هو هو حرفًا — قيمةً ونوعًا
                foreach (array_diff_key($sent, ['sale_price' => true]) as $key => $value) {
                    $this->assertSame($row[$key], $value, "«{$row['name']}».{$key} تغيّر");
                }
                // ‏والاسمُ المعروض كما يقرؤه الصندوق (`fromTill`): الحاضرُ، أو الاسمُ حين غاب
                $this->assertSame($row['label'], $sent['label'] ?? $sent['name']);
            }
        }
    }

    public function test_what_decides_a_sale_reaches_the_till_value_for_value(): void
    {
        $this->in($this->salalah);
        $till = collect($this->tillPage())->keyBy('name');

        // ‏غيرُ المحدَّد نسبةُ المتجر كما يحتسبها الخادم (`Vat::rateForEach`)، والصفرُ المحدَّد صفر — لا يختلطان
        $this->assertEquals(5, $till['باقة تتبع المتجر']['tax'], 'غيرُ المحدَّد يتبع المتجر — لا صفر');
        $this->assertEquals(0, $till['باقة بلا ضريبة']['tax'], 'الصفرُ المحدَّد ليس «يتبع المتجر»');
        $this->assertEquals(5, $till['باقة بخمسة']['tax']);
        $this->assertSame(1.5, $till['باقة بخمسة']['discount']);
        $this->assertSame(7.777, $till['باقة بخمسة']['price']);
        $this->assertSame([15.0, 30.5], array_column($till['باقة بمقاسات']['variants'], 'price'), 'المقاسُ الموقوف لا يُرسل');
        // ‏البطاقةُ مربوطةٌ به، والشوكولاتةُ عامّة لكلّ صنف — كما يقبلها الخادم (`ProductAddons::for`)
        $this->assertEqualsCanonicalizing(Addon::where('business_id', $this->shop->id)->pluck('id')->all(), $till['باقة بمقاسات']['addon_ids']);
        $this->assertTrue($till['باقة بمقاسات']['has_recipe']);
        $this->assertFalse($till['باقة موقوفة']['active'], 'الموقوفُ يصل موقوفًا — لا قابلًا للبيع');
        $this->assertFalse($till['باقة الموقع']['pos_enabled'], 'ما للموقع وحده يصل مغلقًا للصندوق');
        $this->assertFalse($till['تغليف']['tracks_stock']);
        $this->assertSame('ورد أحمر طازج', $till['باقة موصوفة']['pos_description']);
        $this->assertNull($till['باقة وصفها للموقع']['pos_description'], 'وصفٌ لم يُفتح للصندوق لا يصله');
        // ‏ورصيدُ فرع الجهاز لا مجموعُ الشركة
        $this->assertSame([0, 1, 2], array_slice(array_column(array_values($till->all()), 'qty'), 0, 3));
    }

    public function test_an_english_till_keeps_the_english_name_and_an_arabic_one_reads_the_name(): void
    {
        $this->in($this->muscat, 'en');
        $en = collect($this->tillPage())->keyBy('name');
        $this->assertSame('Zero bouquet', $en['باقة بلا ضريبة']['label']);
        $this->assertArrayNotHasKey('label', $en['باقة بخمسة'], 'بلا اسمٍ إنجليزيّ يُعرض الاسمُ نفسُه');

        $this->in($this->muscat, 'ar');
        $ar = collect($this->tillPage())->keyBy('name');
        $this->assertArrayNotHasKey('label', $ar['باقة بلا ضريبة']);
    }

    public function test_the_purchase_screen_and_the_shared_list_keep_every_field(): void
    {
        $this->actingAs($this->owner);
        session(['current_branch' => $this->muscat->id]);

        foreach (Demo::products() as $row) {
            foreach ([...self::NOT_FOR_TILL, 'label'] as $key) {
                $this->assertArrayHasKey($key, $row, "القائمةُ المشتركة فقدت {$key}");
            }
        }

        $rows = $this->get(route('admin.purchases.create'))->assertOk()->viewData('page')['props']['products'];
        $this->assertNotEmpty($rows);
        foreach ([...self::NOT_FOR_TILL, 'label'] as $key) {
            $this->assertArrayHasKey($key, $rows[0], "شاشةُ المشتريات فقدت {$key}");
        }
    }

    public function test_no_other_shops_product_reaches_the_till(): void
    {
        $this->in($this->muscat);
        $ids = array_column($this->tillPage(), 'id');

        $this->assertSame(
            Product::where('business_id', $this->shop->id)->orderBy('id')->pluck('id')->all(),
            $ids,
        );
    }

    public function test_the_till_list_costs_the_same_queries_as_the_shared_one(): void
    {
        $this->in($this->muscat);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Demo::products($this->muscat->id);
        $shared = count(DB::getQueryLog());
        DB::flushQueryLog();
        Demo::tillProducts($this->muscat->id);
        $till = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($shared, $till);
    }

    /**
     * ‏وما لا يُرسل لا يقرؤه كودُ الصندوق — فإن احتاجه يومًا عاد إلى `Demo::forTill` قبله.
     *
     * يُمسح كلُّ ملفٍّ خارج شاشات اللوحة والمنصّة والموقع (حيث يُقرأ من القائمة
     * المشتركة). وتعريفُ الأنواع وملفُّ الصندوق نفسُه يذكرانها لا يقرآنها.
     */
    public function test_no_till_code_reads_what_the_till_is_not_sent(): void
    {
        $root = resource_path('js');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $readers = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (! preg_match('/\.(ts|tsx)$/', $path)
                || preg_match('#^Pages/(Admin|Platform|Storefront|Website)/#', $path)
                || in_array($path, ['types/models.d.ts', 'lib/till-products.ts'], true)) {
                continue;
            }
            foreach (self::NOT_FOR_TILL as $field) {
                if (preg_match('/\b'.$field.'\b/', (string) file_get_contents($file->getPathname()))) {
                    $readers[] = "{$path} → {$field}";
                }
            }
        }

        $this->assertSame([], $readers, 'كودٌ خارج اللوحة يقرأ حقلًا لا يُرسل للصندوق');
    }

    public function test_dropping_only_removes_keys_and_never_changes_a_value(): void
    {
        $row = [
            'id' => 7, 'name' => 'باقة', 'name_en' => null, 'label' => 'باقة', 'cat' => 'ورود', 'cat_label' => 'Flowers',
            'price' => 1.005, 'tax' => null, 'published' => true, 'description_pos_enabled' => false, 'description_web_enabled' => true,
            'pos_description' => null, 'variants' => [], 'addon_ids' => [3],
        ];

        $till = Demo::forTill($row);

        $this->assertSame(['id', 'name', 'name_en', 'cat', 'price', 'tax', 'pos_description', 'variants', 'addon_ids'], array_keys($till));
        foreach ($till as $k => $v) {
            $this->assertSame($row[$k], $v);
        }
        $this->assertSame('Rose', Demo::forTill(['name' => 'باقة', 'label' => 'Rose'])['label']);
        // ‏ومسافةٌ أو حرفٌ يفرّقهما: يبقى كما هو
        $this->assertSame('باقة ', Demo::forTill(['name' => 'باقة', 'label' => 'باقة '])['label']);
    }
}
