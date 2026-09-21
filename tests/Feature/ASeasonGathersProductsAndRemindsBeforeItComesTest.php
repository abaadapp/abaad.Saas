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

    public function test_a_due_reminder_rings_the_bell_and_links_to_its_season(): void
    {
        $s = $this->season();
        $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 7, 'message' => 'مراجعة المنتجات والمخزون', 'active' => true]);

        $this->actingAs($this->owner);
        $row = collect(Demo::allNotifications())->firstWhere('key', 'season-reminder-'.$s->reminders()->first()->id);

        $this->assertNotNull($row, 'لم يرنّ الجرس');
        $this->assertStringContainsString('مراجعة المنتجات والمخزون', $row['text']);
        $this->assertStringContainsString('7', $row['time']);
        $this->assertSame(route('admin.seasons.show', $s->id), $row['url']);
    }

    public function test_a_future_reminder_does_not_ring_early(): void
    {
        $s = $this->season();
        $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 3, 'message' => 'x', 'active' => true]);

        $this->actingAs($this->owner);
        $this->assertNull(collect(Demo::allNotifications())->first(fn ($n) => str_starts_with($n['key'], 'season-reminder-')));
    }

    public function test_a_dismissed_reminder_stays_dismissed(): void
    {
        $s = $this->season();
        $r = $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 7, 'message' => 'x', 'active' => true]);

        $this->actingAs($this->owner)->postJson(route('admin.notifications.dismiss'), ['key' => 'season-reminder-'.$r->id])->assertOk();

        $this->assertNull(collect(Demo::allNotifications())->firstWhere('key', 'season-reminder-'.$r->id));
        $this->assertSame(1, DismissedNotification::count(), 'صفٌّ واحدٌ لا صفٌّ في كلّ استطلاع');
    }

    public function test_an_inactive_reminder_or_season_does_not_ring(): void
    {
        $s = $this->season();
        $off = $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 7, 'message' => 'x', 'active' => false]);
        $dead = $this->season(['name' => 'مطفأ', 'active' => false]);
        $deadR = $dead->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 7, 'message' => 'y', 'active' => true]);

        $this->actingAs($this->owner);
        $keys = collect(Demo::allNotifications())->pluck('key')->all();
        $this->assertNotContains('season-reminder-'.$off->id, $keys);
        $this->assertNotContains('season-reminder-'.$deadR->id, $keys);
    }

    public function test_a_reminder_of_another_shop_is_neither_seen_nor_touched(): void
    {
        $s = $this->season();
        $r = $s->reminders()->create(['business_id' => $this->business->id, 'type' => 'relative', 'days_before' => 7, 'message' => 'x', 'active' => true]);

        $this->actingAs($this->otherOwner);
        $this->assertNull(collect(Demo::allNotifications())->firstWhere('key', 'season-reminder-'.$r->id));
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
}
