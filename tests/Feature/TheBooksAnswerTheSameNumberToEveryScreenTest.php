<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\PurchaseOrderTotals;
use App\Support\SaleLines;
use App\Support\SupplierInvoices;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الدفترُ يقول الرقمَ نفسَه لكلّ شاشةٍ تسأله — ولا يسقط حين يُسأل مرّتين معًا.
 *
 * ═══ ما يجمع هذا الملفّ ═══
 *
 * المالُ يُقرأ من مواضعَ كثيرة، ولكلّ موضعٍ إغراءٌ بأن يحسب لنفسه. وثلاثةُ
 * أعطابٍ من نوعٍ واحد وقعت لذلك:
 *
 *  • **تعريفٌ يُكتب مرّتين فيفترق.** نسبةُ الضريبة مُعرَّفةٌ في `Vat::rate`،
 *    ونُسخت حرفًا بحرفٍ في `SaleLines`، ونُسخت ناقصةً في المشتريات — فباع
 *    المتجرُ بعشرةٍ واشترى بخمسة.
 *
 *  • **شرطٌ يُصلَح في شاشةٍ ويُنسى في أختها.** «المعتمَدُ وحدَه دَين» أُصلح
 *    في سجلّ المشتريات، وبقيت شاشتا المالية تجمعان المرفوضَ والملغى.
 *
 *  • **حملٌ ينمو بالصفوف.** استعلامٌ لكلّ قيدٍ في اليوميّة، واستعلامان لكلّ
 *    حسابٍ بنكيّ في شاشتين.
 *
 * ═══ ورابعٌ يسقط في وجه الكاشير ═══
 *
 * رقمُ القيد كان يُقرأ بلا قفل، و`(business_id, number)` فريدٌ في القاعدة:
 * صندوقان يبيعان في اللحظة نفسها ⇒ أحدُهما يُردّ بخطأ خادم. وقيسَ على
 * PostgreSQL حقيقيّ: **ستُّ كتاباتٍ متزامنة، واحدةٌ تمرّ وخمسٌ تسقط** —
 * وبعد القفل اثنتا عشرةَ كتابةً كلُّها تمرّ بأرقامٍ فريدةٍ متتابعة.
 *
 * والسباقُ نفسُه لا يُقاس هنا: يحتاج عمليّاتٍ متوازيةً على PostgreSQL، وهذه
 * الشجرةُ تختبر على SQLite. فيُحرَس **حاملُ القفل**: أنّ صفَّ المتجر يُقرأ
 * قبل الترقيم. وSQLite تُسقط عبارةَ `for update` من الاستعلام أصلًا، فلا
 * يُفتَّش عنها في النصّ.
 */
