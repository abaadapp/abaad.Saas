<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPortalSession;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\CustomerPurchases;
use App\Support\Document\Pdf\Driver;
use App\Support\Lists\SalesRegister;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\Pdf;
use App\Support\RibbonPortal\Account;
use App\Support\RibbonPortal\Texts;
use App\Support\SalesChannel;
use App\Support\TenantExportGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\RibbonPortalShop;
use Tests\TestCase;

/**
 * عميلُ RIBBON يقرأ ما اشتراه — كما هو في النظام، وكلَّه، وله وحده.
 *
 *   - يدخل من الرابط الواحد برقم هاتفه المسجَّل ورمزِ فاتورته، بلا OTP ولا حساب، واسمُه من سجلّه.
 *   - مشترياتُه من سجلّ البيع الواحد: الصندوقُ والموقعُ وفواتيرُ العملاء، كلُّ بيعةٍ
 *     مرّة، ومجموعُها رقمُه في قائمة العملاء.
 *   - وفاتورتُه الورقةُ نفسُها التي تطبعها اللوحة، ونقاطُه رصيدُه وحركاتُه.
 *   - ويُقرأ كلُّه ساعتَها: بيعةٌ أو سدادٌ أو إلغاءٌ يظهر بلا رابطٍ جديد.
 *   - ولا يُكتب بها شيءٌ في بيعٍ ولا دفترٍ ولا نقاط.
 */
class ARibbonCustomerReadsWhatTheyBoughtTest extends TestCase
{
    use RefreshDatabase;
    use RibbonPortalShop;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-29 10:00:00');
        app()->setLocale('ar');
        Http::preventStrayRequests();
        $this->openRibbon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(Customer $customer, float $price, array $data = [], bool $issue = true): CustomerInvoice
    {
        $draft = CustomerInvoices::create((int) $customer->business_id, $customer, $data, [['description' => 'تنسيق', 'quantity' => 1, 'unit_price' => $price]], $this->owner->id);

        return $issue ? CustomerInvoices::issue($draft, $this->owner->id)->fresh() : $draft->fresh();
    }

    /** أرقامُ ما يُعرض عليه بترتيبه */
    private function numbers(string $cookie, string $query = ''): array
    {
        return array_map(fn ($r) => html_entity_decode(strip_tags((string) preg_match('/data-testid="portal-number">([^<]+)</', $r['html'], $m) ? $m[1] : '')),
            $this->myJson('/data?limit=50'.$query, $cookie)->assertOk()->json('rows'));
    }

    private function row(string $cookie, string $number): string
    {
        return collect($this->myJson('/data?limit=50&q='.$number, $cookie)->json('rows'))->firstOrFail()['html'];
    }

    /* ═════════════ الدخول ═════════════ */

    public function test_a_registered_customer_enters_with_their_phone_on_an_approved_device_and_is_greeted_by_name(): void
    {
        $cookie = $this->enter($this->maryam);

        $page = $this->my('', $cookie)->assertOk();
        $page->assertSee('أهلًا مريم البلوشي');
        $page->assertSee('data-testid="portal-empty"', false);
        // عميلةٌ بلا مشتريات: أصفارٌ حقيقيّة، لا بيانات مخترعة
        $this->assertStringContainsString('لا توجد مشتريات مسجّلة باسمك بعد.', $page->getContent());
        $this->assertSame([], $this->numbers($cookie));

        // وبأيّ صيغةٍ كُتب الرقمُ نفسُه يُعتمد له
        foreach (['91234567', '+968 9123 4567', '00968-91234567', '968 91234567', '٩١٢٣٤٥٦٧', '۹۱۲۳۴۵۶۷', ' 9123-4567 '] as $written) {
            RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
            Carbon::setTestNow(now()->addMinutes(16));
            $this->my('', $this->enter($this->maryam, $written))->assertSee('أهلًا مريم البلوشي');
        }

        // ولا سجلَّ عميلٍ جديدًا ولا حسابَ نظامٍ
        $this->assertSame(1, Customer::where('business_id', $this->ribbon->id)->count());
        $this->assertGuest();
    }

