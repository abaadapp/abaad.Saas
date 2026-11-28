<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\FinancialCorrection;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\FinancialCorrections;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * معتمدان يبتّان في الطلب نفسه في اللحظة نفسها — على PostgreSQL باتّصالين حقيقيّين.
 *
 * الاعتمادُ والرفضُ يقفلان صفَّ الطلب أوّلًا (`FinancialCorrections::lockPending`) ثمّ
 * يسألان: أما زال معلّقًا؟ فالثاني ينتظر الأوّل، ثمّ يجده قد بُتّ فيه فيُردّ — لا
 * يُنفَّذ الطلبُ مرّتين ولا يُعتمد بعد رفضه. وSQLite لا تُبقي معاملتين مفتوحتين معًا،
 * فيُتخطّى عليها.
 */
class TwoApproversOfOneCorrectionAtOnceDecideItOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    private User $first;

    private User $second;

    private FinancialCorrection $row;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        Carbon::setTestNow('2025-09-20 12:00:00');
        app()->setLocale('ar');
        $this->shop = Business::create(['name' => 'ورود المعتمدَين', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $user = fn (string $role, string $email, ?array $perms = null) => User::create([
            'business_id' => $this->shop->id, 'name' => $role.' '.$email, 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $perms,
        ]);
        $this->owner = $user('admin', 'owner@two-approvers.om');
        $approve = ['dashboard', 'orders', Permissions::FINANCIAL_CORRECTION_APPROVE];
        $this->first = $user('accountant', 'first@two-approvers.om', $approve);
        $this->second = $user('accountant', 'second@two-approvers.om', $approve);

        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة', 'phone' => '91234567', 'allow_credit_sales' => true]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $customer, ['issued_at' => '2025-09-10', 'branch_id' => $branch->id], [
            ['description' => 'توريد', 'quantity' => 10, 'unit_price' => 10],
        ], $this->owner->id), $this->owner->id);

        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => FinancialCorrections::SETTING], ['value' => '1']);
        $this->row = FinancialCorrections::requestCreditNote($invoice, [
            'amount' => 20, 'tax_amount' => 0, 'reason' => 'خصمٌ متّفق عليه', 'basis' => CustomerCreditNote::ERROR, 'token' => 'race-1',
        ], $this->owner);
    }

    /**
     * المعتمدُ الآخر: يقفل صفَّ الطلب ويبتّ فيه ويمسكه ثانيةً ونصفًا.
     *
     * @return resource
     */
    private function theOtherDecides(string $status, float $hold = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = sprintf(
            'SELECT id FROM financial_corrections WHERE id = %1$d FOR UPDATE;'
            ." UPDATE financial_corrections SET status = '%2\$s', decided_by = %3\$d, decided_at = now(), decision_note = 'المعتمد الآخر' WHERE id = %1\$d;",
            $this->row->id, $status, $this->second->id,
        );
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($hold * 1_000_000),
        );
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل المعتمد الآخر');
        usleep(600_000);

        return $proc;
    }

    public function test_an_approval_waiting_behind_a_rejection_finds_it_decided_and_issues_nothing(): void
    {
        $other = $this->theOtherDecides(FinancialCorrection::REJECTED);

        $started = microtime(true);
        try {
            FinancialCorrections::approve($this->row, $this->first);
            $this->fail('اعتُمد طلبٌ رُفض في اللحظة نفسها');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('بُتّ', $e->getMessage());
        }
        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($other), 'سقط المعتمدُ الآخر');

        $this->assertGreaterThan(0.5, $waited, 'لم ينتظر الاعتمادُ قفلَ الطلب');
        $this->assertSame([FinancialCorrection::REJECTED, 0], [$this->row->fresh()->status, CustomerCreditNote::count()]);
        $this->assertSame(CustomerInvoice::ISSUED, CustomerInvoice::sole()->status);
    }

    public function test_a_rejection_waiting_behind_an_approval_finds_it_decided_and_changes_nothing(): void
    {
        $other = $this->theOtherDecides(FinancialCorrection::APPROVED);

        $started = microtime(true);
        try {
            FinancialCorrections::reject($this->row, $this->first, 'لا يصحّ هذا الخصم');
            $this->fail('رُفض طلبٌ اعتُمد في اللحظة نفسها');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('بُتّ', $e->getMessage());
        }
        $this->assertSame(0, proc_close($other), 'سقط المعتمدُ الآخر');
        $this->assertGreaterThan(0.5, microtime(true) - $started, 'لم ينتظر الرفضُ قفلَ الطلب');
        $this->assertSame([FinancialCorrection::APPROVED, (int) $this->second->id], [$this->row->fresh()->status, (int) $this->row->fresh()->decided_by]);
    }
}
