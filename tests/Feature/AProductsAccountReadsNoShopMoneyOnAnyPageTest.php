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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * حسابُ «المنتجات» في متجرٍ يُؤوي بوتيكات لا يقرأ مالَ المتجر من أيّ صفحة — لا من اللوحة وحدها.
 *
 * اختبارُ اللوحة (`ADashboardOpensOnlyToWhoReadsTheShopsSalesTest`) يسأل أبوابَها
 * بأسمائها. وهذا يسأل كلَّ بابٍ يُفتح بـGET لمن دخل: يبيع المتجرُ بمبالغَ لا تُشبه
 * غيرها (إجماليُّ الطلب، وسطرُ القطعة، ومستحقُّ البوتيك وعمولتُه، ومجموعُ اليوم)، ثمّ
 * يطرق حسابُ المنتجات — بلوحة التحكم ومن دونها — كلَّ مسار، فلا يجد واحدًا منها.
 *
 * وكان قبل الإصلاح يجد «مجموعَ اليوم» في كلّ صفحةٍ فتحها، حتى ملفّه الشخصيّ: «ملخّصُ
 * اليوم» في الجرس يُرسَل مع كلّ صفحة. وآخرُ بيعةٍ بإجماليها مع كلّ استطلاعٍ للجرس.
 *
 * والمسارُ الذي يُضاف غدًا يُطرق كما يُطرق اليوم — لا قائمةَ هنا تُحدَّث باليد.
 * والمالكُ يجد المبالغَ نفسَها في صفحاتها، فلا يكون الاختبارُ أعمى.
 */
class AProductsAccountReadsNoShopMoneyOnAnyPageTest extends TestCase
{
    use RefreshDatabase;

    /** مبالغُ لا تكون إلّا مالَ بيع: لا سعرَ صنفٍ ولا رقمَ غيرَها */
    private const MONEY = [
        'إجمالي البيعة المختلطة' => '42.718',
        'سطر صنف المتجر' => '24.199',
        'سطر قطعة البوتيك' => '18.519',
        'صافي البوتيك' => '14.815',
        'عمولة البوتيك' => '3.704',
        'إجمالي البيعة الثانية' => '6.914',
        'مجموع اليوم' => '49.632',
    ];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(Business $shop, string $role, ?array $permissions): User
    {
        $mail = 'u'.(++$this->seq);

        return User::create(['business_id' => $shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions]);
    }

    /** كلُّ مسارِ GET لمن دخل — بأرقام ما في القاعدة مكانَ معاملاته */
    private function pages(): array
    {
        $numbers = DB::table('orders')->pluck('number')->filter()->values()->all();
        // أرقامُ ما زُرع فعلًا — على PostgreSQL لا تبدأ العدّاداتُ من ١ في كلّ اختبار
        $ids = collect(['products', 'boutiques', 'orders', 'customers'])
            ->flatMap(fn (string $table) => DB::table($table)->pluck('id'))
            ->map(fn ($id) => (string) $id)->unique()->values()->all() ?: ['1'];
        $pages = [];

        foreach (Route::getRoutes() as $route) {
            $signedIn = collect($route->gatherMiddleware())->contains(fn ($m) => $m === 'auth' || str_starts_with((string) $m, 'auth:'));
            if (! in_array('GET', $route->methods(), true) || ! $signedIn || str_starts_with($route->uri(), 'super-admin')) {
                continue;
            }

            $uris = ['/'.ltrim($route->uri(), '/')];
            preg_match_all('/\{(\w+)\??\}/', $route->uri(), $params);
            foreach ($params[1] as $param) {
                $values = $param === 'number' ? ($numbers ?: ['1']) : $ids;
                $uris = array_slice(collect($uris)->crossJoin($values)
                    ->map(fn ($pair) => preg_replace('/\{'.$param.'\??\}/', $pair[1], $pair[0], 1))->all(), 0, 9);
            }

            foreach ($uris as $uri) {
                $pages[] = $uri;
                if (str_contains($uri, 'search') || str_contains($uri, 'notifications')) {
                    $pages[] = $uri.'?q='.urlencode($numbers[0] ?? '1');
                }
            }
        }

        return array_values(array_unique($pages));
    }

    /** @return array<string, string> صفحةٌ ← ما وجدته فيها من المال */
    private function moneyEachPageShows(User $user): array
    {
        $found = [];
        foreach ($this->pages() as $uri) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($user)->get($uri);
            if ($response->getStatusCode() >= 300) {
                continue;
            }
            $body = (string) $response->getContent();
            $hits = array_keys(array_filter(self::MONEY, fn (string $amount) => str_contains($body, $amount)));
            if ($hits !== []) {
                $found[$uri] = implode('، ', $hits);
            }
        }

        return $found;
    }

    public function test_no_page_brings_a_products_account_the_shops_sales_money(): void
    {
        $shop = Business::create(['name' => 'متجر يُؤوي بوتيكات', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = $this->user($shop, 'admin', null);
        $boutique = Boutique::create(['business_id' => $shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);

        $flowers = Product::create(['business_id' => $shop->id, 'name' => 'ورد المتجر', 'price' => 3.457, 'cost' => 1.111, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $piece = Product::create(['business_id' => $shop->id, 'boutique_id' => $boutique->id, 'name' => 'قطعة البوتيك', 'price' => 6.173, 'cost' => 0, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $this->actingAs($owner)->postJson('/pos/checkout', [
            'items' => [['id' => $flowers->id, 'name' => 'ورد المتجر', 'qty' => 7, 'price' => 3.457], ['id' => $piece->id, 'name' => 'قطعة البوتيك', 'qty' => 3, 'price' => 6.173]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('m', true),
        ])->assertOk();
        $this->actingAs($owner)->postJson('/pos/checkout', [
            'items' => [['id' => $flowers->id, 'name' => 'ورد المتجر', 'qty' => 2, 'price' => 3.457]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('m', true),
        ])->assertOk();

        // والمالكُ يجدها — وإلّا فالاختبارُ لا يرى شيئًا
        $seen = $this->moneyEachPageShows($owner);
        $this->assertArrayHasKey('/admin/dashboard', $seen, 'المالكُ لم يجد مالَ اليوم في لوحته — الاختبارُ أعمى');
        $this->assertStringContainsString('صافي البوتيك', $seen['/admin/products/boutiques/'.$boutique->id] ?? '', 'المالكُ لم يجد مستحقَّ البوتيك في صفحته');

        foreach ([
            'المنتجاتُ ولوحةُ التحكم' => $this->user($shop, 'sales', ['dashboard', 'products']),
            'المنتجاتُ وحدها' => $this->user($shop, 'sales', ['products']),
        ] as $who => $account) {
            $this->assertSame([], $this->moneyEachPageShows($account), "مالُ المتجر وصل حسابَ «{$who}»");
        }
    }
}
