<?php

namespace Tests\Feature;

use App\Console\Commands\PrintingAudit;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\User;
use App\Support\PosTerminal;
use App\Support\TillHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * القصّةُ كلُّها من أوّلها — متجرٌ بفرعٍ واحد، صندوقٌ بطابعة، ثمّ جهازٌ آخر.
 *
 * هذا ما وقع في متجرٍ حقيقيّ: الكاشيرُ يبيع من Chrome والطابعةُ على صندوقه،
 * ثمّ ينتقل إلى تطبيق أبعاد (أو يمسح بيانات المتصفّح) — فيولد صندوقٌ جديدٌ بلا
 * طابعة وحدَه، والإيصالُ لا يخرج، والطابعةُ على صفٍّ لا يُحذف لأنّه باع.
 *
 * يُقاس هنا عبر الأبواب نفسِها (HTTP)، لا بنداء الصنف: لا صندوقَ يولد بلا
 * سؤال، والكاشيرُ لا يولّده بضغطة، والمديرُ يستبدل فتبقى الطابعةُ والمبيعات،
 * والكوكي القديمة تموت. وأثرُ الكود القديم في متجرٍ آخر يُقال «للمراجعة» ولا
 * يُصلَح آليًّا.
 *
 * محاكاةٌ لا جهاز: لا تُثبت أنّ طابعةً حقيقيّةً طبعت.
 */
