<?php

namespace Tests\Feature;

use App\Mail\SubscriptionNoticeMail;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\SubscriptionWhatsApp;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * إنذارُ الاشتراك يصل التاجرَ على واتساب من رقم أبعاد — لا بالبريد وحده.
 *
 * البريدُ على الإنتاج لا يخرج، فلم يصل تاجرًا إنذارٌ قطّ. والحارسُ هنا على
 * أربعة: يخرج من رقم الإشعارات وحده إلى رقم المتجر بقالب مرحلته؛ ولا يخرج
 * شيءٌ قبل أن تعتمد Meta القالب؛ ولا يتكرّر في اليوم؛ والقوالبُ لا تُرسَل
 * للمراجعة إلّا بأمرٍ صريح.
 */
class ASubscriptionNoticeReachesTheOwnerOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> «القالب|اللغة» ← حاله في حساب أبعاد عند Meta */
    private array $templates = [];

    /** @var list<array{status: int, body: array<string, mixed>}> ردودُ الإرسال بترتيبها، وما بعدها نجاح */
    private array $sendReplies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        Setting::create(['business_id' => null, 'key' => 'grace_days', 'value' => '7']);

        $this->approveAll();

        Http::fake(function (HttpRequest $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/9000/message_templates') && $r->method() === 'GET') {
                return Http::response(['data' => array_map(
                    fn ($key, $status) => ['name' => explode('|', $key)[0], 'language' => explode('|', $key)[1], 'status' => $status],
                    array_keys($this->templates), $this->templates,
                )], 200);
            }

            if (str_ends_with($path, '/9000/message_templates') && $r->method() === 'POST') {
                return Http::response(['id' => 'TPL-'.$r['name'], 'status' => 'PENDING', 'category' => 'UTILITY'], 200);
            }

            if (str_ends_with($path, '/PN-ABAAD/messages')) {
                $reply = array_shift($this->sendReplies) ?? ['status' => 200, 'body' => ['messages' => [['id' => 'wamid.SUB']]]];

                return Http::response($reply['body'], $reply['status']);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$path]], 500);
        });
    }

    private function approveAll(string $status = 'APPROVED'): void
    {
        $this->templates = [];

        foreach (SubscriptionWhatsApp::TEMPLATES as $t) {
            foreach (array_keys($t['bodies']) as $language) {
                $this->templates[$t['name'].'|'.$language] = $status;
            }
        }
    }

    private function line(string $purpose = WhatsAppMode::PURPOSE_NOTIFICATIONS, string $pn = 'PN-ABAAD'): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'purpose' => $purpose,
            'phone_number_id' => $pn, 'waba_id' => '9000', 'meta_business_id' => 'MB-ABAAD',
            'display_phone_number' => '+968 7114 1624', 'access_token' => 'TOKEN-OF-ABAAD',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    private function shop(int $daysFromToday, ?string $phone = '9988 7766', ?string $email = 'shop@abaadapp.om', bool $demo = false): Business
    {
        return Business::create([
            'name' => 'متجر الورود', 'type' => 'عام', 'status' => 'نشط', 'is_demo' => $demo,
            'email' => $email, 'phone' => $phone, 'ends_at' => now()->addDays($daysFromToday)->toDateString(),
        ]);
    }

    /** @return list<array<string, mixed>> ما خرج إلى نقطة الإرسال */
    private function sends(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/PN-ABAAD/messages'))
            ->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    /** @return list<string> */
    private function params(array $send): array
    {
        return array_column($send['template']['components'][0]['parameters'] ?? [], 'text');
    }

    // ————— ١· يخرج إلى رقم المتجر بقالب مرحلته —————

    public function test_a_week_before_the_owner_gets_the_approved_template_from_abaads_number(): void
    {
        $this->line();
        $shop = $this->shop(7);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $sends = $this->sends();
        $this->assertCount(1, $sends);
        $this->assertSame('96899887766', $sends[0]['to']);
        $this->assertSame('template', $sends[0]['type']);
        $this->assertSame('abaad_subscription_ends_soon', $sends[0]['template']['name']);
        $this->assertSame('ar', $sends[0]['template']['language']['code']);
        $this->assertSame(['متجر الورود', $shop->ends_at->format('Y-m-d')], $this->params($sends[0]));

        // ‏‏والبريدُ باقٍ كما كان — واتساب يُضاف ولا يحلّ محلّه
        Mail::assertSent(SubscriptionNoticeMail::class, 1);
    }

    public function test_the_first_notice_comes_two_weeks_before_not_every_day(): void
    {
        $this->line();
        $this->shop(14);
        // ‏‏وما بين المواعيد صمت: لا إنذار يوميّ
        $this->shop(13);
        $this->shop(10);
        $this->shop(2);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $sends = $this->sends();
        $this->assertCount(1, $sends);
        $this->assertSame('abaad_subscription_ends_soon', $sends[0]['template']['name']);
        $this->assertSame(now()->addDays(14)->format('Y-m-d'), $this->params($sends[0])[1]);
    }

    public function test_the_expiry_day_has_its_own_template(): void
    {
        $this->line();
        $this->shop(0);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame('abaad_subscription_ends_today', $this->sends()[0]['template']['name']);
    }

    public function test_the_grace_message_names_the_last_working_day(): void
    {
        $this->line();
        // ‏‏انتهى قبل ستّة أيّام ومهلتُه سبعة: يعمل حتى نهاية الغد
        $shop = $this->shop(-6);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $send = $this->sends()[0];
        $this->assertSame('abaad_subscription_grace', $send['template']['name']);
        $this->assertSame(
            ['متجر الورود', $shop->ends_at->format('Y-m-d'), now()->addDay()->format('Y-m-d')],
            $this->params($send),
        );
    }

    public function test_the_lock_day_says_the_system_stopped(): void
    {
        $this->line();
        // ‏‏انتهى قبل ثمانية أيّام ومهلتُه سبعة: اليومُ يومُ الإقفال
        $this->shop(-8);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame('abaad_subscription_locked', $this->sends()[0]['template']['name']);
    }

    public function test_a_shop_with_no_email_still_hears_on_whatsapp(): void
    {
        $this->line();
        $this->shop(1, email: null);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertCount(1, $this->sends());
        Mail::assertNothingSent();
    }

    // ————— ٢· لا يخرج شيءٌ قبل اعتماد Meta —————

    public function test_nothing_leaves_before_meta_approves_the_template(): void
    {
        $this->line();
        $this->shop(7);
        $this->approveAll('PENDING');

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame([], $this->sends());
        Mail::assertSent(SubscriptionNoticeMail::class, 1);
    }

    public function test_an_approved_template_of_another_stage_does_not_open_this_one(): void
    {
        $this->line();
        $this->shop(7);
        $this->approveAll('PENDING');
        $this->templates['abaad_subscription_locked|ar'] = 'APPROVED';

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame([], $this->sends());
    }

    // ————— ٣· لمن لا يخرج —————

    public function test_a_demo_shop_and_a_shop_without_a_phone_get_nothing(): void
    {
        $this->line();
        $this->shop(7, demo: true);
        $this->shop(7, phone: null);
        $this->shop(7, phone: '12');

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame([], $this->sends());
    }

    public function test_only_the_notifications_number_sends_never_the_sales_number(): void
    {
        $this->line(WhatsAppMode::PURPOSE_CRM_SALES, 'PN-ABAAD');
        $this->shop(7);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame([], $this->sends());
    }

    public function test_a_shop_whose_date_is_not_a_notice_day_gets_nothing(): void
    {
        $this->line();
        $this->shop(5);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame([], $this->sends());
    }

    // ————— ٤· مرّةٌ في اليوم —————

    public function test_a_second_run_the_same_day_sends_nothing_new(): void
    {
        $this->line();
        $this->shop(7);

        $this->artisan('subscriptions:notify')->assertSuccessful();
        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertCount(1, $this->sends());
    }

    public function test_a_send_meta_refused_is_tried_again_on_the_next_run(): void
    {
        $this->line();
        $this->shop(7);
        $this->sendReplies = [['status' => 400, 'body' => ['error' => ['code' => 131026, 'message' => 'undeliverable']]]];

        $this->artisan('subscriptions:notify')->assertSuccessful();
        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertCount(2, $this->sends());
    }

    public function test_the_dry_run_neither_asks_meta_nor_sends(): void
    {
        $this->line();
        $this->shop(7);

        $this->artisan('subscriptions:notify', ['--dry' => true])
            ->expectsOutputToContain('…7766')
            ->assertSuccessful();

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    // ————— ٥· القوالب تُرسَل للمراجعة بأمرٍ صريح وحده —————

    public function test_the_templates_command_only_reads_without_create(): void
    {
        $this->line();
        $this->templates = [];

        $this->artisan('subscriptions:whatsapp-templates')
            ->expectsOutputToContain('abaad_subscription_ends_soon (en): غير موجود')
            ->assertSuccessful();

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
    }

    public function test_create_submits_only_the_missing_templates_as_arabic_utility(): void
    {
        $this->line();
        $this->templates = ['abaad_subscription_ends_soon|ar' => 'APPROVED', 'abaad_subscription_grace|en' => 'PENDING'];

        $this->artisan('subscriptions:whatsapp-templates', ['--create' => true])->assertSuccessful();

        $created = Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/9000/message_templates'))
            ->map(fn ($pair) => $pair[0]->data())->values();

        $this->assertSame([
            'abaad_subscription_ends_soon|en',
            'abaad_subscription_ends_today|ar', 'abaad_subscription_ends_today|en',
            'abaad_subscription_grace|ar',
            'abaad_subscription_locked|ar', 'abaad_subscription_locked|en',
        ], $created->map(fn ($t) => $t['name'].'|'.$t['language'])->all());

        $bodies = collect(SubscriptionWhatsApp::TEMPLATES)->mapWithKeys(fn ($t) => [$t['name'] => $t['bodies']]);

        foreach ($created as $t) {
            $this->assertSame($bodies[$t['name']][$t['language']], $t['components'][0]['text']);
            $this->assertSame('UTILITY', $t['category']);
            $body = $t['components'][0];
            // ‏‏مثالٌ لكلّ متغيّرٍ لا أكثر ولا أقلّ — وإلّا ردّت Meta القالب
            $this->assertSame(preg_match_all('/\{\{\d+\}\}/', $body['text']), count($body['example']['body_text'][0]));
        }

        $this->assertSame([], $this->sends());
    }

    public function test_no_template_body_starts_or_ends_with_a_variable_and_both_languages_carry_the_same_variables(): void
    {
        foreach (SubscriptionWhatsApp::TEMPLATES as $t) {
            $this->assertSame(['ar', 'en'], array_keys($t['bodies']), $t['name']);

            foreach ($t['bodies'] as $language => $body) {
                $this->assertDoesNotMatchRegularExpression('/^\s*\{\{|\}\}\s*$/u', $body, $t['name'].' '.$language);
            }

            preg_match_all('/\{\{\d+\}\}/', $t['bodies']['ar'], $ar);
            preg_match_all('/\{\{\d+\}\}/', $t['bodies']['en'], $en);
            $this->assertSame($ar[0], $en[0], $t['name']);
        }
    }

    // ————— ٦· بلغة صاحب النشاط —————

    private function owner(Business $shop, ?string $locale): void
    {
        User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => 'owner'.$shop->id.'@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'locale' => $locale,
        ]);
    }

    public function test_an_owner_who_chose_english_gets_the_english_template_with_the_english_shop_name(): void
    {
        $this->line();
        $shop = $this->shop(7);
        $shop->update(['name_en' => 'Roses Shop']);
        $this->owner($shop, 'en');

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $send = $this->sends()[0];
        $this->assertSame('en', $send['template']['language']['code']);
        $this->assertSame(['Roses Shop', $shop->ends_at->format('Y-m-d')], $this->params($send));
    }

    public function test_the_owners_own_choice_beats_the_shops_setting(): void
    {
        $this->line();
        $shop = $this->shop(7);
        Setting::create(['business_id' => $shop->id, 'key' => 'locale', 'value' => 'en']);
        $this->owner($shop, 'ar');

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $send = $this->sends()[0];
        $this->assertSame('ar', $send['template']['language']['code']);
        $this->assertSame('متجر الورود', $this->params($send)[0]);
    }

    public function test_without_an_owner_choice_the_shop_then_the_platform_language_decides(): void
    {
        $this->line();
        Setting::create(['business_id' => null, 'key' => 'locale', 'value' => 'en']);
        // ‏‏الأوّل ضبط متجرُه العربيّة فتغلب المنصّة؛ والثاني بلا مالكٍ ولا إعداد فتقول المنصّة
        $first = $this->shop(7);
        $this->owner($first, null);
        Setting::create(['business_id' => $first->id, 'key' => 'locale', 'value' => 'ar']);
        $this->shop(7, phone: '9911 2233');

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $sends = collect($this->sends())->keyBy('to');
        $this->assertSame('ar', $sends['96899887766']['template']['language']['code']);
        $this->assertSame('en', $sends['96899112233']['template']['language']['code']);
        // ‏‏وبلا اسمٍ إنجليزيّ يُكتب الاسمُ كما هو
        $this->assertSame('متجر الورود', $this->params($sends['96899112233'])[0]);
    }

    public function test_arabic_is_the_default(): void
    {
        $this->line();
        $this->shop(7);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame('ar', $this->sends()[0]['template']['language']['code']);
    }

    public function test_an_english_owner_gets_arabic_while_english_is_not_yet_approved(): void
    {
        $this->line();
        $shop = $this->shop(7);
        $this->owner($shop, 'en');
        $this->templates['abaad_subscription_ends_soon|en'] = 'PENDING';

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame('ar', $this->sends()[0]['template']['language']['code']);
    }

    public function test_nothing_leaves_when_neither_language_is_approved(): void
    {
        $this->line();
        $shop = $this->shop(7);
        $this->owner($shop, 'en');
        $this->templates['abaad_subscription_ends_soon|en'] = 'PENDING';
        $this->templates['abaad_subscription_ends_soon|ar'] = 'REJECTED';

        $this->artisan('subscriptions:notify')->assertSuccessful();

        $this->assertSame([], $this->sends());
    }
}
