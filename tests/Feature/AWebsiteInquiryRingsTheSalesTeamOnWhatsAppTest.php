<?php

namespace Tests\Feature;

use App\Jobs\SendWebsiteLeadWhatsAppAlert;
use App\Mail\WebsiteLeadMail;
use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use App\Support\CrmWhatsApp;
use App\Support\WebsiteLeadWhatsAppAlert;
use App\Support\WhatsAppMode;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * طلبُ «تواصل معنا» يُحفظ في CRM أوّلًا — ثمّ يُنبَّه فريقُ أبعاد على واتساب.
 *
 * التنبيهُ يخرج من خطّ المبيعات وحده، بقالبٍ معتمَد وحده، إلى رقمٍ داخليٍّ من
 * الإعداد وحده، وبعد الـcommit وحده. وما يسقط منه لا يمسّ الطلبَ ولا الزائر،
 * ولا يخصم من تاجرٍ رسالة. والبريدُ على HOLD كما هو.
 */
class AWebsiteInquiryRingsTheSalesTeamOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://abaadapp.om';

    private const TEAM = '9555 0101';

    private const TEAM_E164 = '96895550101';

    private const SALES_TOKEN = 'sales-token-never-printed-0123456789';

    private WhatsAppConnection $sales;

    private WhatsAppConnection $notices;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // البريدُ على HOLD كما على الإنتاج — `log` لا يُرسل، فلا يُصفّ إشعارُه
        config(['mail.default' => 'log', 'contact.leads_whatsapp_to' => self::TEAM]);

        $this->notices = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'NOTICES-PN',
            'waba_id' => 'WABA-NOTICES',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'notices-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $this->sales = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_CRM_SALES,
            'phone_number_id' => 'SALES-PN',
            'waba_id' => 'WABA-SALES',
            'display_phone_number' => '+968 7200 0000',
            'access_token' => self::SALES_TOKEN,
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $this->templateIs('APPROVED');

        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e);
    }

    private function templateIs(?string $status): void
    {
        Setting::where('key', WebsiteLeadWhatsAppAlert::STATUS_SETTING)->delete();

        if ($status !== null) {
            Setting::create([
                'business_id' => null,
                'key' => WebsiteLeadWhatsAppAlert::STATUS_SETTING,
                'value' => json_encode(['status' => $status]),
            ]);
        }
    }

    /** @return array<string, string> */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'محمد',
            'business_name' => 'ورد الخوير',
            'phone' => '9123 4567',
            'email' => 'mohammed@example.com',
            'activity_type' => 'retail',
            'message' => 'أريد نظام نقاط بيع لمحلّي في الخوير.',
            'locale' => 'ar',
        ], $overrides);
    }

    private function send(array $data): TestResponse
    {
        return $this->post(route('website.contact'), $data, ['Origin' => self::SITE, 'Accept' => 'application/json']);
    }

    private function metaAccepts(): void
    {
        Http::fake(['*/SALES-PN/messages' => Http::response(['messages' => [['id' => 'wamid.ALERT1']]])]);
    }

    private function inquiry(): array
    {
        $note = CrmNote::latest('id')->firstOrFail();

        return [CrmLead::findOrFail($note->lead_id), $note];
    }

    private function job(int $attempt = 1): SendWebsiteLeadWhatsAppAlert
    {
        [$lead, $note] = $this->inquiry();
        $job = (new SendWebsiteLeadWhatsAppAlert($lead->id, $note->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;

        return $job;
    }

    private function savedWithoutAlert(): void
    {
        Queue::fake();
        $this->send($this->valid())->assertOk()->assertExactJson(['ok' => true]);
        Queue::assertNothingPushed();
        $this->assertSame(1, CrmLead::count());
        $this->assertSame(1, CrmNote::count());
    }

    private function loggedReason(string $reason): bool
    {
        return collect($this->logged)->contains(fn (MessageLogged $e) => $e->message === 'website lead alert: not sent'
            && ($e->context['reason'] ?? null) === $reason);
    }

    /* ═══════════════ ١ — الدفترُ أوّلًا، ثمّ التنبيهُ بعد الـcommit ═══════════════ */

    public function test_a_new_inquiry_is_saved_first_and_the_alert_is_queued_after_the_commit(): void
    {
        $baseline = DB::transactionLevel();
        $seen = null;

        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use ($baseline, &$seen) {
            // وقتَ الصفّ: الـtransaction أُغلق، والعميلُ والملاحظةُ محفوظان
            $seen = [
                'level' => DB::transactionLevel(),
                'note' => CrmNote::whereKey($job->noteId)->where('lead_id', $job->leadId)->exists(),
                'job' => $job,
            ];

            return null;
        });

        $this->send($this->valid())->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame($baseline, $seen['level']);
        $this->assertTrue($seen['note']);
        $this->assertInstanceOf(SendWebsiteLeadWhatsAppAlert::class, $seen['job']);
        $this->assertTrue($seen['job']->afterCommit);
        $this->assertSame('whatsapp', $seen['job']->queue);

        // والطابورُ لا يحمل من الزائر شيئًا — معرّفان لا غير
        $payload = serialize($seen['job']);
        foreach (['محمد', 'ورد الخوير', 'mohammed@example.com', 'نقاط بيع', '91234567', self::TEAM_E164] as $pii) {
            $this->assertStringNotContainsString($pii, $payload);
        }
    }

    /* ═══════════════ ٢ و٣ — التكرارُ الحرفيّ بلا تنبيه، والرسالةُ الجديدة بتنبيه ═══════════════ */

    public function test_an_exact_resend_rings_once_and_a_new_message_from_the_same_phone_rings_again(): void
    {
        Queue::fake();

        $this->send($this->valid())->assertOk();
        $this->send($this->valid())->assertOk();
        Queue::assertPushed(SendWebsiteLeadWhatsAppAlert::class, 1);

        $this->send($this->valid(['message' => 'وعندي فرعٌ ثانٍ في صحار.']))->assertOk();

        $this->assertSame(1, CrmLead::count());
        $this->assertSame(2, CrmNote::count());

        $pushed = Queue::pushed(SendWebsiteLeadWhatsAppAlert::class);
        $this->assertCount(2, $pushed);
        $this->assertSame([CrmLead::first()->id], $pushed->pluck('leadId')->unique()->values()->all());
        $this->assertSame(CrmNote::orderBy('id')->pluck('id')->all(), $pushed->pluck('noteId')->all());
    }

    /* ═══════════════ ٤ — ميتا تقبل: القالبُ والمستلمُ والمتغيّرات ═══════════════ */

    public function test_meta_receives_the_approved_template_from_the_sales_line_to_the_team_number(): void
    {
        Queue::fake();
        $this->send($this->valid())->assertOk();
        $this->metaAccepts();

        $job = $this->job();
        $job->handle();
        $job->assertNotReleased();

        [$lead] = $this->inquiry();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($lead) {
            return str_contains($request->url(), '/SALES-PN/messages')
                && $request->hasHeader('Authorization', 'Bearer '.self::SALES_TOKEN)
                && $request['to'] === self::TEAM_E164
                && $request['type'] === 'template'
                && $request['template']['name'] === 'abaad_website_lead_alert'
                && $request['template']['language']['code'] === 'ar'
                && array_column($request['template']['components'][0]['parameters'], 'text') === [
                    'محمد',
                    'ورد الخوير',
                    '+96891234567',
                    'التجزئة',
                    'أريد نظام نقاط بيع لمحلّي في الخوير.',
                    route('super-admin.crm.leads.show', $lead->id),
                ];
        });

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'NOTICES-PN'));
        $this->assertSame('sent', Cache::get(SendWebsiteLeadWhatsAppAlert::claimKey($this->inquiry()[1]->id)));
    }

    public function test_a_long_multi_line_message_is_one_cut_line_in_the_alert_and_whole_in_the_crm(): void
    {
        app()->setLocale('en');
        $long = "السطر الأول\n\nالسطر الثاني    بمسافات\n".str_repeat('كلام ', 200);

        Queue::fake();
        $this->send($this->valid(['message' => $long, 'activity_type' => 'ecommerce', 'locale' => 'en']))->assertOk();
        $this->metaAccepts();

        $this->job()->handle();

        Http::assertSent(function (HttpRequest $request) {
            $values = array_column($request['template']['components'][0]['parameters'], 'text');

            return $values[3] === 'التجارة الإلكترونية'
                && mb_strlen($values[4]) === WebsiteLeadWhatsAppAlert::MESSAGE_LIMIT
                && str_starts_with($values[4], 'السطر الأول السطر الثاني بمسافات كلام')
                && str_ends_with($values[4], '…')
                && collect($values)->every(fn ($v) => $v !== '' && ! preg_match('/[\n\t]| {2,}/u', $v));
        });

        // والرسالةُ كاملةً في الملاحظة كما كتبها
        $this->assertStringContainsString(trim(str_replace("\r\n", "\n", $long)), $this->inquiry()[1]->body);
    }

    /* ═══════════════ ٥ — خطأٌ عابر يُعاد بحدّ ═══════════════ */

    public function test_a_temporary_meta_failure_is_retried_and_then_sent_once(): void
    {
        Queue::fake();
        $this->send($this->valid())->assertOk();

        Http::fake(['*/SALES-PN/messages' => Http::sequence()
            ->push(['error' => ['code' => 131016, 'message' => 'Service unavailable']], 503)
            ->push(['messages' => [['id' => 'wamid.ALERT2']]])]);

        $first = $this->job(1);
        $first->handle();
        $first->assertReleased(60);
        $this->assertNull(Cache::get(SendWebsiteLeadWhatsAppAlert::claimKey($this->inquiry()[1]->id)));

        $second = $this->job(2);
        $second->handle();
        $second->assertNotReleased();

        // ومحاولةٌ ثالثةٌ بعد النجاح لا تُرسل ثانية
        $this->job(3)->handle();

        Http::assertSentCount(2);
        $this->assertSame('sent', Cache::get(SendWebsiteLeadWhatsAppAlert::claimKey($this->inquiry()[1]->id)));
    }

    public function test_a_temporary_failure_on_the_last_attempt_stops_instead_of_looping(): void
    {
        Queue::fake();
        $this->send($this->valid())->assertOk();
        Http::fake(['*/SALES-PN/messages' => Http::response(['error' => ['code' => 4, 'message' => 'rate']], 429)]);

        $last = $this->job(3);
        $last->handle();
        $last->assertNotReleased();

        $this->assertSame('failed', Cache::get(SendWebsiteLeadWhatsAppAlert::claimKey($this->inquiry()[1]->id)));
        Http::assertSentCount(1);
    }

    /* ═══════════════ ٦ — رفضٌ دائم لا يدور ═══════════════ */

    public function test_a_permanent_or_template_rejection_is_not_retried(): void
    {
        Queue::fake();
        $this->send($this->valid())->assertOk();
        Http::fake(['*/SALES-PN/messages' => Http::response(
            ['error' => ['code' => 132001, 'message' => 'Template name does not exist in the translation']], 404,
        )]);

        $job = $this->job(1);
        $job->handle();
        $job->assertNotReleased();

        // وإعادةُ التسليم من الطابور نفسِه لا تُنادي ميتا ثانية
        $this->job(2)->handle();

        Http::assertSentCount(1);
        $this->assertSame('failed', Cache::get(SendWebsiteLeadWhatsAppAlert::claimKey($this->inquiry()[1]->id)));
        $this->assertTrue(collect($this->logged)->contains(fn ($e) => $e->message === 'website lead alert: failed'
            && $e->context['code'] === '132001'));
    }

    /* ═══════════════ ٧ إلى ١٠ — ما لا يُرسَل يُكتب سببُه، والطلبُ محفوظ ═══════════════ */

    public function test_no_configured_recipient_saves_the_lead_and_skips_the_alert(): void
    {
        config(['contact.leads_whatsapp_to' => null]);

        $this->savedWithoutAlert();
        $this->assertTrue($this->loggedReason(WebsiteLeadWhatsAppAlert::SKIP_NO_RECIPIENT));
    }

    public function test_an_invalid_recipient_saves_the_lead_and_skips_the_alert(): void
    {
        config(['contact.leads_whatsapp_to' => '12']);

        $this->savedWithoutAlert();
        $this->assertTrue($this->loggedReason(WebsiteLeadWhatsAppAlert::SKIP_BAD_RECIPIENT));
    }

    public function test_the_sales_number_is_never_told_to_message_itself(): void
    {
        config(['contact.leads_whatsapp_to' => '72000000']);

        $this->savedWithoutAlert();
        $this->assertTrue($this->loggedReason(WebsiteLeadWhatsAppAlert::SKIP_SELF));
    }

    public function test_a_disconnected_sales_line_saves_the_lead_and_does_not_borrow_the_notice_number(): void
    {
        $this->sales->forceFill(['status' => WhatsAppConnection::INACTIVE])->save();
        // ولو أُذن لرقم الإشعارات أن يحمل دفترَ المبيعات — لا يُستعار للتنبيه
        Setting::create(['business_id' => null, 'key' => 'crm_whatsapp_shared', 'value' => '1']);
        $this->assertNotNull(CrmWhatsApp::line());

        $this->savedWithoutAlert();
        $this->assertTrue($this->loggedReason(WebsiteLeadWhatsAppAlert::SKIP_NO_LINE));
    }

    public function test_an_unapproved_or_unchecked_template_never_reaches_meta(): void
    {
        foreach (['PENDING', 'REJECTED', 'MISSING', null] as $i => $status) {
            $this->templateIs($status);

            Queue::fake();
            $this->send($this->valid(['message' => 'طلبٌ رقم '.$i]))->assertOk()->assertExactJson(['ok' => true]);
            Queue::assertNothingPushed();
        }

        // وما صُفّ قبل أن يُوقَف القالب يُسأل عنه ثانيةً عند التنفيذ
        $this->templateIs('PAUSED');
        Http::fake();
        $this->job()->handle();

        Http::assertNothingSent();
        $this->assertSame(4, CrmNote::count());
        $this->assertTrue($this->loggedReason(WebsiteLeadWhatsAppAlert::SKIP_TEMPLATE));
    }

    /* ═══════════════ ١١ — سقوطُ واتساب لا يُسقط الزائر ═══════════════ */

    public function test_whatsapp_failing_in_every_way_still_answers_the_visitor_ok(): void
    {
        config(['queue.default' => 'sync']);

        $answers = [
            Http::response(['error' => ['code' => 131000, 'message' => 'boom']], 500),
            Http::response(['error' => ['code' => 131026, 'message' => 'undeliverable']], 400),
            fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ];

        foreach ($answers as $i => $answer) {
            Http::fake(['*/SALES-PN/messages' => $answer]);

            $this->send($this->valid(['message' => 'محاولةٌ رقم '.$i]))
                ->assertOk()->assertExactJson(['ok' => true]);
        }

        $this->assertSame(1, CrmLead::count());
        $this->assertSame(3, CrmNote::count());
    }

    public function test_a_queue_that_cannot_take_the_alert_does_not_fail_the_visitor(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue table is gone'));

        $this->send($this->valid())->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(1, CrmNote::count());
        $this->assertTrue(collect($this->logged)->contains(fn ($e) => $e->message === 'website lead alert: could not be queued'
            && $e->context['error'] === 'RuntimeException'));
    }

    /* ═══════════════ ١٢ و١٣ — لا حصّةَ تاجرٍ ولا بياناتُه ═══════════════ */

    public function test_the_alert_spends_no_merchant_quota_and_touches_no_merchant_data(): void
    {
        $shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);
        // زبونةٌ للمحلّ برقم الزائر نفسِه — لا تُقرأ ولا تتغيّر
        Customer::create(['business_id' => $shop->id, 'name' => 'مريم', 'phone' => '91234567']);
        DB::table('whatsapp_usage_periods')->insert([
            'business_id' => $shop->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'used' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('whatsapp_message_packs')->insert([
            'business_id' => $shop->id, 'messages' => 100, 'amount' => 5, 'status' => 'مفعّلة',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tables = [
            'businesses', 'customers', 'orders', 'customer_invoices', 'invoices',
            'whatsapp_messages', 'whatsapp_usage_periods', 'whatsapp_message_packs',
            'whatsapp_template_mappings', 'support_conversations', 'support_messages', 'crm_messages',
        ];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $before = $snapshot();

        config(['queue.default' => 'sync']);
        $this->metaAccepts();
        $this->send($this->valid())->assertOk();

        Http::assertSentCount(1);
        $this->assertSame($before, $snapshot());
    }

    /* ═══════════════ ١٤ — البريدُ على HOLD كما هو ═══════════════ */

    public function test_email_stays_on_hold_and_unchanged(): void
    {
        Mail::fake();
        Queue::fake();

        $this->send($this->valid())->assertOk();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        Mail::assertNotQueued(WebsiteLeadMail::class);
        $this->assertTrue(collect($this->logged)->contains(
            fn ($e) => $e->message === 'website contact: notification not sent, mail is not configured',
        ));
        Queue::assertPushed(SendWebsiteLeadWhatsAppAlert::class, 1);
    }

    /* ═══════════════ السجلّ بلا بيانات الزائر ولا سرّ ═══════════════ */

    public function test_nothing_logged_carries_the_visitor_or_the_token(): void
    {
        config(['queue.default' => 'sync']);

        Http::fake(['*/SALES-PN/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.OK']]])
            ->push(['error' => ['code' => 131026, 'message' => 'Message undeliverable to 96895550101']], 400)]);

        $this->send($this->valid())->assertOk();
        $this->send($this->valid(['message' => 'رسالةٌ ثانية لا تصل']))->assertOk();
        config(['contact.leads_whatsapp_to' => null]);
        $this->send($this->valid(['message' => 'رسالةٌ ثالثة بلا مستلم']))->assertOk();

        $this->assertNotEmpty($this->logged);
        $text = collect($this->logged)->map(fn ($e) => $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE))->implode("\n");

        foreach (['محمد', 'ورد الخوير', 'mohammed@example.com', 'نقاط بيع', 'لا تصل', '91234567', self::TEAM_E164, self::SALES_TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    /* ═══════════════ ردُّ الفريق على التنبيه ليس عميلًا ═══════════════ */

    public function test_the_team_replying_on_the_sales_line_does_not_become_a_lead(): void
    {
        CrmWhatsApp::receive($this->sales, [
            'id' => 'wamid.TEAM', 'from' => self::TEAM_E164, 'type' => 'text', 'text' => ['body' => 'تمام'],
        ]);
        $this->assertSame(0, CrmLead::count());

        // وغيرُه يُكتب كما كان
        CrmWhatsApp::receive($this->sales, [
            'id' => 'wamid.STRANGER', 'from' => '96899887766', 'type' => 'text', 'text' => ['body' => 'أريد أبعاد'],
        ]);
        $this->assertSame(1, CrmLead::count());
    }

    /* ═══════════════ حالُ القالب من ميتا — قراءةٌ لا إرسال ═══════════════ */

    public function test_the_hourly_check_reads_the_template_on_the_sales_account_only(): void
    {
        $this->templateIs(null);

        Http::fake(['*/WABA-SALES/message_templates*' => Http::response(['data' => [
            ['name' => 'abaad_website_lead_alert', 'language' => 'ar', 'status' => 'APPROVED', 'components' => []],
            ['name' => 'abaad_order_ready', 'language' => 'ar', 'status' => 'APPROVED', 'components' => []],
        ]])]);

        $this->assertSame(0, Artisan::call('crm:sync-website-lead-alert'));
        $output = Artisan::output();

        $this->assertSame('APPROVED', WebsiteLeadWhatsAppAlert::templateStatus());
        $this->assertStringContainsString('Template status: APPROVED', $output);
        $this->assertStringContainsString('Alert would send: yes', $output);
        $this->assertStringNotContainsString(self::SALES_TOKEN, $output);
        $this->assertStringNotContainsString(self::TEAM_E164, $output);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'WABA-NOTICES') || $r->method() === 'POST');
        // وقوالبُ إشعارات المحلّات لا يُكتب فيها صفّ
        $this->assertSame(0, DB::table('whatsapp_template_mappings')->count());
    }

    public function test_a_missing_template_or_an_unreachable_meta_keeps_the_alert_off(): void
    {
        $this->templateIs(null);
        Http::fake(['*/WABA-SALES/message_templates*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['name' => 'abaad_website_lead_alert', 'language' => 'ar', 'status' => 'APPROVED']]])
            ->push(['error' => ['message' => 'down']], 500)]);

        Artisan::call('crm:sync-website-lead-alert');
        $this->assertSame('MISSING', WebsiteLeadWhatsAppAlert::templateStatus());
        $output = Artisan::output();
        $this->assertStringContainsString('Template exists at Meta: no', $output);
        $this->assertStringContainsString('Alert would send: no (template_not_approved)', $output);

        Artisan::call('crm:sync-website-lead-alert');
        $this->assertSame('APPROVED', WebsiteLeadWhatsAppAlert::templateStatus());

        // وتعذّرُ السؤال لا يكتب حالًا مخترَعًا — يبقى آخرُ ما قالته ميتا
        Artisan::call('crm:sync-website-lead-alert');
        $this->assertSame('APPROVED', WebsiteLeadWhatsAppAlert::templateStatus());
        $this->assertStringContainsString('meta_unreachable', Artisan::output());
    }
}