class APrinterStaysWithItsTillWhenTheDeviceChangesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner-'.Str::random(4).'@abaad.om',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'الكاشير', 'email' => 'cashier-'.Str::random(4).'@abaad.om',
            'password' => 'password', 'role' => 'cashier', 'job_title' => 'كاشير', 'status' => 'نشط',
        ]);
    }

    /** متصفّحٌ بلا كوكي — تطبيقُ أبعاد، أو متصفّحٌ آخر، أو المتصفّحُ نفسُه بعد مسح بياناته */
    private function newBrowser(): self
    {
        $this->defaultCookies = [];

        return $this;
    }

    private function browserWith(string $cookie): self
    {
        $this->defaultCookies = [];

        return $this->withCookie(PosTerminal::COOKIE, $cookie);
    }

    private function cookieOf(TestResponse $response): string
    {
        $cookie = $response->getCookie(PosTerminal::COOKIE);
        $this->assertNotNull($cookie, 'لا كوكي صندوقٍ في الردّ');

        return $cookie->getValue();
    }

    private function sale(PosDevice $till): Order
    {
        return Order::create([
            'business_id' => $till->business_id, 'branch_id' => $till->branch_id,
            'pos_device_id' => $till->id, 'number' => 'INV-'.Str::random(5), 'status' => 'مكتمل',
            'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);
    }

    public function test_moving_from_chrome_to_the_app_keeps_the_printer_and_the_sales(): void
    {
        // ١. Chrome: أوّلُ صندوقٍ في المتجر يُربط وحدَه — لا شيءَ يُفقد
        $chrome = $this->newBrowser()->actingAs($this->cashier)->get(route('pos.index'))->assertRedirect(route('pos.index'));
        $chromeCookie = $this->cookieOf($chrome);
        $till = PosDevice::sole();

        // ٢. المالكُ يضيف طابعةَ الشبكة من الإعدادات — بالتحقّق نفسِه
        $this->newBrowser()->actingAs($this->owner)->post(route('admin.devices.peripherals.store', $till->id), [
            'name' => 'طابعة الإيصالات', 'type' => PosPeripheral::PRINTER, 'connection' => 'network',
            'address' => '192.168.1.50', 'port' => 9100, 'paper_width' => 80, 'auto_print' => true, 'active' => true,
        ])->assertSessionHasNoErrors();
        $printer = PosPeripheral::sole();
        $this->assertTrue($printer->is_receipt_printer, 'أوّلُ طابعةٍ على الصندوق ليست طابعةَ إيصاله');
        $sale = $this->sale($till);

        // ٣. تطبيقُ أبعاد: لا صندوقَ يولد وحدَه — يُسأل
        $this->newBrowser()->actingAs($this->cashier)->get(route('pos.index'))->assertRedirect(route('pos.setup'));

        // والكاشيرُ لا يولّد صندوقًا بلا طابعة بضغطة، ولا يستولي على الصندوق
        $this->newBrowser()->actingAs($this->cashier)->post(route('pos.setup.activate'), [
            'mode' => 'new', 'branch_id' => $this->branch->id, 'name' => 'التطبيق',
        ])->assertSessionHasErrors('mode');
        $this->newBrowser()->actingAs($this->cashier)->post(route('pos.setup.activate'), [
            'mode' => 'replace', 'replaces' => $till->id, 'version' => 'x', 'confirm_active' => true,
            'branch_id' => $this->branch->id, 'name' => 'التطبيق',
        ])->assertSessionHasErrors('replaces');
        $this->assertSame(1, PosDevice::count(), 'وُلد صندوقٌ بلا طابعة');

        // ٤. المالكُ يدخل من التطبيق ويختار «يحلّ محلّ» — بالإصدار الذي رآه، وبتأكيد النشط
        $seen = collect($this->newBrowser()->actingAs($this->owner)->get(route('pos.setup'))->viewData('page')['props']['devices'])
            ->firstWhere('id', $till->id);
        $body = [
            'mode' => 'replace', 'replaces' => $till->id, 'version' => $seen['version'],
            'branch_id' => $this->branch->id, 'name' => 'كاشير المحلّ',
        ];
        $this->newBrowser()->actingAs($this->owner)->post(route('pos.setup.activate'), $body)->assertSessionHasErrors('confirm_active');
        $app = $this->newBrowser()->actingAs($this->owner)->post(route('pos.setup.activate'), $body + ['confirm_active' => true])
            ->assertRedirect(route('pos.index'));
        $appCookie = $this->cookieOf($app);

        // الصندوقُ نفسُه: رقمُه وطابعتُه وبيعُه وفرعُه
        $this->assertSame([$till->id], PosDevice::pluck('id')->all());
        $this->assertSame($till->id, $printer->fresh()->pos_device_id);
        $this->assertTrue($printer->fresh()->is_receipt_printer);
        $this->assertSame($till->id, $sale->fresh()->pos_device_id);
        $this->assertSame($this->branch->id, (int) $till->fresh()->branch_id);

        // ٥. في التطبيق: الكاشيرُ يبيع، وطابعةُ الإيصال مسمّاةٌ في سياق الصفحة
        $page = $this->browserWith($appCookie)->actingAs($this->cashier)->get(route('pos.index'))->assertOk()->viewData('page');
        $named = collect($page['props']['context']['peripherals'])->firstWhere('id', $printer->id);
        $this->assertTrue($named['receipt'], 'الصندوقُ لا يعرف طابعةَ إيصاله');

        // ٦. وكوكي Chrome القديمة لا تبيع باسم الصندوق، ولا تولّد صندوقًا
        $this->browserWith($chromeCookie)->actingAs($this->cashier)->get(route('pos.index'))->assertRedirect(route('pos.setup'));
        $this->assertSame(1, PosDevice::count());

        // ٧. ولا شيءَ يُقال عنه
        $this->assertSame([], TillHealth::forBusiness($this->shop->id));
    }

    /**
     * ومتجرٌ شقّه الكودُ القديم قبل الإصلاح: صندوقٌ ملغى بطابعته ومبيعاته،
     * وصندوقٌ جديدٌ نشطٌ بلا طابعة. التدقيقُ يقوله «مشتبهًا» ولا يدقّ الجرس
     * ولا ينقل شيئًا — والمالكُ يُصلحه من الجهاز بـ«يحلّ محلّ».
     */
    public function test_a_shop_split_by_the_old_code_is_told_and_mended_by_hand(): void
    {
        $old = PosDevice::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'name' => 'كاشير Chrome',
            'token_hash' => hash('sha256', Str::random(40)), 'status' => PosDevice::REVOKED,
            'activated_at' => now()->subDays(20), 'last_seen_at' => now()->subDays(3),
        ]);
        $printer = PosPeripheral::create([
            'business_id' => $this->shop->id, 'pos_device_id' => $old->id, 'name' => 'طابعة', 'type' => PosPeripheral::PRINTER,
            'connection' => 'network', 'address' => '192.168.1.50', 'paper_width' => 80, 'active' => true, 'is_receipt_printer' => true,
        ]);
        $sale = $this->sale($old);
        $split = PosDevice::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'name' => 'جهاز الكاشير',
            'token_hash' => hash('sha256', Str::random(40)), 'status' => PosDevice::ACTIVE,
            'activated_at' => now()->subDays(2), 'last_seen_at' => now(),
        ]);

        $report = PrintingAudit::readOnly(fn () => PrintingAudit::collect($this->shop->id));
        $this->assertSame(TillHealth::SUSPECTED, $report['issues'][TillHealth::PRINTER_ON_REVOKED_TILL]['level']);
        $this->assertSame([TillHealth::CONFIRMED => 0, TillHealth::SUSPECTED => 1, 'healthy' => 0], $report['classification']['tills']);

        config(['pos.till_health_bell' => true]);
        $this->assertSame([], TillHealth::bellItems($this->shop->id), 'دقّ الجرسُ بما لم يثبت');
        $this->assertSame($old->id, $printer->fresh()->pos_device_id, 'نُقلت الطابعةُ آليًّا');

        $seen = collect($this->newBrowser()->actingAs($this->owner)->get(route('pos.setup'))->viewData('page')['props']['devices'])
            ->firstWhere('id', $old->id);
        $this->newBrowser()->actingAs($this->owner)->post(route('pos.setup.activate'), [
            'mode' => 'replace', 'replaces' => $old->id, 'version' => $seen['version'],
            'branch_id' => $this->branch->id, 'name' => 'كاشير المحلّ',
        ])->assertRedirect(route('pos.index'));

        $this->assertSame(PosDevice::ACTIVE, $old->fresh()->status);
        $this->assertSame($old->id, $printer->fresh()->pos_device_id);
        $this->assertSame($old->id, $sale->fresh()->pos_device_id);
        $this->assertNotNull(PosDevice::find($split->id), 'حُذف صندوقٌ بيد الإصلاح');
        $this->assertSame([], TillHealth::forBusiness($this->shop->id));
    }
}