    /* ═════════════ المشتريات ═════════════ */

    public function test_every_purchase_is_listed_once_whichever_door_it_came_through(): void
    {
        Carbon::setTestNow('2026-01-05 09:00:00');
        $this->order($this->maryam, 'OLD-1');
        Carbon::setTestNow('2026-11-20 12:30:00');
        $this->order($this->maryam, 'WEB-1', ['channel' => SalesChannel::WEBSITE, 'employee_name' => null]);
        $this->order($this->maryam, 'CUSTOM-1', ['channel' => null, 'payment_status' => 'غير مدفوع', 'status' => OrderStatus::CONFIRMED]);
        $credit = $this->order($this->maryam, 'CREDIT-1', ['payment_status' => 'غير مدفوع', 'balance_due' => 20]);
        Carbon::setTestNow('2026-11-21 08:00:00');
        $covering = CustomerInvoices::fromOrder($credit, [], $this->owner->id);
        // وهي مسودّة لا تُذكر ولا تُفتح له — حتّى تصدر
        $before = $this->enter($this->maryam);
        $this->assertStringNotContainsString('data-testid="portal-cover"', $this->row($before, 'CREDIT-1'));
        $this->my("/paper/invoice/{$covering->id}", $before)->assertNotFound();
        $covering = CustomerInvoices::issue($covering, $this->owner->id)->fresh();
        $plain = $this->invoice($this->maryam, 50);
        $prepared = $this->invoice($this->maryam, 30);
        CustomerPayments::record($this->ribbon->id, $this->maryam, 10, ['method' => 'نقدي', 'occurred_at' => now()], [$prepared->id => 10], $this->owner->id);
        // وما ليس بيعًا: مسودّةٌ، وسلّةٌ معلّقة، وبيعةُ عميلٍ آخر، وعميلُ متجرٍ آخر بالرقم نفسه
        $draft = $this->invoice($this->maryam, 99, [], false);
        $this->order($this->maryam, 'HELD-1', ['is_held' => true]);
        $this->order($this->buyer('فاطمة', '92222222'), 'OTHER-1');
        $this->order($this->buyer('مريم الجار', '91234567', $this->neighbour), 'NEIGHBOUR-1');

        $cookie = $this->enter($this->maryam);
        $seen = $this->numbers($cookie);

        $this->assertEqualsCanonicalizing(['OLD-1', 'WEB-1', 'CUSTOM-1', 'CREDIT-1', $plain->number, $prepared->number], $seen, 'كلُّ بيعةٍ مرّة');
        $this->assertSame('OLD-1', end($seen), 'الأحدثُ أوّلًا والأقدمُ آخرًا');
        $this->assertStringNotContainsString('>'.$covering->number.'</strong>', implode('', array_column($this->myJson('/data?limit=50', $cookie)->json('rows'), 'html')), 'فاتورةُ الطلب ليست شراءً ثانيًا');
        $this->assertNull($draft->number);

        // والفاتورةُ التي غطّت الطلب تُذكر في صفّه وتُفتح منه
        $this->assertStringContainsString($covering->number, $this->row($cookie, 'CREDIT-1'));
        $this->assertStringContainsString('/my/paper/invoice/'.$covering->id, $this->row($cookie, 'CREDIT-1'));

        // وحالُ السداد كما هي
        foreach (['OLD-1' => 'مدفوعة', 'CUSTOM-1' => 'غير مدفوعة', 'CREDIT-1' => 'غير مدفوعة', $plain->number => 'غير مدفوعة', $prepared->number => 'مدفوعة جزئيًا'] as $number => $state) {
            $this->assertStringContainsString('>'.$state.'</span>', $this->row($cookie, (string) $number), (string) $number);
        }
        $this->assertStringContainsString('2026-11-20', $this->row($cookie, 'WEB-1'));
        $this->assertStringContainsString('12:30', $this->row($cookie, 'WEB-1'));

        // ومجموعُها رقمُه في قائمة العملاء وفي سجلّه — لا حسابٌ ثانٍ
        $c = CustomerPurchases::apply(Customer::whereKey($this->maryam->id))->firstOrFail();
        $totals = SalesRegister::customerTotals($this->ribbon->id, $this->maryam->id);
        $summary = (new Account($this->maryam->fresh(), 'ar'))->summary();
        $this->assertSame(6, $summary['count']);
        $this->assertSame(CustomerPurchases::count($c), $summary['count']);
        $this->assertSame($totals['count'] - $totals['cancelled'], $summary['count']);
        $this->assertSame(160.0, CustomerPurchases::total($c));
        $this->assertSame($totals['sales'], CustomerPurchases::total($c));
        $this->assertStringContainsString('160.000', $summary['total']);
        $this->assertSame('2026-11-21', $summary['last']);
    }

