<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يُفسده ترتيبُ الشجرة، وما يُفسده نموذجٌ لا يسأل عن شيء.
 *
 * هذه الشاشات الثلاث — الشجرة والقيد والحساب البنكيّ — كانت تقبل ما يبدو
 * ترتيبًا وهو عطب:
 *
 * ١) «صندوق الفرع» تحت «الصندوق». يبدو ترتيبًا، وهو في الحقيقة إغلاقُ
 *    الصندوق أمام كلّ ترحيل — الحساب ذو الفروع لا يُرحَّل إليه — فيتوقّف
 *    تسجيل المصروف النقديّ برسالةٍ لا تُفهم، ويسقط ترحيل كلّ بيعةٍ نقدية في
 *    السجلّ بلا أن يرى أحد، ويختفي «الصندوق» من قائمة حسابات القيد.
 * ٢) «مدين الصندوق ٥٠ / دائن الصندوق ٥٠». متوازنٌ تمامًا ولا يعني شيئًا.
 * ٣) نموذج حسابٍ بنكيّ فارغ. حقولُه كلّها اختيارية، فضغطةٌ على «حفظ» تُنشئ
 *    حسابًا اسمُه «حساب بنكي» وتفتح له ورقةً في الشجرة لا تُحذف بعدها.
 * ٤) إيقافُ الحساب الرئيسيّ. يبقى المال ينزل في ورقته، ويختفي هو من الشاشة.
 */
class ChartAndBankSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Ledger::seedChart($this->business->id);
        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function account(string $key): Account
    {
        return Ledger::account($this->bid(), $key);
    }

    /** حسابٌ للتاجر تحت جذرٍ من جذور الشجرة */
    private function ownAccount(string $code, string $rootKey = '5'): Account
    {
        return Account::create([
            'business_id' => $this->bid(),
            'parent_id' => Account::where('business_id', $this->bid())->where('code', $rootKey)->value('id'),
            'code' => $code, 'name' => 'حساب '.$code, 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);
    }

    private function chartRows(): array
    {
        return $this->get(route('admin.finance.chart'))->assertOk()->viewData('page')['props']['accounts'];
    }

    /* ===================== الشجرة: أبٌ لا يصلح أبًا ===================== */

    public function test_a_system_account_never_becomes_a_parent(): void
    {
        $this->post(route('admin.finance.chart.store'), [
            'code' => '1110', 'name' => 'صندوق الفرع', 'type' => 'أصل',
            'parent_id' => $this->account('cash')->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertSame(0, $this->account('cash')->children()->count());
    }

    public function test_the_drawer_still_takes_money_after_the_attempt(): void
    {
        /*
         * وهذا هو الأثر الذي كان يقع: لا رسالةَ خطأ في الشجرة، ثمّ يتوقّف
         * تسجيل المصروف النقديّ في شاشةٍ أخرى بلا أن يربط أحدٌ بين الأمرين.
         */
        $this->post(route('admin.finance.chart.store'), [
            'code' => '1110', 'name' => 'صندوق الفرع', 'type' => 'أصل',
            'parent_id' => $this->account('cash')->id,
        ]);

        $this->post(route('admin.finance.store'), ['kind' => 'expense', 'amount' => 10, 'side' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(-10.0, $this->account('cash')->balance());
    }

    public function test_the_drawer_stays_in_the_entry_account_list(): void
    {
        $this->post(route('admin.finance.chart.store'), [
            'code' => '1110', 'name' => 'صندوق الفرع', 'type' => 'أصل',
            'parent_id' => $this->account('cash')->id,
        ]);

        $labels = collect($this->get(route('admin.finance.journal'))->assertOk()
            ->viewData('page')['props']['accounts'])->pluck('label');

        $this->assertTrue($labels->contains(fn ($l) => str_contains($l, 'الصندوق')));
    }

    public function test_an_existing_account_is_not_moved_under_a_system_account(): void
    {
        $mine = $this->ownAccount('5950');

        $this->put(route('admin.finance.chart.update', $mine->id), [
            'code' => '5950', 'name' => 'حساب 5950', 'type' => 'مصروف', 'normal_side' => 'debit',
            'parent_id' => $this->account('other_expenses')->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertSame(0, $this->account('other_expenses')->children()->count());
    }

    public function test_an_account_with_entries_never_becomes_a_parent(): void
    {
        // حسابٌ عليه قيدٌ يصير مقروءًا مرّتين لو جُمع مع أبنائه
        $mine = $this->ownAccount('5950');
        Ledger::post($this->bid(), 'اختبار', [
            ['account' => $mine, 'debit' => 20],
            ['account' => 'cash', 'credit' => 20],
        ]);

        $this->post(route('admin.finance.chart.store'), [
            'code' => '5951', 'name' => 'تحته', 'type' => 'مصروف', 'parent_id' => $mine->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_a_bank_leaf_never_becomes_a_parent(): void
    {
        $this->post(route('admin.finance.banks.store'), ['label' => 'التحصيل']);
        $this->post(route('admin.finance.banks.store'), ['label' => 'الثاني']);
        $this->get(route('admin.finance.index'));

        $leaf = BankAccount::where('label', 'الثاني')->value('account_id');

        $this->post(route('admin.finance.chart.store'), [
            'code' => '1225', 'name' => 'تحته', 'type' => 'أصل', 'parent_id' => $leaf,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_an_expense_type_account_never_becomes_a_parent(): void
    {
        $mine = $this->ownAccount('5950');
        ExpenseType::create(['business_id' => $this->bid(), 'name' => 'صيانة', 'account_id' => $mine->id]);

        $this->post(route('admin.finance.chart.store'), [
            'code' => '5951', 'name' => 'تحته', 'type' => 'مصروف', 'parent_id' => $mine->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_a_root_still_takes_new_children(): void
    {
        // والمنع ليس منعًا للترتيب: التاجر يبني شجرته تحت الجذور الخمسة
        $this->post(route('admin.finance.chart.store'), [
            'code' => '5350', 'name' => 'كهرباء وماء', 'type' => 'مصروف',
            'parent_id' => Account::where('business_id', $this->bid())->where('code', '5')->value('id'),
        ])->assertSessionHasNoErrors();

        $labels = collect($this->get(route('admin.finance.journal'))->viewData('page')['props']['accounts'])
            ->pluck('label');

        $this->assertTrue($labels->contains(fn ($l) => str_contains($l, 'كهرباء وماء')));
        $this->assertTrue($labels->contains(fn ($l) => str_contains($l, 'مصروفات أخرى')));
    }

    public function test_the_screen_hides_what_it_would_refuse(): void
    {
        $rows = collect($this->chartRows())->keyBy('code');

        $this->assertFalse($rows['1100']['can_parent'], 'عُرض «الصندوق» أبًا محتملًا');
        $this->assertFalse($rows['4100']['can_parent']);
        $this->assertTrue($rows['5']['can_parent'], 'حُجب جذرُ المصروفات عن الأبوّة');
    }

    public function test_a_broken_tree_says_so_on_the_screen(): void
    {
        // شجرةٌ عُطبت قبل الحارس تبقى معطوبة — فتُقال لا تُترك تبدو مرتّبة
        Account::create([
            'business_id' => $this->bid(), 'parent_id' => $this->account('cash')->id,
            'code' => '1110', 'name' => 'صندوق الفرع', 'type' => 'أصل', 'normal_side' => 'debit',
        ]);

        $rows = collect($this->chartRows())->keyBy('code');

        $this->assertTrue($rows['1100']['breaks_posting']);
        $this->assertFalse($rows['1200']['breaks_posting']);
    }

    /* ================= الشجرة: ما لا يُبدَّل وما لا يُحذف ================= */

    public function test_a_system_accounts_type_is_not_switched(): void
    {
        /*
         * «4100» يقصده الترحيل بمفتاحه `sales` لا بنوعه، فجعلُه «مصروفًا» لا
         * يمنع البيع من الترحيل إليه — وإنما يقلبه في كلّ تقريرٍ يقرأ الشجرة
         * بأنواعها: تُطرح المبيعات من نفسها.
         */
        $sales = $this->account('sales');

        $this->put(route('admin.finance.chart.update', $sales->id), [
            'code' => $sales->code, 'name' => $sales->name,
            'type' => 'مصروف', 'normal_side' => 'debit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('إيراد', $sales->fresh()->type);
        $this->assertSame('credit', $sales->fresh()->normal_side);
    }

    public function test_a_system_account_is_still_renamed(): void
    {
        // والمنع على النوع وحده: الاسم والرمز يبقيان بيد التاجر
        $rent = $this->account('rent');

        $this->put(route('admin.finance.chart.update', $rent->id), [
            'code' => '5310', 'name' => 'إيجار المحل', 'type' => 'مصروف', 'normal_side' => 'debit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('إيجار المحل', $rent->fresh()->name);
        $this->assertSame('5310', $rent->fresh()->code);
    }

    public function test_a_bank_leaf_is_not_deleted_from_the_chart(): void
    {
        /*
         * الرابط `nullOnDelete` فلا يشتكي شيء: يُحذف الحساب فيصير الحسابُ
         * البنكيّ بلا ورقة، ويُبنى له غيرُها عند أوّل فتحٍ للشاشة برمزٍ آخر —
         * فيتفرّق رصيدُه على ورقتين لا يجمعهما تقرير.
         */
        $this->post(route('admin.finance.banks.store'), ['label' => 'الأول']);
        $this->post(route('admin.finance.banks.store'), ['label' => 'الثاني']);
        $this->get(route('admin.finance.index'));

        $second = BankAccount::where('label', 'الثاني')->firstOrFail();

        $this->delete(route('admin.finance.chart.destroy', $second->account_id));

        $this->assertNotNull($second->fresh()->account_id);
        $this->assertDatabaseHas('accounts', ['id' => $second->account_id]);
    }

    public function test_a_linked_expense_account_is_not_deleted(): void
    {
        $mine = $this->ownAccount('5950');
        ExpenseType::create(['business_id' => $this->bid(), 'name' => 'صيانة', 'account_id' => $mine->id]);

        $this->delete(route('admin.finance.chart.destroy', $mine->id));

        $this->assertDatabaseHas('accounts', ['id' => $mine->id]);
    }

    public function test_an_unattached_account_is_still_deleted(): void
    {
        $mine = $this->ownAccount('5950');

        $this->delete(route('admin.finance.chart.destroy', $mine->id));

        $this->assertDatabaseMissing('accounts', ['id' => $mine->id]);
    }

    /* ===================== القيد: طرفان لا طرفٌ واحد ===================== */

    public function test_one_account_on_both_sides_is_refused(): void
    {
        $cash = $this->account('cash');

        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => now()->toDateString(), 'description' => 'من الصندوق إلى الصندوق',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 50],
                ['account_id' => $cash->id, 'credit' => 50],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
    }

    public function test_two_lines_on_one_side_are_still_allowed(): void
    {
        // «دائن الصندوق ٣٠ إيجارًا ودائن الصندوق ٢٠ كهرباءً» بيانٌ لا عطب
        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => now()->toDateString(), 'description' => 'مصروفا اليوم',
            'lines' => [
                ['account_id' => $this->account('rent')->id, 'debit' => 30],
                ['account_id' => $this->account('other_expenses')->id, 'debit' => 20],
                ['account_id' => $this->account('cash')->id, 'credit' => 30, 'memo' => 'إيجار'],
                ['account_id' => $this->account('cash')->id, 'credit' => 20, 'memo' => 'كهرباء'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(-50.0, $this->account('cash')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    /* ================= القيد: البابان — مبسّطٌ ومحاسب ================= */

    public function test_the_journal_screen_carries_the_simple_door(): void
    {
        $props = $this->get(route('admin.finance.journal'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['canRecordMovement']);
        $this->assertContains('expense', collect($props['movements'])->pluck('value')->all());
        // والوصفة المحاسبية لا تُرسل: شاشةٌ تعرف الحسابات تُغري بالاختيار منها
        $this->assertArrayNotHasKey('debit', $props['movements'][0]);
    }

    public function test_the_simple_door_writes_in_both_books(): void
    {
        $this->post(route('admin.finance.store'), [
            'kind' => 'expense', 'amount' => 30, 'side' => 'cash', 'description' => 'إيجار',
        ])->assertSessionHasNoErrors();

        $movement = Transaction::where('business_id', $this->bid())->firstOrFail();

        $this->assertNotNull($movement->journal_entry_id, 'حركةٌ بلا قيد');
        $this->assertSame(-30.0, $this->account('cash')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_the_simple_door_is_closed_to_whoever_lacks_finance(): void
    {
        /*
         * المبسّط يمرّ بمسار الحركة المالية، وهو محروسٌ بصلاحية «المالية» لا
         * بـ«المحاسبة المتقدّمة». فمن يملك الثانية وحدها لا يُعرض له البابُ
         * الذي سيُردّ عنه — ويبقى له بابُ المحاسب.
         */
        $accountant = User::create([
            'business_id' => $this->bid(), 'name' => 'محاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'permissions' => ['accounting'],
        ]);

        $props = $this->actingAs($accountant)->get(route('admin.finance.journal'))
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['canRecordMovement']);

        $this->post(route('admin.finance.store'), ['kind' => 'expense', 'amount' => 30, 'side' => 'cash'])
            ->assertForbidden();
    }

    /* ===================== الحساب البنكي ===================== */

    public function test_an_empty_form_creates_nothing(): void
    {
        $before = BankAccount::where('business_id', $this->bid())->count();

        $this->post(route('admin.finance.banks.store'), [])->assertSessionHasErrors('label');

        $this->assertSame($before, BankAccount::where('business_id', $this->bid())->count());
    }

    public function test_a_bank_name_alone_is_enough(): void
    {
        $this->post(route('admin.finance.banks.store'), ['bank_name' => 'بنك مسقط'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bank_accounts', ['business_id' => $this->bid(), 'bank_name' => 'بنك مسقط']);
    }

    public function test_the_primary_account_is_not_switched_off(): void
    {
        $this->post(route('admin.finance.banks.store'), ['label' => 'الأول']);
        $this->post(route('admin.finance.banks.store'), ['label' => 'الثاني']);

        $primary = BankAccount::where('business_id', $this->bid())->where('is_primary', true)->firstOrFail();

        $this->put(route('admin.finance.banks.update', $primary->id), [
            'label' => $primary->label, 'active' => false,
        ])->assertSessionHasErrors('active');

        $this->assertTrue($primary->fresh()->active);
    }

    public function test_a_stopped_account_keeps_its_money_in_sight(): void
    {
        /*
         * حسابٌ أُوقف قد يبقى فيه رصيد. وطرحُه من «مجموع الأرصدة» كان يُخفي
         * مالًا موجودًا: يقرأ التاجر رقمًا أصغر ممّا في الدفتر ولا شيء يقول
         * أين ذهب الباقي.
         */
        $this->post(route('admin.finance.banks.store'), ['label' => 'الأول']);
        $this->post(route('admin.finance.banks.store'), ['label' => 'المتوقّف']);
        $this->get(route('admin.finance.index'));

        $stopped = BankAccount::where('label', 'المتوقّف')->firstOrFail();
        Ledger::post($this->bid(), 'إيداع', [
            ['account' => $stopped->account, 'debit' => 500],
            ['account' => 'capital', 'credit' => 500],
        ]);
        $stopped->update(['active' => false]);

        $banks = $this->get(route('admin.finance.index'))->viewData('page')['props'];
        $this->assertSame(500.0, $banks['summary']['balance']);
        $this->assertSame(1, $banks['summary']['count'], 'عُدّ الموقوف في «حسابات مفعّلة»');

        $summary = $this->get(route('admin.finance.summary'))->viewData('page')['props'];
        $this->assertSame(500.0, $summary['bank']);
    }

    /* ===================== عزل المتاجر ===================== */

    public function test_a_neighbours_account_is_no_parent_of_ours(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);

        $this->post(route('admin.finance.chart.store'), [
            'code' => '5950', 'name' => 'تحت الجار', 'type' => 'مصروف',
            'parent_id' => Ledger::account($other->id, 'other_expenses')->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('accounts', ['business_id' => $this->bid(), 'code' => '5950']);
    }
}
