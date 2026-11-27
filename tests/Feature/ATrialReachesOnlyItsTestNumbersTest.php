<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppBillingTrial;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\DemoGuard;
use App\Support\DemoStore;
use App\Support\MetaWhatsAppClient;
use App\Support\OrderStatus;
use App\Support\WhatsAppBillingLock;
use App\Support\WhatsAppBillingTrials;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakesMetaBilling;
use Tests\TestCase;

/**
 * ‏‏تجربةُ الفوترة لا تصل إلّا أرقامَ اختبارها — والمتجرُ التجريبيّ لا يصل عملاءه العشوائيّين أبدًا.
 *
 * المتجرُ الاختباريّ متجرٌ تجريبيّ: عملاؤه أرقامُ جوّالٍ عُمانيّةٌ عشوائيّة زرعها `DemoStore`، وقد تكون لأناسٍ
 * حقيقيّين، وطلباتُه المفتوحة تتحرّك في العروض. فلا تخرج منه رسالةٌ إلّا:
 *
 *   · من رقمه، أثناء تجربةٍ مفتوحة على ذلك الرقم، وإلى رقمٍ كتبه مديرُ المنصّة رقمَ اختبار.
 *   · لا من رقم أبعاد المشترك أبدًا.
 *
 * وما يُحاسَب به (جهةُ الرسوم وتجربتُها ورسائلُ خرجت إلى Meta) لا يمحوه زرُّ «إعادة البناء» ولا «الحذف».
 */
