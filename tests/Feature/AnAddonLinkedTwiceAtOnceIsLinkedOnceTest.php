<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إضافةٌ تُربط بمنتجٍ مرّتين في اللحظة نفسها تُربط مرّةً واحدة — بلا خطأ.
 *
 * ═══ ما كان ═══
 *
 * الزوجُ (`product_id`, `addon_id`) فريدٌ في `product_addons` منذ أُنشئ
 * الجدول، فالتكرارُ لا يُكتب أصلًا. لكنّ أبوابَ الكتابة كانت تسأل «أموجود؟»
 * ثمّ تكتب: طلبان معًا يقرآن «لا» كلاهما، فيكتب الأوّل، ويصطدم الثاني
 * بالقيد فيسقط بخطأ ٥٠٠ — والتاجرُ يرى «تعذّر الحفظ» لربطٍ وقع فعلًا. وكذا
 * حفظُ منتجات إضافةٍ أو إضافاتِ منتجٍ يلتقي ربطًا في الزوج نفسه.
 *
 * وحفظان لمنتجات إضافةٍ واحدة: يمحو كلٌّ ما يراه ثمّ يكتب قائمته — والثاني
 * لا يرى ما كتبه الأوّل ولم يُودعه بعد، فيبقى الاثنان معًا: قائمةٌ لم
 * يخترها أحد.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * على PostgreSQL وحدها كـ`TheLastUnitIsNotSoldTwiceTest`: عمليّةٌ أخرى
 * باتّصالها تكتب الزوجَ وتُبقي معاملتها مفتوحةً ثمّ تُودعها، وفي أثناء ذلك
 * يصل الطلب. ولا `RefreshDatabase`: معاملتُها تُخفي الصفوفَ عن الاتّصال
 * الثاني، فتُودَع الصفوف وتُكنَس بعد كلّ اختبار.
 */
class AnAddonLinkedTwiceAtOnceIsLinkedOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    private Product $rose;

    private Product $tulip;

    private Product $lily;

    private Addon $wrap;

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        $this->shop = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@race.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        foreach (['rose' => 'وردة', 'tulip' => 'توليب', 'lily' => 'زنبق'] as $key => $name) {
            $this->{$key} = Product::create([
                'business_id' => $this->shop->id, 'name' => $name, 'price' => 5, 'cost' => 0, 'quantity' => 10, 'active' => true,
            ]);
        }

        $this->wrap = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'تغليف', 'price' => 1.5, 'active' => true,
            'scope' => Addon::SCOPE_SELECTED,
        ]);
    }

    /**
     * طلبٌ آخر في عمليّةٍ أخرى: يكتب ما في `$sql` ويُبقي معاملته مفتوحةً ثمّ يُودعها.
     *
     * @return resource
     */
    private function anotherRequest(string $sql, float $holdSeconds = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الطلب الآخر');

        // يُمهَل حتى يكتب فعلًا قبل أن يصل طلبُنا
        usleep(600_000);

        return $proc;
    }

    private function linksTheRose(): string
    {
        return sprintf(
            'INSERT INTO product_addons (business_id, product_id, addon_id, sort_order, created_at, updated_at) VALUES (%d, %d, %d, 0, now(), now())',
            $this->shop->id, $this->rose->id, $this->wrap->id,
        );
    }

    /** @return list<int> */
    private function productsOf(Addon $addon): array
    {
        return DB::table('product_addons')->where('addon_id', $addon->id)->orderBy('product_id')
            ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_two_links_at_once_answer_ok_and_write_one_row(): void
    {
        $proc = $this->anotherRequest($this->linksTheRose());

        try {
            $response = $this->actingAs($this->owner)
                ->putJson(route('admin.products.addons.link', [$this->rose->id, $this->wrap->id]));
        } finally {
            proc_close($proc);
        }

        $response->assertOk()->assertJsonPath('addon.product_ids', [$this->rose->id]);
        $this->assertSame([$this->rose->id], $this->productsOf($this->wrap));
    }

    public function test_saving_an_addons_products_while_one_is_linked_keeps_each_once(): void
    {
        $proc = $this->anotherRequest($this->linksTheRose());

        try {
            $response = $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->wrap->id), [
                'name' => 'تغليف', 'price' => 1.5, 'scope' => Addon::SCOPE_SELECTED,
                'product_ids' => [$this->rose->id, $this->tulip->id],
            ]);
        } finally {
            proc_close($proc);
        }

        $response->assertOk();
        $this->assertSame([$this->rose->id, $this->tulip->id], $this->productsOf($this->wrap));
    }

    public function test_saving_a_products_addons_while_one_is_linked_keeps_it_once(): void
    {
        $proc = $this->anotherRequest($this->linksTheRose());

        try {
            $response = $this->actingAs($this->owner)
                ->put(route('admin.products.addons.sync', $this->rose->id), ['addon_ids' => [$this->wrap->id]]);
        } finally {
            proc_close($proc);
        }

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame([$this->rose->id], $this->productsOf($this->wrap));
    }

    public function test_two_saves_of_one_addons_products_end_on_the_later_one(): void
    {
        // الحفظُ الآخر: يقفل الإضافة كما يقفلها الحفظ، ويكتب «الزنبق وحده»
        $proc = $this->anotherRequest(sprintf(
            'SELECT id FROM addons WHERE id = %1$d FOR NO KEY UPDATE;'
            .' DELETE FROM product_addons WHERE addon_id = %1$d;'
            .' INSERT INTO product_addons (business_id, product_id, addon_id, sort_order, created_at, updated_at)'
            .' VALUES (%2$d, %3$d, %1$d, 0, now(), now())',
            $this->wrap->id, $this->shop->id, $this->lily->id,
        ));

        try {
            // وحفظُنا بعده بلحظة: «الوردة والتوليب» — هو الأخير، فهو ما يبقى
            $response = $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->wrap->id), [
                'name' => 'تغليف', 'price' => 1.5, 'scope' => Addon::SCOPE_SELECTED,
                'product_ids' => [$this->rose->id, $this->tulip->id],
            ]);
        } finally {
            proc_close($proc);
        }

        $response->assertOk();
        $this->assertSame([$this->rose->id, $this->tulip->id], $this->productsOf($this->wrap), 'بقي الزنبقُ من حفظٍ سابق — قائمةٌ لم يخترها أحد');
    }
}
