<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentCode;
use App\Models\PlatformDocumentTemplate;
use App\Models\User;
use App\Support\PlatformDocuments;
use App\Support\SigningCodes;
use App\Support\SigningCodeSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeSigningCodeSender;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * رابطُ المستند وحده لا يوقّع — يُثبت فاتحُه أنّه يملك الهاتف المعتمد.
 *
 * ما يُحرس هنا (انظر `SigningCodes`):
 *   - الرمزُ الصحيح يفتح المستندَ لهذا المتصفّح وحده ويوقّع مرّةً.
 *   - الخاطئ يُعدّ ويُقفل بعد خمس، والمنتهي يُرفض، والمستعمَلُ لا يُعاد.
 *   - الرابطُ وحده، أو إذنٌ مزوَّر، أو إذنُ متصفّحٍ آخر أو مستندٍ آخر — لا يوقّع.
 *   - الهاتفُ المعتمد لا تغيّره صفحةُ التوقيع، وإليه وحده يُرسل الرمز.
 *   - الإلغاءُ والانتهاءُ أثناء التحقّق يُنهيانه.
 *   - الرمزُ لا يُكتب في قاعدة ولا سجلّ ولا جلسة ولا رابط.
 *   - بلا مزوّدٍ مربوط: التوقيعُ مغلق.
 *   - والمسارُ كلّه لا يكتب في جدولٍ للتجّار.
 */
class AClientProvesThePhoneBeforeSigningTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    private PlatformDocumentTemplate $contract;

    private FakeSigningCodeSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

        $plan = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299]);
        $this->shop = Business::create([
            'name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '+96891234567', 'email' => 'salem@rayan.om',
            'city' => 'مسقط', 'status' => 'نشط', 'plan_id' => $plan->id,
        ]);
        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'super@abaadapp.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->contract = PlatformDocumentTemplate::where('name', 'عقد اشتراك أبعاد')->firstOrFail();

        $this->sender = new FakeSigningCodeSender;
        $this->app->instance(SigningCodeSender::class, $this->sender);
    }

    private function issue(array $data = []): PlatformDocument
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $this->contract->id, 'business_id' => $this->shop->id, 'cycle' => 'yearly',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function requestCode(PlatformDocument $doc)
    {
        return $this->post(route('documents.sign.code', $doc->token));
    }

    private function verify(PlatformDocument $doc, ?string $code = null)
    {
        return $this->post(route('documents.sign.verify', $doc->token), ['code' => $code ?? $this->sender->last($doc)]);
    }

    private function unlock(PlatformDocument $doc): void
    {
        $this->requestCode($doc)->assertSessionHasNoErrors();
        $this->verify($doc)->assertSessionHasNoErrors();
    }

    private function signature(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 20, 150, 480, 40, imagecolorallocate($img, 15, 15, 15));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function sign(PlatformDocument $doc, array $over = [])
    {
        return $this->post(route('documents.sign.submit', $doc->token), $over + [
            'client' => ['cr' => '1234567', 'civil' => '98765432'],
            'agree' => '1',
            'signature' => $this->signature(),
        ]);
    }

    /** متصفّحٌ آخر: جلسةٌ فارغة */
    private function anotherBrowser(): void
    {
        $this->flushSession();
    }

    /* ═══ ١. الرمزُ الصحيح ═══ */

    public function test_the_right_code_opens_the_document_for_this_browser_and_signs_it(): void
    {
        $doc = $this->issue();

        $this->requestCode($doc)->assertRedirect(route('documents.sign', $doc->token))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('96891234567', $this->sender->sent[0]['phone']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sender->last($doc));

        // جلسةٌ معرّفُها معروفٌ لغيره (تثبيتُ الجلسة) — الإثباتُ لا يُكتب فيها
        $known = Str::random(40);
        $this->withCookie(config('session.cookie'), $known)
            ->post(route('documents.sign.verify', $doc->token), ['code' => $this->sender->last($doc)])
            ->assertRedirect(route('documents.sign', $doc->token))->assertSessionHasNoErrors();
        $this->assertNotSame($known, session()->getId(), 'جلسةٌ جديدة بعد الإثبات');

        $this->get(route('documents.sign', $doc->token))->assertSee('id="sign-form"', false)->assertSee('سالم الهنائي');
        $this->sign($doc)->assertSessionHasNoErrors();

        $signed = $doc->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $signed->status);
        $this->assertNotNull($signed->phone_verified_at);
        $this->assertSame('96891234567', $signed->signing_phone);
        $this->assertTrue(PlatformDocuments::intact($signed));

        $code = PlatformDocumentCode::where('document_id', $doc->id)->sole();
        $this->assertNotNull($code->verified_at);
        $this->assertNotNull($code->consumed_at);
        $this->assertSame('127.0.0.1', $code->verified_ip);
        $this->assertSame(1, $code->attempts);
    }

    /* ═══ ٢. الرمزُ الخاطئ ═══ */

    public function test_a_wrong_code_is_counted_and_five_close_it(): void
    {
        $doc = $this->issue();
        $this->requestCode($doc);
        $right = $this->sender->last($doc);
        $wrong = $right === '000000' ? '111111' : '000000';

        $this->verify($doc, $wrong)->assertSessionHasErrors(['code' => __('الرمز غير صحيح — بقيت :n محاولات.', ['n' => 4])]);
        $this->get(route('documents.sign', $doc->token))->assertDontSee('id="sign-form"', false);
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);

        for ($i = 0; $i < 4; $i++) {
            $this->verify($doc, $wrong)->assertSessionHasErrors('code');
        }
        $this->assertSame(5, PlatformDocumentCode::where('document_id', $doc->id)->value('attempts'));

        // والصحيحُ بعد الخمس لا يفتح — يُطلب رمزٌ جديد
        $this->verify($doc, $right)->assertSessionHasErrors('code');
        $this->sign($doc)->assertSessionHasErrors('sign');

        $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        $this->requestCode($doc)->assertSessionHasNoErrors();
        $this->assertNotSame('', $this->sender->last($doc));
        $this->verify($doc)->assertSessionHasNoErrors();
        $this->sign($doc)->assertSessionHasNoErrors();
    }

    public function test_eastern_digits_are_read_as_the_same_code(): void
    {
        $doc = $this->issue();
        $this->requestCode($doc);
        $eastern = strtr($this->sender->last($doc), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        $this->verify($doc, $eastern)->assertSessionHasNoErrors();
        $this->sign($doc)->assertSessionHasNoErrors();
    }

    /* ═══ ٣. الرمزُ المنتهي ═══ */

    public function test_an_expired_code_is_refused(): void
    {
        $doc = $this->issue();
        $this->requestCode($doc);

        $this->travel(SigningCodes::CODE_MINUTES + 1)->minutes();
        $this->verify($doc)->assertSessionHasErrors(['code' => __('انتهت صلاحية الرمز — اطلب رمزًا جديدًا.')]);
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);
    }

    /* ═══ ٤. التوقيعُ بلا تحقّق ═══ */

    public function test_the_link_alone_or_a_borrowed_grant_does_not_sign(): void
    {
        $doc = $this->issue();

        // الرابطُ وحده: طلبُ توقيعٍ مباشر
        $this->sign($doc)->assertSessionHasErrors(['sign' => __('أثبت رقمك برمز التحقق قبل التوقيع.')]);

        // إذنٌ مزوَّر في الجلسة لرمزٍ لم يُتحقَّق منه
        $this->requestCode($doc);
        $code = PlatformDocumentCode::where('document_id', $doc->id)->sole();
        $this->withSession(['signing_grants' => [$doc->id => [$code->id, Str::random(40)]]]);
        $this->sign($doc)->assertSessionHasErrors('sign');

        // متصفّحٌ تحقّق، وآخرُ نسخ الرابط — الآخرُ لا يوقّع
        $this->verify($doc)->assertSessionHasNoErrors();
        $real = session()->get('signing_grants.'.$doc->id);
        $this->anotherBrowser();
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->get(route('documents.sign', $doc->token))->assertDontSee('سالم الهنائي');

        // ولا رقمُ صفٍّ تحقّق منه صاحبُه ببصمةٍ مخمَّنة
        $this->withSession(['signing_grants' => [$doc->id => [$real[0], Str::random(40)]]]);
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->get(route('documents.sign', $doc->token))->assertDontSee('سالم الهنائي');

        // ولا إذنُ مستندٍ — صحيحًا كاملًا — لمستندٍ آخر
        $other = $this->issue();
        $this->withSession(['signing_grants' => [$other->id => $real]]);
        $this->sign($other)->assertSessionHasErrors('sign');
        $this->get(route('documents.sign', $other->token))->assertDontSee('id="sign-form"', false);

        // ولا إذنٌ مضت نافذتُه
        $this->anotherBrowser();
        $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        $this->unlock($doc);
        $this->travel(SigningCodes::GRANT_MINUTES + 1)->minutes();
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /* ═══ ٥ و٩. التوقيعُ المتكرّر والمتزامن ═══ */

    public function test_two_browsers_with_two_grants_sign_once(): void
    {
        $doc = $this->issue();
        $this->unlock($doc);
        $first = session()->get('signing_grants.'.$doc->id);

        // متصفّحٌ ثانٍ يثبت الرقمَ نفسه — إذنان صالحان معًا
        $this->anotherBrowser();
        $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        $this->unlock($doc);
        $second = session()->get('signing_grants.'.$doc->id);
        $this->assertNotEquals($first, $second);

        $this->sign($doc)->assertSessionHasNoErrors();
        $this->withSession(['signing_grants' => [$doc->id => $first]]);
        $this->sign($doc, ['client' => ['cr' => '0000000', 'civil' => '0000000']])
            ->assertSessionHasErrors(['sign' => __('وُقِّع هذا المستند من قبل.')]);

        // ونسخةٌ قديمةٌ من الصفّ في يد طلبٍ ثانٍ لا تتخطّى القفل
        $stale = PlatformDocument::find($doc->id);
        $stale->status = PlatformDocument::SENT;
        try {
            PlatformDocuments::sign($stale, [], $this->signature(), '203.0.113.9', 'x', $first);
            $this->fail('توقيعٌ ثانٍ مرّ');
        } catch (RuntimeException $e) {
            $this->assertSame(__('وُقِّع هذا المستند من قبل.'), $e->getMessage());
        }

        $this->assertSame('1234567', $doc->fresh()->client['cr']);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_client_who_reloads_the_page_can_still_sign(): void
    {
        $doc = $this->issue();
        $this->unlock($doc);

        // فتحُ الصفحة مرّاتٍ لا يستهلك حدَّ التوقيع — لكلّ بابٍ عدّادُه
        for ($i = 0; $i < 15; $i++) {
            $this->get(route('documents.sign', $doc->token))->assertOk();
        }
        $this->sign($doc)->assertSessionHasNoErrors()->assertRedirect(route('documents.sign', $doc->token));
        $this->assertSame(PlatformDocument::SIGNED, $doc->fresh()->status);
    }

    public function test_one_grant_signs_once_even_when_the_first_attempt_was_refused(): void
    {
        $doc = $this->issue();
        $this->unlock($doc);

        // رفضٌ قبل الاستهلاك يُبقي الإذن — لا يُعيد العميلَ إلى رمزٍ جديد لخانةٍ نسيها
        $this->sign($doc, ['client' => []])->assertSessionHasErrors('sign');
        $this->assertNull(PlatformDocumentCode::where('document_id', $doc->id)->value('consumed_at'));
        $this->sign($doc)->assertSessionHasNoErrors();
        $this->assertNotNull(PlatformDocumentCode::where('document_id', $doc->id)->value('consumed_at'));
    }

    /* ═══ ٦. الهاتفُ المعتمد لا يُبدَّل ═══ */

    public function test_the_page_cannot_change_the_phone_the_code_goes_to(): void
    {
        $doc = $this->issue();

        // طلبُ الرمز لا يقرأ رقمًا من الطلب
        $this->post(route('documents.sign.code', $doc->token), ['phone' => '+96899999999'])->assertSessionHasNoErrors();
        $this->assertSame('96891234567', $this->sender->sent[0]['phone']);

        $this->verify($doc)->assertSessionHasNoErrors();
        $this->get(route('documents.sign', $doc->token))->assertDontSee('name="client[phone]"', false)->assertSee('+96891234567');

        // والإذنُ مربوطٌ بالرقم: رقمٌ غيرُ الذي وصله الرمز لا يوقّع به
        DB::table('platform_documents')->where('id', $doc->id)->update(['signing_phone' => '96899999999']);
        $this->sign($doc)->assertSessionHasErrors('sign');
        DB::table('platform_documents')->where('id', $doc->id)->update(['signing_phone' => '96891234567']);

        $this->sign($doc, ['client' => ['cr' => '1234567', 'civil' => '98765432', 'phone' => '+96899999999']])
            ->assertSessionHasNoErrors();
        $signed = $doc->fresh();
        $this->assertSame('+96891234567', $signed->client['phone']);
        $this->assertSame('96891234567', $signed->signing_phone);
    }

    public function test_a_document_needs_a_phone_to_be_issued(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), [
            'template_id' => $this->contract->id, 'business_id' => null, 'cycle' => 'yearly', 'plan_id' => Plan::value('id'),
            'client' => ['name' => 'مريم', 'biz' => 'حلويات مريم'],
        ])->assertSessionHasErrors('document');

        $this->assertSame(0, PlatformDocument::count());
    }

    /* ═══ ٧ و٨. الإلغاءُ والانتهاءُ أثناء التحقّق ═══ */

    public function test_cancelling_during_verification_ends_it(): void
    {
        // رمزٌ أُرسل ثمّ أُلغي المستند
        $doc = $this->issue();
        $this->requestCode($doc);
        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $doc->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->verify($doc)->assertSessionHasErrors('code');
        $this->assertNotNull(PlatformDocumentCode::where('document_id', $doc->id)->value('revoked_at'));

        // وإذنٌ في الجلسة ثمّ أُلغي — لا يوقّع ولا يعرض
        $other = $this->issue();
        $this->unlock($other);
        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $other->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->sign($other)->assertSessionHasErrors('sign');
        $this->get(route('documents.sign', $other->token))->assertSee('أُلغي هذا المستند')->assertDontSee('سالم الهنائي');
        $this->requestCode($other)->assertSessionHasErrors('code');
        $this->assertSame(PlatformDocument::CANCELLED, $other->fresh()->status);
    }

    public function test_the_link_expiring_during_verification_ends_it(): void
    {
        $doc = $this->issue(['days' => 1]);
        $this->travelTo($doc->expires_at->copy()->subMinutes(5));
        $this->unlock($doc);

        $this->travel(10)->minutes();
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->verify($doc)->assertSessionHasErrors('code');
        $this->assertSame('expired', $doc->fresh()->state());
        $this->travelBack();
    }

    /* ═══ حدودُ الطلب ═══ */

    public function test_codes_are_rationed_per_document_and_per_address(): void
    {
        $doc = $this->issue();

        $this->requestCode($doc)->assertSessionHasNoErrors();
        $this->requestCode($doc)->assertSessionHasErrors(['code' => __('انتظر دقيقة قبل طلب رمز جديد.')]);
        $this->assertCount(1, $this->sender->sent);

        // الأحدثُ وحده يعمل — وما قبله يُبطَل في صفّه
        $old = $this->sender->last($doc);
        $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        $this->requestCode($doc)->assertSessionHasNoErrors();
        $this->assertSame(1, PlatformDocumentCode::where('document_id', $doc->id)->whereNull('revoked_at')->count());
        if ($old !== $this->sender->last($doc)) {
            $this->verify($doc, $old)->assertSessionHasErrors('code');
        }

        // ولكلّ مستندٍ حدُّه في الساعة وفي اليوم — من أيّ عنوانٍ جاء الطلب
        $other = $this->issue();
        for ($i = 0; $i < SigningCodes::PER_HOUR; $i++) {
            SigningCodes::send($other, '198.51.100.'.$i);
            $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        }
        try {
            SigningCodes::send($other, '198.51.100.99');
            $this->fail('رمزٌ فوق حدّ الساعة');
        } catch (RuntimeException) {
        }
        $this->travel(1)->hours();
        for ($i = 0; $i < SigningCodes::PER_DAY - SigningCodes::PER_HOUR; $i++) {
            SigningCodes::send($other, '198.51.100.'.$i);
            $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        }
        $this->travel(1)->hours();
        try {
            SigningCodes::send($other, '198.51.100.99');
            $this->fail('رمزٌ فوق حدّ اليوم');
        } catch (RuntimeException) {
        }
        $this->assertSame(SigningCodes::PER_DAY, PlatformDocumentCode::where('document_id', $other->id)->count());

        // وللعنوان حدُّه على الباب نفسه
        $third = $this->issue();
        for ($i = 0; $i < 5; $i++) {
            $this->requestCode($third)->assertRedirect();
        }
        $this->requestCode($third)->assertStatus(429);
    }

    /* ═══ ١٠. لا يتسرّب شيء ═══ */

    public function test_the_code_civil_id_and_signature_stay_out_of_storage_logs_and_links(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logs) {
            $logs[] = $m->message.' '.json_encode($m->context, JSON_UNESCAPED_UNICODE);
        });

        $doc = $this->issue();

        // الرابطُ وحده لا يعرض بيانَ العميل
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertDontSee('سالم الهنائي')->assertDontSee('+96891234567')->assertDontSee('salem@rayan.om')
            ->assertDontSee('مسقط')->assertSee('•••• 4567');

        // مزوّدٌ تعطّل ورسالتُه تحمل الرمز — والسجلُّ لا يحمله
        $this->sender->down = true;
        $this->requestCode($doc)->assertSessionHasErrors('code');
        $this->sender->down = false;
        $this->assertTrue(collect($logs)->contains(fn ($l) => str_contains($l, 'code ******')), 'الرمزُ في سجلّ الأخطاء');

        $this->travel(SigningCodes::RESEND_SECONDS + 1)->seconds();
        $response = $this->requestCode($doc);
        $code = $this->sender->last($doc);
        $this->assertStringNotContainsString($code, (string) $response->headers->get('Location'));

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->verify($doc, $wrong);
        $this->assertNull(session()->getOldInput('code'));
        $verified = $this->verify($doc, $code);
        $this->assertStringNotContainsString($code, (string) $verified->headers->get('Location'));

        $this->sign($doc, ['agree' => '']);
        $this->assertNull(session()->getOldInput('signature'), 'صورةُ التوقيع في الجلسة');
        $this->sign($doc)->assertSessionHasNoErrors();

        $rows = json_encode(DB::table('platform_document_codes')->get(), JSON_UNESCAPED_UNICODE)
            .json_encode(DB::table('platform_documents')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($code, $rows, 'الرمزُ مكتوبٌ في القاعدة');

        $activity = DB::table('activity_logs')->pluck('description')->implode("\n");
        $this->assertStringNotContainsString($code, $activity);
        $this->assertStringNotContainsString('98765432', $activity);
        $this->assertStringNotContainsString('91234567', $activity, 'الهاتفُ كاملًا في سجلّ النشاط');
        $this->assertStringContainsString('•••• 4567', $activity);

        foreach ($logs as $line) {
            $this->assertStringNotContainsString($code, $line);
            $this->assertStringNotContainsString('98765432', $line);
        }

        // متصفّحٌ آخر بالرابط نفسه بعد التوقيع: لا رقمَ مدنيّ ولا توقيع ولا ورقة
        $this->anotherBrowser();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertDontSee('98765432')->assertDontSee('data:image/png', false)->assertDontSee('1234567');
        $this->get(route('documents.sign.pdf', $doc->token))->assertRedirect(route('documents.sign', $doc->token));
    }

    /* ═══ ١١. الورقةُ والبصمة ═══ */

    public function test_the_paper_carries_the_phone_proof_and_the_fingerprint_covers_it(): void
    {
        $doc = $this->issue();
        $this->unlock($doc);
        // دقائقُ بين التحقّق والتوقيع — فوقتُ التحقّق في الورقة غيرُ وقت التوقيع
        $this->travel(3)->minutes();
        $this->sign($doc)->assertSessionHasNoErrors();
        $signed = $doc->fresh();
        $this->assertNotSame(PlatformDocuments::date($signed->signed_at), PlatformDocuments::date($signed->phone_verified_at));

        $text = implode(' ', array_column(PdfText::pages($this->get(route('documents.sign.pdf', $doc->token))->getContent()), 'text'));
        $this->assertStringContainsString('4567', $text);
        $this->assertStringContainsString(PlatformDocuments::date($signed->phone_verified_at), $text);
        $this->assertStringContainsString((string) $signed->content_hash, $text);
        $this->assertTrue(PlatformDocuments::intact($signed));

        $row = DB::table('platform_documents')->where('id', $doc->id)->first();
        foreach (['signing_phone' => '96899999999', 'phone_verified_at' => '2020-01-01 00:00:00'] as $column => $forged) {
            DB::table('platform_documents')->where('id', $doc->id)->update([$column => $forged]);
            $this->assertFalse(PlatformDocuments::intact($doc->fresh()), $column);
            DB::table('platform_documents')->where('id', $doc->id)->update([$column => $row->{$column}]);
            $this->assertTrue(PlatformDocuments::intact($doc->fresh()), $column.' restored');
        }

        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))
            ->assertInertia(fn ($p) => $p->where('document.signing_phone', '+96891234567')
                ->where('document.phone_verified_at', PlatformDocuments::date($signed->phone_verified_at))
                ->where('document.intact', true));
    }

    /* ═══ بلا مزوّد: مغلق ═══ */

    public function test_without_a_sender_signing_stays_closed(): void
    {
        $this->app->offsetUnset(SigningCodeSender::class);
        $this->assertFalse(SigningCodes::available());

        $doc = $this->issue();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('التوقيع الإلكتروني غير متاح حاليًا')->assertDontSee('id="code-form"', false)->assertDontSee('سالم الهنائي');
        $this->requestCode($doc)->assertSessionHasErrors('code');
        $this->verify($doc, '123456')->assertSessionHasErrors('code');
        $this->sign($doc)->assertSessionHasErrors('sign');
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);
        $this->assertSame(0, PlatformDocumentCode::count());

        $this->actingAs($this->super)->get(route('super-admin.documents.index'))
            ->assertInertia(fn ($p) => $p->where('signing_ready', false));
    }

    /* ═══ ١٢. التجّارُ لا يُمَسّون ═══ */

    public function test_the_whole_flow_writes_to_no_merchant_table(): void
    {
        $own = ['platform_documents', 'platform_document_codes', 'platform_document_templates', 'activity_logs', 'sessions', 'cache', 'cache_locks'];
        $count = function () use ($own) {
            $out = [];
            foreach (Schema::getTables() as $table) {
                $name = $table['name'] ?? $table;
                if (! in_array($name, $own, true) && ! str_starts_with($name, 'sqlite_')) {
                    $out[$name] = DB::table($name)->count();
                }
            }

            return $out;
        };
        $shopBefore = (array) DB::table('businesses')->where('id', $this->shop->id)->first();
        $before = $count();

        $doc = $this->issue();
        $this->unlock($doc);
        $this->sign($doc)->assertSessionHasNoErrors();
        $this->get(route('documents.sign.pdf', $doc->token))->assertOk();
        $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->assertOk();

        $this->assertSame($before, $count());
        $this->assertSame($shopBefore, (array) DB::table('businesses')->where('id', $this->shop->id)->first());
        $this->assertSame(0, DB::table('activity_logs')->whereNotNull('business_id')->count(), 'سجلُّ المنصّة لا يُنسب إلى متجر');
    }
}
