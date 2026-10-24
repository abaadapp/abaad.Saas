<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Document\Pdf\Driver;
use App\Support\Pdf;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AbaadSubscriptionContract;
use Tests\TestCase;

/**
 * عقدُ أبعاد يخرج برابطٍ ويعود موقَّعًا — بلا رمزٍ ولا مزوّدِ رسائل.
 *
 * السيناريو كما يعيشه المدير: يُصدر عقدَ اشتراكٍ بباقةٍ ودورة، فينسخ الرابطَ أو
 * يفتح به واتساب، فيفتحه العميلُ ويوقّع ويضغط «إرسال التوقيع» — فيصير العقدُ
 * «موقَّعًا» في لوحته بتوقيعه وإقراره وورقته المحفوظة، ويدقّ الجرس.
 *
 * وما يُحرس معه:
 *   - النتيجةُ واحدةٌ أيًّا كانت طريقُ الرابط، والمشاركةُ فعلُ المدير لا دليلُ وصول.
 *   - الدوراتُ الثلاث بأسعار الباقة ومُدَدها، بلا فيضٍ إلى الشهر التالي.
 *   - التوقيعُ لا يُكرَّر، ولا يُقال «تم» قبل الحفظ، وفشلُ حفظ الورقة لا يردّه.
 *   - عقدُ التجديد يتبع سابقَه، وتوقيعُه لا يمدّد اشتراكًا ولا يكتب فاتورة.
 */
