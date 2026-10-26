<?php

namespace Tests\Feature;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueReturnRecovery;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Bank;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\Profitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * مرتجعُ قطعة بوتيكٍ بعد ورقتها إشعارٌ مرتبطٌ بها — والورقةُ وقيدُها كما صدرا.
 *
 * قرارُ المالك عند مراجعة PR #177 (2026-10-26):
 *   - يُسمح بالمرتجع بعد الورقة بإشعارٍ مرتبطٍ بها، بلا تعديلها ولا حذف قيودها.
 *   - البوتيكُ يتحمّل حين تثبت اتّفاقيتُه ذلك؛ وبلا شرطٍ موثَّق يُعرض للمراجعة.
 *   - ورقةٌ لم تُسدَّد: يُصحَّح الدَّينُ في 2160، ولا 1370 بلا مالٍ يُحصَّل.
 *   - ورقةٌ سُدِّدت: مطالبةٌ تُستردّ بمستند أو تُخصم من ورقةٍ لاحقة — مرّةً.
 *   - الأثرُ بيومه في الفترة المفتوحة، ويومُ البيع والورقةُ محفوظان.
 *   - ردُّ الزبون، والقطعة، واستردادُ الحصّة: ثلاثةُ أحوالٍ لا يُغني أحدُها عن غيره.
 *
 * والأرقام: قطعةُ البوتيك بخمسين، ونسبةُ المتجر ٢٠٪ — للبوتيك ٤٠ عن القطعة.
 */
