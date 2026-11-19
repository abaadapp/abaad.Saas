<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\Stock;
use App\Support\TillStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ‏‏مخزونُ الصندوق يأتيه ما تغيّر وحده — ولا يفوته تغيير.
 *
 * ═══ ما كان ═══
 *
 * كلُّ سؤالٍ (كلَّ عشرين ثانية) يأتي بالأصناف كلِّها: عند عشرة آلاف صنف
 * 709KB لكلّ صندوق، تغيّر شيءٌ أو لم يتغيّر.
 *
 * ═══ ما صار (`App\Support\TillStock`) ═══
 *
 * الصندوقُ يُعيد بصماتِ ما أُعطيه، فيُجاب بالسلال التي اختلفت وحدها. والبصمةُ
 * من الكميّات نفسِها لا من وقتٍ أو رقمِ إصدار: فأيُّ بابٍ كتب الكميّة — بيعٌ
 * أو شراءٌ أو تحويلٌ أو `DB::table` لا يمسّ `updated_at` — يظهر في الجواب.
 *
 * وما يُثبته هذا الملفّ: ما يجمعه الصندوقُ من الأجوبة يساوي القائمةَ الكاملة
 * دائمًا، بعد كلّ نوعٍ من التغيير، ومن أيّ أساسٍ سأل. ولا يُقرأ بما يرسله
 * الصندوقُ متجرٌ أو فرعٌ غيرُ ما في جلسته.
 */
