<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بطاقاتُ الملفّ هي بطاقاتُ شاشته — بالمفاتيح نفسِها وبالألفاظ نفسِها.
 *
 * ═══ ما كان يقع ═══
 *
 * `ReportDownloadController` كان يختار البطاقاتِ **بترتيب ظهور المفاتيح**
 * في `ReportData`: أوّلُ أربعةٍ غيرِ مصفوفة. ويسمّيها من قاموسٍ ثانٍ
 * (`LABELS`) كُتب بيدٍ إلى جانب أسماء الأعمدة. تخمينان، فافترقا:
 *
 *  • **الجرد** — الشاشة تُنهي بـ«صافي الفرق» والملفُّ بـ«قيمة الزيادة».
 *  • **الموظفون** — الشاشة «الأعلى مبيعًا» والملفُّ «عدد الموظفين».
 *  • **الهالك** — الشاشة تبدأ بالقيمة والملفُّ بالعدد.
 *  • **الضريبة** — مفاتيحُها لم تكن في القاموس أصلًا، و`__()` تردّ المفتاح
 *    حين لا ترجمة. فكانت ورقةُ الإقرار تحمل: **taxable — output —
 *    purchases — input**، وتحتها بسطرين جدولُها يسمّيها بالعربية. قِستُها.
 *
 * وأثقلُ منه أنّ **«الصافي المستحقّ» لم يكن يظهر**: هو الخامس، والتخمينُ
 * يقصّ عند الرابع — فالرقمُ الوحيد الذي يُدفع في إقرارٍ ضريبيّ غائبٌ عن
 * الورقة التي تُرفع.
 *
 * ═══ وما يقيسه هذا الملفّ ═══
 *
 * البطاقاتُ صارت مصرَّحةً في `ReportColumns::CARDS`. فيُقارَن المصرَّحُ
 * بما ترسمه الشاشة **من مصدر الشاشة نفسِه** — لا بقائمةٍ ثانيةٍ تُكتب هنا
 * وتفترق غدًا: تُقرأ `summary.<key>` من ملفّ الـTSX بترتيب ظهورها.
 */
class AFileSaysWhatItsScreenSaysTest extends TestCase
{
    use RefreshDatabase;

    /** شاشةُ كلّ تقرير — الاسم كما يمرّره `ReportPageController` */
    private const SCREENS = [
        'vat' => 'Vat', 'payments' => 'Payments', 'staff' => 'Staff', 'customers' => 'Customers',
        'finance' => 'Finance', 'expenses' => 'Expenses', 'bank' => 'Bank', 'orders' => 'Orders',
        'products' => 'Products', 'inventory' => 'Inventory', 'stocktake' => 'Stocktake',
        'purchases' => 'Purchases', 'suppliers' => 'Suppliers', 'activity' => 'Activity',
        'seasons' => 'Seasons', 'marketing' => 'Marketing', 'waste' => 'Waste',
    ];

