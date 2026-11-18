<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ‏بيعةُ الطابور يُسأل عنها سجلُّ المتجر بمعرّفها قبل أن تُعاد أو يُحكم بردّها.
 *
 * ═══ العطب ═══
 *
 * خادمٌ يكتب البيعةَ ثمّ يسقط قبل أن يردّ (500 بعد الحفظ)، أو ينقطع الطريقُ
 * بعد الكتابة — فيرى الصندوقُ خطأً عن بيعةٍ مكتوبة. لا يُعرف من الردّ وحده
 * أكُتبت أم لا. فيسأل الصندوقُ `pos.outbox.status` بمعرّفها (`client_uuid`
 * فريدٌ في المتجر): إن كُتبت حُذفت من الطابور بفاتورتها، وإلّا أُعيدت —
 * وإعادتُها لا تكتبها ثانيةً، ولا تخصم المخزونَ ولا النقاطَ مرّتين.
 *
 * والصندوقُ (`lib/outbox.ts`، `usePosCart.flushOutbox`) يقابله في
 * `tests/js/a-queued-sale-waits-alone-and-never-twice.test.tsx`.
 */
class AQueuedSaleIsLookedUpByItsKeyBeforeItIsSentAgainTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورد الطابور', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@queue.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);
        // ‏كسبُ نقاط: لترى البيعةُ المعادةُ أنّها لا تكسب مرّتين
        foreach (['loyalty_enabled' => '1', 'loyalty_earn_rate' => '1', 'vat_enabled' => '0'] as $key => $value) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $key, 'value' => $value]);
        }
        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'مريم', 'phone' => '91234567', 'points' => 0, 'language' => 'ar',
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }

    private function sell(string $uuid, int $qty = 2)
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => $qty]],
            'payment_method' => 'نقدي',
            'customer_id' => $this->customer->id,
            'client_uuid' => $uuid,
            'replay' => true,
        ]);
    }

    private function lookup(array $uuids)
    {
        return $this->postJson(route('pos.outbox.status'), ['uuids' => $uuids]);
    }

    public function test_a_written_sale_is_told_with_its_invoice_and_amount_and_an_unwritten_one_is_not(): void
    {
        $invoice = $this->sell('q-written')->assertOk()->json('invoice');

        $res = $this->lookup(['q-written', 'q-never-arrived'])->assertOk();

        $this->assertSame(['q-written'], array_keys($res->json('saved')));
        $this->assertSame($invoice, $res->json('saved.q-written.invoice'));
        $this->assertEqualsWithDelta(20.0, (float) $res->json('saved.q-written.total'), 0.0001);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    /**
     * ‏خمسمئةٌ بعد الحفظ: البيعةُ كُتبت والردُّ خطأ. فيقول السجلُّ إنّها كُتبت،
     * وإعادتُها بمعرّفها تردّ الفاتورةَ الأولى — ولا تكتب ثانيةً ولا تخصم مرّتين.
     */
    public function test_a_sale_written_before_the_server_failed_is_found_and_never_written_again(): void
    {
        Event::listen('eloquent.creating: '.ActivityLog::class, function () {
            throw new \RuntimeException('سقط الخادمُ بعد أن كتب البيعة');
        });

        $this->sell('q-after-save')->assertStatus(500);
        Event::forget('eloquent.creating: '.ActivityLog::class);

        $order = Order::where('business_id', $this->shop->id)->where('client_uuid', 'q-after-save')->sole();
        $this->assertSame(98, (int) $this->product->fresh()->quantity, 'المخزونُ خُصم مرّةً مع البيعة المكتوبة');
        $earned = (int) $this->customer->fresh()->points;
        $this->assertGreaterThan(0, $earned, 'والنقاطُ كُسبت مرّةً');

        $this->assertSame($order->number, $this->lookup(['q-after-save'])->assertOk()->json('saved.q-after-save.invoice'));

        // ‏وإن أُعيدت على كلّ حال (صندوقٌ لم يصله جوابُ السجلّ) — الفاتورةُ الأولى نفسُها
        $again = $this->sell('q-after-save')->assertOk();
        $this->assertSame($order->number, $again->json('invoice'));
        $this->assertTrue($again->json('duplicate'));

        $this->assertSame(1, Order::where('business_id', $this->shop->id)->where('client_uuid', 'q-after-save')->count());
        $this->assertSame(98, (int) $this->product->fresh()->quantity, 'ولا يُخصم المخزونُ مرّتين');
        $this->assertSame($earned, (int) $this->customer->fresh()->points, 'ولا تُكسب النقاطُ مرّتين');
        $this->assertSame(1, PointTransaction::where('customer_id', $this->customer->id)->where('type', 'earn')->count());
    }

    /** ‏وبيعةٌ ردّها الخادمُ (نفد المخزون) لم تُكتب — فلا يقول السجلُّ إنّها كُتبت */
    public function test_a_refused_sale_is_not_in_the_record(): void
    {
        $this->product->forceFill(['quantity' => 1])->save();

        $this->sell('q-refused', 5)->assertStatus(422);

        $this->assertSame([], (array) $this->lookup(['q-refused'])->assertOk()->json('saved'));
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    /** ‏ومعرّفُ متجرٍ آخر لا يُرى من هنا — ولو أُرسل بعينه */
    public function test_another_shops_sale_is_never_seen(): void
    {
        $other = Business::create(['name' => 'متجرٌ آخر', 'status' => 'نشط']);
        Order::create([
            'business_id' => $other->id, 'number' => 'INV-THEIRS', 'client_uuid' => 'q-theirs',
            'customer_name' => 'عميل نقدي', 'status' => 'مكتمل', 'payment_method' => 'نقدي', 'subtotal' => 5, 'total' => 5,
        ]);

        $this->assertSame([], (array) $this->lookup(['q-theirs'])->assertOk()->json('saved'));
    }

    public function test_only_a_signed_in_user_who_can_sell_asks(): void
    {
        auth()->logout();
        $this->postJson(route('pos.outbox.status'), ['uuids' => ['q-1']])->assertUnauthorized();

        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@queue.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['orders'],
        ]);
        $this->actingAs($cashier)->postJson(route('pos.outbox.status'), ['uuids' => ['q-1']])->assertForbidden();
    }

    public function test_the_question_is_bounded(): void
    {
        $this->lookup([])->assertStatus(422);
        $this->lookup(array_map(fn ($i) => "q-$i", range(1, 101)))->assertStatus(422);
        $this->lookup([['not' => 'a key']])->assertStatus(422);
        $this->lookup([str_repeat('x', 65)])->assertStatus(422);
    }
}
