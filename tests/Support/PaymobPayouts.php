<?php

namespace Tests\Support;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\PaymobSettlement;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Bank;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\PaymentLinks;
use App\Support\PaymobSettlements;
use App\Support\Store\Paymob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * متجرٌ بموقعٍ وPaymob وحسابٍ بنكيّ، ومدفوعاتُه بأنواعها — لاختبارات تسويات Paymob.
 *
 * - `newSale`: بيعةُ موقعٍ بالبطاقة اليوم (#168) — إشعارُ Paymob الموقَّع ⇒ في التسوية (1360).
 * - `oldSale`: بيعةٌ كما رحّلها الإصدارُ السابق — في البنك يومَ البيع.
 * - `paidLink`: رابطُ دفعٍ دُفع ⇒ تحصيلُه في التسوية.
 * - `legacyPayout`: تسويةٌ كما كتبها الإصدارُ السابق — ثلاثةُ أرقام، بلا حالٍ ولا مدفوعات.
 */
trait PaymobPayouts
{
    protected Business $shop;

    protected User $owner;

    protected Product $rose;

    /** متجرٌ بموقعٍ وPaymob وحسابٍ بنكيّ رئيسيّ */
    protected function openShop(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000001',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        $rose = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 0, 'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876', 'active' => true,
        ]);
        $this->actingAs($owner)->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 0, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        return [$shop, $owner, $rose];
    }

    /** بيعةُ موقعٍ بالبطاقة اليوم (#168): زبونٌ يدفع ويصل إشعارُ Paymob الموقَّع ⇒ في التسوية */
    protected function newSale(int $qty = 1, ?Business $shop = null): StorePaymentIntent
    {
        $shop ??= $this->shop;
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);
        $this->postJson('/s/'.$shop->site_slug.'/checkout', [
            'items' => [['id' => Product::where('business_id', $shop->id)->value('id'), 'qty' => $qty]],
            'name' => 'زبون', 'phone' => '95000001', 'fulfil' => 'pickup', 'date' => now()->addDays(2)->toDateString(), 'pay' => 'card',
        ])->assertOk();

        $intent = StorePaymentIntent::where('business_id', $shop->id)->latest('id')->firstOrFail();
        $obj = [
            'amount_cents' => 20000 * $qty, 'created_at' => '2027-02-10T10:00:10.100000', 'currency' => 'OMR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 990000 + $intent->id,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 770000 + $intent->id, 'merchant_order_id' => $intent->reference], 'owner' => 4705,
            'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();

        return $intent->fresh();
    }

    /**
     * بيعةُ موقعٍ بالبطاقة قبل #168 — كما رحّلها الإصدارُ السابق حرفًا: يُدان
     * البنكُ بإجماليّها يومَ البيع (`Bank::leaf` وإلّا «البنك»)، والنيّةُ مدفوعة.
     */
    protected function oldSale(float $amount = 20, ?int $bankAccountId = null): StorePaymentIntent
    {
        $branch = Branch::where('business_id', $this->shop->id)->value('id');
        $order = Order::forceCreate([
            'business_id' => $this->shop->id, 'number' => 'OLD-'.Order::count(), 'channel' => 'website', 'payment_method' => 'بطاقة',
            'payment_status' => 'مدفوع', 'status' => 'مكتمل', 'subtotal' => $amount, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0,
            'total' => $amount, 'branch_id' => $branch, 'ordered_at' => now()->subDays(3), 'customer_name' => 'زبون', 'balance_due' => 0, 'paid_after_sale' => 0,
        ]);
        OrderItem::forceCreate(['order_id' => $order->id, 'product_id' => $this->rose->id, 'name' => 'باقة ورد', 'price' => $amount, 'quantity' => 1, 'total' => $amount, 'cost' => 0]);
        Ledger::post($this->shop->id, 'مبيعات '.$order->number, [
            ['account' => Bank::leaf($this->shop->id, $bankAccountId) ?? 'bank', 'debit' => $amount],
            ['account' => 'sales_website', 'credit' => $amount],
        ], now()->subDays(3), Books::SALE, $branch, $this->owner->id, $order);

        return StorePaymentIntent::forceCreate([
            'business_id' => $this->shop->id, 'reference' => 'OLD-INTENT-'.$order->id, 'payload' => '{}', 'amount' => $amount,
            'status' => StorePaymentIntent::PAID, 'provider_transaction_id' => 'OLD-TX-'.$order->id, 'order_id' => $order->id, 'paid_at' => now()->subDays(3),
        ]);
    }

    /** رابطُ دفعٍ دُفع — يُكتب تحصيلُه في التسوية كما يكتبه إشعارُ Paymob */
    protected function paidLink(float $amount = 30): CustomerPaymentLink
    {
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل', 'phone' => '9500'.random_int(1000, 9999)]);
        $link = CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'reference' => 'pay-'.uniqid(),
            'amount' => $amount, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING, 'provider_link_id' => '1',
        ]);
        PaymentLinks::settle($link, 'TX-'.$link->id);

        return $link->fresh();
    }

    /**
     * آلافُ روابطَ مدفوعة بلا آلاف طلبات: رابطٌ يُدفع كما يدفعه إشعارُ Paymob
     * (`paidLink`)، ثمّ تُنسخ صفوفُه نفسُها — التحصيلُ ورابطُه وقيدُه وسطورُه —
     * بأرقامٍ جديدة. فما يقرؤه التصنيفُ صفوفٌ حقيقيّة، وكلٌّ في «تحت التسوية» بمبلغه.
     *
     * @return list<string> مفاتيحُها بترتيب دفعها: link:12 …
     */
    protected function manyPaidLinks(int $n, float $amount = 1.0): array
    {
        $seed = $this->paidLink($amount);
        $pay = (array) DB::table('customer_payments')->where('id', $seed->customer_payment_id)->first();
        $link = (array) DB::table('customer_payment_links')->where('id', $seed->id)->first();
        $entries = DB::table('journal_entries')->where('sourceable_type', CustomerPayment::class)->where('sourceable_id', $pay['id'])->get()->map(fn ($e) => (array) $e);
        $lines = DB::table('journal_lines')->whereIn('journal_entry_id', $entries->pluck('id'))->get()->map(fn ($l) => (array) $l)->groupBy('journal_entry_id');
        $keys = [$this->key($seed)];

        for ($i = 1; $i < $n; $i++) {
            $pid = DB::table('customer_payments')->insertGetId(['number' => $pay['number'].'-'.$i] + Arr::except($pay, ['id', 'number']));
            $keys[] = 'link:'.DB::table('customer_payment_links')->insertGetId([
                'reference' => $link['reference'].'-'.$i,
                'provider_transaction_id' => $link['provider_transaction_id'] !== null ? $link['provider_transaction_id'].'-'.$i : null,
                'customer_payment_id' => $pid,
            ] + Arr::except($link, ['id', 'reference', 'provider_transaction_id', 'customer_payment_id']));

            foreach ($entries as $e) {
                $eid = DB::table('journal_entries')->insertGetId(['number' => $e['number'].'-'.$i, 'sourceable_id' => $pid] + Arr::except($e, ['id', 'number', 'sourceable_id']));
                DB::table('journal_lines')->insert($lines[$e['id']]->map(fn (array $l) => ['journal_entry_id' => $eid] + Arr::except($l, ['id', 'journal_entry_id']))->all());
            }
        }

        // آلافُ صفوفٍ في معاملة الاختبار لم يرها المخطِّط: بلا إحصاءٍ يختار مسحًا كاملًا
        // لكلّ استعلام. والإنتاجُ يُحلَّل وحده — هذا للاختبار وحده.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ANALYZE customer_payments, customer_payment_links, journal_entries, journal_lines, paymob_settlement_items');
        }

        return $keys;
    }

    protected function key(StorePaymentIntent|CustomerPaymentLink $p): string
    {
        return ($p instanceof StorePaymentIntent ? 'intent:' : 'link:').$p->id;
    }

    protected function settle(array $payments, float $fee, float $net, array $extra = []): PaymobSettlement
    {
        return PaymobSettlements::record($this->shop->id, [
            'items' => array_map(fn ($p) => $this->key($p), $payments), 'fee' => $fee, 'net' => $net, 'settled_on' => '2027-02-13',
        ] + $extra, $this->owner->id);
    }

    /** مالُ البنك كلُّه: كلُّ أوراقه */
    protected function bank(?int $shop = null): float
    {
        return round(Bank::total($shop ?? $this->shop->id), 3);
    }

    protected function clearing(?int $shop = null): float
    {
        return round(Ledger::balance($shop ?? $this->shop->id, 'paymob_clearing'), 3);
    }

    protected function fees(): float
    {
        return round(Ledger::balance($this->shop->id, 'payment_gateway_fees'), 3);
    }

    /** بصمةُ كلِّ قيدٍ وسطرٍ حتى رقمٍ — والعكسُ فيها: التسويةُ لا تعكس شيئًا */
    protected function history(int $upto): string
    {
        return md5(json_encode([
            DB::table('journal_entries')->where('id', '<=', $upto)->orderBy('id')
                ->get(['id', 'business_id', 'number', 'entry_date', 'source', 'sourceable_type', 'sourceable_id', 'posted', 'reverses_id', 'reversed_at']),
            DB::table('journal_lines')->where('journal_entry_id', '<=', $upto)->orderBy('id')->get(['id', 'journal_entry_id', 'account_id', 'debit', 'credit']),
        ]));
    }

    protected function settleFor(int $shop, array $payments, float $fee, float $net): PaymobSettlement
    {
        return PaymobSettlements::record($shop, [
            'items' => array_map(fn ($p) => $this->key($p), $payments), 'fee' => $fee, 'net' => $net, 'settled_on' => '2027-02-13',
        ], null);
    }

    /**
     * تسويةٌ كما كتبها `PaymobSettlements::record` قبل #168 حرفًا: صفٌّ بالإجماليّ
     * والرسوم والصافي — لا حالَ يكتبه (فيقرأ العمودُ افتراضَه) ولا مدفوعاتٍ يسمّيها —
     * وقيدٌ يُدين البنكَ بالصافي والرسومَ بالرسوم من التسوية بالإجماليّ.
     */
    protected function legacyPayout(float $gross, float $fee, ?int $shop = null): PaymobSettlement
    {
        $shop ??= $this->shop->id;
        $bank = BankAccount::where('business_id', $shop)->where('is_primary', true)->value('id');
        $id = DB::table('paymob_settlements')->insertGetId([
            'business_id' => $shop, 'bank_account_id' => $bank, 'gross' => $gross, 'fee' => $fee, 'net' => round($gross - $fee, 3),
            'settled_on' => now()->toDateString(), 'reference' => 'OLD-PMB', 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $settlement = PaymobSettlement::findOrFail($id);
        $lines = [['account' => Bank::leaf($shop, $bank) ?? 'bank', 'debit' => round($gross - $fee, 3)]];
        if ($fee > 0) {
            $lines[] = ['account' => 'payment_gateway_fees', 'debit' => $fee];
        }
        $lines[] = ['account' => 'paymob_clearing', 'credit' => $gross];
        $entry = Ledger::post($shop, 'تسوية Paymob — OLD-PMB', $lines, now(), PaymobSettlements::SOURCE, null, null, $settlement);
        $settlement->forceFill(['journal_entry_id' => $entry->id])->save();

        return $settlement->fresh();
    }
}
