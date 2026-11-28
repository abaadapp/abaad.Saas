<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PreparationController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\FlowerOrder;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\Permissions;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * لوحةُ التجهيز لوحةُ النشاط كلِّه — لا لوحةُ من أنشأ الطلب.
 *
 * ═══ العطبُ كما وصل ═══
 *
 * «لا تظهر جميع الطلبات ومهام الفواتير لكل من يجهّز». وله سببان:
 *
 *   ١ — اللوحةُ تُقصّ بالفرع المحفوظ في **جلسة** من يفتحها
 *       (`session('current_branch')`): موظّفان بالصلاحيّة نفسِها يريان لوحتين،
 *       ولا كلمةَ على الشاشة تقول إنّ فيها ترشيحًا. وفي المقابل لا يُسأل عن
 *       فروعه المُسنَد إليها: من قُيِّد بفرعٍ وجلستُه «كل الفروع» يرى الجميع.
 *   ٢ — فاتورةُ العميل لا تصل اللوحةَ وحدها إلّا برابط Paymob. فالنقدُ
 *       والبطاقةُ والتحويلُ — ولو سُدّدت كلُّها — تنتظر زرًّا لا يُضغط.
 *
 * ═══ وما يُحرس ═══
 *
 *   - ما تعرضه اللوحةُ نطاقُ **صلاحيّة** (متجره، وفروعُه المُسنَد إليها): لا
 *     منشئُ الطلب ولا الجلسةُ ولا جهازُ الصندوق ولا من قبض.
 *   - والنطاقُ نفسُه لكلّ بابٍ: البطاقةُ والعدّادُ والنقلُ والعلامةُ والورقةُ
 *     والسجلّ.
 *   - والفاتورةُ المسدَّدةُ كلُّها تصل مرّةً واحدة أيًّا كانت وسيلةُ سدادها —
 *     بلا قيدٍ ولا تحصيلٍ ولا حركةِ مخزونٍ زائدة.
 */
