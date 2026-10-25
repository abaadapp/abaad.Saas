<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Bank;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «تسجيل السداد» يسأل من أين خرج المال — وتسويةُ البوتيك منه.
 *
 * كان الزرُّ يرسل طلبًا فارغًا، والخادمُ لا يقرأ طريقة. ومصروفُ ورقة البوتيك
 * يُكتب بلا طريقة فيبقى «نقدي». فتسويةٌ حُوّلت للبوتيك من البنك تُرحَّل من
 * الصندوق: الدرجُ ينقص مالًا لم يخرج منه والبنكُ يبقى بمالٍ خرج. وجدها
 * تدقيقُ ماليّة RIBBON (2026-10-24) قبل أوّل تسويةٍ لمستحقّات بوتيكاته.
 */
class AnExpenseIsPaidFromWhereTheMoneyLeftTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $boutique;

    private Product $theirs;

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

        $this->shop = Business::create(['name' => 'متجر السداد', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'pay@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 1000, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        $this->boutique = Boutique::create([
            'business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true,
        ]);
        $this->theirs = Product::create([
            'business_id' => $this->shop->id, 'name' => 'عطر لمى', 'price' => 50, 'cost' => 30,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
            'boutique_id' => $this->boutique->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unpaid(string $method = 'نقدي', float $amount = 120): Expense
    {
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => $amount, 'method' => $method, 'status' => 'غير مدفوع',
            'spent_at' => now()->toDateString(), 'due_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        return Expense::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    /** بيعتان لبضاعة البوتيك (١٠٠) ثمّ ورقةُ فبراير — صافيها ٨٠ بعد العمولة */
    private function settlementExpense(): Expense
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->theirs->id, 'name' => 'عطر لمى', 'qty' => 2, 'price' => 50]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        Carbon::setTestNow('2027-03-02 09:00:00');
        $this->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])->assertSessionHasNoErrors();

        return Expense::findOrFail(BoutiqueSettlement::where('business_id', $this->shop->id)->firstOrFail()->expense_id);
    }

    /** الحسابُ الذي خرج منه المال — والحسابُ البنكيّ الأوّل يملك ورقةَ «البنك» النظاميّة */
    private function credited(Expense $e): ?string
    {
        $entry = JournalEntry::where('business_id', $this->shop->id)->where('sourceable_type', Expense::class)
            ->where('sourceable_id', $e->id)->whereNull('reverses_id')->firstOrFail();

        return Account::find($entry->lines()->where('credit', '>', 0)->value('account_id'))?->system_key;
    }

    private function bank(): float
    {
        return round(Bank::leaf($this->shop->id)->balance(), 3);
    }

    public function test_the_boutique_payout_is_recorded_from_the_bank(): void
    {
        $expense = $this->settlementExpense();
        $this->assertSame('نقدي', $expense->method, 'تهيئة: ورقةُ البوتيك تولد «نقدي»');

        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'تحويل بنكي'])->assertSessionHasNoErrors();

        $this->assertSame('bank', $this->credited($expense));
        $this->assertSame('تحويل بنكي', $expense->fresh()->method);
        $this->assertSame(920.0, $this->bank());
        $this->assertSame(100.0, Ledger::balance($this->shop->id, 'cash'), 'نقص الدرجُ مالًا لم يخرج منه');
    }

    public function test_paying_twice_records_one_payout(): void
    {
        $expense = $this->settlementExpense();

        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'تحويل بنكي']);
        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'نقدي']);

        $this->assertSame(1, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->count());
        $this->assertSame([920.0, 100.0], [$this->bank(), Ledger::balance($this->shop->id, 'cash')]);
    }

    public function test_without_a_method_the_written_one_stands(): void
    {
        $expense = $this->unpaid('بطاقة');

        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();

        $this->assertSame('بطاقة', $expense->fresh()->method);
        $this->assertSame(880.0, $this->bank());
    }

    public function test_cash_is_still_paid_from_the_drawer(): void
    {
        $expense = $this->unpaid('تحويل بنكي');

        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'نقدي'])->assertSessionHasNoErrors();

        $this->assertSame('cash', $this->credited($expense));
        $this->assertSame([1000.0, -120.0], [$this->bank(), Ledger::balance($this->shop->id, 'cash')]);
    }

    public function test_an_unknown_method_is_refused_and_nothing_is_paid(): void
    {
        $expense = $this->unpaid();

        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'ذهب'])->assertSessionHasErrors('method');

        $this->assertSame('غير مدفوع', $expense->fresh()->status);
        $this->assertSame(0, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->count());
    }

    /** ومصروفُ متجرٍ آخر لا يُسدَّد من هنا — ولا تُكتب له طريقة */
    public function test_another_shops_expense_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Expense::create([
            'business_id' => $other->id, 'type' => 'إيجار', 'amount' => 50, 'method' => 'نقدي',
            'status' => 'غير مدفوع', 'spent_at' => now(),
        ]);

        $this->post(route('admin.expenses.paid', $theirs->id), ['method' => 'تحويل بنكي'])->assertNotFound();

        $this->assertSame(['غير مدفوع', 'نقدي'], [$theirs->fresh()->status, $theirs->fresh()->method]);
    }
}
