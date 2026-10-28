<?php

namespace Tests\Feature;

use App\Models\PaymentGateway;
use App\Support\ReportColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\TwoFullShops;
use Tests\TestCase;

/**
 * لا شاشةَ ولا ملفَّ لمتجر A يحمل حرفًا من متجر B.
 *
 * ═══ كيف يُقاس ═══
 *
 * صفوفُ B تُعلَّم بعلاماتٍ لا تتكرّر (`TENANT-B-SECRET-…`) — منتجٌ وزبونٌ
 * ومورّدٌ ومسمًّى وموظّفٌ وفئةٌ وموسمٌ وبوتيكٌ وكوبونٌ وحسابٌ وصفحة… ثمّ
 * يُبحث عنها في **الجواب كلِّه**: صفحةُ Inertia بخصائصها، وملفُّ xlsx بعد
 * فكّه، والنسخةُ الاحتياطيّة بعد فكّ ضغطها. لا في الخاصّيّة المنتظَرة وحدها:
 * القائمةُ المنسدلةُ في «تعديل منتج» تسرّب كما تسرّب قائمةُ المنتجات.
 *
 * وبابٌ جديدٌ لا يُحتاج ذكرُه: كلُّ مسار GET بلا معامل تحت `/admin` و`/pos`
 * يُطلب من نفسه.
 */
class EveryMerchantScreenReadsOnlyItsShopTest extends TestCase
{
    use RefreshDatabase;
    use TwoFullShops;

    private const MARK = 'TENANT-B-SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTwoShops();
        $this->markShopB();

