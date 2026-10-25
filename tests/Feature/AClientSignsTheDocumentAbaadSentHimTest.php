<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AbaadSubscriptionContract;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * قسم المستندات — مديرُ المنصّة يُصدر لعميله مستندَ خدمته، والعميلُ يوقّعه برابط.
 *
 * ما يُحرس هنا:
 *   - البابُ لمدير المنصّة وحده؛ والرابطُ وحده مفتاحُ العميل، بلا حساب.
 *   - المستندُ نسخةٌ ساعةَ إصداره: تعديلُ القالب أو سعرِ الباقة لا يغيّره.
 *   - التوقيعُ يكتمل ببياناته وإقراره ورسمه، ويُقفل مرّةً — ولا يُعاد.
 *   - الملغى والمنتهي لا يُوقَّعان ولا يعرضان بيانَ العميل، والورقةُ لا تُنزَّل قبل التوقيع.
 *   - نسخةُ الموقَّع على رابطها أيّامًا ثمّ يُغلق، ومديرُ المنصّة ينزّلها متى شاء.
 *   - الطرفُ الأوّل كما كان يومَ الإصدار، والبصمةُ تكشف ما عُدِّل في الصفّ بعد التوقيع.
 */
class AClientSignsTheDocumentAbaadSentHimTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private User $merchant;

    private Plan $pro;

    private Business $shop;

    private PlatformDocumentTemplate $contract;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

        $this->pro = Plan::create([
            'name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000,
        ]);
        $this->shop = Business::create([
            'name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '+96891234567',
            'city' => 'مسقط', 'status' => 'نشط', 'plan_id' => $this->pro->id,
        ]);
        $this->merchant = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'super@abaadapp.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        // القالبُ الأوّل تكتبه الهجرة نفسُها — عقدُ الاشتراك كما صُمِّم
        $this->contract = $this->subscriptionContract();
    }

    /** العميلُ يفتح الرابط — بلا حساب ولا رمز */
    private function asClient(): void
    {
        auth()->logout();
    }

    private function issue(array $data = []): PlatformDocument
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $this->contract->id,
            'business_id' => $this->shop->id,
            'cycle' => 'yearly',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function signature(int $w = 600, int $h = 200): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagesetthickness($img, 3);
        imageline($img, 20, 150, (int) ($w * 0.8), 40, imagecolorallocate($img, 15, 15, 15));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function sign(PlatformDocument $doc, array $over = [])
    {
        $this->asClient();

        $response = $this->post(route('documents.sign.submit', $doc->token), $over + [
            'client' => ['cr' => '1234567', 'civil' => '98765432'],
            'agree' => '1',
            'signature' => $this->signature(),
        ]);
        // متصفّحُ الموقِّع يحفظ كوكي نسخته ويعيده
        if ($cookie = $response->getCookie(PlatformDocument::COPY_COOKIE)) {
            $this->withCookie(PlatformDocument::COPY_COOKIE, $cookie->getValue());
        }

        return $response;
    }

    public function test_only_the_platform_reaches_the_documents_section(): void
    {
        $this->get(route('super-admin.documents.index'))->assertRedirect();

        $this->actingAs($this->merchant)->get(route('super-admin.documents.index'))->assertForbidden();
        $this->actingAs($this->merchant)->get(route('super-admin.documents.templates'))->assertForbidden();
        $this->actingAs($this->merchant)->post(route('super-admin.documents.store'), [
            'template_id' => $this->contract->id, 'business_id' => $this->shop->id,
        ])->assertForbidden();
        $this->assertSame(0, PlatformDocument::count());

        $this->actingAs($this->super)->get(route('super-admin.documents.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Platform/Documents/Index')
                ->has('templates', PlatformDocumentTemplate::all()->filter(fn ($t) => $t->issuable())->count()));
        $this->actingAs($this->super)->get(route('super-admin.documents.templates'))->assertOk();
    }

    public function test_a_contract_for_a_business_carries_its_data_its_plan_and_a_link(): void
    {
        $doc = $this->issue();

        $this->assertMatchesRegularExpression('/^AB-\d{6}-\d{4}$/', $doc->number);
        $this->assertSame(32, strlen($doc->token));
        $this->assertSame(PlatformDocument::SENT, $doc->status);
        $this->assertSame((int) $this->shop->id, (int) $doc->client_business_id);
        $this->assertSame(['name' => 'سالم الهنائي', 'biz' => 'زهور الريان', 'phone' => '+96891234567', 'addr' => 'مسقط'], array_diff_key($doc->client, ['branches' => 1]));
        $this->assertSame('299.000', $doc->plan['price']);
        $this->assertSame('سنوي', $doc->plan['cycle_label']);
        $this->assertSame('حتى 3 فروع · حتى 15 مستخدم · حتى 10,000 منتج', $doc->plan['limits']);
        $this->assertSame($this->contract->terms, $doc->terms);
        $this->assertTrue($doc->expires_at->between(now()->addDays(13), now()->addDays(15)));

        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Platform/Documents/Show')
                ->where('document.link', route('documents.sign', $doc->token))
                ->where('document.state', 'sent'));

        // والقائمةُ تجده باسم العميل، وبرقمه كيفما كُتبت حروفه — و«%» وحدها لا تُرجع كلَّ شيء
        $this->actingAs($this->super)->get(route('super-admin.documents.index', ['q' => 'الريان']))
            ->assertInertia(fn ($p) => $p->has('documents', 1));
        $this->get(route('super-admin.documents.index', ['q' => strtolower($doc->number)]))
            ->assertInertia(fn ($p) => $p->has('documents', 1)->where('filters.q', strtolower($doc->number)));
        $this->get(route('super-admin.documents.index', ['q' => '%']))
            ->assertInertia(fn ($p) => $p->where('filters.q', ''));
    }

    public function test_a_new_client_gets_a_service_document_with_its_amount(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.templates.store'), [
            'name' => 'تطبيق خاص', 'service' => 'تطبيق الجوال', 'title' => 'اتفاقية تطبيق خاص',
            'terms' => [['h' => 'التسليم:', 'b' => 'يُسلَّم التطبيق خلال المدة المتفق عليها.']],
            'includes_plan' => false, 'includes_amount' => true, 'active' => true,
        ])->assertSessionHasNoErrors();
        $app = PlatformDocumentTemplate::where('name', 'تطبيق خاص')->firstOrFail();
        // القالبُ الجديد مسوّدةٌ معطَّلة — يعتمده المدير ويفعّله قبل أن يُصدر منه
        $this->assertFalse($app->active);
        $this->completeProvider();
        $this->approveTemplate($app);

        // بلا اسم ولا نشاط لا مستند
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), [
            'template_id' => $app->id, 'amount' => 880,
        ])->assertSessionHasErrors('document');

        $doc = $this->issue([
            'template_id' => $app->id, 'business_id' => null, 'amount' => '880', 'amount_label' => 'تطبيق جوال خاص',
            'client' => ['name' => 'مريم', 'biz' => 'حلويات مريم', 'phone' => '+96899000000'],
        ]);

        $this->assertNull($doc->client_business_id);
        $this->assertNull($doc->plan);
        $this->assertSame('880.000', (string) $doc->amount);
        $this->assertSame('تطبيق جوال خاص', $doc->amount_label);
        $this->assertSame('مريم — حلويات مريم · +96899000000', $doc->client_label);
    }

    public function test_the_client_reads_completes_and_signs_once(): void
    {
        $doc = $this->issue();
        auth()->logout();

        // الرابطُ وحده يفتح العقدَ للقراءة والتوقيع — بلا رمز
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('عقد اشتراك')->assertSee('الباقة الاحترافية')->assertSee('299.000')
            ->assertSee('noindex, nofollow', false)->assertSee('id="sign-form"', false)->assertSee('إرسال التوقيع');
        $this->assertNotNull($doc->fresh()->viewed_at);

        // بلا توقيع، وبلا إقرار، وبلا السجلّ والرقم المدنيّ — لا يُقفل
        $this->sign($doc, ['signature' => ''])->assertSessionHasErrors('signature');
        $this->sign($doc, ['agree' => ''])->assertSessionHasErrors('agree');
        $this->sign($doc, ['client' => []])->assertSessionHasErrors('sign');
        $this->sign($doc, ['signature' => 'data:image/png;base64,'.base64_encode('not a png')])->assertSessionHasErrors('sign');
        $this->sign($doc, ['signature' => $this->signature(10, 10)])->assertSessionHasErrors('sign');
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->sign($doc, ['client' => ['cr' => '1234567', 'civil' => '98765432', 'email' => 'salem@rayan.om']])
            ->assertRedirect(route('documents.sign', $doc->token))->assertSessionHasNoErrors();

        $signed = $doc->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $signed->status);
        $this->assertSame('1234567', $signed->client['cr']);
        $this->assertSame('salem@rayan.om', $signed->client['email']);
        $this->assertSame('سالم الهنائي', $signed->client['name'], 'ما عبّأه المدير باقٍ');
        $this->assertSame('127.0.0.1', $signed->signer_ip);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $signed->content_hash);
        $this->assertTrue(PlatformDocuments::intact($signed));
        Storage::disk('local')->assertExists($signed->signature_path);

        // توقيعٌ ثانٍ يُردّ، ولا يمسّ الأوّل
        $this->sign($doc, ['client' => ['cr' => '0000000', 'civil' => '0000000']])->assertSessionHasErrors('sign');
        $this->assertSame('1234567', $doc->fresh()->client['cr']);
        $this->assertSame($signed->signed_at->toDateTimeString(), $doc->fresh()->signed_at->toDateTimeString());
        // صورةُ توقيعٍ واحدة، وورقةٌ موقَّعةٌ محفوظةٌ واحدة
        $files = collect(Storage::disk('local')->allFiles());
        $this->assertCount(1, $files->filter(fn ($f) => str_ends_with($f, '.png')));
        $this->assertCount(1, $files->filter(fn ($f) => str_ends_with($f, '.pdf')));

        $this->get(route('documents.sign', $doc->token))->assertOk()->assertSee('تم توقيع المستند')->assertDontSee('id="sig"', false);
    }

    public function test_what_was_signed_does_not_follow_the_template_or_the_price(): void
    {
        $doc = $this->issue();

        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $this->contract->id), [
            'name' => 'عقد اشتراك أبعاد', 'title' => 'عقد اشتراك', 'service' => 'اشتراك النظام',
            'terms' => [['h' => 'جديد:', 'b' => 'بندٌ كُتب بعد الإرسال.']],
            'includes_plan' => true, 'includes_amount' => false, 'active' => true,
        ])->assertSessionHasNoErrors();
        $this->pro->update(['yearly_price' => 399]);

        $fresh = $doc->fresh();
        $this->assertSame($this->contract->terms, $fresh->terms);
        $this->assertSame('299.000', $fresh->plan['price']);

        $this->asClient();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('موضوع العقد:')->assertDontSee('بندٌ كُتب بعد الإرسال.')->assertSee('299.000');
    }

    public function test_a_cancelled_or_expired_link_says_so_and_shows_nothing_of_the_client(): void
    {
        $cancelled = $this->issue();
        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $cancelled->id), ['reason' => 'أُرسل بالخطأ'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PlatformDocument::CANCELLED, $cancelled->fresh()->status);

        // رابطٌ أُلغي لأنّه ذهب إلى رقمٍ خاطئ لا يبقى يعرض اسم المالك وهاتفه
        $this->sign($cancelled)->assertSessionHasErrors('sign');
        $this->get(route('documents.sign', $cancelled->token))->assertOk()->assertSee('أُلغي هذا المستند')
            ->assertDontSee('سالم الهنائي')->assertDontSee('+96891234567')->assertDontSee('299.000')
            ->assertDontSee('id="sign-form"', false);

        $late = $this->issue(['days' => 3]);
        $this->travel(4)->days();
        $this->get(route('documents.sign', $late->token))->assertOk()->assertSee('انتهت صلاحية هذا الرابط')
            ->assertDontSee('سالم الهنائي')->assertDontSee('+96891234567')->assertDontSee('id="sign-form"', false);
        $this->sign($late)->assertSessionHasErrors('sign');
        $this->assertSame('expired', $late->fresh()->state());

        // والموقَّعُ لا يُلغى
        $this->travelBack();
        $done = $this->issue();
        $this->sign($done)->assertSessionHasNoErrors();
        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $done->id))->assertSessionHasErrors('document');
        $this->assertSame(PlatformDocument::SIGNED, $done->fresh()->status);
    }

    public function test_the_paper_downloads_and_the_public_one_only_after_signing(): void
    {
        $doc = $this->issue();

        $admin = $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id));
        $admin->assertOk();
        $this->assertSame('application/pdf', $admin->headers->get('Content-Type'));

        auth()->logout();
        $this->get(route('documents.sign.pdf', $doc->token))->assertNotFound();

        $this->sign($doc)->assertSessionHasNoErrors();
        $pdf = $this->get(route('documents.sign.pdf', $doc->token));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $text = implode(' ', array_column(PdfText::pages($pdf->getContent()), 'text'));
        $this->assertStringContainsString($doc->number, $text);
        $this->assertStringContainsString((string) $doc->fresh()->content_hash, $text);
        $this->assertStringContainsString('1234567', $text);
    }

    public function test_the_signed_copy_stays_on_its_link_for_its_days_and_then_the_link_closes(): void
    {
        $doc = $this->issue();
        $this->sign($doc)->assertSessionHasNoErrors();

        // العميلُ يرى نسخته وينزّلها — ولا تبقى في ذاكرة متصفّحٍ مشترك
        $page = $this->get(route('documents.sign', $doc->token));
        $page->assertOk()->assertSee('98765432')->assertSee('data:image/png', false);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $paper = $this->get(route('documents.sign.pdf', $doc->token));
        $paper->assertOk();
        $this->assertStringContainsString('no-store', (string) $paper->headers->get('Cache-Control'));

        $this->travel(PlatformDocument::COPY_DAYS - 1)->days();
        $this->get(route('documents.sign.pdf', $doc->token))->assertOk();

        // وبعد أيّامها رابطٌ في محادثةٍ قديمة لا يفتح رقمَه المدنيّ وتوقيعه
        $this->travel(2)->days();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('انتهت مدة عرض نسخته')
            ->assertDontSee('98765432')->assertDontSee('سالم الهنائي')->assertDontSee('data:image/png', false);
        $this->get(route('documents.sign.pdf', $doc->token))->assertNotFound();

        // ومديرُ المنصّة ينزّلها متى شاء ويرسلها لعميله
        $admin = $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id));
        $admin->assertOk();
        $this->assertStringContainsString('98765432', implode(' ', array_column(PdfText::pages($admin->getContent()), 'text')));
        $this->travelBack();
    }

    public function test_the_signed_paper_keeps_abaad_as_it_was_on_the_day(): void
    {
        // الطرفُ الأوّل باسمه القانونيّ وسجلّه من «مقدّم الخدمة في العقود» — لا من «اسم الشركة المالكة»
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_name'], ['value' => 'Abaad Old LLC']);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_cr'], ['value' => 'OLD-CR-1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'company'], ['value' => 'Company Field LLC']);
        $doc = $this->issue();
        $this->sign($doc)->assertSessionHasNoErrors();

        Setting::whereNull('business_id')->where('key', 'legal_name')->update(['value' => 'Abaad New LLC']);
        Setting::whereNull('business_id')->where('key', 'legal_cr')->update(['value' => 'NEW-CR-2']);

        $this->get(route('documents.sign', $doc->token))->assertSee('Abaad Old LLC')->assertSee('OLD-CR-1')
            ->assertDontSee('Abaad New LLC')->assertDontSee('NEW-CR-2')->assertDontSee('Company Field LLC');
        $text = implode(' ', array_column(PdfText::pages($this->get(route('documents.sign.pdf', $doc->token))->getContent()), 'text'));
        $this->assertStringContainsString('Abaad Old LLC', $text);
        $this->assertStringContainsString('OLD-CR-1', $text);
        $this->assertStringNotContainsString('Abaad New LLC', $text);
        $this->assertStringNotContainsString('NEW-CR-2', $text);
        $this->assertTrue(PlatformDocuments::intact($doc->fresh()));
    }

    public function test_an_unknown_link_says_nothing(): void
    {
        $this->issue();
        auth()->logout();

        $this->get('/sign/'.Str::random(32))->assertNotFound();
        $this->post('/sign/'.Str::random(32), ['agree' => '1', 'signature' => $this->signature()])->assertNotFound();
        $this->get('/sign/short')->assertNotFound();
    }

    public function test_a_change_after_signing_breaks_the_fingerprint(): void
    {
        $doc = $this->issue();
        $this->sign($doc)->assertSessionHasNoErrors();
        $this->assertTrue(PlatformDocuments::intact($doc->fresh()));

        // كلُّ ما تطبعه الورقة داخلٌ في البصمة — الطرفُ الأوّل وجهازُ الموقِّع كبيان العميل
        $row = DB::table('platform_documents')->where('id', $doc->id)->first();
        $provider = json_decode($row->provider, true);
        $provider['name'] = 'Someone Else';
        foreach (['provider' => json_encode($provider), 'signer_ip' => '198.51.100.7', 'title_en' => 'Other'] as $column => $forged) {
            DB::table('platform_documents')->where('id', $doc->id)->update([$column => $forged]);
            $this->assertFalse(PlatformDocuments::intact($doc->fresh()), $column);
            DB::table('platform_documents')->where('id', $doc->id)->update([$column => $row->{$column}]);
            $this->assertTrue(PlatformDocuments::intact($doc->fresh()), $column.' restored');
        }

        $client = $doc->fresh()->client;
        $client['civil'] = '11111111';
        DB::table('platform_documents')->where('id', $doc->id)->update(['client' => json_encode($client)]);

        $this->assertFalse(PlatformDocuments::intact($doc->fresh()));
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))
            ->assertInertia(fn ($p) => $p->where('document.intact', false));
    }
}