    private Business $shop;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        // فرعٌ يشغل الرقم 1 ثمّ يزول — فلا يحمله شيء. وليس تزيينًا: على
        // PostgreSQL لا يتراجع العدّادُ مع تراجع المعاملة، فيعلو بين ملفٍّ
        // وآخر ولا يبقى صفٌّ برقم 1. فاختبارٌ يكتب `branch_id => 1` بيده
        // يمرّ على SQLite ويسقط على قاعدة الإنتاج بـ«مفتاحٌ غيرُ موجود»:
        // وقع فعلًا في CI على 8ef8d2e4، وأسقط ستّ حالاتٍ من هذا الملفّ.
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $gone = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعُ الجار']);

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $gone->forceDelete();   // حذفٌ ناعمٌ يُبقي الصفَّ، والمفتاحُ الأجنبيُّ يقنع به

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** متجرٌ باع واشترى وأتلف — لا محلٌّ فارغ تُقرأ بطاقاتُه أصفارًا */
    private function tradeOneDay(): void
    {
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'زبونة', 'phone' => '96890000001']);
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة ورد',
            'price' => 12.5, 'cost' => 5, 'quantity' => 30, 'alert_qty' => 3, 'active' => true]);
        $supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'مشتل']);
        $season = Season::create(['business_id' => $this->shop->id, 'name' => 'العيد',
            'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(20), 'active' => true]);

        $order = Order::create(['business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'customer_id' => $customer->id, 'customer_name' => 'زبونة', 'employee_name' => 'المالك',
            'user_id' => $this->owner->id, 'number' => 'INV-1', 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => 'نقدي',
            'subtotal' => 25, 'discount' => 0, 'tax' => 1.25, 'total' => 26.25, 'ordered_at' => now()]);

        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة ورد',
            'price' => 12.5, 'quantity' => 2, 'cost' => 5, 'total' => 25,
            'season_id' => $season->id, 'season_name' => 'العيد']);

        Transaction::create(['business_id' => $this->shop->id,
            'reference' => Transaction::nextReference($this->shop->id), 'description' => 'بيع',
            'method' => 'نقدي', 'type' => 'دخل', 'amount' => 26.25,
            'employee_name' => 'المالك', 'occurred_at' => now()]);

        Expense::create(['business_id' => $this->shop->id, 'description' => 'كهرباء',
            'type' => 'تشغيلية', 'amount' => 15, 'method' => 'نقدي',
            'employee_name' => 'المالك', 'spent_at' => now()]);

        SupplierInvoice::create(['business_id' => $this->shop->id, 'supplier_id' => $supplier->id,
            'supplier_ref' => 'SI-1', 'issued_at' => now(), 'subtotal' => 100, 'tax' => 5,
            'total' => 105, 'approval_status' => 'معتمد', 'status' => 'غير مدفوعة']);

        StockAdjustment::create(['business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'product_id' => $product->id, 'number' => 'SA-1', 'quantity_delta' => -2,
            'cost_at_time' => 5, 'reason' => 'تلف', 'created_by' => $this->owner->id,
            'adjusted_at' => now()]);

        Coupon::create(['business_id' => $this->shop->id, 'code' => 'EID',
            'type' => 'نسبة', 'value' => 10, 'active' => true]);
    }

    /**
     * مفاتيحُ البطاقات كما ترسمها الشاشة — تُقرأ من مصدرها لا تُكتب هنا.
     *
     * والقراءةُ من كتلة `stats` وحدها: `summary.x` تظهر في الجدول وفي شروط
     * اللون أيضًا، فقراءةُ الملفّ كلِّه تخلط بطاقةً بخليّة.
     *
     * @return list<string>
     */
    private function cardKeysOnScreen(string $report): array
    {
        $file = resource_path('js/Pages/Admin/Reports/'.self::SCREENS[$report].'.tsx');
        $this->assertFileExists($file, 'شاشةُ التقرير غير موجودة: '.$report);

        $source = file_get_contents($file);

        // كتلةُ البطاقات: من أوّل `label:` إلى آخر `StatCard`/نهاية المصفوفة
        preg_match_all("/label:\s*(?:t\()?'([^']+)'/u", $source, $labels);
        preg_match_all("/label:\s*(?:t\()?'[^']+'\)?,?\s*(?:\n\s*)?value:\s*([^\n]+)/u", $source, $values);

        $keys = [];
        foreach ($values[1] as $expression) {
            if (preg_match('/(?:summary|totals)\.(\w+)/u', $expression, $hit)) {
                $keys[] = $hit[1];
            } else {
                // بطاقةٌ لا تقرأ الملخّص (مقارنةٌ بمدّةٍ سابقة مثلًا)
                $keys[] = null;
            }
        }

        return $keys;
    }

    public function test_every_report_declares_its_cards(): void
    {
        $missing = [];

        foreach (Reports::ALL as $report) {
            $key = $report['key'];
            if (! ReportColumns::has($key) && ! ReportColumns::sectioned($key)) {
                continue;
            }
            if (($ReportColumnsCards = ReportColumns::CARDS[$key] ?? []) === []) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'تقاريرُ تُصدَّر بلا بطاقاتٍ مصرَّحة: '.implode(', ', $missing));
    }

    public function test_no_card_is_labelled_by_its_english_key(): void
    {
        $bad = [];

        foreach (ReportColumns::CARDS as $report => $cards) {
            foreach ($cards as $card) {
                if (! preg_match('/\p{Arabic}/u', $card[1])) {
                    $bad[] = $report.'.'.$card[0].' = '.$card[1];
                }
            }
        }

        $this->assertSame([], $bad, "بطاقاتٌ تُسمّى بمفتاحها:\n".implode("\n", $bad));
    }

    public function test_the_first_cards_of_a_file_are_the_cards_of_its_screen(): void
    {
        $off = [];

        foreach (ReportColumns::CARDS as $report => $cards) {
            $declared = array_column($cards, 0);
            $onScreen = array_values(array_filter(
                array_slice($this->cardKeysOnScreen($report), 0, count($declared)),
                fn ($k) => $k !== null,
            ));

            $expected = array_slice($declared, 0, count($onScreen));

            if ($expected !== $onScreen) {
                $off[] = $report.': الملفّ ['.implode(',', $expected).'] والشاشة ['.implode(',', $onScreen).']';
            }
        }

        $this->assertSame([], $off, "بطاقاتٌ تفترق عن شاشتها:\n".implode("\n", $off));
    }

    public function test_every_declared_card_key_exists_in_the_summary(): void
    {
        $this->tradeOneDay();
        $this->actingAs($this->owner);

        $missing = [];

        foreach (ReportColumns::CARDS as $report => $cards) {
            $summary = ReportData::$report($this->shop->id, ['range' => 'all'])['summary'] ?? [];

            foreach ($cards as $card) {
                foreach (array_filter([$card[0], $card[3][0] ?? null]) as $key) {
                    if (! array_key_exists($key, $summary)) {
                        $missing[] = $report.'.'.$key;
                    }
                }
            }
        }

        $this->assertSame([], $missing, "مفاتيحُ بطاقاتٍ لا يُنتجها التقرير:\n".implode("\n", $missing));
    }

    public function test_the_tax_return_carries_the_number_that_is_paid(): void
    {
        $this->tradeOneDay();
        $this->actingAs($this->owner);

        $summary = ReportData::vat($this->shop->id, ['range' => 'all'])['summary'];
        $labels = array_column(ReportColumns::cards('vat', $summary), 'label');

        $this->assertContains(__('الصافي المستحقّ'), $labels,
            'الرقمُ الوحيد الذي يُدفع في إقرارٍ ضريبيّ — كان يُقصّ عند الرابع');
    }

    public function test_the_tax_cards_and_its_table_call_things_by_one_name(): void
    {
        $this->tradeOneDay();
        $this->actingAs($this->owner);

        $summary = ReportData::vat($this->shop->id, ['range' => 'all'])['summary'];
        $cards = collect(ReportColumns::cards('vat', $summary))->pluck('label')->all();
        $columns = collect(ReportColumns::for('vat'))->pluck('label', 'key')->all();

        foreach (['taxable', 'output', 'input', 'due'] as $key) {
            $this->assertContains($columns[$key], $cards,
                'الورقةُ تسمّي المفتاح نفسَه اسمين: البطاقةُ غيرُ رأس العمود — '.$key);
        }
    }

    public function test_a_money_card_is_written_as_money_and_a_count_as_a_count(): void
    {
        $this->tradeOneDay();
        $this->actingAs($this->owner);

        $summary = ReportData::orders($this->shop->id, ['range' => 'all'])['summary'];
        $cards = collect(ReportColumns::cards('orders', $summary))->pluck('value', 'label')->all();

        $this->assertSame('1', $cards[__('عدد الطلبات')], 'العددُ لا يُكتب بثلاث خاناتٍ عشرية');
        $this->assertStringContainsString('26.250', $cards[__('إجمالي المبيعات')]);
    }

    /** والبطاقةُ المزدوجة على الورق كما هي على الشاشة: «١ / ١» و«نقدي · …» */
    public function test_a_paired_card_carries_both_of_its_numbers(): void
    {
        $this->tradeOneDay();
        $this->actingAs($this->owner);

        $summary = ReportData::payments($this->shop->id, ['range' => 'all'])['summary'];
        $cards = collect(ReportColumns::cards('payments', $summary))->pluck('value', 'label')->all();

        $this->assertStringContainsString('·', $cards[__('الأعلى تحصيلًا')]);
        $this->assertStringContainsString('نقدي', $cards[__('الأعلى تحصيلًا')]);
    }

    /** وما لا قيمةَ له شرطةٌ لا فراغٌ — كما في خلايا الجدول */
    public function test_an_empty_card_reads_as_a_dash(): void
    {
        $this->actingAs($this->owner);

        $summary = ReportData::payments($this->shop->id, ['range' => 'all'])['summary'];
        $cards = collect(ReportColumns::cards('payments', $summary))->pluck('value', 'label')->all();

        $this->assertSame('—', $cards[__('الأعلى تحصيلًا')], 'متجرٌ لم يبع بعد: لا اسمَ لأعلى وسيلة');
    }

    /**
     * وملخّصٌ لم يصل لا يُسقط التنزيل.
     *
     * البطاقةُ تُترك ولا يُقرأ مفتاحٌ غيرُ موجود: تقريرٌ يُضاف له بطاقةٌ قبل
     * أن يُنتج مفتاحَها يخرج ملفُّه ناقصَ بطاقةٍ — لا بصفحة خطأ في وجه من
     * ضغط «تصدير».
     */
    public function test_a_summary_that_did_not_arrive_does_not_break_the_download(): void
    {
        $this->assertSame([], ReportColumns::cards('vat', []));
        $this->assertSame(
            [__('المبيعات الخاضعة')],
            array_column(ReportColumns::cards('vat', ['taxable' => 10.0]), 'label'),
            'ما وصل يُكتب وما لم يصل يُترك',
        );
    }

    public function test_the_waste_file_starts_where_its_screen_starts(): void
    {
        $this->tradeOneDay();
        $this->actingAs($this->owner);

        $summary = ReportData::waste($this->shop->id, [])['summary'];
        $labels = array_column(ReportColumns::cards('waste', $summary), 'label');

        $this->assertSame(__('قيمة الهالك'), $labels[0], 'الشاشةُ تبدأ بالقيمة لا بالعدد');
        $this->assertCount(3, $labels, 'و«المدّة السابقة» مقارنةٌ لا حصيلة — لا مفتاحَ لها في الملخّص');
    }
}
