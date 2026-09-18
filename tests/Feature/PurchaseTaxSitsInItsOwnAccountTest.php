<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalLine;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ضريبةُ الشراء في حسابها — لا في حساب ضريبة البيع.
 *
 * ═══ العطب ═══
 *
 * كانت ضريبةُ سند المورّد تُقيَّد **مدينةً** في «ضريبة مستحقّة» (2300)
 * نفسِها التي تُقيَّد فيها ضريبةُ المبيعات دائنة. والصافي صحيحٌ محاسبيًّا —
 * هو ما يُدفع للجهاز فعلًا — لكنّ الحساب الواحد لا يقول منه شيئًا: من فتح
 * الميزانية قرأ رقمًا لا يعرف أهو ما حصّله من زبائنه أم ما دفعه لمورّديه،
 * ولا يطابق سطرًا واحدًا في الإقرار الذي يقسمهما.
 *
 * وهما في طبيعتهما شيئان لا شيء: المخرجاتُ مالُ الجهاز في يدك — خصم،
 * والمدخلاتُ مالُك عند الجهاز — أصل. لا طرفا حسابٍ واحد.
 *
 * ═══ وما لا يتغيّر ═══
 *
 * الإقرارُ نفسه لم يكن يقرأ الحساب أصلًا: `ReportData::vat` تبنيه من الطلبات
 * وسندات المورّدين. فالفصلُ لا يغيّر رقمًا يُقدَّم للجهاز، يغيّر ما تراه
 * الميزانية — وهو ما تحرسه `AStockLossIsWrittenInBothBooksTest`.
 */
