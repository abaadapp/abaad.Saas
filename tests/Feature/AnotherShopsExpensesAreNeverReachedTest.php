<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TrashController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مصروفاتُ متجرٍ آخر لا تُبلغ من هنا — لا حذفٌ ولا استعادةٌ ولا سدادٌ ولا نطاقٌ ولا محو، ولا تُرى.
 *
 * كلُّ بابٍ مسّه هذا الإصلاح يقرأ المصروفَ ثانيةً تحت قفل، والقراءةُ الثانية
 * بابٌ ثانٍ: إن نسيت `business_id` مرّةً واحدة صار معرّفُ مصروفِ الجار
 * يفتح دفترَه. فالمتجران هنا متماثلان، وكلُّ فعلٍ يُجرَّب بمعرّفات الآخر،
 * ثمّ يُقرأ دفترُ الآخر — قيدًا قيدًا — كما كان.
 */
class AnotherShopsExpensesAreNeverReachedTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');

        [$this->mine, $me] = $this->shop('متجري', 'me@abaad.om');
        [$this->theirs, $them] = $this->shop('الجار', 'them@abaad.om');

        // دفترُ الجار يُبنى بيده: مصروفٌ مدفوع، وفاتورةٌ لم تُدفع، ومحذوفٌ بحذفٍ قديم
        $this->actingAs($them);
        $this->spend(['spent_at' => '2026-09-15']);
        $this->spend(['type' => 'كهرباء', 'amount' => 90, 'status' => 'غير مدفوع', 'due_date' => '2026-11-01']);
        $this->spend(['spent_at' => '2026-10-02']);
        $old = Expense::where('business_id', $this->theirs->id)->latest('id')->firstOrFail();
        $old->transaction()->delete();
        $old->delete();
        $this->spend(['spent_at' => '2026-10-03']);
        $this->delete(route('admin.expenses.destroy', Expense::where('business_id', $this->theirs->id)->latest('id')->value('id')))
            ->assertSessionHasNoErrors();
        Setting::create(['business_id' => $this->theirs->id, 'key' => BooksPeriod::SETTING, 'value' => '2026-08-31']);

        $this->actingAs($me);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{Business, User} */
    private function shop(string $name, string $email): array
    {
        $b = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return [$b, User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ])];
    }

    private function spend(array $over = []): void
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => 300, 'method' => 'نقدي',
        ], $over))->assertSessionHasNoErrors();
    }

    /** دفترُ الجار كلُّه — ما يتغيّر لو بلغه شيءٌ من هنا */
    private function theirBooks(): array
    {
        $bid = $this->theirs->id;

        return [
            'entries' => JournalEntry::where('business_id', $bid)->orderBy('id')->get(['id', 'entry_date', 'reversed_at', 'reverses_id'])->toArray(),
            'expenses' => Expense::withTrashed()->where('business_id', $bid)->orderBy('id')->get(['id', 'status', 'deleted_at', 'branch_id'])->toArray(),
            'cash' => Transaction::withTrashed()->where('business_id', $bid)->orderBy('id')->get(['id', 'deleted_at', 'amount'])->toArray(),
            'opex' => Ledger::operatingExpenses($bid),
        ];
    }

    public function test_every_door_this_fix_touched_refuses_another_shops_expense(): void
    {
        $before = $this->theirBooks();
        $ids = Expense::withTrashed()->where('business_id', $this->theirs->id)->pluck('id');
        $why = ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => 'تجربة'];
        $myBranch = Branch::where('business_id', $this->mine->id)->value('id');

        foreach ($ids as $id) {
            $this->delete(route('admin.expenses.destroy', $id), $why)->assertNotFound();
            $this->post(route('admin.expenses.restore', $id), $why)->assertNotFound();
            $this->delete(route('admin.expenses.purge', $id))->assertNotFound();
            $this->post(route('admin.expenses.paid', $id))->assertNotFound();
            $this->put(route('admin.expenses.scope', $id), ['scope' => 'branch', 'branch_id' => $myBranch])->assertNotFound();
        }

        $this->assertSame($before, $this->theirBooks(), 'بلغ فعلٌ من هنا دفترَ متجرٍ آخر');
    }

    public function test_the_trash_shows_only_this_shops_expenses(): void
    {
        $this->assertSame([], collect(TrashController::panelData()['expenses'])->pluck('id')->all());

        // والأمرُ الليليّ يمرّ على المتاجر كلّها ولا يخلط حكمَ أحدها بالآخر
        Carbon::setTestNow(now()->addDays(TrashController::WINDOW_DAYS + 1));
        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertSame(2, Expense::onlyTrashed()->where('business_id', $this->theirs->id)->count(),
            'مُحي دليلُ متجرٍ آخر');
    }

    public function test_another_shops_closing_date_does_not_open_or_close_this_one(): void
    {
        $this->spend(['spent_at' => '2026-09-20']);
        $e = Expense::where('business_id', $this->mine->id)->latest('id')->firstOrFail();

        // أقفل الجارُ دفاترَه حتى أغسطس — فسبتمبرُه مفتوحٌ معلوم، وسبتمبرُ هذا المتجر مجهول
        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => 'x'])
            ->assertSessionHasErrors('correction');
        $this->assertNotSoftDeleted($e);
        $this->assertSame(1, Books::liveEntriesFor($e)->count());
    }
}
