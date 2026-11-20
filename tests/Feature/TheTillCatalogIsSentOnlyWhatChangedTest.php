<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\ImageVariants;
use App\Support\TillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ‏‏كتالوجُ الصندوق: يأتي الجهازَ ما تغيّر وحده — ولا يفوته تغيير.
 *
 * ═══ ما كان ═══
 *
 * صفحةُ الصندوق تحمل الأصنافَ كلَّها في كلّ فتح (`Demo::tillProducts`): عند عشرة
 * آلاف صنف 4.5MB في 810ms، ولم يتغيّر منها شيء.
 *
 * ═══ ما صار (`App\Support\TillCatalog`) ═══
 *
 * الجهازُ يحفظ الكتالوج ويُعيد بصماتِه ورمزَه، فيُجاب بما تغيّر. وما يُثبته هذا
 * الملفّ: **ما يجمعه الجهازُ من الأجوبة يساوي صفوفَ صفحة الصندوق حرفًا** بعد كلّ
 * نوعٍ من التغيير — أيًّا كان بابُه، ولو `DB::table` لا يمسّ `updated_at` — وأنّ
 * ما يرسله الجهاز لا يقرأ متجرًا ولا فرعًا ولا لغةً غيرَ ما في جلسته.
 */
class TheTillCatalogIsSentOnlyWhatChangedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    private Category $flowers;

    /** @var list<Product> */
    private array $shelf = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['pos.catalog_release' => 'r1']);
        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->flowers = Category::create(['business_id' => $this->business->id, 'name' => 'ورد']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        // ‏‏أكثرُ من سلّة: مئةُ صنف — أربعُ سلال
        for ($k = 1; $k <= 100; $k++) {
            $this->shelf[] = Product::create([
                'business_id' => $this->business->id, 'category_id' => $this->flowers->id, 'name' => "صنف $k", 'name_en' => "Item $k",
                'price' => 2 + $k / 1000, 'cost' => 1, 'quantity' => 50 + $k, 'alert_qty' => 5, 'active' => true,
                'sku' => "SKU-$k", 'barcode' => sprintf('629%010d', $k),
            ]);
        }

        $this->actingAs($this->owner);
        $this->activatePosDevice($this->business->id, $this->muscat->id);
        session(['current_branch' => $this->muscat->id]);
        // ‏‏الجهازُ يفتح بعد أن هدأت الكتابة — والمؤشّراتُ لا يُوثق بها في ثانيتَي آخرِ كتابة (`SETTLED`)
        Carbon::setTestNow(now()->addSeconds(5));
    }

    private function scope(?int $branch = null, string $locale = 'ar'): string
    {
        return TillCatalog::scope($this->business->id, $branch ?? $this->muscat->id, $locale);
    }

    /** ‏‏سؤالُ الجهاز بما يحفظه */
    private function ask(?array $known = null, array $extra = [], ?string $scope = null)
    {
        $body = ['scope' => $scope ?? $this->scope()];
        if ($known) {
            $body += ['t' => $known['t'], 'b' => $known['b'], 'ih' => implode('', $known['ih']), 'oh' => implode('', $known['oh'])];
        }

        return $this->postJson(route('pos.catalog-feed'), $body + $extra);
    }

    /**
     * ‏‏ما يفعله الجهازُ بالجواب — بقواعده (`applyCatalog` في `lib/till-catalog.ts`).
     *
     * لا يُكتب جوابٌ على غير ما سأل عنه (`base`)، ولا صنفٌ من سلّةٍ لم تُعلَن،
     * والكاملُ يُعدّ: عددُه وبصماتُه بعدد سلاله.
     */
    private function apply(?array $known, array $d): array
    {
        if ($d['same'] ?? false) {
            $this->assertNotNull($known, '«كما هو» لجهازٍ لا يحفظ شيئًا');
            $this->assertSame($known['v'], $d['v']);

            return ['t' => $d['t']] + $known;
        }
        if ($d['full'] ?? false) {
            $this->assertCount($d['count'], $d['products']);
            $this->assertSame($d['b'] * TillCatalog::HASH, strlen($d['ih']));
            $this->assertSame($d['b'] * TillCatalog::HASH, strlen($d['oh']));

            return ['t' => $d['t'], 'v' => $d['v'], 'b' => $d['b'], 'ih' => str_split($d['ih'], TillCatalog::HASH), 'oh' => str_split($d['oh'], TillCatalog::HASH),
                'rows' => collect($d['products'])->keyBy('id')->all()];
        }
        $this->assertSame($known['v'], $d['base'], 'جوابٌ على غير ما سأل عنه الجهاز');
        $this->assertSame($known['b'], $d['b']);
        $declared = array_column($d['buckets'], 0);
        foreach ($d['rows'] as $i) {
            $this->assertContains($i, $declared, 'سلّةٌ بأصنافها ولم تُعلَن بصمتُها');
        }
        $rows = array_filter($known['rows'], fn ($r) => ! in_array($r['id'] % $known['b'], $d['rows'], true));
        foreach ($d['products'] as $r) {
            $this->assertContains($r['id'] % $known['b'], $d['rows'], 'صنفٌ من سلّةٍ لم تُعلَن');
            $rows[$r['id']] = $r;
        }
        [$ih, $oh] = [$known['ih'], $known['oh']];
        foreach ($d['buckets'] as [$i, $a, $o]) {
            [$ih[$i], $oh[$i]] = [$a, $o];
        }

        return ['t' => $d['t'], 'v' => $d['v'], 'b' => $known['b'], 'ih' => $ih, 'oh' => $oh, 'rows' => $rows];
    }

    /** ‏‏صفوفُ صفحة الصندوق الآن — المرجع (A) */
    private function page(): array
    {
        return collect(Demo::tillProducts($this->muscat->id))->keyBy('id')->all();
    }

    /** ‏‏والكميّةُ ليست من الكتالوج: لها تغذيتُها (`TillStock`) */
    private function withoutStock(array $rows): array
    {
        ksort($rows);

        return array_map(fn ($r) => array_diff_key($r, ['qty' => 1, 'stock_status' => 1]), $rows);
    }

    /** ‏‏ما عند الجهاز (B) يساوي الصفحة (A) — حرفًا، إلّا الكميّة */
    private function assertSameAsThePage(array $known, string $what = ''): void
    {
        $this->assertSame($this->withoutStock($this->page()), $this->withoutStock($known['rows']), $what);
    }

    /** ‏‏جهازٌ أخذ الكتالوجَ للتوّ */
    private function fresh(): array
    {
        return $this->apply(null, $this->ask()->assertOk()->json());
    }

    /* ─────────────────────────── الأساس ─────────────────────────── */

    /** ‏‏أوّلُ فتحٍ بلا نسخة: الكتالوجُ كلُّه، وهو صفوفُ صفحة الصندوق نفسُها */
    public function test_a_device_without_a_copy_gets_the_till_pages_own_rows(): void
    {
        $d = $this->ask()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertTrue($d['full']);
        $this->assertSame(4, $d['b']);
        $this->assertSame(100, $d['count']);
        $this->assertSame(array_values($this->page()), $d['products']);
        $this->assertSame($this->scope(), $d['scope']);
    }

    /** ‏‏ولم يتغيّر شيء: «كما هو» بلا صنف — ولا تُقرأ الأصنافُ ما دامت المؤشّراتُ لم تتحرّك */
    public function test_nothing_changed_is_answered_with_nothing_and_without_reading_the_shelf(): void
    {
        $known = $this->fresh();
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            $seen[] = $q->sql;
        });

        $d = $this->ask($known)->assertOk()->json();

        $this->assertTrue($d['same']);
        $this->assertArrayNotHasKey('products', $d);
        $this->assertSame([], array_values(array_filter($seen, fn ($s) => preg_match('/^select \* from "products"/', $s))), 'قُرئت الأصنافُ ولم يتحرّك مؤشّر');
    }

    /* ─────────────────────────── كلُّ تغيير ─────────────────────────── */

    /**
     * ‏‏كلُّ ما يغيّر صفَّ الصنف يصل الجهاز — ووحدَه: سلّتُه لا الكتالوج.
     *
     * @return array<string, array{0: \Closure(self): void, 1: int|null}> ما يُفعل، وكم سلّةً تُرسل أصنافُها (`null` الكلّ)
     */
    public static function changes(): array
    {
        return [
            'صنفٌ جديد' => [fn (self $t) => Product::create(['business_id' => $t->business->id, 'name' => 'جديد', 'price' => 3, 'quantity' => 4, 'active' => true]), 1],
            'اسمُه' => [fn (self $t) => $t->shelf[4]->update(['name' => 'اسمٌ آخر']), 1],
            'ترجمتُه' => [fn (self $t) => $t->shelf[4]->update(['name_en' => 'Another name']), 1],
            'سعرُه' => [fn (self $t) => $t->shelf[4]->update(['price' => 9.876]), 1],
            'خصمُه' => [fn (self $t) => $t->shelf[4]->update(['discount' => 15]), 1],
            'ضريبتُه' => [fn (self $t) => $t->shelf[4]->update(['tax' => 0]), 1],
            'إيقافُه' => [fn (self $t) => $t->shelf[4]->update(['active' => false]), 1],
            'إغلاقُه للصندوق' => [fn (self $t) => $t->shelf[4]->update(['pos_enabled' => false]), 1],
            'حذفُه' => [fn (self $t) => $t->shelf[4]->delete(), 1],
            'حذفُه من القاعدة' => [fn (self $t) => DB::table('products')->where('id', $t->shelf[4]->id)->delete(), 1],
            'باركودُه' => [fn (self $t) => $t->shelf[4]->update(['barcode' => '6290000999999']), 1],
            'رمزُه' => [fn (self $t) => $t->shelf[4]->update(['sku' => 'NEW-SKU']), 1],
            'صورتُه' => [fn (self $t) => $t->shelf[4]->update(['image' => 'products/1/new.jpg']), 1],
            'وصفُه للصندوق' => [fn (self $t) => $t->shelf[4]->update(['description' => 'وصف', 'description_pos_enabled' => true]), 1],
            'حدُّ تنبيهه' => [fn (self $t) => $t->shelf[4]->update(['alert_qty' => 1]), 1],
            'ارتباطُه بالمخزون' => [fn (self $t) => $t->shelf[4]->update(['tracks_stock' => false]), 1],
            'قسمُه' => [fn (self $t) => $t->shelf[4]->update(['category_id' => Category::create(['business_id' => $t->business->id, 'name' => 'هدايا'])->id]), 1],
            'اسمُ قسمه' => [fn (self $t) => $t->flowers->update(['name' => 'زهور']), null],
            'مقاسٌ جديد' => [fn (self $t) => ProductVariant::create(['business_id' => $t->business->id, 'product_id' => $t->shelf[4]->id, 'name' => 'كبير', 'price' => 7, 'active' => true]), 1],
            'وصفةٌ' => [fn (self $t) => RecipeItem::create(['business_id' => $t->business->id, 'product_id' => $t->shelf[4]->id, 'component_product_id' => $t->shelf[5]->id, 'quantity' => 1]), 1],
            'ضريبةُ المتجر' => [fn (self $t) => Setting::where('business_id', $t->business->id)->where('key', 'vat_rate')->update(['value' => '7']), null],
            'إطفاءُ ضريبة المتجر' => [fn (self $t) => Setting::where('business_id', $t->business->id)->where('key', 'vat_enabled')->update(['value' => '0']), null],
        ];
    }

    #[DataProvider('changes')]
    public function test_every_change_to_a_till_row_reaches_the_device_and_only_its_bucket(\Closure $change, ?int $buckets): void
    {
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSecond());

        $change($this);
        $d = $this->ask($known)->assertOk()->json();
        $known = $this->apply($known, $d);

        $this->assertSameAsThePage($known);
        $this->assertArrayHasKey('base', $d, 'تغييرٌ لم يصل، أو وصل الكتالوجُ كلُّه');
        $this->assertSame($buckets ?? 4, count($d['rows']));
        // ‏‏والجوابُ التالي «كما هو»
        $this->assertTrue($this->ask($known)->json('same'));
    }

    /**
     * ‏‏مقاسٌ يُعدَّل ويُوقف ويُحذف، وإضافةٌ تُربط وتُفكّ بـ`DB::table` (هكذا تكتبها
     * شاشاتُ الإضافات كلُّها، بلا `updated_at`) — وإضافةُ المتجر نفسُها تُوقف.
     */
    public function test_variants_and_addon_links_written_any_way_reach_the_device(): void
    {
        $size = ProductVariant::create(['business_id' => $this->business->id, 'product_id' => $this->shelf[1]->id, 'name' => 'وسط', 'price' => 4, 'active' => true]);
        $wrap = Addon::create(['business_id' => $this->business->id, 'name' => 'تغليف', 'price' => 1, 'active' => true, 'scope' => 'selected']);
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSecond());

        foreach ([
            'سعرُ المقاس' => fn () => $size->update(['price' => 4.5]),
            'باركودُ المقاس' => fn () => $size->update(['barcode' => '6291111111111']),
            'إيقافُ المقاس' => fn () => $size->update(['active' => false]),
            'حذفُ المقاس' => fn () => $size->delete(),
            'ربطُ إضافة' => fn () => DB::table('product_addons')->insert(['business_id' => $this->business->id, 'product_id' => $this->shelf[2]->id, 'addon_id' => $wrap->id, 'sort_order' => 1]),
            'فكُّها' => fn () => DB::table('product_addons')->where('product_id', $this->shelf[2]->id)->delete(),
            'ربطُها ثانيةً' => fn () => DB::table('product_addons')->insert(['business_id' => $this->business->id, 'product_id' => $this->shelf[2]->id, 'addon_id' => $wrap->id, 'sort_order' => 1]),
            'إيقافُ الإضافة' => fn () => $wrap->update(['active' => false]),
            'إغلاقُها للصندوق' => fn () => $wrap->update(['active' => true, 'pos_enabled' => false]),
        ] as $what => $change) {
            $change();
            $known = $this->apply($known, $this->ask($known)->assertOk()->json());
            $this->assertSameAsThePage($known, $what);
        }
    }

    /**
     * ‏‏كتابةٌ لا تحرّك مؤشّرًا (`DB::table` بلا `updated_at` على عمودٍ لا يغيّر عددًا)
     * تُرى حين يُقرأ المحتوى — بعد `CHECK_EVERY` على الأكثر، لا أبدًا.
     *
     * هذه هي النافذةُ الوحيدة التي تُترك للمؤشّرات، مكتوبةً بحدّها: والخادمُ في
     * أثنائها يحسب البيعةَ بسعره ويردّ ما يخالفه (`PosController::assertScreenTotal`).
     */
    public function test_a_write_the_markers_cannot_see_is_seen_once_the_content_is_read(): void
    {
        $known = $this->fresh();
        DB::table('products')->where('id', $this->shelf[7]->id)->update(['price' => 99]);

        $this->assertTrue($this->ask($known)->json('same'), 'المؤشّراتُ لم تتحرّك — والجوابُ ما زال من الرمز');

        Carbon::setTestNow(now()->addSeconds(TillCatalog::CHECK_EVERY));
        $d = $this->ask($known)->assertOk()->json();
        $known = $this->apply($known, $d);

        $this->assertSame(99.0, $known['rows'][$this->shelf[7]->id]['price']);
        $this->assertSameAsThePage($known);
    }

    /**
     * ‏‏تعديلان في الثانية نفسِها لا يحرّكان «آخرَ تعديل» — والجهازُ فُحص بينهما.
     *
     * `updated_at` بالثانية. فرمزٌ صدر في ثانيتَي آخرِ كتابةٍ لا يُعفي من قراءة
     * المُدخلات (`SETTLED`): الثاني يصل في السؤال التالي، لا بعد خمس دقائق.
     */
    public function test_two_edits_in_the_same_second_both_reach_the_device(): void
    {
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSeconds(10));
        $this->shelf[3]->update(['price' => 5.5]);
        $known = $this->apply($known, $this->ask($known)->json());
        $this->shelf[3]->update(['name' => 'في الثانية نفسِها']);

        $d = $this->ask($known)->json();
        $known = $this->apply($known, $d);

        $this->assertSame('في الثانية نفسِها', $known['rows'][$this->shelf[3]->id]['name']);
        $this->assertSameAsThePage($known);
    }

    /** ‏‏ورمزٌ من المستقبل (ساعةٌ رجعت) لا يُعفي من الفحص */
    public function test_a_token_from_the_future_does_not_skip_the_check(): void
    {
        Carbon::setTestNow(now()->addHour());
        $known = $this->fresh();
        Carbon::setTestNow(now()->subHours(2));
        DB::table('products')->where('id', $this->shelf[7]->id)->update(['price' => 99]);

        $known = $this->apply($known, $this->ask($known)->json());

        $this->assertSame(99.0, $known['rows'][$this->shelf[7]->id]['price']);
    }

    /** ‏‏والكميّةُ وحدها ليست تغييرًا في الكتالوج: لا يُعاد صفٌّ لبيعة */
    public function test_a_stock_move_alone_sends_no_row(): void
    {
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSecond());
        $this->shelf[3]->decrement('quantity', 2);
        DB::table('products')->where('id', $this->shelf[9]->id)->update(['quantity' => 0]);
        Carbon::setTestNow(now()->addSeconds(TillCatalog::CHECK_EVERY));

        $d = $this->ask($known)->assertOk()->json();

        $this->assertTrue($d['same'] ?? false, 'بيعةٌ أعادت صفوفًا لم يتغيّر فيها إلّا الرصيد');
    }

    /** ‏‏وعمودٌ للموقع وحده يغيّر بصمةَ المُدخلات لا الصفّ: تُرسل بصمتُه لا أصنافُه */
    public function test_a_website_only_column_moves_the_hashes_but_sends_no_row(): void
    {
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSecond());
        $this->shelf[11]->update(['published' => false]);

        $d = $this->ask($known)->assertOk()->json();
        $known = $this->apply($known, $d);

        $this->assertCount(1, $d['buckets']);
        $this->assertSame([], $d['rows']);
        $this->assertSame([], $d['products']);
        $this->assertTrue($this->ask($known)->json('same'));
    }

    /**
     * ‏‏ما لا تراه المُدخلات — الكودُ الذي يبني الصفّ، ونسخُ الصور على القرص — يُرى
     * بعد النشر: الإصدارُ الجديد يُبنى فيه الكلُّ ويُقارَن الصفُّ المبنيّ نفسُه.
     */
    public function test_after_a_release_the_built_rows_themselves_are_compared(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/rose.jpg', 'x');
        $this->shelf[6]->update(['image' => 'products/1/rose.jpg']);
        $known = $this->fresh();
        $before = $known['rows'][$this->shelf[6]->id]['thumb'];
        // ‏‏مصغّرةٌ صُنعت بعد الحفظ — القاعدةُ لم تتغيّر
        Storage::disk('public')->put(ImageVariants::path('products/1/rose.jpg', ImageVariants::THUMB), 'x');
        Carbon::setTestNow(now()->addSeconds(TillCatalog::CHECK_EVERY));

        $this->assertTrue($this->ask($known)->json('same'), 'المُدخلاتُ لم تتغيّر');

        config(['pos.catalog_release' => 'r2']);
        $d = $this->ask($known)->assertOk()->json();
        $known = $this->apply($known, $d);

        $this->assertNotSame($before, $known['rows'][$this->shelf[6]->id]['thumb']);
        $this->assertSame([$this->shelf[6]->id % 4], $d['rows'], 'نشرٌ أعاد ما لم يتغيّر صفُّه');
        $this->assertSameAsThePage($known);
    }

    /** ‏‏وبعد النشر ولم يتغيّر صفّ: «كما هو» — لا تنزيلٌ للكتالوج */
    public function test_a_release_that_changed_no_row_sends_nothing(): void
    {
        $known = $this->fresh();
        config(['pos.catalog_release' => 'r2']);

        $this->assertTrue($this->ask($known)->json('same'));
    }

    /**
     * ‏‏ومن يطلب الفحصَ العميق (مبلغٌ ردّه الخادم) يُقارَن صفُّه المبنيّ — في الإصدار
     * نفسِه، والرمزُ صحيح: ما تغيّر خارج القاعدة يصله بلا انتظار نشر.
     */
    public function test_a_deep_check_compares_the_built_rows(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/lily.jpg', 'x');
        $this->shelf[20]->update(['image' => 'products/1/lily.jpg']);
        Carbon::setTestNow(now()->addSeconds(5));
        $known = $this->fresh();
        Storage::disk('public')->put(ImageVariants::path('products/1/lily.jpg', ImageVariants::THUMB), 'x');

        $this->assertTrue($this->ask($known)->json('same'), 'المُدخلاتُ لم تتغيّر والرمزُ صحيح');

        $d = $this->ask($known, ['deep' => true])->assertOk()->json();
        $known = $this->apply($known, $d);

        $this->assertSame([$this->shelf[20]->id % 4], $d['rows']);
        $this->assertSameAsThePage($known);
    }

    /* ─────────────────────────── ما لا يُوثق به ─────────────────────────── */

    /** ‏‏رمزٌ مزوَّرٌ أو لنسخةٍ أخرى لا يُعفي من شيء: يُقارَن الصفُّ المبنيّ */
    public function test_a_forged_or_borrowed_token_vouches_for_nothing(): void
    {
        $known = $this->fresh();
        $id = $this->shelf[30]->id;
        DB::table('products')->where('id', $id)->update(['price' => 42]);

        foreach ([
            'رمزٌ مزوَّر' => substr($known['t'], 0, -2).'00',
            'رمزٌ بلا توقيع' => explode('.', $known['t'])[0],
            'لا رمز' => null,
        ] as $what => $t) {
            $d = $this->ask(['t' => $t] + $known)->assertOk()->json();
            $this->assertSame(42.0, $this->apply($known, $d)['rows'][$id]['price'], $what);
        }
    }

    /** ‏‏بصماتٌ مشوَّهة أو بعددٍ آخر من السلال: الكتالوجُ كلُّه */
    public function test_hashes_it_cannot_read_are_answered_with_the_whole_catalog(): void
    {
        $known = $this->fresh();
        foreach ([
            'سلالٌ بعددٍ آخر' => ['b' => 8] + $known,
            'بصمةٌ قصيرة' => ['ih' => ['abc']] + $known,
            'ليست ستّ عشريّة' => ['oh' => array_fill(0, 4, str_repeat('z', 16))] + $known,
        ] as $what => $bad) {
            $this->assertTrue($this->ask($bad)->assertOk()->json('full'), $what);
        }
        $this->assertTrue($this->postJson(route('pos.catalog-feed'), ['scope' => $this->scope(), 'b' => '4', 'ih' => 1, 'oh' => []])->assertOk()->json('full'));
    }

    /** ‏‏وجوابٌ يحمل بصمةَ ما سُئل به — ونسخةٌ تغيّرت بعد السؤال لا تُطبّقه */
    public function test_an_answer_names_the_copy_it_answers(): void
    {
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSecond());
        $this->shelf[0]->update(['price' => 5]);
        $first = $this->ask($known)->json();
        $this->shelf[1]->update(['price' => 6]);
        $second = $this->ask($known)->json();

        $this->assertSame($known['v'], $first['base']);
        $this->assertSame($known['v'], $second['base']);
        $after = $this->apply($known, $second);
        // ‏‏الأوّلُ وصل متأخّرًا: أساسُه ليس ما عند الجهاز الآن
        $this->assertNotSame($after['v'], $first['base']);
    }

    /** ‏‏سلالٌ تزيد مع الرفّ: يُعاد الكتالوجُ كلُّه بعددها الجديد */
    public function test_a_shelf_that_outgrows_its_buckets_is_sent_whole(): void
    {
        $known = $this->fresh();
        for ($k = 0; $k < 30; $k++) {
            Product::create(['business_id' => $this->business->id, 'name' => "زيادة $k", 'price' => 1, 'quantity' => 1, 'active' => true]);
        }

        $d = $this->ask($known)->assertOk()->json();

        $this->assertTrue($d['full']);
        $this->assertSame(8, $d['b']);
        $this->assertSameAsThePage($this->apply(null, $d));
    }

    /* ─────────────────────────── العزل ─────────────────────────── */

    /** ‏‏أصنافُ متجرٍ آخر لا تُرسل — ولا رمزُه يُعفي هنا */
    public function test_another_shops_catalog_is_never_sent(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $secret = Product::create(['business_id' => $theirs->id, 'name' => 'سرّي', 'price' => 1, 'quantity' => 9, 'active' => true]);
        $known = $this->fresh();
        Carbon::setTestNow(now()->addSecond());
        $secret->update(['price' => 2]);
        $this->shelf[0]->update(['price' => 3]);

        $d = $this->ask($known)->json();

        $this->assertNotContains($secret->id, array_column($d['products'], 'id'));
        $this->assertNotContains($secret->id, array_keys($this->apply($known, $d)['rows']));
    }

    /**
     * ‏‏جهازٌ فُتح صندوقُه لمتجرٍ أو فرعٍ أو لغةٍ غيرِ ما في الجلسة: 409 ولا صنف.
     */
    public function test_a_till_opened_for_another_shop_branch_or_language_is_told_and_given_nothing(): void
    {
        foreach ([
            'متجرٌ آخر' => TillCatalog::scope($this->business->id + 1, $this->muscat->id, 'ar'),
            'فرعٌ آخر' => $this->scope($this->salalah->id),
            'لغةٌ أخرى' => $this->scope(null, 'en'),
            'بلا مفتاح' => '',
        ] as $what => $scope) {
            $r = $this->ask(null, [], $scope)->assertStatus(409);
            $this->assertTrue($r->json('scope_changed'), $what);
            $this->assertNull($r->json('products'), "«{$what}» أُعطي أصنافًا");
        }
    }

    /** ‏‏ورمزُ متجرٍ آخر — موقَّعٌ صحيحٌ لمفتاحٍ آخر — لا يُعفي من الفحص هنا */
    public function test_a_valid_token_for_another_scope_vouches_for_nothing_here(): void
    {
        $known = $this->fresh();
        $this->activatePosDevice($this->business->id, $this->salalah->id);
        session(['current_branch' => $this->salalah->id]);
        $salalah = $this->apply(null, $this->ask(null, [], $this->scope($this->salalah->id))->json());
        DB::table('products')->where('id', $this->shelf[0]->id)->update(['price' => 77]);

        $d = $this->ask(['t' => $known['t']] + $salalah, [], $this->scope($this->salalah->id))->json();

        $this->assertSame(77.0, $this->apply($salalah, $d)['rows'][$this->shelf[0]->id]['price']);
    }

    /** ‏‏الكاشيرُ والمديرُ يأخذان الكتالوجَ نفسَه، ومن لا يبيع لا يُجاب، ولا الزائر */
    public function test_who_may_ask(): void
    {
        $owners = $this->ask()->json('products');
        foreach (['cashier', 'manager'] as $role) {
            $user = User::create(['business_id' => $this->business->id, 'name' => $role, 'email' => "$role@abaad.om", 'password' => bcrypt('p'), 'role' => $role, 'status' => 'نشط']);
            $this->actingAs($user);
            $this->assertSame($owners, $this->ask()->assertOk()->json('products'), $role);
        }

        $clerk = User::create(['business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'c@abaad.om', 'password' => bcrypt('p'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['orders']]);
        $this->actingAs($clerk);
        $this->ask()->assertForbidden();

        auth()->logout();
        $this->ask()->assertUnauthorized();
    }

    /** ‏‏وصفوفُ اللغة الأخرى بلغتها — مفتاحٌ آخر ونسخةٌ أخرى، ولا تُقرأ واحدةٌ للأخرى */
    public function test_each_language_has_its_own_copy(): void
    {
        $this->shelf[0]->update(['description' => 'وصف', 'description_en' => 'Desc', 'description_pos_enabled' => true]);
        $arabic = $this->fresh();
        session(['locale' => 'en']);

        $this->ask($arabic)->assertStatus(409);
        $english = $this->ask(null, [], $this->scope(null, 'en'))->assertOk()->json();

        $this->assertSame('Desc', collect($english['products'])->firstWhere('id', $this->shelf[0]->id)['pos_description']);
        $this->assertSame('وصف', $arabic['rows'][$this->shelf[0]->id]['pos_description']);
        $this->assertNotSame($arabic['v'], $english['v']);
    }

    /* ─────────────────────────── الحجم ─────────────────────────── */

    public function test_the_buckets_grow_with_the_shelf_and_stop_at_their_ceiling(): void
    {
        $this->assertSame(1, TillCatalog::bucketsFor(0));
        $this->assertSame(4, TillCatalog::bucketsFor(100));
        $this->assertSame(32, TillCatalog::bucketsFor(1000));
        $this->assertSame(TillCatalog::MAX_BUCKETS, TillCatalog::bucketsFor(10000));
        $this->assertSame(TillCatalog::MAX_BUCKETS, TillCatalog::bucketsFor(1_000_000));
    }

    /** ‏‏وصفحةُ الصندوق لم تتغيّر: الصفوفُ هي هي بلا تضييق */
    public function test_the_till_page_rows_are_untouched_by_the_subset_build(): void
    {
        $all = Demo::tillProducts($this->muscat->id);
        $some = Demo::tillProducts($this->muscat->id, fn ($q) => $q->whereRaw('(products.id % 4) in (1)'));

        $this->assertSame(array_values(array_filter($all, fn ($r) => $r['id'] % 4 === 1)), $some);
        $this->assertCount(100, $all);
    }
}
