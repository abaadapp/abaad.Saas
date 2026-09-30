<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\NewArrivals;
use App\Support\Store\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «وصل حديثًا» يختاره صاحبُه بيده ويرتّبه — لمتجر سعود وحده.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لمن في `storefront.ribbon_curated_new_arrivals_businesses` وحده. ومن
 *     ليس فيها يبقى على أحدث أربعة، ويُردّ حفظُه للمفتاحين بـ403.
 *   - التلقائيّ كما كان: أحدثُ أربعةٍ معروضة بالمعرّف، وتعديلُ صنفٍ قديم لا
 *     يرفعه.
 *   - اليدويّ بترتيبه، ممّا تعرضه الواجهةُ وحده: ما أُخفي بعد اختياره يسقط
 *     ولا يُحشى مكانُه، وإن سقط كلُّه عاد القسمُ تلقائيًّا.
 *   - والحفظُ لا يأتمن الشاشة: أصنافُ المتجر المعروضةُ وحدها، أربعةٌ لا
 *     أكثر، أرقامٌ موجبة، والمكرَّرُ يُطوى.
 *   - ولا يمسّ الصنفَ نفسَه: معرّفُه وتاريخاه ونشرُه كما كانت.
 *
 * والمتجران هنا عامّان — يُكتب معرّفُ أحدهما في الإعداد، ولا يُفرض المعرّف ٥.
 * وسلوكُ اللوحة في `tests/js/the-owner-orders-his-new-arrivals-by-hand.test.tsx`.
 */
