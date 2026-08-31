<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشات المالية الخمس تكتب في دفترٍ واحد — ولا تكتب فيه إلا ما يتوازن.
 *
 * `LedgerTest` يحرس الدفتر من الداخل: التوازن، والطبيعة، والحسابات المقفلة.
 * وهذا الملفّ يحرس الأبواب التي يصل منها التاجر إليه: شاشةٌ تلتفّ على
 * `Ledger::post` أو تحذف ما لا يُحذف تُفسد الدفتر وإن كان الدفتر نفسه سليمًا.
 */
class FinanceSectionTest extends TestCase
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

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    /* ---------------------------- شجرة الحسابات ---------------------------- */

    public function test_the_chart_builds_itself_on_first_visit(): void
    {
        // متجرٌ أُنشئ قبل هذه النسخة لا شجرة له، وشاشةٌ فارغة لا تقول ماذا يفعل
        $this->assertSame(0, Account::where('business_id', $this->bid())->count());

        $this->get(route('admin.finance.chart'))->assertOk();

        $this->assertGreaterThan(15, Account::where('business_id', $this->bid())->count());
    }

    public function test_a_system_account_is_neither_deleted_nor_closed(): void
    {
        /*
         * الترحيل التلقائي يقصد هذه الحسابات بمفتاحها. إغلاق «الصندوق» يوقف
         * كلّ بيعة نقدية برسالةٍ لا يفهمها الكاشير، وحذفه يقطع سطورًا مرحَّلة
         * عن حسابها فلا يتوازن ميزانٌ بعده.
         */
        $this->get(route('admin.finance.chart'));
        $cash = Ledger::account($this->bid(), 'cash');

        $this->delete(route('admin.finance.chart.destroy', $cash->id));
        $this->assertNotNull(Account::find($cash->id), 'حُذف حسابٌ يقصده النظام');

        $this->post(route('admin.finance.chart.toggle', $cash->id));
        $this->assertTrue($cash->fresh()->active, 'أُغلق حسابٌ يقصده النظام');
    }

    public function test_an_account_with_entries_is_not_deleted(): void
    {
        $this->get(route('admin.finance.chart'));

        $mine = Account::create([
            'business_id' => $this->bid(), 'code' => '5950', 'name' => 'قرطاسية',
            'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        Ledger::post($this->bid(), 'شراء قرطاسية', [
            ['account' => $mine, 'debit' => 12],
            ['account' => 'cash', 'credit' => 12],
        ]);

        $this->delete(route('admin.finance.chart.destroy', $mine->id));

        $this->assertNotNull(Account::find($mine->id), 'حُذف حسابٌ عليه حركة، فبقيت سطوره بلا حساب');
    }

    public function test_an_account_cannot_become_a_child_of_its_own_child(): void
    {
        // الشجرة تصير حلقةً فيدور كلّ جمعٍ عليها إلى الأبد
        $this->get(route('admin.finance.chart'));

        $parent = Account::where('business_id', $this->bid())->where('code', '1')->first();
        $child = Ledger::account($this->bid(), 'cash');

        $this->put(route('admin.finance.chart.update', $parent->id), [
            'code' => $parent->code, 'name' => $parent->name, 'parent_id' => $child->id,
            'type' => 'أصل', 'normal_side' => 'debit',
        ])->assertSessionHasErrors('parent_id');

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_the_type_of_an_account_with_entries_is_frozen(): void
    {
        /*
         * قلبُ الطبيعة على حسابٍ عليه حركة يقلب إشارة رصيده التاريخيّ كلّه:
         * تُقرأ أرباح العام الماضي خسائر بلا أن يمسّ أحدٌ قيدًا واحدًا.
         */
        $this->get(route('admin.finance.chart'));
        $sales = Ledger::account($this->bid(), 'sales');

        Ledger::post($this->bid(), 'بيع', [
            ['account' => 'cash', 'debit' => 50],
            ['account' => $sales, 'credit' => 50],
        ]);

        $this->put(route('admin.finance.chart.update', $sales->id), [
            'code' => $sales->code, 'name' => $sales->name,
            'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        $this->assertSame('إيراد', $sales->fresh()->type);
        $this->assertSame('credit', $sales->fresh()->normal_side);
    }

    /* ---------------------------- القيود اليومية ---------------------------- */

    public function test_a_balanced_entry_saved_from_the_screen_reaches_the_ledger(): void
    {
        $this->get(route('admin.finance.journal'));

        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => now()->toDateString(),
            'description' => 'تسوية صندوق',
            'lines' => [
                ['account_id' => Ledger::account($this->bid(), 'cash')->id, 'debit' => 30],
                ['account_id' => Ledger::account($this->bid(), 'other_income')->id, 'credit' => 30],
            ],
        ])->assertSessionHasNoErrors();

        $entry = JournalEntry::where('business_id', $this->bid())->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->posted);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_an_unbalanced_entry_from_the_screen_leaves_nothing_behind(): void
    {
        /*
         * أهمّ ما في هذا الملفّ: الشاشة لا تكتب بنفسها.
         *
         * لو كتبت لتجاوزت فحص التوازن، ولوقع الخلل في الدفتر لا في الطلب —
         * فلا يظهر إلا في ميزان المراجعة بعد شهور، ولا يُعرف حينها أيّ قيدٍ
         * أفسده. والرفض هنا يجب أن يكون تامًّا: لا رأسَ قيدٍ يبقى.
         */
        $this->get(route('admin.finance.journal'));

        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => now()->toDateString(),
            'description' => 'قيد مختلّ',
            'lines' => [
                ['account_id' => Ledger::account($this->bid(), 'cash')->id, 'debit' => 100],
                ['account_id' => Ledger::account($this->bid(), 'sales')->id, 'credit' => 90],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
        $this->assertSame(0, \App\Models\JournalLine::count());
    }

    public function test_an_entry_cannot_be_posted_to_another_stores_account(): void
    {
        $this->get(route('admin.finance.journal'));

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);

        $this->post(route('admin.finance.journal.store'), [
            'entry_date' => now()->toDateString(),
            'description' => 'قيد على حساب الجار',
            'lines' => [
                ['account_id' => Ledger::account($other->id, 'cash')->id, 'debit' => 10],
                ['account_id' => Ledger::account($this->bid(), 'sales')->id, 'credit' => 10],
            ],
        ])->assertSessionHasErrors();

        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
    }

    /* --------------------------- الأصول الثابتة --------------------------- */

    /** يسجّل أصلًا من بابه: الشاشة تقيّد شراءه أيضًا، فالدفتر يبدأ متوازنًا */
    private function asset(array $attributes = []): FixedAsset
    {
        $this->get(route('admin.finance.assets'));

        $this->post(route('admin.finance.assets.store'), array_merge([
            'name' => 'ثلاجة عرض',
            /*
             * أوّل الشهر ثم الطرح — لا العكس.
             *
             * `now()->subMonths(2)` يفيض في اليوم ٣١: ٣١ أغسطس ناقص شهرين
             * تصير أوّل يوليو لا أوّل يونيو، فيصير الأصل ابنَ شهرين لا ثلاثة
             * ويسقط الاختبار في ثلاثة أيّامٍ من كلّ شهر. والتثبيت على أوّل
             * الشهر أوّلًا يجعل الحساب لا يعتمد على تاريخ التشغيل إطلاقًا.
             */
            'purchased_at' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'cost' => 1200,
            'salvage_value' => 0,
            'life_months' => 12,
            'paid_from' => 'cash',
        ], $attributes))->assertSessionHasNoErrors();

        return FixedAsset::where('business_id', $this->bid())->latest('id')->first();
    }

    public function test_depreciation_posts_the_month_and_only_the_month(): void
    {
        $asset = $this->asset();

        $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')])
            ->assertSessionHasNoErrors();

        // ثلاثة أشهر (الشراء وشهران بعده) × ١٠٠
        $this->assertSame(300.0, (float) $asset->fresh()->accumulated);

        $expense = Ledger::account($this->bid(), 'depreciation')->balance();
        $accumulated = Ledger::account($this->bid(), 'accumulated_depreciation')->balance();

        $this->assertSame(300.0, $expense);
        $this->assertSame(300.0, $accumulated);
    }

    public function test_pressing_depreciate_twice_does_not_double_the_expense(): void
    {
        /*
         * الشهر لا يُهلَك مرّتين. وبلا حارسٍ عليه يكفي أن يضغط اثنان الزرّ
         * نفسه — أو أن يعيد أحدهم تحميل الصفحة — ليتضاعف مصروف الشهر كلّه.
         */
        $asset = $this->asset();

        $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')]);
        $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')]);

        $this->assertSame(300.0, (float) $asset->fresh()->accumulated);
        $this->assertSame(300.0, Ledger::account($this->bid(), 'depreciation')->balance());
    }

    public function test_depreciation_stops_at_the_end_of_the_useful_life(): void
    {
        /*
         * بلا سقفٍ يستمرّ الإهلاك بعد انتهاء العمر فتهبط القيمة الدفترية تحت
         * قيمة الخردة ثم تصير سالبة: أصلٌ يُنتج مصروفًا إلى الأبد.
         */
        $asset = $this->asset([
            'purchased_at' => now()->subMonths(30)->startOfMonth()->toDateString(),
            'cost' => 1200, 'salvage_value' => 200, 'life_months' => 10,
        ]);

        $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')]);

        $this->assertSame(1000.0, (float) $asset->fresh()->accumulated);
        $this->assertSame(200.0, $asset->fresh()->bookValue(), 'أُهلك الأصل تحت قيمة خردته');
    }

    public function test_selling_an_asset_clears_it_from_the_books_and_books_the_gain(): void
    {
        $asset = $this->asset(['cost' => 1200, 'life_months' => 12]);
        $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')]);

        // القيمة الدفترية ٩٠٠، والبيع بـ١٠٠٠ ربحُه ١٠٠
        $this->post(route('admin.finance.assets.dispose', $asset->id), [
            'disposed_at' => now()->toDateString(),
            'amount' => 1000,
            'received_in' => 'bank',
        ])->assertSessionHasNoErrors();

        $this->assertSame('مباع', $asset->fresh()->status);
        $this->assertSame(0.0, Ledger::account($this->bid(), 'fixed_assets')->balance(), 'بقي الأصل في الميزانية بعد بيعه');
        $this->assertSame(0.0, Ledger::account($this->bid(), 'accumulated_depreciation')->balance());
        $this->assertSame(100.0, Ledger::account($this->bid(), 'other_income')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_an_asset_that_entered_the_ledger_is_not_deleted(): void
    {
        $asset = $this->asset();
        $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')]);

        $this->delete(route('admin.finance.assets.destroy', $asset->id));

        $this->assertNotNull(FixedAsset::find($asset->id), 'حُذف أصلٌ له قيود، فبقي إهلاكٌ لا يُعرف عمّا نشأ');
    }

    /* --------------------------- الحسابات البنكية --------------------------- */

    public function test_a_second_bank_account_does_not_turn_the_bank_leaf_into_a_parent(): void
    {
        /*
         * لو صارت ورقة «البنك» (1200) أبًا لتعطّل أمران معًا: الترحيل التلقائي
         * يقصدها بمفتاحها والحساب ذو الأبناء لا يُرحَّل إليه، وسطورُها القديمة
         * تبقى عليها فتُقرأ مرّتين — فيها وفي مجموع أبنائها.
         */
        $this->get(route('admin.finance.index'));

        $this->post(route('admin.finance.banks.store'), ['label' => 'التحصيل', 'bank_name' => 'بنك مسقط']);
        $this->post(route('admin.finance.banks.store'), ['label' => 'المصروفات', 'bank_name' => 'بنك ظفار']);

        $bank = Ledger::account($this->bid(), 'bank');

        $this->assertTrue($bank->isPostable(), 'صار حساب البنك أبًا فسقط كلّ ترحيلٍ إليه');
        $this->assertSame(
            3,
            BankAccount::where('business_id', $this->bid())->whereNotNull('account_id')->count(),
            'حسابٌ بنكيّ بلا ورقة في الشجرة لا يُقرأ رصيده في الميزانية'
        );
        $this->assertSame(
            3,
            BankAccount::where('business_id', $this->bid())->distinct()->count('account_id'),
            'حسابان بنكيّان على ورقةٍ واحدة يجمعان رصيدهما فلا يُعرف ما في كلٍّ منهما'
        );
    }

    public function test_only_one_bank_account_is_primary(): void
    {
        $this->get(route('admin.finance.index'));
        $this->post(route('admin.finance.banks.store'), ['label' => 'الثاني']);

        $second = BankAccount::where('business_id', $this->bid())->latest('id')->first();
        $this->post(route('admin.finance.banks.primary', $second->id));

        $this->assertSame(1, BankAccount::where('business_id', $this->bid())->where('is_primary', true)->count());
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_a_bank_account_with_ledger_movement_is_not_deleted(): void
    {
        $this->get(route('admin.finance.index'));
        $account = BankAccount::where('business_id', $this->bid())->first();

        Ledger::post($this->bid(), 'إيداع', [
            ['account' => 'bank', 'debit' => 500],
            ['account' => 'capital', 'credit' => 500],
        ]);

        $this->delete(route('admin.finance.banks.destroy', $account->id));

        $this->assertNotNull(BankAccount::find($account->id), 'حُذف حسابٌ عليه حركة، فلم يُعرف من أيّ بنكٍ خرج المال');
    }

    public function test_the_statement_opens_for_the_primary_and_for_a_named_account(): void
    {
        // المسار يحمل معرّفًا اختياريًّا، فلا يمرّ به زاحف الصفحات
        $this->get(route('admin.finance.statement'))->assertOk();

        $this->post(route('admin.finance.banks.store'), ['label' => 'الثاني']);
        $second = BankAccount::where('business_id', $this->bid())->latest('id')->first();

        $props = $this->get(route('admin.finance.statement', $second->id))->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($second->id, $props['account']['id'], 'فُتح كشف حسابٍ غير المطلوب');
    }

    public function test_another_stores_bank_account_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = BankAccount::create(['business_id' => $other->id, 'bank_name' => 'بنكهم']);

        $this->put(route('admin.finance.banks.update', $theirs->id), ['bank_name' => 'انتحال'])->assertNotFound();
        $this->assertSame('بنكهم', $theirs->fresh()->bank_name);
    }

    /* --------------------------- مصاريف شهرية --------------------------- */

    public function test_the_expenses_screen_totals_the_shown_month_not_all_time(): void
    {
        // «كم أنفقتُ هذا الشهر؟» جوابُه واحدٌ مهما تصفّحت — والترقيم يقصّ الصفوف لا السؤال
        \App\Models\Expense::create([
            'business_id' => $this->bid(), 'type' => 'إيجار', 'amount' => 300,
            'status' => 'مدفوع', 'spent_at' => now()->toDateString(),
        ]);
        \App\Models\Expense::create([
            'business_id' => $this->bid(), 'type' => 'إيجار', 'amount' => 500,
            'status' => 'مدفوع', 'spent_at' => now()->subMonths(2)->toDateString(),
        ]);

        $props = $this->get(route('admin.expenses.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(300.0, $props['monthTotal'], 'تسرّب مصروف شهرٍ آخر إلى مجموع الشهر');
        $this->assertSame(800.0, $props['totalAmount'], 'ضاع مجموع العمر حين صارت الشاشة شهرية');
        $this->assertContains(now()->subMonths(2)->format('Y-m'), $props['months']);
    }
}
