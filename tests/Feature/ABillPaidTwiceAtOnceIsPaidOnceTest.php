<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فاتورةٌ تُسدَّد بضغطتين متزامنتين تُسدَّد مرّةً واحدة — في الدفتر وفي الصندوق.
 *
 * ═══ ما ظهر في تجربة تزامنٍ حقيقيّة ═══
 *
 * ثلاثة طلباتٍ معًا على «سُدِّدت» لفاتورةٍ واحدة على PostgreSQL: قيدٌ واحد في
 * الدفتر (يحرسه `recordExpense`)، لكن **ثلاثُ حركاتٍ** في دفتر الصندوق
 * بتسعين لكلٍّ منها. فشاشةُ المالية والمطابقةُ البنكيّة تقرآن ٢٧٠ خرجت،
 * والدفترُ ٩٠. والسبب أنّ «أهي مسدَّدة؟» تُسأل قبل المعاملة وبلا قفل: كلُّ
 * طلبٍ يجدها غيرَ مسدَّدة، فيكتب حركتَه.
 *
 * والتزامنُ لا يُصنع في اختبار — فيُصنع ترتيبُه: الطلبُ الآخر يفوز بين
 * قراءة هذا الطلب للفاتورة ودخولِه المعاملة.
 */
class ABillPaidTwiceAtOnceIsPaidOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payment_that_lost_the_race_writes_no_second_cash_row(): void
    {
        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        $this->actingAs(User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]));

        $this->post(route('admin.expenses.store'), [
            'type' => 'كهرباء', 'amount' => 90, 'status' => 'غير مدفوع', 'due_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();
        $bill = Expense::sole();

        // الطلبُ الآخر يسدّدها كاملةً بعد أن قرأها هذا الطلبُ غيرَ مسدَّدة
        $won = false;
        DB::listen(function (QueryExecuted $q) use (&$won, $bill) {
            if ($won || ! str_starts_with($q->sql, 'select * from "expenses"')) {
                return;
            }
            $won = true;

            DB::table('expenses')->where('id', $bill->id)->update(['status' => Expense::PAID, 'spent_at' => now()]);
            $winner = Transaction::create([
                'business_id' => $bill->business_id, 'reference' => Transaction::nextReference($bill->business_id),
                'description' => $bill->type, 'method' => 'نقدي', 'type' => 'مصروف', 'amount' => 90, 'occurred_at' => now(),
            ]);
            DB::table('expenses')->where('id', $bill->id)->update(['transaction_id' => $winner->id]);
            Books::recordExpense($bill->fresh());
        });

        $this->post(route('admin.expenses.paid', $bill->id))->assertSessionHasNoErrors();

        $this->assertTrue($won, 'لم يقع الترتيبُ المقصود');
        $this->assertSame(1, Transaction::where('business_id', $business->id)->where('type', 'مصروف')->count(),
            'سُدِّدت الفاتورةُ مرّتين في الصندوق');
        $this->assertSame(90.0, Ledger::operatingExpenses($business->id));
    }
}
