<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerFlags;
use App\Support\OrderNotice;
use App\Support\PosTerminal;
use App\Support\WhatsAppEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * العميلُ يحمل تنبيهَه وملاحظتَه وعيدَ ميلاده — للموظّف، لا للورقة ولا للرسالة.
 *
 * ═══ ما يُحرس ═══
 *
 * مقابضُ «الإعدادات ← العملاء» تُحفظ وتُطبَّق؛ والإطفاءُ يُخفي ولا يمحو.
 * والتحذيرُ يُقال للكاشير، والحظرُ يردّه الخادمُ ولو عُدّل الطلبُ بيد،
 * والتجاوزُ لمن يملكه بسببٍ يُقيَّد. وعيدُ الميلاد يُحسب على التقويم —
 * ديسمبر إلى يناير، و٢٩ فبراير بلا استثناء. ولا شيءَ من ذلك يخرج في إيصالٍ
 * أو رسالةِ واتساب أو إلى متجرٍ آخر.
 */
class ACustomerCarriesHisOwnWarningTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => $value]);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '9'.random_int(1000000, 9999999), 'language' => 'ar',
        ], $over));
    }

    private function sell(Customer $customer, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->cashier)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1]],
                'customer' => $customer->name, 'customer_id' => $customer->id,
                'payment_method' => 'نقدي', 'client_uuid' => uniqid('b', true),
            ], $extra));
    }

    private function till(?User $as = null)
    {
        $raw = str_repeat('k', 64);
        $device = PosDevice::firstOrCreate(
            ['business_id' => $this->shop->id, 'name' => 'صندوق'],
            ['branch_id' => $this->branch->id, 'token_hash' => hash('sha256', $raw), 'status' => PosDevice::ACTIVE, 'activated_at' => now()],
        );

        return $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw)->actingAs($as ?? $this->cashier);
    }

    /** صفُّ الزبون كما يصل الصندوق */
    private function tillRow(Customer $customer, ?User $as = null): array
    {
        $rows = $this->till($as)->get(route('pos.index'))->viewData('page')['props']['customers'];

        return collect($rows)->firstWhere('id', $customer->id);
    }

    /* ═════════════ الإعدادات ═════════════ */

    public function test_customer_settings_are_saved_from_the_settings_screen(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'customer_alerts_enabled' => '0',
            'show_customer_notes_in_pos' => '0',
            'customer_sale_blocking_enabled' => '1',
            'customer_block_manager_override' => '0',
            'customer_birthdays_enabled' => '1',
            'customer_birthday_pos_reminder' => '1',
            'customer_birthday_reminder_days' => '3',
        ])->assertSessionHasNoErrors();

        $saved = Setting::where('business_id', $this->shop->id)->pluck('value', 'key');
        $this->assertSame('0', $saved['customer_alerts_enabled']);
        $this->assertSame('0', $saved['show_customer_notes_in_pos']);
        $this->assertSame('0', $saved['customer_block_manager_override']);
        $this->assertSame('3', $saved['customer_birthday_reminder_days']);

        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['customer_birthday_reminder_days' => '31'])
            ->assertSessionHasErrors('customer_birthday_reminder_days');
    }

    public function test_the_cashier_cannot_change_customer_settings(): void
    {
        $this->actingAs($this->cashier)->post(route('admin.settings.update'), ['customer_alerts_enabled' => '0'])
            ->assertForbidden();
        $this->assertNull(Setting::where('business_id', $this->shop->id)->where('key', 'customer_alerts_enabled')->first());
    }

    /* ═════════════ التحذيرُ والملاحظةُ في الصندوق ═════════════ */

    public function test_a_warning_reaches_the_till_with_its_reason_and_the_raw_columns_do_not(): void
    {
        $c = $this->customer(['alert_type' => 'warning', 'alert_reason' => 'تأكد من الدفع قبل التجهيز', 'notes' => 'يفضل واتساب بعد ٤']);

        $row = $this->tillRow($c);
        $this->assertSame(['type' => 'warning', 'reason' => 'تأكد من الدفع قبل التجهيز'], $row['context']['alert']);
        $this->assertSame('يفضل واتساب بعد ٤', $row['context']['note']);
        foreach (['notes', 'alert_type', 'alert_reason', 'birth_day', 'birth_month', 'birth_year'] as $raw) {
            $this->assertArrayNotHasKey($raw, $row, "العمودُ الخام {$raw} وصل الصندوق");
        }

        // والبيعُ يمضي — التحذيرُ يُقال ولا يمنع
        $this->sell($c)->assertOk();
    }

    public function test_the_note_reaches_the_till_only_when_the_setting_allows(): void
    {
        $c = $this->customer(['notes' => 'يفضل واتساب بعد ٤']);
        $this->set(CustomerFlags::NOTES_IN_POS, '0');

        $this->assertNull($this->tillRow($c)['context']['note']);
        $this->assertSame('يفضل واتساب بعد ٤', $c->fresh()->notes, 'الإطفاءُ محا الملاحظة');
    }

    public function test_switching_alerts_off_hides_them_and_keeps_the_data(): void
    {
        $c = $this->customer(['alert_type' => 'block', 'alert_reason' => 'لم يستلم طلبات']);
        $this->set(CustomerFlags::ALERTS, '0');

        $this->assertNull($this->tillRow($c)['context']['alert']);
        $this->sell($c)->assertOk();

        $this->assertSame('block', $c->fresh()->alert_type);
        $this->assertSame('لم يستلم طلبات', $c->fresh()->alert_reason);

        // ويعود بعودة المقبض
        $this->set(CustomerFlags::ALERTS, '1');
        $this->assertSame('block', $this->tillRow($c)['context']['alert']['type']);
    }

    /* ═════════════ الحظرُ يردّه الخادم ═════════════ */

    public function test_a_blocked_customer_cannot_check_out_even_when_the_request_is_hand_made(): void
    {
        $c = $this->customer(['alert_type' => 'block', 'alert_reason' => 'لم يستلم طلبات']);

        // الشاشةُ ما كانت لتُرسل — والطلبُ المكتوب باليد يُردّ بالجواب نفسه
        $this->sell($c)->assertStatus(422)->assertJsonValidationErrors('customer_block')
            ->assertJsonMissing(['لم يستلم طلبات']);
        $this->assertSame(0, Order::count(), 'بيعةٌ مرّت لزبونٍ موقوف');

        // والكاشيرُ لا يتجاوز ولو كتب سببًا
        $this->sell($c, null, ['block_override_reason' => 'المدير وافق'])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_blocking_switched_off_lets_the_sale_through_as_a_warning(): void
    {
        $c = $this->customer(['alert_type' => 'block', 'alert_reason' => 'لم يستلم طلبات']);
        $this->set(CustomerFlags::BLOCKING, '0');

        $this->assertSame('warning', $this->tillRow($c)['context']['alert']['type']);
        $this->sell($c)->assertOk();
    }

    public function test_an_authorised_override_with_a_reason_sells_and_is_logged(): void
    {
        $c = $this->customer(['alert_type' => 'block', 'alert_reason' => 'لم يستلم طلبات']);

        // بلا سببٍ يُردّ حتى المالك
        $this->sell($c, $this->owner)->assertStatus(422)->assertJsonValidationErrors('customer_block');

        $this->sell($c, $this->owner, ['block_override_reason' => 'دفع مقدّمًا اليوم'])->assertOk();
        $this->assertSame(1, Order::count());

        $log = ActivityLog::where('business_id', $this->shop->id)->where('description', 'like', '%تجاوز حظرَ البيع%')->first();
        $this->assertNotNull($log, 'التجاوزُ لم يُقيَّد');
        $this->assertStringContainsString('دفع مقدّمًا اليوم', $log->description);
        $this->assertSame($this->owner->id, (int) $log->user_id);
        $this->assertSame($c->id, (int) $log->subject_id);
    }

    public function test_the_override_switch_off_stops_even_the_owner(): void
    {
        $c = $this->customer(['alert_type' => 'block']);
        $this->set(CustomerFlags::MANAGER_OVERRIDE, '0');

        $this->sell($c, $this->owner, ['block_override_reason' => 'سبب'])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_the_till_is_told_who_may_override(): void
    {
        $props = fn (User $u) => $this->till($u)->get(route('pos.index'))->viewData('page')['props']['settings'];
        $this->assertTrue($props($this->owner)['canOverrideBlock']);
        $this->assertFalse($props($this->cashier)['canOverrideBlock']);

        $this->set(CustomerFlags::MANAGER_OVERRIDE, '0');
        $this->assertFalse($props($this->owner)['canOverrideBlock']);
    }

    /* ═════════════ الملفُّ والسجلّ ═════════════ */

    public function test_the_profile_saves_birthday_and_alert_and_logs_the_block(): void
    {
        $c = $this->customer();

        $this->actingAs($this->owner)->post(route('admin.customers.internal', $c->id), [
            'birth_day' => 29, 'birth_month' => 2, 'birth_year' => '',
            'alert_enabled' => 1, 'alert_type' => 'block', 'alert_reason' => 'لم يستلم',
        ])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame([29, 2, null], [(int) $c->birth_day, (int) $c->birth_month, $c->birth_year]);
        $this->assertSame('block', $c->alert_type);
        $this->assertNotNull(ActivityLog::where('description', 'like', '%حظر البيع على العميل%')->first());

        // إطفاءُ التنبيه يُقيَّد رفعًا ويُبقي السببَ مكتوبًا
        $this->actingAs($this->owner)->post(route('admin.customers.internal', $c->id), [
            'birth_day' => 29, 'birth_month' => 2, 'alert_enabled' => 0, 'alert_reason' => 'لم يستلم',
        ])->assertSessionHasNoErrors();
        $this->assertNull($c->fresh()->alert_type);
        $this->assertSame('لم يستلم', $c->fresh()->alert_reason);
        $this->assertNotNull(ActivityLog::where('description', 'like', '%رفع حظر البيع عن العميل%')->first());

        // ولا تاريخَ مخترَع
        $this->actingAs($this->owner)->post(route('admin.customers.internal', $c->id), ['birth_day' => 31, 'birth_month' => 4])
            ->assertSessionHasErrors('birth_day');
        $this->actingAs($this->owner)->post(route('admin.customers.internal', $c->id), ['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2023])
            ->assertSessionHasErrors('birth_day');
        $this->actingAs($this->owner)->post(route('admin.customers.internal', $c->id), ['birth_day' => 5])
            ->assertSessionHasErrors('birth_month');
        $this->actingAs($this->owner)->post(route('admin.customers.internal', $c->id), ['alert_enabled' => 1, 'alert_type' => ''])
            ->assertSessionHasErrors('alert_type');
    }

    /* ═════════════ عيدُ الميلاد على التقويم ═════════════ */

    public function test_the_birthday_reminder_counts_days_across_the_year_end_and_respects_the_window(): void
    {
        $c = $this->customer(['birth_day' => 2, 'birth_month' => 1]);

        Carbon::setTestNow('2026-12-28');
        $this->assertSame(5, CustomerFlags::daysToBirthday($c));
        $this->assertSame(5, $this->tillRow($c)['context']['birthday_in'], 'ديسمبر → يناير لم يُعدّ');

        Carbon::setTestNow('2026-12-20');
        $this->assertSame(13, CustomerFlags::daysToBirthday($c));
        $this->assertNull($this->tillRow($c)['context']['birthday_in'], 'خارجَ السبعة وظهر');

        $this->set(CustomerFlags::BIRTHDAY_DAYS, '0');
        Carbon::setTestNow('2027-01-02');
        $this->assertSame(0, $this->tillRow($c)['context']['birthday_in']);
        Carbon::setTestNow('2027-01-01');
        $this->assertNull($this->tillRow($c)['context']['birthday_in']);

        // والمُطفأ لا يُقال — والبيانُ باقٍ
        $this->set(CustomerFlags::BIRTHDAY_DAYS, '7');
        $this->set(CustomerFlags::BIRTHDAYS, '0');
        $this->assertNull($this->tillRow($c)['context']['birthday_in']);
        $this->assertSame(2, (int) $c->fresh()->birth_day);

        Carbon::setTestNow();
    }

    public function test_a_leap_day_birthday_is_kept_on_the_last_day_of_february(): void
    {
        $c = $this->customer(['birth_day' => 29, 'birth_month' => 2]);

        Carbon::setTestNow('2027-02-25'); // لا ٢٩ فبراير في ٢٠٢٧
        $this->assertSame(3, CustomerFlags::daysToBirthday($c));
        Carbon::setTestNow('2028-02-25'); // كبيسة
        $this->assertSame(4, CustomerFlags::daysToBirthday($c));
        Carbon::setTestNow('2027-03-01'); // فات — والقادمُ ٢٩ فبراير ٢٠٢٨ نفسُه
        $this->assertSame(365, CustomerFlags::daysToBirthday($c));

        Carbon::setTestNow();
    }

    /* ═════════════ لا ورقةَ ولا رسالة ═════════════ */

    public function test_internal_note_and_alert_reason_never_reach_paper_or_whatsapp(): void
    {
        $c = $this->customer(['alert_type' => 'warning', 'alert_reason' => 'سببٌ سرّيٌّ جدًّا', 'notes' => 'ملاحظةٌ داخليّةٌ جدًّا']);
        $this->sell($c)->assertOk();
        $order = Order::latest('id')->firstOrFail();

        $receipt = $this->till()->getJson(route('pos.receipt.paper', $order->number))->assertOk()->json('html');
        $this->assertStringNotContainsString('سببٌ سرّيٌّ', $receipt);
        $this->assertStringNotContainsString('ملاحظةٌ داخليّةٌ', $receipt);

        $invoice = $this->actingAs($this->owner)->get(route('admin.orders.receipt', $order->number))->getContent();
        $this->assertStringNotContainsString('سببٌ سرّيٌّ', $invoice);
        $this->assertStringNotContainsString('ملاحظةٌ داخليّةٌ', $invoice);

        $text = OrderNotice::text($order, WhatsAppEvent::ORDER_READY);
        $this->assertStringNotContainsString('سببٌ سرّيٌّ', $text);
        $this->assertStringNotContainsString('ملاحظةٌ داخليّةٌ', $text);

        // والموظّفُ يراه على شاشة الطلب حيث يراسل منها
        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p->where('order.customer_alert.reason', 'سببٌ سرّيٌّ جدًّا'));
    }

    /* ═════════════ متجرٌ لا يرى متجرًا ═════════════ */

    public function test_another_shop_cannot_read_or_change_the_internal_data(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'جارهم', 'phone' => '99001100', 'language' => 'ar',
            'alert_type' => 'block', 'alert_reason' => 'سرّهم', 'birth_day' => 1, 'birth_month' => 1]);

        $this->actingAs($this->owner)->post(route('admin.customers.internal', $theirs->id), ['alert_enabled' => 0])->assertNotFound();
        $this->assertSame('block', $theirs->fresh()->alert_type);

        $this->actingAs($this->owner)->get(route('admin.customers.show', $theirs->id))->assertNotFound();

        $rows = $this->till($this->owner)->get(route('pos.index'))->viewData('page')['props']['customers'];
        $this->assertNull(collect($rows)->firstWhere('id', $theirs->id), 'زبونُ متجرٍ آخر وصل الصندوق');
        $this->assertStringNotContainsString('سرّهم', json_encode($rows, JSON_UNESCAPED_UNICODE));

        // وبيعةٌ باسم زبونهم تُكتب نقديّةً بلا ربطٍ — لا تُردّ بحظرهم ولا تكشفه
        $this->sell($theirs, $this->owner)->assertOk();
        $this->assertNull(Order::latest('id')->first()->customer_id);
    }
}