class TheBooksAnswerTheSameNumberToEveryScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function bid(): int
    {
        return (int) $this->business->id;
    }

    private function queriesOn(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    private function entries(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Ledger::post($this->bid(), 'قيد '.$i, [
                ['account' => 'cash', 'debit' => 5],
                ['account' => 'sales', 'credit' => 5],
            ]);
        }
    }

    /* ───────────── أوّلًا: رقمُ القيد ───────────── */

    /**
     * صفُّ المتجر يُقرأ قبل أن يُقطَع الرقم — وهو حاملُ القفل.
     *
     * ولا يُفتَّش عن «for update» في نصّ الاستعلام: SQLite تُسقطها. فيُسأل
     * عن القراءة نفسِها، وهي لا تقع إلّا من أجل القفل — لا يُقرأ من صفّ
     * المتجر حرفٌ واحد هنا.
     */
    public function test_the_journal_number_is_cut_under_a_lock(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        JournalEntry::nextNumber($this->bid());
        $sql = array_map(fn ($q) => $q['query'], DB::getQueryLog());
        DB::disableQueryLog();

        $read = array_values(array_filter($sql, fn ($q) => str_contains($q, 'from "businesses"')));

        $this->assertNotSame([], $read, "لم يُقرأ صفُّ المتجر، فلا قفلَ يحمل الترقيم:\n".implode("\n", $sql));
    }

    /** والأعلى عددًا لا الأحدثُ صفًّا: قيدٌ يدخل بأثرٍ رجعيّ لا يُعيد التسلسل إلى رقمٍ استُعمل */
    public function test_the_journal_number_follows_the_highest_not_the_newest_row(): void
    {
        $row = fn (string $number) => DB::table('journal_entries')->insert([
            'business_id' => $this->bid(), 'number' => $number, 'entry_date' => now()->toDateString(),
            'description' => $number, 'source' => 'يدوي', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row('JV-000900');
        $row('JV-000002');

        $this->assertSame('JV-000901', JournalEntry::nextNumber($this->bid()));
    }

    /* ───────────── ثانيًا: نسبةٌ واحدة ───────────── */

    /**
     * المبيعاتُ والمشتريات تقرآن النسبةَ نفسَها — ولو لم يكتبها المتجر.
     *
     * وافتراضيُّ المنصّة هو موضعُ الافتراق: `Vat::rate` تقرؤه، والمشترياتُ
     * كانت تتخطّاه إلى خمسة. ومتجرٌ لم يلمس نسبتَه — وهو حالُ أكثرهم — كان
     * يبيع بواحدةٍ ويشتري بأخرى.
     */
    public function test_one_shop_reads_one_vat_rate_everywhere(): void
    {
        Setting::create(['business_id' => null, 'key' => 'vat_rate', 'value' => '10']);

        $this->assertSame(10.0, Vat::rate($this->bid()), 'التعريف');
        $this->assertSame(10.0, (new SaleLines($this->bid()))->vatRate(), 'سطور البيع');
        $this->assertSame(10.0, PurchaseOrderTotals::taxRateFor($this->bid()), 'المشتريات');
    }

    /** والإطفاء يسبقها في المواضع الثلاثة */
    public function test_a_shop_that_switched_vat_off_reads_zero_everywhere(): void
    {
        Setting::create(['business_id' => null, 'key' => 'vat_rate', 'value' => '10']);
        Setting::create(['business_id' => $this->bid(), 'key' => 'vat_enabled', 'value' => '0']);

        $this->assertSame(0.0, Vat::rate($this->bid()));
        $this->assertSame(0.0, (new SaleLines($this->bid()))->vatRate());
        $this->assertSame(0.0, PurchaseOrderTotals::taxRateFor($this->bid()));
    }

    /* ───────────── ثالثًا: ما عليك هو ما في الدفتر ───────────── */

    private function bill(string $ref, string $status, float $total = 300): SupplierInvoice
    {
        $supplier = Supplier::firstOrCreate(
            ['business_id' => $this->bid(), 'name' => 'مزرعة الورد'],
        );

        return SupplierInvoice::create([
            'business_id' => $this->bid(), 'supplier_id' => $supplier->id, 'supplier_ref' => $ref,
            'issued_at' => now(), 'due_at' => now()->subDay(), 'subtotal' => $total, 'tax' => 0,
            'total' => $total, 'paid' => 0, 'approval_status' => $status,
        ]);
    }

    /**
     * سندٌ مرفوضٌ أو ملغًى أو لم يوقَّع بعد ليس دَينًا — في الشاشتين معًا.
     *
     * ولا يُحذف الصفّ: الورقةُ وصلت فعلًا وتبقى مقروءةً في المشتريات. وهي
     * وحدَها ليست دَينًا، ولا يقبل بابُ السداد سدادَها.
     */
    public function test_only_an_approved_supplier_bill_is_money_owed(): void
    {
        $this->bill('DUP-1', SupplierInvoices::REJECTED);
        $this->bill('OLD-1', SupplierInvoices::CANCELLED);
        $this->bill('NEW-1', SupplierInvoices::PENDING);
        $this->bill('OK-1', SupplierInvoices::APPROVED, 120);

        $dues = $this->actingAs($this->owner)->get('/admin/finance/dues')
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(['OK-1'], array_column($dues['invoices'], 'reference'));
        $this->assertSame(120.0, $dues['totals']['invoices']);
        $this->assertSame(1, $dues['totals']['overdue'], 'عُدّت أوراقٌ ليست دَينًا متأخّرةً');

        $summary = $this->actingAs($this->owner)->get('/admin/finance/summary')
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(120.0, $summary['dues']['invoices'], 'الملخّصُ يقول غيرَ ما تقوله المستحقّات');
        $this->assertSame(4, SupplierInvoice::count(), 'حُذفت ورقةٌ وصلت — والحلُّ ألّا تُجمع لا أن تُمحى');
    }

    /* ───────────── رابعًا: ولا شاشةَ تكبر بصفوفها ───────────── */

    /**
     * والقياسُ فرقٌ لا رقمٌ مطلق: قاعدةُ اللوحة مشتركةٌ وتتبدّل، وما يُحرَس
     * هنا أنّ العددَ **لا يتغيّر** حين تتضاعف الصفوف.
     */
    public function test_no_finance_screen_costs_more_because_it_has_more_rows(): void
    {
        $screens = [
            '/admin/finance', '/admin/finance/journal', '/admin/finance/chart',
            '/admin/finance/transactions', '/admin/finance/summary', '/admin/finance/dues',
            '/admin/finance/cheques', '/admin/finance/receivables', '/admin/finance/assets',
            '/admin/expenses',
        ];

        foreach ($screens as $url) {
            $this->queriesOn($url);
        }

        $this->entries(3);
        $few = [];
        foreach ($screens as $url) {
            $few[$url] = $this->queriesOn($url);
        }

        $this->entries(15);
        $grew = [];
        foreach ($screens as $url) {
            $now = $this->queriesOn($url);
            if ($now !== $few[$url]) {
                $grew[] = $url.': '.$few[$url].' ← '.$now;
            }
        }

        $this->assertSame([], $grew, "نمت بعدد القيود:\n".implode("\n", $grew));
    }

    /** وحسابٌ بنكيٌّ ثانٍ لا يفتح استعلامين — لا في شاشته ولا في الملخّص */
    public function test_a_second_bank_account_does_not_cost_two_more_queries(): void
    {
        $open = fn (int $i) => $this->actingAs($this->owner)->post('/admin/finance/banks', [
            'name' => 'بنك '.$i, 'bank_name' => 'مسقط', 'account_number' => '100'.$i,
            'opening_balance' => 0,
        ])->assertSessionHasNoErrors();

        $screens = ['/admin/finance', '/admin/finance/summary'];
        foreach ($screens as $url) {
            $this->queriesOn($url);
        }

        $open(0);
        $open(1);
        $this->assertSame(2, BankAccount::count(), 'لم يُفتح حسابٌ بنكيّ — الحارسُ يقيس فراغًا');

        $few = [];
        foreach ($screens as $url) {
            $few[$url] = $this->queriesOn($url);
        }

        for ($i = 2; $i < 10; $i++) {
            $open($i);
        }
        $this->assertSame(10, BankAccount::count());

        $grew = [];
        foreach ($screens as $url) {
            $now = $this->queriesOn($url);
            if ($now !== $few[$url]) {
                $grew[] = $url.': '.$few[$url].' ← '.$now;
            }
        }

        $this->assertSame([], $grew, "نمت بعدد الحسابات البنكية:\n".implode("\n", $grew));
    }

    /** والمستحقّاتُ لا تكبر بعدد أوراقها */
    public function test_the_dues_screen_does_not_grow_with_its_papers(): void
    {
        $paper = function (int $i) {
            $this->bill('B'.$i, SupplierInvoices::APPROVED, 10);
            Expense::create([
                'business_id' => $this->bid(), 'type' => 'إيجار', 'amount' => 5,
                'spent_at' => now(), 'status' => 'غير مدفوع',
            ]);
        };

        $paper(0);
        $paper(1);
        $this->queriesOn('/admin/finance/dues');
        $few = $this->queriesOn('/admin/finance/dues');

        for ($i = 2; $i < 12; $i++) {
            $paper($i);
        }

        $this->assertSame($few, $this->queriesOn('/admin/finance/dues'), 'المستحقّات تنمو بعدد المستندات');
    }

    /* ───────────── خامسًا: ولا حقلَ يُنادى باسمه البرمجيّ ───────────── */

    /**
     * كلُّ حقلٍ في شاشات المالية له اسمٌ عربيّ.
     *
     * ═══ ولمَ حارسٌ يمشي على الملفّات ═══
     *
     * التاجرُ كان يُردّ بـ«حقل purchased at مطلوب» — اسمُ عمودٍ في قاعدة
     * البيانات في جملةٍ عربيّة. والخريطةُ موجودةٌ من قبل في
     * `lang/ar/validation.php`، وإنّما تُنسى عند كلّ حقلٍ جديد.
     *
     * فالحارسُ يقرأ نداءات `validate` نفسَها لا قائمةً أكتبها بيدي: قائمةٌ
     * تحرس ما تذكّرتُه، وهذا يحرس **الحقلَ القادم** الذي يُضاف غدًا بلا
     * اسم. ويقبل الاسمَ المُمرَّر في نداء `validate` مباشرةً كما يقبل
     * الخريطة — كلاهما يُخرج جملةً عربيّة.
     */
    public function test_every_finance_field_has_an_arabic_name(): void
    {
        $names = (require base_path('lang/ar/validation.php'))['attributes'];

        $files = [
            'Finance/BankAccountController', 'Finance/ChartController', 'Finance/FixedAssetController',
            'Finance/JournalController', 'Finance/OverviewController', 'FinanceController',
            'ExpenseController', 'ExpenseTypeController', 'ChequeController',
            'BankStatementController', 'ReceivablesController',
        ];

        $nameless = [];

        foreach ($files as $file) {
            $path = app_path('Http/Controllers/Admin/'.$file.'.php');

            $this->assertFileExists($path, 'مسارُ متحكّمٍ تبدّل — والحارسُ يمشي على ملفّاتٍ لا وجود لها');

            $source = (string) file_get_contents($path);

            preg_match_all(
                "/'([a-z0-9_.*]+)'\s*=>\s*\[\s*'(?:required|nullable|sometimes|boolean|array|integer|numeric|string|date|file|image|in:)/",
                $source,
                $found,
            );

            foreach (array_unique($found[1]) as $field) {
                if (isset($names[$field])) {
                    continue;
                }

                // اسمٌ مُمرَّر في نداء `validate` نفسِه — وهو كافٍ
                if (preg_match("/'".preg_quote($field, '/')."'\s*=>\s*__\(/", $source)) {
                    continue;
                }

                $nameless[] = $file.' › '.$field;
            }
        }

        $this->assertSame([], $nameless, "حقولٌ تُنادى باسمها البرمجيّ في وجه التاجر:\n".implode("\n", $nameless));
    }
}