class PurchaseTaxSitsInItsOwnAccountTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_19_091000_input_tax_leaves_the_payable_account.php';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function approve(float $subtotal = 100, float $tax = 5): SupplierInvoice
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);

        $invoice = SupplierInvoice::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'supplier_ref' => 'R-'.fake()->unique()->numerify('####'),
            'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax,
            'approval_status' => SupplierInvoices::PENDING,
        ]);

        SupplierInvoices::approve($invoice, $this->owner);

        return $invoice->fresh();
    }

    private function balance(string $key): float
    {
        return Ledger::account($this->business->id, $key)->balance();
    }

    /* ───────────────────────── الشجرة ───────────────────────── */

    /** حسابُ المدخلات في الشجرة الافتراضيّة — أصلٌ مدين، لا خصمٌ دائن */
    public function test_the_chart_carries_an_input_tax_account(): void
    {
        $account = Ledger::account($this->business->id, 'tax_input');

        $this->assertNotNull($account, 'لا حسابَ لضريبة المشتريات فتعود إلى حساب المبيعات');
        $this->assertSame('أصل', $account->type, 'ضريبةٌ تُستردّ قُيّدت خصمًا');
        $this->assertSame('debit', $account->normal_side);
    }

    /** وشجرةٌ بُنيت قبل هذه النسخة تستدركه */
    public function test_an_older_chart_gains_it(): void
    {
        Account::where('business_id', $this->business->id)->where('system_key', 'tax_input')->delete();

        Ledger::ensureSystemAccounts($this->business->id);

        $this->assertNotNull(Ledger::account($this->business->id, 'tax_input'));
    }

    /* ───────────────────────── الترحيل ───────────────────────── */

    /** سندُ مورّدٍ معتمَد يُقيّد ضريبتَه في «المدخلات» */
    public function test_an_approved_invoice_debits_input_tax(): void
    {
        $this->approve(100, 5);

        $this->assertSame(5.0, $this->balance('tax_input'), 'ضريبةُ الشراء ليست في حسابها');
        $this->assertSame(0.0, $this->balance('tax_payable'), 'ضريبةُ شراءٍ جلست في حساب ضريبة البيع');
        $this->assertSame(100.0, $this->balance('inventory'));
        $this->assertSame(105.0, $this->balance('payable'));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /**
     * والحسابان لا يختلطان: بيعٌ وشراءٌ معًا يقرأ كلٌّ منهما شقَّه.
     *
     * وهذا هو الفرق كلُّه: قبلها كان الحسابُ الواحد يقول ٥ — وهي ١٠ مخرجاتٍ
     * ناقص ٥ مدخلات — فلا يُعرف الشقّان.
     */
    public function test_output_and_input_are_read_apart(): void
    {
        $this->approve(100, 5);

        Ledger::post($this->business->id, 'بيع', [
            ['account' => 'cash', 'debit' => 210],
            ['account' => 'sales', 'credit' => 200],
            ['account' => 'tax_payable', 'credit' => 10],
        ]);

        $this->assertSame(10.0, $this->balance('tax_payable'), 'المخرجاتُ لا تُقرأ وحدها');
        $this->assertSame(5.0, $this->balance('tax_input'), 'المدخلاتُ لا تُقرأ وحدها');
        $this->assertSame(5.0, round($this->balance('tax_payable') - $this->balance('tax_input'), 3));
    }

    /** وغيرُ المسجَّل لا ضريبةَ مدخلاتٍ له — الضريبةُ عنده ثمنٌ لا يُستردّ */
    public function test_an_unregistered_shop_posts_no_input_tax(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '0'],
        );

        $this->approve(100, 5);

        $this->assertSame(0.0, $this->balance('tax_input'));
        $this->assertSame(105.0, $this->balance('inventory'), 'ضريبةٌ لا تُستردّ خرجت من تكلفة البضاعة');
    }

    /** وإلغاءُ السند يردّها كما جاءت — من حسابها هي */
    public function test_reversing_the_invoice_unwinds_the_input_tax(): void
    {
        $invoice = $this->approve(100, 5);

        SupplierInvoices::cancel($invoice, 'خطأ في السند', $this->owner);

        $this->assertSame(0.0, $this->balance('tax_input'));
        $this->assertSame(0.0, $this->balance('payable'));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /* ───────────────────────── إعادةُ التصنيف ───────────────────────── */

    /** ما رُحّل قديمًا إلى «المستحقّة» يُنقل بقيدٍ واحد — ولا تُمسّ سطورُه */
    public function test_the_migration_reclassifies_what_was_posted_before(): void
    {
        $this->approve(100, 5);

        // شكلُ ما قبل الإصلاح: السطرُ الضريبيُّ مدينًا في حساب ضريبة البيع
        JournalLine::where('account_id', Ledger::account($this->business->id, 'tax_input')->id)
            ->update(['account_id' => Ledger::account($this->business->id, 'tax_payable')->id]);

        $this->assertSame(0.0, $this->balance('tax_input'));
        $this->assertSame(-5.0, $this->balance('tax_payable'), 'الحالة قبل الترحيل ليست حالة الإنتاج');

        (require base_path(self::MIGRATION))->up();

        $this->assertSame(5.0, $this->balance('tax_input'), 'لم يُنقل ما رُحّل قديمًا');
        $this->assertSame(0.0, $this->balance('tax_payable'));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /**
     * ولا يُنقل مرّتين إن أُعيد الترحيل على قاعدةٍ نصفَ محدَّثة.
     *
     * وقيدُ النقل نفسُه لا مستندَ له، فلا يُنقص الصافيَ المقيس: من قاسه
     * بالمستندات وحدها وأعاد التشغيل نقل المبلغ مرّتين — فصار في «المدخلات»
     * ضِعفُ ما دُفع، وفي «المستحقّة» دَينٌ لا وجود له.
     */
    public function test_the_migration_is_idempotent(): void
    {
        $this->approve(100, 5);

        // شكلُ ما قبل الإصلاح: السطرُ الضريبيُّ مدينًا في حساب ضريبة البيع
        JournalLine::where('account_id', Ledger::account($this->business->id, 'tax_input')->id)
            ->update(['account_id' => Ledger::account($this->business->id, 'tax_payable')->id]);

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertSame(5.0, $this->balance('tax_input'), 'نُقل المبلغُ مرّتين');
        $this->assertSame(0.0, $this->balance('tax_payable'));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }
}
