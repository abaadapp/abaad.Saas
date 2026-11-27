<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\ImageVariants;
use App\Support\TillCatalog;
use App\Support\TillCatalogSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ‏‏مفتاحُ كتالوج الصندوق على الجهاز — بيد مدير المنصّة، ويسري من الطلب التالي.
 *
 * ═══ ما كان ═══
 *
 * متغيّرُ بيئة (`POS_CATALOG_ON_DEVICE`) يُقرأ من الإعداد المخبّأ. وopcache على الخادم لا
 * يفحص تواريخ الملفّات، فأُطفئ في 2026-11-20 بـ`.env` و`config:cache` ولم يتغيّر ما يقدّمه
 * الموقع: يحتاج إعادةَ تحميل php-fpm فوقهما.
 *
 * ═══ ما صار ═══
 *
 * صفٌّ في إعدادات المنصّة يُقرأ في كلّ طلب (`TillCatalogSwitch`)، وبابٌ واحد يكتبه لمدير
 * المنصّة وحده، ويقيّد من بدّله وكان ماذا وصار ماذا. مطفأً: الصفحةُ كما كانت قبل v6.600،
 * وسؤالُ الكتالوج يقول «مطفأ» بلا صنف. ومشغَّلًا من جديد: لا يُعفى رمزُ جهازٍ صدر قبل
 * التبديل — يُقارَن الصفُّ المبنيّ نفسُه.
 */