class ThePreparationBoardIsSharedByWhoeverPreparesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $khuwair;

    private User $owner;

    private User $first;

    private User $second;

    private Customer $customer;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');
        app()->setLocale('ar');

        $this->shop = $this->shop('ورود الخليج');
        $this->khuwair = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        $this->owner = $this->user($this->shop, 'المالك', 'admin');
        $this->first = $this->user($this->shop, 'الموظف الأول', 'manager');
        $this->second = $this->user($this->shop, 'الموظف الثاني', 'manager');
        $this->customer = $this->customer($this->shop);
        $this->rose = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function shop(string $name, bool $invoices = true, array $over = []): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط'] + $over);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $shop->forceFill(['customer_invoice_preparation_enabled' => $invoices])->save();

        return $shop;
    }

    private function user(Business $shop, string $name, string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => $name, 'email' => uniqid('u').'@shared.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** من يجهّز وحدَه — لا مبيعاتٌ ولا فواتيرُ ولا ماليّة */
    private function bench(?Business $shop = null): User
    {
        return $this->user($shop ?? $this->shop, 'المجهّز', 'cashier', ['preparation']);
    }

    private function customer(Business $shop): Customer
    {
        return Customer::create(['business_id' => $shop->id, 'name' => 'شركة الضيافة', 'phone' => '91234567', 'allow_credit_sales' => true]);
    }

    private function order(array $over = []): Order
    {
        return Order::create(array_merge([
            'business_id' => $this->shop->id, 'branch_id' => $this->khuwair->id, 'branch' => $this->khuwair->name,
            'number' => 'INV-'.strtoupper(uniqid()), 'customer_name' => 'ليلى', 'employee_name' => 'موظف',
            'status' => OrderStatus::CONFIRMED, 'is_held' => false, 'channel' => SalesChannel::POS,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 20, 'total' => 20,
            'ordered_at' => now(), 'scheduled_for' => now()->addHours(3), 'fulfillment_type' => FlowerOrder::PICKUP,
        ], $over));
    }

    /** بيعةٌ من الصندوق الحقيقيّ بموعد — يبيعها هذا الموظّف */
    private function till(User $seller, array $over = []): Order
    {
        $number = $this->actingAs($seller)->withCredentials()->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->rose->id, 'name' => $this->rose->name, 'qty' => 1]],
            'payment_method' => 'نقدي', 'customer' => 'خالد المشتري',
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ], $over))->assertOk()->json('invoice');

        return Order::where('business_id', $this->shop->id)->where('number', $number)->firstOrFail();
    }

    private function board(User $user, array $query = [], array $session = []): array
    {
        return $this->actingAs($user)->withSession($session + ['current_branch' => null])
            ->get(route('admin.preparation.index', $query))->assertOk()->viewData('page')['props'];
    }

    /** مفاتيحُ البطاقات على لوحة هذا المستخدم */
    private function keys(User $user, array $query = [], array $session = []): array
    {
        return collect($this->board($user, $query, $session)['orders'])->pluck('key')->sort()->values()->all();
    }

    private function prepInput(array $over = []): array
    {
        return array_merge([
            'enabled' => true, 'fulfillment_type' => FlowerOrder::PICKUP, 'branch_id' => $this->khuwair->id,
            'scheduled_for' => '2026-11-25 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
        ], $over);
    }

    /** فاتورةٌ من شاشتها — يكتبها هذا المستخدم، وتجهيزُها مطلوبٌ أو لا */
    private function write(User $by, bool $prepare = true, array $over = []): CustomerInvoice
    {
        $this->actingAs($by)->post(route('admin.customerInvoices.store'), array_merge([
            'customer_id' => $this->customer->id, 'issue' => true,
            'payment_method' => CustomerInvoices::CREDIT,
            'items' => [['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50]],
        ], $prepare ? ['preparation' => $this->prepInput()] : [], $over))->assertSessionHasNoErrors();

        return CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    /** تحصيلٌ من بابه — يسجّله هذا المستخدم */
    private function pay(User $by, CustomerInvoice $invoice, float $amount, string $method = 'نقدي', bool $named = true): void
    {
        $this->actingAs($by)->post(route('admin.customerPayments.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(), 
            'customer_id' => $invoice->customer_id, 'amount' => $amount, 'method' => $method,
        ] + ($named ? ['customer_invoice_id' => $invoice->id] : []))->assertSessionHasNoErrors();
    }

    private function prep(CustomerInvoice $invoice): CustomerInvoicePreparation
    {
        return CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->firstOrFail();
    }

    private function key(CustomerInvoice $invoice): string
    {
        return 'customer-invoice:'.$this->prep($invoice)->id;
    }

    /* ============================ ١ — الطلباتُ لمن يجهّز، لا لمن أنشأها ============================ */

    /** ١ و٢ و٣ — طلبُ المالك والأوّلِ والثاني على لوحة الثلاثة، ومن يجهّز وحدَه */
    public function test_whoever_made_the_order_it_stands_on_every_preparers_board(): void
    {
        // ولكلٍّ صندوقُه — جهازٌ لكلّ بائع
        $made = collect([$this->owner, $this->first, $this->second])->map(function (User $u) {
            $this->activatePosDevice($this->shop->id, $this->khuwair->id);

            return 'order:'.$this->till($u)->number;
        })->sort()->values()->all();
        $this->assertSame(3, Order::where('business_id', $this->shop->id)->distinct()->count('pos_device_id'), 'ثلاثةُ صناديق');

        foreach ([$this->owner, $this->first, $this->second, $this->bench()] as $who) {
            $this->assertSame($made, $this->keys($who), 'لوحةُ «'.$who->name.'»');
            $this->assertSame(3, $this->board($who)['counts']['all']);
        }
    }

    /**
     * ٤ — والجلسةُ القديمةُ وجهازُ الصندوق والورديةُ لا تُخفي طلبًا.
     *
     * هذا هو العطبُ نفسُه: موظّفٌ بقي في جلسته «السيب» فلا يرى طلبَ الخوير،
     * وزميلُه يراه — ولا شيءَ على الشاشة يقول لماذا.
     */
    public function test_a_stale_session_a_till_or_a_shift_hides_nothing(): void
    {
        $seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        $here = $this->order();
        $there = $this->order(['branch_id' => $seeb->id, 'branch' => 'السيب']);
        $all = collect([$here, $there])->map(fn ($o) => 'order:'.$o->number)->sort()->values()->all();

        foreach ([null, $this->khuwair->id, $seeb->id] as $stale) {
            $this->assertSame($all, $this->keys($this->first, [], ['current_branch' => $stale]), 'جلسةٌ على الفرع '.var_export($stale, true));
        }

        // وجهازُ صندوق السيب في المتصفّح نفسِه — والصندوقُ مفتوحٌ عليه
        $till = $this->activatePosDevice($this->shop->id, $seeb->id);
        $this->actingAs($this->first)->get(route('pos.index'))->assertOk();
        $this->assertSame($all, $this->keys($this->first));

        // وطلبٌ من جهازٍ آخر وورديّةٍ مغلقةٍ وكاشيرٍ لم يعد — صفوفٌ قديمةٌ تبقى للتقارير
        $shift = Shift::create([
            'business_id' => $this->shop->id, 'branch_id' => $seeb->id, 'pos_device_id' => $till->id, 'user_id' => $this->second->id,
            'employee_name' => 'كاشير سابق', 'opened_at' => now()->subDays(40), 'closed_at' => now()->subDays(39), 'opening_balance' => 0, 'status' => Shift::CLOSED,
        ]);
        $shifted = $this->order(['pos_device_id' => $till->id, 'shift_id' => $shift->id, 'user_id' => $this->second->id, 'employee_name' => 'كاشير سابق']);
        foreach ([$this->owner, $this->first, $this->bench()] as $who) {
            $this->assertContains('order:'.$shifted->number, $this->keys($who));
        }
        $shifted->delete();

        // وكلُّ بابٍ يبلغ ما تعرضه اللوحة — لا يُعرض طلبٌ ويُردّ نقلُه
        $this->actingAs($this->first)->withSession(['current_branch' => $seeb->id])
            ->post(route('admin.preparation.move', $here->number), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::PREPARING, $here->fresh()->status);
        $this->actingAs($this->first)->withSession(['current_branch' => $seeb->id])
            ->post(route('admin.preparation.check', $here->number), ['key' => 'task:packaging', 'checked' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->first)->withSession(['current_branch' => $seeb->id])
            ->get(route('admin.preparation.timeline', $here->number))->assertOk();
    }

    /** وترشيحُ الفرع اختيارٌ ظاهرٌ في اللوحة يُلغى — والعدّاداتُ تتبعه */
    public function test_choosing_a_branch_is_named_on_the_board_and_can_be_dropped(): void
    {
        $seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        $here = $this->order();
        $this->order(['branch_id' => $seeb->id]);

        $props = $this->board($this->first, ['branch' => $this->khuwair->id]);
        $this->assertSame(['order:'.$here->number], array_column($props['orders'], 'key'));
        $this->assertSame($this->khuwair->id, $props['filters']['branch']);
        $this->assertSame(1, $props['counts']['all']);
        $this->assertSame(1, $props['typeCounts']['all']);
        $this->assertSame(1, array_sum($props['columnCounts']));
        $this->assertSame(['الخوير', 'السيب'], array_column($props['branches'], 'name'));

        $all = $this->board($this->first);
        $this->assertNull($all['filters']['branch']);
        $this->assertCount(2, $all['orders']);

        // وفرعٌ لا وجودَ له — أو من متجرٍ آخر — يعني «الكل» لا لوحةً فارغة
        $foreign = Branch::create(['business_id' => $this->shop('الجار')->id, 'name' => 'فرع الجار']);
        foreach ([$foreign->id, 999999, 'x'] as $bad) {
            $odd = $this->board($this->first, ['branch' => $bad]);
            $this->assertNull($odd['filters']['branch']);
            $this->assertCount(2, $odd['orders']);
        }
    }

    /** ومتجرٌ بفرعٍ واحد لا يُعرض له مُرشِّحٌ لا معنى له */
    public function test_a_single_branch_shop_is_offered_no_branch_filter(): void
    {
        $this->order();
        $props = $this->board($this->first, [], ['current_branch' => $this->khuwair->id]);

        $this->assertCount(1, $props['orders']);
        $this->assertSame([], $props['branches']);

        // ومن أُسند إليه لا يُقال له «فروعك» — هو فرعُ المتجر كلُّه
        $bench = $this->bench();
        $bench->branches()->sync([$this->khuwair->id]);
        $this->assertNull($this->board($bench)['limited']);
        $this->assertCount(1, $this->board($bench)['orders']);
    }

    /* ============================ ٢ — الفرعُ صلاحيّةٌ لا جلسة ============================ */

    /** ١٦ — من قُيِّد بفرعٍ لا يرى غيرَه ولا يبلغه من بابٍ واحد — مهما قالت جلستُه */
    public function test_a_branch_he_is_not_assigned_to_is_closed_at_every_door(): void
    {
        $seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        $mine = $this->order();
        $theirs = $this->order(['branch_id' => $seeb->id, 'branch' => 'السيب']);
        $nowhere = $this->order(['branch_id' => null, 'branch' => '']);
        $unknown = $this->write($this->owner);
        $this->pay($this->owner, $unknown, 100);
        // ومهمّةٌ لا يُعرف فرعُها — قد تكون مهمّةَ فرعٍ لا يعمل فيه
        $this->prep($unknown)->forceFill(['branch_id' => null])->save();
        $task = $this->write($this->owner, true, ['preparation' => $this->prepInput(['branch_id' => $seeb->id])]);
        $this->pay($this->owner, $task, 100);
        $prep = $this->prep($task);

        $bench = $this->bench();
        $bench->branches()->sync([$this->khuwair->id]);

        foreach ([null, $this->khuwair->id, $seeb->id] as $session) {
            $props = $this->board($bench, [], ['current_branch' => $session]);
            $this->assertSame(['order:'.$mine->number], array_column($props['orders'], 'key'), 'طلبُ فرعه — ولا ما لا يُعرف فرعُه');
            $this->assertSame(1, $props['counts']['all'], 'والعدّادُ عدّادُ ما يُعرض');
            $this->assertSame(0, $props['unassigned'], 'ولا يُقال له عددُ ما لا يراه');
            $this->assertSame(['الخوير'], $props['limited']);
        }

        // وطلبُ فرعٍ لا يعمل فيه لا يُفتح بالمُرشِّح
        $this->assertNotContains('order:'.$theirs->number, $this->keys($bench, ['branch' => $seeb->id]));

        $this->actingAs($bench)->withSession(['current_branch' => $seeb->id]);
        $this->post(route('admin.preparation.move', $theirs->number), ['status' => OrderStatus::PREPARING])->assertNotFound();
        $this->post(route('admin.preparation.check', $theirs->number), ['key' => 'task:packaging', 'checked' => true])->assertNotFound();
        $this->get(route('admin.preparation.timeline', $theirs->number))->assertNotFound();
        $this->get(route('admin.preparation.deliveryNote', $theirs->number))->assertNotFound();
        $this->get(route('admin.preparation.deliveryNoteThermal', $theirs->number))->assertNotFound();
        $this->get(route('admin.preparation.receipt', $theirs->number))->assertNotFound();
        $this->get(route('admin.preparation.sheet', $theirs->number))->assertNotFound();
        $this->get(route('admin.preparation.giftcard', $theirs->id))->assertNotFound();
        $this->post(route('admin.preparation.invoiceMove', $prep->id), ['status' => OrderStatus::PREPARING])->assertSessionHasErrors('status');
        $this->get(route('admin.preparation.invoiceTimeline', $prep->id))->assertNotFound();
        $this->get(route('admin.preparation.invoiceSheet', $prep->id))->assertNotFound();
        $this->get(route('admin.preparation.invoiceInternalPdf', $prep->id))->assertNotFound();
        $this->get(route('admin.preparation.invoiceDeliveryNote', $prep->id))->assertNotFound();
        $this->post(route('admin.preparation.invoiceRequeue', $prep->id))->assertNotFound();
        $this->post(route('admin.preparation.invoicePrintConfirm', $prep->id))->assertNotFound();

        // وما لا فرعَ له مغلقٌ عليه من أبوابه كذلك
        $this->post(route('admin.preparation.move', $nowhere->number), ['status' => OrderStatus::PREPARING])->assertNotFound();
        $this->get(route('admin.preparation.sheet', $nowhere->number))->assertNotFound();
        $this->post(route('admin.preparation.invoiceMove', $this->prep($unknown)->id), ['status' => OrderStatus::PREPARING])->assertSessionHasErrors('status');
        $this->get(route('admin.preparation.invoiceInternalPdf', $this->prep($unknown)->id))->assertNotFound();

        $this->assertSame(OrderStatus::CONFIRMED, $theirs->fresh()->status);
        $this->assertSame(OrderStatus::CONFIRMED, $prep->fresh()->status);

        // والمالكُ يرى الكلّ ولو قُيِّد في صفوفه — كما في عمليّات الفروع — ويُقال له ما لا فرعَ له
        $this->owner->branches()->sync([$this->khuwair->id]);
        $all = $this->board($this->owner->fresh());
        $this->assertCount(5, $all['orders']);
        $this->assertSame(2, $all['unassigned']);

        // ومن لم يُقيَّد كذلك، ومن أُسند إلى الفروع كلِّها ليس مقيَّدًا
        $this->assertCount(5, $this->board($this->bench())['orders']);
        $bench->branches()->sync([$this->khuwair->id, $seeb->id]);
        $this->assertCount(5, $this->board($bench->fresh())['orders']);

        // وفرعٌ ثالثٌ في السلّة ما زال فرعًا لم يُسنَد إليه
        $third = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $third->delete();
        $this->assertCount(3, $this->board($bench->fresh())['orders']);
    }

    /** ومتجرٌ بفرعٍ واحد: كلُّ من أُسند إليه يرى ما فيه كلَّه — وما لا فرعَ له معه */
    public function test_in_a_single_branch_shop_unassigned_work_is_everyones(): void
    {
        $nowhere = $this->order(['branch_id' => null, 'branch' => '']);
        $bench = $this->bench();
        $bench->branches()->sync([$this->khuwair->id]);

        $this->assertSame(['order:'.$nowhere->number], $this->keys($bench));
        $this->assertSame(0, $this->board($this->owner)['unassigned'], 'ولا تنبيهَ لمتجرٍ بفرعٍ واحد');
    }

    /** وصفُّ فرعٍ مُحي من القاعدة يُفرّغ فرعَ مهامّه — ولا تصير مشاعًا لمن قُيِّد بغيره */
    public function test_erasing_a_branch_row_hands_its_work_to_no_restricted_worker(): void
    {
        $seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $task = $this->write($this->owner, true, ['preparation' => $this->prepInput(['branch_id' => $seeb->id])]);
        $this->pay($this->owner, $task, 100);
        $bench = $this->bench();
        $bench->branches()->sync([$this->khuwair->id]);

        $seeb->delete();
        $seeb->forceDelete();
        $this->assertNull($this->prep($task)->branch_id, 'المحوُ يُفرّغ الفرع');

        $this->assertSame([], $this->keys($bench));
        $this->assertSame([$this->key($task)], $this->keys($this->owner));
    }

    /** ومن قُيِّد بفرعٍ حُذف لا يصير بلا قيد */
    public function test_losing_his_branch_does_not_open_the_others(): void
    {
        $seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        $other = $this->order();
        $bench = $this->bench();
        $bench->branches()->sync([$seeb->id]);
        $seeb->delete();

        $this->assertNotContains('order:'.$other->number, $this->keys($bench));
        $this->assertSame([], $this->board($bench)['limited'], 'ويُقال له أنّه بلا فرع');
        $this->actingAs($bench)->post(route('admin.preparation.move', $other->number), ['status' => OrderStatus::PREPARING])->assertNotFound();
    }

    /** ١٧ — متجران: لا بطاقةَ ولا عدّادَ ولا بابَ من جارٍ، ولو حمل رقمُ طلبه رقمَ طلبنا */
    public function test_two_shops_never_meet_on_a_board(): void
    {
        $other = $this->shop('الجار');
        $branch = Branch::create(['business_id' => $other->id, 'name' => 'فرع الجار']);
        $theirs = $this->order(['business_id' => $other->id, 'branch_id' => $branch->id, 'number' => 'INV-777001']);
        $mine = $this->order(['number' => 'INV-777002']);

        $this->assertSame(['order:'.$mine->number], $this->keys($this->owner));
        $this->assertSame(['order:'.$theirs->number], $this->keys($this->user($other, 'مالك الجار', 'admin')));

        // وصفُّ إسنادٍ إلى فرع الجار — عبثًا في القاعدة — لا يفتح منه شيئًا
        $bench = $this->bench();
        $bench->branches()->sync([$branch->id]);
        $this->assertNotContains('order:'.$theirs->number, $this->keys($bench));
        $this->actingAs($bench)->post(route('admin.preparation.move', $theirs->number), ['status' => OrderStatus::PREPARING])->assertNotFound();
    }

    /* ============================ ٣ — فواتيرُ العملاء بعد السداد ============================ */

    /** ٥ و٧ — كتبها المالكُ وطلب تجهيزَها، وسجّل موظّفٌ سدادَها: على لوحة الجميع وحدها */
    public function test_an_invoice_the_owner_wrote_and_a_worker_collected_reaches_everyone(): void
    {
        $invoice = $this->write($this->owner);
        $this->assertSame([], $this->keys($this->second), 'قبل السداد لا شيء');

        $this->pay($this->first, $invoice, 100);

        foreach ([$this->owner, $this->first, $this->second, $this->bench()] as $who) {
            $this->assertSame([$this->key($invoice)], $this->keys($who), 'لوحةُ «'.$who->name.'»');
        }

        $prep = $this->prep($invoice);
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMENT, $prep->activation_source);
        $this->assertSame((int) CustomerPayment::latest('id')->value('id'), (int) $prep->activation_payment_id);
        $this->assertSame($this->first->id, (int) $prep->activated_by, 'ومن سجّل السدادَ محفوظٌ للسجلّ');
        $this->assertSame(1, PrintJob::where('subject_id', $prep->id)->count(), 'وورقةُ الصندوق مرّة');
    }

    /** ٦ — كتبها موظّفٌ وسجّل سدادَها آخر، وجهّزها ثالث */
    public function test_one_writes_another_collects_a_third_prepares(): void
    {
        $invoice = $this->write($this->first);
        $this->pay($this->second, $invoice, 100);
        $bench = $this->bench();

        $this->assertSame([$this->key($invoice)], $this->keys($bench));
        $this->actingAs($bench)->post(route('admin.preparation.invoiceMove', $this->prep($invoice)->id), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasNoErrors();

        foreach ([$this->owner, $this->first, $this->second] as $who) {
            $this->assertSame(OrderStatus::PREPARING, $this->board($who)['orders'][0]['status']);
        }
    }

    /** ٨ و٩ — جزءٌ لا يُظهرها، والدفعاتُ حين تكتمل تُظهرها مرّةً واحدة */
    public function test_parts_wait_and_the_last_part_shows_it_once(): void
    {
        $invoice = $this->write($this->owner);

        $this->pay($this->first, $invoice, 30);
        $this->pay($this->second, $invoice, 50, 'بطاقة');
        $this->assertNull($this->prep($invoice)->activated_at);
        $this->assertSame([], $this->keys($this->second));

        $this->pay($this->first, $invoice, 20, 'تحويل');
        $this->assertSame([$this->key($invoice)], $this->keys($this->second));

        /*
         * وتحصيلٌ بعدها، والمطابقةُ مرّتين، وزرُّ الإرسال — لا مهمّةَ ثانية.
         *
         * والتحصيلُ على الفاتورة نفسِها وقد سُدّدت يُردّ ولا يُكتب: لا يصير رصيدًا
         * للعميل في صمت (`CustomerPayments::record` بـ`exact`). فالمالُ الذي يأتي
         * بعدها يُسجَّل على حساب العميل — وهو ما يُسأل عنه هنا: لا مهمّةَ ثانية.
         */
        $payments = CustomerPayment::count();
        $this->actingAs($this->first)->post(route('admin.customerPayments.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $invoice->customer_id, 'customer_invoice_id' => $invoice->id, 'amount' => 10, 'method' => 'نقدي',
        ])->assertSessionHasErrors('amount');
        $this->assertSame($payments, CustomerPayment::count(), 'تحصيلٌ على فاتورةٍ مسدَّدة كُتب');
        $this->pay($this->first, $invoice, 10, 'نقدي', false);
        Artisan::call('customer-invoices:reconcile-preparations');
        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.preparation.activate', $invoice->id))->assertSessionHasNoErrors();

        $this->assertSame([$this->key($invoice)], $this->keys($this->second));
        $this->assertSame(1, CustomerInvoicePreparation::count());
        $this->assertSame(1, PrintJob::count());
    }

    /** وكلُّ وسيلةِ سدادٍ يعرفها النظام — وعند الإصدار، وعلى الحساب بلا فاتورةٍ مسمّاة */
    public function test_every_way_of_paying_counts(): void
    {
        foreach (CustomerPayments::METHODS as $method) {
            $invoice = $this->write($this->owner);
            $this->pay($this->first, $invoice, 100, $method);
            $this->assertNotNull($this->prep($invoice)->activated_at, $method);
        }

        // مدفوعةٌ لحظةَ إصدارها — التحصيلُ مع الورقة في طلبٍ واحد
        $atIssue = $this->write($this->first, true, ['payment_method' => 'نقدي']);
        $this->assertNotNull($this->prep($atIssue)->activated_at, 'مدفوعةٌ عند الإصدار');

        // وحوالةٌ على الحساب توزَّع على الأقدم — فتُسدَّد بها ورقتان
        $one = $this->write($this->owner);
        $two = $this->write($this->owner);
        $this->pay($this->second, $one, 200, 'تحويل', named: false);
        $this->assertNotNull($this->prep($one)->activated_at);
        $this->assertNotNull($this->prep($two)->activated_at);
    }

    /** ١٠ — وفاتورةٌ لم يُطلب تجهيزُها لا تصل، سُدّدت أو لم تُسدَّد */
    public function test_a_plain_invoice_never_reaches_the_board(): void
    {
        $invoice = $this->write($this->owner, prepare: false);
        $this->pay($this->first, $invoice, 100);
        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);

        $this->assertSame([], $this->keys($this->owner));
        $this->assertSame(0, CustomerInvoicePreparation::count());
        $this->assertSame(0, PrintJob::count());
    }

    /** وإلغاءُ التحصيل قبل اكتماله لا يُحسب — والإشعارُ الدائنُ ليس سدادًا */
    public function test_a_cancelled_payment_does_not_complete_it(): void
    {
        $invoice = $this->write($this->owner);
        $this->pay($this->first, $invoice, 60);
        CustomerPayments::cancel(CustomerPayment::latest('id')->firstOrFail(), 'سُجّلت خطأً', $this->owner->id);
        $this->pay($this->first, $invoice, 40);

        $this->assertNull($this->prep($invoice)->activated_at, '٤٠ من ١٠٠ ليست سدادًا كاملًا');

        $this->pay($this->second, $invoice, 60);
        $this->assertNotNull($this->prep($invoice)->activated_at);

        // ودفعةٌ تُلغى بعد الظهور لا تمحو عملًا بدأ — ولا تُقال «مدفوعة» (السلوكُ القائم)
        CustomerPayments::cancel(CustomerPayment::latest('id')->firstOrFail(), 'استرجاع', $this->owner->id);
        $card = $this->board($this->owner)['orders'][0];
        $this->assertFalse($card['payment_verified']);
    }

    /** ١٩ — ومتجرٌ لم تُفتح له الميزةُ لا يصله شيء، ولا تُفتح بسداد */
    public function test_a_shop_without_the_feature_keeps_its_board_as_it_was(): void
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => false])->save();
        $invoice = $this->write($this->owner);
        $this->pay($this->first, $invoice, 100);
        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);

        $this->assertSame(0, CustomerInvoicePreparation::count());
        $this->assertSame([], $this->keys($this->owner));
        $this->assertFalse((bool) $this->shop->fresh()->customer_invoice_preparation_enabled);

        // وطلبٌ كُتب تجهيزُه ثمّ أُقفلت الميزة — السدادُ لا يُظهره
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $later = $this->write($this->owner);
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => false])->save();
        $this->pay($this->first, $later, 100);
        $this->assertNull($this->prep($later)->activated_at);

        // والطلباتُ على لوحته كما هي
        $o = $this->order();
        $this->assertSame(['order:'.$o->number], $this->keys($this->second));
    }

    /** وسقوطُ التفعيل لا يُسقط التحصيل — والمطابقةُ تعيده */
    public function test_a_failed_activation_never_loses_the_payment(): void
    {
        $invoice = $this->write($this->owner);

        \Illuminate\Support\Facades\Schema::rename('print_jobs', 'print_jobs_away');
        $this->pay($this->first, $invoice, 100);
        \Illuminate\Support\Facades\Schema::rename('print_jobs_away', 'print_jobs');

        $this->assertSame(1, CustomerPayment::count(), 'المالُ ثابت');
        $this->assertNull($this->prep($invoice)->activated_at);
        $this->assertNotNull($this->prep($invoice)->activation_error, 'وسببُ السقوط يُرى في الفاتورة');

        Artisan::call('customer-invoices:reconcile-preparations');
        $this->assertNotNull($this->prep($invoice)->activated_at);
        $this->assertNull($this->prep($invoice)->activation_error);
        $this->assertSame(1, PrintJob::count());
    }

    /* ============================ ٤ — القديمُ الذي لم يُغلق ============================ */

    /** فاتورةٌ سُدّدت قبل هذا الإصلاح — طلبُ تجهيزها مكتوبٌ ولم تصل اللوحة */
    private function oldPaid(string $scheduled, string $paidAt): CustomerInvoice
    {
        Carbon::setTestNow($paidAt);
        $invoice = $this->write($this->owner, true, ['preparation' => $this->prepInput(['scheduled_for' => $scheduled])]);
        $this->pay($this->first, $invoice, 100);
        // كما كان قبل الإصلاح: السدادُ مسجَّلٌ والمهمّةُ لم تظهر
        $this->prep($invoice)->forceFill([
            'activated_at' => null, 'activation_source' => null, 'activation_payment_id' => null,
            'activated_by' => null, 'status' => null, 'status_changed_at' => null,
        ])->save();
        PrintJob::query()->delete();
        Carbon::setTestNow('2026-11-24 09:00:00');

        return $invoice;
    }

    /** ١١ — طلبٌ قديمٌ لم يُغلق يظهر، ومهمّةٌ قديمةٌ موعدُها لم يفت تظهر وحدها */
    public function test_old_live_work_appears(): void
    {
        $old = $this->order(['ordered_at' => now()->subMonths(2), 'scheduled_for' => now()->subMonth(), 'employee_name' => null]);
        $closed = $this->order(['ordered_at' => now()->subMonths(2), 'scheduled_for' => now()->subMonth(), 'status' => OrderStatus::COMPLETED]);
        $invoice = $this->oldPaid('2026-11-26 10:00', '2026-11-10 10:00');

        // والطلبُ القديمُ على اللوحة بلا أمر — والمهمّةُ تعيدها المطابقةُ الدوريّة
        $this->assertSame(['order:'.$old->number], $this->keys($this->second));
        Artisan::call('customer-invoices:reconcile-preparations');

        $this->assertEqualsCanonicalizing(['order:'.$old->number, $this->key($invoice)], $this->keys($this->second));
        $this->assertSame(OrderStatus::COMPLETED, $closed->fresh()->status, 'المكتملُ لا تُمسّ حالُه');
        $this->assertSame(0, PrintJob::count(), 'وقديمٌ يُعاد إلى اللوحة لا يطبع ورقةً وحده');
    }

    /**
     * ومهمّةٌ فات موعدُها قبل الإصلاح لا تعود وحدها — بأمرٍ صريح يُقرأ أثرُه أوّلًا.
     *
     * ربّما سُلّمت بيدٍ منذ أسابيع. فالأمرُ يَعدّ ولا يكتب (`--dry-run`)، ثمّ يكتب
     * بلا ورقةٍ تُطبع ولا رسالةٍ تصل زبونًا، ويُعاد بلا أثر.
     */
    public function test_overdue_history_returns_only_by_an_explicit_command_and_once(): void
    {
        $invoice = $this->oldPaid('2026-11-12 10:00', '2026-11-10 10:00');
        $money = [JournalEntry::count(), CustomerPayment::count(), CustomerInvoice::count(), Order::count(), (float) $this->rose->fresh()->quantity];

        Artisan::call('customer-invoices:reconcile-preparations');
        $this->assertNull($this->prep($invoice)->activated_at);

        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true, '--dry-run' => true]);
        $this->assertStringContainsString('1', Artisan::output());
        $this->assertNull($this->prep($invoice)->activated_at, 'العدُّ لا يكتب');

        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);
        $first = $this->prep($invoice)->activated_at;
        $this->assertNotNull($first);
        $this->assertSame([$this->key($invoice)], $this->keys($this->bench()));

        Carbon::setTestNow('2026-11-24 10:00:00');
        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);
        $this->assertEquals($first, $this->prep($invoice)->activated_at, 'ويُعاد بلا أثر');
        $this->assertSame(0, PrintJob::count());
        $this->assertSame($money, [JournalEntry::count(), CustomerPayment::count(), CustomerInvoice::count(), Order::count(), (float) $this->rose->fresh()->quantity]);
    }

    /** وما سُدّد قبل أن تُفتح الميزة لا يُعاد ولو بالأمر — جُهّز بيدٍ يومَها */
    public function test_what_was_paid_before_the_feature_opened_is_not_replayed(): void
    {
        $invoice = $this->oldPaid('2026-11-26 10:00', '2026-11-10 10:00');
        $this->shop->forceFill(['customer_invoice_preparation_enabled_at' => '2026-11-20 08:00:00'])->save();

        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);

        $this->assertNull($this->prep($invoice)->activated_at);
    }

    /* ============================ ٥ — التزامنُ والأعداد ============================ */

    /** ١٢ — نقلُ موظّفٍ وعلامتُه يراهما غيرُه، والمغلقُ يغيب عن الجميع */
    public function test_a_move_and_a_tick_by_one_are_read_by_the_others(): void
    {
        $o = $this->order();

        $this->actingAs($this->first)->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();
        $this->actingAs($this->first)->post(route('admin.preparation.check', $o->number), ['key' => 'task:packaging', 'checked' => true])->assertSessionHasNoErrors();

        $card = $this->board($this->second)['orders'][0];
        $this->assertSame(OrderStatus::PREPARING, $card['status']);
        $this->assertSame('الموظف الأول', $card['checks']['task:packaging']['by']);

        foreach ([OrderStatus::READY, OrderStatus::PICKED_UP] as $to) {
            $this->actingAs($this->second)->post(route('admin.preparation.move', $o->number), ['status' => $to])->assertSessionHasNoErrors();
        }
        foreach ([$this->owner, $this->first, $this->second] as $who) {
            $this->assertSame([], $this->keys($who));
            $this->assertSame(0, $this->board($who)['counts']['all']);
        }
    }

    /** وما فوق السقف يُقال عددُه ويُبلغ — «عرض المزيد» يحمل بقيّته */
    public function test_what_exceeds_the_limit_is_said_and_can_be_reached(): void
    {
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = [
                'business_id' => $this->shop->id, 'branch_id' => $this->khuwair->id, 'number' => 'INV-L'.$i, 'status' => OrderStatus::CONFIRMED,
                'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 1, 'total' => 1,
                'ordered_at' => now(), 'scheduled_for' => now()->addMinutes($i), 'fulfillment_type' => FlowerOrder::PICKUP,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        Order::insert($rows);

        $props = $this->board($this->second);
        $this->assertSame(['shown' => 200, 'total' => 205, 'from' => 0, 'more' => 400, 'next' => null, 'prev' => null], $props['truncated']);
        $this->assertSame(205, $props['counts']['all']);

        $more = $this->board($this->second, ['show' => 400]);
        $this->assertNull($more['truncated']);
        $this->assertCount(205, $more['orders']);

        // ورقمٌ بيدٍ لا يفتح اللوحةَ بلا سقف
        $this->assertSame(200, count($this->board($this->second, ['show' => 5])['orders']));
        $this->assertSame(PreparationController::BOARD_MAX, $this->board($this->second, ['show' => 999999])['filters']['show']);
    }

    /**
     * وما فوق الألف يُبلغ كلُّه — نافذةً بعد نافذة، لا تسقط بطاقةٌ ولا تتكرّر.
     *
     * والمواعيدُ متساويةٌ عمدًا: ترتيبٌ لا يُحسم بالمعرّف يضع البطاقةَ في
     * نافذتين أو في لا شيء. ومعها مهامُّ فواتيرَ بين الطلبات.
     */
    public function test_past_a_thousand_every_card_is_still_reached_once(): void
    {
        $max = PreparationController::BOARD_MAX;
        $rows = [];
        for ($i = 0; $i < $max + 230; $i++) {
            $rows[] = [
                'business_id' => $this->shop->id, 'branch_id' => $this->khuwair->id, 'number' => 'INV-K'.$i, 'status' => OrderStatus::CONFIRMED,
                'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 1, 'total' => 1,
                'ordered_at' => now(), 'scheduled_for' => now()->addHours(intdiv($i, 400)), 'fulfillment_type' => FlowerOrder::PICKUP,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $part) {
            Order::insert($part);
        }
        $tasks = [];
        foreach (['2026-11-24 10:00', '2026-11-24 12:00', '2026-12-30 10:00'] as $at) {
            $invoice = $this->write($this->owner, true, ['preparation' => $this->prepInput(['scheduled_for' => $at])]);
            $this->pay($this->first, $invoice, 100);
            $tasks[] = $this->key($invoice);
        }
        $total = $max + 230 + 3;
        $bench = $this->bench();

        $first = $this->board($bench, ['show' => $max]);
        $this->assertCount($max, $first['orders']);
        $this->assertSame(['shown' => $max, 'total' => $total, 'from' => 0, 'more' => null, 'next' => $max, 'prev' => null], $first['truncated']);

        $last = $this->board($bench, ['show' => $max, 'from' => $first['truncated']['next']]);
        $this->assertCount(233, $last['orders']);
        $this->assertSame(['shown' => 233, 'total' => $total, 'from' => $max, 'more' => null, 'next' => null, 'prev' => 0], $last['truncated']);

        $seen = array_merge(array_column($first['orders'], 'key'), array_column($last['orders'], 'key'));
        $this->assertCount($total, $seen);
        $this->assertCount($total, array_unique($seen), 'لا بطاقةَ مرّتين');
        foreach ($tasks as $key) {
            $this->assertContains($key, $seen);
        }
        // والبعيدةُ موعدًا في النافذة الثانية — الترتيبُ واحدٌ للجدولين
        $this->assertContains($tasks[2], array_column($last['orders'], 'key'));
        $this->assertContains($tasks[0], array_column($first['orders'], 'key'));

        // والعدّادُ واحدٌ في النافذتين، والنقلُ يبلغ ما في الثانية
        $this->assertSame($total, $last['counts']['all']);
        $far = collect($last['orders'])->firstWhere('kind', 'order');
        $this->actingAs($bench)->post(route('admin.preparation.move', $far['number']), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();

        // ونافذةٌ خلت — سُلّم ما فيها — تردّ إلى آخر ما فيه شيء لا إلى لوحةٍ فارغة
        Order::where('business_id', $this->shop->id)->whereIn('number', array_column($rows, 'number'))->orderByDesc('id')->limit(300)->update(['status' => OrderStatus::PICKED_UP]);
        $after = $this->board($bench, ['show' => $max, 'from' => $max]);
        $this->assertNotEmpty($after['orders']);
        $this->assertNull($after['filters']['from']);
    }

    /* ============================ ٦ — المصادرُ والحالات ============================ */

    /** ١٣ — طلبُ الموقع وطلبُ واتساب (فاتورتُه) على لوحة الجميع */
    public function test_website_and_whatsapp_orders_reach_everyone(): void
    {
        $this->shop->forceFill(['site_slug' => 'shared-shop', 'tier' => 'gold', 'storefront_theme' => 'ribbon'])->save();
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_field_date' => 'off', 'store_field_slot' => 'off']);

        $this->postJson('/s/shared-shop/checkout', [
            'items' => [['id' => $this->rose->id, 'qty' => 1]], 'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001',
        ])->assertOk();
        $web = Order::where('business_id', $this->shop->id)->where('channel', SalesChannel::WEBSITE)->firstOrFail();

        // طلبُ واتساب فاتورةٌ بتجهيزها — لا كاتبَ لها من الموظّفين
        $whatsapp = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-11-24'], [
            ['description' => 'باقة من واتساب', 'quantity' => 1, 'unit_price' => 30],
        ]));
        CustomerInvoicePreparations::save($whatsapp, $this->prepInput());
        $this->pay($this->first, $whatsapp, 30);

        $expected = ['order:'.$web->number, $this->key($whatsapp)];
        foreach ([$this->owner, $this->first, $this->second, $this->bench()] as $who) {
            $this->assertEqualsCanonicalizing($expected, $this->keys($who));
        }
    }

    /** وما يُجهَّز لا يولد مكتملًا، وما لا يُجهَّز لا يزحم اللوحة */
    public function test_only_work_to_prepare_stands_on_the_board(): void
    {
        $this->activatePosDevice($this->shop->id, $this->khuwair->id);

        $scheduled = $this->till($this->first);
        $this->assertSame(OrderStatus::PENDING, $scheduled->status);

        // نوعُ تنفيذٍ بلا موعد يُردّ — لا يُكتب مكتملًا في صمت
        $this->actingAs($this->first)->withCredentials()->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->rose->id, 'name' => $this->rose->name, 'qty' => 1]],
            'payment_method' => 'نقدي', 'customer' => 'خالد', 'fulfillment_type' => FlowerOrder::PICKUP,
        ])->assertStatus(422);

        // وبيعةُ المنضدة مكتملةٌ ولا تظهر
        $walkIn = $this->till($this->first, ['fulfillment_type' => null, 'scheduled_for' => null]);
        $this->assertSame(OrderStatus::COMPLETED, $walkIn->status);

        $this->order(['is_held' => true, 'status' => 'معلّق']);
        $this->order(['status' => OrderStatus::CANCELLED]);
        $this->order(['status' => OrderStatus::DELIVERED]);

        $this->assertSame(['order:'.$scheduled->number], $this->keys($this->second));
    }

    /* ============================ ٧ — الصلاحيّات ============================ */

    /** ١٤ — التجهيزُ وحدَه يكفي للتجهيز، ولا يفتح فاتورةً ولا ماليّةً ولا تكلفة */
    public function test_preparing_alone_is_enough_to_prepare_and_opens_nothing_else(): void
    {
        $invoice = $this->write($this->owner);
        $this->pay($this->first, $invoice, 100);
        $o = $this->order();
        $bench = $this->bench();

        $this->assertCount(2, $this->keys($bench));
        $this->actingAs($bench)->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();
        $this->actingAs($bench)->post(route('admin.preparation.invoiceMove', $this->prep($invoice)->id), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();

        $this->get(route('admin.customerInvoices.show', $invoice->id))->assertForbidden();
        $this->get(route('admin.orders.index'))->assertForbidden();
        $this->post(route('admin.customerPayments.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => 1, 'method' => 'نقدي'])->assertForbidden();

        $json = json_encode($this->board($bench)['orders']);
        foreach (['"cost"', '"profit"', '"margin"', '"subtotal"'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        // والإلغاءُ وتعديلُ بيانات التسليم فعلان مستقلّان لا يجيئان مع القسم
        $this->actingAs($bench)->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::CANCELLED])->assertSessionHasErrors('status');
        $this->actingAs($bench)->post(route('admin.preparation.invoiceDetails', $this->prep($invoice)->id), ['preparation' => $this->prepInput()])->assertForbidden();
        $this->assertFalse($bench->may(Permissions::PREPARATION_CANCEL));
    }

    /** ١٥ — ومن لا يملك التجهيزَ يُردّ عن اللوحة وعن كلّ بابٍ من أبوابها */
    public function test_without_the_section_every_door_is_closed(): void
    {
        $o = $this->order();
        $invoice = $this->write($this->owner);
        $this->pay($this->first, $invoice, 100);
        $prep = $this->prep($invoice);
        $seller = $this->user($this->shop, 'بائع', 'sales', ['orders', 'pos', 'customers']);

        $this->actingAs($seller);
        $this->get(route('admin.preparation.index'))->assertForbidden();
        $this->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::PREPARING])->assertForbidden();
        $this->post(route('admin.preparation.check', $o->number), ['key' => 'task:packaging', 'checked' => true])->assertForbidden();
        $this->get(route('admin.preparation.timeline', $o->number))->assertForbidden();
        $this->get(route('admin.preparation.sheet', $o->number))->assertForbidden();
        $this->post(route('admin.preparation.invoiceMove', $prep->id), ['status' => OrderStatus::PREPARING])->assertForbidden();
        $this->get(route('admin.preparation.invoiceTimeline', $prep->id))->assertForbidden();
        $this->assertSame(OrderStatus::CONFIRMED, $o->fresh()->status);
        $this->assertSame(OrderStatus::CONFIRMED, $prep->fresh()->status);
    }

    /* ============================ ٨ — كلُّ تاجر، حاليٌّ وقادم ============================ */

    /** ١٨ — متجرٌ وُلد اليوم: الميزةُ تعمل له بلا ضبطٍ خاصّ */
    public function test_a_shop_born_today_works_without_any_setup(): void
    {
        $this->shop = $this->shop('متجر جديد');
        $this->khuwair = Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع الأول']);
        $this->owner = $this->user($this->shop, 'مالك جديد', 'admin');
        $this->first = $this->user($this->shop, 'موظف جديد', 'manager');
        $this->customer = $this->customer($this->shop);

        $o = $this->order();
        $invoice = $this->write($this->owner);
        $this->pay($this->first, $invoice, 100);

        foreach ([$this->owner, $this->first, $this->bench($this->shop)] as $who) {
            $this->assertEqualsCanonicalizing(['order:'.$o->number, $this->key($invoice)], $this->keys($who));
        }
    }

    /** ولا رقمَ متجرٍ ولا موظّفٍ مكتوبٌ في منطق اللوحة */
    public function test_no_shop_or_user_is_named_in_the_boards_logic(): void
    {
        foreach (['app/Support/PreparationScope.php', 'app/Support/CustomerInvoicePreparations.php', 'app/Http/Controllers/Admin/PreparationController.php'] as $file) {
            $code = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression("/(business_id|user_id)['\"]?\s*(=>|,|===|==)\s*\d+/", $code, $file);
            $this->assertStringNotContainsString('currentBranchId(', $code, $file.' يقرأ فرعَ الجلسة');
        }
    }

    /* ============================ ٩ — لا تكرارَ ولا مال ============================ */

    /** ٢٠ — الظهورُ والتجهيزُ والتسليمُ لا يكتبان بيعًا ولا قيدًا ولا حركةَ مخزون */
    public function test_showing_and_preparing_write_no_money_and_no_stock(): void
    {
        $invoice = $this->write($this->owner);
        $before = fn () => [
            JournalEntry::where('business_id', $this->shop->id)->count(), CustomerInvoice::count(), Order::count(),
            CustomerPayment::count(), (float) $this->rose->fresh()->quantity,
            round(Ledger::balance($this->shop->id, 'sales'), 3), round(Ledger::balance($this->shop->id, 'receivable'), 3),
        ];

        $this->pay($this->first, $invoice, 100);
        $paid = $before();
        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame(0.0, round(Ledger::balance($this->shop->id, 'receivable'), 3));

        $prep = $this->prep($invoice);
        foreach ([OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::PICKED_UP] as $to) {
            $this->actingAs($this->second)->post(route('admin.preparation.invoiceMove', $prep->id), ['status' => $to])->assertSessionHasNoErrors();
        }
        Artisan::call('customer-invoices:reconcile-preparations', ['--history' => true]);

        $this->assertSame($paid, $before());
        $this->assertSame([], $this->keys($this->owner), 'وما سُلّم يغيب ولا يعود');
        $this->assertSame(1, CustomerInvoicePreparation::count());
    }
}
