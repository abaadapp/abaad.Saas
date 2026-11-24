<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * صلاحيّةُ «المنتجات» تُدخل أصنافَ البوتيك — ولا تفتح مالَه.
 *
 * من يُضيف قطعًا أو صاحبُ بوتيكٍ أُعطي حسابًا لذلك لا يقرأ كشفًا ولا عمولةً ولا
 * صافيًا، ولا يُصدر ورقةً ولا يسدّدها — على الخادم وفي الاستجابة وفي الرابط المباشر.
 * والقراءةُ (المصروفات) ليست إصدارًا، والإصدارُ (اعتماد السند) ليس سدادًا.
 */
class AProductsKeyOpensNoBoutiqueMoneyTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $silver;

    private Product $gift;

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
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = $this->user('o', 'admin', null);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 17.5, 'active' => true, 'notes' => 'شرطٌ سرّيّ', 'returns_terms' => null]);
        $this->silver = $this->product('قطعة فضّة', 7, $this->lama->id);
        $this->gift = $this->product('باقة', 20);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->gift->id, 'component_product_id' => $this->silver->id, 'quantity' => 1, 'wastage_percent' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $mail, string $role, ?array $permissions): User
    {
        return User::create(['business_id' => $this->shop->id ?? null, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions]);
    }

    private function product(string $name, float $price, ?int $boutique = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique]);
    }

    private function sellBoth(): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->gift->id, 'name' => 'باقة', 'qty' => 1, 'price' => 20], ['id' => $this->silver->id, 'name' => 'قطعة فضّة', 'qty' => 2, 'price' => 7]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    /** من لا يقرأ المال — كلٌّ باسمه */
    private function unauthorised(): array
    {
        return [
            'موظف منتجات' => $this->user('products', 'sales', ['dashboard', 'products']),
            'صاحب بوتيك أُعطي حسابًا لأصنافه' => $this->user('boutiqueowner', 'sales', ['dashboard', 'products']),
            'مبيعات بدوره' => $this->user('sales', 'sales', null),
            'مخزون بدوره' => $this->user('inventory', 'inventory', null),
            'كاشير' => $this->user('cashier', 'cashier', null),
        ];
    }

    /** @return array<string, mixed> */
    private function doors(BoutiqueSettlement $sheet): array
    {
        return [
            ['post', route('admin.boutiques.settle', $this->lama->id), ['period' => self::PERIOD]],
            ['post', route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي']],
            ['post', route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي']],
            ['post', route('admin.boutiques.returns.review', $this->lama->id), ['note' => 1, 'bearer' => 'shop', 'why' => 'x']],
            ['post', route('admin.boutiques.returns.receipt', $this->lama->id), ['amount' => 1, 'method' => 'نقدي', 'reference' => 'x']],
            ['post', route('admin.boutiques.returns.offset', $this->lama->id), ['settlement' => $sheet->id]],
            ['post', route('admin.boutiques.store'), ['name' => 'جديد', 'commission_rate' => 1]],
            ['put', route('admin.boutiques.update', $this->lama->id), ['name' => 'اختطاف', 'commission_rate' => 0]],
            ['delete', route('admin.boutiques.destroy', $this->lama->id), []],
        ];
    }

    private function issued(): BoutiqueSettlement
    {
        $this->sellBoth();

        return Boutiques::settle($this->shop, $this->lama, self::PERIOD);
    }

    private function state(): array
    {
        return [
            BoutiqueSettlement::count(), DB::table('journal_entries')->count(), DB::table('expenses')->count(),
            Boutique::where('business_id', $this->shop->id)->get()->map(fn ($b) => [$b->name, (float) $b->commission_rate])->all(),
            BoutiqueSettlement::whereNotNull('components_paid_at')->count(),
        ];
    }

    /* ═══════════ من لا يقرأ ═══════════ */

    public function test_the_products_key_neither_reads_the_money_nor_acts_on_it(): void
    {
        $sheet = $this->issued();
        $before = $this->state();
        foreach ($this->unauthorised() as $who => $user) {
            foreach ($this->doors($sheet) as [$method, $url, $data]) {
                foreach (['json', 'web'] as $how) {
                    $res = $how === 'json' ? $this->actingAs($user)->{$method.'Json'}($url, $data) : $this->actingAs($user)->{$method}($url, $data);
                    $this->assertContains($res->status(), [302, 403], "{$who} بلغ {$method} {$url}: {$res->status()}");
                    if ($res->status() === 302) {
                        // الكاشيرُ بلا لوحةٍ يُردّ إلى دخوله/صندوقه — لا إلى الشاشة
                        $this->assertStringNotContainsString('/boutiques', (string) $res->headers->get('Location'), $who);
                    }
                }
            }
            $this->assertSame($before, $this->state(), $who.' غيّر مالًا أو بيانات');
        }
    }

    public function test_what_reaches_the_products_key_carries_no_money(): void
    {
        $this->issued();
        foreach ([$this->user('p2', 'sales', ['dashboard', 'products']), $this->user('s2', 'sales', null)] as $user) {
            foreach ([route('admin.boutiques.index', ['period' => self::PERIOD]), route('admin.boutiques.show', [$this->lama->id, 'period' => self::PERIOD])] as $url) {
                $res = $this->actingAs($user)->get($url)->assertOk();
                $props = $res->viewData('page')['props'];
                $this->assertFalse($props['canSeeMoney']);
                foreach (['statement', 'settlement', 'ledger', 'returns'] as $k) {
                    $this->assertTrue(! array_key_exists($k, $props) || $props[$k] === null, "{$k} في استجابةٍ لمن لا يقرأ المال");
                }
                $this->assertSame([], $props['history'] ?? []);
                $json = json_encode($props, JSON_UNESCAPED_UNICODE);
                // لا نسبةٌ ولا ملاحظةٌ ولا رقمٌ من الكشف في نصّ الاستجابة
                foreach (['17.5', 'شرطٌ سرّيّ', 'commission', 'payable', 'owed', '"net"', '"gross"', 'BQ'] as $leak) {
                    $this->assertStringNotContainsString($leak, $json, "تسريب «{$leak}» في {$url}");
                }
            }
            $row = $this->actingAs($user)->get(route('admin.boutiques.index'))->viewData('page')['props']['boutiques'][0];
            $this->assertSame(['id', 'name', 'name_en', 'phone', 'contact_person', 'active', 'products_count'], array_values(array_intersect(['id', 'name', 'name_en', 'phone', 'contact_person', 'rate', 'active', 'notes', 'returns_terms', 'returns_terms_ref', 'products_count', 'gross', 'pending', 'settled'], array_keys($row))));
        }
    }

    /** ولا يُمنع من عمله: إدخالُ الأصناف وإسنادُها وشاشةُ المنتجات كما كانت */
    public function test_the_products_key_keeps_its_own_work(): void
    {
        $user = $this->user('p3', 'sales', ['dashboard', 'products']);
        $this->actingAs($user)->get(route('admin.boutiques.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.boutiques.show', $this->lama->id))->assertOk();
        $this->actingAs($user)->get(route('admin.products.index'))->assertOk();

        $new = $this->product('قطعة جديدة', 9);
        $this->actingAs($user)->post(route('admin.boutiques.attach', $this->lama->id), ['product_ids' => [$new->id]])->assertSessionHasNoErrors();
        $this->assertSame($this->lama->id, $new->fresh()->boutique_id, 'إسنادُ الصنف إلى بوتيك عملُه');
        $this->actingAs($user)->post(route('admin.boutiques.attach', $this->lama->id), ['product_ids' => [$new->id], 'detach' => true])->assertSessionHasNoErrors();
        $this->assertNull($new->fresh()->boutique_id);
    }

    /* ═══════════ ثلاثةُ أسئلة ═══════════ */

    public function test_reading_is_not_issuing_and_issuing_is_not_paying(): void
    {
        $reader = $this->user('reader', 'sales', ['dashboard', 'products', 'expenses']);
        $issuer = $this->user('issuer', 'sales', ['dashboard', 'products', 'expenses', Permissions::INVOICE_APPROVE]);
        $payer = $this->user('payer', 'sales', ['dashboard', 'products', 'expenses', Permissions::INVOICE_PAY]);
        $this->sellBoth();

        // القارئُ يقرأ كلَّ شيءٍ ولا يُصدر ولا يسدّد
        $props = $this->actingAs($reader)->get(route('admin.boutiques.show', [$this->lama->id, 'period' => self::PERIOD]))->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['canSeeMoney']);
        $this->assertFalse($props['canIssue']);
        $this->assertFalse($props['canPay']);
        $this->assertNotNull($props['statement']);
        $this->assertEquals(17.5, $props['boutique']['rate']);
        $this->actingAs($reader)->post(route('admin.boutiques.settle', $this->lama->id), ['period' => self::PERIOD])->assertForbidden();
        $this->assertSame(0, BoutiqueSettlement::count());

        // ومن يُصدر يُصدر ولا يسدّد
        $this->actingAs($issuer)->post(route('admin.boutiques.settle', $this->lama->id), ['period' => self::PERIOD])->assertSessionHasNoErrors();
        $sheet = BoutiqueSettlement::firstOrFail();
        $this->actingAs($issuer)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])->assertForbidden();
        $this->actingAs($issuer)->post(route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي'])->assertForbidden();
        $this->actingAs($reader)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])->assertForbidden();
        $this->assertNull($sheet->fresh()->components_paid_at);

        // ومن يسدّد يسدّد ولا يُصدر
        $this->actingAs($payer)->post(route('admin.boutiques.settle', $this->lama->id), ['period' => self::PERIOD])->assertForbidden();
        $this->actingAs($payer)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertNotNull($sheet->fresh()->components_paid_at);
    }

    public function test_an_owner_and_a_manager_read_issue_and_pay_and_the_accountant_reads(): void
    {
        $this->sellBoth();
        foreach (['المالك' => $this->owner, 'المدير' => $this->user('m', 'manager', null)] as $who => $user) {
            $props = $this->actingAs($user)->get(route('admin.boutiques.show', [$this->lama->id, 'period' => self::PERIOD]))->assertOk()->viewData('page')['props'];
            $this->assertSame([true, true, true], [$props['canSeeMoney'], $props['canIssue'], $props['canPay']], $who);
        }
        $this->actingAs($this->owner)->post(route('admin.boutiques.settle', $this->lama->id), ['period' => self::PERIOD])->assertSessionHasNoErrors();
        $this->assertSame(1, BoutiqueSettlement::count());
        // والمحاسبُ يقرأ المصروفاتِ لا المنتجات — شاشةُ البوتيك تحت «المنتجات»، فيُمنح القسمَ معها
        $accountant = $this->user('acc', 'accountant', null);
        $this->actingAs($accountant)->get(route('admin.boutiques.show', $this->lama->id))->assertForbidden();
        $granted = $this->user('acc2', 'accountant', ['dashboard', 'products', 'expenses', 'finance']);
        $this->actingAs($granted)->get(route('admin.boutiques.show', $this->lama->id))->assertOk();
    }

    /** بلا إذن الاستلام: قراءةُ المرتجعات وقرارُها شيء، واستلامُ مالٍ أو مقاصّتُه شيء */
    public function test_receiving_and_offsetting_money_need_the_pay_key(): void
    {
        $reader = $this->user('r3', 'sales', ['dashboard', 'products', 'expenses']);
        $payer = $this->user('p4', 'sales', ['dashboard', 'products', 'expenses', Permissions::INVOICE_PAY]);
        $this->sellBoth();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $order = Order::where('business_id', $this->shop->id)->firstOrFail();
        $this->actingAs($this->owner);
        $items = OrderItem::where('order_id', $order->id)->whereNotNull('boutique_id')->get();
        OrderCorrection::cancel($order->fresh(), 'رُدّ', boutiqueReturned: true, boutiqueLines: $items->mapWithKeys(fn ($i) => [$i->id => ['sellable' => (int) $i->quantity, 'damaged' => 0]])->all());

        $this->actingAs($reader)->post(route('admin.boutiques.returns.receipt', $this->lama->id), ['amount' => 1, 'method' => 'نقدي', 'reference' => 'R'])->assertForbidden();
        $this->actingAs($reader)->post(route('admin.boutiques.returns.offset', $this->lama->id), ['settlement' => $sheet->id])->assertForbidden();
        $note = BoutiqueReturn::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertNotNull($note);
        // والقارئُ يقرّر المرتجعَ كما كان — هو قرارُ المصروفات
        $this->actingAs($reader)->post(route('admin.boutiques.returns.review', $this->lama->id), ['note' => $note->id, 'bearer' => 'shop', 'why' => 'قرار'])->assertStatus(302);
        $this->actingAs($payer)->post(route('admin.boutiques.returns.receipt', $this->lama->id), ['amount' => 1, 'method' => 'نقدي', 'reference' => 'R'])->assertStatus(302);
    }

    /** ولا يُفتح مالُه من باب التقارير ولا ملفّها ولا طباعتها — وكلُّها خارج قسم المنتجات */
    public function test_reports_downloads_and_prints_carry_no_boutique_money_to_the_products_key(): void
    {
        $this->issued();
        $user = $this->user('p5', 'sales', ['dashboard', 'products']);
        $tried = 0;
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($name, 'admin.') || str_contains($route->uri(), '{')
                // تصديرُ المنتجات عملُ صاحبها، واسمُ البوتيك فيه لا رقمُه — يُفحص ملفُّه أسفل
                || str_starts_with($name, 'admin.products.') || ! preg_match('/report|export|pdf|finance|expenses/', $name)) {
                continue;
            }
            // رابطٌ موسومٌ بمتجره: فلا يُردّ بحارس التبويب القديم (٤٠٩) قبل أن يُسأل الإذن
            $res = $this->actingAs($user)->get($this->exportRoute($name, ['boutique' => (string) $this->lama->id], (int) $this->shop->id));
            $status = $res->baseResponse->getStatusCode();
            if ($name === 'admin.export.products') {
                // ملفُّ المنتجات عملُه: يُفتح، ولا عمولةَ ولا كشفَ بوتيكٍ فيه
                $this->assertSame(200, $status);
                foreach (['17.5', 'commission', 'نسبة المتجر', 'شرطٌ سرّيّ'] as $leak) {
                    $this->assertStringNotContainsString($leak, $res->streamedContent(), "تسريب «{$leak}» في تصدير المنتجات");
                }
                $tried++;

                continue;
            }
            $this->assertContains($status, [302, 403, 404], "{$name} فُتح لمن لا يقرأ المال: {$status}");
            $tried++;
        }
        $this->assertGreaterThan(10, $tried, 'لم تُقرأ أبواب التقارير — الاختبارُ لا يقيس شيئًا');
    }

    /** صفحةُ المنتج: كم بيع وهامشُه وحركاتُه لا تُرسل لمن يفتح المنتجاتِ وحدها */
    public function test_a_product_page_tells_a_products_only_account_nothing_that_was_sold(): void
    {
        $this->sellBoth();
        $page = fn (User $u) => $this->actingAs($u)->get(route('admin.products.show', $this->silver->id))->assertOk()->viewData('page')['props'];
        $labels = fn (array $props) => array_column($props['stats'], 'label');

        $boutiqueOwner = $page($this->user('bo', 'sales', ['products']));
        $this->assertSame(['الكمية المتوفرة', 'سعر التكلفة'], $labels($boutiqueOwner));
        $this->assertNull($boutiqueOwner['movements']);

        // ومن له بابٌ يقرأ منه ما بيع لا يتغيّر عليه شيء: المالكُ والمبيعاتُ والمخزونُ والمحاسب
        foreach ([$this->owner, $this->user('s1', 'sales', null), $this->user('i1', 'inventory', null), $this->user('a1', 'sales', ['products', 'expenses']), $this->user('r1', 'sales', ['products', 'reports'])] as $u) {
            $props = $page($u);
            $this->assertSame(['الكمية المتوفرة', 'إجمالي المبيعات', 'سعر التكلفة', 'هامش الربح'], $labels($props), $u->name);
            $this->assertNotNull($props['movements'], $u->name);
        }

        // ومتجرٌ لا بوتيكَ عنده كما كان
        Boutique::where('business_id', $this->shop->id)->delete();
        $this->shop->forceFill(['boutiques_enabled' => false])->save();
        $this->assertCount(4, $page($this->user('bo2', 'sales', ['products']))['stats']);
    }

    /** وصفحةُ الطلب: ثمنُ القطعة مستحقُّها — لا يُرسل لمن يفتح المبيعاتِ ولا يقرأ المال */
    public function test_the_order_page_asks_about_a_piece_without_its_price(): void
    {
        $order = $this->sellBoth();
        $piece = fn (User $u) => $this->actingAs($u)->get(route('admin.orders.show', $order->number))->assertOk()->viewData('page')['props']['boutiquePieces'][0];
        $this->assertArrayNotHasKey('unit_price', $piece($this->user('s2', 'sales', null)));
        $this->assertSame('قطعة فضّة', $piece($this->user('s3', 'sales', null))['product']);
        $this->assertEquals(7.0, $piece($this->owner)['unit_price']);
    }

    /**
     * كلُّ شاشةٍ يفتحها مفتاحُ المنتجات وحده معدودة — وشاشةٌ جديدةٌ تُفحص قبل أن تُضاف هنا.
     *
     * صاحبُ بوتيكٍ أُعطي حسابًا لأصنافه هو هذا المفتاح: فما لا يُذكر هنا لا يبلغه.
     */
    public function test_every_screen_the_products_key_opens_is_known(): void
    {
        $user = $this->user('only', 'sales', ['products']);
        $opened = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($name, 'admin.') || str_contains($route->uri(), '{')) {
                continue;
            }
            $res = $this->actingAs($user)->get($this->exportRoute($name, [], (int) $this->shop->id));
            if ($res->baseResponse->getStatusCode() === 200) {
                $opened[] = $name;
            }
        }
        sort($opened);
        $known = [
            'admin.addons.index', 'admin.boutiques.index', 'admin.export.products', 'admin.help.index',
            'admin.notifications.feed', 'admin.notifications.history', 'admin.products.create', 'admin.products.index',
            'admin.products.stockFeed', 'admin.search', 'admin.seasons.index',
            // ملفّاتُ قائمة المنتجات نفسِها: أعمدتُها صنفٌ وسعرٌ وكمّيّةٌ واسمُ بوتيك — لا مبيعَ ولا نسبة
            'admin.products.export.pdf', 'admin.products.export.xlsx', 'admin.products.exportPdf', 'admin.products.xlsx',
        ];
        $this->assertSame([], array_values(array_diff($opened, $known)), 'شاشةٌ جديدةٌ يفتحها مفتاحُ المنتجات — أفيها مبيعاتٌ أو مال؟');
        $this->assertContains('admin.products.index', $opened);
    }

    /* ═══════════ متجرٌ آخر ═══════════ */

    public function test_another_shops_boutique_and_sheet_are_never_reached_by_changing_the_number(): void
    {
        $sheet = $this->issued();
        $other = Business::create(['name' => 'آخر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $theirs = User::create(['business_id' => $other->id, 'name' => 'آخر', 'email' => 'x@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        Boutique::create(['business_id' => $other->id, 'name' => 'عندهم', 'commission_rate' => 5, 'active' => true]);
        $before = $this->state();
        foreach ($this->doors($sheet) as [$method, $url, $data]) {
            $status = $this->actingAs($theirs)->{$method}($url, $data)->status();
            $this->assertContains($status, [302, 404], "{$method} {$url}: {$status}");
        }
        $this->assertSame($before, $this->state());
        $this->assertSame(1, BoutiqueComponentDue::whereNotNull('boutique_settlement_id')->count());
        $this->actingAs($theirs)->get(route('admin.boutiques.show', $this->lama->id))->assertNotFound();
        $this->assertTrue(BoutiqueDues::active($this->shop->id));
    }
}
