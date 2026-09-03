<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\ExpenseType;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نوع المصروف يعرف حسابه — والموظّف لا يعرف أنّ للحسابات وجودًا.
 *
 * كان كلّ مصروفٍ يقع في «مصروفات أخرى» مهما كان نوعه: الإيجارُ والكهرباءُ
 * والرواتبُ في سطرٍ واحد اسمه «أخرى» يبتلعها جميعًا، فتُقرأ قائمة الدخل ولا
 * تقول أين يذهب مال المتجر.
 *
 * والربط قرارٌ محاسبيّ يُتَّخذ مرّةً: يضبطه من يعرف المحاسبة، ويعمل من لا
 * يعرفها كلّ يوم بلا أن يراه — يختار «إيجار»، والنظام يعرف أنّها 5300.
 */
class ExpenseAccountMappingTest extends TestCase
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

    private function balance(string $key): float
    {
        return Ledger::account($this->bid(), $key)?->balance() ?? 0.0;
    }

    private function type(string $name, ?string $accountKey = null): ExpenseType
    {
        return ExpenseType::create([
            'business_id' => $this->bid(),
            'name' => $name,
            'account_id' => $accountKey ? Ledger::account($this->bid(), $accountKey)->id : null,
        ]);
    }

    private function staff(array $permissions): User
    {
        return User::create([
            'business_id' => $this->bid(), 'name' => 'موظف',
            'email' => 'e'.uniqid().'@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /* ------------------------- الترحيل يتبع النوع ------------------------- */

    public function test_a_linked_type_posts_to_its_own_account(): void
    {
        $this->type('إيجار', 'rent');

        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300])
            ->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('rent'));
        $this->assertSame(0.0, $this->balance('other_expenses'), 'ابتلعت «أخرى» مصروفًا له حسابُه');
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_an_unlinked_type_falls_back_to_other_expenses(): void
    {
        $this->type('صيانة');

        $this->post(route('admin.expenses.store'), ['type' => 'صيانة', 'amount' => 40])
            ->assertSessionHasNoErrors();

        $this->assertSame(40.0, $this->balance('other_expenses'));
    }

    public function test_a_type_that_was_never_defined_still_posts(): void
    {
        // التاجر يكتب نوعًا لم يُسجَّل في القائمة — ولا يسقط تسجيل مصروفه
        $this->post(route('admin.expenses.store'), ['type' => 'قرطاسية', 'amount' => 12])
            ->assertSessionHasNoErrors();

        $this->assertSame(12.0, $this->balance('other_expenses'));
    }

    public function test_the_finance_screen_follows_the_same_map(): void
    {
        $this->type('إيجار', 'rent');

        $this->post(route('admin.finance.store'), [
            'kind' => 'expense', 'amount' => 300, 'side' => 'cash', 'expense_type' => 'إيجار',
        ])->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('rent'));
        $this->assertSame(-300.0, $this->balance('cash'));
    }

    public function test_paying_a_bill_later_posts_to_the_linked_account(): void
    {
        $this->type('إيجار', 'rent');

        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'status' => 'غير مدفوع',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance('rent'), 'قُيّد خروج مالٍ لم يخرج');

        $expense = \App\Models\Expense::where('business_id', $this->bid())->firstOrFail();
        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('rent'));
    }

    public function test_a_closed_account_does_not_capture_the_expense(): void
    {
        /*
         * الحساب المغلق لا يُرحَّل إليه، فربطُه كان سيُسقط تسجيل كلّ مصروفٍ
         * من نوعه برسالةٍ لا يفهمها من سجّله. فيرجع إلى «أخرى» ويمرّ المصروف.
         */
        $type = $this->type('إيجار', 'rent');
        Account::whereKey($type->account_id)->update(['active' => false]);

        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300])
            ->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('other_expenses'));
    }

    /* --------------------------- من يضبط الخريطة --------------------------- */

    public function test_the_accountant_can_link_a_type_to_an_account(): void
    {
        $type = $this->type('إيجار');
        $rent = Ledger::account($this->bid(), 'rent');

        $this->actingAs($this->staff(['expenses', 'accounting']))
            ->put(route('admin.expenseTypes.update', $type->id), ['account_id' => $rent->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($rent->id, (int) $type->fresh()->account_id);
    }

    public function test_a_clerk_cannot_touch_the_map(): void
    {
        $type = $this->type('إيجار');
        $rent = Ledger::account($this->bid(), 'rent');

        $this->actingAs($this->staff(['expenses', 'finance']))
            ->put(route('admin.expenseTypes.update', $type->id), ['account_id' => $rent->id])
            ->assertForbidden();

        $this->assertNull($type->fresh()->account_id);
    }

    public function test_a_clerk_never_receives_the_account_list(): void
    {
        /*
         * إخفاء العمود في الشاشة لا يمنع من يقرأ حمولة الصفحة: أوراق الشجرة
         * بأسمائها ورموزها هي ما يُمنع منه من لم يُمنح القسم، فلا تُرسل إليه.
         */
        $clerk = $this->actingAs($this->staff(['expenses', 'finance']));
        $props = $clerk->get(route('admin.expenses.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['expenseAccounts']);
    }

    public function test_the_accountant_receives_only_postable_expense_accounts(): void
    {
        $props = $this->actingAs($this->staff(['expenses', 'accounting']))
            ->get(route('admin.expenses.index'))->assertOk()->viewData('page')['props'];

        $labels = collect($props['expenseAccounts'])->pluck('label');

        $this->assertTrue($labels->contains(fn ($l) => str_contains($l, 'الإيجار')));
        // الأب لا يقبل قيدًا، ولا الصندوق: القائمة مصروفاتٌ وأوراقٌ فقط
        $this->assertFalse($labels->contains(fn ($l) => str_contains($l, 'الصندوق')));
        $this->assertFalse($labels->contains(fn ($l) => str_starts_with($l, '5 —')));
    }

    public function test_an_account_that_is_not_an_expense_is_refused(): void
    {
        // ربطُ الإيجار بحساب بنكٍ يقلب القيد ولا يشتكي — فيُردّ يوم الربط
        $type = $this->type('إيجار');

        $this->put(route('admin.expenseTypes.update', $type->id), [
            'account_id' => Ledger::account($this->bid(), 'bank')->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertNull($type->fresh()->account_id);
    }

    public function test_unlinking_returns_the_type_to_the_fallback(): void
    {
        $type = $this->type('إيجار', 'rent');

        $this->put(route('admin.expenseTypes.update', $type->id), ['account_id' => null])
            ->assertSessionHasNoErrors();

        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300]);

        $this->assertSame(0.0, $this->balance('rent'));
        $this->assertSame(300.0, $this->balance('other_expenses'));
    }

    /* ------------------------- عزل المتاجر ------------------------- */

    public function test_a_neighbours_account_cannot_be_linked(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $type = $this->type('إيجار');

        $this->put(route('admin.expenseTypes.update', $type->id), [
            'account_id' => Ledger::account($other->id, 'rent')->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertNull($type->fresh()->account_id);
    }

    public function test_a_neighbours_type_map_never_steers_our_expense(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        ExpenseType::create([
            'business_id' => $other->id, 'name' => 'إيجار',
            'account_id' => Ledger::account($other->id, 'rent')->id,
        ]);

        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300])
            ->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('other_expenses'), 'اتّبع مصروفُنا خريطةَ الجار');
        $this->assertSame(0.0, Ledger::account($other->id, 'rent')->balance());
    }
}
