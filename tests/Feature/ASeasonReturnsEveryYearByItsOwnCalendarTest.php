<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\SeasonReminder;
use App\Models\User;
use App\Support\Hijri;
use App\Support\SeasonCycle;
use App\Support\Seasons;
use App\Support\SeasonSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموسمُ يعود كلَّ سنةٍ بتقويمه — ولا يُعاد تأريخُه بيد أحد.
 *
 * ═══ المرساةُ لا تُمسّ ═══
 *
 * `starts_at` و`ends_at` تبقيان كما كُتبتا أوّلَ مرّة. والدورةُ تُحسب منهما
 * في كلّ قراءة، فلا صفَّ يُنسخ كلَّ سنة، ولا أصنافَ تُعاد، ولا تقريرَ ينقطع.
 *
 * ═══ وثلاثةُ فخاخٍ تُقاس هنا ═══
 *
 * ١ · الهجريُّ لا يُحسب بإضافة ٣٥٤ يومًا: السنةُ ٣٥٤ أو ٣٥٥، والشهرُ ٢٩ أو
 *     ٣٠. ومن أضاف عددًا ثابتًا انزلق موسمُه عن رمضان بعد سنوات.
 * ٢ · موسمٌ يعبر رأسَ السنة (٢٥ ديسمبر — ٥ يناير) نهايتُه في السنة التالية.
 * ٣ · ٢٩ فبراير في سنةٍ لا كبيسَ فيها يُقصّ إلى ٢٨ ولا ينزلق إلى مارس.
 */
class ASeasonReturnsEveryYearByItsOwnCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => 'password12345', 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function season(array $over = []): Season
    {
        return Season::create(array_merge([
            'business_id' => $this->shop->id,
            'name' => 'موسم',
            'starts_at' => '2026-02-10',
            'ends_at' => '2026-02-20',
            'active' => true,
            'show_in_pos' => true,
            'show_on_website' => true,
        ], $over));
    }

    private function hijriOrSkip(): void
    {
        if (! Hijri::available()) {
            $this->markTestSkipped('امتداد intl غير مثبّت هنا — التقويم الهجريّ يُقاس على PHP التي تحمله');
        }
    }

    /* ══════════ ١ · الميلاديُّ يعود في يومه ══════════ */

    public function test_a_gregorian_season_returns_on_the_same_day_next_year(): void
    {
        $s = $this->season(['repeats' => true, 'calendar' => SeasonCycle::GREGORIAN]);

        $c = $s->cycle(Carbon::parse('2029-02-12'));

        $this->assertSame('2029-02-10', $c['starts_at']->toDateString());
        $this->assertSame('2029-02-20', $c['ends_at']->toDateString());
        $this->assertSame(Season::ACTIVE, $s->status(Carbon::parse('2029-02-12')));
    }

    /** ولا تُمسّ مرساتُه في القاعدة — التقاريرُ القديمة تبقى على تواريخها */
    public function test_and_the_stored_anchor_is_never_rewritten(): void
    {
        $s = $this->season(['repeats' => true]);

        $s->cycle(Carbon::parse('2031-06-01'));
        $s->status(Carbon::parse('2031-06-01'));
        Seasons::forPos($this->shop->id, Carbon::parse('2031-06-01'));

        $this->assertSame('2026-02-10', $s->fresh()->starts_at->toDateString());
        $this->assertSame('2026-02-20', $s->fresh()->ends_at->toDateString());
    }

    /** والمتكرّرُ لا ينتهي: مضت دورتُه فصار قادمًا للسنة التالية */
    public function test_a_repeating_season_is_never_ended(): void
    {
        $s = $this->season(['repeats' => true]);

        $after = Carbon::parse('2029-03-01');

        $this->assertSame(Season::UPCOMING, $s->status($after));
        $this->assertSame('2030-02-10', $s->cycle($after)['starts_at']->toDateString());
    }

    /** وموسمُ المرّة الواحدة يبقى كما كان: ينتهي وينقضي */
    public function test_but_a_one_time_season_still_ends(): void
    {
        $s = $this->season();

        $this->assertSame(Season::ENDED, $s->status(Carbon::parse('2026-03-01')));
        $this->assertSame('2026-02-10', $s->cycle(Carbon::parse('2026-03-01'))['starts_at']->toDateString());
    }

    /* ══════════ ٢ · ما يعبر رأسَ السنة ══════════ */

    public function test_a_season_that_crosses_new_year_keeps_its_length(): void
    {
        $s = $this->season(['starts_at' => '2026-12-25', 'ends_at' => '2027-01-05', 'repeats' => true]);

        $c = $s->cycle(Carbon::parse('2030-12-28'));

        $this->assertSame('2030-12-25', $c['starts_at']->toDateString());
        $this->assertSame('2031-01-05', $c['ends_at']->toDateString());
        $this->assertSame(Season::ACTIVE, $s->status(Carbon::parse('2030-12-28')));
    }

    /** وهو جارٍ في أوّل يناير كذلك — وقد بدأ في السنة الفائتة */
    public function test_and_it_is_live_in_january_from_last_years_start(): void
    {
        $s = $this->season(['starts_at' => '2026-12-25', 'ends_at' => '2027-01-05', 'repeats' => true]);

        $day = Carbon::parse('2031-01-03');

        $this->assertSame(Season::ACTIVE, $s->status($day));
        $this->assertSame('2030-12-25', $s->cycle($day)['starts_at']->toDateString());
    }

    /* ══════════ ٣ · ٢٩ فبراير ══════════ */

    public function test_a_leap_day_season_is_clipped_not_slid_into_march(): void
    {
        $s = $this->season(['starts_at' => '2028-02-29', 'ends_at' => '2028-03-02', 'repeats' => true]);

        $c = $s->cycle(Carbon::parse('2029-02-28'));

        $this->assertSame('2029-02-28', $c['starts_at']->toDateString(), 'انزلق إلى مارس بدل أن يُقصّ');
        $this->assertSame('2029-03-02', $c['ends_at']->toDateString());
    }

    /* ══════════ ٤ · الهجريّ ══════════ */

    /** رمضانُ يعود برمضان — لا بإضافة ٣٥٤ يومًا */
    public function test_ramadan_returns_by_the_hijri_calendar_not_by_adding_354_days(): void
    {
        $this->hijriOrSkip();

        // رمضان ١٤٤٧: من ٢٠٢٦-٠٢-١٨ إلى ٢٠٢٦-٠٣-١٩ (٣٠ يومًا)
        $s = $this->season([
            'starts_at' => '2026-02-18', 'ends_at' => '2026-03-19',
            'repeats' => true, 'calendar' => SeasonCycle::HIJRI,
        ]);

        // رمضان ١٤٤٨ يبدأ ٢٠٢٧-٠٢-٠٨ — لا ٢٠٢٦-٠٢-١٨ + ٣٥٤
        $c = $s->cycle(Carbon::parse('2027-02-10'));

        $this->assertSame('2027-02-08', $c['starts_at']->toDateString());
        $this->assertSame(Season::ACTIVE, $s->status(Carbon::parse('2027-02-10')));

        $naive = Carbon::parse('2026-02-18')->addDays(354)->toDateString();
        $this->assertNotSame($naive, $c['starts_at']->toDateString(), 'الحسابُ الساذجُ صادف الصوابَ — فالقياسُ لا يفرّق');
    }

    /** وطولُ الشهر يُقرأ: يومُ ٣٠ في شهرٍ جاء ٢٩ يُقصّ إلى آخره */
    public function test_a_hijri_day_thirty_is_clipped_to_the_end_of_a_short_month(): void
    {
        $this->hijriOrSkip();

        // ٣٠ رمضان ١٤٤٧ = ٢٠٢٦-٠٣-١٩، ورمضان ١٤٤٨ تسعةٌ وعشرون يومًا
        $s = $this->season([
            'starts_at' => '2026-03-19', 'ends_at' => '2026-03-19',
            'repeats' => true, 'calendar' => SeasonCycle::HIJRI,
        ]);

        $c = $s->cycle(Carbon::parse('2027-03-01'));
        $h = Hijri::fromGregorian($c['starts_at']);

        $this->assertSame(9, $h['month'], 'خرج من رمضان إلى شوّال');
        $this->assertSame(Hijri::daysInMonth($h['year'], 9), $h['day'], 'لم يُقصّ إلى آخر الشهر');
    }

    /** ويتقدّم كلَّ سنةٍ ميلاديّة — أحدَ عشرَ يومًا أو نحوَها، لا يومًا ثابتًا */
    public function test_and_it_moves_forward_each_gregorian_year(): void
    {
        $this->hijriOrSkip();

        $s = $this->season([
            'starts_at' => '2026-02-18', 'ends_at' => '2026-03-19',
            'repeats' => true, 'calendar' => SeasonCycle::HIJRI,
        ]);

        $first = $s->cycle(Carbon::parse('2026-02-20'))['starts_at'];
        $second = $s->cycle(Carbon::parse('2027-02-10'))['starts_at'];
        $gap = $first->diffInDays($second);

        $this->assertGreaterThanOrEqual(353, $gap);
        $this->assertLessThanOrEqual(356, $gap);
    }

    /* ══════════ ٥ · وتصحيحُ دورةٍ بعينها ══════════ */

    /** الحسابُ يقترح، والإعلانُ الرسميُّ يحكم */
    public function test_a_merchant_may_correct_one_cycle_without_touching_the_rest(): void
    {
        $this->hijriOrSkip();

        $s = $this->season([
            'starts_at' => '2026-02-18', 'ends_at' => '2026-03-19',
            'repeats' => true, 'calendar' => SeasonCycle::HIJRI,
            'cycle_overrides' => ['1448' => ['starts_at' => '2027-02-09', 'ends_at' => '2027-03-10']],
        ]);

        $fixed = $s->cycle(Carbon::parse('2027-02-15'));

        $this->assertSame('2027-02-09', $fixed['starts_at']->toDateString());
        $this->assertTrue($fixed['overridden']);

        // وما لم يُصحَّح يبقى على الحساب
        $next = SeasonCycle::forCycle($s, '1449');
        $this->assertFalse($next['overridden']);
    }

    /* ══════════ ٦ · والتذكيرُ يعود مع الدورة ══════════ */

    public function test_a_reminder_returns_with_next_years_cycle(): void
    {
        $s = $this->season(['repeats' => true]);
        $r = SeasonReminder::create([
            'business_id' => $this->shop->id, 'season_id' => $s->id,
            'type' => SeasonReminder::RELATIVE, 'days_before' => 7,
            'message' => 'جهّز أصناف الموسم', 'active' => true,
        ]);

        // دورةُ ٢٠٢٩: تبدأ ١٠ فبراير، فالتذكيرُ يحين في ٣ منه
        $this->travelTo(Carbon::parse('2029-02-04 10:00'));
        $this->assertTrue($r->fresh()->isDue($s->fresh()), 'لم يحن تذكيرُ الدورة الجارية');

        // يقرؤه فيسكت في دورته
        $r->update(['acknowledged_for' => $s->cycle(today())['starts_at']->toDateString()]);
        $this->assertFalse($r->fresh()->isDue($s->fresh()), 'قُرئ ثمّ عاد في الدورة نفسِها');

        // وفي السنة التالية يعود بلا أن يمسّه أحد
        $this->travelTo(Carbon::parse('2030-02-04 10:00'));
        $this->assertTrue($r->fresh()->isDue($s->fresh()), 'لم يعد التذكيرُ في الدورة التالية');

        $this->travelBack();
    }

    /** ولا يتكرّر مرّتين في الدورة الواحدة ولو فُتحت الشاشةُ عشرًا */
    public function test_and_it_does_not_repeat_twice_in_one_cycle(): void
    {
        $s = $this->season(['repeats' => true]);
        SeasonReminder::create([
            'business_id' => $this->shop->id, 'season_id' => $s->id,
            'type' => SeasonReminder::RELATIVE, 'days_before' => 7,
            'message' => 'جهّز', 'active' => true,
        ]);

        $this->travelTo(Carbon::parse('2029-02-04 10:00'));

        $this->assertCount(1, Seasons::due($this->shop->id));
        $this->assertCount(1, Seasons::due($this->shop->id), 'تكرّر بقراءةٍ ثانية');

        $this->travelBack();
    }

    /* ══════════ ٧ · وفي الصندوق والموقع ══════════ */

    public function test_the_till_and_the_website_see_the_returning_season(): void
    {
        $s = $this->season(['repeats' => true]);
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردٌ أحمر', 'price' => 5, 'cost' => 2, 'quantity' => 10,
        ]);
        $s->products()->attach($p->id);

        $day = Carbon::parse('2032-02-12');

        $pos = Seasons::forPos($this->shop->id, $day);
        $this->assertCount(1, $pos, 'الشريطُ لا يرى الموسمَ العائد');
        $this->assertSame([$p->id], $pos[0]['product_ids']);

        $this->assertCount(1, Seasons::forWebsite($this->shop->id, $day));

        // وخارجَ الدورة لا يُعرض
        $this->assertCount(0, Seasons::forPos($this->shop->id, Carbon::parse('2032-06-01')));
    }

    /* ══════════ ٨.٥ · وتقريرُ كلّ دورةٍ على حدة ══════════ */

    /** بيعةٌ في هذه السنةِ لا تُحسب في تقرير السنة الماضية — ولا العكس */
    public function test_each_cycle_keeps_its_own_sales_report(): void
    {
        $s = $this->season(['repeats' => true]);
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'صنف', 'price' => 10, 'cost' => 4, 'quantity' => 100,
        ]);
        $s->products()->attach($p->id);

        $this->sold($s, $p, '2029-02-12', 3);   // دورةُ ٢٠٢٩
        $this->sold($s, $p, '2030-02-12', 7);   // دورةُ ٢٠٣٠

        $old = SeasonSales::report($s, null, SeasonCycle::forCycle($s, '2029'));
        $new = SeasonSales::report($s, null, SeasonCycle::forCycle($s, '2030'));

        $this->assertSame(30.0, $old['summary']['sales'], 'تسرّبت بيعاتُ سنةٍ إلى تقرير سنةٍ أخرى');
        $this->assertSame(70.0, $new['summary']['sales']);
    }

    /** ولا تتبدّل أرقامُ السنة الماضية حين تحلّ السنةُ الجديدة */
    public function test_and_last_years_numbers_do_not_change_when_the_year_turns(): void
    {
        $s = $this->season(['repeats' => true]);
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'صنف', 'price' => 10, 'cost' => 4, 'quantity' => 100,
        ]);
        $s->products()->attach($p->id);
        $this->sold($s, $p, '2029-02-12', 3);

        $before = SeasonSales::report($s, null, SeasonCycle::forCycle($s, '2029'))['summary'];

        $this->sold($s, $p, '2030-02-12', 7);
        $after = SeasonSales::report($s, null, SeasonCycle::forCycle($s, '2029'))['summary'];

        $this->assertSame($before, $after, 'تبدّل تقريرُ دورةٍ مضت');
    }

    /** ولا تُمسّ الطلباتُ ولا البنودُ القديمة — القصُّ قراءةٌ لا كتابة */
    public function test_and_no_old_order_row_is_rewritten(): void
    {
        $s = $this->season(['repeats' => true]);
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'صنف', 'price' => 10, 'cost' => 4, 'quantity' => 100,
        ]);
        $this->sold($s, $p, '2029-02-12', 3);

        $before = DB::table('order_items')->orderBy('id')->get()->toJson();

        SeasonSales::report($s, null, SeasonCycle::forCycle($s, '2030'));
        $s->cycle(Carbon::parse('2031-05-05'));

        $this->assertSame($before, DB::table('order_items')->orderBy('id')->get()->toJson());
    }

    /** بيعةٌ منسوبةٌ للموسم في يومٍ بعينه */
    private function sold(Season $s, Product $p, string $day, int $qty): void
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'S-'.$day.'-'.$qty,
            'status' => 'مكتمل', 'is_held' => false,
            'subtotal' => 10 * $qty, 'total' => 10 * $qty, 'ordered_at' => $day.' 12:00:00',
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => 10, 'quantity' => $qty, 'total' => 10 * $qty, 'cost' => 4,
            'season_id' => $s->id, 'season_name' => $s->name,
        ]);
    }

    /* ══════════ ٩ · وما يمرّ من الشاشة ══════════ */

    public function test_a_merchant_creates_a_returning_season_from_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.seasons.store'), [
                'name' => 'العيد', 'starts_at' => '2026-03-20', 'ends_at' => '2026-03-23',
                'repeats' => true, 'calendar' => SeasonCycle::GREGORIAN,
            ])->assertRedirect();

        $s = Season::where('business_id', $this->shop->id)->firstOrFail();

        $this->assertTrue($s->repeats);
        $this->assertSame(SeasonCycle::GREGORIAN, $s->calendar);
    }

    /** والأصلُ «لا يتكرّر»: من لم يطلب التكرارَ لا يُفرض عليه */
    public function test_and_a_season_created_without_asking_does_not_repeat(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.seasons.store'), [
                'name' => 'تصفية', 'starts_at' => '2026-03-20', 'ends_at' => '2026-03-23',
            ])->assertRedirect();

        $this->assertFalse(Season::where('name', 'تصفية')->firstOrFail()->repeats);
    }

    /**
     * وتحويلُ موسمٍ قائمٍ إلى متكرّرٍ لا يمسّ تاريخَه ولا أصنافَه ولا تقاريره.
     *
     * وهو المطلوبُ صراحةً: طريقٌ آمنٌ لمن له مواسمُ قديمة.
     */
    public function test_an_old_season_becomes_repeating_without_losing_anything(): void
    {
        $s = $this->season();
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'صنف', 'price' => 5, 'cost' => 2, 'quantity' => 10,
        ]);
        $s->products()->attach($p->id);

        $this->actingAs($this->owner)
            ->put(route('admin.seasons.update', $s->id), [
                'name' => $s->name, 'starts_at' => '2026-02-10', 'ends_at' => '2026-02-20',
                'repeats' => true, 'calendar' => SeasonCycle::GREGORIAN,
            ])->assertRedirect();

        $s->refresh();

        $this->assertTrue($s->repeats);
        $this->assertSame('2026-02-10', $s->starts_at->toDateString(), 'تبدّلت المرساة');
        $this->assertSame([$p->id], $s->products()->pluck('products.id')->all());
    }

    /* ══════════ ١٠ · وبابُ تصحيح الدورة ══════════ */

    public function test_a_cycle_correction_is_written_and_read_back(): void
    {
        $s = $this->season(['repeats' => true]);

        $this->actingAs($this->owner)
            ->put(route('admin.seasons.cycle', $s->id), [
                'cycle' => '2030', 'starts_at' => '2030-02-12', 'ends_at' => '2030-02-22',
            ])->assertRedirect();

        $this->assertSame('2030-02-12', $s->fresh()->cycle(Carbon::parse('2030-02-14'))['starts_at']->toDateString());
    }

    /** وتُردّ إلى الحساب بضغطةٍ واحدة */
    public function test_and_it_can_be_reset_to_the_calculation(): void
    {
        $s = $this->season(['repeats' => true, 'cycle_overrides' => ['2030' => ['starts_at' => '2030-02-12', 'ends_at' => '2030-02-22']]]);

        $this->actingAs($this->owner)
            ->put(route('admin.seasons.cycle', $s->id), ['cycle' => '2030', 'reset' => true])
            ->assertRedirect();

        $this->assertSame('2030-02-10', $s->fresh()->cycle(Carbon::parse('2030-02-14'))['starts_at']->toDateString());
    }

    /** وموسمُ المرّة الواحدة لا دورةَ فيه تُصحَّح — يُعدَّل تاريخُه نفسُه */
    public function test_but_a_one_time_season_has_no_cycle_to_correct(): void
    {
        $s = $this->season();

        $this->actingAs($this->owner)
            ->put(route('admin.seasons.cycle', $s->id), [
                'cycle' => '2026', 'starts_at' => '2026-02-12', 'ends_at' => '2026-02-22',
            ])->assertSessionHasErrors('cycle');

        $this->assertNull($s->fresh()->cycle_overrides);
    }

    /** ولا يصحّح جارٌ دورةَ موسمِ جاره */
    public function test_and_a_stranger_cannot_correct_another_shops_cycle(): void
    {
        $other = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعه']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'x@abaadapp.om',
            'password' => 'password12345', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $s = $this->season(['repeats' => true]);

        $this->actingAs($stranger)
            ->put(route('admin.seasons.cycle', $s->id), [
                'cycle' => '2030', 'starts_at' => '2030-01-01', 'ends_at' => '2030-01-05',
            ])->assertNotFound();

        $this->assertNull($s->fresh()->cycle_overrides);
    }

    /* ══════════ ٨ · ولا يتسرّب موسمٌ بين المتاجر ══════════ */

    public function test_a_returning_season_stays_inside_its_shop(): void
    {
        $other = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
        $s = $this->season(['repeats' => true]);
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'صنف', 'price' => 5, 'cost' => 2, 'quantity' => 10,
        ]);
        $s->products()->attach($p->id);

        $day = Carbon::parse('2030-02-12');

        $this->assertCount(1, Seasons::forPos($this->shop->id, $day));
        $this->assertCount(0, Seasons::forPos($other->id, $day));
        $this->assertCount(0, Seasons::due($other->id));
    }
}