class AnAbaadContractGoesOutAndComesBackSignedTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private Plan $pro;

    private Business $shop;

    private PlatformDocumentTemplate $contract;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        $this->travelTo('2026-10-24 10:00:00');

        $this->pro = Plan::create([
            'name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000,
        ]);
        $this->shop = Business::create([
            'name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'city' => 'مسقط',
            'status' => 'نشط', 'plan_id' => $this->pro->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-08-31',
        ]);
        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'super@abaadapp.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->contract = $this->subscriptionContract();
    }

    private function issue(array $data = []): PlatformDocument
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $this->contract->id, 'business_id' => $this->shop->id, 'cycle' => 'yearly',
        ])->assertSessionHasNoErrors()->assertRedirect();
        auth()->logout();

        return PlatformDocument::latest('id')->firstOrFail();
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

    /** «إرسال التوقيع» كما تُرسله الصفحة — طلبُ JSON */
    private function send(PlatformDocument $doc, array $over = [])
    {
        auth()->logout();

        return $this->keepCopy($this->postJson(route('documents.sign.submit', $doc->token), $over + [
            'client' => ['cr' => '1234567', 'civil' => '98765432'],
            'agree' => '1',
            'signature' => $this->signature(),
        ]));
    }

    /** متصفّحُ العميل يحفظ كوكي نسخته ويعيده — كما يفعل المتصفّحُ الحقيقيّ */
    private function keepCopy($response)
    {
        if ($cookie = $response->getCookie(PlatformDocument::COPY_COOKIE)) {
            $this->withCookie(PlatformDocument::COPY_COOKIE, $cookie->getValue());
        }

        return $response;
    }

    /** السيناريو كاملًا من طريقٍ واحد — ويعيد ما يراه المديرُ بعده */
    private function roundTrip(string $via): PlatformDocument
    {
        $doc = $this->issue();
        $this->assertMatchesRegularExpression('/^AB-\d{6}-\d{4}$/', $doc->number);

        // المديرُ يشارك الرابط — يُسجَّل فعلُه، والمستندُ ينتظر كما هو
        $this->actingAs($this->super)->post(route('super-admin.documents.shared', $doc->id), ['via' => $via])->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertSame($via, $doc->shared_via);
        $this->assertSame(PlatformDocument::SENT, $doc->status);
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))
            ->assertInertia(fn ($p) => $p->where('document.state', 'sent')->where('document.shared', PlatformDocument::SHARED[$via]));

        // العميلُ يفتح الرابط — العقدُ كاملًا، بلا رمز
        auth()->logout();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('إرسال التوقيع')->assertSee('الباقة الاحترافية')->assertSee('299.000')
            ->assertSee('12 شهرًا')->assertSee('2026-10-24')->assertSee('2027-10-24')
            ->assertSee('brand/abaad/logo.svg', false)->assertSee('مدة الاشتراك:')
            ->assertDontSee('رمز التحقق')->assertDontSee('name="code"', false);

        // يوقّع ويضغط «إرسال التوقيع»
        $this->send($doc)->assertOk()->assertJson(['ok' => true, 'redirect' => route('documents.sign', $doc->token)]);
        $this->get(route('documents.sign', $doc->token))->assertSee('تم إرسال توقيعك')->assertSee('تنزيل نسختك PDF');

        $signed = $doc->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $signed->status);
        $this->assertSame(PlatformDocuments::CONSENT, $signed->consent_text);
        $this->assertNotNull($signed->agreed_at);
        $this->assertTrue(PlatformDocuments::intact($signed));
        Storage::disk('local')->assertExists($signed->pdf_path);
        $this->assertSame($signed->pdf_hash, hash('sha256', Storage::disk('local')->get($signed->pdf_path)));

        return $signed;
    }

    /* ═══ السيناريو: نسخُ الرابط، ثمّ واتساب — والنتيجةُ واحدة ═══ */

    public function test_a_copied_link_comes_back_signed_in_the_admin_panel(): void
    {
        $doc = $this->roundTrip('copy');

        // يظهر عند المدير موقَّعًا: الاسمُ والوقتُ والتوقيعُ والإقرارُ والورقة
        $this->actingAs($this->super)->get(route('super-admin.documents.index', ['state' => 'signed']))
            ->assertInertia(fn ($p) => $p->has('documents', 1)->where('documents.0.state', 'signed')->where('documents.0.number', $doc->number));
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))
            ->assertInertia(fn ($p) => $p->where('document.state', 'signed')
                ->where('document.client_name', 'سالم الهنائي')
                ->where('document.signed_at', PlatformDocuments::date($doc->signed_at))
                ->where('document.consent_text', PlatformDocuments::CONSENT)
                ->where('document.pdf_hash', $doc->pdf_hash)
                ->where('document.intact', true)
                ->where('document.signature', fn ($s) => str_starts_with((string) $s, 'data:image/png;base64,')));

        // والجرسُ يقوله دون أن يُرسله أحد
        $this->actingAs($this->super)->get(route('super-admin.documents.index'))
            ->assertInertia(fn ($p) => $p->where('notifications.items', fn ($items) => collect($items)
                ->contains(fn ($i) => str_contains($i['text'], $doc->number) && str_contains($i['url'], '/documents/'.$doc->id))));

        // المديرُ والعميلُ ينزّلان الورقةَ المحفوظة نفسها
        $admin = $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id));
        $admin->assertOk();
        auth()->logout();
        $client = $this->get(route('documents.sign.pdf', $doc->token));
        $client->assertOk();
        $this->assertSame($doc->pdf_hash, hash('sha256', $admin->getContent()));
        $this->assertSame($admin->getContent(), $client->getContent());

        // وتوقيعٌ ثانٍ يُردّ — والصفحةُ تعود إلى النسخة الموقَّعة
        $this->send($doc, ['client' => ['cr' => '0000000', 'civil' => '0000000']])
            ->assertStatus(409)->assertJson(['redirect' => route('documents.sign', $doc->token)]);
        $this->assertSame('1234567', $doc->fresh()->client['cr']);
        $this->assertCount(1, collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_ends_with($f, '.png')));
    }

    public function test_a_link_sent_through_whatsapp_ends_exactly_the_same(): void
    {
        $doc = $this->issue();
        $show = $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id));
        $wa = $show->viewData('page')['props']['document']['whatsapp'];
        $this->assertStringStartsWith('https://wa.me/96891234567?text=', $wa);
        $text = rawurldecode(substr($wa, strlen('https://wa.me/96891234567?text=')));
        $this->assertStringContainsString('سالم الهنائي', $text);
        $this->assertStringContainsString($doc->number, $text);
        $this->assertStringContainsString(route('documents.sign', $doc->token), $text);
        $this->assertStringContainsString('أبعاد', $text);

        $viaWhatsapp = $this->roundTrip('whatsapp');
        $viaCopy = $this->roundTrip('copy');

        foreach (['status', 'consent_text'] as $k) {
            $this->assertSame($viaCopy->{$k}, $viaWhatsapp->{$k});
        }
        $this->assertSame(array_keys($viaCopy->client), array_keys($viaWhatsapp->client));
        $this->assertNotNull($viaWhatsapp->pdf_hash);
    }

    public function test_a_link_passed_on_after_signing_shows_nothing_of_the_client(): void
    {
        $doc = $this->issue();
        $signed = $this->send($doc)->assertOk();

        // مفتاحُ النسخة: لا يقرؤه جافاسكربت، على مسار الرابط وحده، لأيّام النسخة — والقاعدةُ تحفظ بصمتَه لا قيمتَه
        $cookie = $signed->getCookie(PlatformDocument::COPY_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/sign/'.$doc->token, $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(PlatformDocument::COPY_DAYS)->timestamp, $cookie->getExpiresTime(), 5);
        $key = $cookie->getValue();
        $this->assertSame(40, strlen($key));
        $row = DB::table('platform_documents')->where('id', $doc->id)->first();
        $this->assertSame(hash('sha256', $key), $row->copy_key_hash);
        $this->assertStringNotContainsString($key, json_encode($row, JSON_UNESCAPED_UNICODE));

        // جهازُ الموقِّع يرى نسخته وينزّلها
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('98765432')->assertSee('data:image/png', false)->assertSee('id="pdf"', false);
        $this->get(route('documents.sign.pdf', $doc->token))->assertOk();

        // ومن مُرِّر إليه الرابطُ — بلا كوكي، أو بمفتاحٍ آخر، أو فارغ — يرى «وُقِّع» وحده
        foreach ([null, Str::random(40), ''] as $other) {
            $this->defaultCookies = $other === null ? [] : [PlatformDocument::COPY_COOKIE => $other];
            $this->get(route('documents.sign', $doc->token))->assertOk()
                ->assertSee('لا تُعرض نسخته الموقَّعة إلا على الجهاز')
                ->assertDontSee('98765432')->assertDontSee('1234567')->assertDontSee('سالم الهنائي')->assertDontSee('زهور الريان')
                ->assertDontSee('299.000')->assertDontSee('data:image/png', false)->assertDontSee('id="pdf"', false);
            $this->get(route('documents.sign.pdf', $doc->token))->assertNotFound();
        }

        // وتوقيعٌ ثانٍ منه لا يعطيه مفتاحًا ولا يبدّل مفتاحَ الموقِّع
        $this->send($doc)->assertStatus(409)->assertCookieMissing(PlatformDocument::COPY_COOKIE);
        $this->assertSame(hash('sha256', $key), DB::table('platform_documents')->where('id', $doc->id)->value('copy_key_hash'));

        // ومفتاحُ الموقِّع نفسُه لا يفتح بعد أيّام النسخة
        $this->defaultCookies = [PlatformDocument::COPY_COOKIE => $key];
        $this->travel(PlatformDocument::COPY_DAYS + 1)->days();
        $this->get(route('documents.sign', $doc->token))->assertSee('انتهت مدة عرض نسخته')->assertDontSee('98765432');
        $this->get(route('documents.sign.pdf', $doc->token))->assertNotFound();

        // والمديرُ ينزّلها متى شاء ويرسلها لعميله
        $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->assertOk();
        $this->travelBack();
    }

    public function test_sharing_is_recorded_as_the_admins_act_and_never_signs_or_leaks_the_link(): void
    {
        $doc = $this->issue();
        $this->actingAs($this->super)->post(route('super-admin.documents.shared', $doc->id), ['via' => 'carrier-pigeon'])
            ->assertSessionHasErrors('via');
        $this->post(route('super-admin.documents.shared', $doc->id), ['via' => 'copy'])->assertSessionHasNoErrors();
        $this->post(route('super-admin.documents.shared', $doc->id), ['via' => 'whatsapp'])->assertSessionHasNoErrors();

        $this->assertSame('whatsapp', $doc->fresh()->shared_via);
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);
        $log = DB::table('activity_logs')->pluck('description')->implode("\n");
        $this->assertStringContainsString('نسخ رابط توقيع المستند '.$doc->number, $log);
        $this->assertStringContainsString('فتح واتساب لإرسال المستند '.$doc->number, $log);
        $this->assertStringNotContainsString($doc->token, $log, 'الرابطُ في سجلّ النشاط');

        // والتاجرُ لا يصل الباب
        $merchant = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($merchant)->post(route('super-admin.documents.shared', $doc->id), ['via' => 'copy'])->assertForbidden();
    }

    /* ═══ الدوراتُ الثلاث ═══ */

    public function test_the_three_cycles_carry_the_plan_price_and_their_term(): void
    {
        $expect = [
            'monthly' => ['29.900', '2026-11-24', 'شهر واحد'],
            'semiannual' => ['179.400', '2027-04-24', '6 أشهر'],
            'yearly' => ['299.000', '2027-10-24', '12 شهرًا'],
        ];
        foreach ($expect as $cycle => [$price, $end, $duration]) {
            $doc = $this->issue(['cycle' => $cycle]);
            $this->assertSame($price, $doc->plan['price'], $cycle);
            $this->assertSame($duration, $doc->plan['duration'], $cycle);
            $this->assertSame('2026-10-24', $doc->term_starts_on->toDateString(), $cycle);
            $this->assertSame($end, $doc->term_ends_on->toDateString(), $cycle);
            $this->get(route('documents.sign', $doc->token))->assertSee($price)->assertSee($end)->assertSee($duration);
        }

        // من آخر الشهر إلى آخر الشهر — لا فيضَ إلى ما بعده (`Billing::extend`)
        $doc = $this->issue(['cycle' => 'monthly', 'term_starts_on' => '2027-01-31']);
        $this->assertSame('2027-02-28', $doc->term_ends_on->toDateString());
        $doc = $this->issue(['cycle' => 'semiannual', 'term_starts_on' => '2026-08-31']);
        $this->assertSame('2027-02-28', $doc->term_ends_on->toDateString());

        // ونشاطٌ اشتراكُه جارٍ: المدّةُ الجديدة تبدأ من نهايته
        $this->shop->update(['ends_at' => '2026-12-15']);
        $this->assertSame('2026-12-15', $this->issue(['cycle' => 'yearly'])->term_starts_on->toDateString());

        // ونهايةٌ اتُّفق عليها تُحفظ كما كُتبت — ولا تسبق البداية
        $this->assertSame('2027-12-31', $this->issue(['term_starts_on' => '2027-01-01', 'term_ends_on' => '2027-12-31'])->term_ends_on->toDateString());
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), [
            'template_id' => $this->contract->id, 'business_id' => $this->shop->id, 'cycle' => 'yearly',
            'term_starts_on' => '2027-01-01', 'term_ends_on' => '2026-12-31',
        ])->assertSessionHasErrors('term_ends_on');

        // والسعرُ من الباقة لا من الكود: لا خصمٌ ولا تعديلٌ على الباقة
        $this->assertSame('29.900', number_format((float) $this->pro->fresh()->monthly_price, 3, '.', ''));
        $this->assertSame('299.000', number_format((float) $this->pro->fresh()->yearly_price, 3, '.', ''));
    }

    public function test_the_contract_text_names_the_three_cycles_and_payment_before_extension(): void
    {
        $terms = collect($this->contract->terms)->pluck('b')->implode(' ');
        $this->assertStringContainsString('شهرًا واحدًا للدورة الشهرية', $terms);
        $this->assertStringContainsString('ستة أشهر للدورة النصف سنوية', $terms);
        $this->assertStringContainsString('اثني عشر شهرًا للدورة السنوية', $terms);
        $this->assertStringContainsString('ولا تُمدَّد مدة الاشتراك إلا بعد سداد رسوم الدورة الجديدة', $terms);
    }

    /* ═══ التجديد: عقدٌ يتبع سابقَه، ولا يمسّ الاشتراك ═══ */

    public function test_a_renewal_follows_its_contract_and_signing_it_extends_nothing(): void
    {
        $first = $this->roundTrip('copy');

        $this->actingAs($this->super)->get(route('super-admin.documents.index', ['renew' => $first->id]))
            ->assertInertia(fn ($p) => $p->where('renew.number', $first->number)->where('renew.starts_on', '2027-10-24'));

        $renewal = $this->issue(['renews_document_id' => $first->id, 'business_id' => null, 'client' => ['phone' => '+96891234567']]);
        $this->assertSame($first->id, $renewal->renews_document_id);
        $this->assertSame($this->shop->id, (int) $renewal->client_business_id);
        $this->assertSame('2027-10-24', $renewal->term_starts_on->toDateString());
        $this->assertSame('2028-10-24', $renewal->term_ends_on->toDateString());
        $this->assertSame('1234567', $renewal->client['cr'], 'بيانُ العميل من العقد السابق');

        // تجديدٌ ثانٍ للعقد نفسه لا يُصدر وأوّلُه قائم
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), [
            'template_id' => $this->contract->id, 'renews_document_id' => $first->id, 'cycle' => 'yearly',
        ])->assertSessionHasErrors('document');
        $this->actingAs($this->super)->get(route('super-admin.documents.index', ['state' => 'renewal']))
            ->assertInertia(fn ($p) => $p->has('documents', 1)->where('documents.0.number', $renewal->number));

        // التوقيعُ لا يمدّد الاشتراك ولا يكتب فاتورة — التمديدُ عند السداد
        $before = [
            Subscription::count(), Invoice::count(),
            (array) DB::table('businesses')->where('id', $this->shop->id)->first(),
        ];
        $this->send($renewal)->assertOk();
        $this->assertSame(PlatformDocument::SIGNED, $renewal->fresh()->status);
        $this->assertSame($before, [
            Subscription::count(), Invoice::count(),
            (array) DB::table('businesses')->where('id', $this->shop->id)->first(),
        ]);

        $this->actingAs($this->super)->get(route('super-admin.documents.show', $renewal->id))
            ->assertInertia(fn ($p) => $p->where('document.renews.number', $first->number)
                ->where('document.subscription.ends_at', '2026-08-31')
                ->where('document.subscription.pending_invoice', null));
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $first->id))
            ->assertInertia(fn ($p) => $p->where('document.can_renew', false)->has('document.renewals', 1));
    }

    /* ═══ لا «تم» قبل الحفظ، والورقةُ لا تردّ توقيعًا ═══ */

    public function test_a_refused_or_closed_signing_never_says_it_succeeded(): void
    {
        $doc = $this->issue();
        $this->send($doc, ['client' => []])->assertStatus(422)->assertJsonMissing(['ok' => true]);
        $this->send($doc, ['agree' => ''])->assertStatus(422)->assertJsonMissing(['ok' => true]);
        $this->send($doc, ['signature' => 'data:image/png;base64,'.base64_encode('not a png')])->assertStatus(422);
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $doc->id))->assertSessionHasNoErrors();
        $this->send($doc)->assertStatus(422)->assertJson(['message' => __('هذا الرابط لم يعد صالحًا للتوقيع.')]);

        $late = $this->issue(['days' => 1]);
        $this->travel(3)->days();
        $this->send($late)->assertStatus(422);
        $this->assertSame('expired', $late->fresh()->state());

        $this->postJson('/sign/'.str_repeat('x', 32), ['agree' => '1'])->assertNotFound();
    }

    public function test_a_double_press_writes_one_signature(): void
    {
        $doc = $this->issue();

        $this->send($doc)->assertOk();
        $this->send($doc)->assertStatus(409);

        $this->assertSame(PlatformDocument::SIGNED, $doc->fresh()->status);
        $this->assertCount(1, collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_ends_with($f, '.png')));
        $this->assertSame(1, DB::table('activity_logs')->where('description', 'like', 'وقّع العميل المستند:%')->count());
    }

    public function test_a_paper_that_fails_to_save_does_not_undo_the_signature(): void
    {
        $doc = $this->issue();

        $broken = new class implements Driver
        {
            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                throw new RuntimeException('paper engine down');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                throw new RuntimeException('paper engine down');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 0.0;
            }
        };
        $was = Pdf::swap($broken);
        try {
            $this->send($doc)->assertOk()->assertJson(['ok' => true]);
        } finally {
            Pdf::swap($was);
        }

        $signed = $doc->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $signed->status);
        $this->assertNull($signed->pdf_path);

        // وأوّلُ طلبٍ للورقة يرسمها من البيانات الموقَّعة ويحفظها
        $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->assertOk();
        $this->assertNotNull($doc->fresh()->pdf_path);
        $this->assertTrue(PlatformDocuments::intact($doc->fresh()));
    }

    /* ═══ حمايةُ البيانات في الصفحة ═══ */

    public function test_the_signing_page_keeps_the_client_out_of_caches_crawlers_and_third_parties(): void
    {
        $doc = $this->issue();
        $page = $this->get(route('documents.sign', $doc->token));

        $page->assertOk()->assertSee('noindex, nofollow', false)->assertSee('<meta name="referrer" content="no-referrer">', false)
            ->assertDontSee('<script src', false)->assertDontSee('<link rel="stylesheet"', false)
            ->assertDontSee('googletagmanager')->assertDontSee('analytics');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        // الصورُ الوحيدةُ من المنصّة نفسها
        preg_match_all('/<img[^>]+src="([^"]+)"/', $page->getContent(), $m);
        foreach ($m[1] as $src) {
            $this->assertTrue(str_starts_with($src, url('/')) || str_starts_with($src, 'data:image/png'), $src);
        }
    }
}
