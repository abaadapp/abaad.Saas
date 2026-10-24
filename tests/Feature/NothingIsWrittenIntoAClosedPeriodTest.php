<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Bank;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * لا قيدَ في فترةٍ مقفلة — من أيّ بابٍ جاء.
 *
 * ═══ ما كان ═══
 *
 * الإقفالُ (`books_closed_through`، ومعه آخرُ إقرارٍ قُدِّم) كان يُسأل في بابٍ
 * واحد: تصحيحُ المصروف. والقيدُ اليدويّ يقبل أيَّ تاريخ، وعكسُه يُكتب بتاريخ
 * أصله، والمصروفُ الجديد وسندُ المورّد والأصلُ الثابت والافتتاحيُّ تُكتب
 * بتواريخ مستنداتها — كلُّها إلى `Ledger::post` ولا شيء يسأل. فشهرٌ أُقفل
 * وقُدِّم تقريرُه يتغيّر بعد تقديمه، بلا ورقةٍ ولا رسالة. وحارسُ التصحيح
 * نفسُه كان يكتب مصروفًا جديدًا في شهرٍ مقفل ليختبر تصحيحه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - في الدفتر نفسه (`Ledger::post`، ومنه `reverse`): بعد الحدّ يُكتب، وعلى
 *     الحدّ وقبله يُردّ بـ`ClosedPeriod` ولا يُكتب منه سطر.
 *   - والإقرارُ المقدَّم يُقفل كما يُقفل تاريخُ الدفاتر، والأبعدُ منهما هو الحدّ.
 *   - والعكسُ في فترةٍ مقفلة يُردّ والأصلُ لا يُمسّ، وفي الفترة الجارية يمضي.
 *   - والشاشاتُ ترفض برسالةٍ عربيّة — والطلبُ المباشر (JSON) يُجاب 422 لا 500.
 *   - والمستندُ لا يبقى نصفَ مكتوب: مصروفٌ، وسندٌ اعتُمد عند إدخاله، وأصلٌ
 *     ثابت، وافتتاحيُّ بنكٍ — إمّا كلُّه أو لا شيء.
 *   - وبيعُ اليوم يمضي والشهرُ الماضي مقفل.
 *   - وإقفالُ متجرٍ لا يُقفل غيرَه.
 *   - والإقرارُ لا يُقدَّم ليومٍ لم ينتهِ.
 *
 * والتزامنُ حارسُه `AClosingDateAndAnEntryWrittenAtOnceTest` (PostgreSQL).
 */
class NothingIsWrittenIntoAClosedPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Ledger::seedChart($this->business->id);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function close(?string $through, string $key = BooksPeriod::SETTING, ?Business $shop = null): void
    {
        Setting::updateOrCreate(['business_id' => ($shop ?? $this->business)->id, 'key' => $key], ['value' => $through]);
    }

    private function entry(?string $on, ?Business $shop = null): JournalEntry
    {
        return Ledger::post(($shop ?? $this->business)->id, 'قيد اختبار', [
            ['account' => 'cash', 'debit' => 10],
            ['account' => 'sales', 'credit' => 10],
        ], $on ? Carbon::parse($on) : null);
    }

    private function refused(callable $write): ClosedPeriod
    {
        $entries = JournalEntry::count();
        $lines = JournalLine::count();

        try {
            $write();
        } catch (ClosedPeriod $e) {
            $this->assertSame($entries, JournalEntry::count(), 'كُتب رأسُ قيدٍ في فترةٍ مقفلة');
            $this->assertSame($lines, JournalLine::count(), 'كُتبت سطورٌ في فترةٍ مقفلة');

            return $e;
        }

        $this->fail('كُتب قيدٌ في فترةٍ مقفلة');
    }

    private function account(string $key): Account
    {
        return Ledger::account($this->business->id, $key);
    }

    /* ============================ في الدفتر نفسه ============================ */

    public function test_an_entry_after_the_closed_day_is_written(): void
    {
        $this->close('2026-09-30');

        $this->assertSame('2026-10-01', $this->entry('2026-10-01')->entry_date->toDateString());
    }

    public function test_an_entry_inside_the_closed_period_is_refused_and_nothing_is_written(): void
    {
        $this->close('2026-09-30');

        $e = $this->refused(fn () => $this->entry('2026-09-15'));

        $this->assertStringContainsString('الدفاتر مقفلة حتى 2026-09-30', $e->getMessage());
        $this->assertStringContainsString('2026-09-15', $e->getMessage());
    }

    /** «مقفلة حتى ٣٠ سبتمبر» تعني أنّ ٣٠ سبتمبر نفسَه مقفل — والساعةُ لا تُخرجه */
    public function test_the_closed_day_itself_is_closed(): void
    {
        $this->close('2026-09-30');

        $this->refused(fn () => $this->entry('2026-09-30 23:59:00'));
        $this->assertSame('2026-10-01', $this->entry('2026-10-01 00:00:00')->entry_date->toDateString());
    }

    /** وقيدٌ بلا تاريخ يومُه اليوم — مفتوحٌ ما دام اليومُ بعد الحدّ */
    public function test_an_entry_without_a_date_is_today_and_open(): void
    {
        $this->close('2026-09-30');

        $this->assertSame('2026-10-20', $this->entry(null)->entry_date->toDateString());
    }

    public function test_a_filed_vat_return_closes_too_and_says_so(): void
    {
        $this->close('2026-09-30', 'vat_filed_through');

        $e = $this->refused(fn () => $this->entry('2026-09-15'));

        $this->assertStringContainsString('إقرارُ الضريبة', $e->getMessage());
        $this->assertTrue($e->byVatReturn);
    }

    /** والأبعدُ من التاريخين هو الحدّ — ورسالتُه تقول أيَّهما */
    public function test_the_farther_of_the_two_dates_is_the_limit(): void
    {
        $this->close('2026-08-31');
        $this->close('2026-09-30', 'vat_filed_through');

        $this->assertTrue($this->refused(fn () => $this->entry('2026-09-15'))->byVatReturn);

        $this->close('2026-09-30');
        $this->close('2026-08-31', 'vat_filed_through');

        $books = $this->refused(fn () => $this->entry('2026-09-15'));
        $this->assertFalse($books->byVatReturn);
        $this->assertSame('2026-09-30', $books->closedThrough->toDateString());
    }

    /** وتاريخٌ فارغٌ لا يُقفل شيئًا */
    public function test_an_empty_close_date_closes_nothing(): void
    {
        $this->close('');
        $this->close('', 'vat_filed_through');

        $this->assertSame('2026-01-15', $this->entry('2026-01-15')->entry_date->toDateString());
    }

    /* ============================ العكس ============================ */

    public function test_a_reversal_into_a_closed_period_is_refused_and_the_original_is_untouched(): void
    {
        $original = $this->entry('2026-09-15');
        $this->close('2026-09-30');

        $this->refused(fn () => Ledger::reverse($original, Carbon::parse('2026-09-15')));

        $this->assertNull($original->fresh()->reversed_at, 'خُتم الأصلُ معكوسًا والعكسُ لم يُكتب');
        $this->assertSame(0, JournalEntry::where('reverses_id', $original->id)->count());
        $this->assertSame(10.0, (float) $original->fresh()->lines->sum('debit'), 'تغيّرت سطورُ الأصل');
    }

    /** والإلغاءُ في الفترة الجارية يمضي كما كان: أصلٌ مقفلٌ يُعكس بيومه */
    public function test_a_reversal_in_the_open_period_still_works(): void
    {
        $original = $this->entry('2026-09-15');
        $this->close('2026-09-30');

        $reversal = Ledger::reverse($original);

        $this->assertSame('2026-10-20', $reversal->entry_date->toDateString());
        $this->assertNotNull($original->fresh()->reversed_at);
    }

    /* ============================ الشاشات ============================ */

    private function manualLines(): array
    {
        return [
            ['account_id' => $this->account('cash')->id, 'debit' => 25, 'credit' => 0],
            ['account_id' => $this->account('capital')->id, 'debit' => 0, 'credit' => 25],
        ];
    }

    public function test_the_manual_entry_screen_refuses_a_closed_date(): void
    {
        $this->close('2026-09-30');

        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => '2026-09-15', 'description' => 'رأس مال', 'lines' => $this->manualLines(),
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, JournalEntry::where('source', 'يدوي')->count());

        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => '2026-10-01', 'description' => 'رأس مال', 'lines' => $this->manualLines(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, JournalEntry::where('source', 'يدوي')->count());
    }

    /** وزرُّ «عكس القيد» يعكس بتاريخ الأصل — فأصلٌ في فترةٍ مقفلة لا يُعكس من هنا */
    public function test_the_reverse_button_cannot_write_into_a_closed_period(): void
    {
        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => '2026-09-15', 'description' => 'رأس مال', 'lines' => $this->manualLines(),
        ])->assertSessionHasNoErrors();
        $entry = JournalEntry::where('source', 'يدوي')->firstOrFail();
        $this->close('2026-09-30');

        $this->post(route('admin.finance.journal.reverse', $entry->id), ['reason' => 'خطأ'])
            ->assertSessionHasErrors('period')
            ->assertSessionHas('toast', fn ($t) => $t['type'] === 'danger' && str_contains($t['msg'], 'مقفلة'));

        $this->assertNull($entry->fresh()->reversed_at);
        $this->assertSame(0, JournalEntry::where('reverses_id', $entry->id)->count());
    }

    /** والطلبُ المباشر يُجاب برسالته — لا بصفحة خطأ خادم */
    public function test_a_direct_json_request_is_answered_422_with_the_reason(): void
    {
        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => '2026-09-15', 'description' => 'رأس مال', 'lines' => $this->manualLines(),
        ]);
        $entry = JournalEntry::where('source', 'يدوي')->firstOrFail();
        $this->close('2026-09-30');

        $this->postJson(route('admin.finance.journal.reverse', $entry->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.period.0', fn ($m) => str_contains($m, 'الدفاتر مقفلة حتى 2026-09-30'));

        $this->assertNull($entry->fresh()->reversed_at);
    }

    /* ============================ المستندُ كلُّه أو لا شيء ============================ */

    public function test_a_new_expense_dated_in_a_closed_month_is_refused_and_not_saved(): void
    {
        $this->close('2026-09-30');

        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 400, 'spent_at' => '2026-09-20', 'method' => 'نقدي',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Expense::withTrashed()->count(), 'حُفظ مصروفٌ بلا قيده');
        $this->assertSame(0, JournalEntry::count());
    }

    /**
     * سندٌ يُعتمد عند إدخاله في فترةٍ مقفلة لا يبقى معلّقًا بلا مرفقه.
     *
     * كان السندُ يلتزم ثمّ يُرفض اعتمادُه، فيبقى ويُحذف ملفُّه من تحته،
     * ويُردّ إعادةُ الإرسال بـ«رقم السند مكرّر».
     */
    public function test_a_supplier_invoice_approved_on_entry_in_a_closed_period_leaves_nothing_behind(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'زهور']);
        $this->close('2026-09-30');
        $form = fn (string $on) => [
            'supplier_id' => $supplier->id, 'supplier_ref' => '7096', 'issued_at' => $on,
            'subtotal' => 47.4, 'tax' => 0, 'approve_now' => true,
        ];

        $this->post(route('admin.purchases.invoices.store'), $form('2026-09-29'))->assertSessionHasErrors();
        $this->assertSame(0, SupplierInvoice::count(), 'بقي سندٌ معلّقٌ والاعتمادُ رُفض');

        // والنموذجُ نفسُه بتاريخٍ مفتوح يمرّ — لا «رقم السند مكرّر»
        $this->post(route('admin.purchases.invoices.store'), $form('2026-10-01'))->assertSessionHasNoErrors();
        $this->assertSame(SupplierInvoice::count(), 1);
    }

    public function test_a_fixed_asset_paid_in_a_closed_period_is_refused_whole(): void
    {
        $this->close('2026-09-30');
        $asset = fn (array $over = []) => array_merge([
            'name' => 'ثلاجة ورد', 'purchased_at' => '2026-09-10', 'cost' => 300, 'life_months' => 36, 'paid_from' => 'cash',
        ], $over);

        $this->post(route('admin.finance.assets.store'), $asset())->assertSessionHasErrors('period');
        $this->assertSame(0, FixedAsset::count(), 'أصلٌ في السجلّ بلا ثمنه في الدفتر');

        // وأصلٌ يُسجَّل بلا قيدٍ (لم يُذكر من أين دُفع) لا يمسّ الدفتر فيُقبل
        $this->post(route('admin.finance.assets.store'), $asset(['paid_from' => null]))->assertSessionHasNoErrors();
        $this->assertSame(1, FixedAsset::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_bank_opening_in_a_closed_period_is_not_half_rewritten(): void
    {
        $this->post(route('admin.finance.banks.store'), [
            'label' => 'الحساب الجاري', 'opening_balance' => 100, 'opening_date' => '2026-09-01',
        ])->assertSessionHasNoErrors();
        $account = BankAccount::where('business_id', $this->business->id)->firstOrFail();
        $opening = Books::liveEntriesFor($account)->sole();
        $this->close('2026-09-30');

        $this->put(route('admin.finance.banks.update', $account->id), [
            'label' => 'الحساب الجاري', 'opening_balance' => 250, 'opening_date' => '2026-09-01',
        ])->assertSessionHasErrors('period');

        $this->assertSame(100.0, (float) $account->fresh()->opening_balance, 'حُفظ افتتاحيٌّ لا يقوله الدفتر');
        $this->assertNull($opening->fresh()->reversed_at, 'عُكس الافتتاحيُّ القديم ولم يُكتب الجديد');
        $this->assertSame(1, Books::liveEntriesFor($account)->count());

        // وحفظُ ما لا يمسّ الدفترَ يمضي
        $this->put(route('admin.finance.banks.update', $account->id), [
            'label' => 'الحساب الجاري', 'iban' => 'OM00TEST', 'opening_balance' => 100, 'opening_date' => '2026-09-01',
        ])->assertSessionHasNoErrors();
        $this->assertSame('OM00TEST', $account->fresh()->iban);
    }

    /**
     * و`syncOpening` نفسُها كلُّها أو لا شيء — لمن يناديها بلا معاملةٍ حولها.
     *
     * شاشةُ الحسابات تستدرك الحساباتِ القديمة بها عند فتحها. وكان عكسُ
     * القديم يلتزم وحده ثمّ يُردّ الجديد: افتتاحيٌّ يُمحى من الدفتر بفتح شاشة.
     */
    public function test_the_opening_sync_itself_does_not_reverse_what_it_cannot_rewrite(): void
    {
        $this->post(route('admin.finance.banks.store'), [
            'label' => 'الحساب الجاري', 'opening_balance' => 100, 'opening_date' => '2026-09-01',
        ])->assertSessionHasNoErrors();
        $account = BankAccount::where('business_id', $this->business->id)->firstOrFail();
        $opening = Books::liveEntriesFor($account)->sole();
        $this->close('2026-09-30');

        $account->forceFill(['opening_balance' => 250])->saveQuietly();

        $this->refused(fn () => Bank::syncOpening($account->fresh(), $this->owner->id));

        $this->assertNull($opening->fresh()->reversed_at, 'عُكس الافتتاحيُّ القديم ولم يُكتب الجديد');
    }

    /** وبيعُ اليوم يُكتب والشهرُ الماضي مقفل — الصندوقُ لا يتوقّف */
    public function test_a_till_sale_today_is_written_while_last_month_is_closed(): void
    {
        $this->close('2026-09-30');
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 20, 'cost' => 0, 'quantity' => 50, 'active' => true,
        ]);
        $branch = Branch::where('business_id', $this->business->id)->firstOrFail();
        $this->activatePosDevice($this->business->id, $branch->id);
        session(['pos_cashier_id' => $this->owner->id, 'current_branch' => $branch->id]);

        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => 'باقة', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $sale = JournalEntry::where('sourceable_type', Order::class)->where('sourceable_id', $order->id)->sole();
        $this->assertSame('2026-10-20', $sale->entry_date->toDateString());
    }

    /* ============================ العزل ============================ */

    public function test_one_shops_close_date_does_not_close_another(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $this->close('2026-09-30');

        $this->assertSame('2026-09-15', $this->entry('2026-09-15', $other)->entry_date->toDateString());
        $this->refused(fn () => $this->entry('2026-09-15'));

        // وإقفالُ الآخر لا يُقفل هذا
        $this->close('', BooksPeriod::SETTING);
        $this->close('2026-09-30', BooksPeriod::SETTING, $other);
        $this->assertSame('2026-09-15', $this->entry('2026-09-15')->entry_date->toDateString());
    }

    /* ============================ الإعدادات ============================ */

    /**
     * والإقرارُ لا يُقدَّم ليومٍ لم ينتهِ — كالدفاتر.
     *
     * كان اليومُ يُقبل، ومنذ صار الدفترُ كلُّه يسأل الإقفال صار إقرارُ اليوم
     * يردّ كلَّ قيدٍ بعده حتى منتصف الليل: بيعَ الصندوق ودفعةَ Paymob وكلَّ إلغاء.
     */
    public function test_a_vat_return_cannot_be_filed_for_today(): void
    {
        $this->post(route('admin.settings.update'), ['vat_filed_through' => '2026-10-20'])
            ->assertSessionHasErrors('vat_filed_through');
        $this->assertNull(Setting::where('business_id', $this->business->id)->where('key', 'vat_filed_through')->value('value'));

        $this->post(route('admin.settings.update'), ['vat_filed_through' => '2026-10-19'])->assertSessionHasNoErrors();
        $this->post(route('admin.settings.update'), ['books_closed_through' => '2026-10-20'])
            ->assertSessionHasErrors('books_closed_through');
    }
}
