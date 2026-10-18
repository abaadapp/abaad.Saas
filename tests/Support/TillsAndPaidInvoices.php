<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoicePreparation;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\PosTerminal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * متجرٌ فُتح له التجهيزُ بعد السداد، وصناديقُه، وفاتورةٌ تُسدَّد فتُكتب ورقتُها.
 *
 * ومتجرٌ جارٌ مفتوحٌ له كذلك — ليُقاس أنّ صندوقَ أحدهما لا يبلغ ورقَ الآخر.
 * والصناديقُ تُنشأ صفوفًا مباشرةً ببصمةٍ تُحمل في الكوكي: `activatePosDevice`
 * يبدّل كوكي الجهاز، فيصير كلُّ طلبٍ بعده صادرًا عن آخر صندوقٍ فُعِّل.
 */
trait TillsAndPaidInvoices
{
    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $branch;

    private Branch $elsewhere;

    /** @var array<int, string> */
    private array $tokens = [];

    private function setUpTills(): void
    {
        Carbon::setTestNow('2026-10-19 09:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $this->other->forceFill(['customer_invoice_preparation_enabled' => true])->save();

        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوير']);
        $this->elsewhere = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع صحار']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@till.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs($this->owner);
    }

    /** صندوقٌ في فرعٍ — وطابعتُه تستلم فواتيرَ العملاء إن طُلب */
    private function till(?Branch $branch = null, bool $designated = true, ?Business $shop = null): PosDevice
    {
        $shop ??= $this->shop;
        $raw = Str::random(64);
        $device = PosDevice::create([
            'business_id' => $shop->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'name' => 'صندوق '.Str::random(4),
            'token_hash' => hash('sha256', $raw),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);
        $this->tokens[$device->id] = $raw;

        $device->peripherals()->create([
            'business_id' => $shop->id, 'name' => 'طابعة الكاشير', 'type' => PosPeripheral::PRINTER,
            'connection' => 'network', 'address' => '192.168.1.50', 'port' => 9100, 'paper_width' => 80,
            'active' => true, 'auto_print_customer_invoices' => $designated,
        ]);

        return $device;
    }

    /** الطلبُ صادرٌ من هذا الصندوق — بصمتُه في كوكيه */
    private function at(PosDevice $device, ?User $user = null)
    {
        // و`withCredentials`: طلبُ JSON في الاختبار لا يحمل الكوكي بدونها — كما لا يحمله `fetch` بلا `credentials`
        return $this->actingAs($user ?? $this->owner)
            ->withCredentials()
            ->withCookie(PosTerminal::COOKIE, $device->id.'|'.$this->tokens[$device->id]);
    }

    /**
     * فاتورةٌ تُسدَّد فتظهر مهمّتُها — وتُكتب ورقةُ صندوقها.
     *
     * ولمتجر الجار بفرعه وزبونه هو: لا شيءَ فيها من هذا المتجر.
     */
    private function paidTask(?Branch $branch = null, ?Business $shop = null, float $price = 40): CustomerInvoicePreparation
    {
        $shop ??= $this->shop;
        $customer = $shop->is($this->shop)
            ? $this->customer
            : Customer::firstOrCreate(['business_id' => $shop->id, 'name' => 'زبون الجار'], ['phone' => '92345678', 'allow_credit_sales' => true]);
        $branch ??= $shop->is($this->shop)
            ? $this->branch
            : Branch::firstOrCreate(['business_id' => $shop->id, 'name' => 'فرع الجار']);

        $invoice = CustomerInvoices::issue(CustomerInvoices::create($shop->id, $customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => $price],
        ]));
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $branch->id,
            'scheduled_for' => '2026-10-19 15:00', 'recipient_name' => 'سالم', 'recipient_phone' => '91234567',
        ], $this->owner->id);
        CustomerPayments::record($shop->id, $customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2026-10-19'], [$invoice->id => (float) $invoice->total]);

        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        return $task;
    }

    private function claim(PosDevice $device): ?array
    {
        return $this->at($device)->postJson(route('pos.printJobs.claim'))->assertOk()->json('job');
    }

    private function ack(PosDevice $device, int $id, array $body): array
    {
        return $this->at($device)->postJson(route('pos.printJobs.ack', $id), $body)->assertOk()->json();
    }

    /** «خرجت» أو «لم تخرج» — من الصندوق */
    private function answer(PosDevice $device, int $id, bool $printed): array
    {
        return $this->at($device)->postJson(route('pos.printJobs.confirm', $id), ['printed' => $printed])->assertOk()->json();
    }

    /** ما في طابور هذا الصندوق — كما يقرؤه */
    private function queue(PosDevice $device): array
    {
        return $this->at($device)->getJson(route('pos.printJobs.index'))->assertOk()->json();
    }
}