class ATrialReachesOnlyItsTestNumbersTest extends TestCase
{
    use FakesMetaBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMeta();
    }

    /* ═════════════ أدوات ═════════════ */

    private function openTrial(array $over = [])
    {
        return $this->actingAs($this->super)->post(route('super-admin.whatsapp.billing-trial.open'), $over + [
            'business_id' => $this->shop->id, 'messages' => 5, 'hours' => 24, 'charges_accepted' => true, 'phones' => '91234567',
        ]);
    }

    /** ‏‏متجرُ تجربةٍ مربوطٌ برقمه، وجاهزٌ لأن تُفتح تجربتُه */
    private function linked(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) $this->shop->id])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-1001', 'waba_id' => '1001', 'phone_number_id' => 'PN-1',
        ])->assertSessionHasNoErrors();
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        WhatsAppTemplates::seedBusinessDefaults($this->shop->id);
        DB::table('whatsapp_template_mappings')->where('business_id', $this->shop->id)->update(['meta_status' => 'APPROVED', 'enabled' => true]);
    }

    private function trial(array $over = []): WhatsAppBillingTrial
    {
        $this->linked();
        $this->openTrial($over)->assertSessionHasNoErrors();
        $this->calls = [];

        return WhatsAppBillingTrial::latest('id')->firstOrFail();
    }

    private function demo(bool $is = true): void
    {
        DB::table('businesses')->where('id', $this->shop->id)->update(['is_demo' => $is]);
        $this->shop->refresh();
    }

    private function c(): WhatsAppConnection
    {
        return WhatsAppConnections::forBusiness((int) $this->shop->id);
    }

    /** ‏‏زبونٌ آخر للمتجر — رقمُه ليس رقمَ اختبار، كعملاء المتجر التجريبيّ */
    private function stranger(): Customer
    {
        return Customer::create(['business_id' => $this->shop->id, 'name' => 'زبون لا نعرفه', 'phone' => '95555555']);
    }

    /* ═════════════ تُفتح بأرقامها ═════════════ */

    /** @return array<string, array{0: mixed}> */
    public static function badNumbers(): array
    {
        return [
            'بلا أرقام' => [null],
            'فارغة' => ['   '],
            'ليست رقمًا' => ['أحمد'],
            'أقصر من رقم' => ['1234'],
            'أكثر من خمسة' => ["91111111\n92222222\n93333333\n94444444\n95555555\n96666666"],
        ];
    }

    #[DataProvider('badNumbers')]
    public function test_a_trial_without_valid_test_numbers_does_not_open(mixed $phones): void
    {
        $this->linked();
        $this->openTrial(['phones' => $phones])->assertSessionHasErrors('phones');
        $this->assertSame(0, WhatsAppBillingTrial::count());

        // ‏‏ولا يفتحها نداءٌ يتخطّى الشاشة
        $r = WhatsAppBillingTrials::open($this->shop, $this->super, 5, 24, []);
        $this->assertSame(['ok' => false, 'field' => 'phones'], ['ok' => $r['ok'], 'field' => $r['field']]);
        $this->assertSame(0, WhatsAppBillingTrial::count());
    }

    public function test_the_numbers_are_kept_as_whatsapp_writes_them_and_shown_to_the_platform_manager(): void
    {
        $trial = $this->trial(['phones' => "+968 9123 4567\n٩٥٥٥٥٥٥٥، 0096891234567"]);
        $this->assertSame(['96891234567', '96895555555'], $trial->allowed_phones);
        $this->assertSame(['96891234567', '96895555555'], WhatsAppBillingTrials::state()['trial']['phones']);
        $this->assertSame(WhatsAppBillingTrials::MAX_PHONES, WhatsAppBillingTrials::state()['defaults']['max_phones']);
    }

    /* ═════════════ ولا تصل غيرَها ═════════════ */

    public function test_the_trial_reaches_its_test_number_and_no_other_customer(): void
    {
        $trial = $this->trial();

        // ‏‏طلبُ زبونٍ ليس رقمَ اختبار: يُرفض قبل Meta، ويُكتب سببُه، ولا يأكل من حدّ التجربة
        $this->order(null, $this->stranger())->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());
        $refused = WhatsAppMessage::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $this->assertSame([WhatsAppStatus::FAILED, WhatsAppBillingTrials::NOT_A_TEST_NUMBER], [$refused->status, $refused->error_code]);
        $this->assertSame(WhatsAppBillingLock::says(WhatsAppBillingTrials::NOT_A_TEST_NUMBER)['text'], $refused->error_message);
        $this->assertSame(0, $trial->fresh()->messages_used);

        // ‏‏ورقمُ الاختبار تصله، وتُعدّ
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-1'], $this->outgoing());
        $this->assertSame(1, $trial->fresh()->messages_used);

        // ‏‏وعند آخر باب، لكلّ نوعٍ من الرسائل، أيًّا كتب الرقم
        $c = $this->c();
        $this->assertSame(WhatsAppBillingTrials::NOT_A_TEST_NUMBER, MetaWhatsAppClient::sendText($c, '96895555555', 'س')['code']);
        $this->assertSame(WhatsAppBillingTrials::NOT_A_TEST_NUMBER, MetaWhatsAppClient::sendTemplate($c, '96895555555', 't', 'ar')['code']);
        $this->assertSame(WhatsAppBillingTrials::NOT_A_TEST_NUMBER, MetaWhatsAppClient::sendInteractive($c, '96895555555', ['type' => 'button'])['code']);
        $this->assertSame(WhatsAppBillingTrials::NOT_A_TEST_NUMBER, MetaWhatsAppClient::sendMedia($c, '96895555555', 'image', 'MEDIA-1')['code']);
        $this->assertSame(1, $trial->fresh()->messages_used, 'المرفوضُ لا يُعدّ');
        $this->assertTrue(MetaWhatsAppClient::sendText($c, '+968 9123 4567', 'س')['ok']);
        $this->assertSame(['PN-1', 'PN-1'], $this->outgoing());
        $this->assertSame(2, $trial->fresh()->messages_used);

        // ‏‏والرفضُ يخصّ المستلم: الرقمُ لا يقف به
        $this->assertNotContains(WhatsAppBillingTrials::NOT_A_TEST_NUMBER, WhatsAppBillingLock::STOPS);

        // ‏‏وتجربةٌ بلا أرقام — صفٌّ قديم أو كُتب بغير الشاشة — لا تصل أحدًا
        foreach ([[], null] as $none) {
            $trial->fresh()->update(['allowed_phones' => $none]);
            $this->assertSame(WhatsAppBillingTrials::NOT_A_TEST_NUMBER, MetaWhatsAppClient::sendText($this->c(), '96891234567', 'س')['code']);
        }
        $this->assertSame(2, $trial->fresh()->messages_used);
    }

    /* ═════════════ والمتجرُ التجريبيّ ═════════════ */

    public function test_a_demo_shop_sends_only_to_the_test_numbers_of_an_open_trial_even_after_it_is_proven(): void
    {
        $this->demo();
        $trial = $this->trial();
        $this->order(null, $this->stranger())->update(['status' => OrderStatus::READY]);
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-1'], $this->outgoing(), 'أثناء التجربة: رقمُ الاختبار وحده');

        // ‏‏أُغلقت التجربة — ثمّ ثبتت فوترتُه: لا يصل أحدًا، ولا رقمَ الاختبار
        $this->actingAs($this->super)->delete(route('super-admin.whatsapp.billing-trial.close', $trial->id))->assertSessionHasNoErrors();
        $this->proveWhatsAppBilling((int) $this->shop->id, '1001');
        foreach (['96891234567', '96895555555'] as $to) {
            $this->assertSame(WhatsAppBillingTrials::DEMO_SENDS_NOTHING, MetaWhatsAppClient::sendText($this->c(), $to, 'س')['code'], $to);
        }
        $this->order(null, $this->stranger())->update(['status' => OrderStatus::OUT_FOR_DELIVERY]);
        $this->assertSame(['PN-1'], $this->outgoing());

        // ‏‏ومتجرٌ غيرُ تجريبيّ ثبتت فوترتُه يصل عملاءه كما كان
        $this->demo(false);
        $this->assertTrue(MetaWhatsAppClient::sendText($this->c(), '96895555555', 'س')['ok']);
    }

    public function test_a_demo_shop_never_sends_from_abaads_shared_number(): void
    {
        $this->assertNull(WhatsAppFeature::blockReason($this->shop));
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-ABAAD'], $this->outgoing(), 'متجرٌ غيرُ تجريبيّ يرسل من رقم أبعاد كما كان');

        $this->calls = [];
        $this->demo();
        $this->assertSame(WhatsAppStatus::SKIP_DEMO, WhatsAppFeature::blockReason($this->shop));
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->order(null, $this->stranger())->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());
        $this->assertNotNull(WhatsAppStatus::reason(WhatsAppStatus::SKIP_DEMO));
    }

    /* ═════════════ وما يُحاسَب به لا يُمحى ═════════════ */

    private const KEPT = ['whatsapp_billing_trials', 'whatsapp_billings', 'whatsapp_billing_events', 'whatsapp_message_prices', 'whatsapp_messages'];

    public function test_a_demo_shop_with_whatsapp_records_is_neither_rebuilt_nor_deleted(): void
    {
        $this->demo();
        $this->trial();
        $this->order()->update(['status' => OrderStatus::READY]);
        $wamid = (string) WhatsAppMessage::where('business_id', $this->shop->id)->whereNotNull('provider_message_id')->value('provider_message_id');
        $this->metaSaid($wamid, 'delivered', ['pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'type' => 'regular', 'category' => 'utility']]);

        $saved = [];
        foreach (self::KEPT as $table) {
            $saved[$table] = DB::table($table)->where('business_id', $this->shop->id)->get()->map(fn ($r) => (array) $r)->all();
            $this->assertNotEmpty($saved[$table], $table);
        }

        $super = User::where('role', 'super_admin')->firstOrFail();
        $this->actingAs($super)->post(route('super-admin.demo.reseed', $this->shop->id), ['size' => array_key_first(DemoStore::SIZES)])->assertSessionHasErrors('size');
        $this->actingAs($super)->delete(route('super-admin.demo.destroy', $this->shop->id))->assertSessionHasErrors('store');
        $this->assertNotNull($this->shop->fresh());
        $this->assertGreaterThan(0, DB::table('orders')->where('business_id', $this->shop->id)->count());
        foreach (self::KEPT as $table) {
            $this->assertCount(count($saved[$table]), DB::table($table)->where('business_id', $this->shop->id)->get(), $table);
        }

        /*
         * ‏‏وكلُّ سجلٍّ منها وحده يكفي ليمنع — ورسالةٌ لم تصل Meta لا تمنع.
         * تُنقل السجلّاتُ الأخرى إلى متجرٍ آخر بدل حذفها: لا مفتاحَ أجنبيًّا ينكسر في أيّ قاعدة.
         */
        [$parking] = $this->makeShop('متجر جانبي', 'p@abaadapp.om', '93333333');
        $ids = fn (string $table) => array_column($saved[$table], 'id');
        $place = function (?string $only) use ($ids, $parking) {
            foreach (self::KEPT as $table) {
                DB::table($table)->whereIn('id', $ids($table))->update(['business_id' => $table === $only ? $this->shop->id : $parking->id]);
            }
        };
        foreach (self::KEPT as $only) {
            $place($only);
            $this->assertRefused($only);
        }
        $place(null);
        DemoGuard::assertWipeable($this->shop->fresh());
        DB::table('whatsapp_messages')->whereIn('id', $ids('whatsapp_messages'))->update(['business_id' => $this->shop->id, 'provider_message_id' => null]);
        DemoGuard::assertWipeable($this->shop->fresh());
    }

    private function assertRefused(string $only): void
    {
        try {
            DemoGuard::assertWipeable($this->shop->fresh());
            $this->fail('لم يُمنع المحو وفي '.$only.' سجل');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->shop->name, $e->getMessage(), $only);
        }
    }

    public function test_every_new_reason_has_words_in_both_languages(): void
    {
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        foreach ([...WhatsAppBillingTrials::RECIPIENT_CODES] as $code) {
            $this->assertArrayHasKey(WhatsAppBillingLock::says($code)['text'], $en, $code);
        }
        $this->assertArrayHasKey(WhatsAppStatus::SKIP_REASONS[WhatsAppStatus::SKIP_DEMO], $en);
    }
}
