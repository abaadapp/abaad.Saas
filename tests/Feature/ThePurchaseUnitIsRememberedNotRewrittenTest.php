<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Ledger;
use App\Support\PurchaseUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وحدةُ الشراء تُذكَر ولا تُعاد كتابتُها.
 *
 * ═══ ما كان ═══
 *
 * حقلٌ نصٌّ حرٌّ يفتح فارغًا، وقائمةٌ من عشر كلماتٍ مكتوبةٍ في الشاشة يرسمها
 * نظامُ التشغيل بلا سهمٍ يقول إنّها هناك. فمن لم يعرف بها ترك الحقلَ فارغًا
 * — ويُقرأ البندُ بعدها «—»: لا أحد يعرف أصندوقًا طلب أم حبّة. ومن اشترى
 * بوحدةٍ ليست في العشر كتبها بيده في كلّ بندٍ من كلّ أمر، وكتابةُ اليد
 * تُخطئ حرفًا فتصير وحدتين.
 *
 * ═══ وما صار ═══
 *
 * القائمةُ تُقرأ ممّا اشترى به المتجرُ فعلًا، مرتَّبةً بكثرة استعماله، ثمّ
 * المقترَحاتُ لمن لم يشترِ بعد. ورأسُها هو ما يفتح به الحقلُ نفسَه.
 */
class ThePurchaseUnitIsRememberedNotRewrittenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة حمراء',
            'sku' => 'FLW-1', 'price' => 1, 'cost' => 0, 'quantity' => 0,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
    }

    /** بندٌ محفوظٌ بوحدته — مباشرةً، فالقائمةُ تُقرأ من البنود لا من الشاشة */
    private function bought(string $unit, int $times = 1, ?int $businessId = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            $order = PurchaseOrder::create([
                'business_id' => $businessId ?? $this->business->id,
                'number' => 'PO-'.($businessId ?? $this->business->id).'-'.$unit.'-'.$i,
                'supplier_id' => $this->supplier->id, 'supplier_name' => 'ورد الخليج',
                'status' => 'قيد التنفيذ', 'total' => 10, 'ordered_at' => now()->toDateString(),
            ]);
            PurchaseOrderItem::create([
                'purchase_order_id' => $order->id, 'name' => 'وردة حمراء',
                'purchase_unit' => $unit, 'units_per_purchase_unit' => 1,
                'cost' => 10, 'quantity' => 1,
            ]);
        }
    }

    /* ==================== القائمة ==================== */

    public function test_a_shop_that_never_bought_gets_the_suggestions(): void
    {
        $this->assertSame(PurchaseUnits::SUGGESTED, PurchaseUnits::forBusiness($this->business->id));
    }

    public function test_what_the_shop_buys_with_most_comes_first(): void
    {
        /*
         * و«صندوق» تسبق «كيلو» أبجديًّا — فترتيبُها بعدها لا يقع إلّا بالعدّ.
         * ولو رُتّبت القائمةُ بحروفها لمرّ هذا الاختبارُ كاذبًا.
         */
        $this->bought('صندوق', 1);
        $this->bought('كيلو', 3);

        $units = PurchaseUnits::forBusiness($this->business->id);

        // ‏و«حبة» أوّلُ المقترَحات — تُزاح حين يصير للمتجر عادة
        $this->assertSame(['كيلو', 'صندوق'], array_slice($units, 0, 2));
    }

    public function test_two_units_bought_the_same_number_of_times_keep_one_order(): void
    {
        // ‏ولا ترتيبَ تقرّره قاعدةُ البيانات: القائمةُ نفسُها في كلّ فتحة
        $this->bought('متر');
        $this->bought('باقة');

        $this->assertSame(['باقة', 'متر'], array_slice(PurchaseUnits::forBusiness($this->business->id), 0, 2));
    }

    public function test_a_unit_the_shop_invented_comes_back_next_time(): void
    {
        $this->bought('شتلة');

        $this->assertContains('شتلة', PurchaseUnits::forBusiness($this->business->id));
    }

    public function test_no_unit_is_listed_twice(): void
    {
        $this->bought('صندوق', 2);

        $units = PurchaseUnits::forBusiness($this->business->id);

        $this->assertSame(array_values(array_unique($units)), $units);
    }

    public function test_another_shops_units_do_not_leak(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->bought('لفة', 1, $other->id);

        $this->assertNotContains('لفة', PurchaseUnits::forBusiness($this->business->id));
        $this->assertContains('لفة', PurchaseUnits::forBusiness($other->id));
    }

    /* ==================== الشاشة ==================== */

    public function test_the_screen_is_given_the_units_it_offers(): void
    {
        $this->bought('شتلة', 2);

        $this->actingAs($this->owner)->get(route('admin.purchases.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('units.0', 'شتلة')
                ->where('units', fn ($u) => collect($u)->contains('كرتون'))
                ->etc()
            );
    }

    public function test_the_screen_no_longer_leans_on_the_list_the_system_draws(): void
    {
        /*
         * القائمةُ الأصليّة (`datalist`) يرسمها نظامُ التشغيل: نافذةٌ داكنةٌ
         * ضيّقة تحجب الحقل، ولا سهمَ فيها يقول إنّ ثمّةَ خياراتٍ أصلًا.
         * وهي نفسُها التي رُفعت من مرشّحات التقارير.
         */
        $screen = file_get_contents(resource_path('js/Pages/Admin/Purchases/Create.tsx'));

        $this->assertStringNotContainsString('<datalist', $screen, 'عادت القائمة التي يرسمها نظام التشغيل');
        $this->assertStringContainsString('<UnitPicker', $screen, 'حقل الوحدة بلا منتقٍ مرسوم في الصفحة');
    }

    /* ==================== الحفظ ==================== */

    public function test_a_unit_written_by_hand_is_saved_as_written(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'ordered_at' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id, 'name' => 'وردة حمراء',
                'purchase_unit' => '  شتلة  ', 'units_per_purchase_unit' => 1,
                'cost' => 10, 'quantity' => 1,
            ]],
        ])->assertRedirect();

        // ‏ومقلَّمةً: «شتلة » و«شتلة» وحدتان في القائمة وواحدةٌ في الواقع
        $this->assertSame('شتلة', PurchaseOrderItem::latest('id')->firstOrFail()->purchase_unit);
        $this->assertContains('شتلة', PurchaseUnits::forBusiness($this->business->id));
    }
}
