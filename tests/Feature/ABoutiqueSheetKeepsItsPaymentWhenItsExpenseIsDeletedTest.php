<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\BoutiqueDues;
use App\Support\Ledger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ورقةُ تسوية البوتيك لا تعود مستحقّةً لأنّ مصروفها حُذف — ولا تُدفع مرّتين.
 *
 * ═══ ما كان يقع ═══
 *
 * تُصدَر الورقة فيُنشأ لها مصروفٌ غير مدفوع بصافي البوتيك، ويُسدَّد بـ«سُدِّدت»
 * من شاشة المصروفات. ثمّ يُحذف المصروف من الشاشة نفسها — كأيّ مصروف:
 *
 *   - تُحذف حركةُ الصندوق ويُعكس قيدُ السداد، والمالُ خرج فعلًا إلى البوتيك.
 *   - والورقةُ باقيةٌ صادرة وبنودُها محجوزةٌ لها، لكنّ `SoftDeletes` يُخفي
 *     مصروفها عن علاقتها، فتقرؤها شاشةُ المستحقّات **غيرَ مسدَّدة**: ٢٣ ر.ع
 *     تعود دَينًا على المحلّ — والدفترُ يوافقها، فيدفعها أحدٌ ثانيةً.
 *   - وما حُذف قبل هذا الإصلاح يُقرأ كذلك إلى اليوم.
 *
 * وكانت «سُدِّدت» تسأل «أمسدَّدة؟» قبل معاملتها، فضغطتان معًا تكتبان دفعتين.
 *
 * ═══ ما صار ═══
 *
 *   - مصروفُ الورقة لا يُحذف ولا يُمحى من أيّ باب — الشاشة، والمحذوفات،
 *     والأمر الليليّ، وأيّ كودٍ يحذف النموذج. إلغاءُ الورقة له مستندُه، ولا بابَ
 *     له بعد (مهمّةٌ مستقلّة)؛ والحذفُ ليس إلغاءً ماليًّا.
 *   - وعلاقةُ الورقة بمصروفها تقرأ المحذوف: ما حُذف قبل الإصلاح يبقى مسدَّدًا
 *     كما كان — بلا تعديلٍ على صفّه.
 *   - و«سُدِّدت» تعيد السؤال تحت قفل الصفّ.
 */