class AListedShopChoosesItsNewArrivalsByHandTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private User $otherOwner;

    /** @var array<int, int> المعرّفُ بترتيب الإنشاء — ١ أقدمُها */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'saud@abaad.om');
        [$this->other, $this->otherOwner] = $this->ribbon('other', 'other@abaad.om');

        foreach (range(1, 6) as $n) {
            $this->p[$n] = $this->product($this->shop, 'صنف '.$n)->id;
        }

        config([
            'storefront.ribbon_curated_new_arrivals_businesses' => [$this->shop->id],
            'storefront.ribbon_catalog_editor_businesses' => [$this->shop->id],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        $owner = User::create(['business_id' => $shop->id, 'name' => 'مالك '.$slug, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function product(Business $shop, string $name, array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $shop->id, 'name' => $name, 'price' => 10,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** @return list<int> «وصل حديثًا» كما تعرضه الواجهة */
    private function shown(string $slug = 'ribbon'): array
    {
        return array_column($this->get("/s/{$slug}")->assertOk()->viewData('new'), 'id');
    }

    private function saveAs(User $user, array $payload)
    {
        return $this->actingAs($user)->from(route('admin.website.editor'))->post(route('admin.marketing.store.save'), $payload);
    }

    private function stored(Business $shop): array
    {
        $g = MarketingSettings::group($shop->id, 'website');

        return [$g['store_new_arrivals_mode'], $g['store_new_arrivals']];
    }

    /** @return array<string, mixed>|null */
    private function catalogTools(): ?array
    {
        return $this->actingAs($this->owner)
            ->get(route('admin.website.editor'))
            ->assertOk()
            ->viewData('page')['props']['catalogTools'] ?? null;
    }

    /* ═══════════ لمن ═══════════ */

    public function test_the_shipped_list_is_saud_alone_and_apart_from_the_others(): void
    {
        $config = require config_path('storefront.php');

        $this->assertSame([5], $config['ribbon_curated_new_arrivals_businesses']);
        $this->assertSame([5], $config['ribbon_catalog_editor_businesses'], 'قائمةُ لوحتَي المحرّر كما هي');
        $this->assertSame([5], $config['paymob_businesses'], 'قائمةُ البوّابة كما هي');
    }

    public function test_no_business_id_is_written_outside_the_list(): void
    {
        $files = [
            app_path('Support/Store/NewArrivals.php'),
            app_path('Support/Store/StorePage.php'),
            app_path('Support/Store/CatalogTools.php'),
            app_path('Http/Controllers/Store/RibbonController.php'),
            app_path('Http/Controllers/Admin/Marketing/MarketingController.php'),
            resource_path('js/Pages/Admin/Website/ThemeEditor.tsx'),
            resource_path('js/Pages/Admin/Website/theme/CatalogTools.tsx'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(business_?[iI]d|bid|id)\s*(===?|!==?)\s*5\b|\[\s*5\s*\]/',
                file_get_contents($file),
                basename($file).' يسأل عن متجرٍ بمعرّفه',
            );
        }
    }

    public function test_the_defaults_leave_every_shop_on_automatic(): void
    {
        $defaults = MarketingSettings::GROUPS['website'];

        $this->assertSame('auto', $defaults['store_new_arrivals_mode']);
        $this->assertSame('', $defaults['store_new_arrivals']);
        $this->assertSame(['auto', ''], $this->stored($this->shop));
    }

    public function test_a_shop_outside_the_list_stays_automatic_even_with_a_manual_setting(): void
    {
        $theirs = [];
        foreach (range(1, 5) as $n) {
            $theirs[$n] = $this->product($this->other, 'صنفهم '.$n)->id;
        }

        // يُكتب في الجدول بلا بابِ الحفظ — ولا يُقرأ
        MarketingSettings::save($this->other->id, 'website', ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $theirs[1].','.$theirs[2]]);

        $this->assertSame([$theirs[5], $theirs[4], $theirs[3], $theirs[2]], $this->shown('other'));
    }

    public function test_a_shop_outside_the_list_cannot_turn_it_on_by_posting(): void
    {
        $mine = $this->product($this->other, 'صنفهم')->id;

        foreach ([
            ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => (string) $mine],
            ['store_new_arrivals_mode' => 'manual'],
            ['store_new_arrivals' => (string) $mine],
            // ولو خُبّئ المفتاحُ بين حقولٍ مشروعة — لا يُكتب منها شيء
            ['store_headline' => 'عنوان', 'store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => (string) $mine],
        ] as $payload) {
            $this->saveAs($this->otherOwner, $payload)->assertForbidden();
        }

        $this->assertSame(['auto', ''], $this->stored($this->other));
        $this->assertNotSame('عنوان', MarketingSettings::group($this->other->id, 'website')['store_headline']);

        // وحفظُه المعتاد لا يتعثّر بما لا يرسله
        $this->saveAs($this->otherOwner, ['store_headline' => 'عنوان'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('عنوان', MarketingSettings::group($this->other->id, 'website')['store_headline']);
    }

    /** وكلُّ ما سوى `manual` تلقائيّ — قيمةٌ فارغةٌ أو غريبةٌ في الجدول لا تقلب القسم */
    public function test_anything_but_manual_reads_as_automatic(): void
    {
        foreach (['', 'MANUAL', 'Manual', 'hand', 'auto'] as $raw) {
            $this->assertSame('auto', StorePage::mode($raw), json_encode($raw));

            MarketingSettings::save($this->shop->id, 'website', ['store_new_arrivals_mode' => $raw, 'store_new_arrivals' => $this->p[1].','.$this->p[2]]);
            $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], $this->shown(), json_encode($raw));
        }

        $this->assertSame('manual', StorePage::mode('manual'));
    }

    /* ═══════════ التلقائيّ ═══════════ */

    public function test_automatic_is_the_newest_four_shown_by_id(): void
    {
        $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], $this->shown());

        MarketingSettings::save($this->shop->id, 'website', ['store_new_arrivals_mode' => 'auto', 'store_new_arrivals' => $this->p[1].','.$this->p[2]]);
        $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], $this->shown(), 'قائمةٌ محفوظةٌ لا تُقرأ في التلقائيّ');
    }

    public function test_editing_an_old_product_does_not_make_it_new(): void
    {
        Carbon::setTestNow('2027-03-01 09:00:00');
        Product::find($this->p[1])->update(['name' => 'صنف ١ بعد التعديل', 'price' => 12]);

        $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], $this->shown());
    }

    /* ═══════════ اليدويّ ═══════════ */

    public function test_manual_shows_the_saved_order_exactly(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[2].','.$this->p[5].','.$this->p[1]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['manual', $this->p[2].','.$this->p[5].','.$this->p[1]], $this->stored($this->shop));
        $this->assertSame([$this->p[2], $this->p[5], $this->p[1]], $this->shown());
    }

    public function test_duplicates_fold_to_their_first_place(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => " {$this->p[3]}, {$this->p[1]},{$this->p[3]} ,"])
            ->assertSessionHasNoErrors();

        $this->assertSame(['manual', $this->p[3].','.$this->p[1]], $this->stored($this->shop));
    }

    public function test_more_than_four_is_refused(): void
    {
        $five = implode(',', array_slice($this->p, 0, 5));

        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $five])
            ->assertSessionHasErrors('store_new_arrivals');

        $this->assertSame(['auto', ''], $this->stored($this->shop));
    }

    public function test_what_is_not_a_positive_number_is_refused(): void
    {
        foreach (['1,abc', '0', '-3', '2.5', $this->p[1].';'.$this->p[2], '99999999999999999999999'] as $raw) {
            $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $raw])
                ->assertSessionHasErrors('store_new_arrivals');
        }

        $this->assertSame(['auto', ''], $this->stored($this->shop));
    }

    public function test_another_shops_product_is_refused_and_never_shown(): void
    {
        $foreign = $this->product($this->other, 'صنف الغريب')->id;

        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[1].','.$foreign])
            ->assertSessionHasErrors('store_new_arrivals');
        $this->assertSame(['auto', ''], $this->stored($this->shop));

        // ولو كُتب في الجدول بلا بابِ الحفظ — لا يُعرض
        MarketingSettings::save($this->shop->id, 'website', ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $foreign.','.$this->p[1]]);
        $this->assertSame([$this->p[1]], $this->shown());
        $this->assertNotContains($foreign, array_column($this->catalogTools()['new_arrivals'], 'id'));
    }

    public function test_an_off_or_hidden_product_is_refused_at_save(): void
    {
        Product::whereKey($this->p[1])->update(['active' => false]);
        Product::whereKey($this->p[2])->update(['published' => false]);

        foreach ([$this->p[1], $this->p[2]] as $id) {
            $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[3].','.$id])
                ->assertSessionHasErrors('store_new_arrivals');
        }

        $this->assertSame(['auto', ''], $this->stored($this->shop));
    }

    public function test_manual_needs_at_least_one_product(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => ''])
            ->assertSessionHasErrors('store_new_arrivals');
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual'])
            ->assertSessionHasErrors('store_new_arrivals');
        $this->assertSame(['auto', ''], $this->stored($this->shop));

        // والتلقائيّ لا يطلب شيئًا
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'auto', 'store_new_arrivals' => ''])
            ->assertSessionHasNoErrors();
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        foreach (['random', '', 'MANUAL'] as $mode) {
            $this->saveAs($this->owner, ['store_new_arrivals_mode' => $mode, 'store_new_arrivals' => (string) $this->p[1]])
                ->assertSessionHasErrors('store_new_arrivals_mode');
        }

        $this->assertSame(['auto', ''], $this->stored($this->shop));
    }

    public function test_saving_the_mode_alone_keeps_the_list(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[2].','.$this->p[1]]);
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'auto'])->assertSessionHasNoErrors();
        $this->assertSame(['auto', $this->p[2].','.$this->p[1]], $this->stored($this->shop));

        // ويعود إليه اليدويُّ بلا أن يُعيد الاختيار
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual'])->assertSessionHasNoErrors();
        $this->assertSame([$this->p[2], $this->p[1]], $this->shown());
    }

    /* ═══════════ ما يسقط بعد الاختيار ═══════════ */

    public function test_a_product_hidden_after_choosing_drops_out_and_nothing_fills_its_place(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[1].','.$this->p[4].','.$this->p[2]]);

        Product::whereKey($this->p[4])->update(['published' => false]);
        $this->assertSame([$this->p[1], $this->p[2]], $this->shown());

        Product::whereKey($this->p[1])->update(['active' => false]);
        $this->assertSame([$this->p[2]], $this->shown());
    }

    public function test_when_every_choice_is_gone_the_section_is_automatic_again(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[1].','.$this->p[2]]);
        Product::whereKey([$this->p[1], $this->p[2]])->update(['active' => false]);

        $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], $this->shown());
    }

    public function test_choosing_does_not_touch_the_products(): void
    {
        $before = DB::table('products')->orderBy('id')->get(['id', 'active', 'published', 'created_at', 'updated_at'])->toArray();

        Carbon::setTestNow('2027-03-01 09:00:00');
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[1].','.$this->p[2]]);
        $this->shown();

        $this->assertEquals($before, DB::table('products')->orderBy('id')->get(['id', 'active', 'published', 'created_at', 'updated_at'])->toArray());
    }

    /* ═══════════ المحرّر والمعاينة ═══════════ */

    public function test_the_editor_receives_the_mode_the_ids_and_what_the_page_shows(): void
    {
        $this->saveAs($this->owner, ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[5].','.$this->p[2]]);

        $tools = $this->catalogTools();
        $this->assertSame('manual', $tools['new_arrivals_mode']);
        $this->assertSame([$this->p[5], $this->p[2]], $tools['new_arrival_ids']);
        $this->assertSame($this->shown(), array_column($tools['new_arrivals'], 'id'), 'المحرّرُ غيرُ ما تعرضه الواجهة');
    }

    public function test_the_editor_panel_of_a_shop_outside_the_curation_list_is_as_it_was(): void
    {
        config(['storefront.ribbon_curated_new_arrivals_businesses' => []]);
        MarketingSettings::save($this->shop->id, 'website', ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => (string) $this->p[1]]);

        $tools = $this->catalogTools();
        $this->assertSame(['categories', 'new_arrivals'], array_keys($tools));
        $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], array_column($tools['new_arrivals'], 'id'));
    }

    public function test_the_preview_shows_the_unsaved_choice_and_the_site_does_not(): void
    {
        $draft = ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[3].','.$this->p[6]];

        $preview = $this->actingAs($this->owner)->post(route('admin.store.preview'), ['draft' => json_encode($draft)])->assertOk();
        $this->assertSame([$this->p[3], $this->p[6]], array_column($preview->viewData('new'), 'id'));

        // والتراكبُ يموت مع طلبه
        $this->assertSame([$this->p[6], $this->p[5], $this->p[4], $this->p[3]], $this->shown());
    }

    public function test_the_pick_reads_nothing_beyond_what_it_is_given(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_new_arrivals_mode' => 'manual', 'store_new_arrivals' => $this->p[2].','.$this->p[1]]);

        // `$shown` بلا الصنف ٢ — فلا يُجلب من القاعدة ولو كان منشورًا فيها
        $given = Product::whereKey([$this->p[1], $this->p[3]])->get();
        $this->assertSame([$this->p[1]], NewArrivals::pick($this->shop->id, $given)->pluck('id')->all());
        $this->assertSame(StorePage::NEW_ARRIVALS, 4);
    }
}
