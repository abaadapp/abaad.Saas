<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\DismissedNotification;
use App\Models\Product;
use App\Models\Season;
use App\Models\SeasonReminder;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Seasons;
use App\Support\Storefront;
use App\Support\Website\Builder;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الموسمُ يجمع أصنافًا قائمةً ويُذكّر قبل أن يحلّ — ولا يمسّ الصنفَ بحال.
 */
class ASeasonGathersProductsAndRemindsBeforeItComesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Business $other;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '96890000000', 'city' => 'مسقط', 'site_slug' => 'wrood']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->otherOwner = User::create(['business_id' => $this->other->id, 'name' => 'جار', 'email' => 'n@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function season(array $attrs = []): Season
    {
        return Season::create($attrs + [
            'business_id' => $this->business->id, 'name' => 'رمضان 2027',
            'starts_at' => '2027-02-08', 'ends_at' => '2027-03-09',
            'active' => true, 'show_in_pos' => true, 'show_on_website' => true,
        ]);
    }

    /* ═══════════ الإنشاء والحالة ═══════════ */

    public function test_a_business_creates_a_season(): void
    {
        $this->actingAs($this->owner)->post(route('admin.seasons.store'), [
            'name' => 'العيد', 'starts_at' => '2027-03-20', 'ends_at' => '2027-03-25',
        ])->assertRedirect();

        $s = Season::where('name', 'العيد')->firstOrFail();
        $this->assertSame($this->business->id, $s->business_id);
        $this->assertTrue($s->active && $s->show_in_pos && $s->show_on_website, 'الافتراضاتُ الثلاثة مضاءة');
    }

    public function test_the_end_cannot_precede_the_start(): void
    {
        $this->actingAs($this->owner)->post(route('admin.seasons.store'), [
            'name' => 'مقلوب', 'starts_at' => '2027-03-25', 'ends_at' => '2027-03-20',
        ])->assertSessionHasErrors('ends_at');

        $this->assertSame(0, Season::count());
    }

    public function test_status_is_upcoming_active_ended_or_inactive(): void
    {
        $s = $this->season();

        $this->assertSame(Season::UPCOMING, $s->status(Carbon::parse('2027-02-07')));
        $this->assertSame(Season::ACTIVE, $s->status(Carbon::parse('2027-02-08')));
        $this->assertSame(Season::ACTIVE, $s->status(Carbon::parse('2027-03-09 23:30')), 'اليومُ الأخير موسمٌ حتى منتصف ليله');
        $this->assertSame(Season::ENDED, $s->status(Carbon::parse('2027-03-10')));

        $s->update(['active' => false]);
        $this->assertSame(Season::INACTIVE, $s->status(Carbon::parse('2027-02-20')), 'المُطفأ غيرُ فعّالٍ ولو في مدّته');
    }

    public function test_the_list_filters_by_status(): void
    {
        $this->season(['name' => 'قادم']);
        $this->season(['name' => 'جارٍ', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20']);
        $this->season(['name' => 'منتهٍ', 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-31']);

        $names = fn (string $f) => collect($this->actingAs($this->owner)->get(route('admin.seasons.index', ['status' => $f]))
            ->viewData('page')['props']['seasons'])->pluck('name')->all();

        $this->assertSame(['قادم'], $names('upcoming'));
        $this->assertSame(['جارٍ'], $names('active'));
        $this->assertSame(['منتهٍ'], $names('ended'));
        $this->assertCount(3, $names('all'));
    }

    /* ═══════════ الأصناف ═══════════ */

    public function test_a_product_attaches_and_may_belong_to_many_seasons(): void
    {
        $p = $this->product();
        $ramadan = $this->season();
        $eid = $this->season(['name' => 'العيد', 'starts_at' => '2027-03-20', 'ends_at' => '2027-03-25']);

        $this->actingAs($this->owner)->post(route('admin.seasons.attach', $ramadan->id), ['product_ids' => [$p->id]])->assertRedirect();
        $this->actingAs($this->owner)->post(route('admin.seasons.attach', $eid->id), ['product_ids' => [$p->id]])->assertRedirect();

        $this->assertSame(1, $ramadan->products()->count());
        $this->assertSame(1, $eid->products()->count());
        $this->assertNull(Product::find($p->id)->getAttribute('season_id'), 'لا عمودَ موسمٍ على الصنف');
    }

    public function test_the_same_product_is_not_duplicated_inside_a_season(): void
    {
        $p = $this->product();
        $s = $this->season();

        $this->actingAs($this->owner)->post(route('admin.seasons.attach', $s->id), ['product_ids' => [$p->id, $p->id]]);
        $this->actingAs($this->owner)->post(route('admin.seasons.attach', $s->id), ['product_ids' => [$p->id]]);

        $this->assertSame(1, \DB::table('season_product')->where('season_id', $s->id)->count());
    }

    public function test_a_product_of_another_shop_never_attaches(): void
    {
        $foreign = Product::create(['business_id' => $this->other->id, 'name' => 'صنف الجار', 'price' => 1, 'active' => true]);
        $s = $this->season();

        $this->actingAs($this->owner)->post(route('admin.seasons.attach', $s->id), ['product_ids' => [$foreign->id]]);

        $this->assertSame(0, $s->products()->count());
        $this->assertNotContains($foreign->id, collect($this->actingAs($this->owner)
            ->getJson(route('admin.seasons.products', $s->id))->json('products'))->pluck('id')->all(), 'ولا يُقترح في المُنتقي');
    }

    public function test_another_shop_cannot_see_edit_or_delete_the_season(): void
    {
        $s = $this->season();

        $this->actingAs($this->otherOwner)->get(route('admin.seasons.show', $s->id))->assertNotFound();
        $this->actingAs($this->otherOwner)->put(route('admin.seasons.update', $s->id), ['name' => 'x', 'starts_at' => '2027-02-08', 'ends_at' => '2027-03-09'])->assertNotFound();
        $this->actingAs($this->otherOwner)->delete(route('admin.seasons.destroy', $s->id))->assertNotFound();
        $this->assertNotNull($s->fresh());
        $this->assertEmpty(collect($this->actingAs($this->otherOwner)->get(route('admin.seasons.index'))->viewData('page')['props']['seasons']));
    }

    public function test_removing_a_product_from_a_season_does_not_delete_it(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);

        $this->actingAs($this->owner)->delete(route('admin.seasons.detach', [$s->id, $p->id]))->assertRedirect();

        $this->assertSame(0, $s->products()->count());
        $this->assertNotNull(Product::find($p->id));
        $this->assertSame(5, (int) $p->fresh()->quantity);
    }

    public function test_deleting_a_season_does_not_delete_or_change_products(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 3, 'message' => 'x', 'active' => true]);
        $before = $p->fresh()->toArray();

        $this->actingAs($this->owner)->delete(route('admin.seasons.destroy', $s->id))->assertRedirect(route('admin.seasons.index'));

        $this->assertNull(Season::find($s->id));
        $this->assertSame(0, \DB::table('season_product')->count());
        $this->assertSame(0, SeasonReminder::count());
        $this->assertSame($before, $p->fresh()->toArray(), 'الصنفُ لم يُمسّ');
    }

    public function test_a_soft_deleted_product_drops_out_of_the_season(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $p->delete();

        $this->assertSame(0, $s->products()->count());
        $this->assertSame([], Seasons::forPos($this->business->id, Carbon::parse('2027-02-20')));
        $this->assertNotNull(Product::withTrashed()->find($p->id), 'ولا يُستعاد عبر الموسم');
    }

    public function test_a_product_without_a_season_sells_as_before(): void
    {
        $p = $this->product();
        $this->season(); // موسمٌ قائم لا يضمّه

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 10]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $this->assertSame(4, (int) $p->fresh()->quantity);
    }

    /* ═══════════ التذكيرات ═══════════ */

    public function test_a_season_holds_many_reminders_of_both_kinds(): void
    {
        $s = $this->season();

        $this->actingAs($this->owner)->post(route('admin.seasons.reminders.store', $s->id), ['type' => 'relative', 'days_before' => 30, 'message' => 'بدء التخطيط'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('admin.seasons.reminders.store', $s->id), ['type' => 'relative', 'days_before' => 14, 'message' => 'مراجعة المخزون'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('admin.seasons.reminders.store', $s->id), ['type' => 'fixed', 'remind_at' => '2027-02-05 09:00', 'message' => 'جاهزية الموقع'])->assertRedirect();

        $this->assertSame(3, $s->reminders()->count());
    }

    public function test_a_relative_reminder_follows_the_season_start(): void
    {
        $s = $this->season();
        $r = $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 14, 'message' => 'x', 'active' => true]);

        $this->assertSame('2027-01-25 09:00', $r->dueAt($s)->format('Y-m-d H:i'));

        $s->update(['starts_at' => '2027-03-01']);
        $this->assertSame('2027-02-15 09:00', $r->fresh()->dueAt($s->fresh())->format('Y-m-d H:i'), 'تبع الموسمَ بلا أن يُمسّ');
    }

    public function test_a_fixed_reminder_does_not_move_with_the_season(): void
    {
        $s = $this->season();
        $r = $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'fixed', 'remind_at' => '2027-02-05 09:00:00', 'message' => 'x', 'active' => true]);

        $s->update(['starts_at' => '2027-03-01']);

        $this->assertSame('2027-02-05 09:00', $r->fresh()->dueAt($s->fresh())->format('Y-m-d H:i'));
    }

    /**
     * ولا تذكيرَ موسمٍ في الجرس العامّ — وبقيّةُ سكّانه على حالهم.
     *
     * تنبيهُ الموسم في قسمه وحدَه. وكان الجرسُ يعرضه، فكان للتنبيه الواحد
     * بابان وإخفاءان: يُخفى من الجرس فيبقى في القسم، ويُقرأ في القسم فيبقى
     * في الجرس.
     *
     * والحارسُ يشهد للأمرين معًا: غيابُ الموسم، وبقاءُ «مخزونٌ منخفض» —
     * فحذفُ سطرِ المواسم من الجرس لا يجوز أن يكون حذفًا لسكّانه.
     */
    public function test_no_season_reminder_reaches_the_general_bell(): void
    {
        $low = $this->product(['name' => 'وردٌ أحمر', 'quantity' => 0, 'alert_qty' => 3]);
        $season = $this->season();
        $r = $this->reminder($season, ['days_before' => 7]);

        $this->actingAs($this->owner);
        $keys = collect(Demo::allNotifications())->pluck('key')->all();

        $this->assertTrue($r->fresh()->isDue($season), 'التذكيرُ لم يحن، فالحارسُ لا يشهد بشيء');
        $this->assertNotContains('season-reminder-'.$r->id, $keys, 'تنبيهُ الموسم في الجرس');
        $this->assertEmpty(array_filter($keys, fn ($k) => str_starts_with($k, 'season-reminder-')));
        $this->assertContains('low-'.$low->id, $keys, 'ذهب مع تذكير الموسم ساكنٌ آخر من الجرس');
    }

    /**
     * ولا يُخفى من الجرس ما ليس فيه.
     *
     * بابُ الإخفاء العامّ يبقى لسكّانه، ومفتاحُ موسمٍ يُرسل إليه لا يكتب صفًّا
     * ولا يمسّ التنبيهَ في قسمه: القراءةُ هناك `acknowledged_for` لا
     * `DismissedNotification`.
     */
    public function test_dismissing_a_season_key_in_the_bell_changes_nothing(): void
    {
        $season = $this->season();
        $r = $this->reminder($season, ['days_before' => 7]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.notifications.dismiss'), ['key' => 'season-reminder-'.$r->id])
            ->assertOk();

        $this->assertTrue($r->fresh()->isDue($season->fresh()), 'الإخفاءُ العامُّ أسكت تنبيهَ القسم');
        $this->assertSame(0, DismissedNotification::where('key', 'like', 'season-reminder-%')->count());
    }

    public function test_a_reminder_of_another_shop_is_neither_seen_nor_touched(): void
    {
        $s = $this->season();
        $r = $this->reminder($s, ['days_before' => 7]);

        $this->actingAs($this->otherOwner);
        $this->delete(route('admin.seasons.reminders.destroy', [$s->id, $r->id]))->assertNotFound();
        $this->assertNotNull($r->fresh());
    }

    /* ═══════════ الصندوق ═══════════ */

    private function posSeasons(): array
    {
        return Seasons::forPos($this->business->id);
    }

    public function test_a_live_pos_season_reaches_the_till_with_its_product_ids(): void
    {
        $p = $this->product();
        $s = $this->season(['starts_at' => '2027-01-20', 'ends_at' => '2027-02-20']);
        $s->products()->attach($p->id);

        $seasons = $this->posSeasons();
        $this->assertCount(1, $seasons);
        $this->assertSame([$p->id], $seasons[0]['product_ids']);
    }

    public function test_upcoming_ended_hidden_or_empty_seasons_do_not_reach_the_till(): void
    {
        $p = $this->product();
        foreach ([
            ['name' => 'قادم'],
            ['name' => 'منتهٍ', 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-31'],
            ['name' => 'مخفيّ', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'show_in_pos' => false],
            ['name' => 'مطفأ', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'active' => false],
        ] as $attrs) {
            $this->season($attrs)->products()->attach($p->id);
        }
        $this->season(['name' => 'فارغ', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20']);

        $this->assertSame([], $this->posSeasons());
    }

    public function test_a_season_cannot_make_a_stopped_or_sold_out_product_sellable(): void
    {
        $stopped = $this->product(['name' => 'موقوف', 'active' => false]);
        $empty = $this->product(['name' => 'نافد', 'quantity' => 0]);
        $s = $this->season(['starts_at' => '2027-01-20', 'ends_at' => '2027-02-20']);
        $s->products()->attach([$stopped->id, $empty->id]);

        foreach ([$stopped, $empty] as $p) {
            $this->actingAs($this->owner)->postJson('/pos/checkout', [
                'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => 10]],
                'payment_method' => 'نقدي',
            ])->assertStatus(422);
        }
    }

    public function test_an_untracked_product_in_a_season_still_sells_by_its_own_rule(): void
    {
        $service = $this->product(['name' => 'خدمة', 'tracks_stock' => false, 'quantity' => 0]);
        $s = $this->season(['starts_at' => '2027-01-20', 'ends_at' => '2027-02-20']);
        $s->products()->attach($service->id);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $service->id, 'name' => $service->name, 'qty' => 1, 'price' => 10]],
            'payment_method' => 'نقدي',
        ])->assertOk();
    }

    /* ═══════════ الموقع ═══════════ */

    private function publishedHome(): array
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        return Published::forBusiness((int) $this->business->id)['site']['pages'][0]['sections'];
    }

    private function seasonSections(array $sections): array
    {
        return array_values(array_filter($sections, fn ($s) => str_starts_with((string) ($s['id'] ?? ''), 'season-')));
    }

    public function test_a_live_website_season_appears_with_its_published_products_only(): void
    {
        $shown = $this->product(['name' => 'معروض']);
        $unpublished = $this->product(['name' => 'مخفيّ', 'published' => false]);
        $stopped = $this->product(['name' => 'موقوف', 'active' => false]);
        $soldOut = $this->product(['name' => 'نافد', 'quantity' => 0]);
        $s = $this->season(['starts_at' => '2027-01-20', 'ends_at' => '2027-02-20']);
        $s->products()->attach([$shown->id, $unpublished->id, $stopped->id, $soldOut->id]);

        $sections = $this->seasonSections($this->publishedHome());

        $this->assertCount(1, $sections);
        $this->assertSame('featured_products', $sections[0]['type'], 'بنوعٍ يعرفه الراسم');
        $this->assertSame('رمضان 2027', $sections[0]['data']['title']);
        $this->assertSame([$shown->id], array_column($sections[0]['items'], 'id'));
        $this->assertArrayNotHasKey('cost', $sections[0]['items'][0]);
        $this->assertArrayNotHasKey('quantity', $sections[0]['items'][0]);
    }

    public function test_hidden_upcoming_ended_or_inactive_seasons_do_not_reach_the_website(): void
    {
        $p = $this->product();
        foreach ([
            ['name' => 'قادم'],
            ['name' => 'منتهٍ', 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-31'],
            ['name' => 'مخفيّ', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'show_on_website' => false],
            ['name' => 'مطفأ', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'active' => false],
        ] as $attrs) {
            $this->season($attrs)->products()->attach($p->id);
        }

        $this->assertSame([], $this->seasonSections($this->publishedHome()));
    }

    public function test_a_season_ending_does_not_unpublish_its_products(): void
    {
        $p = $this->product();
        $s = $this->season(['starts_at' => '2027-01-20', 'ends_at' => '2027-01-31']);
        $s->products()->attach($p->id);

        $sections = $this->publishedHome();
        $this->assertSame([], $this->seasonSections($sections));

        $ids = collect($sections)->flatMap(fn ($sec) => array_column($sec['items'] ?? [], 'id'))->unique()->all();
        $this->assertContains($p->id, $ids, 'الصنفُ في مكانه المعتاد');
        $this->assertTrue((bool) $p->fresh()->published);
    }

    public function test_another_shops_season_never_reaches_this_storefront(): void
    {
        $foreignProduct = Product::create(['business_id' => $this->other->id, 'name' => 'صنف الجار', 'price' => 1, 'active' => true, 'published' => true, 'quantity' => 3]);
        Season::create(['business_id' => $this->other->id, 'name' => 'موسم الجار', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'active' => true, 'show_in_pos' => true, 'show_on_website' => true])
            ->products()->attach($foreignProduct->id);

        $sections = $this->publishedHome();

        $this->assertSame([], $this->seasonSections($sections));
        $this->assertNotContains($foreignProduct->id, collect($sections)->flatMap(fn ($sec) => array_column($sec['items'] ?? [], 'id'))->all());
    }

    public function test_the_simple_store_page_carries_live_seasons_as_chips(): void
    {
        $p = $this->product();
        $this->season(['starts_at' => '2027-01-20', 'ends_at' => '2027-02-20'])->products()->attach($p->id);
        $this->season(['name' => 'قادم'])->products()->attach($p->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'store_published', 'value' => '1']);

        $page = Storefront::page($this->business->fresh());

        $this->assertCount(1, $page['seasons']);
        $this->assertSame('رمضان 2027', $page['seasons'][0]['name']);
        $this->assertSame([$p->id], $page['seasons'][0]['product_ids']);
    }

    /* ═══════════ تنبيهُ الموسم في قسمه ═══════════ */

    private function reminder(Season $season, array $attrs = []): SeasonReminder
    {
        return $season->reminders()->create($attrs + [
            'business_id' => $season->business_id,
            'type' => SeasonReminder::RELATIVE,
            'days_before' => 30,
            'message' => 'راجع المخزون',
            'active' => true,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function alerts(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->get(route('admin.seasons.index'))
            ->assertOk()->viewData('page')['props']['alerts'];
    }

    /**
     * التنبيهُ ينتظره حين يفتح القسم — لا يُشترط أن تكون الصفحةُ مفتوحةً وقتَه.
     *
     * وهو لبُّ الميزة: موعدُ التذكير يحين والتاجرُ نائم، فإذا فتح قسمَه بعد
     * يومين وجده واقفًا. والحسابُ عند كلّ فتحةٍ من بداية الموسم، لا صفٌّ
     * يُكتب في لحظة الموعد.
     */
    public function test_a_reminder_that_came_due_while_away_is_waiting(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $this->reminder($season, ['days_before' => 30]);   // يحين 2027-02-08 09:00

        // وقد مضى على حينه يومان
        Carbon::setTestNow('2027-02-10 20:00:00');

        $alerts = $this->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('راجع المخزون', $alerts[0]['message']);
        $this->assertSame(28, $alerts[0]['days'], 'الأيّامُ إلى بداية الموسم لا إلى موعد التذكير');
    }

    /** ولا يظهر قبل موعده بدقيقة */
    public function test_a_reminder_does_not_show_before_its_time(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $this->reminder($season, ['days_before' => 30]);

        Carbon::setTestNow('2027-02-08 08:59:00');

        $this->assertSame([], $this->alerts());
    }

    /** ومن قرأه لا يُعاد عليه في الدورة نفسِها */
    public function test_an_acknowledged_reminder_does_not_come_back_in_the_same_cycle(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $r = $this->reminder($season, ['days_before' => 30]);

        Carbon::setTestNow('2027-02-10 20:00:00');
        $this->assertCount(1, $this->alerts());

        $this->actingAs($this->owner)
            ->post(route('admin.seasons.reminders.read', [$season->id, $r->id]))
            ->assertRedirect();

        $this->assertSame([], $this->alerts(), 'عاد التنبيهُ بعد قراءته');

        // وبعد يومٍ آخرَ ما زال مقروءًا
        Carbon::setTestNow('2027-02-11 09:00:00');
        $this->assertSame([], $this->alerts());
    }

    /**
     * ويعود في الدورة التالية — وهي حالُ الموسم الهجريّ كلَّ عام.
     *
     * رمضانُ يتقدّم أحدَ عشرَ يومًا في كلّ سنة، فصاحبُه يُعيد تأريخَ موسمه.
     * والتنبيهُ الذي قرأه العامَ الماضي يجب أن يعود — دورةٌ أخرى وموعدٌ آخر.
     */
    public function test_a_reshuffled_hijri_season_arms_the_reminder_again(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $r = $this->reminder($season, ['days_before' => 30]);

        Carbon::setTestNow('2027-02-10 20:00:00');
        $this->actingAs($this->owner)->post(route('admin.seasons.reminders.read', [$season->id, $r->id]));
        $this->assertSame([], $this->alerts());

        // وفي العام التالي: رمضانُ يتقدّم، فيُعاد تأريخُ الموسم
        $season->update(['starts_at' => '2028-02-27', 'ends_at' => '2028-03-08']);
        Carbon::setTestNow('2028-01-29 09:00:00');

        $alerts = $this->alerts();

        $this->assertCount(1, $alerts, 'لم يعد التنبيهُ في الدورة الجديدة');
        $this->assertSame(29, $alerts[0]['days']);
    }

    /** والتنبيهُ يتبع بدايةَ الموسم: من أخّر موسمَه تأخّر تنبيهُه بلا أن يمسّه */
    public function test_moving_the_season_moves_the_reminder(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $this->reminder($season, ['days_before' => 30]);

        Carbon::setTestNow('2027-02-10 20:00:00');
        $this->assertCount(1, $this->alerts());

        // أُخِّر الموسمُ شهرًا — فلم يَحِن تنبيهُه بعد
        $season->update(['starts_at' => '2027-04-10', 'ends_at' => '2027-04-20']);

        $this->assertSame([], $this->alerts());
    }

    /** ولا يُنبَّه بموسمٍ مطفأٍ ولا بتذكيرٍ مطفأ */
    public function test_a_switched_off_season_or_reminder_says_nothing(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $r = $this->reminder($season, ['days_before' => 30]);
        Carbon::setTestNow('2027-02-10 20:00:00');

        $r->update(['active' => false]);
        $this->assertSame([], $this->alerts(), 'تذكيرٌ مطفأ');

        $r->update(['active' => true]);
        $season->update(['active' => false]);
        $this->assertSame([], $this->alerts(), 'موسمٌ مطفأ');
    }

    /** وموسمٌ انتهى لا يُستعدّ له */
    public function test_an_ended_season_says_nothing(): void
    {
        $season = $this->season(['starts_at' => '2027-01-01', 'ends_at' => '2027-01-10']);
        $this->reminder($season, ['days_before' => 30]);

        Carbon::setTestNow('2027-02-10 20:00:00');

        $this->assertSame([], $this->alerts());
    }

    /** وتنبيهُ الجار لا يصل شاشتَه */
    public function test_a_neighbours_alert_never_reaches_this_shop(): void
    {
        $mine = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $this->reminder($mine, ['days_before' => 30, 'message' => 'تذكيري']);

        $theirs = Season::create([
            'business_id' => $this->other->id, 'name' => 'موسمُ الجار',
            'starts_at' => '2027-03-10', 'ends_at' => '2027-03-20', 'active' => true,
        ]);
        $theirs->reminders()->create([
            'business_id' => $this->other->id, 'type' => SeasonReminder::RELATIVE,
            'days_before' => 30, 'message' => 'تذكيرُ الجار', 'active' => true,
        ]);

        Carbon::setTestNow('2027-02-10 20:00:00');

        $mineAlerts = $this->alerts();
        $this->assertCount(1, $mineAlerts);
        $this->assertSame('تذكيري', $mineAlerts[0]['message']);

        $theirAlerts = $this->alerts($this->otherOwner);
        $this->assertCount(1, $theirAlerts);
        $this->assertSame('تذكيرُ الجار', $theirAlerts[0]['message']);
    }

    /** ولا يُقرأ تذكيرُ الجار من بابِ هذا المتجر */
    public function test_a_neighbours_reminder_cannot_be_acknowledged_from_here(): void
    {
        $theirs = Season::create([
            'business_id' => $this->other->id, 'name' => 'موسمُ الجار',
            'starts_at' => '2027-03-10', 'ends_at' => '2027-03-20', 'active' => true,
        ]);
        $r = $theirs->reminders()->create([
            'business_id' => $this->other->id, 'type' => SeasonReminder::RELATIVE,
            'days_before' => 30, 'message' => 'تذكيرُ الجار', 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.seasons.reminders.read', [$theirs->id, $r->id]))
            ->assertNotFound();

        $this->assertNull($r->fresh()->acknowledged_for);
    }

    /**
     * وصفُّ التذكير في صفحة الموسم يقول «حان» بصدق.
     *
     * و`due` تُرشِّح الموسمَ المطفأ في الاستعلام، فحذفُ الشرط من
     * `isDue` لا يظهر هناك. لكنّ صفحةَ الموسم تسأل `isDue` مباشرةً لكلّ
     * تذكير — وهنا يظهر.
     */
    public function test_the_row_does_not_call_a_switched_off_seasons_reminder_due(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $this->reminder($season, ['days_before' => 30]);
        Carbon::setTestNow('2027-02-10 20:00:00');

        $row = fn () => $this->actingAs($this->owner)->get(route('admin.seasons.show', $season->id))
            ->assertOk()->viewData('page')['props']['season']['reminders'][0];

        $this->assertTrue($row()['due'], 'حان ولم يُقل');

        $season->update(['active' => false]);

        $this->assertFalse($row()['due'], 'موسمٌ مطفأٌ وتذكيرُه يقول «حان»');
    }

    /**
     * والاختصاراتُ أربعةٌ مختلفة — أسبوعٌ وأسبوعان وشهرٌ وشهران.
     *
     * وأربعةُ أزرارٍ تحمل العددَ نفسَه ليست اختصارات.
     */
    public function test_the_four_presets_are_four_distinct_spans(): void
    {
        $this->assertSame([7, 14, 30, 60], SeasonReminder::PRESETS);
        $this->assertCount(4, array_unique(SeasonReminder::PRESETS));

        /* ومحلُّها شاشةُ الموسم حيث يُختار الموعد — لا قائمةُ المواسم */
        $season = $this->season();
        $props = $this->actingAs($this->owner)->get(route('admin.seasons.show', $season->id))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame([7, 14, 30, 60], $props['presets'], 'الاختصاراتُ لا تصل الشاشة');
        $this->assertArrayNotHasKey(
            'presets',
            $this->get(route('admin.seasons.index'))->assertOk()->viewData('page')['props'],
            'حمولةٌ لا تُقرأ في قائمة المواسم',
        );
    }

    /* ═══════════ تعديلُ الموعد ═══════════ */

    /** ويُعدَّل الموعدُ في موضعه — لا حذفٌ وإعادةُ إنشاء */
    public function test_the_timing_can_be_changed_in_place(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $r = $this->reminder($season, ['days_before' => 7]);

        $this->actingAs($this->owner)->patch(
            route('admin.seasons.reminders.update', [$season->id, $r->id]),
            ['type' => SeasonReminder::RELATIVE, 'days_before' => 60],
        )->assertRedirect();

        $this->assertSame(60, $r->fresh()->days_before);
    }

    /**
     * وتغييرُ الموعد يمحو القراءةَ السابقة.
     *
     * من نقل تذكيرَه من أسبوعٍ إلى شهرَين يريد أن يُنبَّه بالموعد الجديد —
     * ولو بقي «مقروءًا» لَما رآه، وهو لم يقرأ هذا الموعدَ قطُّ.
     */
    public function test_changing_the_timing_re_arms_a_read_reminder(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $r = $this->reminder($season, ['days_before' => 30]);

        Carbon::setTestNow('2027-02-10 20:00:00');
        $this->actingAs($this->owner)->post(route('admin.seasons.reminders.read', [$season->id, $r->id]));
        $this->assertSame([], $this->alerts());

        $this->actingAs($this->owner)->patch(
            route('admin.seasons.reminders.update', [$season->id, $r->id]),
            ['type' => SeasonReminder::RELATIVE, 'days_before' => 60],
        );

        $this->assertCount(1, $this->alerts(), 'لم يعد التنبيهُ بعد نقل موعده');
    }

    /** ومفتاحُ التشغيل وحدَه لا يمحو الموعد */
    public function test_toggling_active_keeps_the_timing(): void
    {
        $season = $this->season();
        $r = $this->reminder($season, ['days_before' => 45]);

        $this->actingAs($this->owner)->patch(
            route('admin.seasons.reminders.update', [$season->id, $r->id]),
            ['active' => false],
        )->assertRedirect();

        $fresh = $r->fresh();
        $this->assertFalse($fresh->active);
        $this->assertSame(45, $fresh->days_before, 'مُحي الموعدُ بإطفاء التذكير');
    }

    /* ═══════════ ولا يخرج من قسمه ═══════════ */

    /**
     * التنبيهُ لا يُرسَل إلى بابٍ آخر.
     *
     * `alerts` خاصّيّةُ شاشة المواسم وحدَها — لا لوحةُ التحكّم ولا شاشةٌ
     * أخرى تقرؤها، ولا بريدَ ولا واتساب.
     */
    public function test_the_alert_prop_exists_only_on_the_seasons_screen(): void
    {
        $season = $this->season(['starts_at' => '2027-03-10', 'ends_at' => '2027-03-20']);
        $this->reminder($season, ['days_before' => 30]);
        Carbon::setTestNow('2027-02-10 20:00:00');

        foreach (['admin.dashboard', 'admin.products.index'] as $name) {
            $props = $this->actingAs($this->owner)->get(route($name))
                ->assertOk()->viewData('page')['props'];

            $this->assertArrayNotHasKey('alerts', $props, $name.' تحمل تنبيهات المواسم');
        }
    }
}
