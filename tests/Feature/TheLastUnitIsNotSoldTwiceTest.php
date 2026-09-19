<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * آخرُ قطعةٍ من مكوّنٍ لا تُباع مرّتين — ولو تزامن جهازان عليها.
 *
 * ═══ العطب ═══
 *
 * `priceItems` يقفل الأصناف التي في السلّة، و`availabilityResolver` يقفل
 * صفوف الفروع الموجودة. لكنّ مكوّنَ وصفةٍ لم يُوزَّع بعدُ على فرعٍ لا صفَّ له
 * يُقفل، ولم يكن صفُّ الصنف نفسِه يُقفل في `assertStock` — فيُقرأ إجماليُّه
 * كما هو. جهازان يبيعان باقةً في اللحظة نفسها ومكوّنُها قطعةٌ واحدة: يقرأ
 * كلاهما «١»، ويمرّ كلاهما، ويخصم كلاهما — فيصير الرفُّ ‎−١‎ وبيعت باقتان
 * بوردةٍ واحدة.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * SQLite لا تقفل صفًّا، فالسباق لا يقع فيها ولا يُثبَت. يُجرى على
 * PostgreSQL وحدها (وظيفةُ CI الثانية تشغّله): اتّصالٌ ثانٍ في عمليّةٍ أخرى
 * يبيع آخر قطعةٍ ويُبقي معاملته مفتوحةً لحظةً ثمّ يُودعها — وفي أثناء ذلك
 * يطلب الصندوقُ الباقة. بالقفل ينتظر الصندوقُ الإيداعَ ثمّ يقرأ صفرًا فيرفض؛
 * وبدونه يقرأ «١» ويمرّ.
 *
 * ولا `RefreshDatabase` هنا: معاملتُها تُخفي الصفوفَ عن الاتّصال الثاني.
 */
class TheLastUnitIsNotSoldTwiceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $cashier;

    private Product $bouquet;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تقفل صفًّا فلا سباقَ فيها');
        }

        $this->shop = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@race.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        // الوردةُ لم تُوزَّع على فرعٍ قطّ: لا صفَّ لها في branch_stocks
        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 1, 'cost' => 0.3,
            'quantity' => 1, 'active' => true,
        ]);
        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 0,
            'quantity' => 0, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->shop->id, 'product_id' => $this->bouquet->id,
            'component_product_id' => $this->rose->id, 'quantity' => 1, 'sort_order' => 0,
        ]);
    }

    /**
     * الجهازُ الآخر: يبيع آخر قطعةٍ ويُبقي معاملته مفتوحةً ثمّ يُودعها.
     *
     * @return resource
     */
    private function otherDeviceSellsTheLastRose(float $holdSeconds)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->beginTransaction();'
            .' $p->exec("UPDATE products SET quantity = 0 WHERE id = %d");'
            .' usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            $this->rose->id, (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الجهاز الآخر');

        // يُمهَل حتى يمسك الصفّ فعلًا قبل أن يطلب الصندوق
        usleep(600_000);

        return $proc;
    }

    public function test_two_devices_cannot_both_sell_the_last_component(): void
    {
        $proc = $this->otherDeviceSellsTheLastRose(holdSeconds: 1.5);

        try {
            $response = $this->actingAs($this->cashier)->postJson('/pos/checkout', [
                'items' => [['id' => $this->bouquet->id, 'name' => 'باقة', 'qty' => 1]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('r', true),
            ]);
        } finally {
            proc_close($proc);
        }

        $response->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, (int) $this->rose->fresh()->quantity, 'بيعت الوردةُ مرّتين — الرفُّ سالب');
        $this->assertSame(0, Order::count(), 'كُتبت فاتورةٌ لباقةٍ بلا وردة');
        $this->assertSame(0, InventoryMovement::where('product_id', $this->rose->id)->count());
    }
}