class ABoutiqueReturnAfterItsSheetIsANoteTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'اتفاقية التوريد 2027/1 — البند 4';

    private Business $shop;

    private User $owner;

    private Branch $main;

    private Branch $bausher;

    private Boutique $boutique;

    private Product $theirs;

    private Product $ours;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create(['name' => 'متجر المرتجع', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        $this->main = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->bausher = Branch::create(['business_id' => $this->shop->id, 'name' => 'بوشر']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'returns@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 1000, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        $this->boutique = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->theirs = $this->product('عطر لمى', 50, $this->boutique->id);
        $this->ours = $this->product('باقة ورد', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ أدوات ═══════════ */

    private function product(string $name, float $price, ?int $boutique = null): Product
    {
        $product = Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
        foreach ([$this->main, $this->bausher] as $branch) {
            BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 50]);
        }

        return $product;
    }

    private function terms(?string $terms, ?string $ref = self::REF): void
    {
        $this->boutique->update(['returns_terms' => $terms, 'returns_terms_ref' => $terms ? $ref : null]);
    }

    private function sell(int $qty = 2, ?Branch $at = null, ?Product $product = null, ?float $price = null): Order
    {
        $product ??= $this->theirs;
        $this->withSession(['current_branch' => ($at ?? $this->main)->id])->postJson('/pos/checkout', [
            'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => $qty, 'price' => $price ?? (float) $product->price]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function issue(string $period = '2027-02', string $at = '2027-03-02 09:00:00'): BoutiqueSettlement
    {
        Carbon::setTestNow($at);
        $this->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => $period])->assertSessionHasNoErrors();

        return BoutiqueSettlement::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function pay(BoutiqueSettlement $sheet, string $at = '2027-03-05 11:00:00'): void
    {
        Carbon::setTestNow($at);
        $this->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'تحويل بنكي'])->assertSessionHasNoErrors();
    }

    /** @param  array<string, int>  $pieces */
    private function cancel(Order $order, array $pieces = [], string $at = '2027-03-10 12:00:00'): void
    {
        Carbon::setTestNow($at);
        $line = $this->line($order);
        OrderCorrection::cancel($order->fresh(), 'أعاد الزبون القطعة', null, $pieces === [] ? null : [$line->id => $pieces]);
    }

    private function line(Order $order, ?Product $product = null): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', ($product ?? $this->theirs)->id)->firstOrFail();
    }

    /** @return Collection<int, BoutiqueReturn> */
    private function notes(): Collection
    {
        return BoutiqueReturn::where('business_id', $this->shop->id)->orderBy('id')->get();
    }

    private function owed(): float
    {
        return Ledger::balance($this->shop->id, 'boutique_payable');
    }

    private function claimed(): float
    {
        return Ledger::balance($this->shop->id, 'boutique_receivable');
    }

    private function share(): float
    {
        return Ledger::balance($this->shop->id, 'other_expenses');
    }

    private function bank(): float
    {
        return round(Bank::leaf($this->shop->id)->balance(), 3);
    }

    private function shelf(?Branch $branch = null): int
    {
        return (int) BranchStock::where('branch_id', ($branch ?? $this->main)->id)->where('product_id', $this->theirs->id)->value('quantity');
    }

    private function profit(string $month, ?Branch $branch = null): float
    {
        $start = Carbon::parse($month.'-01');

        return Profitability::summary($this->shop->id, $start, $start->copy()->addMonthNoOverflow(), $branch?->id)['net_profit'];
    }

    private function returnEntries(): int
    {
        return JournalEntry::where('business_id', $this->shop->id)->where('source', BoutiqueReturns::SOURCE)->count();
    }

    /* ═══════════ قبل الورقة: كما كان ═══════════ */

    public function test_a_sale_cancelled_before_its_sheet_is_left_out_of_it_and_writes_no_note(): void
    {
        $kept = $this->sell();
        $cancelled = $this->sell();

        $this->delete(route('admin.orders.destroy', $cancelled->number))->assertSessionHasNoErrors();
        $sheet = $this->issue();

        $this->assertSame([100.0, 80.0], [(float) $sheet->gross, (float) $sheet->net]);
        $this->assertSame((int) $sheet->id, (int) $this->line($kept)->boutique_settlement_id);
        $this->assertNull($this->line($cancelled)->boutique_settlement_id);
        $this->assertCount(0, $this->notes());
        $this->assertSame(48, $this->shelf(), 'قطعتا الطلب الملغى عادتا إلى الرفّ');
    }

    /* ═══════════ ورقةٌ لم تُسدَّد ═══════════ */

    public function test_a_sound_piece_on_an_unpaid_sheet_under_documented_terms_lowers_the_debt_in_2160(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $sheet = $this->issue();
        $this->assertSame(80.0, $this->owed());

        $this->cancel($order, ['sellable' => 2]);

        $note = $this->notes()->sole();
        $this->assertSame(
            [BoutiqueReturn::REDUCED, BoutiqueReturn::SELLABLE, 'boutique', 'agreement', self::REF, 80.0],
            [$note->status, $note->condition, $note->bearer, $note->basis, $note->terms_ref, (float) $note->amount],
        );
        $this->assertSame(['2027-02-10', '2027-03-10', (int) $sheet->id], [$note->sold_at->toDateString(), $note->booked_at->toDateString(), (int) $note->boutique_settlement_id]);
        $this->assertNotNull($note->order_edit_id, 'ردُّ الزبون مستندُه الإلغاء نفسُه');

        $this->assertSame([0.0, 0.0, 0.0, 0.0], [$this->owed(), $this->claimed(), $this->share(), Ledger::balance($this->shop->id, 'cash')]);
        $this->assertSame(50, $this->shelf(), 'السليمتان عادتا إلى الرفّ');

        // والورقةُ وقيدُ استحقاقها كما صدرا
        $this->assertSame([100.0, 80.0], [(float) $sheet->fresh()->gross, (float) $sheet->fresh()->net]);
        $this->assertSame(1, JournalEntry::where('source', Boutiques::ACCRUAL)->whereNull('reversed_at')->whereNull('reverses_id')->count());
        $this->assertSame(0.0, BoutiqueReturns::payable(Expense::findOrFail($sheet->expense_id)));

        $this->pay($sheet, '2027-03-12 09:00:00');
        $this->assertSame(Expense::PAID, Expense::findOrFail($sheet->expense_id)->status);
        $this->assertSame(1000.0, $this->bank(), 'لا يخرج للبوتيك شيءٌ عن قطعةٍ رُدّت');
        $this->assertNull(Expense::findOrFail($sheet->expense_id)->transaction_id);

        // ربحُ القطعة المردودة صفر: ٢٠ في فبراير، و−٢٠ يومَ المرتجع — والفرعُ كمتجره
        $this->assertSame([20.0, -20.0], [$this->profit('2027-02'), $this->profit('2027-03')]);
        $this->assertSame([20.0, -20.0], [$this->profit('2027-02', $this->main), $this->profit('2027-03', $this->main)]);
    }

    public function test_a_sheet_reduced_by_part_of_its_sales_pays_only_the_rest(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $kept = $this->sell();
        $returned = $this->sell();
        $sheet = $this->issue();
        $this->cancel($returned, ['sellable' => 2]);

        $this->pay($sheet, '2027-03-12 09:00:00');

        $this->assertSame([0.0, 920.0], [$this->owed(), $this->bank()]);
        $paid = Transaction::findOrFail(Expense::findOrFail($sheet->expense_id)->transaction_id);
        $this->assertSame([80.0, '2027-03-12'], [(float) $paid->amount, Carbon::parse($paid->occurred_at)->toDateString()], 'الحركةُ بيوم خروج المال لا بيوم الورقة');
        $this->assertSame(1, $kept->fresh()->items()->count());
    }

    public function test_the_dues_screen_and_its_total_read_the_net_of_a_reduced_sheet(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $returned = $this->sell();
        $this->sell();
        $sheet = $this->issue();
        $this->cancel($returned, ['sellable' => 2]);

        $this->get(route('admin.finance.dues'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('expenses.0.id', $sheet->expense_id)
            ->where('expenses.0.amount', 80)
            ->where('totals.expenses', 80)
        );
        $this->assertSame(80.0, $this->owed(), 'الشاشةُ و2160 يقولان رقمًا واحدًا');
    }

    /* ═══════════ ورقةٌ سُدِّدت ═══════════ */

    public function test_a_returned_piece_on_a_paid_sheet_is_a_claim_recovered_by_documented_receipts_once(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->pay($this->issue());
        $this->assertSame([0.0, 920.0], [$this->owed(), $this->bank()]);

        $this->cancel($order, ['sellable' => 2]);

        $note = $this->notes()->sole();
        $this->assertSame([BoutiqueReturn::CLAIM, 80.0], [$note->status, (float) $note->amount]);
        $this->assertSame([0.0, 80.0, 0.0, 920.0], [$this->owed(), $this->claimed(), $this->share(), $this->bank()]);

        $receipt = fn (float $amount) => $this->post(route('admin.boutiques.returns.receipt', $this->boutique->id), [
            'amount' => $amount, 'method' => 'تحويل', 'reference' => 'TR-501',
        ]);

        $receipt(50)->assertSessionHasNoErrors();
        $this->assertSame([970.0, 30.0, 30.0, BoutiqueReturn::CLAIM], [$this->bank(), $this->claimed(), BoutiqueReturns::remaining($note->fresh()), $note->fresh()->status]);

        $receipt(40)->assertSessionHasErrors('returns');
        $this->assertSame(970.0, $this->bank(), 'لا يُستلم أكثرُ من المطالَب به');

        $receipt(30)->assertSessionHasNoErrors();
        $this->assertSame([1000.0, 0.0, BoutiqueReturn::RECOVERED], [$this->bank(), $this->claimed(), $note->fresh()->status]);
        $this->assertSame(['TR-501', 'TR-501'], BoutiqueReturnRecovery::orderBy('id')->pluck('reference')->all());

        $receipt(1)->assertSessionHasErrors('returns');
        $this->assertSame(0.0, $this->profit('2027-02') + $this->profit('2027-03'));
    }

    public function test_a_claim_is_offset_against_a_later_unpaid_sheet_and_only_once(): void
    {
        $this->terms(BoutiqueReturns::TERMS_ALL);
        $february = $this->sell();
        $this->pay($this->issue());

        $this->cancel($february, ['damaged' => 2]);
        $this->assertSame([BoutiqueReturn::CLAIM, 80.0], [$this->notes()->sole()->status, $this->claimed()]);
        $this->assertSame(48, $this->shelf(), 'التالفتان خارجَ الرفّ');

        Carbon::setTestNow('2027-03-15 10:00:00');
        $this->sell();
        $march = $this->issue('2027-03', '2027-04-02 09:00:00');
        $this->assertSame(80.0, $this->owed());

        $offset = fn () => $this->post(route('admin.boutiques.returns.offset', $this->boutique->id), ['settlement' => $march->id]);

        $offset()->assertSessionHasNoErrors();
        $this->assertSame([0.0, 0.0, BoutiqueReturn::RECOVERED], [$this->owed(), $this->claimed(), $this->notes()->sole()->status]);
        $this->assertSame(0.0, BoutiqueReturns::payable(Expense::findOrFail($march->expense_id)));

        $offset()->assertSessionHasErrors('returns');
        $this->post(route('admin.boutiques.returns.receipt', $this->boutique->id), ['amount' => 1, 'method' => 'نقدي', 'reference' => 'R'])
            ->assertSessionHasErrors('returns');
        $this->assertSame(1, BoutiqueReturnRecovery::count());

        $this->pay($march, '2027-04-05 09:00:00');
        $this->assertSame([920.0, 0.0], [$this->bank(), $this->owed()], 'الخصمُ أغنى عن سداد ورقة مارس');
    }

    public function test_a_paid_sheet_is_never_a_target_for_an_offset(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $sheet = $this->issue();
        $this->pay($sheet);
        $this->cancel($order, ['sellable' => 2]);

        $this->post(route('admin.boutiques.returns.offset', $this->boutique->id), ['settlement' => $sheet->id])->assertSessionHasErrors('returns');

        $this->assertSame([80.0, 0], [$this->claimed(), BoutiqueReturnRecovery::count()]);
    }

    /* ═══════════ بلا شرطٍ موثَّق: المراجعة ═══════════ */

    public function test_without_documented_terms_the_return_waits_for_the_accountant_then_lowers_the_debt(): void
    {
        $order = $this->sell();
        $sheet = $this->issue();

        $this->cancel($order, ['sellable' => 2]);

        $note = $this->notes()->sole();
        $this->assertSame(BoutiqueReturn::REVIEW, $note->status);
        $this->assertSame([80.0, 0.0, 80.0, 0], [$this->owed(), $this->claimed(), $this->share(), $this->returnEntries()], 'لا يُفترض أنّ البوتيك يتحمّل');
        $this->assertSame(80.0, BoutiqueReturns::payable(Expense::findOrFail($sheet->expense_id)));

        $review = fn (string $bearer) => $this->post(route('admin.boutiques.returns.review', $this->boutique->id), [
            'note' => $note->id, 'bearer' => $bearer, 'why' => 'وافق البوتيك كتابةً في 12 مارس',
        ]);

        $review('boutique')->assertSessionHasNoErrors();
        $this->assertSame([BoutiqueReturn::REDUCED, 'accountant', (int) $this->owner->id], [$note->fresh()->status, $note->fresh()->basis, (int) $note->fresh()->decided_by]);
        $this->assertSame(0.0, $this->owed());

        $review('shop')->assertSessionHasErrors('returns');
        $this->assertSame(1, $this->returnEntries());
    }

    public function test_when_the_accountant_puts_it_on_the_shop_the_sheet_stands_and_the_piece_leaves_the_boutique_shelf(): void
    {
        $order = $this->sell();
        $sheet = $this->issue();
        $this->cancel($order, ['sellable' => 2]);
        $this->assertSame(50, $this->shelf());

        $this->post(route('admin.boutiques.returns.review', $this->boutique->id), [
            'note' => $this->notes()->sole()->id, 'bearer' => 'shop', 'why' => 'الاتفاقية لا تذكر المرتجع',
        ])->assertSessionHasNoErrors();

        $this->assertSame(BoutiqueReturn::SHOP, $this->notes()->sole()->status);
        $this->assertSame([80.0, 0.0, 0], [$this->owed(), $this->claimed(), $this->returnEntries()]);
        $this->assertSame(80.0, BoutiqueReturns::payable(Expense::findOrFail($sheet->expense_id)));
        $this->assertSame(48, $this->shelf(), 'دُفع للبوتيك ثمنُها، فلا تُباع من رفّه ثانيةً');
    }

    public function test_terms_without_a_reference_are_not_documented(): void
    {
        $this->put(route('admin.boutiques.update', $this->boutique->id), [
            'name' => 'بوتيك لمى', 'commission_rate' => 20, 'returns_terms' => BoutiqueReturns::TERMS_SELLABLE,
        ])->assertSessionHasErrors('returns_terms_ref');

        DB::table('boutiques')->where('id', $this->boutique->id)->update(['returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => null]);
        $order = $this->sell();
        $this->issue();
        $this->cancel($order, ['sellable' => 2]);

        $this->assertSame(BoutiqueReturn::REVIEW, $this->notes()->sole()->status);
    }

    public function test_setting_the_terms_with_their_reference_is_logged(): void
    {
        $this->put(route('admin.boutiques.update', $this->boutique->id), [
            'name' => 'بوتيك لمى', 'commission_rate' => 20,
            'returns_terms' => BoutiqueReturns::TERMS_SELLABLE, 'returns_terms_ref' => self::REF,
        ])->assertSessionHasNoErrors();

        $this->assertSame([BoutiqueReturns::TERMS_SELLABLE, self::REF], [$this->boutique->fresh()->returns_terms, $this->boutique->fresh()->returns_terms_ref]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $this->boutique->id, 'subject_type' => 'boutique']);
    }

    /* ═══════════ حالُ القطعة ═══════════ */

    public function test_a_damaged_piece_under_sound_only_terms_waits_for_review_and_stays_off_the_shelf(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->issue();

        $this->cancel($order, ['damaged' => 2]);

        $this->assertSame([BoutiqueReturn::REVIEW, BoutiqueReturn::DAMAGED], [$this->notes()->sole()->status, $this->notes()->sole()->condition]);
        $this->assertSame([48, 80.0], [$this->shelf(), $this->owed()]);
    }

    public function test_one_line_returned_part_sound_part_damaged_is_split_to_the_fils(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell(3);
        $this->issue();
        $this->assertSame(120.0, $this->owed());

        $this->cancel($order, ['sellable' => 2, 'damaged' => 1]);

        $this->assertSame(
            [[BoutiqueReturn::SELLABLE, 2, 80.0, BoutiqueReturn::REDUCED], [BoutiqueReturn::DAMAGED, 1, 40.0, BoutiqueReturn::REVIEW]],
            $this->notes()->map(fn ($n) => [$n->condition, (int) $n->quantity, (float) $n->amount, $n->status])->all(),
        );
        $this->assertSame([40.0, 49], [$this->owed(), $this->shelf()]);
    }

    public function test_pieces_not_yet_received_stay_off_the_shelf_until_someone_says_what_came_back(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->issue();

        Carbon::setTestNow('2027-03-10 12:00:00');
        $this->delete(route('admin.orders.destroy', $order->number))->assertSessionHasNoErrors();

        $note = $this->notes()->sole();
        $this->assertSame([BoutiqueReturn::AWAITING, BoutiqueReturn::PENDING, 2, 80.0], [$note->status, $note->condition, (int) $note->quantity, (float) $note->amount]);
        $this->assertSame([48, 80.0, 0], [$this->shelf(), $this->owed(), $this->returnEntries()]);

        $receive = fn () => $this->post(route('admin.orders.boutique-returns', $order->number), ['note' => $note->id, 'sellable' => 1, 'damaged' => 1]);
        $receive()->assertSessionHasNoErrors();

        $this->assertSame(
            [[BoutiqueReturn::SELLABLE, 1, 40.0, BoutiqueReturn::REDUCED], [BoutiqueReturn::DAMAGED, 1, 40.0, BoutiqueReturn::REVIEW]],
            $this->notes()->map(fn ($n) => [$n->condition, (int) $n->quantity, (float) $n->amount, $n->status])->all(),
        );
        $this->assertSame([49, 40.0], [$this->shelf(), $this->owed()]);

        // ونقرةٌ ثانية على ما سُجّل حالُه لا تردّه إلى الرفّ ثانيةً ولا تُنقص الدَّين مرّتين
        $entries = $this->returnEntries();
        $this->post(route('admin.orders.boutique-returns', $order->number), ['note' => $note->id, 'sellable' => 1, 'damaged' => 0])
            ->assertSessionHasErrors('pieces');
        $this->assertSame([2, 49, 40.0, $entries], [$this->notes()->count(), $this->shelf(), $this->owed(), $this->returnEntries()]);
    }

    /* ═══════════ مرتجعٌ جزئيٌّ في يوم البيع ثمّ الباقي ═══════════ */

    public function test_a_same_day_partial_return_and_a_later_cancel_add_up_to_the_sheet(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell(3);
        $sheet = $this->issue('2027-02', '2027-02-10 11:00:00');
        $this->assertSame([150.0, 120.0], [(float) $sheet->gross, (float) $sheet->net]);

        foreach ([
            fn () => OrderCorrection::setQuantity($order->fresh(), $this->line($order), 4, 'أراد أخرى', 'collected', 'نقدي'),
            fn () => OrderCorrection::replaceLine($order->fresh(), $this->line($order), ['product_id' => $this->ours->id, 'qty' => 1], 'غيّر رأيه', 'refunded', 'نقدي'),
        ] as $refused) {
            try {
                $refused();
                $this->fail('زِيد أو استُبدل بندٌ حملته ورقة');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($sheet->number, $e->getMessage());
            }
        }

        OrderCorrection::setQuantity($order->fresh(), $this->line($order), 1, 'أعاد قطعتين', 'refunded', 'نقدي');

        $partial = $this->notes()->sole();
        $this->assertSame([BoutiqueReturn::QUANTITY, BoutiqueReturn::AWAITING, 2, 80.0], [$partial->source, $partial->status, (int) $partial->quantity, (float) $partial->amount]);
        $this->assertSame([47, 50.0], [$this->shelf(), Ledger::balance($this->shop->id, 'cash')]);

        BoutiqueReturns::receive($partial, 2, 0, 'استُلمتا');
        $this->assertSame([49, 40.0], [$this->shelf(), $this->owed()]);

        $this->cancel($order, ['sellable' => 1]);

        $this->assertSame(120.0, round((float) $this->notes()->sum('amount'), 3), 'مجموعُ الإشعارات صافي الورقة');
        $this->assertSame([0.0, 50], [$this->owed(), $this->shelf()]);
    }

    /** بسعرٍ ونسبةٍ يقع فيهما التقريب: قطعتان في يومها ثمّ الثالثة — كلٌّ بحصّته من سطر الورقة */
    public function test_a_same_day_partial_at_odd_prices_takes_its_share_of_the_sheets_line(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $this->boutique->update(['commission_rate' => 17.5]);
        $odd = $this->product('عطر صغير', 3.333, $this->boutique->id);
        $order = $this->sell(3, null, $odd);
        $this->assertSame(8.249, (float) $this->issue('2027-02', '2027-02-10 11:00:00')->net);

        OrderCorrection::setQuantity($order->fresh(), $this->line($order, $odd), 1, 'أعاد قطعتين', 'refunded', 'نقدي');
        Carbon::setTestNow('2027-03-10 12:00:00');
        OrderCorrection::cancel($order->fresh(), 'مرتجع', null, [$this->line($order, $odd)->id => ['sellable' => 1]]);

        // صافي ٣ قطع ٨٫٢٤٩ وصافي واحدةٍ ٢٫٧٥٠: فالقطعتان ٥٫٤٩٩ — لا ٥٫٥٠٠
        $this->assertSame([5.499, 2.75], $this->notes()->map(fn ($n) => (float) $n->amount)->all());
    }

    public function test_odd_prices_returned_one_by_one_close_the_sheet_to_the_fils(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $this->boutique->update(['commission_rate' => 17.5]);
        $odd = $this->product('عطر صغير', 3.333, $this->boutique->id);
        $orders = [$this->sell(1, null, $odd), $this->sell(1, null, $odd), $this->sell(1, null, $odd)];
        $sheet = $this->issue();
        $this->assertSame(8.249, (float) $sheet->net);

        foreach ($orders as $order) {
            Carbon::setTestNow('2027-03-10 12:00:00');
            OrderCorrection::cancel($order->fresh(), 'مرتجع', null, [$this->line($order, $odd)->id => ['sellable' => 1]]);
        }

        $this->assertSame([2.75, 2.749, 2.75], $this->notes()->map(fn ($n) => (float) $n->amount)->all());
        $this->assertSame(0.0, $this->owed());
    }

    /* ═══════════ التاريخُ المحاسبيّ ═══════════ */

    public function test_a_closed_month_is_not_written_into_and_the_sale_day_is_kept(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->issue();
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => '2027-02-28']);
        $february = fn () => DB::table('journal_lines')->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('e.business_id', $this->shop->id)->where('e.entry_date', '<', '2027-03-01')
            ->selectRaw('COUNT(*) as n, SUM(debit) as d, SUM(credit) as c')->first();
        $before = (array) $february();

        $this->cancel($order, ['sellable' => 2]);

        $this->assertEquals($before, (array) $february(), 'فبراير المقفل كما هو');
        $note = $this->notes()->sole();
        $this->assertSame(['2027-02-10', '2027-03-10'], [$note->sold_at->toDateString(), $note->booked_at->toDateString()]);
        $this->assertSame('2027-03-10', JournalEntry::where('source', BoutiqueReturns::SOURCE)->sole()->entry_date->toDateString());
    }

    /* ═══════════ الأوراقُ القديمة ═══════════ */

    /** ورقةٌ صدرت قبل قيد الإصدار: لا دَينَ لها في 2160 */
    private function makeLegacy(BoutiqueSettlement $sheet): void
    {
        $entry = JournalEntry::where('business_id', $this->shop->id)->where('source', Boutiques::ACCRUAL)
            ->where('sourceable_id', $sheet->id)->sole();
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete();
        DB::table('journal_entries')->where('id', $entry->id)->delete();
    }

    public function test_an_old_unpaid_sheet_pays_its_net_and_the_return_is_booked_on_the_payment_day(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $returned = $this->sell();
        $this->sell();
        $sheet = $this->issue();
        $this->makeLegacy($sheet);

        $this->cancel($returned, ['sellable' => 2]);

        $note = $this->notes()->sole();
        $this->assertSame([BoutiqueReturn::REDUCED, null, 0, 0.0], [$note->status, $note->booked_at, $this->returnEntries(), $this->owed()], 'لا قيدَ في 2160 لورقةٍ لا دَينَ لها فيه');

        $this->pay($sheet, '2027-03-12 09:00:00');

        $this->assertSame([920.0, 80.0, '2027-03-12'], [$this->bank(), $this->share(), $note->fresh()->booked_at->toDateString()]);
        // ١٠٠ إيرادٌ باقٍ و٨٠ للبوتيك عنه: ٢٠ — للمتجر وللفرع
        $this->assertSame(20.0, $this->profit('2027-02') + $this->profit('2027-03'));
        $this->assertSame(20.0, $this->profit('2027-02', $this->main) + $this->profit('2027-03', $this->main));
    }

    public function test_an_old_paid_sheet_makes_a_claim_against_the_account_its_payment_debited(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $sheet = $this->issue();
        $this->makeLegacy($sheet);
        $this->pay($sheet);

        $this->cancel($order, ['sellable' => 2]);

        $this->assertSame([BoutiqueReturn::CLAIM, 80.0, 0.0, 920.0], [$this->notes()->sole()->status, $this->claimed(), $this->share(), $this->bank()]);
    }

    /* ═══════════ الفروع ═══════════ */

    public function test_a_return_in_one_branch_of_a_split_sheet_comes_off_that_branch_and_branches_add_up_to_the_shop(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $this->sell(2, $this->main);
        $returned = $this->sell(2, $this->bausher);
        $this->issue();

        $this->cancel($returned, ['sellable' => 2]);

        $this->assertSame((int) $this->bausher->id, (int) $this->notes()->sole()->branch_id);
        foreach (['2027-02' => [20.0, 20.0, 40.0], '2027-03' => [0.0, -20.0, -20.0]] as $month => $expected) {
            $this->assertSame($expected, [$this->profit($month, $this->main), $this->profit($month, $this->bausher), $this->profit($month)], $month);
        }
        $this->assertSame(50, $this->shelf($this->bausher));
    }

    /* ═══════════ معاملةٌ واحدة ومرّةٌ واحدة ═══════════ */

    public function test_if_the_note_cannot_be_written_the_customer_is_not_refunded_either(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->issue();
        $entries = JournalEntry::count();
        BoutiqueReturn::creating(fn () => throw new RuntimeException('تعذّرت كتابة الإشعار'));

        try {
            $this->cancel($order, ['sellable' => 2]);
            $this->fail('أُلغي الطلب بلا إشعار');
        } catch (RuntimeException $e) {
            $this->assertSame('تعذّرت كتابة الإشعار', $e->getMessage());
        }

        $this->assertSame(OrderStatus::COMPLETED, (string) $order->fresh()->status);
        $this->assertSame([100.0, 80.0, 48, $entries], [Ledger::balance($this->shop->id, 'cash'), $this->owed(), $this->shelf(), JournalEntry::count()]);
    }

    public function test_cancelling_twice_writes_one_note(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->issue();

        $this->cancel($order, ['sellable' => 2]);
        $this->cancel($order, ['sellable' => 2]);

        $this->assertCount(1, $this->notes());
        $this->assertSame([0.0, 50], [$this->owed(), $this->shelf()]);
    }

    public function test_the_order_screen_cancels_with_the_state_of_each_line(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::PREPARING]);
        $this->issue();
        Carbon::setTestNow('2027-03-10 12:00:00');

        $this->post(route('admin.orders.status', $order->number), [
            'status' => OrderStatus::CANCELLED, 'lines' => [$this->line($order)->id => ['sellable' => 1, 'damaged' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::CANCELLED, (string) $order->fresh()->status);
        $this->assertSame([BoutiqueReturn::SELLABLE, BoutiqueReturn::DAMAGED], $this->notes()->pluck('condition')->all());
    }

    /* ═══════════ عزلُ المتاجر ═══════════ */

    public function test_another_shop_reaches_none_of_these_doors(): void
    {
        $this->terms(BoutiqueReturns::TERMS_SELLABLE);
        $order = $this->sell();
        $this->pay($this->issue());
        $this->cancel($order, ['damaged' => 2]);
        $note = $this->notes()->sole();

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'stranger-returns@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $theirs = Boutique::create(['business_id' => $other->id, 'name' => 'بوتيكهم', 'commission_rate' => 10, 'active' => true]);
        $before = [$note->fresh()->status, BoutiqueReturnRecovery::count(), JournalEntry::count()];

        $this->actingAs($stranger);
        $this->post(route('admin.boutiques.returns.review', $this->boutique->id), ['note' => $note->id, 'bearer' => 'shop', 'why' => 'x'])->assertNotFound();
        $this->post(route('admin.boutiques.returns.review', $theirs->id), ['note' => $note->id, 'bearer' => 'shop', 'why' => 'x'])->assertNotFound();
        $this->post(route('admin.boutiques.returns.receipt', $this->boutique->id), ['amount' => 1, 'method' => 'نقدي', 'reference' => 'x'])->assertNotFound();
        $this->post(route('admin.orders.boutique-returns', $order->number), ['note' => $note->id, 'sellable' => 1, 'damaged' => 0])->assertNotFound();

        $this->assertSame($before, [$note->fresh()->status, BoutiqueReturnRecovery::count(), JournalEntry::count()]);
    }

    /* ═══════════ وما بقي من قبل ═══════════ */

    public function test_the_shops_own_line_on_the_same_sale_is_still_corrected(): void
    {
        $this->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->theirs->id, 'name' => 'عطر لمى', 'qty' => 2, 'price' => 50],
                ['id' => $this->ours->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 20],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $this->issue('2027-02', '2027-02-10 11:00:00');

        OrderCorrection::setQuantity($order->fresh(), $this->line($order, $this->ours), 0, 'أعاد الباقة', 'refunded', 'نقدي');

        $this->assertSame([2], OrderItem::where('order_id', $order->id)->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame([80.0, 100.0, 0], [$this->owed(), Ledger::balance($this->shop->id, 'cash'), $this->notes()->count()]);
    }

    /**
     * ورقةٌ تُصدَر بكشفٍ تغيّر بعد قراءته تُردّ ليُراجَع — لا تُختم برقمٍ قديم.
     */
    public function test_a_statement_that_changed_before_issuing_is_refused_and_nothing_is_written(): void
    {
        $this->sell();
        $cancelled = $this->sell();
        Carbon::setTestNow('2027-03-02 09:00:00');
        $stale = Boutiques::statement($this->shop->id, $this->boutique->id, '2027-02');
        OrderCorrection::cancel($cancelled->fresh(), 'أُلغيت قبل الإصدار');

        $write = new \ReflectionMethod(Boutiques::class, 'write');

        try {
            $write->invoke(null, $this->shop, $this->boutique, '2027-02', $stale, 'المالك');
            $this->fail('صدرت ورقةٌ بكشفٍ تغيّر');
        } catch (SettlementRefused $e) {
            $this->assertStringContainsString('تغيّرت', $e->getMessage());
        }

        $this->assertSame([0, 0, 0, 0.0], [BoutiqueSettlement::count(), Expense::count(), OrderItem::whereNotNull('boutique_settlement_id')->count(), $this->owed()]);
    }
}