class ThePlatformTurnsTheTillCatalogOnAndOffTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private User $owner;

    private User $platform;

    /** @var list<Product> */
    private array $shelf = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['pos.catalog_release' => 'r1']);
        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $flowers = Category::create(['business_id' => $this->business->id, 'name' => 'ورد']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        for ($k = 1; $k <= 40; $k++) {
            $this->shelf[] = Product::create([
                'business_id' => $this->business->id, 'category_id' => $flowers->id, 'name' => "صنف $k", 'name_en' => "Item $k",
                'price' => 2 + $k / 1000, 'cost' => 1, 'quantity' => 50, 'alert_qty' => 5, 'active' => true,
                'discount' => $k % 7 === 0 ? 15 : 0, 'tax' => $k % 5 === 0 ? 0 : null,
            ]);
        }

        $this->activatePosDevice($this->business->id, $this->muscat->id);
        $this->asOwner();
        // ‏‏الجهازُ يفتح بعد أن هدأت الكتابة (`TillCatalog::SETTLED`)
        Carbon::setTestNow(now()->addSeconds(5));
    }

    private function asOwner(): void
    {
        $this->actingAs($this->owner);
        session(['current_branch' => $this->muscat->id]);
    }

    /** ‏‏مديرُ المنصّة يضغط المفتاح — من الشاشة، ثمّ يعود التاجرُ إلى صندوقه */
    private function flip(bool $on)
    {
        $this->actingAs($this->platform);
        $r = $this->from(route('super-admin.settings.index'))->post(route('super-admin.pos-catalog.update'), ['enabled' => $on]);
        $this->asOwner();

        return $r;
    }

    private function scope(): string
    {
        return TillCatalog::scope($this->business->id, $this->muscat->id, 'ar');
    }

    private function ask(?array $known = null)
    {
        $body = ['scope' => $this->scope()];
        if ($known) {
            $body += ['t' => $known['t'], 'b' => $known['b'], 'ih' => implode('', $known['ih']), 'oh' => implode('', $known['oh'])];
        }

        return $this->withCredentials()->postJson(route('pos.catalog-feed'), $body);
    }

    /** ‏‏ما يحفظه الجهازُ من الكامل، ثمّ ما يطبّقه من سلالٍ تغيّرت — بقواعد `applyCatalog` */
    private function fresh(): array
    {
        $d = $this->ask()->assertOk()->json();
        $this->assertTrue($d['full'] ?? false);

        return ['t' => $d['t'], 'v' => $d['v'], 'b' => $d['b'], 'ih' => str_split($d['ih'], TillCatalog::HASH), 'oh' => str_split($d['oh'], TillCatalog::HASH),
            'rows' => collect($d['products'])->keyBy('id')->all()];
    }

    private function apply(array $known, array $d): array
    {
        if ($d['same'] ?? false) {
            return ['t' => $d['t']] + $known;
        }
        $this->assertSame($known['v'], $d['base']);
        $rows = array_filter($known['rows'], fn ($r) => ! in_array($r['id'] % $known['b'], $d['rows'], true));
        foreach ($d['products'] as $r) {
            $rows[$r['id']] = $r;
        }
        [$ih, $oh] = [$known['ih'], $known['oh']];
        foreach ($d['buckets'] as [$i, $a, $o]) {
            [$ih[$i], $oh[$i]] = [$a, $o];
        }

        return ['t' => $d['t'], 'v' => $d['v'], 'b' => $known['b'], 'ih' => $ih, 'oh' => $oh, 'rows' => $rows];
    }

    private function assertSameAsThePage(array $known, string $what): void
    {
        $strip = function (array $rows) {
            ksort($rows);

            return array_map(fn ($r) => array_diff_key($r, ['qty' => 1, 'stock_status' => 1]), $rows);
        };
        $this->assertSame($strip(collect(Demo::tillProducts($this->muscat->id))->keyBy('id')->all()), $strip($known['rows']), $what);
    }

    private static function asSent(array $rows): array
    {
        return json_decode(json_encode($rows), true);
    }

    /* ─────────────────────────── بلا ضغط: كما هو ─────────────────────────── */

    /** ‏‏لا صفّ = مشغَّل: لا يتغيّر شيءٌ عند التجّار حتى يُضغط المفتاح */
    public function test_untouched_the_till_works_as_it_does_since_v6600(): void
    {
        $this->assertNull(Setting::whereNull('business_id')->where('key', TillCatalogSwitch::KEY)->first());
        $this->assertTrue(TillCatalogSwitch::on());

        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('products')
            ->where('catalog.scope', $this->scope())
            ->where('stock.full', true));
        $this->assertTrue($this->ask()->assertOk()->json('full'));
    }

    /* ─────────────────────────── الإطفاء ─────────────────────────── */

    public function test_the_platform_manager_turns_it_off_and_the_next_page_and_question_obey(): void
    {
        $this->flip(false)->assertRedirect(route('super-admin.settings.index'))->assertSessionHas('toast');

        $this->assertSame('0', Setting::whereNull('business_id')->where('key', TillCatalogSwitch::KEY)->value('value'));
        $this->assertFalse(TillCatalogSwitch::on());

        // ‏‏الصفحةُ كما كانت قبل v6.600 حرفًا: الأصنافُ كلُّها، ولا مفتاحَ نسخةٍ ولا لقطة
        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products', self::asSent(Demo::tillProducts($this->muscat->id)))
            ->where('catalog', null)
            ->where('stock', null));

        // ‏‏والصندوقُ المفتوح يُقال له «مطفأ» — بلا صنفٍ ولا بصمة
        $d = $this->ask()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame(['off' => true, 'scope' => $this->scope()], $d);
    }

    /** ‏‏ومن بدّله ومتى وكان ماذا وصار ماذا — في سجلّ النشاط، سطرٌ لكلّ تبديل */
    public function test_every_flip_is_written_down_with_who_when_and_from_what(): void
    {
        $this->flip(false);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->flip(true);

        $rows = ActivityLog::where('subject_type', Setting::class)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$this->platform->id, $this->platform->id], $rows->pluck('user_id')->all());
        $this->assertSame(['مدير المنصّة', 'مدير المنصّة'], $rows->pluck('user_name')->all());
        $this->assertStringContainsString('كان مشغّلًا وصار مطفأً', $rows[0]->description);
        $this->assertStringContainsString('كان مطفأً وصار مشغّلًا', $rows[1]->description);
        $this->assertTrue($rows[1]->created_at->gt($rows[0]->created_at));

        // ‏‏والشاشةُ تعرضها: الأحدثُ أوّلًا
        $this->actingAs($this->platform);
        $this->get(route('super-admin.settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('posCatalog.on', true)
            ->where('posCatalog.platform', true)
            ->where('posCatalog.env', true)
            ->where('posCatalog.readable', true)
            ->has('posCatalog.history', 2)
            ->where('posCatalog.history.0.by', 'مدير المنصّة')
            ->where('posCatalog.history.0.from', false)
            ->where('posCatalog.history.0.to', true)
            ->where('posCatalog.history.1.from', true)
            ->where('posCatalog.history.1.to', false));
    }

    /** ‏‏وما هو عليه أصلًا لا يُكتب ولا يُقيَّد — ضغطتان من تبويبين لا تصنعان سطرين */
    public function test_flipping_to_where_it_already_is_writes_nothing(): void
    {
        $this->flip(true)->assertRedirect();
        $this->assertNull(Setting::whereNull('business_id')->where('key', TillCatalogSwitch::KEY)->first(), 'لا صفّ = مشغَّل');

        $this->flip(false);
        $this->flip(false);
        $this->assertSame(1, ActivityLog::where('subject_type', Setting::class)->count());
    }

    /* ─────────────────────────── التشغيل من جديد ─────────────────────────── */

    /**
     * ‏‏ما يعود إليه الجهازُ بعد التشغيل مُتحقَّقٌ منه صفًّا صفًّا — لا ما كان عنده يوم أُطفئ.
     *
     * والدليلُ تغييرٌ لا تراه المُدخلاتُ ولا المؤشّرات: مصغّرةُ صورةٍ صُنعت على القرص وهو
     * مطفأ. فلولا أنّ التبديلَ في السياق لقال الرمزُ «كما هو» وبقيت الصورةُ القديمة.
     */
    public function test_turned_on_again_a_copy_from_before_is_checked_row_by_row(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/rose.jpg', 'x');
        DB::table('products')->where('id', $this->shelf[6]->id)->update(['image' => 'products/1/rose.jpg']);
        Carbon::setTestNow(now()->addSeconds(5));
        $known = $this->fresh();
        $before = $known['rows'][$this->shelf[6]->id]['thumb'];

        $this->flip(false);
        Storage::disk('public')->put(ImageVariants::path('products/1/rose.jpg', ImageVariants::THUMB), 'x');
        $this->flip(true);

        // ‏‏والرمزُ صالحٌ وحديث، والمؤشّراتُ لم تتحرّك — ولا يُعفى
        $d = $this->ask($known)->assertOk()->json();
        $this->assertFalse($d['same'] ?? false, 'رمزٌ صدر قبل التبديل أعفى من المقارنة');
        $known = $this->apply($known, $d);
        $this->assertNotSame($before, $known['rows'][$this->shelf[6]->id]['thumb']);
        $this->assertSameAsThePage($known, 'بعد التشغيل');

        // ‏‏ثمّ يعود إلى ما كان: «كما هو» بلا قراءة صفّ
        $this->assertTrue($this->ask($known)->assertOk()->json('same'));
    }

    /** ‏‏وما تغيّر وهو مطفأ يصل الجهازَ حين يُشغَّل: السعرُ والخصمُ والحذفُ والجديد */
    public function test_what_changed_while_off_reaches_the_device_when_turned_on(): void
    {
        $known = $this->fresh();
        $this->flip(false);

        $this->shelf[3]->update(['price' => 9.875]);
        $this->shelf[8]->update(['discount' => 20]);
        $this->shelf[11]->delete();
        Product::create(['business_id' => $this->business->id, 'name' => 'جديد', 'price' => 4.25, 'quantity' => 3, 'active' => true]);
        $this->assertSame(['off' => true, 'scope' => $this->scope()], $this->ask($known)->json(), 'مطفأً لا يصل شيء');

        $this->flip(true);
        Carbon::setTestNow(now()->addSeconds(5));
        $known = $this->apply($known, $this->ask($known)->assertOk()->json());
        $this->assertSameAsThePage($known, 'ما تغيّر وهو مطفأ');
    }

    /* ─────────────────────────── ما يغلبه، وما لا يُقرأ ─────────────────────────── */

    /** ‏‏إطفاءُ الخادم يغلب المنصّة — ولا يُشعَل من الشاشة ما أطفأه */
    public function test_the_server_switch_wins_over_the_platform(): void
    {
        config(['pos.catalog_on_device' => false]);
        $this->flip(true);

        $this->assertFalse(TillCatalogSwitch::on());
        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalog', null)
            ->has('products', count($this->shelf)));
        $this->assertTrue($this->ask()->assertOk()->json('off'));

        $this->actingAs($this->platform);
        $this->get(route('super-admin.settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('posCatalog.on', false)
            ->where('posCatalog.env', false)
            ->where('posCatalog.platform', true));
    }

    /**
     * ‏‏مفتاحٌ لا يُقرأ = مطفأ: الطريقُ القديم، أصنافُ الخادم — لا كتالوجٌ لم يُتحقَّق منه الآن.
     *
     * يُرمى الخطأُ قبل أن يبلغ السؤالُ القاعدة: فلا تُفسد معاملةُ الاختبار على PostgreSQL،
     * ولا يُمسّ غيرُ سؤال المفتاح (ضريبةُ المتجر تُقرأ من الجدول نفسِه).
     */
    public function test_a_switch_that_cannot_be_read_is_off(): void
    {
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) {
            if (in_array(TillCatalogSwitch::KEY, $bindings, true)) {
                throw new \RuntimeException('settings unreadable');
            }
        });

        $this->assertFalse(TillCatalogSwitch::on());
        $this->assertSame('', TillCatalogSwitch::epoch());
        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalog', null)
            ->where('products', self::asSent(Demo::tillProducts($this->muscat->id))));
        $this->assertTrue($this->ask()->assertOk()->json('off'));

        $this->actingAs($this->platform);
        $this->get(route('super-admin.settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('posCatalog.on', false)
            ->where('posCatalog.readable', false));
    }

    /* ─────────────────────────── من يملكه ─────────────────────────── */

    /** ‏‏لا تاجرٌ ولا مديرُ فرعٍ ولا كاشير ولا زائر — ولا يُكتب شيءٌ ولا يُقيَّد */
    public function test_only_the_platform_manager_can_flip_it(): void
    {
        $manager = User::create(['business_id' => $this->business->id, 'name' => 'المدير', 'email' => 'm@abaad.om', 'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'نشط']);
        $cashier = User::create(['business_id' => $this->business->id, 'name' => 'الكاشير', 'email' => 'c@abaad.om', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);

        foreach ([$this->owner, $manager, $cashier] as $who) {
            $this->actingAs($who)->post(route('super-admin.pos-catalog.update'), ['enabled' => false])->assertForbidden();
        }
        auth()->logout();
        $this->post(route('super-admin.pos-catalog.update'), ['enabled' => false])->assertRedirect(route('login'));

        $this->assertNull(Setting::whereNull('business_id')->where('key', TillCatalogSwitch::KEY)->first());
        $this->assertSame(0, ActivityLog::where('subject_type', Setting::class)->count());
        $this->assertTrue(TillCatalogSwitch::on());
    }

    /** ‏‏ولا يُكتب من نموذج الإعدادات العامّ — له بابٌ واحد يقيّد من بدّله */
    public function test_the_general_settings_form_cannot_write_it(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.settings.update'), [TillCatalogSwitch::KEY => '0'])->assertRedirect();

        $this->assertNull(Setting::whereNull('business_id')->where('key', TillCatalogSwitch::KEY)->first());
        $this->assertTrue(TillCatalogSwitch::on());
    }

    /** ‏‏وقيمةٌ ليست «نعم» ولا «لا» تُردّ ولا تُكتب */
    public function test_a_value_that_is_not_on_or_off_is_refused(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.pos-catalog.update'), ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
        $this->post(route('super-admin.pos-catalog.update'), [])->assertSessionHasErrors('enabled');

        $this->assertNull(Setting::whereNull('business_id')->where('key', TillCatalogSwitch::KEY)->first());
    }
}
