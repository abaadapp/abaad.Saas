<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\SalesChannel;
use App\Support\SeasonSales;
use App\Support\Website\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * البيعةُ تذكر موسمَها ساعةَ البيع — والتقريرُ يقرأ ما كُتب ولا يخمّن.
 *
 * لا دفترَ ثانيًا: البيعةُ بموسمٍ تُقيَّد كما تُقيَّد بلا موسم حرفًا بحرف،
 * والأداءُ يُقرأ من البنود التي حملت اسمَه.
 */
class ASaleRemembersItsSeasonAndTheReportReadsOnlyWhatWasWrittenTest extends TestCase
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
        Currency::create(['business_id' => $this->other->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->other->id);
        Setting::create(['business_id' => $this->other->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->otherOwner = User::create(['business_id' => $this->other->id, 'name' => 'جار', 'email' => 'n@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ أدوات ═══════════ */

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** موسمٌ جارٍ اليوم (2027-02-01) */
    private function season(array $attrs = []): Season
    {
        return Season::create($attrs + [
            'business_id' => $this->business->id, 'name' => 'رمضان 2027',
            'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20',
            'active' => true, 'show_in_pos' => true, 'show_on_website' => true,
        ]);
    }

    /** بيعةٌ من الصندوق — والموسمُ على البند كما يرسله الصندوق */
    private function sell(array $items, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/pos/checkout', [
            'items' => array_map(fn ($i) => $i + ['qty' => 1, 'price' => 10], $items),
            'payment_method' => 'نقدي',
        ]);
    }

    private function line(Product $p, ?int $seasonId, int $qty = 1): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'season_id' => $seasonId];
    }

    private function lastOrder(): Order
    {
        return Order::where('business_id', $this->business->id)->where('is_held', false)->orderByDesc('id')->firstOrFail();
    }

    private function report(Season $s, ?string $channel = null): array
    {
        return SeasonSales::report($s->fresh(), $channel);
    }

    /* ═══════════ النسبة ═══════════ */

    public function test_a_pos_sale_from_an_explicit_season_context_is_attributed(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);

        $this->sell([$this->line($p, $s->id)])->assertOk();

        $item = $this->lastOrder()->items->first();
        $this->assertSame($s->id, (int) $item->season_id);
        $this->assertSame('رمضان 2027', $item->season_name, 'الاسمُ لقطةٌ على البند');
        $this->assertSame(SalesChannel::POS, $this->lastOrder()->channel, 'والبابُ يُكتب على الطلب');
    }

    public function test_a_foreign_business_season_id_is_dropped_not_trusted(): void
    {
        $p = $this->product();
        $foreign = Season::create(['business_id' => $this->other->id, 'name' => 'موسم الجار', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'active' => true, 'show_in_pos' => true, 'show_on_website' => true]);
        // وصنفُنا مربوطٌ بموسم الجار — عبثًا أو خطأً — فلا يُنقذنا إلّا حصرُ المتجر
        $foreign->products()->attach($p->id);

        $this->sell([$this->line($p, $foreign->id)])->assertOk();

        $this->assertNull($this->lastOrder()->items->first()->season_id);
        $this->assertSame(0, $this->report($foreign)['summary']['orders'], 'ولا يظهر شيءٌ في تقرير الجار');
    }

    public function test_a_product_not_linked_to_the_season_is_not_attributed(): void
    {
        $p = $this->product();
        $s = $this->season(); // لا يضمّه

        $this->sell([$this->line($p, $s->id)])->assertOk();

        $this->assertNull($this->lastOrder()->items->first()->season_id);
    }

    public function test_only_a_live_season_is_attributed(): void
    {
        $p = $this->product();
        $off = $this->season(['name' => 'مُطفأ', 'active' => false]);
        $upcoming = $this->season(['name' => 'قادم', 'starts_at' => '2027-03-01', 'ends_at' => '2027-03-10']);
        $ended = $this->season(['name' => 'منتهٍ', 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-31']);
        foreach ([$off, $upcoming, $ended] as $s) {
            $s->products()->attach($p->id);
            $this->sell([$this->line($p, $s->id)])->assertOk();
            $this->assertNull($this->lastOrder()->items->first()->season_id, $s->name.' لا يُنسب إليه');
        }
    }

    public function test_a_season_hidden_from_pos_is_not_attributed_through_pos(): void
    {
        $p = $this->product();
        $s = $this->season(['show_in_pos' => false]);
        $s->products()->attach($p->id);

        $this->sell([$this->line($p, $s->id)])->assertOk();

        $this->assertNull($this->lastOrder()->items->first()->season_id);
    }

    public function test_a_product_in_two_seasons_goes_to_the_chosen_one_only(): void
    {
        $p = $this->product();
        $ramadan = $this->season(['name' => 'رمضان']);
        $spring = $this->season(['name' => 'الربيع']);
        $ramadan->products()->attach($p->id);
        $spring->products()->attach($p->id);

        $this->sell([$this->line($p, $ramadan->id)])->assertOk();

        $this->assertSame($ramadan->id, (int) $this->lastOrder()->items->first()->season_id);
        $this->assertSame(10.0, $this->report($ramadan)['summary']['sales']);
        $this->assertSame(0.0, $this->report($spring)['summary']['sales'], 'لا تُعدّ البيعةُ مرّتين');
        $this->assertSame(1, OrderItem::whereNotNull('season_id')->count(), 'بندٌ واحد لا اثنان');
    }

    public function test_a_sale_without_context_is_not_guessed_even_with_one_live_season(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);

        $this->sell([$this->line($p, null)])->assertOk();

        $this->assertNull($this->lastOrder()->items->first()->season_id, 'شاشةُ «الكل» لا تُنسب ولو كان الموسمُ واحدًا');
        $this->assertSame(0, $this->report($s)['summary']['orders']);
        $this->assertSame(['orders' => 1, 'units' => 1], $this->report($s)['unattributed'], 'ويُقال للتاجر أنّ بيعةً من أصنافه لم تُنسب');
    }

    public function test_removing_the_product_from_the_season_keeps_the_history(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        $s->products()->detach($p->id);

        $this->assertSame(10.0, $this->report($s)['summary']['sales']);
        $this->assertSame(1, $this->report($s)['summary']['orders']);
    }

    public function test_renaming_the_season_keeps_the_report_and_the_snapshot(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        $s->update(['name' => 'رمضان المبارك']);

        $this->assertSame(10.0, $this->report($s)['summary']['sales']);
        $this->assertSame('رمضان 2027', OrderItem::first()->season_name, 'واللقطةُ كما كُتبت');
    }

    public function test_a_sale_with_no_season_at_all_still_works(): void
    {
        $p = $this->product();

        $this->sell([['id' => $p->id, 'name' => $p->name]])->assertOk();

        $o = $this->lastOrder();
        $this->assertNull($o->items->first()->season_id);
        $this->assertNull($o->items->first()->season_name);
        $this->assertSame(49, (int) $p->fresh()->quantity);
    }

    public function test_old_orders_are_not_backfilled(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);

        // بيعةٌ سبقت الميزة: داخل المدّة، من صنف الموسم، بلا نسبة
        $this->sell([$this->line($p, null)])->assertOk();
        DB::table('orders')->update(['channel' => null]);

        $r = $this->report($s);
        $this->assertSame(0, $r['summary']['orders']);
        $this->assertSame(0.0, $r['summary']['sales']);
        $this->assertSame([], $r['channels']);
        $this->assertSame(1, $r['unattributed']['orders']);
    }

    /* ═══════════ الماليةُ لا تتغيّر ═══════════ */

    /** البيعةُ نفسُها مرّتين — بلا موسمٍ ثمّ بموسم — وكلُّ ما في الدفتر واحد */
    public function test_a_season_sale_posts_exactly_what_the_same_sale_posted_before(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $p = $this->product(['price' => 12.5, 'cost' => 4.25]);
        $s = $this->season();
        $s->products()->attach($p->id);

        $snapshot = function (): array {
            $o = $this->lastOrder();
            $entries = JournalEntry::where('sourceable_type', Order::class)->where('sourceable_id', $o->id)->orderBy('id')->get();
            $lines = JournalLine::whereIn('journal_entry_id', $entries->pluck('id'))
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->orderBy('journal_lines.id')
                ->get(['accounts.system_key', 'journal_lines.debit', 'journal_lines.credit'])
                ->map(fn ($l) => [$l->system_key, round((float) $l->debit, 3), round((float) $l->credit, 3)])->all();
            $tx = Transaction::where('order_id', $o->id)->get();

            return [
                'total' => (float) $o->total, 'tax' => (float) $o->tax, 'subtotal' => (float) $o->subtotal,
                'tx_count' => $tx->count(), 'tx_amount' => (float) $tx->sum('amount'), 'tx_tax' => (float) $tx->sum('tax_amount'),
                'entries' => $entries->count(), 'sources' => $entries->pluck('source')->all(),
                'lines' => $lines,
                // رقمُ الفاتورة يختلف بين البيعتين وحدَه — يُنزع ليُقارَن الباقي
                'descriptions' => $entries->pluck('description')->map(fn ($d) => preg_replace('/INV-\d+/', 'INV', $d))->all(),
            ];
        };

        $this->sell([$this->line($p, null, 2)])->assertOk();
        $plain = $snapshot();

        $this->sell([$this->line($p, $s->id, 2)])->assertOk();
        $seasoned = $snapshot();

        $this->assertSame($plain, $seasoned);
        $this->assertSame(2, $seasoned['entries'], 'قيدُ البيع وقيدُ التكلفة — لا ثالثَ لهما');
        $this->assertSame(1, $seasoned['tx_count']);
        foreach ($seasoned['descriptions'] as $d) {
            $this->assertStringNotContainsString('موسم', $d, 'لا قيدَ باسم الموسم');
        }
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
        $this->assertSame(1, Season::count(), 'ولا حسابَ ولا جدولَ للموسم في المالية');
    }

    public function test_season_cogs_agrees_with_the_books(): void
    {
        $p = $this->product(['cost' => 3.75]);
        $q = $this->product(['name' => 'زهرة', 'price' => 2, 'cost' => 0.5]);
        $s = $this->season();
        $s->products()->attach([$p->id, $q->id]);

        $this->sell([$this->line($p, $s->id, 2), $this->line($q, $s->id, 3)])->assertOk();

        $r = $this->report($s)['summary'];
        $this->assertSame(round(Books::costOf($this->lastOrder()), 3), $r['cogs'], 'التكلفةُ بقاعدة الدفتر نفسِها');
        $this->assertSame(9.0, $r['cogs']);
        $this->assertSame(26.0, $r['sales']);
    }

    /* ═══════════ التقرير ═══════════ */

    public function test_sales_are_historical_and_a_price_change_does_not_rewrite_them(): void
    {
        $p = $this->product(['price' => 10]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id, 3)])->assertOk();

        $p->update(['price' => 99]);

        $this->assertSame(30.0, $this->report($s)['summary']['sales']);
    }

    public function test_cogs_is_historical_and_a_cost_change_does_not_rewrite_it(): void
    {
        $p = $this->product(['cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id, 3)])->assertOk();

        $p->update(['cost' => 40]);

        $this->assertSame(12.0, $this->report($s)['summary']['cogs']);
    }

    public function test_gross_profit_and_margin(): void
    {
        $p = $this->product(['price' => 10, 'cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id, 2)])->assertOk();

        $r = $this->report($s)['summary'];
        $this->assertSame(20.0, $r['sales']);
        $this->assertSame(8.0, $r['cogs']);
        $this->assertSame(12.0, $r['gross_profit']);
        $this->assertSame(60.0, $r['margin']);
    }

    public function test_an_empty_season_has_zero_margin_not_a_division_error(): void
    {
        $s = $this->season();

        $r = $this->report($s)['summary'];
        $this->assertSame(['sales' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0, 'margin' => 0.0, 'orders' => 0, 'units' => 0], $r);
    }

    public function test_a_cancelled_sale_leaves_the_report(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();
        $this->assertSame(1, $this->report($s)['summary']['orders']);

        OrderCorrection::cancel($this->lastOrder(), 'رجّعها');

        $r = $this->report($s)['summary'];
        $this->assertSame(0, $r['orders']);
        $this->assertSame(0.0, $r['sales']);
        $this->assertSame(1, OrderItem::whereNotNull('season_id')->count(), 'والنسبةُ على البند لا تُمحى — الحالةُ هي التي تُخرجها');
    }

    public function test_a_quantity_correction_follows_the_invoice(): void
    {
        $p = $this->product(['price' => 10, 'cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id, 3)])->assertOk();

        $order = $this->lastOrder();
        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'اكتفى بواحدة');

        $r = $this->report($s)['summary'];
        $this->assertSame(10.0, $r['sales']);
        $this->assertSame(4.0, $r['cogs']);
        $this->assertSame(1, $r['units']);
        $this->assertSame($s->id, (int) $order->items()->first()->season_id, 'التصحيحُ لا يمسّ النسبة');
    }

    public function test_orders_and_units_are_counted_once(): void
    {
        $p = $this->product();
        $q = $this->product(['name' => 'زهرة', 'price' => 2]);
        $s = $this->season();
        $s->products()->attach([$p->id, $q->id]);

        $this->sell([$this->line($p, $s->id, 2), $this->line($q, $s->id, 5)])->assertOk();
        $this->sell([$this->line($p, $s->id, 1)])->assertOk();
        $this->sell([$this->line($q, null, 1)])->assertOk(); // بلا نسبة

        $r = $this->report($s)['summary'];
        $this->assertSame(2, $r['orders']);
        $this->assertSame(8, $r['units']);
    }

    public function test_top_products_come_from_the_attributed_lines(): void
    {
        $p = $this->product(['name' => 'باقة', 'price' => 10, 'cost' => 4]);
        $q = $this->product(['name' => 'زهرة', 'price' => 2, 'cost' => 1]);
        $s = $this->season();
        $s->products()->attach([$p->id, $q->id]);
        $this->sell([$this->line($p, $s->id, 2), $this->line($q, $s->id, 5)])->assertOk();
        $p->update(['name' => 'باقة كبيرة']);

        $top = $this->report($s)['top'];
        $this->assertSame(['باقة', 'زهرة'], array_column($top, 'name'), 'بالاسم كما بيع، والأعلى مبيعًا أوّلًا');
        $this->assertSame([20.0, 10.0], array_column($top, 'sales'));
        $this->assertSame([2, 5], array_column($top, 'units'));
        $this->assertSame([12.0, 5.0], array_column($top, 'gross_profit'));
    }

    public function test_the_report_is_scoped_to_its_own_business(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        // موسمُ الجار يحمل — بالمصادفة أو بالعبث — معرّفَ موسمنا على بندنا
        $neighbour = Season::create(['business_id' => $this->other->id, 'name' => 'موسم الجار', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'active' => true, 'show_in_pos' => true, 'show_on_website' => true]);
        OrderItem::query()->update(['season_id' => $neighbour->id]);

        $this->assertSame(0, $this->report($neighbour)['summary']['orders'], 'بيعةُ متجرٍ لا تُقرأ من موسم متجرٍ آخر');
        $this->assertSame([], $this->report($neighbour)['top']);
    }

    public function test_the_report_counts_by_attribution_not_by_date_and_never_twice(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        // طلبٌ نُسب ثمّ صار تاريخُه خارج المدّة (تصحيحٌ أو ترحيل) — يبقى مرّةً واحدة
        Order::query()->update(['ordered_at' => '2026-06-01 10:00:00']);

        $r = $this->report($s)['summary'];
        $this->assertSame(1, $r['orders']);
        $this->assertSame(10.0, $r['sales']);
    }

    /* ═══════════ القنوات ═══════════ */

    public function test_a_pos_sale_appears_under_pos(): void
    {
        $p = $this->product(['cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        $this->assertSame([[
            'key' => 'pos', 'label' => 'نقطة البيع', 'sales' => 10.0, 'orders' => 1, 'gross_profit' => 6.0,
        ]], $this->report($s)['channels']);
    }

    public function test_an_order_without_a_channel_is_unknown_not_website(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();
        DB::table('orders')->update(['channel' => null]);

        $channels = $this->report($s)['channels'];
        $this->assertSame(['unknown'], array_column($channels, 'key'));
        $this->assertSame('غير محدّدة', $channels[0]['label']);
        $this->assertStringNotContainsString('الموقع', json_encode($channels, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_website_has_no_checkout_and_nothing_writes_its_channel(): void
    {
        $this->assertFalse(Commerce::checkout($this->business->id), 'الموقعُ لا يُنشئ طلبًا اليوم');
        $this->assertSame(0, Order::count());

        /*
         * حارسٌ نصّيّ: لا موضعَ في التطبيق يكتب قناةَ «الموقع» على طلب. فحين
         * يُكتب يومًا — بسلّةٍ حقيقيّة — يسقط هذا ويُراجَع أنّ المصدر موثوق.
         */
        $writers = collect(glob(app_path('**/*.php')))->merge(glob(app_path('**/**/*.php')))->merge(glob(app_path('**/**/**/*.php')))
            ->filter(fn ($f) => preg_match("/'channel'\s*=>\s*SalesChannel::WEBSITE|channel'\s*=>\s*'website'/", file_get_contents($f)))
            ->map(fn ($f) => str_replace(app_path().'/', '', $f))->values()->all();

        $this->assertSame([], $writers);
    }

    public function test_a_website_row_appears_only_when_a_real_order_carries_that_channel(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);

        $this->sell([$this->line($p, $s->id)])->assertOk();
        $this->assertSame(['pos'], array_column($this->report($s)['channels'], 'key'), 'لا صفَّ للموقع بلا طلبٍ منه');

        $this->sell([$this->line($p, $s->id, 2)])->assertOk();
        // طلبٌ حقيقيّ من الموقع — حين يوجد يومًا
        $this->lastOrder()->update(['channel' => SalesChannel::WEBSITE]);

        $rows = collect($this->report($s)['channels'])->keyBy('key');
        $this->assertSame(['website', 'pos'], $rows->keys()->all(), 'الأعلى مبيعًا أوّلًا');
        $this->assertSame(['sales' => 20.0, 'orders' => 1], ['sales' => $rows['website']['sales'], 'orders' => $rows['website']['orders']]);
        $this->assertSame('الموقع الإلكتروني', $rows['website']['label']);
    }

    public function test_all_channels_add_up_to_the_summary_and_a_channel_filter_narrows(): void
    {
        $p = $this->product(['cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id, 1)])->assertOk();
        $this->sell([$this->line($p, $s->id, 2)])->assertOk();
        $this->lastOrder()->update(['channel' => null]);

        $r = $this->report($s);
        $this->assertSame($r['summary']['sales'], round(array_sum(array_column($r['channels'], 'sales')), 3));
        $this->assertSame($r['summary']['orders'], array_sum(array_column($r['channels'], 'orders')));
        $this->assertSame($r['summary']['gross_profit'], round(array_sum(array_column($r['channels'], 'gross_profit')), 3));

        $pos = $this->report($s, SalesChannel::POS)['summary'];
        $this->assertSame(['sales' => 10.0, 'orders' => 1, 'units' => 1], ['sales' => $pos['sales'], 'orders' => $pos['orders'], 'units' => $pos['units']]);
        $unknown = $this->report($s, SalesChannel::UNKNOWN)['summary'];
        $this->assertSame(20.0, $unknown['sales']);
    }

    /* ═══════════ الصلاحيات ═══════════ */

    private function page(User $as, Season $s, array $query = [])
    {
        return $this->actingAs($as)->get(route('admin.seasons.show', [$s->id] + $query));
    }

    public function test_the_owner_reads_the_performance_on_the_season_page(): void
    {
        $p = $this->product(['cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        $props = $this->page($this->owner, $s)->assertOk()->viewData('page')['props'];
        $this->assertSame(10.0, $props['performance']['summary']['sales']);
        $this->assertSame(6.0, $props['performance']['summary']['gross_profit']);

        $filtered = $this->page($this->owner, $s, ['channel' => 'pos'])->viewData('page')['props']['performance'];
        $this->assertSame('pos', $filtered['channel']);
        $bogus = $this->page($this->owner, $s, ['channel' => 'x'])->viewData('page')['props']['performance'];
        $this->assertNull($bogus['channel'], 'قناةٌ لا تُعرف تُهمل');
    }

    public function test_who_opens_products_but_not_reports_sees_no_money(): void
    {
        $p = $this->product(['cost' => 4]);
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        $sales = User::create(['business_id' => $this->business->id, 'name' => 'بائع', 'email' => 's@abaad.om', 'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط']);
        $this->assertTrue($sales->allows('products'));
        $this->assertFalse($sales->allows('reports'));

        $res = $this->page($sales, $s)->assertOk();
        $this->assertNull($res->viewData('page')['props']['performance']);
        $this->assertStringNotContainsString('gross_profit', json_encode($res->viewData('page')['props']));
    }

    public function test_a_cashier_gains_no_financial_sight_through_seasons(): void
    {
        $s = $this->season();
        $cashier = User::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);

        $this->page($cashier, $s)->assertForbidden();
    }

    public function test_a_neighbour_cannot_read_the_report(): void
    {
        $s = $this->season();

        $this->page($this->otherOwner, $s)->assertNotFound();
    }

    /* ═══════════ الحذفُ والتعليق ═══════════ */

    public function test_a_season_with_attributed_sales_is_not_deleted(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);
        $this->sell([$this->line($p, $s->id)])->assertOk();

        $this->actingAs($this->owner)->delete(route('admin.seasons.destroy', $s->id))->assertSessionHasErrors('message');

        $this->assertNotNull($s->fresh());
        $this->assertSame(1, OrderItem::count());
    }

    public function test_a_season_without_sales_is_still_deleted_as_before(): void
    {
        $s = $this->season();

        $this->actingAs($this->owner)->delete(route('admin.seasons.destroy', $s->id))->assertRedirect(route('admin.seasons.index'));

        $this->assertNull($s->fresh());
    }

    public function test_a_held_cart_keeps_its_season_and_checkout_decides_again(): void
    {
        $p = $this->product();
        $s = $this->season();
        $s->products()->attach($p->id);

        $this->actingAs($this->owner)->postJson('/pos/hold', [
            'items' => [$this->line($p, $s->id)],
        ])->assertOk();
        $held = Order::where('is_held', true)->firstOrFail();
        $this->assertSame($s->id, (int) $held->items->first()->season_id);

        $this->actingAs($this->owner)->get(route('pos.orders.resume', $held->id));
        $this->assertSame($s->id, session('resume_cart')['items'][0]['season_id']);

        // انتهى الموسمُ قبل أن تُستأنف — تُباع بلا موسم ولا تُرفض
        Carbon::setTestNow('2027-03-01 10:00:00');
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [$this->line($p, $s->id)], 'payment_method' => 'نقدي', 'resume_id' => $held->id,
        ])->assertOk();
        $this->assertNull($this->lastOrder()->items->first()->season_id);
    }
}
