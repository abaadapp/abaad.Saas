<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Exports\Workbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * ما تقول الشاشةُ «٣٤٧ نتيجة» يخرج ملفًّا من ٣٤٧ صفًّا — لا صفحتَها.
 *
 * كلُّ شاشةٍ وملفّاتُها من استعلامٍ واحد (`App\Support\Lists\*`): الشاشةُ
 * ترقّمه والتصديرُ يقرؤه كلَّه. فيُقاس هنا عددُ الشاشة (`pagination.total`)
 * بعددِ صفوف ملفّها للمرشِّحات نفسها، والصفحةُ أقلُّ من العدد عمدًا.
 *
 * ومعه ما يعد به كلُّ ملفّ: المبالغُ أرقامٌ بمنازل العملة ورمزُها في الرأس،
 * والتواريخُ تواريخُ Excel، ولا عمودَ تقنيّ، والمرشِّحاتُ في الترويسة،
 * ولا يعبر ملفٌّ إلى متجرٍ آخر أو فرعٍ آخر أو صلاحيةٍ لا يملكها.
 */
class WhatTheScreenCountsTheFileCarriesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Business $other;

    private Branch $khuwair;

    private Branch $seeb;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->business = Business::create(['name' => 'متجر التصدير', 'email' => 'e@x.local', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'email' => 'n@x.local', 'status' => 'نشط']);
        $this->khuwair = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->seeb = Branch::create(['business_id' => $this->business->id, 'name' => 'السيب']);
        Branch::create(['business_id' => $this->other->id, 'name' => 'فرع الجار']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'o@x.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function body(TestResponse $res): string
    {
        ob_start();
        $res->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function book(TestResponse $res): Worksheet
    {
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $this->body($res));
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    /** صفُّ رأس الجدول — أوّلُ صفٍّ فيه `$label` — وأعمدتُه بعناوينها */
    private function header(Worksheet $sheet, string $label): array
    {
        foreach ($sheet->toArray(null, false, false) as $i => $row) {
            if (in_array($label, $row, true)) {
                return [$i + 1, array_flip(array_filter($row, fn ($v) => $v !== null))];
            }
        }
        $this->fail("لا رأسَ فيه «{$label}»");
    }

    /** صفوفُ البيانات تحت الرأس حتى أوّل صفٍّ فارغ — بلا رأسٍ ولا مجاميع */
    private function dataRows(Worksheet $sheet, string $label): array
    {
        [$headRow] = $this->header($sheet, $label);
        $rows = [];
        foreach (array_slice($sheet->toArray(null, false, false), $headRow) as $row) {
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                break;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function csvRows(TestResponse $res): array
    {
        $res->assertOk();
        $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), array_filter(explode("\n", trim(ltrim($this->body($res), "\xEF\xBB\xBF")))));

        return array_slice($lines, 1);
    }

    /** عددُ الشاشة كما تقوله لقارئها — مجموعُ ما رُشّح لا صفحتُه */
    private function screenTotal(string $route, array $params = []): int
    {
        $props = $this->actingAs($this->owner)->get(route($route, $params))->assertOk()->viewData('page')['props'];

        return (int) $props['pagination']['total'];
    }

    private function order(int $n, array $over = []): Order
    {
        return Order::create($over + [
            'business_id' => $this->business->id, 'branch_id' => $this->khuwair->id, 'branch' => 'الخوير',
            'number' => 'INV-'.$n, 'customer_name' => 'زبون '.$n, 'employee_name' => 'كاشير',
            'status' => 'مكتمل', 'payment_method' => 'نقدي', 'is_held' => false,
            'subtotal' => $n, 'tax' => 0, 'total' => $n, 'ordered_at' => now()->subHours($n),
        ]);
    }

    /* ======================= العددُ عددُ الشاشة ======================= */

    public function test_orders_file_carries_every_filtered_order_not_the_page(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->order($i, ['status' => $i % 4 === 0 ? 'ملغي' : 'مكتمل']);
        }
        $params = ['status' => 'مكتمل'];

        $screen = $this->screenTotal('admin.orders.index', $params);
        $this->assertSame(18, $screen);

        $sheet = $this->book($this->actingAs($this->owner)->get(route('admin.orders.xlsx', $params)));
        $this->assertCount($screen, $this->dataRows($sheet, 'رقم الطلب'), 'ملفّ الطلبات صفحةٌ لا النتائج كلُّها');
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.orders', $params))));
    }

    public function test_products_file_carries_every_filtered_product(): void
    {
        $flowers = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);
        for ($i = 1; $i <= 17; $i++) {
            Product::create(['business_id' => $this->business->id, 'category_id' => $i <= 14 ? $flowers->id : null,
                'name' => 'صنف '.$i, 'sku' => 'S-'.$i, 'price' => $i, 'cost' => 1, 'quantity' => 5, 'alert_qty' => 1, 'active' => true]);
        }
        $params = ['category' => 'ورود'];

        $screen = $this->screenTotal('admin.products.index', $params);
        $this->assertSame(14, $screen);
        $this->assertCount($screen, $this->dataRows($this->book($this->actingAs($this->owner)->get(route('admin.products.xlsx', $params))), 'الاسم'));
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.products', $params))));
    }

    public function test_customers_file_carries_every_searched_customer(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            Customer::create(['business_id' => $this->business->id, 'name' => ($i <= 12 ? 'سالم ' : 'خالد ').$i, 'phone' => '9900'.$i]);
        }
        $params = ['q' => 'سالم'];

        $screen = $this->screenTotal('admin.customers.index', $params);
        $this->assertSame(12, $screen);
        $this->assertCount($screen, $this->dataRows($this->book($this->actingAs($this->owner)->get(route('admin.customers.xlsx', $params))), 'الاسم'));
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.customers', $params))));
    }

    public function test_expenses_file_carries_its_month_and_status_whole(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            Expense::create(['business_id' => $this->business->id, 'type' => 'إيجار', 'amount' => 10,
                'status' => $i <= 12 ? Expense::PAID : Expense::UNPAID, 'spent_at' => '2026-09-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        Expense::create(['business_id' => $this->business->id, 'type' => 'إيجار', 'amount' => 99, 'status' => Expense::PAID, 'spent_at' => '2026-10-01']);
        $params = ['month' => '2026-09', 'status' => Expense::PAID];

        $screen = $this->screenTotal('admin.expenses.index', $params);
        $this->assertSame(12, $screen);

        $res = $this->actingAs($this->owner)->get(route('admin.expenses.xlsx', $params));
        $this->assertStringContainsString('expenses-2026-09.xlsx', (string) $res->headers->get('content-disposition'));
        $this->assertCount($screen, $this->dataRows($this->book($res), 'المرجع'));
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.expenses', $params))));
    }

    public function test_transactions_file_follows_the_screens_dates_and_kind_not_this_month(): void
    {
        for ($i = 1; $i <= 24; $i++) {
            Transaction::create(['business_id' => $this->business->id, 'reference' => 'T-'.$i, 'description' => 'حركة',
                'kind' => $i <= 22 ? 'expense' : 'other_income', 'method' => 'نقدي', 'type' => $i <= 22 ? 'مصروف' : 'دخل',
                'amount' => 1, 'occurred_at' => Carbon::parse('2026-03-01')->addDays($i)]);
        }
        // وحركةُ فبراير قبل «من» — وحركةُ هذا الشهر بعد «إلى»، وكان الملفّ يُخرجها وحدها
        Transaction::create(['business_id' => $this->business->id, 'reference' => 'FEB', 'description' => 'قبل',
            'kind' => 'expense', 'method' => 'نقدي', 'type' => 'مصروف', 'amount' => 1, 'occurred_at' => '2026-02-20 10:00:00']);
        Transaction::create(['business_id' => $this->business->id, 'reference' => 'NOW', 'description' => 'اليوم',
            'kind' => 'expense', 'method' => 'نقدي', 'type' => 'مصروف', 'amount' => 1, 'occurred_at' => now()]);
        $params = ['from' => '2026-03-01', 'to' => '2026-03-31', 'kind' => 'expense'];

        $screen = $this->screenTotal('admin.finance.transactions', $params);
        $this->assertSame(22, $screen);

        $res = $this->actingAs($this->owner)->get(route('admin.finance.xlsx', $params));
        $this->assertStringContainsString('transactions-2026-03-01-to-2026-03-31.xlsx', (string) $res->headers->get('content-disposition'));
        $rows = $this->dataRows($this->book($res), 'المرجع');
        $this->assertCount($screen, $rows);
        $this->assertNotContains('NOW', array_column($rows, 0), 'ملفّ الحركة عاد إلى «هذا الشهر»');
        $this->assertNotContains('FEB', array_column($rows, 0), 'ملفّ الحركة تجاهل «من»');
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.transactions', $params))));
    }

    public function test_inventory_file_follows_the_search_and_status_that_now_live_in_the_url(): void
    {
        for ($i = 1; $i <= 28; $i++) {
            Product::create(['business_id' => $this->business->id, 'name' => ($i <= 27 ? 'ورد ' : 'إناء ').$i, 'sku' => 'I-'.$i,
                // والإناءُ نافدٌ أيضًا: الحالةُ وحدها تجمعه، والبحثُ يُخرجه
                'price' => 2, 'cost' => 1, 'quantity' => ($i <= 26 || $i === 28) ? 0 : 9, 'alert_qty' => 1, 'active' => true]);
        }
        $params = ['q' => 'ورد', 'stock' => 'نفد المخزون'];

        $screen = $this->screenTotal('admin.inventory.index', $params);
        $this->assertSame(26, $screen);
        $this->assertCount($screen, $this->dataRows($this->book($this->actingAs($this->owner)->get(route('admin.inventory.xlsx', $params))), 'المنتج'));
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.inventory', $params))));
    }

    public function test_suppliers_file_follows_the_search(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            Supplier::create(['business_id' => $this->business->id, 'name' => ($i <= 26 ? 'مشتل ' : 'مطبعة ').$i, 'phone' => '9700'.$i]);
        }
        $params = ['q' => 'مشتل'];

        $screen = $this->screenTotal('admin.suppliers.index', $params);
        $this->assertSame(26, $screen);
        $this->assertCount($screen, $this->dataRows($this->book($this->actingAs($this->owner)->get(route('admin.suppliers.xlsx', $params))), 'الاسم'));
        $this->assertCount($screen, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.suppliers', $params))));
    }

    /* ======================= الترتيبُ ترتيبُ الشاشة ======================= */

    public function test_the_file_keeps_the_screens_sort(): void
    {
        foreach ([30, 5, 18] as $n) {
            $this->order($n);
        }

        // الأغلى أوّلًا — وهو غيرُ ترتيب الشاشة الافتراضيّ (الأحدث)، فيُقاس الفرق
        $desc = $this->dataRows($this->book($this->actingAs($this->owner)->get(route('admin.orders.xlsx', ['sort' => 'total', 'dir' => 'desc']))), 'رقم الطلب');
        $this->assertSame(['INV-30', 'INV-18', 'INV-5'], array_column($desc, 0));

        // وبلا ترتيبٍ مختار: ترتيبُ الشاشة الافتراضيّ — الأحدثُ أوّلًا
        $default = $this->dataRows($this->book($this->actingAs($this->owner)->get(route('admin.orders.xlsx'))), 'رقم الطلب');
        $this->assertSame(['INV-5', 'INV-18', 'INV-30'], array_column($default, 0));
    }

    /* ======================= ما يعد به كلُّ ملفّ ======================= */

    public function test_money_is_a_number_in_the_shops_currency_and_dates_are_excel_dates(): void
    {
        Currency::create(['business_id' => $this->business->id, 'code' => 'AED', 'name' => 'درهم',
            'symbol' => 'د.إ', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->order(7, ['total' => 1234.5]);

        $sheet = $this->book($this->actingAs($this->owner)->get(route('admin.orders.xlsx')));
        [$headRow, $cols] = $this->header($sheet, 'رقم الطلب');
        $this->assertArrayHasKey('الإجمالي (AED)', $cols, 'رأسُ المبلغ لا يحمل عملة المتجر');
        $this->assertArrayNotHasKey('الإجمالي (OMR)', $cols);

        $money = $sheet->getCell(Coordinate::stringFromColumnIndex($cols['الإجمالي (AED)'] + 1).($headRow + 1));
        $this->assertIsFloat($money->getValue(), 'المبلغ نصٌّ لا يُجمع');
        $this->assertSame(1234.5, $money->getValue());
        $this->assertSame('#,##0.00', $money->getStyle()->getNumberFormat()->getFormatCode(), 'منازلُ الدرهم اثنتان لا ثلاث');

        $date = $sheet->getCell(Coordinate::stringFromColumnIndex($cols['التاريخ'] + 1).($headRow + 1));
        $this->assertTrue(is_numeric($date->getValue()), 'التاريخ نصٌّ لا تاريخ');
        $this->assertSame('yyyy-mm-dd hh:mm', $date->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame(now()->subHours(7)->format('Y-m-d H:i'), ExcelDate::excelToDateTimeObject($date->getValue())->format('Y-m-d H:i'));
    }

    public function test_no_technical_id_column_reaches_a_report(): void
    {
        Product::create(['business_id' => $this->business->id, 'name' => 'صنف', 'price' => 1, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 0, 'active' => true]);
        Customer::create(['business_id' => $this->business->id, 'name' => 'عميل', 'phone' => '1']);

        foreach (['admin.products.xlsx' => 'الاسم', 'admin.inventory.xlsx' => 'المنتج', 'admin.customers.xlsx' => 'الاسم'] as $route => $first) {
            [, $cols] = $this->header($this->book($this->actingAs($this->owner)->get(route($route))), $first);
            $this->assertArrayNotHasKey('المعرّف', $cols, "{$route} يحمل عمود المعرّف");
            $this->assertSame(0, $cols[$first], "{$route}: الأعمدةُ لا تبدأ بعمود الشاشة الأوّل");
        }
    }

    public function test_the_header_names_the_active_filters_and_only_them(): void
    {
        $this->order(3);

        $text = collect($this->book($this->actingAs($this->owner)->get(route('admin.orders.xlsx', ['q' => 'زبون', 'channel' => ''])))
            ->toArray(null, false, false))->flatten()->filter()->implode(' | ');

        $this->assertStringContainsString('متجر التصدير', $text);
        $this->assertStringContainsString('البحث: زبون', $text);
        $this->assertStringContainsString('تاريخ التصدير', $text);
        $this->assertStringNotContainsString('المصدر:', $text, 'مرشِّحٌ فارغ طُبع في الترويسة');
        $this->assertStringNotContainsString('الحالة:', $text);
    }

    public function test_an_empty_result_is_a_file_that_says_so_not_an_error(): void
    {
        $sheet = $this->book($this->actingAs($this->owner)->get(route('admin.orders.xlsx', ['q' => 'لا أحد'])));

        $this->assertStringContainsString('لا توجد نتائج', collect($sheet->toArray(null, false, false))->flatten()->filter()->implode(' '));
        $this->assertCount(0, $this->csvRows($this->actingAs($this->owner)->get(route('admin.export.orders', ['q' => 'لا أحد']))));
    }

    public function test_the_button_learns_when_the_download_started(): void
    {
        $res = $this->actingAs($this->owner)->get(route('admin.orders.xlsx', ['download_token' => 'abc123XYZ']));

        $this->assertSame('abc123XYZ', collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'download_token')?->getValue());

        // ورمزٌ غريبُ الشكل لا يُكتب كعكةً
        $odd = $this->actingAs($this->owner)->get(route('admin.orders.xlsx', ['download_token' => '<script>']));
        $this->assertNull(collect($odd->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'download_token'));
    }

    public function test_a_filename_never_carries_unsafe_user_text(): void
    {
        $this->assertSame('expenses-2026-10.xlsx', Workbook::filename('expenses', ['2026-10']));
        $this->assertSame('sales-2026-03-to-2026-06.xlsx', Workbook::filename('sales', ['2026-03', 'to', '2026-06']));
        $this->assertSame('x-a-b.csv', Workbook::filename('x', ['../a', 'b/..'], 'csv'));
        // والعربيُّ يُكتب بحروفٍ لاتينيّة لا يكسر اسمَ الملفّ
        $this->assertMatchesRegularExpression('/^orders(-[a-z0-9]+)?\.xlsx$/', Workbook::filename('orders', ['مرحبا', null]));
    }

    /* ======================= ولا يعبر ملفٌّ حدَّه ======================= */

    public function test_another_business_never_reaches_the_file_even_when_named_in_the_url(): void
    {
        $this->order(1, ['customer_name' => 'زبوننا']);
        $this->order(2, ['business_id' => $this->other->id, 'branch_id' => null, 'customer_name' => 'زبون الجار']);
        Expense::create(['business_id' => $this->other->id, 'type' => 'سرّ الجار', 'amount' => 1, 'status' => Expense::PAID, 'spent_at' => now()]);

        foreach (['admin.orders.xlsx', 'admin.export.orders', 'admin.expenses.xlsx', 'admin.finance.xlsx', 'admin.customers.xlsx'] as $route) {
            $body = $this->body($this->actingAs($this->owner)->get(route($route, ['business_id' => $this->other->id]))->assertOk());
            if (str_starts_with($body, 'PK')) {
                $path = tempnam(sys_get_temp_dir(), 'xl');
                file_put_contents($path, $body);
                $body = collect(IOFactory::load($path)->getActiveSheet()->toArray())->flatten()->filter()->implode(' ');
                @unlink($path);
            }
            $this->assertStringNotContainsString('الجار', $body, "{$route} عبر إلى متجرٍ آخر");
        }
    }

    public function test_the_chosen_branch_bounds_the_file_and_the_url_cannot_widen_it(): void
    {
        $this->order(1, ['customer_name' => 'زبون الخوير']);
        $this->order(2, ['branch_id' => $this->seeb->id, 'branch' => 'السيب', 'customer_name' => 'زبون السيب']);

        $rows = $this->dataRows($this->book($this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->khuwair->id])
            ->get(route('admin.orders.xlsx', ['branch_id' => $this->seeb->id, 'branch' => $this->seeb->id]))), 'رقم الطلب');

        $this->assertSame(['INV-1'], array_column($rows, 0), 'الرابطُ وسّع الملفّ إلى فرعٍ آخر');
    }

    public function test_who_cannot_open_the_screen_cannot_download_its_file(): void
    {
        $cashier = User::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@x.local',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['pos']]);

        foreach (['admin.expenses.xlsx', 'admin.export.expenses', 'admin.finance.xlsx', 'admin.customers.xlsx', 'admin.suppliers.xlsx'] as $route) {
            $this->actingAs($cashier)->get(route($route))->assertForbidden();
        }
    }

    /* ======================= ولوحةُ المنصّة كذلك ======================= */

    public function test_platform_files_follow_their_screens_filters(): void
    {
        $super = User::create(['name' => 'المنصّة', 'email' => 'super@x.local', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
        for ($i = 1; $i <= 13; $i++) {
            $b = Business::create(['name' => 'شركة '.$i, 'email' => "b{$i}@x.local", 'status' => $i <= 11 ? 'نشط' : 'موقوف']);
            \App\Models\Invoice::create(['business_id' => $b->id, 'number' => 'SUB-'.$i, 'amount' => 10,
                'status' => $i <= 27 && $i % 2 === 0 ? 'مدفوعة' : 'غير مدفوعة', 'issued_at' => now()]);
        }

        // الشركات النشطة: الإحدى عشرة ومتجرا الإعداد — والصفحةُ عشرٌ منها
        $props = $this->actingAs($super)->get(route('super-admin.businesses.index', ['status' => 'نشط']))->assertOk()->viewData('page')['props'];
        $this->assertSame(13, $props['pagination']['total']);
        $this->assertCount(13, $this->dataRows($this->book($this->actingAs($super)->get(route('super-admin.businesses.xlsx', ['status' => 'نشط']))), 'الشركة'));
        $this->assertCount(13, $this->csvRows($this->actingAs($super)->get(route('super-admin.export.businesses', ['status' => 'نشط']))));

        // والفواتير: البحثُ والحالةُ على الخادم الآن فيبلغان الملفّ
        $props = $this->actingAs($super)->get(route('super-admin.subscriptions.invoices', ['status' => 'غير مدفوعة']))->assertOk()->viewData('page')['props'];
        $this->assertSame(7, $props['pagination']['total']);
        $this->assertCount(7, $this->dataRows($this->book($this->actingAs($super)->get(route('super-admin.invoices.xlsx', ['status' => 'غير مدفوعة']))), 'رقم الفاتورة'));
    }

    /* ======================= مركزُ التقارير بلا سقف الشاشة ======================= */

    public function test_the_report_centre_file_carries_more_than_the_screens_five_hundred(): void
    {
        $rows = [];
        for ($i = 1; $i <= 520; $i++) {
            $rows[] = ['business_id' => $this->business->id, 'branch_id' => $this->khuwair->id, 'branch' => 'الخوير',
                'number' => 'R-'.$i, 'customer_name' => 'زبون', 'employee_name' => 'كاشير', 'status' => 'مكتمل',
                'payment_method' => 'نقدي', 'is_held' => false, 'subtotal' => 1, 'tax' => 0, 'total' => 1,
                'ordered_at' => now()->subMinutes($i), 'created_at' => now(), 'updated_at' => now()];
        }
        Order::insert($rows);

        $csv = $this->body($this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'orders', 'range' => 'month']))->assertOk());

        $this->assertSame(520, substr_count($csv, 'R-'), 'ملفّ التقرير قُصّ عند سقف الشاشة');
    }
}