    public function test_nothing_of_the_shops_own_reaches_the_customer(): void
    {
        $order = $this->order($this->maryam, 'INV-7', ['notes' => 'بلا شوكولاتة']);
        $invoice = $this->invoice($this->maryam, 40, ['internal_notes' => 'ملاحظة المحاسب', 'notes' => 'شكرًا لكم']);
        PointTransaction::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'type' => 'adjust', 'points' => 5, 'balance_after' => 5, 'note' => 'تصحيح إداري بيد سعود — عميلة مزعجة']);
        $cookie = $this->enter($this->maryam);

        $everything = $this->my('', $cookie)->getContent().$this->myJson('/data?all=1&limit=50', $cookie)->getContent().$this->myJson('/points', $cookie)->getContent()
            .$this->my("/paper/order/{$order->id}", $cookie)->getContent().$this->my("/paper/order/{$order->id}/sheet", $cookie)->getContent()
            .$this->my("/paper/invoice/{$invoice->id}/sheet", $cookie)->getContent();

        foreach (['3.777', 'سرٌّ داخليّ', 'ملاحظة المحاسب', 'عميلة مزعجة', 'بيد سعود'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, $secret);
        }
        // وقائمتُه بلا بائعٍ ولا فرع
        $this->assertStringNotContainsString('الكاشير سالم', $this->my('', $cookie)->getContent());
        $this->assertStringContainsString('تصحيح رصيد', $this->myJson('/points', $cookie)->json('rows.0.html'));
    }

    public function test_search_dates_and_older_purchases_are_all_reachable(): void
    {
        for ($i = 1; $i <= 64; $i++) {
            Carbon::setTestNow(Carbon::parse('2024-01-01 10:00:00')->addDays($i));
            $this->order($this->maryam, sprintf('INV-%03d', $i));
        }
        Carbon::setTestNow('2026-11-29 10:00:00');
        $cookie = $this->enter($this->maryam);

        // صفحةٌ صفحةٌ حتّى آخرها، بلا سقفٍ ولا تكرار
        $all = [];
        $offset = 0;
        do {
            $page = $this->myJson("/data?offset={$offset}&limit=10", $cookie)->assertOk();
            $keys = array_column($page->json('rows'), 'key');
            $all = [...$all, ...$keys];
            $offset += count($keys);
        } while ($page->json('more'));
        $this->assertCount(64, $all);
        $this->assertCount(64, array_unique($all));

        $this->assertSame(['INV-007'], $this->numbers($cookie, '&q=INV-007'));
        $this->assertSame([], $this->numbers($cookie, '&q=ZZZ'));
        // علاماتُ البحث ليست «كلّ شيء»
        $this->assertCount(50, $this->numbers($cookie, '&q=%25'));
        $this->assertSame(['INV-010', 'INV-009'], $this->numbers($cookie, '&from=2024-01-10&to=2024-01-11'));
        $this->assertCount(10, $this->myJson('/data?from=ليس-تاريخًا', $cookie)->assertOk()->json('rows'), 'تاريخٌ لا يُقرأ يُهمل');
        $this->assertCount(50, $this->myJson('/data?limit=99999', $cookie)->json('rows'), 'ولا طلبٌ يحمّل كلَّ شيءٍ مرّة');
    }

    /* ═════════════ الفاتورة الأصليّة ═════════════ */

    public function test_the_invoice_is_the_very_paper_the_shop_prints(): void
    {
        $order = $this->order($this->maryam, 'INV-ORIG', ['discount' => 2, 'total' => 18]);
        $invoice = $this->invoice($this->maryam, 75);
        $cookie = $this->enter($this->maryam);

        $spy = new class implements Driver
        {
            public array $drawn = [];

            public function sheet(string $html, string $name, array $paper, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->drawn[] = compact('html', 'name', 'paper', 'context');

                return response('%PDF', 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"']);
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 0.0;
            }
        };
        $was = Pdf::swap($spy);

        try {
            foreach ([
                ['order', $order->id, route('admin.orders.pdf', ['number' => $order->number, 'tenant' => $this->ribbon->id])],
                ['invoice', $invoice->id, route('admin.customerInvoices.pdf', ['id' => $invoice->id, 'tenant' => $this->ribbon->id])],
            ] as [$kind, $id, $admin]) {
                $spy->drawn = [];
                // على مضيف البوابة كما هو — فالرمزُ لا يحمله
                URL::forceRootUrl(null);
                $this->my("/paper/{$kind}/{$id}/pdf", $cookie)->assertOk()->assertHeader('Content-Type', 'application/pdf');
                URL::forceRootUrl('http://localhost');
                $this->defaultCookies = [];
                $this->actingAs($this->owner)->get(TenantExportGuard::url($admin, (int) $this->ribbon->id))->assertOk();

                $this->assertCount(2, $spy->drawn, $kind);
                [$mine, $shops] = $spy->drawn;
                $this->assertSame($shops['name'], $mine['name'], $kind);
                $this->assertSame($shops['paper'], $mine['paper'], $kind);
                $this->assertSame($this->still($shops['html']), $this->still($mine['html']), "ورقةُ {$kind} هي ورقةُ اللوحة حرفًا");
                $this->assertStringContainsString('http://localhost/i/', $mine['html'], 'ورمزُها رمزُ الورقة المطبوعة');
            }
        } finally {
            Pdf::swap($was);
        }

        // وتُعرض كاملةً في الصفحة، وتُنزَّل ملفًّا، وتُفتح
        $sheet = $this->my("/paper/order/{$order->id}/sheet", $cookie)->assertOk();
        foreach (['INV-ORIG', 'باقة INV-ORIG', '18.000'] as $on) {
            $this->assertStringContainsString($on, $sheet->getContent());
        }
        $this->assertStringNotContainsString('<barcode', $sheet->getContent());
        $this->assertStringContainsString('data:image/svg+xml;base64,', $sheet->getContent(), 'ورمزُها يُرى على الشاشة كما يُطبع');
        $this->assertStringContainsString('sandbox', (string) $sheet->headers->get('Content-Security-Policy'));
        $this->my("/paper/invoice/{$invoice->id}", $cookie)->assertOk()->assertSee((string) $invoice->number);
        $pdf = $this->my("/paper/invoice/{$invoice->id}/pdf?download=1", $cookie)->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringStartsWith('attachment', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('inline', (string) $this->my("/paper/invoice/{$invoice->id}/pdf", $cookie)->headers->get('Content-Disposition'));
    }

    /** الورقةُ بلا ما يتبدّل بين رسمتين (ساعةُ الرسم إن كُتبت) */
    private function still(string $html): string
    {
        return (string) preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?/', 'T', $html);
    }

    public function test_reading_writes_no_sale_no_ledger_and_no_points(): void
    {
        $order = $this->order($this->maryam, 'INV-R');
        $invoice = $this->invoice($this->maryam, 12);
        $this->maryam->update(['points' => 40]);
        $cookie = $this->enter($this->maryam);

        $watched = ['orders', 'order_items', 'customer_invoices', 'customer_invoice_items', 'customer_payments', 'customer_credit_notes', 'journal_entries', 'journal_lines',
            'transactions', 'inventory_movements', 'products', 'customers', 'point_transactions', 'settings', 'users', 'businesses', 'activity_logs'];
        $print = fn () => collect($watched)->mapWithKeys(fn ($t) => [$t => md5(json_encode(DB::table($t)->orderBy('id')->get()))])->all();
        $before = $print();

        $this->my('', $cookie)->assertOk();
        $this->myJson('/data?all=1', $cookie)->assertOk();
        $this->myJson('/points', $cookie)->assertOk();
        foreach (["order/{$order->id}", "invoice/{$invoice->id}"] as $doc) {
            $this->my("/paper/{$doc}", $cookie)->assertOk();
            $this->my("/paper/{$doc}/sheet", $cookie)->assertOk();
            $this->my("/paper/{$doc}/pdf?download=1", $cookie)->assertOk();
        }

        $this->assertSame($before, $print());
        // ولا بابَ كتابةٍ فيها سوى رقم الهاتف والخروج
        foreach (['/data', '/points', '/wait', "/paper/order/{$order->id}"] as $path) {
            $this->visitor($cookie)->post('http://ribbon.abaadapp.om/my'.$path)->assertStatus(405);
        }
    }

    /* ═════════════ يتحدّث وحده ═════════════ */

    public function test_a_new_sale_its_points_its_payment_and_its_cancellation_show_without_entering_again(): void
    {
        $cookie = $this->enter($this->maryam);
        $this->assertSame([], $this->numbers($cookie));

        // بيعةُ صندوقٍ حقيقيّة بعميلها: تظهر ونقاطُها معها
        $this->defaultCookies = [];
        $sold = $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->bouquet->id, 'name' => 'باقة', 'qty' => 3]], 'payment_method' => 'نقدي',
            'customer_id' => $this->maryam->id, 'client_uuid' => uniqid('p', true),
        ])->assertOk()->json();
        $this->assertSame('https://ribbon.abaadapp.om/my', $sold['portal']['url'] ?? null, 'الرابطُ الواحد');
        $this->assertGreaterThan(0, $sold['points_earned']);

        $this->assertSame([$sold['invoice']], $this->numbers($cookie));
        $data = $this->myJson('/data?all=1', $cookie)->json();
        $this->assertStringContainsString('data-testid="portal-points">'.number_format($sold['points_balance']).'<', $data['summary']);
        $this->assertSame((int) $this->maryam->fresh()->points, (int) $sold['points_balance']);

        // فاتورةٌ تُسدَّد: حالُها تتبدّل
        $invoice = $this->invoice($this->maryam, 60);
        $this->assertStringContainsString('>غير مدفوعة</span>', $this->row($cookie, $invoice->number));
        CustomerPayments::record($this->ribbon->id, $this->maryam, 60, ['method' => 'نقدي', 'occurred_at' => now()], [$invoice->id => 60], $this->owner->id);
        $this->assertStringContainsString('>مدفوعة</span>', $this->row($cookie, $invoice->number));

        // وإشعارُ دائنٍ يُطرح من مجموعه ويُذكر في صفّه
        CustomerInvoices::creditNote($invoice->fresh(), 10, 0, 'مرتجع', $this->owner->id);
        $this->assertStringContainsString('إشعار دائن', $this->row($cookie, $invoice->number));
        $this->assertStringContainsString('80.000', (new Account($this->maryam->fresh(), 'ar'))->summary()['total']);

        // وإلغاءُ البيعة: صفُّها «ملغاة»، وخرجت من العدد والمجموع، ونقاطُها عادت
        $order = Order::where('number', $sold['invoice'])->firstOrFail();
        OrderCorrection::cancel($order->fresh(), 'إلغاء');
        $this->assertStringContainsString('>ملغاة</span>', $this->row($cookie, $sold['invoice']));
        $summary = (new Account($this->maryam->fresh(), 'ar'))->summary();
        $this->assertSame(1, $summary['count']);
        $this->assertSame(0, $summary['points']);
        $moves = array_column((new Account($this->maryam->fresh(), 'ar'))->points()['rows'], 'move');
        $this->assertSame(['move.reversed', 'move.earned'], $moves);

        // والجلسةُ هي هي: لا دخولَ ثانٍ
        $this->assertSame(1, CustomerPortalSession::where('customer_id', $this->maryam->id)->count());
    }

    /* ═════════════ النقاط ═════════════ */

    public function test_points_are_the_real_balance_its_history_and_the_shops_own_rules(): void
    {
        $this->maryam->update(['points' => 130]);
        foreach ([['earn', 200, 200, 'اكتساب من الشراء — فاتورة A'], ['redeem', -100, 100, 'استبدال — فاتورة B'], ['earn', 30, 130, 'ردّ نقاط بنودٍ أُرجعت من الفاتورة B']] as [$type, $n, $after, $note]) {
            PointTransaction::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'type' => $type, 'points' => $n, 'balance_after' => $after, 'note' => $note]);
        }
        // حركاتُ غيره لا تدخل
        PointTransaction::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->buyer('سارة', '95555555')->id, 'type' => 'earn', 'points' => 999, 'balance_after' => 999]);

        $points = (new Account($this->maryam->fresh(), 'ar'))->points();
        $this->assertSame([130, 200, 70], [$points['balance'], $points['earned'], $points['used']]);
        $this->assertSame(['move.returned', 'move.used', 'move.earned'], array_column($points['rows'], 'move'));

        $say = fn (array $set) => (function () use ($set) {
            foreach ($set as $key => $value) {
                Setting::updateOrCreate(['business_id' => $this->ribbon->id, 'key' => $key], ['value' => $value]);
            }

            return implode(' | ', (new Account($this->maryam->fresh(), 'ar'))->rules());
        })();

        $fixed = $say(['loyalty_enabled' => '1', 'loyalty_earn_rate' => '5', 'loyalty_redeem_enabled' => '1', 'loyalty_redeem_mode' => 'fixed', 'loyalty_redeem_points' => '100', 'loyalty_redeem_value' => '1.5']);
        $this->assertStringContainsString('تكسب 5 نقطة عن كل 1.000', $fixed);
        $this->assertStringContainsString('كل 100 نقطة تساوي خصم 1.500', $fixed);

        // والنسبةُ تُقال نسبةً: لا قيمةَ نقديّةً ثابتة تُفترض
        $percent = $say(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => '10']);
        $this->assertStringContainsString('خصم 10% من قيمة الفاتورة', $percent);
        $this->assertStringNotContainsString('خصم 1.', $percent);

        $this->assertStringContainsString('غير متاح', $say(['loyalty_redeem_enabled' => '0']));
        $this->assertSame('برنامج النقاط غير مفعّل حاليًا.', $say(['loyalty_enabled' => '0']));
    }

    /* ═════════════ اللغتان ═════════════ */

    public function test_both_languages_in_their_direction(): void
    {
        $this->order($this->maryam, 'INV-EN');
        $cookie = $this->enter($this->maryam);

        $ar = $this->my('', $cookie)->getContent();
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $ar);
        $this->assertStringContainsString('Powered by ABAAD', $ar);

        // لغةُ العميل في سجلّه، وما اختاره في جهازه يسبقها
        $this->maryam->update(['language' => 'en']);
        $en = $this->my('', $cookie)->getContent();
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $en);
        foreach (['Welcome, مريم البلوشي', 'Your purchases', 'Loyalty points', 'View invoice', 'Paid'] as $said) {
            $this->assertStringContainsString($said, $en);
        }
        $this->assertSame(['ar', 'en'], Texts::LANGS);
        $this->assertSame(array_keys(Texts::all('ar')), array_keys(Texts::all('en')), 'لا نصَّ بلغةٍ دون أختها');

        $this->visitor($cookie)->get('http://ribbon.abaadapp.om/my?lang=ar')->assertRedirect()->assertCookie('rb_my_lang', 'ar');
    }
}
