<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOnboarding;
use App\Support\WhatsAppOwnApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ‏‏من ربط بتطبيقه على Meta يبقى عليه — وإن نُقل التجّارُ الجدد إلى تطبيق أبعاد (وصلةُ RIBBON).
 *
 * كان المفتاحُ وحده يقرّر طريقَ كلّ متجر: فبعد النقل تعرض صفحةُ متجرٍ على تطبيقه زرَّ تطبيق أبعاد
 * ونموذجَه اليدويّ، وكلاهما يكتب رمزَ تطبيق أبعاد فوق صفٍّ سرُّه وعنوانُ إشعاراته لتطبيق التاجر —
 * وصلةٌ نصفُها لتطبيقٍ ونصفُها لآخر.
 *
 * والحالُ المعكوسة: من فصل تطبيقه بيده ثمّ ربط عبر أبعاد يصير على تطبيق أبعاد كاملًا، لا نصفين.
 */
class AShopOnItsOwnMetaAppStaysOnItTest extends TestCase
{
    use RefreshDatabase;

    private const ABAAD = '1082481610822941';

    private const HIS_APP = '5550000000001';

    private Business $shop;

    private User $owner;

    private bool $subscribed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            // ‏‏نُقل الجددُ إلى تطبيق أبعاد
            'whatsapp.merchant_link' => 'abaad_app',
            'whatsapp.app_id' => self::ABAAD,
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        $this->shop = Business::create(['name' => 'RIBBON', 'status' => 'نشط', 'whatsapp_own_allowed' => true, 'whatsapp_enabled' => true]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        // ‏‏ورسومُه على حسابه — ثبتت، وما قبل ثبوتها يحرسه `AbaadNeverPaysForAShopsMessagesTest`
        $this->billedToTheShop((int) $this->shop->id, 'WABA-1');
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'ABAAD-APP-TOKEN-0123456789'], 200),
            '*/debug_token*' => Http::response(['data' => [
                'is_valid' => true, 'app_id' => self::ABAAD, 'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
                ],
            ]], 200),
            '*/WABA-1/phone_numbers*' => Http::response(['data' => [['id' => 'PN-1', 'display_phone_number' => '+968 7679 0720', 'platform_type' => 'CLOUD_API']]], 200),
            '*fields=is_on_biz_app*' => Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200),
            '*/WABA-1/subscribed_apps' => function ($request) {
                if ($request->method() === 'POST') {
                    $this->subscribed = true;

                    return Http::response(['success' => true], 200);
                }

                return Http::response(['data' => $this->subscribed ? [['whatsapp_business_api_data' => ['id' => self::ABAAD]]] : []], 200);
            },
            '*/WABA-1?*' => Http::response(['id' => 'WABA-1', 'owner_business_info' => ['id' => 'MB-9']], 200),
            '*' => Http::response(['id' => 'WABA-1'], 200),
        ]);
        // ‏‏وما بعد الإطلاق: نجحت تجربةُ الفوترة، فيسري «تطبيق أبعاد»
        $this->launchWhatsAppForAll();
    }

    private function ownLink(string $status = WhatsAppConnection::ACTIVE): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS, 'link_type' => WhatsAppOwnApp::TYPE,
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'display_phone_number' => '+968 7679 0720',
            'access_token' => 'HIS-SYSTEM-USER-TOKEN', 'token_app_id' => self::HIS_APP, 'app_secret' => 'HIS-APP-SECRET',
            'webhook_key' => str_repeat('k', 40), 'webhook_verify_token' => 'HIS-VERIFY', 'webhook_verified_at' => now()->subDay(),
            'billing_state' => WhatsAppOwnApp::BILLING_CONFIRMED, 'billing_owner_id' => 'MB-HIS', 'billing_owner_name' => 'RIBBON LLC',
            'billing_checked_at' => now()->subDay(), 'billing_evidence' => ['kind' => 'meta_invoice', 'reference' => 'INV-1'],
            'metadata' => ['billing_facts' => ['reach' => ['WABA-1']], 'app_webhook' => 'listening'],
            'status' => $status, 'connected_at' => now()->subMonth(),
        ]);
    }

    /** ‏‏الصفُّ كما في القاعدة حرفًا بحرف — والمشفَّرُ بنصّه المشفَّر */
    private function raw(): array
    {
        return (array) DB::table('whatsapp_connections')->where('business_id', $this->shop->id)->first();
    }

    private function oneTap()
    {
        return $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789', 'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1',
        ]);
    }

    private function pasted()
    {
        return $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), [
            'phone_number_id' => 'PN-1', 'waba_id' => 'WABA-1', 'access_token' => 'ABAAD-APP-TOKEN-0123456789',
        ]);
    }

    public static function livingStates(): array
    {
        return [
            'تعمل' => [WhatsAppConnection::ACTIVE],
            'تنتظر خطوة' => [WhatsAppConnection::PENDING],
            'تعثّرت' => [WhatsAppConnection::ERROR],
            'انتهى رمزها' => [WhatsAppConnection::EXPIRED],
            'سُحب إذنها' => [WhatsAppConnection::REVOKED],
            'تحتاج تجديدًا' => [WhatsAppConnection::REAUTH],
        ];
    }

    #[DataProvider('livingStates')]
    public function test_no_abaad_app_door_writes_over_a_living_own_app_link(string $status): void
    {
        $this->ownLink($status);
        $before = $this->raw();

        $this->oneTap()->assertSessionHasErrors('code');
        $this->assertSame($before, $this->raw(), 'زرُّ الربط مسّ وصلةَ تطبيق التاجر');

        // ‏‏والنموذجُ اليدويّ نموذجُ تطبيقه: يطلب معرّفَ تطبيقه، ولا يقبل رمزًا من تطبيق أبعاد
        $this->pasted()->assertSessionHasErrors('app_id');
        $this->assertSame($before, $this->raw(), 'النموذجُ اليدويّ مسّ وصلةَ تطبيق التاجر');

        // ‏‏ومن أيّ بابٍ آخر يمرّ بالربط نفسه — لا تُمسّ ولا يُكتب عليها فشل
        $result = WhatsAppOnboarding::connect($this->shop, null, 'AQD-short-lived-code-0123456789', 'WABA-1', 'PN-1');
        $this->assertFalse($result['ok']);
        $this->assertSame($before, $this->raw());

        Http::assertNothingSent();
    }

    public function test_its_page_keeps_its_own_way_and_offers_no_abaad_button(): void
    {
        $this->ownLink();

        $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('automation.own_app.enabled', true)
            ->where('automation.own_app.connection.app_id', self::HIS_APP));
        $this->assertTrue(WhatsAppOwnApp::onOwnApp((int) $this->shop->id));
    }

    public function test_a_new_shop_links_through_abaads_app_as_before(): void
    {
        $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('automation.own_app.enabled', false));

        $this->oneTap()->assertSessionHasNoErrors();

        $row = WhatsAppConnection::where('business_id', $this->shop->id)->sole();
        $this->assertNull($row->link_type);
        $this->assertTrue($row->isUsable());
        $this->assertNull($row->metadata['left_own_app'] ?? null);
    }

    /** ‏‏ومن فصل تطبيقه بيده ثمّ ربط عبر أبعاد — على تطبيق أبعاد كاملًا، من البابين */
    #[DataProvider('doors')]
    public function test_a_shop_that_disconnected_its_app_moves_wholly(string $door): void
    {
        $this->ownLink();
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'))->assertSessionHasNoErrors();
        $this->assertFalse(WhatsAppOwnApp::onOwnApp((int) $this->shop->id));

        $this->{$door}()->assertSessionHasNoErrors();

        $row = WhatsAppConnection::where('business_id', $this->shop->id)->sole();
        $this->assertSame('ABAAD-APP-TOKEN-0123456789', $row->access_token);
        $this->assertSame(self::ABAAD, $row->token_app_id);
        $this->assertTrue($row->isUsable(), 'رُبط عبر أبعاد ولا يُرسل — بقي فيه من تطبيقه ما يمنعه');

        $raw = $this->raw();
        foreach (['link_type', 'app_secret', 'webhook_key', 'webhook_verify_token', 'webhook_verified_at',
            'billing_state', 'billing_owner_id', 'billing_owner_name', 'billing_checked_at', 'billing_evidence'] as $column) {
            $this->assertNull($raw[$column], $column.' بقي من تطبيقه');
        }

        // ‏‏وما رُوجع يبقى أثرًا — بلا سرٍّ ولا رمز
        $this->assertSame(self::HIS_APP, $row->metadata['left_own_app']['app_id']);
        $this->assertSame('INV-1', $row->metadata['left_own_app']['billing_evidence']['reference']);
        $this->assertArrayNotHasKey('billing_facts', $row->metadata);
        $this->assertArrayNotHasKey('app_webhook', $row->metadata);
        $this->assertStringNotContainsString('HIS-', json_encode($row->metadata));
    }

    public static function doors(): array
    {
        return ['بضغطة' => ['oneTap'], 'باليد' => ['pasted']];
    }
}