class TheTillIsSentOnlyTheStockThatChangedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    /** @var list<Product> */
    private array $shelf = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        // ‏‏أكثرُ من سلّة: مئةُ صنف — أربعُ سلال
        for ($k = 1; $k <= 100; $k++) {
            $this->shelf[] = Product::create([
                'business_id' => $this->business->id, 'name' => "صنف $k", 'price' => 2, 'cost' => 1,
                'quantity' => 50 + $k, 'alert_qty' => 5, 'active' => true,
            ]);
        }

        $this->actingAs($this->owner);
        $this->activatePosDevice($this->business->id, $this->muscat->id);
        session(['current_branch' => $this->muscat->id]);
    }

    private function scope(): string
    {
        return TillStock::scope($this->business->id, $this->muscat->id);
    }

    /** ‏‏سؤالُ الصندوق بما يعرفه */
    private function ask(?array $known = null, ?string $scope = null)
    {
        $body = ['scope' => $scope ?? $this->scope()];
        if ($known) {
            $body += ['v' => $known['v'], 'b' => $known['b'], 'h' => implode('', $known['h'])];
        }

        return $this->postJson(route('pos.stock-feed'), $body);
    }

    /** ‏‏ما يفعله الصندوقُ بالجواب (`applyStock` في `useLiveStock.ts`) — بالقواعد نفسِها */
    private function apply(?array $known, array $d): array
    {
        if ($d['same'] ?? false) {
            $this->assertNotNull($known);
            $this->assertSame($known['v'], $d['v']);

            return $known;
        }
        if ($d['full'] ?? false) {
            return ['v' => $d['v'], 'b' => $d['b'], 'h' => str_split($d['h'], TillStock::HASH), 'rows' => collect($d['products'])->keyBy('id')->all()];
        }
        $this->assertSame($known['v'], $d['base'], 'جوابٌ على غير ما سأل عنه الصندوق');
        $this->assertSame($known['b'], $d['b']);
        $touched = array_column($d['buckets'], 0);
        $rows = array_filter($known['rows'], fn ($r) => ! in_array($r['id'] % $known['b'], $touched, true));
        foreach ($d['products'] as $r) {
            $this->assertContains($r['id'] % $known['b'], $touched, 'صنفٌ من سلّةٍ لم تُعلَن');
            $rows[$r['id']] = $r;
        }
        $h = $known['h'];
        foreach ($d['buckets'] as [$i, $hash]) {
            $h[$i] = $hash;
        }

        return ['v' => $d['v'], 'b' => $known['b'], 'h' => $h, 'rows' => $rows];
    }

    /** ‏‏القائمةُ الكاملة الآن — كما يحسبها الخادمُ لأوّل سؤال */
    private function truth(): array
    {
        return collect($this->ask()->assertOk()->json('products'))->keyBy('id')->all();
    }

    private function assertTillHasTheTruth(array $known, string $after): void
    {
        ksort($known['rows']);
        $truth = $this->truth();
        ksort($truth);
        $this->assertSame($truth, $known['rows'], "ما جمعه الصندوقُ بعد «{$after}» ليس ما عند الخادم");
        $this->assertTrue($this->ask($known)->json('same'), "الخادمُ لا يرى الصندوقَ مطابقًا بعد «{$after}»");
    }

    /* ─────────────────────────── الشكل ─────────────────────────── */

    /** ‏‏صندوقٌ بنسخةٍ أقدم يسأل GET بلا شيء — فيأخذ القائمةَ كما كانت، صفًّا صفًّا */
    public function test_an_older_till_still_gets_every_row_as_before(): void
    {
        $plain = $this->shelf[0];
        $plain->update(['tracks_stock' => false]);
        $this->shelf[1]->update(['quantity' => 3]);
        $this->shelf[2]->update(['quantity' => 0]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->muscat->id, 'product_id' => $this->shelf[3]->id, 'quantity' => 4]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->salalah->id, 'product_id' => $this->shelf[4]->id, 'quantity' => 9]);
        $this->shelf[5]->delete();

        $feed = $this->getJson(route('pos.stock-feed'))->assertOk();

        // ‏‏كما كانت تُبنى بالنموذج: رصيدُ الفرع وحالتُه، والمحذوفُ غائب
        $available = Stock::availabilityResolver($this->business->id, $this->muscat->id);
        $expected = Product::where('business_id', $this->business->id)->orderBy('id')->get()
            ->map(function ($p) use ($available) {
                $qty = $available($p->id, (int) $p->quantity);

                return ['id' => $p->id, 'qty' => $qty, 'stock_status' => $p->stockStatusAt($qty)];
            })->all();
        $this->assertSame($expected, $feed->json('products'));
        $this->assertSame(['id', 'qty', 'stock_status'], array_keys($feed->json('products.0')));
        $this->assertSame(Product::UNTRACKED, $feed->json('products.0.stock_status'));
        $this->assertSame(0, collect($feed->json('products'))->firstWhere('id', $this->shelf[4]->id)['qty'], 'رصيدُ صلالة عُدّ لمسقط');
    }

    public function test_nothing_changed_is_answered_with_nothing(): void
    {
        $first = $this->ask()->assertOk();
        $this->assertTrue($first->json('full'));
        $this->assertCount(100, $first->json('products'));
        $this->assertSame(4, $first->json('b'));
        $known = $this->apply(null, $first->json());

        $again = $this->ask($known)->assertOk();

        $this->assertTrue($again->json('same'));
        $this->assertNull($again->json('products'), 'أُرسلت أصنافٌ لم يتغيّر منها شيء');
    }

    /* ─────────────────── كلُّ بابٍ يكتب الكميّة يظهر ─────────────────── */

    /** ‏‏بيعٌ من الصندوق: يأتي صنفُه وسلّتُه وحدها — لا القائمة */
    public function test_a_sale_sends_only_the_bucket_of_what_was_sold(): void
    {
        $known = $this->apply(null, $this->ask()->json());
        $sold = $this->shelf[10];

        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $sold->id, 'name' => $sold->name, 'qty' => 2]], 'payment_method' => 'نقدي',
        ])->assertOk();

        $d = $this->ask($known)->assertOk()->json();
        $this->assertSame([$sold->id % 4], array_column($d['buckets'], 0));
        $this->assertLessThanOrEqual(30, count($d['products']), 'أُرسلت أكثرُ من سلّة');
        $this->assertSame(59, collect($d['products'])->firstWhere('id', $sold->id)['qty']);
        $this->assertTillHasTheTruth($this->apply($known, $d), 'بيع');
    }

    /**
     * ‏‏وما يكتب الكميّةَ بلا `updated_at` — ورصيدُ الفرع في جدوله — والحالةُ وحدها.
     *
     * كلُّ بابٍ يكتب الكميّة ينتهي إلى أحد العمودين: `products.quantity` أو
     * `branch_stocks.quantity` (البيعُ والإرجاعُ والإلغاءُ والشراءُ والتسويةُ
     * والتحويلُ والجرد). وهنا تُكتب بأفقر طريق — `DB::table` لا يمسّ وقتًا — فإن
     * ظهرت هنا ظهرت من كلّ باب.
     */
    public function test_every_way_the_stock_is_written_reaches_the_till(): void
    {
        $known = $this->apply(null, $this->ask()->json());
        $p = $this->shelf;
        $stamp = DB::table('products')->where('id', $p[20]->id)->value('updated_at');

        $steps = [
            'كميّةٌ بلا updated_at' => fn () => DB::table('products')->where('id', $p[20]->id)->update(['quantity' => 7]),
            'رصيدُ فرعٍ يُوزَّع' => fn () => DB::table('branch_stocks')->insert(['business_id' => $this->business->id, 'branch_id' => $this->muscat->id, 'product_id' => $p[21]->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]),
            'رصيدُ فرعٍ يتغيّر' => fn () => DB::table('branch_stocks')->where('product_id', $p[21]->id)->update(['quantity' => 0]),
            'رصيدُ الفرع الآخر وحده' => fn () => DB::table('branch_stocks')->insert(['business_id' => $this->business->id, 'branch_id' => $this->salalah->id, 'product_id' => $p[22]->id, 'quantity' => 40, 'created_at' => now(), 'updated_at' => now()]),
            'حدُّ التنبيه (الحالةُ وحدها)' => fn () => DB::table('products')->where('id', $p[23]->id)->update(['alert_qty' => 500]),
            'صنفٌ لا يُعدّ' => fn () => DB::table('products')->where('id', $p[24]->id)->update(['tracks_stock' => false]),
            'صنفٌ يُحذف' => fn () => $p[25]->delete(),
            'صنفٌ جديد' => fn () => Product::create(['business_id' => $this->business->id, 'name' => 'جديد', 'price' => 1, 'quantity' => 3, 'active' => true]),
            'صنفٌ يُستعاد' => fn () => Product::withTrashed()->find($p[25]->id)->restore(),
            'كميّاتٌ كثيرة معًا' => fn () => DB::table('products')->where('business_id', $this->business->id)->where('id', '>', $p[60]->id)->update(['quantity' => DB::raw('quantity + 1')]),
        ];

        foreach ($steps as $what => $step) {
            $step();
            $d = $this->ask($known)->assertOk()->json();
            $this->assertFalse($d['same'] ?? false, "لم يُرَ «{$what}»");
            $known = $this->apply($known, $d);
            $this->assertTillHasTheTruth($known, $what);
        }

        $this->assertSame($stamp, DB::table('products')->where('id', $p[20]->id)->value('updated_at'), 'الاختبارُ نفسُه مسّ الوقت');
        $this->assertSame(0, $known['rows'][$p[22]->id]['qty'], 'رصيدُ صلالة ظهر في مسقط');
    }

    /** ‏‏ومن أيّ أساسٍ سأل — صندوقٌ غاب ثلاثَ دورات — يصل إلى ما عند الخادم */
    public function test_a_till_that_missed_several_answers_still_lands_on_the_truth(): void
    {
        $old = $this->apply(null, $this->ask()->json());
        foreach ([3, 40, 77] as $k) {
            DB::table('products')->where('id', $this->shelf[$k]->id)->update(['quantity' => 1]);
            $this->ask($old); // ‏‏صندوقٌ آخر يسأل — لا يغيّر ما عند هذا
        }

        $this->assertTillHasTheTruth($this->apply($old, $this->ask($old)->json()), 'ثلاثة تغييرات فاتته');
    }

    /* ───────────────── ما لا يُعرف أساسُه: القائمةُ كاملة ───────────────── */

    public function test_marks_it_cannot_read_are_answered_with_the_whole_list(): void
    {
        $known = $this->apply(null, $this->ask()->json());
        DB::table('products')->where('id', $this->shelf[0]->id)->update(['quantity' => 2]);
        $h = implode('', $known['h']);

        foreach ([
            'بصماتٌ ناقصة' => ['v' => $known['v'], 'b' => 4, 'h' => substr($h, 1)],
            'بصماتٌ بغير الحروف' => ['v' => $known['v'], 'b' => 4, 'h' => str_repeat('z', strlen($h))],
            'عددُ سلالٍ آخر' => ['v' => $known['v'], 'b' => 8, 'h' => $h.$h],
            'بلا بصمة كاملة' => ['b' => 4, 'h' => $h],
            'بصماتٌ ليست ممّا أُعطي' => ['v' => str_repeat('0', 32), 'b' => 4, 'h' => str_repeat('0', strlen($h))],
        ] as $what => $body) {
            $d = $this->postJson(route('pos.stock-feed'), $body + ['scope' => $this->scope()])->assertOk()->json();
            if ($what === 'بصماتٌ ليست ممّا أُعطي') {
                // ‏‏كلُّ سلّةٍ تختلف — فتأتي كلُّها، والقائمةُ هي هي
                $this->assertCount(100, $d['products'], $what);

                continue;
            }
            $this->assertTrue($d['full'] ?? false, "«{$what}» لم يُجب بالقائمة كاملة");
            $this->assertCount(100, $d['products'], $what);
        }
    }

    /* ─────────────────────────── العزل ─────────────────────────── */

    /** ‏‏ما يرسله الصندوقُ لا يقرأ متجرًا آخر: أصنافُ الجار لا تظهر في كاملٍ ولا في سلّة */
    public function test_another_shops_stock_is_never_sent(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $secret = Product::create(['business_id' => $theirs->id, 'name' => 'سرّي', 'price' => 1, 'quantity' => 999, 'active' => true]);
        $known = $this->apply(null, $this->ask()->json());
        DB::table('products')->where('id', $secret->id)->update(['quantity' => 5]);
        DB::table('products')->where('id', $this->shelf[0]->id)->update(['quantity' => 5]);

        $d = $this->ask($known)->json();

        $this->assertNotContains($secret->id, array_column($d['products'], 'id'));
        $this->assertNotContains($secret->id, array_keys($known['rows']));
    }

    /**
     * ‏‏صندوقٌ فُتح لمتجرٍ أو فرعٍ غيرِ ما في الجلسة الآن (بُدّل في تبويبٍ آخر):
     * يُقال له 409 ولا يُعطى كميّات — لا كميّاتِ ما في الجلسة لشاشةٍ تعرض غيره.
     */
    public function test_a_till_opened_for_another_shop_or_branch_is_told_and_given_nothing(): void
    {
        foreach ([
            'متجرٌ آخر' => TillStock::scope($this->business->id + 1, $this->muscat->id),
            'فرعٌ آخر' => TillStock::scope($this->business->id, $this->salalah->id),
        ] as $what => $scope) {
            $r = $this->ask(null, $scope)->assertStatus(409);
            $this->assertTrue($r->json('scope_changed'), $what);
            $this->assertNull($r->json('products'), "«{$what}» أُعطي كميّات");
        }
    }

    /** ‏‏وفرعُ الجهاز هو ما يُقرأ: صندوقُ صلالة يأخذ رصيدَ صلالة */
    public function test_each_branch_till_reads_its_own_branch(): void
    {
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->muscat->id, 'product_id' => $this->shelf[0]->id, 'quantity' => 10]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->salalah->id, 'product_id' => $this->shelf[0]->id, 'quantity' => 1]);

        $this->activatePosDevice($this->business->id, $this->salalah->id);
        session(['current_branch' => $this->salalah->id]);
        $r = $this->ask(null, TillStock::scope($this->business->id, $this->salalah->id))->assertOk();

        $this->assertSame(1, collect($r->json('products'))->firstWhere('id', $this->shelf[0]->id)['qty']);
        $this->assertSame(TillStock::scope($this->business->id, $this->salalah->id), $r->json('scope'));
    }

    public function test_a_guest_is_not_answered(): void
    {
        auth()->logout();
        $this->postJson(route('pos.stock-feed'), ['scope' => $this->scope()])->assertUnauthorized();
    }

    /* ─────────────────────────── الحجم ─────────────────────────── */

    public function test_the_buckets_grow_with_the_shelf_and_stop_at_their_ceiling(): void
    {
        $this->assertSame(1, TillStock::bucketsFor(0));
        $this->assertSame(1, TillStock::bucketsFor(32));
        $this->assertSame(4, TillStock::bucketsFor(100));
        $this->assertSame(32, TillStock::bucketsFor(1000));
        $this->assertSame(64, TillStock::bucketsFor(2000));
        $this->assertSame(256, TillStock::bucketsFor(5000));
        $this->assertSame(TillStock::MAX_BUCKETS, TillStock::bucketsFor(10000));
        $this->assertSame(TillStock::MAX_BUCKETS, TillStock::bucketsFor(1_000_000));
    }
}
