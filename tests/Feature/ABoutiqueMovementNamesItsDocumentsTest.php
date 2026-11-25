<?php

namespace Tests\Feature;

use App\Exceptions\SettlementRefused;
use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueReturnRecovery;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Bank;
use App\Support\BoutiqueDues;
use App\Support\BoutiqueMovements;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * حركاتُ البوتيك: كلُّ ما غيّر ما بينه وبين المتجر يُقرأ بمستنده وقيده وحركته الماليّة.
 *
 * كان سدادُ قطع ورقةٍ، واستردادُ رصيدٍ من بوتيك، وتحصيلُ مطالبة مرتجع — قيدًا في الدفتر بلا صفٍّ
 * في «الحركات». فصار لكلٍّ صفُّه مرّةً، مرتبطًا بقيده، وبلا قيدٍ ثانٍ. والسجلُّ يُقرأ من
 * المستندات، ومجموعُ آثاره ما على المتجر للبوتيك بالبيسة.
 *
 * والأرقام: لمى ٢٠٪ وريم ٣٣٫٣٣٪. قطعةُ لمى ١٢، وقطعةُ ريم ٧٫٣٣٥.
 */
class ABoutiqueMovementNamesItsDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $hers;

    private Product $reems;

    private Product $bundle;

    private Product $rose;

    private Addon $addon;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create(['name' => 'متجر الحركات', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'mov@test.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true, 'returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'ع 1']);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'ريم', 'commission_rate' => 33.33, 'active' => true]);
        $this->hers = $this->product('خاتم', 12, $this->lama);
        $this->reems = $this->product('سوار', 7.335, $this->reem);
        $this->bundle = $this->product('باقة', 60);
        $this->rose = $this->product('ورد', 9.5);
        foreach ([[$this->hers, 1], [$this->reems, 2]] as [$p, $n]) {
            RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->bundle->id, 'component_product_id' => $p->id, 'quantity' => $n, 'wastage_percent' => 0]);
        }
        $this->addon = Addon::create(['business_id' => $this->shop->id, 'name' => 'إضافة', 'price' => 8, 'active' => true, 'scope' => Addon::SCOPE_ALL, 'inventory_product_id' => $this->reems->id, 'inventory_quantity' => 1]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?Boutique $of = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => $of ? 0 : 0.7, 'quantity' => 100000, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $of?->id]);
    }

    private function sell(array $items, string $method = 'نقدي'): Order
    {
        $this->postJson('/pos/checkout', [
            'items' => array_map(fn (array $i) => ['id' => $i[0]->id, 'name' => $i[0]->name, 'qty' => $i[1], 'price' => (float) $i[0]->price] + ($i[2] ?? []), $items),
            'payment_method' => $method, 'client_uuid' => uniqid('m', true),
        ])->assertOk();

        return Order::latest('id')->first();
    }

    private function paySheet(BoutiqueSettlement $s, string $method = 'نقدي'): void
    {
        $this->post("/admin/expenses/{$s->expense_id}/paid", ['method' => $method])->assertSessionHasNoErrors();
    }

    private function rows(Boutique $b, string $period = '2027-02', ?string $group = null): array
    {
        return $this->getJson(route('admin.boutiques.movements', ['id' => $b->id, 'period' => $period, 'group' => $group]))->assertOk()->json('rows');
    }

    private function ledger(string $key): float
    {
        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.business_id', $this->shop->id)->where('a.system_key', $key)->selectRaw('coalesce(sum(l.credit - l.debit),0) as v')->value('v'), 3) + 0.0;
    }

    /** الأثرُ كلُّه = ما على المتجر للبوتيك، وشهرٌ ينتهي حيث يبدأ الذي بعده */
    private function assertTies(string $why): void
    {
        foreach ([$this->lama, $this->reem] as $b) {
            $balance = BoutiqueMovements::balance($this->shop->id, $b->id);
            $this->assertSame(number_format($balance['net'], 3), number_format(BoutiqueMovements::effects($this->shop->id, $b->id), 3), "$why: {$b->name}");

            $closing = null;
            foreach (['2027-02', '2027-03', '2027-04', '2027-05'] as $period) {
                $page = BoutiqueMovements::page($this->shop->id, $b->id, ['period' => $period, 'group' => null, 'page' => 1]);
                if ($closing !== null) {
                    $this->assertSame(number_format($closing, 3), number_format($page['opening'], 3), "$why: {$b->name} $period");
                }
                $closing = $page['closing'];
            }
            $this->assertSame(number_format($balance['net'], 3), number_format($closing, 3), "$why: {$b->name}");
        }
    }

    /* ═══════════ ما كان بلا صفّ ═══════════ */

    public function test_paying_the_pieces_of_a_sheet_is_one_money_movement_tied_to_its_entry(): void
    {
        $this->sell([[$this->bundle, 1]]);
        $sheet = Boutiques::settle($this->shop, $this->reem, '2027-02');
        $before = Transaction::where('business_id', $this->shop->id)->count();
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();

        BoutiqueDues::pay($sheet, 'تحويل');
        BoutiqueDues::pay($sheet->fresh(), 'نقدي');

        $this->assertSame($before + 1, Transaction::where('business_id', $this->shop->id)->count());
        $this->assertSame($entries + 1, JournalEntry::where('business_id', $this->shop->id)->count(), 'قيدٌ واحد لا اثنان');
        $row = Transaction::where('kind', Transaction::BOUTIQUE_PIECES)->sole();
        $this->assertSame('14.670', number_format((float) $row->amount, 3));
        $this->assertSame('مصروف', $row->type);
        // باسمها الذي تقرؤه مطابقةُ البنك
        $this->assertSame('تحويل بنكي', $row->method);
        $this->assertTrue(Bank::transactions($this->shop->id)->whereKey($row->id)->exists());
        $this->assertSame((int) $row->id, (int) $sheet->fresh()->transaction_id);
        $this->assertSame(BoutiqueSettlement::class, $row->journalEntry->sourceable_type);
        $this->assertStringContainsString($sheet->number, $row->description);
        $this->assertSame('-14.670', number_format((float) collect($this->rows($this->reem))->firstWhere('kind', 'pieces_paid')['effect'], 3));
        $this->assertTies('سداد القطع');
    }

    public function test_pieces_wholly_offset_by_a_credit_move_no_money(): void
    {
        $order = $this->sell([[$this->bundle, 1]]);
        BoutiqueDues::pay(Boutiques::settle($this->shop, $this->reem, '2027-02'), 'نقدي');
        DB::transaction(fn () => OrderCorrection::cancel($order, 'أُلغي', true));
        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->sell([[$this->bundle, 1]]);
        $sheet = Boutiques::settle($this->shop, $this->reem, '2027-03');
        $this->assertSame('0.000', number_format((float) $sheet->components_net, 3));
        $before = Transaction::where('business_id', $this->shop->id)->count();

        BoutiqueDues::pay($sheet, 'نقدي');

        $this->assertNotNull($sheet->fresh()->components_paid_at);
        $this->assertSame($before, Transaction::where('business_id', $this->shop->id)->count());
        $this->assertNull($sheet->fresh()->transaction_id);
        $this->assertContains('offset', array_column($this->rows($this->reem, '2027-03'), 'kind'));
        $this->assertTies('مقاصّة كاملة');
    }

    public function test_a_credit_refunded_by_the_boutique_is_money_that_came_in(): void
    {
        $order = $this->sell([[$this->bundle, 1]]);
        BoutiqueDues::pay(Boutiques::settle($this->shop, $this->reem, '2027-02'), 'نقدي');
        DB::transaction(fn () => OrderCorrection::cancel($order, 'أُلغي', true));

        $doc = BoutiqueDues::refund($this->shop, $this->reem, 'نقدي', amount: 5.0);

        $row = Transaction::where('kind', Transaction::BOUTIQUE_REFUND)->sole();
        $this->assertSame(['دخل', '5.000'], [$row->type, number_format((float) $row->amount, 3)]);
        $this->assertSame((int) $row->id, (int) $doc->fresh()->transaction_id);
        $this->assertSame((int) $doc->id, (int) $row->journalEntry->sourceable_id);
        $seen = collect($this->rows($this->reem))->firstWhere('kind', 'refund');
        $this->assertSame([5.0, $doc->number, $row->reference], [(float) $seen['effect'], $seen['sheet'], $seen['transaction']]);
        $this->assertTies('استرداد');
    }

    public function test_one_receipt_over_two_claims_is_one_money_movement_and_one_entry(): void
    {
        $orders = [$this->sell([[$this->hers, 1]]), $this->sell([[$this->hers, 2]])];
        $this->paySheet(Boutiques::settle($this->shop, $this->lama, '2027-02'));
        foreach ($orders as $o) {
            $line = BoutiqueReturns::settledLines($o)->first();
            DB::transaction(fn () => OrderCorrection::cancel($o, 'أُلغي', null, [$line->id => ['sellable' => (int) $line->quantity, 'damaged' => 0]]));
        }
        $this->assertSame('28.800', number_format((float) BoutiqueReturns::claims($this->shop->id, $this->lama->id)->sum('amount'), 3));
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();

        BoutiqueReturns::receipt($this->shop, $this->lama, 20.0, 'تحويل', 'حوالة 88');

        $row = Transaction::where('kind', Transaction::BOUTIQUE_CLAIM)->sole();
        $this->assertSame(['دخل', '20.000', 'تحويل بنكي'], [$row->type, number_format((float) $row->amount, 3), $row->method]);
        $this->assertSame($entries + 1, JournalEntry::where('business_id', $this->shop->id)->count());
        $lines = $row->journalEntry->lines()->with('account')->get();
        $this->assertSame(['20.000'], $lines->where('debit', '>', 0)->map(fn ($l) => number_format((float) $l->debit, 3))->values()->all());
        $this->assertSame(['9.600', '10.400'], $lines->where('credit', '>', 0)->map(fn ($l) => number_format((float) $l->credit, 3))->values()->all());
        $this->assertSame([(int) $row->id, (int) $row->id], BoutiqueReturnRecovery::orderBy('id')->pluck('transaction_id')->map(fn ($v) => (int) $v)->all());
        // ما بقي من المطالبتين ٨٫٨ — وهو ما في 1370
        $this->assertSame('-8.800', number_format($this->ledger('boutique_receivable'), 3));
        $this->assertTies('استلام مطالبتين');
    }

    public function test_a_receipt_that_is_refused_writes_nothing(): void
    {
        $before = [Transaction::count(), JournalEntry::count(), BoutiqueReturnRecovery::count()];

        $this->post(route('admin.boutiques.returns.receipt', $this->lama->id), ['amount' => 5, 'method' => 'نقدي', 'reference' => 'x'])->assertSessionHasErrors('returns');

        $this->assertSame($before, [Transaction::count(), JournalEntry::count(), BoutiqueReturnRecovery::count()]);
    }

    public function test_a_sheet_payment_is_named_and_tied_to_its_entry(): void
    {
        $this->sell([[$this->hers, 1]]);
        $sheet = Boutiques::settle($this->shop, $this->lama, '2027-02');
        $this->paySheet($sheet);

        $row = Transaction::where('kind', Transaction::BOUTIQUE_SHEET)->sole();
        $this->assertSame('9.600', number_format((float) $row->amount, 3));
        $this->assertSame(Expense::class, $row->journalEntry->sourceable_type);
        $this->assertSame((int) $sheet->expense_id, (int) $row->journalEntry->sourceable_id);

        // ومصروفٌ عاديّ كما كان: بلا نوعٍ ولا قيدٍ على صفّه
        $e = Expense::create(['business_id' => $this->shop->id, 'reference' => 'EXP-9', 'type' => 'إيجار', 'amount' => 3, 'status' => Expense::UNPAID, 'spent_at' => now()]);
        $this->post("/admin/expenses/{$e->id}/paid", ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $plain = Transaction::find($e->fresh()->transaction_id);
        $this->assertNull($plain->kind);
        $this->assertNull($plain->journal_entry_id);
    }

    public function test_the_money_movements_are_what_the_ledger_moved_for_the_boutiques(): void
    {
        $this->storm(7, 90);

        $cash = round((float) Transaction::whereIn('kind', Transaction::BOUTIQUE_KINDS)->get()->sum(fn ($t) => $t->type === 'دخل' ? (float) $t->amount : -(float) $t->amount), 3);
        $moved = round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.business_id', $this->shop->id)->whereIn('a.system_key', ['cash', 'bank'])
            ->where(fn ($q) => $q->whereIn('e.source', [BoutiqueDues::SOURCE, BoutiqueReturns::SOURCE])
                ->orWhere(fn ($w) => $w->where('e.sourceable_type', Expense::class)->whereIn('e.sourceable_id', BoutiqueSettlement::whereNotNull('expense_id')->pluck('expense_id'))))
            ->selectRaw('coalesce(sum(l.debit - l.credit),0) as v')->value('v'), 3);

        $this->assertNotSame(0.0, $cash + 0.0);
        $this->assertSame(number_format($moved, 3), number_format($cash, 3));
        foreach (Transaction::whereIn('kind', Transaction::BOUTIQUE_KINDS)->get() as $t) {
            $this->assertNotNull($t->journal_entry_id, $t->description);
        }
    }

    /* ═══════════ السجلّ ═══════════ */

    public function test_a_line_removed_before_its_sheet_leaves_what_it_was(): void
    {
        $order = $this->sell([[$this->hers, 3], [$this->rose, 1]]);
        $item = OrderItem::where('order_id', $order->id)->where('boutique_id', $this->lama->id)->first();
        OrderCorrection::setQuantity($order->fresh(), $item, 1, 'نقص', 'refunded', 'نقدي');
        $this->assertTies('إنقاص');
        OrderCorrection::setQuantity($order->fresh(), $item->fresh(), 0, 'حذف', 'refunded', 'نقدي');

        $this->assertSame([-19.2, -9.6], OrderEdit::whereNotNull('boutique_share')->orderBy('id')->pluck('boutique_share')->map(fn ($v) => (float) $v)->all());
        $rows = collect($this->rows($this->lama));
        $this->assertSame(28.8, (float) $rows->firstWhere('kind', 'recorded')['effect']);
        $this->assertSame([-9.6, -19.2], $rows->where('kind', 'changed')->pluck('effect')->map(fn ($v) => (float) $v)->values()->all());
        $this->assertSame(['حذف', 'نقص'], $rows->where('kind', 'changed')->pluck('why')->values()->all());
        $this->assertTies('حذف');
    }

    public function test_a_decrease_after_the_sheet_is_a_note_and_not_a_correction_of_what_was_recorded(): void
    {
        $order = $this->sell([[$this->hers, 2], [$this->rose, 1]]);
        Boutiques::settle($this->shop, $this->lama, '2027-02');
        $item = OrderItem::where('order_id', $order->id)->where('boutique_id', $this->lama->id)->first();
        OrderCorrection::setQuantity($order->fresh(), $item, 1, 'نقص', 'refunded', 'نقدي');

        $this->assertSame(0, OrderEdit::whereNotNull('boutique_share')->count());
        $rows = collect($this->rows($this->lama));
        $this->assertSame(19.2, (float) $rows->firstWhere('kind', 'recorded')['effect']);
        $this->assertSame(0.0, (float) $rows->firstWhere('kind', 'sheet')['effect'], 'الورقةُ كما صدرت: لا فرق');
        $this->assertSame(['awaiting', 0.0], [$rows->firstWhere('kind', 'note')['state'], (float) $rows->firstWhere('kind', 'note')['effect']]);

        BoutiqueReturns::receive(BoutiqueReturn::sole(), 1, 0, 'عادت');
        $decided = collect($this->rows($this->lama))->firstWhere('kind', 'decided');
        $this->assertSame(['reduced', -9.6], [$decided['state'], (float) $decided['effect']]);
        $this->assertNotNull($decided['entry']);
        $this->assertTies('مرتجع بعد الورقة');

        // وبندٌ حُذف كلُّه بعد ورقته: ما حملته باقٍ في السجلّ
        OrderCorrection::setQuantity($order->fresh(), $item->fresh(), 0, 'حذف', 'refunded', 'نقدي');
        $this->assertSame(19.2, round((float) collect($this->rows($this->lama))->where('kind', 'recorded')->sum('effect'), 3));
        $this->assertSame(0.0, (float) collect($this->rows($this->lama))->firstWhere('kind', 'sheet')['effect']);
        $this->assertTies('حذف بعد الورقة');
    }

    public function test_every_movement_points_to_its_document_and_entry(): void
    {
        $this->sell([[$this->hers, 1]]);
        $bundled = $this->sell([[$this->bundle, 1]]);
        $cancelled = $this->sell([[$this->hers, 1]]);
        DB::transaction(fn () => OrderCorrection::cancel($cancelled, 'خطأ', true));
        $sheet = Boutiques::settle($this->shop, $this->lama, '2027-02');
        $this->paySheet($sheet);
        BoutiqueDues::pay($sheet->fresh(), 'نقدي');

        $rows = collect($this->rows($this->lama));
        $byKind = fn (string $k) => $rows->firstWhere('kind', $k);

        $this->assertSame([12.0, 12.0, $bundled->number], [(float) $byKind('piece')['effect'], (float) $byKind('piece')['amount'], $byKind('piece')['order']]);
        $this->assertSame(JournalEntry::where('source', 'تكلفة مبيعات')->where('sourceable_id', $bundled->id)->value('number'), $byKind('piece')['entry']);
        $this->assertSame([-9.6, $cancelled->number], [(float) $byKind('cancelled')['effect'], $byKind('cancelled')['order']]);
        $this->assertSame(JournalEntry::where('source', Boutiques::ACCRUAL)->value('number'), $byKind('sheet')['entry']);
        $this->assertSame([21.6, 0.0], [(float) $byKind('sheet')['amount'], (float) $byKind('sheet')['effect']]);
        $this->assertSame([-9.6, $sheet->expense->reference], [(float) $byKind('paid')['effect'], $byKind('paid')['expense']]);
        $this->assertSame(JournalEntry::where('sourceable_type', Expense::class)->value('number'), $byKind('paid')['entry']);
        $this->assertSame(Transaction::where('kind', Transaction::BOUTIQUE_PIECES)->value('reference'), $byKind('pieces_paid')['transaction']);
        $this->assertSame(0.0, (float) $rows->first()['balance'], 'آخرُ رصيد: لم يبق شيء');
        $this->assertTies('كلّ الأنواع');
    }

    public function test_a_piece_points_to_the_entry_that_stands_and_not_to_one_that_was_reversed(): void
    {
        $order = $this->sell([[$this->bundle, 2]]);
        $item = OrderItem::where('order_id', $order->id)->first();
        OrderCorrection::setQuantity($order->fresh(), $item, 1, 'نقص', 'refunded', 'نقدي');

        $live = JournalEntry::where('source', 'تكلفة مبيعات')->where('sourceable_id', $order->id)->whereNull('reversed_at')->whereNull('reverses_id')->sole();
        $this->assertSame([$live->number], collect($this->rows($this->lama))->pluck('entry')->unique()->values()->all());
        $this->assertTies('تصحيح باقة');
    }

    public function test_a_return_the_shop_bears_is_said_and_changes_nothing(): void
    {
        $order = $this->sell([[$this->reems, 1]]);
        Boutiques::settle($this->shop, $this->reem, '2027-02');
        $line = BoutiqueReturns::settledLines($order)->first();
        DB::transaction(fn () => OrderCorrection::cancel($order, 'أُلغي', null, [$line->id => ['sellable' => 1, 'damaged' => 0]]));
        BoutiqueReturns::review(BoutiqueReturn::sole(), 'shop', 'تلف عندنا');

        $row = collect($this->rows($this->reem))->firstWhere('kind', 'decided');
        $this->assertSame(['shop', 0.0, 'تلف عندنا'], [$row['state'], (float) $row['effect'], $row['why']]);
        $this->assertTies('على المتجر');
    }

    public function test_the_groups_filter_without_changing_the_running_balance(): void
    {
        $this->sell([[$this->hers, 1]]);
        $sheet = Boutiques::settle($this->shop, $this->lama, '2027-02');
        $this->paySheet($sheet);

        $page = $this->getJson(route('admin.boutiques.movements', ['id' => $this->lama->id, 'period' => '2027-02', 'group' => 'payments']))->assertOk()->json();
        $this->assertSame(['paid'], array_column($page['rows'], 'kind'));
        $this->assertSame(0.0, (float) $page['rows'][0]['balance']);
        $this->assertSame(1, $page['groups']['dues']['count']);
        $this->assertSame(9.6, (float) $page['groups']['dues']['effect']);

        // ومُرشِّحٌ لا يُعرف يُقرأ «الكلّ»
        $this->assertCount(3, $this->getJson(route('admin.boutiques.movements', ['id' => $this->lama->id, 'period' => '2027-02', 'group' => 'x']))->json('rows'));
    }

    public function test_a_month_is_paged_and_its_balance_runs_across_the_pages(): void
    {
        foreach (range(1, 30) as $_) {
            $this->sell([[$this->hers, 1]]);
        }

        $first = $this->getJson(route('admin.boutiques.movements', ['id' => $this->lama->id, 'period' => '2027-02']))->json();
        $second = $this->getJson(route('admin.boutiques.movements', ['id' => $this->lama->id, 'period' => '2027-02', 'page' => 2]))->json();

        $this->assertSame([30, 2, 25, 5], [$first['total'], $first['last_page'], count($first['rows']), count($second['rows'])]);
        $this->assertSame(288.0, (float) $first['rows'][0]['balance']);
        $this->assertSame(9.6, (float) end($second['rows'])['balance']);
        $this->assertSame(288.0, (float) $first['closing']);
    }

    public function test_a_sheet_rounded_as_a_group_says_its_difference_from_its_lines(): void
    {
        // ثلاثُ فواتير: كلُّ بندٍ ٧٫٣٣٥ بـ٣٣٫٣٣٪ — الورقةُ تقرّب مجموعَها والبنودُ تُقرَّب واحدًا واحدًا
        foreach (range(1, 3) as $_) {
            $this->sell([[$this->reems, 1]]);
        }
        $sheet = Boutiques::settle($this->shop, $this->reem, '2027-02');

        $rows = collect($this->rows($this->reem));
        $lines = round((float) $rows->where('kind', 'recorded')->sum('effect'), 3);
        $this->assertSame(number_format((float) $sheet->net - $lines, 3), number_format((float) $rows->firstWhere('kind', 'sheet')['effect'], 3));
        $this->assertSame(number_format((float) $sheet->net, 3), number_format((float) $rows->first()['balance'], 3));
        $this->assertTies('فرق التقريب');
    }

    /** ثلاثةُ متاجرَ من العمليّات العشوائيّة: بعد كلّ دفعةٍ الأثرُ كلُّه ما على المتجر */
    public function test_random_months_always_tie_to_what_is_owed(): void
    {
        foreach ([11, 12] as $seed) {
            $this->storm($seed, 70, true);
        }
    }

    private function storm(int $seed, int $steps, bool $check = false): void
    {
        mt_srand($seed);
        $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $at = Carbon::parse('2027-02-03 09:00:00')->addMonths($seed % 2);
        $live = fn () => Order::where('business_id', $this->shop->id)->where('is_held', false)->where('status', '!=', Order::CANCELLED)->orderBy('id')->get();

        for ($i = 0; $i < $steps; $i++) {
            $at = $at->copy()->addHours(mt_rand(3, 30));
            Carbon::setTestNow($at);
            match (mt_rand(0, 11)) {
                0, 1, 2, 3 => $this->sell([[$pick([$this->hers, $this->reems, $this->bundle]), mt_rand(1, 3)], [$this->rose, 1, mt_rand(0, 1) ? ['addons' => [['addon_id' => $this->addon->id, 'qty' => 1]]] : []]]),
                4 => $this->quietly(function () use ($live) {
                    $o = $live()->random();
                    $answers = BoutiqueReturns::settledLines($o)->mapWithKeys(fn ($l) => [(int) $l->id => ['sellable' => mt_rand(0, (int) $l->quantity), 'damaged' => 0]])->all();
                    DB::transaction(fn () => OrderCorrection::cancel($o, 'أُلغي', [true, false, null][mt_rand(0, 2)], $answers ?: null));
                }),
                5 => $this->quietly(function () {
                    $it = OrderItem::whereNotNull('boutique_id')->where('quantity', '>', 1)->whereIn('order_id', Order::whereDate('ordered_at', now())->select('id'))->orderBy('id')->get()->random();
                    OrderCorrection::setQuantity($it->order->fresh(), $it, (int) $it->quantity - 1, 'نقص', 'refunded', 'نقدي');
                }),
                6, 7 => $this->quietly(fn () => Boutiques::settle($this->shop->fresh(), $pick([$this->lama, $this->reem]), $at->copy()->subDays(mt_rand(0, 20))->format('Y-m'))),
                8 => $this->quietly(fn () => ($s = BoutiqueSettlement::whereNotNull('expense_id')->orderBy('id')->get()->random()) ? $this->post("/admin/expenses/{$s->expense_id}/paid", ['method' => $pick(['نقدي', 'تحويل بنكي'])]) : null),
                9 => $this->quietly(fn () => BoutiqueDues::pay(BoutiqueSettlement::where('components_due', '>', 0)->whereNull('components_paid_at')->orderBy('id')->get()->random(), 'نقدي')),
                10 => $this->quietly(fn () => mt_rand(0, 1)
                    ? BoutiqueReturns::receive(BoutiqueReturn::where('status', BoutiqueReturn::AWAITING)->orderBy('id')->get()->random(), 1, 0, 'عادت')
                    : BoutiqueReturns::review(BoutiqueReturn::where('status', BoutiqueReturn::REVIEW)->orderBy('id')->get()->random(), $pick(['boutique', 'shop']), 'قرار')),
                default => $this->quietly(function () use ($pick) {
                    $b = $pick([$this->lama, $this->reem]);
                    match (mt_rand(0, 2)) {
                        0 => BoutiqueDues::refund($this->shop, $b, 'نقدي', amount: 1.5),
                        1 => BoutiqueReturns::receipt($this->shop, $b, 2.0, 'نقدي', 'إيصال'),
                        default => BoutiqueReturns::offset($this->shop, $b, BoutiqueSettlement::where('boutique_id', $b->id)->whereNotNull('expense_id')->latest('id')->firstOr(fn () => throw new \RuntimeException('لا ورقة'))),
                    };
                }),
            };
            if ($check && $i % 5 === 0) {
                $this->assertTies("بذرة $seed خطوة $i");
            }
        }
    }

    /** ما يردّه النظام (لا شيء يُسوّى، لا رصيد) ليس عطبًا هنا */
    private function quietly(\Closure $do): void
    {
        try {
            $do();
        } catch (SettlementRefused|\RuntimeException|\InvalidArgumentException|ValidationException $e) {
            session()->forget('errors');
        }
    }

    /* ═══════════ من يقرؤه ═══════════ */

    public function test_the_register_is_for_who_reads_the_boutiques_money_in_its_own_shop(): void
    {
        $url = route('admin.boutiques.movements', ['id' => $this->lama->id, 'period' => '2027-02']);
        $this->sell([[$this->hers, 1]]);

        foreach ([['products'], ['products', 'orders', 'reports', 'inventory']] as $n => $permissions) {
            $u = User::create(['business_id' => $this->shop->id, 'name' => "م$n", 'email' => "m$n@test.om", 'password' => bcrypt('x'), 'role' => 'custom', 'status' => 'نشط', 'permissions' => $permissions]);
            $this->actingAs($u)->getJson($url)->assertForbidden();
        }
        $reader = User::create(['business_id' => $this->shop->id, 'name' => 'قارئ', 'email' => 'r@test.om', 'password' => bcrypt('x'), 'role' => 'custom', 'status' => 'نشط', 'permissions' => ['products', 'expenses']]);
        $row = $this->actingAs($reader)->getJson($url)->assertOk()->json('rows.0');
        // يقرأ رقمَ الفاتورة ولا يُعطى بابَها: لا يملك المبيعات ولا الماليّة
        $this->assertNotNull($row['order']);
        $this->assertNull($row['order_url']);
        // وكذلك قيدُ الورقة وحركتُها: الرقمُ بلا باب
        $this->actingAs($this->owner);
        $this->paySheet(Boutiques::settle($this->shop, $this->lama, '2027-02'));
        $paid = collect($this->rows($this->lama))->firstWhere('kind', 'paid');
        $this->assertNotNull($paid['entry_url']);
        $this->assertNotNull($paid['transaction_url']);
        $seen = collect($this->actingAs($reader)->getJson($url)->json('rows'))->firstWhere('kind', 'paid');
        $this->assertSame([$paid['entry'], $paid['transaction'], null, null], [$seen['entry'], $seen['transaction'], $seen['entry_url'], $seen['transaction_url']]);

        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $neighbour = User::create(['business_id' => $other->id, 'name' => 'جار', 'email' => 'n@test.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($neighbour)->getJson($url)->assertNotFound();
        $none = Business::create(['name' => 'بلا بوتيكات', 'type' => 'عام', 'status' => 'نشط']);
        $nobody = User::create(['business_id' => $none->id, 'name' => 'ن', 'email' => 'z@test.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($nobody)->getJson($url)->assertNotFound();
    }

    public function test_the_register_stays_when_the_feature_is_switched_off(): void
    {
        $this->sell([[$this->hers, 1], [$this->bundle, 1]]);
        $this->shop->update(['boutiques_enabled' => false, 'boutique_components_enabled' => false]);

        $this->assertNotEmpty($this->rows($this->lama));
        $this->assertTies('المفتاح مطفأ');
    }

    public function test_reading_the_register_writes_nothing(): void
    {
        $this->sell([[$this->hers, 1], [$this->bundle, 1]]);
        $count = fn () => collect(['transactions', 'journal_entries', 'journal_lines', 'boutique_component_dues', 'boutique_settlements', 'expenses', 'order_edits'])
            ->map(fn ($t) => DB::table($t)->count())->all();
        $before = $count();

        $this->rows($this->lama);
        $this->rows($this->reem, '2027-03', 'returns');

        $this->assertSame($before, $count());
    }

    /* ═══════════ الهجرة ═══════════ */

    public function test_the_migration_adds_empty_columns_and_refuses_to_be_undone_over_its_links(): void
    {
        $file = database_path('migrations/2026_12_03_090000_a_boutique_movement_names_its_documents.php');
        $migration = require $file;
        $migration->down();
        $this->assertFalse(Schema::hasColumn('boutique_settlements', 'transaction_id'));
        $this->assertFalse(Schema::hasColumn('order_edits', 'boutique_share'));
        (require $file)->up();

        $this->sell([[$this->bundle, 1]]);
        BoutiqueDues::pay(Boutiques::settle($this->shop, $this->reem, '2027-02'), 'نقدي');
        $this->expectException(\RuntimeException::class);
        (require $file)->down();
    }

    public function test_statuses_used_in_the_screen_are_the_ones_the_system_writes(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/Boutiques/MovementsPanel.tsx'));
        foreach (array_merge(...array_values(BoutiqueMovements::GROUPS)) as $kind) {
            $this->assertStringContainsString($kind.": '", $screen, $kind);
        }
        foreach ([BoutiqueReturn::AWAITING, BoutiqueReturn::REVIEW, BoutiqueReturn::SHOP, BoutiqueReturn::REDUCED, BoutiqueReturn::CLAIM, BoutiqueReturn::RECOVERED, BoutiqueDues::LOST, BoutiqueDues::UNVERIFIED] as $state) {
            $this->assertStringContainsString($state.": '", $screen, $state);
        }
    }
}