class ABoutiqueSheetKeepsItsPaymentWhenItsExpenseIsDeletedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = $this->shop('متجر الورد');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->boutique = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(string $name): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    /**
     * الدورةُ كلُّها من أبوابها: بيعُ قطعة البوتيك في الصندوق، وطيُّ الشهر،
     * وإصدارُ الورقة. ٢٨٫٧٥ بعمولة ٢٠٪ = ٢٣ ر.ع للبوتيك.
     */
    private function issueSheet(): Expense
    {
        $piece = Product::create(['business_id' => $this->shop->id, 'name' => 'عطر لمى', 'price' => 28.75, 'cost' => 15,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true, 'boutique_id' => $this->boutique->id]);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $piece->id, 'name' => $piece->name, 'qty' => 1, 'price' => 28.75]],
            'payment_method' => 'نقدي',
        ])->assertOk();
        $this->assertNotNull(Order::where('business_id', $this->shop->id)->first());

        Carbon::setTestNow('2027-03-02 09:00:00');
        $this->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])->assertSessionHasNoErrors();

        $sheet = BoutiqueSettlement::where('business_id', $this->shop->id)->sole();
        $this->assertSame(23.0, (float) $sheet->net);

        return Expense::findOrFail($sheet->expense_id);
    }

    private function owed(): float
    {
        return (float) BoutiqueDues::ledgerFor($this->shop->id, $this->boutique->id)['standalone_unpaid'];
    }

    private function cashRows(Expense $e): int
    {
        return Transaction::withTrashed()->where('business_id', $this->shop->id)->whereNull('deleted_at')
            ->where('type', 'مصروف')->where('amount', $e->amount)->count();
    }

    private function assertBalanced(): void
    {
        $lines = JournalLine::join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $this->shop->id);
        $this->assertEqualsWithDelta((float) (clone $lines)->sum('debit'), (float) (clone $lines)->sum('credit'), 0.0001, 'الدفتر غير متوازن');
    }

    public function test_a_paid_sheets_expense_is_not_deleted_and_its_payment_stays(): void
    {
        $expense = $this->issueSheet();
        $this->assertSame(23.0, $this->owed());

        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->owed());
        $this->assertSame(1, $this->cashRows($expense));
        $entry = Books::liveEntriesFor($expense->fresh())->sole();

        $this->delete(route('admin.expenses.destroy', $expense->id))->assertSessionHas('toast.type', 'danger');

        $this->assertNotSoftDeleted($expense);
        $this->assertSame(0.0, $this->owed(), 'عاد المستحقّ قابلًا للدفع');
        $this->assertSame(1, $this->cashRows($expense), 'حُذفت حركةُ الصندوق');
        $this->assertNull($entry->fresh()->reversed_at, 'عُكس قيدُ السداد');
        $this->assertBalanced();

        // والرسالةُ تقول ما هو ولماذا — برقم الورقة
        $this->assertStringContainsString($expense->reference, session('toast')['msg']);
    }

    public function test_an_unpaid_sheets_expense_is_not_deleted_either(): void
    {
        $expense = $this->issueSheet();

        $this->delete(route('admin.expenses.destroy', $expense->id))->assertSessionHas('toast.type', 'danger');

        $this->assertNotSoftDeleted($expense);
        $this->assertSame(23.0, $this->owed());
        // وتبقى قابلةً للسداد من بابها
        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->owed());
    }

    public function test_paying_a_sheet_twice_writes_one_payment(): void
    {
        $expense = $this->issueSheet();

        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHas('toast.type', 'info');

        $this->assertSame(1, $this->cashRows($expense));
        $this->assertCount(1, Books::liveEntriesFor($expense->fresh()));
    }

    /** طلبٌ ثانٍ يصل بعد أن قرأ الأوّلُ الورقةَ غيرَ مسدَّدة، وقبل أن يكتب */
    public function test_a_payment_that_lands_while_another_is_paying_is_not_written_twice(): void
    {
        $expense = $this->issueSheet();

        $landed = false;
        DB::listen(function (QueryExecuted $q) use (&$landed, $expense) {
            if ($landed || ! str_starts_with($q->sql, 'select * from "expenses"')) {
                return;
            }
            $landed = true;
            // الطلبُ الآخر سدّدها والتزم — كما يراه هذا الطلب بعد قراءته الأولى
            DB::table('expenses')->where('id', $expense->id)->update(['status' => Expense::PAID]);
        });

        $this->post(route('admin.expenses.paid', $expense->id));

        $this->assertTrue($landed, 'لم يقع الترتيبُ المقصود');
        $this->assertSame(0, $this->cashRows($expense), 'كُتبت دفعةٌ ثانية');
        $this->assertCount(0, Books::liveEntriesFor($expense->fresh()));
    }

    /** ورقةٌ حُذف مصروفها المدفوع قبل هذا الإصلاح — تُقرأ كما كانت حقًّا، بلا تعديلٍ على صفّها */
    public function test_a_sheet_deleted_before_the_fix_still_reads_paid(): void
    {
        $expense = $this->issueSheet();
        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();
        DB::table('expenses')->where('id', $expense->id)->update(['deleted_at' => now()]);   // كما تركه الحذفُ القديم

        $this->assertSame(0.0, $this->owed(), 'المسدَّدةُ المحذوفة تُقرأ مستحقّة');
        $sheet = $this->get(route('admin.boutiques.show', $this->boutique->id))->assertOk()
            ->viewData('page')['props']['settlements'][0] ?? null;
        $this->assertTrue($sheet === null || $sheet['standalone_paid'] ?? true);

        // والمحذوفُ غيرُ المدفوع يبقى مستحقًّا — لم يُدفع قطّ
        $unpaid = Expense::create(['business_id' => $this->shop->id, 'type' => 'تسوية بوتيكات', 'amount' => 9, 'method' => 'نقدي',
            'spent_at' => '2027-03-02', 'status' => Expense::UNPAID, 'reference' => 'BQ-OLD']);
        BoutiqueSettlement::create(['business_id' => $this->shop->id, 'boutique_id' => $this->boutique->id, 'number' => 'BQ-OLD',
            'period' => '2027-01', 'gross' => 9, 'commission' => 0, 'net' => 9, 'lines_count' => 1,
            'kind' => BoutiqueSettlement::SETTLEMENT, 'expense_id' => $unpaid->id, 'issued_at' => now()]);
        DB::table('expenses')->where('id', $unpaid->id)->update(['deleted_at' => now()]);
        $this->assertSame(9.0, $this->owed());

        // والاستعادةُ مسموحة، ولا تكتب سدادًا ثانيًا
        $this->post(route('admin.expenses.restore', $expense->id))->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($expense);
        $this->assertCount(1, Books::liveEntriesFor($expense->fresh()));
        $this->assertSame(9.0, $this->owed());
    }

    /** والمحوُ النهائيّ — بالزرّ أو بالأمر الليليّ — لا يمحو مصروفَ ورقة، ولا يتوقّف عنده */
    public function test_a_sheets_expense_left_in_the_trash_is_never_purged(): void
    {
        $expense = $this->issueSheet();
        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();
        DB::table('expenses')->where('id', $expense->id)->update(['deleted_at' => now()->subYear()]);

        $plain = Expense::create(['business_id' => $this->shop->id, 'type' => 'ماء', 'amount' => 4, 'method' => 'نقدي',
            'spent_at' => '2026-01-05', 'status' => Expense::UNPAID]);
        $plain->delete();
        DB::table('expenses')->where('id', $plain->id)->update(['deleted_at' => now()->subYear()]);

        $this->delete(route('admin.expenses.purge', $expense->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSoftDeleted($expense);

        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertSoftDeleted($expense);
        $this->assertDatabaseMissing('expenses', ['id' => $plain->id]);
        $this->assertSame(0.0, $this->owed());
    }

    /** والحارسُ على النموذج نفسه: كودٌ يحذفه مباشرةً — ناعمًا أو نهائيًّا — يُردّ ولا يمسّ شيئًا */
    public function test_code_that_deletes_a_sheets_expense_directly_is_refused(): void
    {
        $expense = $this->issueSheet();

        foreach (['delete', 'forceDelete'] as $how) {
            try {
                $expense->fresh()->{$how}();
                $this->fail("{$how} حذف مصروفَ الورقة");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($expense->reference, $e->getMessage());
            }
        }

        $this->assertNotSoftDeleted($expense);
        $this->assertSame(23.0, $this->owed());
    }

    public function test_an_ordinary_expense_is_deleted_as_before(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 40, 'spent_at' => '2027-02-10', 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();
        $rent = Expense::where('business_id', $this->shop->id)->latest('id')->firstOrFail();

        $this->delete(route('admin.expenses.destroy', $rent->id))->assertSessionHas('toast.type', 'warning');

        $this->assertSoftDeleted($rent);
        $this->assertCount(0, Books::liveEntriesFor($rent));
        $this->assertBalanced();
    }

    /** ومتجرٌ لا يقرأ ورقةَ متجرٍ آخر ولا يمسّ مصروفها */
    public function test_another_shops_sheet_is_neither_read_nor_reached(): void
    {
        $expense = $this->issueSheet();
        $this->post(route('admin.expenses.paid', $expense->id))->assertSessionHasNoErrors();

        $neighbour = $this->shop('الجار');
        $this->assertSame(0.0, (float) BoutiqueDues::ledgerFor($neighbour->id, $this->boutique->id)['standalone_unpaid']);

        $stranger = User::create(['business_id' => $neighbour->id, 'name' => 'الجار', 'email' => 'n@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($stranger)->delete(route('admin.expenses.destroy', $expense->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.expenses.paid', $expense->id))->assertNotFound();

        $this->assertNotSoftDeleted($expense);
        $this->assertSame(0.0, $this->owed());
    }
}
