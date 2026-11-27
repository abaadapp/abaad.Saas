<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * لوحةُ التحكم مالُ المتجر كلُّه — ولا تُفتح في متجرٍ يُؤوي بوتيكات لمن لا يقرأ مبيعاته.
 *
 * حسابٌ أُعطي «المنتجات» ليُدخل قطعَ بوتيكه، ومعها «لوحة التحكم» لأنّها أوّلُ
 * القائمة، كان يقرأ مبيعاتِ اليوم والشهر ورسمَ السنة وطرقَ الدفع وأحدثَ الطلبات
 * بإجماليّها وأفضلَ الأصناف بإيرادها — وفي الجرس «ملخّص اليوم» بصافي ربحه،
 * ومع كلّ استطلاعٍ آخرَ طلبٍ بإجماليه. كلُّ ذلك من الخادم لا من الواجهة.
 *
 * والمالكُ ومن يقرأ المبيعات من بابٍ آخر، ومتجرٌ لا يؤوي بوتيكًا: لا يتغيّر عليهم شيء.
 */
class ADashboardOpensOnlyToWhoReadsTheShopsSalesTest extends TestCase
{
    use RefreshDatabase;

    private Business $hosting;

    private Business $plain;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        $this->hosting = $this->shop('متجر يُؤوي بوتيكات', true);
        $this->plain = $this->shop('متجر بلا بوتيك', false);
        Boutique::create(['business_id' => $this->hosting->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(string $name, bool $hosts): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط'] + ($hosts ? ['boutiques_enabled' => true] : []));
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    private function user(Business $shop, string $role, ?array $permissions): User
    {
        $mail = 'u'.(++$this->seq);

        return User::create(['business_id' => $shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions]);
    }

    /** بيعةٌ اليوم من المالك — ليكون في اللوحة والجرس ما يُقرأ */
    private function sellToday(Business $shop): void
    {
        $owner = $this->user($shop, 'admin', null);
        $flowers = Product::create(['business_id' => $shop->id, 'name' => 'ورد', 'price' => 9, 'cost' => 1, 'quantity' => 50, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $this->actingAs($owner)->postJson('/pos/checkout', [
            'items' => [['id' => $flowers->id, 'name' => 'ورد', 'qty' => 2, 'price' => 9]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('d', true),
        ])->assertOk();
        auth()->logout();
    }

    /** أبوابُ اللوحة الثلاثة: الصفحةُ ونبضتُها والأهداف */
    private function doors(User $user): array
    {
        return [
            'الصفحة' => $this->actingAs($user)->get(route('admin.dashboard'))->status(),
            'النبضة' => $this->actingAs($user)->getJson(route('admin.dashboard.stats'))->status(),
            'الأهداف' => $this->actingAs($user)->postJson(route('admin.goals.update'), ['monthly_target' => 100])->status(),
        ];
    }

    public function test_a_products_account_in_a_shop_that_hosts_boutiques_opens_no_door_of_the_dashboard(): void
    {
        $this->sellToday($this->hosting);
        $boutiqueAccount = $this->user($this->hosting, 'sales', ['dashboard', 'products']);

        foreach ($this->doors($boutiqueAccount) as $door => $status) {
            $this->assertSame(403, $status, "باب «{$door}» فُتح لحساب المنتجات وحدها");
        }

        // والمحفوظُ في الحساب لا يُمسّ — ما منحه التاجرُ باقٍ كما كتبه
        $this->assertSame(['dashboard', 'products'], json_decode((string) DB::table('users')->where('id', $boutiqueAccount->id)->value('permissions'), true));
    }

    public function test_it_lands_on_its_products_and_its_menu_carries_no_dashboard(): void
    {
        $boutiqueAccount = $this->user($this->hosting, 'sales', ['dashboard', 'products']);

        $this->post(route('login.attempt'), ['email' => $boutiqueAccount->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('admin.products.index'));

        $this->actingAs($boutiqueAccount)->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.abilities', fn ($abilities) => ! collect($abilities)->contains('dashboard')
                && collect($abilities)->contains('products'))
                ->where('auth.panelUrl', route('admin.products.index')));
    }

    public function test_the_bell_brings_it_neither_the_days_summary_nor_the_last_sale(): void
    {
        $this->sellToday($this->hosting);
        $boutiqueAccount = $this->user($this->hosting, 'sales', ['dashboard', 'products']);
        $owner = $this->user($this->hosting, 'admin', null);

        $feed = $this->actingAs($boutiqueAccount)->getJson(route('admin.notifications.feed'))->assertOk()->json();
        $this->assertNull($feed['latest_order'], 'آخرُ بيعةٍ بإجماليها وصلت حسابَ المنتجات');
        $this->assertFalse(collect($feed['items'])->contains(fn ($i) => str_contains(json_encode($i, JSON_UNESCAPED_UNICODE), 'ملخّص اليوم')), 'ملخّص اليوم بصافي ربحه وصل حسابَ المنتجات');

        // والمالكُ يصله ما كان يصله
        $mine = $this->actingAs($owner)->getJson(route('admin.notifications.feed'))->assertOk()->json();
        $this->assertSame(18.0, (float) $mine['latest_order']['total']);
        $this->assertTrue(collect($mine['items'])->contains(fn ($i) => str_contains(json_encode($i, JSON_UNESCAPED_UNICODE), 'ملخّص اليوم')));
    }

    public function test_nothing_changes_for_who_reads_sales_or_where_no_boutique_lives(): void
    {
        $this->sellToday($this->hosting);
        $this->sellToday($this->plain);

        $open = [
            'المالك' => $this->user($this->hosting, 'admin', null),
            'المدير' => $this->user($this->hosting, 'manager', null),
            'المحاسب بدوره' => $this->user($this->hosting, 'accountant', null),
            'أمين المخزن بدوره' => $this->user($this->hosting, 'inventory', null),
            'البائع بدوره' => $this->user($this->hosting, 'sales', null),
            'منتجاتٌ وطلبات' => $this->user($this->hosting, 'sales', ['dashboard', 'products', 'orders']),
            'منتجاتٌ وتقارير' => $this->user($this->hosting, 'sales', ['dashboard', 'products', 'reports']),
            'منتجاتٌ في متجرٍ بلا بوتيك' => $this->user($this->plain, 'sales', ['dashboard', 'products']),
        ];

        foreach ($open as $who => $user) {
            foreach ($this->doors($user) as $door => $status) {
                $this->assertNotSame(403, $status, "«{$who}» رُدّ عن باب «{$door}»");
            }
            $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->has('stats')->has('recentOrders', 1));
            $this->assertNotNull($this->actingAs($user)->getJson(route('admin.notifications.feed'))->json('latest_order'), "«{$who}» فقد آخر بيعة");
        }
    }
}
