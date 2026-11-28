<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueReturns;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Lists\SalesRegister;
use App\Support\Lists\TransactionsList;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\Receivables;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عرضُ حال السداد لا يغيّر رقمًا — والإثباتُ قبلُ وبعد.
 *
 * يبني متجرًا فيه كلُّ ما يمسّه العرض: دفعٌ عند الاستلام سُدّ بعضُه بشيكٍ لم يُصرف،
 * وفاتورةٌ لطلبين قُبض بعضُها وعليها إرجاع، وبيعةُ صندوقٍ نقديّة، وبيعٌ آجلٌ سُدّ بشيكٍ
 * ارتدّ، وطلبٌ ملغى قُبضت فاتورتُه. ثمّ يقرأ أرقامَ الدفتر والسجلّ والملخّص والذمم
 * بأدواتٍ قائمةٍ في main قبل هذا الطلب، ويقارنها بأرقامٍ مكتوبة.
 *
 * والملفُّ نفسُه يجري على main بلا تعديل — فمروره هناك وهنا معًا هو الإثبات أنّ
 * الأرقامَ قبل الإصلاح وبعده واحدة (`round16/paystate/figures-main.log`).
 */
class ThePaymentStateOnScreenChangesNoFigureTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Product $vase;

    private Product $ring;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Muscat'));
        $this->shop = Business::create(['name' => 'متجر الأرقام', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'figures', 'tier' => 'gold',
            'storefront_theme' => Business::THEME_RIBBON, 'boutiques_enabled' => true, 'order_notes_and_edits_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        foreach (['vat_enabled' => '0', 'pay_credit' => '1'] as $k => $v) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $k, 'value' => $v]);
        }
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'figures@abaad.test', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true, 'returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'ع 1']);
        $this->vase = Product::create(['business_id' => $this->shop->id, 'name' => 'مزهرية', 'price' => 15, 'cost' => 5, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $this->ring = Product::create(['business_id' => $this->shop->id, 'name' => 'خاتم', 'price' => 10, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $lama->id]);
        $this->actingAs($this->owner);
        session(['current_branch' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cod(string $phone): Order
    {
        return WebCheckout::place($this->shop->fresh(), [
            'items' => [['id' => $this->vase->id, 'variant_id' => null, 'qty' => 1], ['id' => $this->ring->id, 'variant_id' => null, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => $phone, 'date' => '2027-02-11',
        ], 'ar');
    }

    private function invoice(Order ...$orders): CustomerInvoice
    {
        $customer = Customer::findOrFail($orders[0]->fresh()->customer_id);

        return CustomerInvoices::consolidate($customer, array_map(fn (Order $o) => $o->id, $orders), [], $this->owner->id);
    }

    private function collect(CustomerInvoice $invoice, float $amount, array $extra = []): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, Customer::findOrFail($invoice->customer_id), $amount, $extra + ['method' => 'نقدي'], [$invoice->id => $amount], $this->owner->id);
    }

    /** ما تقوله الأرقام — بأدواتٍ في main قبل هذا الطلب */
    private function figures(): array
    {
        $bid = $this->shop->id;
        $req = Request::create('/admin/customer-invoices/sales', 'GET');
        $summary = $this->get(route('admin.finance.summary', ['range' => 'month']))->assertOk()->viewData('page')['props'];
        $tb = Ledger::trialBalance($bid);
        $list = TransactionsList::summary(TransactionsList::filtered(Request::create('/admin/finance/transactions')));

        return [
            'register' => SalesRegister::totals($req),
            'summary_period' => $summary['period'],
            'transactions' => ['in' => $list['in'], 'out' => $list['out'], 'transfers' => $list['transfers']],
            'net_revenue' => Ledger::netRevenue($bid),
            'trial_balance' => collect($tb['accounts'])->filter(fn ($a) => abs($a['debit']) + abs($a['credit']) > 0)
                ->map(fn ($a) => [$a['code'], round($a['debit'], 3), round($a['credit'], 3)])->values()->all(),
            'balanced' => round(collect($tb['accounts'])->sum('debit') - collect($tb['accounts'])->sum('credit'), 3),
            'invoices' => CustomerInvoice::where('business_id', $bid)->orderBy('id')->get()
                ->map(fn (CustomerInvoice $i) => [$i->status, round((float) $i->total, 3), round($i->paidTotal(), 3), round($i->outstanding(), 3)])->all(),
            'receivables' => round(Receivables::outstanding($bid), 3),
            'customer_credit' => round(Receivables::credit($bid), 3),
            'cheques' => Cheques::summary($bid),
            'payment_status' => DB::table('orders')->where('business_id', $bid)->orderBy('id')->pluck('payment_status')->all(),
            'rows' => [
                'payments' => DB::table('customer_payments')->count(),
                'allocations' => DB::table('customer_payment_allocations')->count(),
                'transactions' => DB::table('transactions')->count(),
                'entries' => DB::table('journal_entries')->count(),
                'notes' => DB::table('customer_credit_notes')->count(),
            ],
        ];
    }

    public function test_the_figures_are_the_ones_main_reads(): void
    {
        $bank = BankAccount::create(['business_id' => $this->shop->id, 'bank_name' => 'بنك مسقط', 'label' => 'الرئيسي', 'active' => true, 'is_primary' => true]);
        $cheque = ['method' => 'شيك', 'bank_account_id' => $bank->id, 'occurred_at' => '2027-02-10'];

        // ١) دفعٌ عند الاستلام 25: شيكٌ 10 لم يُصرف ونقدٌ 5 — والباقي 10
        $a = $this->cod('96899120001');
        $this->collect($inv = $this->invoice($a), 10, $cheque);
        $this->collect($inv->fresh(), 5);

        // ٢) فاتورةٌ لطلبين (50): قُبض 30، وإرجاعٌ 10 عليها
        $b = $this->cod('96899120002');
        Carbon::setTestNow(Carbon::parse('2027-02-10 11:00:00', 'Asia/Muscat'));
        $c = $this->cod('96899120002');
        $shared = $this->invoice($b, $c);
        $this->collect($shared, 30);
        CustomerInvoices::creditNote($shared->fresh(), 10, 0, 'أعاد خاتمًا', $this->owner->id, basis: CustomerCreditNote::RETURN);

        // ٣) بيعةُ صندوقٍ نقديّة 15
        $this->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 15]],
            'payment_method' => 'نقدي', 'client_uuid' => 'figures-cash',
        ])->assertOk();

        // ٤) بيعٌ آجلٌ 25 سُدّ بشيكٍ ارتدّ
        $company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222223', 'allow_credit_sales' => true, 'credit_limit' => null]);
        $this->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 15], ['id' => $this->ring->id, 'name' => 'خاتم', 'qty' => 1, 'price' => 10]],
            'payment_method' => 'نقدي', 'client_uuid' => 'figures-credit', 'customer_id' => $company->id, 'credit' => true,
        ])->assertOk();
        $credit = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $creditInv = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($credit->id))->firstOrFail();
        $bounced = $this->collect($creditInv, 25, $cheque);
        Cheques::bounce($bounced->fresh(), 'رصيد غير كافٍ', $this->owner->id);

        // ٥) طلبٌ ملغى قُبضت فاتورتُه 25
        $d = $this->cod('96899120004');
        $this->collect($dInv = $this->invoice($d), 25);
        OrderCorrection::cancel($d->fresh(), 'أُعيد', true);

        $figures = $this->figures();
        file_put_contents(storage_path('framework/testing/payment-state-figures.json'), json_encode($figures, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // الأرقامُ مكتوبةً — هي ما يقرؤه main قبل الإصلاح (الملفُّ نفسُه جرى عليه)
        $expected = json_decode((string) file_get_contents(__DIR__.'/fixtures/payment-state-figures.json'), true);
        $this->assertEquals($expected, json_decode(json_encode($figures), true), 'رقمٌ تغيّر');

        // وقراءةُ الشاشات كلِّها لا تكتب شيئًا ولا تغيّر رقمًا
        foreach ([$a, $b, $c, $credit, $d] as $o) {
            $this->get(route('admin.orders.show', $o->fresh()->number))->assertOk();
            $this->withSession(['current_branch' => $this->branch->id])->get(route('pos.order-details', $o->fresh()->number))->assertOk();
        }
        $this->get(route('admin.preparation.index'))->assertOk();
        $this->get(route('admin.customerInvoices.sales'))->assertOk();
        SalesRegister::rows(Request::create('/admin/customer-invoices/sales', 'GET'));
        $this->assertEquals($expected, json_decode(json_encode($this->figures()), true), 'قراءةُ الشاشات غيّرت رقمًا');
    }
}
