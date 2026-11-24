<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
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
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * إيواءُ البوتيكات صفةُ متجرٍ يفتحها مديرُ المنصّة — لا ميزةُ متجرٍ بعينه.
 *
 * أربعةُ متاجر: مفتوحٌ من قبل، وجديدٌ يُفتح له، وجديدٌ لم يُفتح له، ومتجرٌ
 * فيه بوتيكاتٌ ومعاملاتٌ ثمّ أُطفئ. والمفتاحُ يُقلَّب من باب المنصّة نفسِه
 * (`super-admin.businesses.update`) لا بتعديل القاعدة — فما يُثبَت هنا هو ما
 * سيفعله المشغّلُ بيده، بلا كودٍ ولا نشر.
 */
class AnyShopMayHostBoutiquesAndReadsOnlyItsOwnSalesTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private User $super;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        // قوائمُ المتاجر في الإعدادات بمعرّفاتها — لا يُعامَل متجرُ الاختبار معاملةَ متجرٍ بعينه
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ أدوات ═══════════ */

    /** متجرٌ كما يولد: مفتاحُه كما تتركه القاعدة — لا يُكتب */
    private function shop(string $name): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $owner = MerchantAccount::create($shop, 'owner'.(++$this->seq), 'secret12345');
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::firstOrCreate(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::updateOrCreate(['business_id' => $shop->id, 'key' => 'vat_enabled'], ['value' => '0']);

        return [$shop->fresh(), $owner->fresh()];
    }

    /** مديرُ المنصّة يحفظ المتجر من شاشته — بما تُرسله الشاشة */
    private function platformSaves(Business $shop, array $over): void
    {
        $this->actingAs($this->super)->put(
            route('super-admin.businesses.update', $shop->id),
            $over + ['name' => $shop->name, 'type' => 'عام', 'status' => 'نشط'],
        )->assertSessionHasNoErrors();
        $shop->refresh();
    }

    private function boutique(Business $shop, User $owner, string $name, float $rate): Boutique
    {
        $this->actingAs($owner)->post(route('admin.boutiques.store'), ['name' => $name, 'commission_rate' => $rate, 'active' => true])
            ->assertSessionHasNoErrors();

        return Boutique::where('business_id', $shop->id)->where('name', $name)->firstOrFail();
    }

    private function product(Business $shop, string $name, float $price, ?int $boutique = null): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 100,
            'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    /** @param list<array{0: Product, 1?: int}> $lines */
    private function sell(User $owner, array $lines): Order
    {
        $this->actingAs($owner)->postJson('/pos/checkout', [
            'items' => array_map(fn ($l) => ['id' => $l[0]->id, 'name' => $l[0]->name, 'qty' => $l[1] ?? 1, 'price' => (float) $l[0]->price], $lines),
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('m', true),
        ])->assertOk();

        return Order::where('business_id', $owner->business_id)->orderByDesc('id')->firstOrFail();
    }

    private function rows(User $owner, array $params = []): array
    {
        return $this->actingAs($owner)->getJson(route('admin.boutiques.sales', ['period' => self::PERIOD, ...$params]))->assertOk()->json();
    }

    private function summary(User $owner, array $params = []): array
    {
        return $this->actingAs($owner)->getJson(route('admin.boutiques.sales.summary', ['period' => self::PERIOD, ...$params]))->assertOk()->json();
    }

    private function hostsTab(User $owner): bool
    {
        $hosted = null;
        $this->actingAs($owner)->get(route('admin.products.index'))->assertOk()
            ->assertInertia(function (Assert $page) use (&$hosted) {
                $page->where('context.hosted', function ($h) use (&$hosted) {
                    $hosted = collect($h)->contains('boutiques');

                    return true;
                });
            });

        return (bool) $hosted;
    }

    /* ═══════════ جديدٌ لم يُفتح له ═══════════ */

    public function test_a_new_shop_is_born_without_boutiques_whichever_door_made_it(): void
    {
        [$plain] = $this->shop('متجر عادي');
        $this->assertFalse((bool) $plain->boutiques_enabled, 'المفتاحُ مطفأٌ في القاعدة أصلًا');

        // ومن باب المنصّة: «إضافة متجر» بلا ذكر البوتيكات
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'متجر من المنصة', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'fromplatform', 'login_password' => 'secret12345',
        ])->assertSessionHasNoErrors();
        $this->assertFalse((bool) Business::where('name', 'متجر من المنصة')->firstOrFail()->boutiques_enabled);
    }

    public function test_a_shop_without_the_feature_and_without_boutiques_meets_a_closed_door_everywhere(): void
    {
        [, $owner] = $this->shop('متجر بلا بوتيكات');
        [$hosting, $hostOwner] = $this->shop('متجر يؤوي');
        $this->platformSaves($hosting, ['boutiques_enabled' => '1']);
        $theirs = $this->boutique($hosting, $hostOwner, 'بوتيك هناك', 10);
        $theirOrder = $this->sell($hostOwner, [[$this->product($hosting, 'قطعة هناك', 6, $theirs->id)]]);

        $this->assertFalse($this->hostsTab($owner), 'تبويبُ البوتيكات ظاهرٌ لمن لم يُفتح له');

        // كلُّ بابٍ اسمُه admin.boutiques.* — بقراءة الجدول نفسِه، فما يُضاف غدًا يُفحص بلا تعديل
        $doors = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'admin.boutiques.'));
        $this->assertGreaterThanOrEqual(15, $doors->count(), 'لم تُقرأ أبوابُ البوتيك — الاختبارُ لا يقيس شيئًا');
        foreach ($doors as $door) {
            $params = [];
            foreach ($door->parameterNames() as $name) {
                // بوتيكٌ وفاتورةٌ حقيقيّان من متجرٍ آخر — لا معرّفاتٌ لا وجود لها
                $params[$name] = $name === 'order' ? $theirOrder->id : $theirs->id;
            }
            $method = collect($door->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            foreach (['web', 'json'] as $how) {
                $url = route($door->getName(), $params, false);
                $res = $how === 'json'
                    ? $this->actingAs($owner)->json($method, $url, [])
                    : $this->actingAs($owner)->call($method, $url, []);
                $this->assertSame(404, $res->status(), "{$how}: {$method} {$door->getName()} فُتح لمتجرٍ لم يُفتح له");
            }
        }

        $this->assertSame(1, Boutique::count(), 'بابٌ كتب لمتجرٍ لا يملكه');
    }

    /* ═══════════ جديدٌ فُتح له ═══════════ */

    public function test_the_platform_turns_it_on_for_any_shop_and_he_sees_his_own_boutiques_reports_and_invoices(): void
    {
        [$shop, $owner] = $this->shop('متجر جديد');
        $this->assertFalse($this->hostsTab($owner));
        $this->actingAs($owner)->get(route('admin.boutiques.index'))->assertNotFound();

        $this->platformSaves($shop, ['boutiques_enabled' => '1']);
        $this->assertTrue((bool) $shop->boutiques_enabled);
        $this->assertFalse((bool) $shop->boutique_components_enabled, 'فتحُ الإيواء لا يفتح حسابَ قطع الباقات');
        $this->assertTrue($this->hostsTab($owner->fresh()), 'التبويبُ ظهر بعد الفتح بلا كودٍ ولا نشر');
        $this->actingAs($owner)->get(route('admin.boutiques.index'))->assertOk();

        // وعدّةُ بوتيكات، كلٌّ بنسبته وأصنافه
        $noor = $this->boutique($shop, $owner, 'بوتيك نور', 15);
        $sama = $this->boutique($shop, $owner, 'بوتيك سما', 25);
        $ring = $this->product($shop, 'خاتم', 10, $noor->id);
        $scarf = $this->product($shop, 'وشاح', 8, $sama->id);
        $vase = $this->product($shop, 'مزهرية المتجر', 30);

        $first = $this->sell($owner, [[$ring, 2], [$vase]]);
        $second = $this->sell($owner, [[$ring], [$scarf, 3]]);

        $this->actingAs($owner)->get(route('admin.boutiques.show', $noor->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSeeSales', true)->has('boutiqueOptions', 2));

        $rows = collect($this->rows($owner)['rows']);
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([$first->number, $second->number, $second->number], $rows->pluck('order_number')->all());

        // لكلّ بوتيكٍ مبيعاتُه وعمولتُه وحده
        $n = $this->summary($owner, ['boutique' => (string) $noor->id]);
        $s = $this->summary($owner, ['boutique' => (string) $sama->id]);
        $this->assertEquals([30, 4.5, 25.5], [$n['standalone']['gross'], $n['standalone']['commission'], $n['owed']], '٣ خواتم × ١٠ بنسبة ١٥٪');
        $this->assertEquals([24, 6, 18], [$s['standalone']['gross'], $s['standalone']['commission'], $s['owed']], '٣ أوشحة × ٨ بنسبة ٢٥٪');
        $all = $this->summary($owner);
        $this->assertEquals(43.5, $all['owed']);
        $this->assertEquals(round($n['owed'] + $s['owed'], 3), $all['owed'], 'مجموعُ البوتيكين هو الكلّ');
        $this->assertEquals([2, 1], [$this->rows($owner, ['boutique' => (string) $noor->id])['total'], $this->rows($owner, ['boutique' => (string) $sama->id])['total']]);

        // وفاتورتُه بأنصبتها: المتجرُ وبوتيكاه
        $inv = $this->actingAs($owner)->getJson(route('admin.boutiques.sales.order', $second->id))->assertOk()->json();
        $owed = collect($inv['shares']['boutiques'])->pluck('owed', 'name');
        $this->assertEquals(['بوتيك نور' => 8.5, 'بوتيك سما' => 18.0], $owed->all());
        $this->assertEquals(7.5, $inv['shares']['shop'], 'عمولتا المتجر ١٫٥ + ٦');
        $this->assertEquals(34, $inv['shares']['lines_total']);
        $this->assertTrue($inv['hosting'] ?? true);

        // والكشفُ القائم للبوتيك نفسِه يقرأ ما يقرؤه القسم
        $this->assertEquals(Boutiques::statement($shop->id, $noor->id, self::PERIOD)['net'], $n['owed']);
    }

    public function test_an_employee_of_a_newly_enabled_shop_sees_only_what_his_owner_granted_him(): void
    {
        [$shop, $owner] = $this->shop('متجر بموظفين');
        $this->platformSaves($shop, ['boutiques_enabled' => '1']);
        $noor = $this->boutique($shop, $owner, 'بوتيك نور', 15);
        $order = $this->sell($owner, [[$this->product($shop, 'خاتم', 10, $noor->id)]]);

        $staff = fn (string $mail, array $permissions) => User::create([
            'business_id' => $shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('x'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
        $plain = $staff('plain', ['dashboard', 'products']);
        // صاحبُ بوتيكٍ أُعطي حسابًا لإدخال أصنافه: موظفٌ كغيره، لا صلاحيةَ له بملكيّته
        $boutiqueOwner = $staff('noor', ['dashboard', 'products']);
        $trusted = $staff('trusted', ['dashboard', 'products', 'expenses']);

        foreach ([route('admin.boutiques.sales', ['period' => self::PERIOD]), route('admin.boutiques.sales.summary', ['period' => self::PERIOD]), route('admin.boutiques.sales.order', $order->id)] as $url) {
            $this->actingAs($plain)->getJson($url)->assertForbidden();
            $this->actingAs($boutiqueOwner)->getJson($url)->assertForbidden();
            $this->actingAs($trusted)->getJson($url)->assertOk();
        }
    }

    /* ═══════════ أُطفئ وفيه بوتيكاتٌ ومعاملات ═══════════ */

    public function test_switching_it_off_hides_no_obligation_and_deletes_nothing(): void
    {
        [$shop, $owner] = $this->shop('متجر سابق');
        $this->platformSaves($shop, ['boutiques_enabled' => '1']);
        $this->platformSaves($shop, ['boutiques_enabled' => '1', 'boutique_components_enabled' => '1']);
        $noor = $this->boutique($shop, $owner, 'بوتيك نور', 15);
        $sama = $this->boutique($shop, $owner, 'بوتيك سما', 25);
        $ring = $this->product($shop, 'خاتم', 10, $noor->id);
        $scarf = $this->product($shop, 'وشاح', 8, $sama->id);
        $set = $this->product($shop, 'هدية المتجر', 40);
        RecipeItem::create(['business_id' => $shop->id, 'product_id' => $set->id, 'component_product_id' => $ring->id, 'quantity' => 1, 'wastage_percent' => 0]);
        $order = $this->sell($owner, [[$ring, 2], [$scarf], [$set]]);

        $this->assertSame(1, BoutiqueComponentDue::count(), 'القطعةُ في الهدية مستحقٌّ كُتب');
        $before = [
            'rows' => $this->rows($owner)['rows'],
            'summary' => $this->summary($owner),
            'invoice' => $this->actingAs($owner)->getJson(route('admin.boutiques.sales.order', $order->id))->json(),
        ];
        $counts = fn () => [
            'orders' => Order::count(), 'items' => OrderItem::whereNotNull('boutique_id')->count(),
            'dues' => BoutiqueComponentDue::count(), 'boutiques' => Boutique::count(), 'sheets' => BoutiqueSettlement::count(),
        ];
        $kept = $counts();
        $this->assertEquals(33, $before['summary']['owed'], 'خاتمان لنور ١٧ (٢٠ − ١٥٪)، ووشاح لسما ٦ (٨ − ٢٥٪)، وقطعةُ نور في الهدية ١٠');

        // مديرُ المنصّة يُطفئ الإيواء من شاشته
        $this->platformSaves($shop, ['boutiques_enabled' => '0']);
        $this->assertFalse((bool) $shop->boutiques_enabled);
        $this->assertSame($kept, $counts(), 'إطفاءُ المفتاح محا شيئًا');

        // فالشاشةُ باقية، والأرقامُ هي الأرقام — لا التزامٌ يُخفى
        $this->assertTrue($this->hostsTab($owner->fresh()), 'أُطفئ المفتاحُ واختفى تبويبٌ خلفه مالٌ قائم');
        $this->actingAs($owner)->get(route('admin.boutiques.index'))->assertOk();
        $this->actingAs($owner)->get(route('admin.boutiques.show', $noor->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSeeSales', true));

        $after = $this->summary($owner);
        $this->assertFalse($after['hosting'], 'الشاشةُ تقول إنّ المفتاح مطفأ');
        $this->assertSame('off', $after['pieces']);
        $this->assertTrue($after['pieces_recorded']);
        foreach (['standalone', 'components', 'bundles', 'owed', 'settlement', 'returns'] as $k) {
            $this->assertEquals($before['summary'][$k], $after[$k], "الإطفاءُ غيّر «{$k}»");
        }
        $this->assertEquals($before['rows'], $this->rows($owner)['rows'], 'الإطفاءُ غيّر صفوفَ الفواتير');
        $this->assertEquals($before['invoice'], $this->actingAs($owner)->getJson(route('admin.boutiques.sales.order', $order->id))->json(), 'الإطفاءُ غيّر الفاتورة');

        // وما يُباع بعدُ: القطعةُ في الهدية لا تُستحقّ، والمنفردُ كما كان
        $late = $this->sell($owner, [[$ring], [$set]]);
        $this->assertSame(1, BoutiqueComponentDue::count(), 'بيعٌ بعد الإطفاء كتب مستحقًّا');
        $this->assertSame(1, collect($this->rows($owner)['rows'])->where('order_number', $late->number)->count(), 'المنفردُ وحده');

        // ويُسوّى الدينُ القائم — لا يُقطع على صاحب البوتيك
        Carbon::setTestNow('2027-03-01 10:00:00');
        $this->actingAs($owner)->post(route('admin.boutiques.settle', $noor->id), ['period' => self::PERIOD])->assertSessionHasNoErrors();
        $this->assertSame(1, BoutiqueSettlement::where('boutique_id', $noor->id)->count());
        $this->assertGreaterThan(0, (float) BoutiqueSettlement::where('boutique_id', $noor->id)->value('net'));

        // وإعادةُ الفتح من المنصّة ترجع كلَّ شيءٍ كما كان
        $this->platformSaves($shop, ['boutiques_enabled' => '1', 'boutique_components_enabled' => '1']);
        $this->assertTrue($this->summary($owner)['hosting']);
    }

    /**
     * ما يقوله تنبيهُ الشاشة يطابق ما يفعله باب السداد اليوم.
     *
     * بعد إطفاء حساب القطع يُردّ سدادُ جزئها من ورقةٍ صدرت (٤٠٤) ولو كانت
     * مستحقّة — ثغرةٌ قائمة منذ ‎#146‎ معروضةٌ للقرار، لا تُصلَح هنا لأنّها
     * مسارُ مال. وإن أُصلحت سقط هذا الاختبار: حدِّث التنبيه والتقرير معه.
     */
    public function test_the_note_says_what_the_pay_door_does_while_the_pieces_are_off(): void
    {
        [$shop, $owner] = $this->shop('متجر بمستحقّ قديم');
        $this->platformSaves($shop, ['boutiques_enabled' => '1']);
        $this->platformSaves($shop, ['boutiques_enabled' => '1', 'boutique_components_enabled' => '1']);
        $noor = $this->boutique($shop, $owner, 'بوتيك نور', 15);
        $ring = $this->product($shop, 'خاتم', 10, $noor->id);
        $set = $this->product($shop, 'هدية', 40);
        RecipeItem::create(['business_id' => $shop->id, 'product_id' => $set->id, 'component_product_id' => $ring->id, 'quantity' => 1, 'wastage_percent' => 0]);
        $this->sell($owner, [[$set]]);
        Carbon::setTestNow('2027-03-01 10:00:00');
        $this->actingAs($owner)->post(route('admin.boutiques.settle', $noor->id), ['period' => self::PERIOD])->assertSessionHasNoErrors();
        $sheet = BoutiqueSettlement::firstOrFail();
        $this->assertEquals(10, $sheet->components_due);

        $this->platformSaves($shop, ['boutiques_enabled' => '0', 'boutique_components_enabled' => '0']);
        $this->actingAs($owner->fresh())->post(route('admin.boutiques.components.pay', [$noor->id, $sheet->id]), ['method' => 'نقدي'])->assertNotFound();
        $this->assertNull($sheet->fresh()->components_paid_at);

        $s = $this->summary($owner->fresh());
        $this->assertEquals(10, $s['settlement']['unpaid'], 'الالتزامُ يُرى ولو تعذّر سدادُه من الشاشة');
        $this->assertSame('off', $s['pieces']);
        $this->assertTrue($s['pieces_recorded']);

        // وإعادةُ التشغيل تفتح السداد — فلا التزامَ عالقٌ بلا مخرج
        $this->platformSaves($shop, ['boutiques_enabled' => '1']);
        $this->platformSaves($shop, ['boutiques_enabled' => '1', 'boutique_components_enabled' => '1']);
        $this->actingAs($owner->fresh())->post(route('admin.boutiques.components.pay', [$noor->id, $sheet->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertNotNull($sheet->fresh()->components_paid_at);
    }

    /* ═══════════ عزلُ المتاجر ═══════════ */

    public function test_two_shops_with_the_same_names_and_numbers_never_read_each_other(): void
    {
        [$a, $ownerA] = $this->shop('متجر ألف');
        [$b, $ownerB] = $this->shop('متجر باء');
        foreach ([$a, $b] as $shop) {
            $this->platformSaves($shop, ['boutiques_enabled' => '1']);
        }
        // الاسمُ نفسُه في المتجرين، ورقمُ الفاتورة يبدأ في كلٍّ من أوّله
        $boutiqueA = $this->boutique($a, $ownerA, 'بوتيك الورد', 10);
        $boutiqueB = $this->boutique($b, $ownerB, 'بوتيك الورد', 40);
        $orderA = $this->sell($ownerA, [[$this->product($a, 'قطعة ألف', 10, $boutiqueA->id)]]);
        $orderB = $this->sell($ownerB, [[$this->product($b, 'قطعة باء', 70, $boutiqueB->id), 2]]);
        $this->assertSame($orderA->number, $orderB->number, 'رقما الفاتورتين متطابقان — وإلّا لم يُختبر التعارض');

        foreach ([[$ownerA, $orderB, $boutiqueB, 'قطعة ألف', 10, 1], [$ownerB, $orderA, $boutiqueA, 'قطعة باء', 140, 56]] as [$owner, $foreign, $foreignBoutique, $mine, $gross, $commission]) {
            $rows = $this->rows($owner)['rows'];
            $this->assertSame([$mine], collect($rows)->pluck('product')->all());
            // والبحثُ برقم الفاتورة المشترك لا يأتي بفاتورة الجار
            $this->assertSame([$mine], collect($this->rows($owner, ['q' => $foreign->number])['rows'])->pluck('product')->all());
            $s = $this->summary($owner);
            $this->assertEquals([$gross, $commission], [$s['standalone']['gross'], $s['standalone']['commission']]);

            // رقمُ بوتيك الجار في الرابط، ورقمُ فاتورته بالمعرّف، وشاشتُه
            $this->assertSame([$mine], collect($this->rows($owner, ['boutique' => (string) $foreignBoutique->id])['rows'])->pluck('product')->all());
            $this->assertEquals($gross, $this->summary($owner, ['boutique' => (string) $foreignBoutique->id])['standalone']['gross']);
            $this->actingAs($owner)->getJson(route('admin.boutiques.sales.order', $foreign->id))->assertNotFound();
            $this->actingAs($owner)->get(route('admin.boutiques.show', $foreignBoutique->id))->assertNotFound();
            $this->actingAs($owner)->get(route('admin.boutiques.show', $foreignBoutique->id + 9999))->assertNotFound();
            $this->actingAs($owner)->put(route('admin.boutiques.update', $foreignBoutique->id), ['name' => 'اختطاف', 'commission_rate' => 0])->assertNotFound();
            $this->actingAs($owner)->post(route('admin.boutiques.settle', $foreignBoutique->id), ['period' => self::PERIOD])->assertNotFound();
        }
        $this->assertSame(['بوتيك الورد', 'بوتيك الورد'], Boutique::orderBy('id')->pluck('name')->all());
        $this->assertSame(0, BoutiqueSettlement::count());
    }

    /* ═══════════ لا متجرَ بعينه في المنطق ═══════════ */

    public function test_no_shop_name_number_or_boutique_is_written_into_the_feature(): void
    {
        foreach ([
            'app/Support/BoutiqueSales.php', 'app/Support/Boutiques.php', 'app/Http/Controllers/Admin/BoutiqueController.php',
            'resources/js/Pages/Admin/Boutiques/SalesPanel.tsx', 'resources/js/Pages/Admin/Boutiques/Show.tsx', 'resources/js/Pages/Admin/Boutiques/Index.tsx',
        ] as $file) {
            if (! is_file(base_path($file))) {
                continue;
            }
            $code = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/سعود|\bsaud\b|RIBBON|ribbon_|زهرة مسقط/iu', $code, "{$file}: اسمُ متجرٍ بعينه في المنطق");
            $this->assertDoesNotMatchRegularExpression('/(business_id|businessId|boutique_id|boutiqueId)[\'"]?\s*(=>|===|==|,)\s*\d+/', $code, "{$file}: رقمُ متجرٍ أو بوتيكٍ مكتوبٌ في المنطق");
        }
    }
}
