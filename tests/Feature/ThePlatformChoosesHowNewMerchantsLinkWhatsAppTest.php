<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\WhatsAppEmbeddedSignup;
use App\Support\WhatsAppMerchantLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ‏‏كيف يربط التجّارُ الجدد واتسابهم — يقرّره مديرُ المنصّة من شاشته (`WhatsAppMerchantLink`).
 *
 * طريقُ تطبيق التاجر على Meta، أو طريقُ تطبيق أبعاد بضغطة (مزوّدًا تقنيًّا). وكان متغيّرَ
 * بيئةٍ لا يسري حتى `config:cache` وإعادةِ تحميل php-fpm؛ فصار صفًّا يُقرأ في الطلب التالي،
 * ومعه رقمُ إعداد الربط المدمج. وبلا صفٍّ يبقى ما في الإعداد، فلا يتغيّر شيءٌ يوم النشر.
 */
class ThePlatformChoosesHowNewMerchantsLinkWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG = '2177821789438050';

    private Business $shop;

    private User $owner;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => '1082481610822941',
            'whatsapp.app_secret' => 'abaad-app-secret-not-real',
            'whatsapp.config_id' => null,
            'whatsapp.merchant_link' => 'own_app',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_mode' => WhatsAppMode::DEFAULT,
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'rose@link.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function row(string $key): ?Setting
    {
        return Setting::whereNull('business_id')->where('key', $key)->first();
    }

    private function flips(): int
    {
        return ActivityLog::where('subject_type', Setting::class)->count();
    }

    /** ‏‏صفحةُ واتساب عند التاجر كما يراها — طريقُ الربط ورقمُ الإعداد الذي تحمله إلى نافذة Meta */
    private function merchantPage()
    {
        return $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertOk();
    }

    /* ─────────────────────────── بلا قرار: ما في الإعداد ─────────────────────────── */

    public function test_untouched_new_merchants_link_the_way_the_server_says(): void
    {
        $this->assertSame(WhatsAppMerchantLink::OWN_APP, WhatsAppMerchantLink::mode());
        $this->assertTrue(WhatsAppOwnApp::enabled());
        $this->assertFalse(WhatsAppEmbeddedSignup::configured(), 'بلا رقم إعداد لا يُعرض الربطُ بضغطة');

        config(['whatsapp.merchant_link' => 'abaad_app', 'whatsapp.config_id' => self::CONFIG]);
        $this->assertSame(WhatsAppMerchantLink::ABAAD_APP, WhatsAppMerchantLink::mode());
        $this->assertFalse(WhatsAppOwnApp::enabled());
        $this->assertSame(self::CONFIG, WhatsAppMerchantLink::configId());

        $this->assertNull($this->row(WhatsAppMerchantLink::KEY));
        $this->assertSame(0, $this->flips());
    }

    /* ─────────────────────────── مديرُ المنصّة يبدّل ─────────────────────────── */

    public function test_the_platform_manager_sets_the_config_and_moves_new_merchants_to_abaads_app(): void
    {
        $this->actingAs($this->platform);

        // ‏‏رقمُ الإعداد أوّلًا — ثمّ الطريق
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => self::CONFIG])
            ->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');
        $this->assertSame(self::CONFIG, WhatsAppMerchantLink::configId());
        $this->assertTrue(WhatsAppEmbeddedSignup::configured());

        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app'])
            ->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $this->assertSame('abaad_app', $this->row(WhatsAppMerchantLink::KEY)->value);
        $this->assertFalse(WhatsAppOwnApp::enabled(), 'يسري من الطلب التالي بلا نشرٍ ولا .env');

        // ‏‏والتاجرُ يرى الزرّ الواحد بالرقم الذي كُتب في الشاشة — لا نموذجَ تطبيقه
        $this->merchantPage()->assertInertia(fn (Assert $page) => $page
            ->where('automation.embedded.configured', true)
            ->where('automation.embedded.config_id', self::CONFIG)
            ->where('automation.own_app.enabled', false));

        // ‏‏وبابُ الربط المدمج مفتوحٌ له الآن: يُبدَّل الكود عند Meta (لا يُردّ بـ«اربط تطبيقك»)
        Http::fake(['*' => Http::response(['error' => ['message' => 'stop here']], 400)]);
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), ['code' => 'AQD-short-lived-code-0123456789']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/access_token'));
    }

    public function test_every_change_is_written_down_with_who_and_from_what(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => self::CONFIG]);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app']);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'own_app']);

        $logs = ActivityLog::where('subject_type', Setting::class)->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame(['مدير المنصّة'], $logs->pluck('user_name')->unique()->values()->all());
        $this->assertStringContainsString('كان «—» وصار «'.self::CONFIG.'»', $logs[0]->description);
        $this->assertStringContainsString('كانت «تطبيق التاجر على Meta» وصارت «تطبيق أبعاد (بضغطة)»', $logs[1]->description);
        $this->assertStringContainsString('كانت «تطبيق أبعاد (بضغطة)» وصارت «تطبيق التاجر على Meta»', $logs[2]->description);

        // ‏‏والشاشةُ تعرض الحالَ وما تبدّل — بلا سرّ التطبيق
        $this->get(route('super-admin.settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('whatsappLink.mode', 'own_app')
            ->where('whatsappLink.config_id', self::CONFIG)
            ->where('whatsappLink.config_source', 'platform')
            ->where('whatsappLink.app_secret_ready', true)
            ->where('whatsappLink.ready', true)
            ->has('whatsappLink.history', 3));
        $this->assertStringNotContainsString('abaad-app-secret-not-real', json_encode(WhatsAppMerchantLink::state()));
    }

    public function test_moving_to_where_it_already_is_writes_nothing(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'own_app'])->assertSessionHas('toast.type', 'info');
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => ''])->assertSessionHas('toast.type', 'info');

        $this->assertNull($this->row(WhatsAppMerchantLink::KEY));
        $this->assertNull($this->row(WhatsAppMerchantLink::CONFIG_KEY));
        $this->assertSame(0, $this->flips());
    }

    /* ─────────────────────────── ما يُردّ ─────────────────────────── */

    /** ‏‏لا يُفتح طريقُ أبعاد والربطُ بضغطةٍ غيرُ جاهز — لَرأى كلُّ تاجرٍ «غير مهيّأ» مكان طريقٍ يعمل */
    public function test_abaads_app_is_not_opened_before_the_one_click_link_is_ready(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app'])->assertSessionHasErrors('mode');

        config(['whatsapp.app_secret' => null]);
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => self::CONFIG]);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app'])->assertSessionHasErrors('mode');

        $this->assertNull($this->row(WhatsAppMerchantLink::KEY));
        $this->assertTrue(WhatsAppOwnApp::enabled());
    }

    public function test_the_config_cannot_be_cleared_while_merchants_link_through_it(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => self::CONFIG]);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app']);

        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => ''])->assertSessionHasErrors('config_id');
        $this->assertSame(self::CONFIG, WhatsAppMerchantLink::configId());
        $this->assertTrue(WhatsAppEmbeddedSignup::configured());
    }

    public function test_bad_values_are_refused_and_nothing_is_written(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'shared'])->assertSessionHasErrors('mode');
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), [])->assertSessionHasErrors('mode');
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => '12ab-34'])->assertSessionHasErrors('config_id');
        $this->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => '123'])->assertSessionHasErrors('config_id');
        $this->assertSame(['ok' => false, 'changed' => false], array_slice(WhatsAppMerchantLink::setConfigId('<script>'), 0, 2));

        $this->assertNull($this->row(WhatsAppMerchantLink::KEY));
        $this->assertNull($this->row(WhatsAppMerchantLink::CONFIG_KEY));
        $this->assertSame(0, $this->flips());
    }

    /* ─────────────────────────── من يملكه ─────────────────────────── */

    public function test_only_the_platform_manager_can_change_it(): void
    {
        $manager = User::create(['business_id' => $this->shop->id, 'name' => 'المدير', 'email' => 'm@link.om', 'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'نشط']);
        $cashier = User::create(['business_id' => $this->shop->id, 'name' => 'الكاشير', 'email' => 'c@link.om', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);

        foreach ([$this->owner, $manager, $cashier] as $who) {
            $this->actingAs($who)->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app'])->assertForbidden();
            $this->actingAs($who)->post(route('super-admin.whatsapp.merchant-link.config'), ['config_id' => self::CONFIG])->assertForbidden();
        }
        auth()->logout();
        $this->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app'])->assertRedirect(route('login'));

        $this->assertNull($this->row(WhatsAppMerchantLink::KEY));
        $this->assertNull($this->row(WhatsAppMerchantLink::CONFIG_KEY));
        $this->assertSame(0, $this->flips());
    }

    public function test_the_general_settings_form_cannot_write_them(): void
    {
        $this->actingAs($this->platform);
        $this->post(route('super-admin.settings.update'), [
            WhatsAppMerchantLink::KEY => 'abaad_app', WhatsAppMerchantLink::CONFIG_KEY => self::CONFIG,
        ])->assertRedirect();

        $this->assertNull($this->row(WhatsAppMerchantLink::KEY));
        $this->assertNull($this->row(WhatsAppMerchantLink::CONFIG_KEY));
        $this->assertTrue(WhatsAppOwnApp::enabled());
    }

    /* ─────────────────────────── صفٌّ لا يُقرأ ─────────────────────────── */

    /** ‏‏يُرجَع إلى ما في الإعداد — الطريقُ القائم، لا طريقٌ جديد */
    public function test_a_row_that_cannot_be_read_falls_back_to_the_server(): void
    {
        Setting::create(['business_id' => null, 'key' => WhatsAppMerchantLink::KEY, 'value' => 'abaad_app']);
        Setting::create(['business_id' => null, 'key' => WhatsAppMerchantLink::CONFIG_KEY, 'value' => self::CONFIG]);
        config(['whatsapp.config_id' => '9990000000001']);

        DB::connection()->beforeExecuting(function (string $sql, array $bindings) {
            if (array_intersect([WhatsAppMerchantLink::KEY, WhatsAppMerchantLink::CONFIG_KEY], $bindings) !== []) {
                throw new \RuntimeException('settings unreadable');
            }
        });

        $this->assertSame(WhatsAppMerchantLink::OWN_APP, WhatsAppMerchantLink::mode());
        $this->assertSame('9990000000001', WhatsAppMerchantLink::configId());
        $this->assertFalse(WhatsAppMerchantLink::state()['readable']);
    }
}