        /*
         * والمتجران فُتحت فيهما روابطُ الدفع (`PaymentLinks::ready`): فتُقرأ «كل
         * المصادر» بتحصيلاتها (`AllSalesList`) — ولكلٍّ تحصيلٌ مدفوع (`paid_link`)
         * عميلُه في B معلَّم. ولا طلبَ يخرج إلى Paymob من شاشةٍ تُقرأ.
         */
        Http::preventStrayRequests();
        foreach (['a', 'b'] as $shop) {
            PaymentGateway::create([
                'business_id' => $this->ids[$shop]['bid'], 'provider' => PaymentGateway::PAYMOB, 'active' => true,
                'public_key' => 'pk_'.$shop, 'secret_key' => 'sk_'.$shop, 'hmac_secret' => 'hmac_'.$shop,
                'card_integration_id' => '111', 'api_key' => 'api_'.$shop, 'mode' => PaymentGateway::LIVE,
            ]);
        }
    }

    /** يعلّم صفوفَ B — وكلُّ علامةٍ تبدأ بـ`MARK` */
    private function markShopB(): void
    {
        $b = $this->ids['b'];
        $marks = [
            ['products', $b['product'], 'name', 'PRODUCT'],
            ['customers', $b['customer'], 'name', 'CUSTOMER'],
            ['suppliers', $b['supplier'], 'name', 'SUPPLIER'],
            ['job_titles', $b['job_title'], 'name', 'JOB-TITLE'],
            ['users', $b['employee'], 'name', 'EMPLOYEE'],
            ['branches', $b['branch'], 'name', 'BRANCH'],
            ['categories', $b['category'], 'name', 'CATEGORY'],
            ['seasons', $b['season'], 'name', 'SEASON'],
            ['boutiques', $b['boutique'], 'name', 'BOUTIQUE'],
            ['coupons', $b['coupon'], 'code', 'COUPON'],
            ['expense_types', $b['expense_type'], 'name', 'EXPENSE-TYPE'],
            ['expenses', $b['expense'], 'description', 'EXPENSE'],
            ['bank_accounts', $b['bank'], 'account_name', 'BANK'],
            ['accounts', $b['account'], 'name', 'ACCOUNT'],
            ['fixed_assets', $b['asset'], 'name', 'ASSET'],
            ['custom_order_templates', $b['template'], 'name', 'TEMPLATE'],
            ['custom_alerts', $b['alert'], 'message', 'ALERT'],
            ['website_pages', $b['page'], 'title', 'PAGE'],
            ['reviews', $b['review'], 'comment', 'REVIEW'],
            ['addons', $b['addon'], 'name', 'ADDON'],
            ['product_variants', $b['variant'], 'name', 'VARIANT'],
        ];

        foreach ($marks as [$table, $id, $column, $tag]) {
            DB::table($table)->where('id', $id)->update([$column => self::MARK.'-'.$tag]);
        }
    }

    /** الجوابُ نصًّا كاملًا — والمضغوطُ مفكوكًا */
    private function bodyOf(TestResponse $res): string
    {
        $base = $res->baseResponse;

        $content = match (true) {
            $base instanceof StreamedResponse => (string) $res->streamedContent(),
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            default => (string) $base->getContent(),
        };

        if (str_starts_with($content, "\x1f\x8b")) {
            $content .= (string) @gzdecode($content);
        }

        if (str_starts_with($content, 'PK')) {
            $tmp = tempnam(sys_get_temp_dir(), 'iso');
            file_put_contents($tmp, $content);
            $zip = new \ZipArchive;
            if ($zip->open($tmp) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $content .= (string) $zip->getFromIndex($i);
                }
                $zip->close();
            }
            @unlink($tmp);
        }

        return $content;
    }

    /** أيُّ علامةٍ من B في هذا الجواب — أو لا شيء */
    private function leak(TestResponse $res): ?string
    {
        $body = $this->bodyOf($res);

        return preg_match('/'.self::MARK.'-[A-Z-]+/', $body, $m) ? $m[0] : null;
    }

    /* ══════════════ ١ · كلُّ شاشةٍ بلا معامل ══════════════ */

    public function test_no_screen_without_an_id_carries_shop_b(): void
    {
        $leaks = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')
                || ! (str_starts_with($uri, 'admin') || str_starts_with($uri, 'pos'))) {
                continue;
            }

            $res = $this->actingAs($this->ownerA)->get('/'.$uri);
            $checked++;

            if ($mark = $this->leak($res)) {
                $leaks[] = "GET /{$uri} ({$route->getName()}) → HTTP {$res->getStatusCode()} يحمل «{$mark}» — المالك: متجر B";
            }
        }

        $this->assertGreaterThan(100, $checked, 'قلّت الشاشات المفحوصة — تغيّر شيءٌ في المسارات');
        $this->assertSame([], $leaks, "شاشاتٌ لمتجر A تحمل بيانات B:\n".implode("\n", $leaks));
    }

    /** والبحثُ بعلامة B نفسِها لا يجدها */
    public function test_searching_for_shop_b_finds_nothing(): void
    {
        $leaks = [];
        $searches = [
            '/admin/search?q='.self::MARK,
            '/admin/products?q='.self::MARK,
            '/admin/customers?q='.self::MARK,
            '/admin/suppliers?q='.self::MARK,
            '/admin/employees?q='.self::MARK,
            '/admin/orders?q='.self::MARK,
            '/admin/expenses?q='.self::MARK,
            '/admin/marketing/reviews?q='.self::MARK,
            '/pos/customers?q='.self::MARK,
        ];

        foreach ($searches as $url) {
            if ($mark = $this->leak($this->actingAs($this->ownerA)->get($url))) {
                $leaks[] = "GET {$url} يجد «{$mark}»";
            }
        }

        $this->assertSame([], $leaks, "البحثُ في A يجد B:\n".implode("\n", $leaks));
    }

    /* ══════════════ ٢ · كلُّ شاشةٍ بمعرّف ══════════════ */

    /** [المسار => [المعامل => مفتاح الصفّ]] — والمفتاحُ `transaction` يُحسب في الاختبار */
    private const ID_SCREENS = [
        'admin.branch.switch' => ['branch' => 'branch'],
        'admin.customerInvoices.show' => ['id' => 'invoice'],
        'admin.customerInvoices.pdf' => ['id' => 'invoice'],
        'admin.customerInvoices.attachment' => ['id' => 'invoice', 'attachment' => 'invoice_attachment'],
        'admin.customerPayments.show' => ['id' => 'payment'],
        'admin.customerPayments.pdf' => ['id' => 'payment'],
        'admin.customers.show' => ['id' => 'customer'],
        'admin.customers.statement' => ['id' => 'customer'],
        'admin.employees.show' => ['id' => 'employee'],
        'admin.employees.edit' => ['id' => 'employee'],
        'admin.expenses.attachment' => ['id' => 'expense'],
        'admin.finance.customerStatement' => ['customer' => 'customer'],
        'admin.finance.customerStatement.pdf' => ['customer' => 'customer'],
        'admin.finance.statement' => ['id' => 'bank'],
        'admin.finance.transactionPaper' => ['id' => 'transaction'],
        'admin.inventory.receipts.show' => ['id' => 'grn'],
        'admin.inventory.transfers.show' => ['id' => 'transfer'],
        'admin.inventory.supply.show' => ['id' => 'supply'],
        'admin.inventory.receipts.attachment' => ['id' => 'grn'],
        'admin.inventory.receipts.pdf' => ['id' => 'grn'],
        'admin.orders.giftcard' => ['id' => 'order'],
        // «عرض» تحصيلٍ عبر رابط دفعٍ مستقلّ — `PaidLinksList::detail` مقيّدٌ بمتجر الجلسة
        'admin.orders.collection' => ['id' => 'paid_link'],
        'admin.orders.show' => ['number' => 'order_number'],
        'admin.orders.deliveryNote' => ['number' => 'order_number'],
        'admin.orders.deliveryNoteThermal' => ['number' => 'order_number'],
        'admin.orders.pdf' => ['number' => 'order_number'],
        'admin.orders.receipt' => ['number' => 'order_number'],
        'admin.orders.taxInvoice' => ['number' => 'order_number'],
        'admin.preparation.giftcard' => ['id' => 'order'],
        'admin.preparation.deliveryNote' => ['number' => 'order_number'],
        'admin.preparation.deliveryNoteThermal' => ['number' => 'order_number'],
        'admin.preparation.receipt' => ['number' => 'order_number'],
        'admin.preparation.timeline' => ['number' => 'order_number'],
        // «ورقة تجهيز داخلية» — بحصر اللوحة نفسِه (`PreparationController::sheet`)
        'admin.preparation.sheet' => ['number' => 'order_number'],
        'admin.boutiques.show' => ['id' => 'boutique'],
        'admin.seasons.show' => ['id' => 'season'],
        'admin.seasons.products' => ['id' => 'season'],
        'admin.products.show' => ['id' => 'product'],
        'admin.products.edit' => ['id' => 'product'],
        'admin.purchases.invoices.show' => ['id' => 'supplier_invoice'],
        'admin.purchases.invoices.attachment' => ['id' => 'supplier_invoice'],
        'admin.purchases.invoices.pdf' => ['id' => 'supplier_invoice'],
        'admin.purchases.show' => ['id' => 'purchase'],
        'admin.purchases.attachment' => ['id' => 'purchase'],
        'admin.purchases.pdf' => ['id' => 'purchase'],
        'admin.purchases.receiptFile' => ['id' => 'purchase'],
        'admin.website.editor' => ['id' => 'page'],
        'pos.orders.resume' => ['id' => 'held'],
        'pos.order-details' => ['number' => 'order_number'],
        'pos.receipt.paper' => ['number' => 'order_number'],
        'pos.receipt.pdf' => ['number' => 'order_number'],
        'pos.receipts.show' => ['number' => 'order_number'],
    ];

    /**
     * شاشاتٌ تُسقط المعرّفَ الغريبَ وتعرض ما للتاجر نفسِه — ٢٠٠ بلا حرفٍ من B.
     *
     * `finance.statement/{id?}`: المعرّفُ يُختار من حساباتِ المتجر وحدها
     * (`BankAccountController:215`)، ومعرّفٌ ليس منها يُعامل كالغائب فيُفتح
     * الحسابُ الأوّل للمتجر. والمقيسُ فيها أن لا يظهر من B شيء.
     */
    private const FALLS_BACK_TO_OWN = ['admin.finance.statement'];

    private function args(string $shop, array $params): array
    {
        $ids = $this->ids[$shop] + [
            'transaction' => (int) DB::table('transactions')->where('business_id', $this->ids[$shop]['bid'])->value('id'),
        ];

        return array_map(fn ($key) => $ids[$key], $params);
    }

    /** بمعرّفِ B: يُردّ — ولا يحمل منه حرفًا */
    public function test_a_screen_opened_with_shop_b_id_is_refused(): void
    {
        $opened = [];

        foreach (self::ID_SCREENS as $name => $params) {
            $args = $this->args('b', $params);
            $res = $this->actingAs($this->ownerA)->get(route($name, $args));
            $status = $res->getStatusCode();
            $mark = $this->leak($res);

            $refused = in_array($status, [302, 403, 404], true)
                || (in_array($name, self::FALLS_BACK_TO_OWN, true) && $status === 200);

            if (! $refused || $mark) {
                $opened[] = "GET {$name} ".json_encode($args)." → HTTP {$status}".($mark ? " يحمل «{$mark}»" : '').' — المالك: متجر B';
            }
        }

        $this->assertSame([], $opened, "شاشاتٌ فُتحت بمعرّفٍ من متجر B:\n".implode("\n", $opened));
    }

    /** وبمعرّفِ A نفسِه: قوائمُه المنسدلةُ وجداولُه الفرعيّةُ من A وحده */
    public function test_a_screen_of_shop_a_lists_nothing_of_shop_b(): void
    {
        $leaks = [];

        foreach (self::ID_SCREENS as $name => $params) {
            $args = $this->args('a', $params);
            $res = $this->actingAs($this->ownerA)->get(route($name, $args));

            if ($mark = $this->leak($res)) {
                $leaks[] = "GET {$name} ".json_encode($args)." (صفُّ A) يحمل «{$mark}» — المالك: متجر B";
            }
        }

        $this->assertSame([], $leaks, "شاشاتُ A تحمل بيانات B:\n".implode("\n", $leaks));
    }

    /** ولا مسارَ GET بمعرّفٍ يُضاف ويُنسى هنا */
    private const NOT_A_ROW = [
        'admin.currency.switch' => '{code} رمزُ عملةٍ يُحلّ بعملات المتجر (Demo::displayCurrency)',
        'pos.currency.switch' => 'مثلُه',
        'admin.settings.templates.edit' => '{type} نوعُ مستندٍ من قائمةٍ ثابتة',
        'admin.reports.export.xlsx' => '{report} مفتاحُ تقرير — يُفحص كلُّه في test_every_report_export_reads_only_its_shop',
        'admin.reports.export.csv' => 'مثلُه',
        'admin.reports.export.pdf' => 'مثلُه — والـPDF مضغوطٌ لا يُقرأ نصُّه هنا',
        'admin.archives.download' => '{archive} أرشيفٌ شهريّ — حارسُه `AnArchiveThatDoesNotComeBackIsNotAnArchiveTest`',
        'admin.customerInvoices.creditNotes.show' => '{note} إشعارٌ دائن — مقيّدٌ بـbusiness_id (CustomerInvoiceController:332)',
        'admin.customerInvoices.creditNotes.pdf' => 'مثلُه (DocumentPrintController:86)',
        'admin.help.show' => 'محادثاتُ الدعم — `Support::visibleTo`',
        'admin.help.attachment' => 'مثلُه',
        'admin.preparation.invoiceDeliveryNote' => '{id} مهمّةُ تجهيزِ فاتورةٍ تظهر بسدادٍ موثَّق — مقيّدةٌ بمتجر الجلسة وفرعه (CustomerInvoicePreparations::board)، وحارسُها `TheBoardPreparesAPaidCustomerInvoiceTest::test_another_shop_neither_sees_nor_touches_the_task`',
        'admin.preparation.invoiceInternalPdf' => 'مثلُه',
        'admin.preparation.invoiceTimeline' => 'مثلُه',
        'admin.preparation.invoiceSheet' => 'مثلُه — وحارسُ ورقته `APreparationSheetPrintsWhatTheBenchNeedsTest::test_a_task_sheet_is_closed_while_the_feature_is_off_for_the_shop`',
        'pos.printJobs.pdf' => '{id} ورقةُ طابورٍ تُنزَّل لصندوقها الذي حجزها وحده — `APaidCustomerInvoicePrintsOnceAtItsTillTest::test_a_till_of_another_shop_cannot_download_a_paper`',
        'admin.marketing.whatsapp.conversations.older' => '{message} رسالةٌ — يُبدأ من المتجر والرقم الحاليّ، وحارسُه `WhatsAppConversationsTest::test_the_same_phone_in_two_shops_stays_two_and_a_foreign_message_id_is_404`',
        'admin.whatsappInbox.older' => '{chat} محادثةٌ تُطلب داخل متجر الجلسة (`WhatsAppInbox::find`) — وحارسُه `AShopReadsAndAnswersItsCustomersOnWhatsAppTest::test_another_shops_chat_message_and_attachment_cannot_be_reached_by_changing_the_url`',
        'admin.whatsappInbox.attachment' => 'مثلُه (`WhatsAppInbox::attachment`)',
    ];

    public function test_no_id_screen_is_left_out(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_contains($uri, '{')
                || ! (str_starts_with($uri, 'admin') || str_starts_with($uri, 'pos'))) {
                continue;
            }

            $name = (string) $route->getName();
            if (! isset(self::ID_SCREENS[$name]) && ! isset(self::NOT_A_ROW[$name])) {
                $missing[] = "GET {$uri} ({$name})";
            }
        }

        $this->assertSame([], $missing, "شاشاتٌ بمعرّفٍ لا يفحصها هذا الاختبار:\n".implode("\n", $missing));
    }

    /* ══════════════ ٣ · تحصيلاتُ روابط الدفع ══════════════ */

    /**
     * تاجرُ A يرى تحصيلَه في «كل المصادر» ويفتحه — ولا يفتح تحصيلَ B ولا
     * فاتورتَه ولا سندَه، ولا يُلصق تحصيلُ B بفاتورته هو، ولا يجده بمرجعه.
     */
    public function test_a_merchant_opens_its_own_collection_and_nothing_of_shop_b(): void
    {
        $a = $this->ids['a'];
        $b = $this->ids['b'];
        $this->actingAs($this->ownerA);

        $all = $this->get(route('admin.orders.index'))->assertOk();
        $this->assertNull($this->leak($all));
        $props = $all->viewData('page')['props'];
        $this->assertTrue($props['withLinks']);
        $receipts = array_column(array_filter($props['orders'], fn ($r) => ($r['kind'] ?? '') === 'link'), 'id');
        $this->assertContains('RC-A-1', $receipts);
        $this->assertNotContains('RC-B-1', $receipts);

        $own = $this->get(route('admin.orders.collection', $a['paid_link']))->assertOk();
        $this->assertSame('Admin/Orders/Collection', $own->viewData('page')['component']);
        $this->assertSame('RC-A-1', $own->viewData('page')['props']['collection']['payment']['number']);
        $this->assertNull($this->leak($own));

        $this->get(route('admin.orders.collection', $b['paid_link']))->assertNotFound();
        foreach ([
            route('admin.customerInvoices.show', ['id' => $b['invoice'], 'link' => $b['paid_link']]),
            route('admin.customerPayments.show', $b['payment']),
            $this->exportRoute('admin.customerPayments.pdf', $b['payment']),
        ] as $url) {
            $res = $this->get($url);
            $this->assertContains($res->getStatusCode(), [403, 404], $url);
            $this->assertNull($this->leak($res), $url);
        }

        $mine = $this->get(route('admin.customerInvoices.show', ['id' => $a['invoice'], 'link' => $b['paid_link']]))->assertOk();
        $this->assertNull($mine->viewData('page')['props']['collection'], 'أُلصق تحصيلُ B بفاتورة A');
        $this->assertNull($this->leak($mine));

        $search = $this->get(route('admin.orders.index', ['q' => 'TX-B-1']))->assertOk();
        $this->assertSame([], $search->viewData('page')['props']['orders']);
        $this->assertNull($this->leak($search));
    }

    /* ══════════════ ٤ · التصدير ══════════════ */

    public function test_every_report_export_reads_only_its_shop(): void
    {
        $leaks = [];
        $reports = array_unique(array_merge(array_keys(ReportColumns::MAP), array_keys(ReportColumns::SECTIONS)));

        foreach ($reports as $report) {
            foreach (['xlsx', 'csv'] as $format) {
                $res = $this->actingAs($this->ownerA)->get(route('admin.reports.export.'.$format, ['report' => $report]));

                if ($mark = $this->leak($res)) {
                    $leaks[] = "{$format} «{$report}» يحمل «{$mark}»";
                }
            }
        }

        $this->assertSame([], $leaks, "تصديرُ A يحمل بيانات B:\n".implode("\n", $leaks));
    }

    /** والنسخةُ الاحتياطيّةُ الكاملةُ لمتجر A — لا صفَّ فيها من B */
    public function test_the_backup_of_shop_a_carries_nothing_of_shop_b(): void
    {
        $res = $this->actingAs($this->ownerA)->get($this->exportRoute('admin.backup.download'));

        $this->assertNull($this->leak($res), 'نسخةُ A الاحتياطيّة تحمل صفوفًا من B');
        $this->assertTrue(Schema::hasTable('products'));
    }
}
