<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesMetaLinking;
use Tests\TestCase;

/**
 * كلُّ تاجرٍ يربط رقمَه هو بحسابه هو — ويُفعّله إن كان جديدًا على Cloud API.
 *
 *  • رقمٌ لم يُسجَّل في Cloud API (`platform_type = NOT_APPLICABLE`) يُحفظ
 *    «بانتظار التفعيل» ولا يُرسل، حتى يكتب صاحبُه رمزَ التحقّق بخطوتين.
 *  • ورقمٌ مسجَّلٌ أصلًا — كرقم تاجرٍ يعمل على تطبيقه الخاصّ — لا يُسجَّل
 *    ثانيةً: لا `register` ولا `deregister` على ما يعمل.
 *  • والرمزُ لا يُحفظ عندنا، ولا يُفعَّل رقمُ متجرٍ من جلسة متجرٍ آخر.
 *  • وما لم يُربط لا يخرج من رقم أبعاد بدلًا منه.
 *
 * وميتا كلُّها مُحاكاة (`FakesMetaLinking`) — لا نداءَ حقيقيّ ولا رسالة.
 */
class EveryMerchantLinksAndActivatesItsOwnNumberTest extends TestCase
{
    use FakesMetaLinking;
    use RefreshDatabase;

    private const PIN = '482915';

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    private User $neighbourOwner;

