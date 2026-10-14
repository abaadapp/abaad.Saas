<?php

namespace Tests\Feature;

use App\Mail\WebsiteLeadMail;
use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\User;
use App\Support\Crm;
use App\Support\CrmWebsiteInquiries;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * «تواصل معنا» في abaadapp.om يصل دفترَ مبيعات أبعاد — لا تطبيقَ بريد الزائر.
 *
 * الطلبُ عميلٌ محتمَلٌ مصدرُه «الموقع الإلكتروني» وملاحظةٌ مؤرَّخة بكلّ ما كتبه،
 * ثمّ إشعارٌ إلى بريد أبعاد ردُّه إلى الزائر. والدفترُ هو الأصل: بريدٌ يسقط لا
 * يُضيع طلبًا. والبابُ عامّ، فحرّاسُه هنا: الأصل، والحجم، والخنق، والفخّ،
 * والتحقّق، وحقولُ الدفتر الممنوعة.
 */
class AWebsiteVisitorReachesTheSalesBookTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://abaadapp.om';

    private const SECRET = 'S3CRET-smtp-password-never-shown';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // مُرسِلٌ حيٌّ في نظر `Mailer::configured` — والإرسالُ نفسُه مزيَّفٌ في كلّ اختبار
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.password' => self::SECRET,
            'mail.from.address' => 'no-reply@abaadapp.om',
            'contact.leads_to' => 'abaadappom@gmail.com',
        ]);

        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e);
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

    private function send(array $data, string $ip = '10.0.0.1', array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(
            route('website.contact'),
            $data,
            array_merge(['Origin' => self::SITE, 'Accept' => 'application/json'], $headers),
        );
    }

    private function lead(): ?CrmLead
    {
        return CrmLead::where('phone', '96891234567')->first();
    }

    /** كلُّ ما سُجّل — نصًّا واحدًا يُبحث فيه */
    private function logText(): string
    {
        return json_encode(array_map(fn (MessageLogged $e) => [$e->message, $e->context], $this->logged), JSON_UNESCAPED_UNICODE);
    }

    /* ═════════════════════ الطلبُ يصل الدفتر ═════════════════════ */

    public function test_an_arabic_submission_becomes_a_website_lead_with_everything_the_visitor_wrote(): void
    {
        Mail::fake();

        $this->send($this->valid())->assertOk()->assertExactJson(['ok' => true]);

        $lead = $this->lead();
        $this->assertNotNull($lead, 'لم يُكتب عميلٌ محتمَل — والرقمُ مطبَّعٌ بتطبيع الدفتر نفسِه');
        $this->assertSame(Crm::SOURCE_WEBSITE, $lead->source);
        $this->assertSame(Crm::NEW, $lead->stage);
        $this->assertSame(Crm::ACTIVE, $lead->status);
        $this->assertSame('محمد', $lead->name);
        $this->assertSame('ورد الخوير', $lead->business_name);
        $this->assertSame('9123 4567', $lead->phone_raw);
        $this->assertNull($lead->assigned_to);
        $this->assertNotNull($lead->last_contact_at);
        $this->assertSame(1, $lead->stageEvents()->where('to_stage', Crm::NEW)->count());

        $note = $lead->notes()->sole();
        $this->assertNull($note->user_id, 'لا مستخدمَ وهميّ يُخترع للموقع');
        $this->assertSame(CrmWebsiteInquiries::AUTHOR, $note->user_name);
        foreach (['محمد', 'ورد الخوير', '9123 4567', 'mohammed@example.com', 'التجزئة', 'العربية', 'أريد نظام نقاط بيع لمحلّي في الخوير.'] as $part) {
            $this->assertStringContainsString($part, $note->body, "ضاع من الطلب: {$part}");
        }
        $this->assertSame('أريد نظام نقاط بيع لمحلّي في الخوير.', $lead->notes_summary);
    }

    public function test_an_english_submission_is_kept_the_same_way(): void
    {
        Mail::fake();

        $this->send($this->valid(['name' => 'Sara', 'business_name' => 'Rose Shop', 'locale' => 'en', 'activity_type' => 'ecommerce', 'message' => 'We need an online store.']))
            ->assertOk();

        $note = $this->lead()->notes()->sole();
        $this->assertStringContainsString('الإنجليزية', $note->body);
        $this->assertStringContainsString('التجارة الإلكترونية', $note->body);
        $this->assertStringContainsString('We need an online store.', $note->body);
    }

    /* ═════════════════════ رقمٌ واحد، عميلٌ واحد ═════════════════════ */

    public function test_the_same_phone_writes_twice_one_lead_two_dated_inquiries_two_notices(): void
    {
        Mail::fake();

        $this->send($this->valid(['message' => 'الرسالة الأولى من محمد.']))->assertOk();
        $this->travel(5)->minutes();
        $this->send($this->valid(['phone' => '+968 9123-4567', 'message' => 'الرسالة الثانية من محمد.']))->assertOk();

        $this->assertSame(1, CrmLead::count(), 'رقمٌ واحدٌ صار عميلَين');

        $notes = $this->lead()->notes()->orderBy('id')->get();
        $this->assertCount(2, $notes, 'الرسالةُ الثانية رُميت لأنّ العميل موجود');
        $this->assertStringContainsString('الرسالة الأولى من محمد.', $notes[0]->body);
        $this->assertStringContainsString('الرسالة الثانية من محمد.', $notes[1]->body);
        $this->assertTrue($notes[1]->created_at->gt($notes[0]->created_at), 'لكلّ طلبٍ تاريخُه');
        $this->assertSame('الرسالة الثانية من محمد.', $this->lead()->notes_summary);

        Mail::assertQueued(WebsiteLeadMail::class, 2);
    }

    /** وإعادةُ الطلب نفسِه بحروفه في دقائق — انقطاعٌ لا رسالة: لا ملاحظةَ ثانية ولا بريدَ ثانٍ */
    public function test_the_exact_same_request_resent_within_minutes_is_kept_once(): void
    {
        Mail::fake();

        $this->send($this->valid())->assertOk();
        $this->travel(3)->minutes();
        $this->send($this->valid())->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(1, CrmNote::count());
        Mail::assertQueued(WebsiteLeadMail::class, 1);

        // وبعد النافذة طلبٌ جديد ولو تطابقت حروفُه
        $this->travel(20)->minutes();
        $this->send($this->valid())->assertOk();
        $this->assertSame(2, CrmNote::count());
        Mail::assertQueued(WebsiteLeadMail::class, 2);
    }

    /** وما في الملفّ أغنى ممّا في النموذج — لا يُمسح ولا يُستبدل، ويُملأ الفارغ وحده */
    public function test_a_known_lead_keeps_what_the_sales_team_wrote(): void
    {
        Mail::fake();

        $staff = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);
        $known = CrmLead::create([
            'phone' => '96891234567', 'phone_raw' => '91234567', 'name' => null,
            'business_name' => 'ورود الخوير — الفرع الرئيسي', 'source' => Crm::SOURCE_WHATSAPP,
            'status' => Crm::ACTIVE, 'stage' => Crm::QUOTATION, 'assigned_to' => $staff->id,
            'expected_value' => 120, 'last_contact_at' => now()->subWeek(),
        ]);

        $this->send($this->valid())->assertOk();

        $known->refresh();
        $this->assertSame('ورود الخوير — الفرع الرئيسي', $known->business_name, 'مُسح اسمُ النشاط المعدَّل يدويًّا');
        $this->assertSame('محمد', $known->name, 'الاسمُ الفارغ يُملأ');
        $this->assertSame(Crm::SOURCE_WHATSAPP, $known->source);
        $this->assertSame(Crm::QUOTATION, $known->stage);
        $this->assertSame($staff->id, $known->assigned_to);
        $this->assertSame('120.000', $known->expected_value);
        $this->assertSame('91234567', $known->phone_raw);
        $this->assertTrue($known->last_contact_at->isToday(), 'تواصلٌ واردٌ جديد ولم يتحرّك «آخر تواصل»');
        $this->assertSame(1, $known->notes()->count());
        $this->assertSame(0, $known->stageEvents()->count(), 'طلبٌ من الموقع حرّك المرحلة');
    }

    /** طلبان متزامنان للرقم نفسِه: السابقُ يكتب الصفّ، واللاحقُ يكتب ملاحظتَه عليه — لا صفَّين */
    public function test_two_submissions_racing_for_one_phone_make_one_lead(): void
    {
        Mail::fake();

        $raced = false;
        DB::listen(function (QueryExecuted $q) use (&$raced) {
            if ($raced || ! str_contains($q->sql, 'from "crm_leads"') || ! str_contains($q->sql, '"phone" = ?')) {
                return;
            }
            $raced = true;
            // الطلبُ الآخر يكتب صفَّه بين القراءة والكتابة
            DB::table('crm_leads')->insert([
                'phone' => '96891234567', 'name' => 'سبق', 'source' => Crm::SOURCE_WEBSITE,
                'status' => Crm::ACTIVE, 'stage' => Crm::NEW, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->send($this->valid())->assertOk();

        $this->assertTrue($raced, 'لم يقع السباق — تغيّر شكلُ الاستعلام');
        $this->assertSame(1, CrmLead::where('phone', '96891234567')->count(), 'سباقٌ صنع عميلَين');
        $this->assertSame('سبق', $this->lead()->name);
        $this->assertSame(1, $this->lead()->notes()->count(), 'الطلبُ الخاسرُ للسباق ضاع');
        Mail::assertQueued(WebsiteLeadMail::class, 1);
    }

    /* ═════════════════════ البريد: إشعارٌ لا أصل ═════════════════════ */

    public function test_the_notice_goes_to_abaads_gmail_and_a_reply_reaches_the_visitor(): void
    {
        Mail::fake();

        $this->send($this->valid())->assertOk();
        $lead = $this->lead();

        $queued = null;
        Mail::assertQueued(WebsiteLeadMail::class, function (WebsiteLeadMail $mail) use ($lead, &$queued) {
            $queued = $mail;
            $this->assertTrue($mail->hasTo('abaadappom@gmail.com'));
            $this->assertTrue($mail->hasReplyTo('mohammed@example.com'), 'الردُّ لا يصل الزائر');
            $this->assertNull($mail->envelope()->from, 'المُرسِلُ عنوانُ النظام — MAIL_FROM_ADDRESS');
            $this->assertTrue($mail->hasSubject('طلب تواصل جديد من موقع أبعاد — ورد الخوير'));
            $this->assertSame(route('super-admin.crm.leads.show', $lead->id), $mail->inquiry['lead_url']);

            $html = $mail->render();
            foreach (['محمد', 'ورد الخوير', '9123 4567', 'mohammed@example.com', 'التجزئة', 'العربية', 'أريد نظام نقاط بيع لمحلّي في الخوير.', 'الموقع الإلكتروني', e($mail->inquiry['lead_url'])] as $part) {
                $this->assertStringContainsString($part, $html, "غاب عن البريد: {$part}");
            }

            return true;
        });

        // والرسالةُ كما تخرج فعلًا: رؤوسُها بعد البناء، لا ما في الصنف
        $array = (new MailManager($this->app))->mailer('array');
        $queued->send($array);
        $sent = $array->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

        $this->assertSame(['no-reply@abaadapp.om'], array_map(fn ($a) => $a->getAddress(), $sent->getFrom()), 'المُرسِلُ انتحل بريدَ الزائر');
        $this->assertSame(['mohammed@example.com'], array_map(fn ($a) => $a->getAddress(), $sent->getReplyTo()));
        $this->assertSame(['abaadappom@gmail.com'], array_map(fn ($a) => $a->getAddress(), $sent->getTo()));
    }

    public function test_the_recipient_is_read_from_config_not_from_the_code(): void
    {
        Mail::fake();
        config(['contact.leads_to' => 'sales@abaadapp.om']);

        $this->send($this->valid())->assertOk();

        Mail::assertQueued(WebsiteLeadMail::class, fn (WebsiteLeadMail $m) => $m->hasTo('sales@abaadapp.om'));
    }

    /** مُرسِلٌ ساقطٌ في الطابور: الطلبُ في الدفتر، والزائرُ يسمع «وصل»، والسجلُّ بلا بياناته */
    public function test_a_failing_mailer_loses_nothing_and_logs_no_visitor_data(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('550 rejected for mohammed@example.com');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        $response = $this->send($this->valid())->assertOk()->assertExactJson(['ok' => true]);

        $lead = $this->lead();
        $this->assertNotNull($lead, 'سقوطُ البريد أضاع العميل');
        $this->assertSame(1, $lead->notes()->count(), 'سقوطُ البريد أضاع الطلب');

        $log = $this->logText();
        $this->assertStringContainsString('website contact: notification', $log, 'الفشلُ لم يُسجَّل');
        foreach (['mohammed@example.com', 'محمد', '9123', 'أريد نظام', self::SECRET] as $private) {
            $this->assertStringNotContainsString($private, $log, "سُجّل ما لا يُسجَّل: {$private}");
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    /** ومُرسِلٌ لا يُوصل (log) لا يُكتب فيه الطلبُ كاملًا — ولا يُضيعه */
    public function test_a_mailer_that_delivers_nothing_is_not_used_and_the_lead_is_kept(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);

        $this->send($this->valid())->assertOk();

        $this->assertNotNull($this->lead());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertStringContainsString('mail is not configured', $this->logText());
        $this->assertStringNotContainsString('mohammed@example.com', $this->logText());
    }

    /* ═════════════════════ التحقّق ═════════════════════ */

    public function test_a_bad_email_a_bad_phone_or_a_missing_field_is_a_422_and_writes_nothing(): void
    {
        Mail::fake();

        $cases = [
            'email' => ['email' => 'not-an-email'],
            'phone' => ['phone' => '12'],
            'activity_type' => ['activity_type' => 'casino'],
            'locale' => ['locale' => 'fr'],
            'message' => ['message' => str_repeat('م', 3001)],
            'name' => ['name' => str_repeat('م', 151)],
        ];
        foreach (array_keys($this->valid()) as $field) {
            $cases["missing {$field}"] = [$field => ''];
        }

        $ip = 1;
        foreach ($cases as $case => $override) {
            $field = str_replace('missing ', '', $case);
            $this->send($this->valid($override), '10.1.0.'.$ip++)
                ->assertStatus(422)
                ->assertJsonPath('ok', false)
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, CrmLead::count());
        $this->assertSame(0, CrmNote::count());
        Mail::assertNothingQueued();
    }

    public function test_an_absurd_body_is_refused_before_it_is_read(): void
    {
        $body = http_build_query($this->valid(['message' => str_repeat('x', 20000)]));

        $this->call('POST', route('website.contact'), [], [], [], [
            'HTTP_ORIGIN' => self::SITE,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(413)->assertJsonPath('error', 'payload_too_large');

        $this->assertSame(0, CrmLead::count());
    }

    /** المرحلةُ والحالُ والمصدرُ والإسنادُ والقيمةُ والباقةُ والمتجر — لا يكتبها المتصفّح */
    public function test_the_browser_cannot_write_privileged_crm_fields(): void
    {
        Mail::fake();
        $staff = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);
        $shop = Business::create(['name' => 'محل', 'type' => 'محل ورود', 'status' => 'active']);

        $this->send($this->valid([
            'stage' => Crm::WON, 'status' => Crm::CONVERTED, 'source' => Crm::SOURCE_MANUAL,
            'assigned_to' => $staff->id, 'expected_value' => 9999, 'plan_id' => 1, 'interested_plan_id' => 1,
            'business_id' => $shop->id, 'converted_business_id' => $shop->id, 'tags' => ['vip'],
        ]))->assertOk();

        $lead = $this->lead();
        $this->assertSame(Crm::NEW, $lead->stage);
        $this->assertSame(Crm::ACTIVE, $lead->status);
        $this->assertSame(Crm::SOURCE_WEBSITE, $lead->source);
        $this->assertNull($lead->assigned_to);
        $this->assertNull($lead->expected_value);
        $this->assertNull($lead->interested_plan_id);
        $this->assertNull($lead->converted_business_id);
        $this->assertNull($lead->tags);
    }

    /* ═════════════════════ سبام ═════════════════════ */

    public function test_a_bot_that_fills_the_hidden_field_hears_ok_and_writes_and_sends_nothing(): void
    {
        Mail::fake();

        $this->send($this->valid(['website' => 'https://spam.example']))->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(0, CrmLead::count());
        $this->assertSame(0, CrmNote::count());
        Mail::assertNothingQueued();
    }

    public function test_one_address_is_slowed_after_five_a_minute(): void
    {
        Mail::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->send($this->valid(['phone' => '9123 456'.$i]))->assertOk();
        }

        $this->send($this->valid(['phone' => '9123 4569']))
            ->assertStatus(429)
            ->assertJsonPath('error', 'too_many_attempts')
            ->assertHeader('Retry-After');

        $this->assertSame(5, CrmLead::count());

        // وعنوانٌ آخر لا يدفع ثمنَ غيره
        $this->send($this->valid(['phone' => '9123 4569']), '10.0.0.2')->assertOk();
    }

    public function test_one_phone_cannot_write_hundreds_of_times_from_many_addresses(): void
    {
        Mail::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->send($this->valid(['message' => 'رسالة رقم '.$i.' من محمد']), '10.2.0.'.$i)->assertOk();
        }

        $this->send($this->valid(['message' => 'رسالة سادسة من محمد']), '10.2.0.99')->assertStatus(429);
        $this->assertSame(5, CrmNote::count());
    }

    /* ═════════════════════ الأصل وCORS وبلا جلسة ═════════════════════ */

    public function test_the_site_reaches_it_without_login_csrf_or_a_session(): void
    {
        Mail::fake();

        $response = $this->send($this->valid())->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::SITE);

        $this->assertGuest();
        $response->assertCookieMissing(config('session.cookie'));
        $response->assertCookieMissing('XSRF-TOKEN');
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_a_foreign_or_missing_origin_is_refused_and_granted_nothing(): void
    {
        Mail::fake();

        foreach (['https://evil.example', 'https://www.abaadapp.om', 'https://app.abaadapp.om.evil.example', 'null'] as $origin) {
            $response = $this->send($this->valid(), '10.3.0.1', ['Origin' => $origin])->assertStatus(403);
            $this->assertNotGranted($response, $origin);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.2'])
            ->post(route('website.contact'), $this->valid(), ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertSame(0, CrmLead::count());
    }

    public function test_the_preflight_answers_the_site_and_no_one_else(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', route('website.contact'), [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        $preflight(self::SITE)->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::SITE)
            ->assertHeader('Access-Control-Allow-Methods', 'POST');

        $this->assertNotGranted($preflight('https://evil.example'), 'https://evil.example');
    }

    /**
     * أصلٌ لم يُمنح شيئًا: لا `*` ولا اسمُه في Access-Control-Allow-Origin.
     *
     * وبأصلٍ مسموحٍ واحد تُرسل المكتبةُ اسمَه هو في كلّ ردّ — فيرى المتصفّحُ
     * أنّه لا يطابق الصفحةَ الغريبة ويحجب الردَّ عنها.
     */
    private function assertNotGranted(TestResponse $response, string $origin): void
    {
        $granted = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('*', $granted);
        $this->assertNotSame($origin, $granted, "مُنح {$origin} حقَّ قراءة الردّ");
        $this->assertContains($granted, [null, self::SITE]);
    }

    /** ولا مسارَ آخرُ في النظام صار مفتوحًا لأصلٍ غريب */
    public function test_cors_opens_this_path_alone(): void
    {
        $this->assertSame(['website/contact'], config('cors.paths'));
        $this->assertSame([self::SITE], config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));

        $this->get(route('login'), ['Origin' => self::SITE])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    /* ═════════════════════ ما حوله ═════════════════════ */

    public function test_merchant_customer_and_order_tables_are_untouched(): void
    {
        Mail::fake();
        $shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);
        // زبونةٌ للمحلّ برقم الزائر نفسِه — ولا تصير عميلًا ولا تتغيّر
        $customer = Customer::create(['business_id' => $shop->id, 'name' => 'مريم', 'phone' => '91234567']);

        $tables = ['businesses', 'customers', 'orders', 'users', 'products', 'invoices'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->send($this->valid())->assertOk();

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertSame('مريم', $customer->fresh()->name);
        $this->assertSame($customer->updated_at->toDateTimeString(), $customer->fresh()->updated_at->toDateTimeString());
    }

    public function test_the_crm_screens_still_open_and_show_the_inquiry(): void
    {
        Mail::fake();
        $this->send($this->valid())->assertOk();
        $lead = $this->lead();

        $staff = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);

        foreach (['super-admin.crm.dashboard', 'super-admin.crm.leads.index', 'super-admin.crm.pipeline', 'super-admin.crm.reports'] as $name) {
            $this->actingAs($staff)->get(route($name))->assertOk();
        }

        $this->actingAs($staff)->get(route('super-admin.crm.leads.show', $lead->id))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lead.source', Crm::SOURCE_WEBSITE)
                ->where('notes.0.author', CrmWebsiteInquiries::AUTHOR)
                ->where('notes.0.body', fn ($body) => str_contains($body, 'mohammed@example.com')));
    }
}