    /** كم كودًا بُدّل — لكلّ تفويضٍ رمزُه */
    private int $codes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // الطريقُ السابق — رمزٌ من تطبيق أبعاد للتاجر؛ والافتراضُ اليوم تطبيقُ التاجر (`WhatsAppOwnApp`)
        config(['whatsapp.merchant_link' => 'abaad_app']);
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => '1082481610822941',
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.coexistence_enabled' => false,
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        [$this->shop, $this->owner] = $this->merchant('محل الورد', 'rose@link.om');
        [$this->neighbour, $this->neighbourOwner] = $this->merchant('محل الجار', 'next@link.om');
    }

    /** @return array{Business, User} */
    private function merchant(string $name, string $email): array
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::DEFAULT,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return [$b, User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ])];
    }

    /**
     * ميتا كما تجيب — لكلّ حسابٍ رقمُه، جديدًا على Cloud API أو مسجَّلًا.
     *
     * تُضبط مرّةً في الاختبار: `Http::fake` يأخذ أوّلَ نمطٍ ينطبق، فضبطٌ ثانٍ
     * لا يغلب الأوّل.
     *
     * @param  array<string, array{string, string}>  $numbers  حساب ← [رقم، platform_type]
     * @param  array<string, mixed>  $register  ردُّ `register`
     */
    private function meta(array $numbers, array $register = ['success' => true], int $status = 200): void
    {
        $patterns = [
            '*/oauth/access_token*' => fn () => Http::response(['access_token' => 'BISU-TOKEN-'.(++$this->codes).'-0123456789'], 200),
        ];

        foreach ($numbers as $waba => [$number, $platform]) {
            $patterns["*/{$waba}/phone_numbers*"] = Http::response(['data' => [[
                'id' => $number, 'display_phone_number' => '+968 9100 0001', 'platform_type' => $platform,
            ]]], 200);
            $patterns["*/{$number}/register"] = Http::response($register, $status);
        }

        Http::fake($patterns + $this->abaadAppMeta(array_map(fn (array $n) => [$n[0]], $numbers)));
    }

    private function signup(User $owner, string $waba, string $number)
    {
        return $this->actingAs($owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789', 'waba_id' => $waba, 'phone_number_id' => $number,
        ]);
    }

    private function link(Business $b): ?WhatsAppConnection
    {
        return WhatsAppConnection::where('business_id', $b->id)->latest('id')->first();
    }

    private function registered(): int
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/register'))->count();
    }

    /* ═════════════ ١ · تاجرٌ جديد: يبدأ على رقمه، ولا يخرج شيءٌ قبل أن يربطه ═════════════ */

    public function test_a_new_shop_sends_nothing_from_abaads_number_before_it_links_its_own(): void
    {
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, WhatsAppFeature::effectiveMode($this->shop));
        $this->assertNull(WhatsAppConnections::resolve($this->shop), 'لا احتياطَ إلى رقم أبعاد');

        $step = collect(WhatsAppFeature::readiness($this->shop)['steps'])->firstWhere('key', 'own');
        $this->assertFalse($step['done']);
        $this->assertSame('اربط رقم متجرك أدناه — أو بدّل الإرسال إلى رقم أبعاد.', $step['fix']);
    }

    /* ═════════════ ٢ · رقمٌ جديدٌ على Cloud API: يُحفظ بانتظار التفعيل ═════════════ */

    public function test_a_number_new_to_cloud_api_waits_for_its_pin_and_sends_nothing(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'NOT_APPLICABLE']]);

        $this->signup($this->owner, 'WABA-1', 'PN-1')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast.type', 'info');

        $link = $this->link($this->shop);
        $this->assertSame(WhatsAppConnection::PENDING, $link->status);
        $this->assertSame(WhatsAppLink::NEEDS_REGISTRATION, WhatsAppLink::state($link));
        $this->assertFalse($link->isUsable(), 'لا يُرسل قبل التفعيل');
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
        $this->assertSame(0, $this->registered(), 'التسجيلُ لا يقع بلا رمزٍ كتبه صاحبُه');
    }

    public function test_the_owner_activates_it_with_the_pin_and_the_pin_is_kept_nowhere(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'NOT_APPLICABLE']]);
        $this->signup($this->owner, 'WABA-1', 'PN-1');

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.register'), ['pin' => self::PIN])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast.type', 'success');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/PN-1/register')
            && $r['pin'] === self::PIN && $r['messaging_product'] === 'whatsapp'
            && $r->hasHeader('Authorization', 'Bearer BISU-TOKEN-1-0123456789'));

        $link = $this->link($this->shop);
        $this->assertSame(WhatsAppConnection::ACTIVE, $link->status);
        $this->assertSame(WhatsAppLink::CONNECTED, WhatsAppLink::state($link));
        $this->assertSame($link->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);

        // والرمزُ لا يُحفظ في صفٍّ ولا في سجلّ النشاط
        $raw = json_encode(DB::table('whatsapp_connections')->where('id', $link->id)->first())
            .json_encode(DB::table('activity_logs')->get());
        $this->assertStringNotContainsString(self::PIN, $raw);
    }

    public function test_a_wrong_pin_is_told_plainly_and_changes_nothing(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'NOT_APPLICABLE']],
            ['error' => ['code' => 133005, 'message' => 'Two step verification PIN Mismatch']], 400);
        $this->signup($this->owner, 'WABA-1', 'PN-1');

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.register'), ['pin' => '000000'])
            ->assertSessionHasErrors(['pin' => 'الرمز لا يطابق رمز التحقّق بخطوتين المضبوط لرقمك — اكتب رمزه الحاليّ.']);

        $this->assertSame(WhatsAppLink::NEEDS_REGISTRATION, WhatsAppLink::state($this->link($this->shop)));
    }

    public function test_the_pin_is_six_digits_and_belongs_to_the_owner(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'NOT_APPLICABLE']]);
        $this->signup($this->owner, 'WABA-1', 'PN-1');

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.register'), ['pin' => '12ab'])
            ->assertSessionHasErrors('pin');

        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'till@link.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->actingAs($cashier)->post(route('admin.integrations.whatsapp.register'), ['pin' => self::PIN]);

        $this->assertSame(0, $this->registered());
        $this->assertSame(WhatsAppConnection::PENDING, $this->link($this->shop)->status);
    }

    /* ═════════════ ٣ · رقمٌ مسجَّلٌ أصلًا — كرقم RIBBON — لا يُسجَّل ثانيةً ═════════════ */

    public function test_a_number_already_on_cloud_api_is_linked_without_register(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'CLOUD_API']]);

        $this->signup($this->owner, 'WABA-1', 'PN-1')->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppConnection::ACTIVE, $this->link($this->shop)->status);
        $this->assertSame(0, $this->registered(), 'لا يُمسّ ما يعمل عند صاحبه');

        // ولا يُفعَّل ما لا ينتظر
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.register'), ['pin' => self::PIN])
            ->assertSessionHasErrors('pin');
        $this->assertSame(0, $this->registered());
    }

    /** ورقمٌ جديدٌ لا يحلّ محلَّ رقمٍ يُرسل اليوم */
    public function test_an_unregistered_new_number_never_replaces_a_working_one(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'CLOUD_API'], 'WABA-2' => ['PN-2', 'NOT_APPLICABLE']]);
        $this->signup($this->owner, 'WABA-1', 'PN-1');

        $this->signup($this->owner, 'WABA-2', 'PN-2')->assertSessionHasErrors('phone_number_id');

        $link = $this->link($this->shop);
        $this->assertSame(['PN-1', WhatsAppConnection::ACTIVE], [$link->phone_number_id, $link->status]);
    }

    /* ═════════════ ٤ · متجران، رقمان، حسابان — ولا يمسّ أحدُهما الآخر ═════════════ */

    public function test_two_shops_with_their_own_accounts_stay_apart(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'NOT_APPLICABLE'], 'WABA-2' => ['PN-2', 'CLOUD_API']]);
        $this->signup($this->owner, 'WABA-1', 'PN-1');

        // جارٌ يطرق باب التفعيل من جلسته: لا رقمَ ينتظر عنده — ورقمُ الأوّل لا يُمسّ
        $this->actingAs($this->neighbourOwner)->post(route('admin.integrations.whatsapp.register'), ['pin' => self::PIN])
            ->assertSessionHasErrors('pin');
        $this->assertSame(0, $this->registered());

        $this->signup($this->neighbourOwner, 'WABA-2', 'PN-2')->assertSessionHasNoErrors();

        $mine = $this->link($this->shop);
        $theirs = $this->link($this->neighbour);
        $this->assertSame(['WABA-1', 'PN-1', WhatsAppConnection::PENDING], [$mine->waba_id, $mine->phone_number_id, $mine->status]);
        $this->assertSame(['WABA-2', 'PN-2', WhatsAppConnection::ACTIVE], [$theirs->waba_id, $theirs->phone_number_id, $theirs->status]);
        $this->assertNotSame($mine->access_token, $theirs->access_token, 'لكلٍّ تفويضُه');

        // ورقمُ أحدهما لا يُربط للآخر
        $this->signup($this->owner, 'WABA-2', 'PN-2')->assertSessionHasErrors('phone_number_id');
        $this->assertSame('PN-1', $this->link($this->shop)->phone_number_id);
    }

    /* ═════════════ ٥ · والفصلُ محلّيّ، وإعادةُ الربط تعيده ═════════════ */

    public function test_disconnecting_touches_nothing_at_meta_and_relinking_brings_it_back(): void
    {
        $this->meta(['WABA-1' => ['PN-1', 'CLOUD_API']]);
        $this->signup($this->owner, 'WABA-1', 'PN-1');

        $before = count(Http::recorded());
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'))->assertSessionHasNoErrors();
        $this->assertSame($before, count(Http::recorded()), 'لا نداءَ إلى ميتا — لا deregister ولا حذف');

        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shop->fresh()->whatsapp_mode, 'لا يُرجَع إلى رقم أبعاد');
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));

        $this->signup($this->owner, 'WABA-1', 'PN-1')->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsAppConnection::where('business_id', $this->shop->id)->count(), 'الصفُّ نفسُه يعود');
        $this->assertSame(WhatsAppConnection::ACTIVE, $this->link($this->shop)->status);
    }

    /* ═════════════ ٦ · ورمزٌ من تطبيقٍ آخر يُردّ ويقول الطريق ═════════════ */

    public function test_a_token_from_another_meta_app_is_refused_with_the_supported_way(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'OTHER-APP-TOKEN-0123456789'], 200)]
            + $this->abaadAppMeta(appId: '980169825105048'));

        $this->signup($this->owner, 'WABA-1', 'PN-1')->assertSessionHasErrors('code');

        $this->assertNull($this->link($this->shop));
        $this->assertStringContainsString('ولا يُطلب منك ترك تطبيقك', session('errors')->first('code'));
        $this->assertSame(0, Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST')->count(), 'لا اشتراكَ ولا تسجيل');
        $this->assertFalse(WhatsAppOnboarding::awaitsRegistration($this->link($this->shop)));
    }
}
