<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\User;
use App\Support\AlreadySigned;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AbaadSubscriptionContract;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * الاتفاقيةُ الموقَّعة لا تتغيّر دون أن يُكشف ذلك — ولا تُوقَّع إلّا مرّةً بما يحقّ لصاحبها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الملغى والمنتهي لا يُوقَّعان، والموقَّعُ لا يُوقَّع ثانيةً — ولو وصل طلبان معًا.
 *   - انقطاعٌ في منتصف الحفظ لا يترك نصفَ توقيع: يبقى المستندُ بانتظار التوقيع، ويُعاد.
 *   - العميلُ لا يكتب حقلَ فريقٍ ولا قسمَ «بعد التنفيذ» ولا هاتفَه، والإلزاميُّ يُفحص في
 *     الخادم ولو أرسل المتصفّحُ غيرَ نصّ. ولا بابَ يعدّل مستندًا بعد إصداره.
 *   - البصمةُ تشمل النصَّ والحقولَ والإجاباتِ والإقرارَ ومقدّمَ الخدمة والمبلغَ وصورةَ
 *     التوقيع: تغييرُ أيٍّ منها في القاعدة يكسرها.
 *   - وطريقةُ حسابها مثبّتةٌ بأرقامٍ حرفيّة: تحديثٌ يغيّرها يكسر كلَّ توقيعٍ قديم، فيسقط هنا أوّلًا.
 *   - ولا تدّعي الصفحةُ ولا الورقةُ تحقّقًا من هويّة الموقِّع.
 *   - والتاجرُ لا يفتح مستنداتِ المنصّة، ولا رابطٌ يفتح غيرَ مستنده.
 */
class ASignedAgreementCannotBeAlteredUnseenTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    private const CLIENT = [
        'org' => 'مؤسسة زهور الريان', 'person' => 'سالم الهنائي', 'phone' => '+96891234567',
        'email' => 'salem@rayan.om', 'store' => 'زهور الريان', 'wa' => '+96891234567', 'biz' => 'زهور الريان',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        $this->travelTo('2026-10-25 10:00:00');

        $plan = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000]);
        $this->shop = Business::create(['name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'status' => 'نشط', 'plan_id' => $plan->id]);
        $this->super = User::create(['name' => 'مدير المنصة', 'email' => 'super@abaadapp.test', 'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط']);
        DocumentForms::seedTemplates();
        $this->completeProvider();
    }

    private function issue(): PlatformDocument
    {
        $t = PlatformDocumentTemplate::where('name', DocumentForms::seed('whatsapp-integration')['name'])->firstOrFail();
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $this->confirmed([
            'template_id' => $t->id, 'business_id' => $this->shop->id, 'team' => ['itype' => 'WhatsApp Cloud API'],
        ]))->assertSessionHasNoErrors();
        auth()->logout();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function signature(int $seed = 40): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 20, 150, 480, $seed, imagecolorallocate($img, 15, 15, 15));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function send(PlatformDocument $doc, array $answers = self::CLIENT, int $seed = 40)
    {
        return $this->postJson(route('documents.sign.submit', $doc->token), ['agree' => '1', 'signature' => $this->signature($seed), 'answers' => $answers]);
    }

    public function test_a_cancelled_or_expired_agreement_is_not_signed(): void
    {
        $cancelled = $this->issue();
        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $cancelled->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->send($cancelled)->assertStatus(422)->assertJsonFragment(['message' => 'هذا الرابط لم يعد صالحًا للتوقيع.']);

        $expired = $this->issue();
        $this->travel(15)->days();
        $this->send($expired)->assertStatus(422);

        foreach ([$cancelled, $expired] as $doc) {
            $doc = $doc->fresh();
            $this->assertNotSame(PlatformDocument::SIGNED, $doc->status);
            $this->assertNull($doc->answers);
            $this->assertNull($doc->signature_path);
        }
        $this->assertSame([], Storage::disk('local')->allFiles(), 'لا صورةَ توقيعٍ تُحفظ لمستندٍ لا يُوقَّع');
    }

    public function test_a_second_submission_or_a_racing_one_does_not_sign_again(): void
    {
        $doc = $this->issue();
        $stale = $doc->fresh();
        $this->send($doc)->assertOk();
        $signed = $doc->fresh();

        // الضغطةُ الثانية — أو ردٌّ ضاع فأُعيد الإرسال — تُردّ وتُحيل إلى النسخة الموقَّعة
        $this->send($doc, ['wa' => '+96800000000'] + self::CLIENT, 90)->assertStatus(409)->assertJsonStructure(['redirect']);

        // وطلبٌ سبقه الآخرُ إلى القفل يحمل نسخةً قديمة تقول «بانتظار التوقيع» — فيقرأ الصفَّ تحت القفل
        $this->assertSame(PlatformDocument::SENT, $stale->status);
        try {
            PlatformDocuments::sign($stale, [], $this->signature(90), '10.0.0.9', 'racer', null, ['wa' => '+96800000000'] + self::CLIENT);
            $this->fail('وُقِّع المستندُ مرّتين');
        } catch (AlreadySigned) {
        }

        $after = $doc->fresh();
        $this->assertSame($signed->content_hash, $after->content_hash);
        $this->assertSame('+96891234567', $after->answers['wa']);
        $this->assertSame($signed->signature_path, $after->signature_path);
        $this->assertCount(1, array_filter(Storage::disk('local')->files('documents/'.$doc->id), fn ($f) => str_contains($f, '/signature-')), 'صورةُ توقيعٍ واحدة');
        $this->assertTrue(PlatformDocuments::intact($after));
    }

    public function test_a_failure_halfway_leaves_no_half_signature_and_the_client_can_retry(): void
    {
        $doc = $this->issue();
        $disk = Storage::disk('local');
        // القرصُ يمتلئ: `put` تقول `false` ولا ترمي — كما يفعل قرصُ Laravel بضبطه
        $broken = Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('put')->andReturn(false);
        Storage::set('local', $broken);
        $this->send($doc)->assertStatus(422)->assertJsonFragment(['message' => 'تعذّر حفظ التوقيع الآن — أعد الإرسال بعد قليل.']);

        // وانقطاعٌ يرمي في منتصف المعاملة لا يُبقي منها شيئًا
        $broken = Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('put')->andThrow(new \LogicException('connection lost'));
        Storage::set('local', $broken);
        $this->send($doc)->assertStatus(500);
        Storage::set('local', $disk);

        $half = $doc->fresh();
        $this->assertSame(PlatformDocument::SENT, $half->status);
        $this->assertNull($half->answers);
        $this->assertNull($half->signed_at);
        $this->assertNull($half->content_hash);

        $this->send($doc)->assertOk();
        $this->assertSame(PlatformDocument::SIGNED, $doc->fresh()->status);
        $this->assertTrue(PlatformDocuments::intact($doc->fresh()));
    }

    public function test_the_client_writes_only_his_own_fields_and_the_server_checks_the_required_ones(): void
    {
        $doc = $this->issue();

        // حقلٌ إلزاميّ يصل قائمةً لا نصًّا — لا يُقبل ولا يُسقط الطلب
        $this->send($doc, ['wa' => ['+96891234567']] + self::CLIENT)->assertStatus(422)->assertJsonFragment(['message' => 'أكمل: رقم واتساب المطلوب ربطه مع رمز الدولة']);
        $this->send($doc, ['wa' => '   '] + self::CLIENT)->assertStatus(422);

        $this->postJson(route('documents.sign.submit', $doc->token), [
            'agree' => '1', 'signature' => $this->signature(),
            'answers' => self::CLIENT + ['itype' => 'تزوير', 'rcvName' => 'قبل الأوان', 'tplState' => 'منجز'],
            'team' => ['itype' => 'تزوير'],
            'client' => ['phone' => '+96800000000', 'name' => 'آخر'],
        ])->assertOk();

        $signed = $doc->fresh();
        $this->assertSame(['itype' => 'WhatsApp Cloud API'], $signed->team, 'حقولُ الفريق كما أصدرها');
        $this->assertArrayNotHasKey('itype', $signed->answers);
        $this->assertArrayNotHasKey('tplState', $signed->answers, 'حقلُ فريقٍ في قسم التسليم');
        $this->assertSame('96891234567', $signed->client_phone, 'الهاتفُ يعتمده المدير لا الموقِّع');
        $this->assertSame($doc->client, $signed->client, 'بيانُ النموذج في حقوله — والعامُّ كما أصدره المدير');
    }

    public function test_no_route_rewrites_an_issued_document_and_a_signed_one_is_not_cancelled(): void
    {
        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'super-admin.documents.') && ! str_contains((string) $r->getName(), 'templates'))
            ->reject(fn ($r) => $r->methods() === ['GET', 'HEAD'])
            ->map(fn ($r) => $r->getName())->sort()->values()->all();
        $this->assertSame(['super-admin.documents.cancel', 'super-admin.documents.shared', 'super-admin.documents.store'], $writes,
            'بابٌ جديد يكتب في مستندٍ صدر — والموقَّعُ لا يُعدَّل');

        $doc = $this->issue();
        $this->send($doc)->assertOk();
        $hash = $doc->fresh()->content_hash;
        $this->actingAs($this->super)->post(route('super-admin.documents.cancel', $doc->id))->assertSessionHasErrors('document');
        $this->actingAs($this->super)->post(route('super-admin.documents.shared', $doc->id), ['via' => 'copy']);
        $this->assertSame(PlatformDocument::SIGNED, $doc->fresh()->status);
        $this->assertSame($hash, $doc->fresh()->content_hash);
        $this->assertTrue(PlatformDocuments::intact($doc->fresh()), 'تسجيلُ المشاركة خارج ما وُقِّع');
    }

    public function test_any_change_to_what_was_signed_is_caught(): void
    {
        $doc = $this->issue();
        $this->send($doc, self::CLIENT + ['c2_0' => [1]])->assertOk();
        $signed = $doc->fresh();
        $this->assertTrue(PlatformDocuments::intact($signed));

        $row = (array) DB::table('platform_documents')->where('id', $doc->id)->first();
        $form = $signed->form;
        $form['sections'][1]['parts'][0]['text'] = 'نصٌّ غير الذي وُقِّع';
        $tamper = [
            'النصّ' => ['form' => json_encode($form, JSON_UNESCAPED_UNICODE)],
            'حقول الفريق' => ['team' => json_encode(['itype' => 'غيره'], JSON_UNESCAPED_UNICODE)],
            'إجابات العميل' => ['answers' => json_encode(['c2_0' => [0, 1]] + $signed->answers, JSON_UNESCAPED_UNICODE)],
            'نصّ الإقرار' => ['consent_text' => 'أقرّ بغير ذلك'],
            'مقدّم الخدمة' => ['provider' => json_encode(['cr' => 'OTHER'] + $signed->provider, JSON_UNESCAPED_UNICODE)],
            'المبلغ' => ['amount' => '1.000'],
            'بيان العميل' => ['client' => json_encode(['biz' => 'غيره'] + $signed->client, JSON_UNESCAPED_UNICODE)],
            'وقت التوقيع' => ['signed_at' => '2026-10-24 09:00:00'],
            'جهاز الموقِّع' => ['signer_ip' => '10.9.9.9'],
        ];
        foreach ($tamper as $what => $change) {
            DB::table('platform_documents')->where('id', $doc->id)->update($change);
            $this->assertFalse(PlatformDocuments::intact($doc->fresh()), 'تغيّر «'.$what.'» بعد التوقيع ولم يُكشف');
            DB::table('platform_documents')->where('id', $doc->id)->update(array_intersect_key($row, $change));
            $this->assertTrue(PlatformDocuments::intact($doc->fresh()), 'أُعيد «'.$what.'» كما كان');
        }

        // وصورةُ التوقيع تُستبدل في القرص
        Storage::disk('local')->put($signed->signature_path, base64_decode(substr($this->signature(120), 22)));
        $this->assertFalse(PlatformDocuments::intact($doc->fresh()), 'استُبدلت صورةُ التوقيع ولم يُكشف');

        // والمديرُ يرى ذلك في صفحة المستند
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))
            ->assertInertia(fn ($p) => $p->where('document.intact', false));
    }

    /**
     * طريقةُ البصمة بأرقامٍ حرفيّة — لمستند بنود ولمستند نموذج.
     *
     * كلُّ توقيعٍ قديم مختومٌ بها. فإن غيّر تحديثٌ ترتيبَ ما يُبصم أو أضاف إليه
     * مفتاحًا لكلّ المستندات، صارت العقودُ الموقَّعة كلُّها «تغيّرت بعد توقيعها».
     * والإضافةُ الصحيحة تقع لمستنداتٍ جديدةٍ وحدها (كما وقع `form`).
     */
    public function test_the_fingerprint_formula_does_not_drift(): void
    {
        $base = [
            'number' => 'AB-202610-0001', 'renews_document_id' => null,
            'provider' => ['name' => 'مشاريع الذهلي الجديدة', 'email' => '', 'phone' => '', 'website' => ''],
            'service' => 'اشتراك النظام', 'title' => 'عقد اشتراك', 'title_en' => 'Subscription Agreement',
            'terms' => [['h' => 'المدة:', 'b' => 'سنة.']],
            'plan' => ['name' => 'الباقة', 'cycle' => 'yearly', 'price' => '299.000'],
            'term_starts_on' => '2026-11-01', 'term_ends_on' => '2027-11-01',
            'amount' => null, 'amount_label' => null,
            'client' => ['name' => 'سالم', 'biz' => 'زهور', 'civil' => '98765432'],
            'consent_text' => PlatformDocuments::CONSENT,
            'signer_ip' => '127.0.0.1', 'signer_agent' => 'Mozilla/5.0',
        ];
        $doc = (new PlatformDocument)->forceFill($base);
        $doc->created_at = Carbon::parse('2026-10-20 08:00:00');
        $doc->agreed_at = Carbon::parse('2026-10-21 09:30:00');
        $doc->signed_at = Carbon::parse('2026-10-21 09:30:00');

        $this->assertSame('0af4f517f5a73063c49dcde936470c56196f9c6855d8650be863bf9892a5ae52', PlatformDocuments::hash($doc, 'PNG-BYTES'), 'بصمةُ مستندات البنود تغيّرت');

        $doc->forceFill([
            'terms' => [], 'amount' => '25.000', 'amount_label' => 'رسوم الربط',
            'form' => ['intro' => null, 'highlights' => [], 'sections' => [['title' => 'أ', 'after' => false, 'parts' => [['kind' => 'p', 'text' => 'نص']]]], 'consent' => 'أقر', 'agree' => 'أوافق', 'footer' => null],
            'team' => ['itype' => 'Cloud API'], 'answers' => ['wa' => '+96891234567', 'c0' => [1]],
        ]);
        $this->assertSame('841f7813376460b9e5ebc745027eb948f13a2016675a0f1640acb3eaa9bab90a', PlatformDocuments::hash($doc, 'PNG-BYTES'), 'بصمةُ مستندات النماذج تغيّرت');
    }

    public function test_nothing_claims_the_signers_identity_was_verified(): void
    {
        $doc = $this->issue();
        $page = $this->get(route('documents.sign', $doc->token))->getContent();
        $this->send($doc)->assertOk();
        $paper = (string) $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->getContent();
        $text = implode(' ', array_column(PdfText::pages($paper), 'text'));

        // «تم التحقق من النشاط التجاري في Meta» بندٌ عن حساب العميل لدى Meta — لا عن هويّته هنا
        foreach (['التحقق من هوية', 'التحقق من الهوية', 'هوية موثقة', 'هويته موثقة', 'verified identity', 'identity verified'] as $claim) {
            $this->assertStringNotContainsString($claim, $page);
            $this->assertStringNotContainsString($claim, json_encode($doc->fresh()->form, JSON_UNESCAPED_UNICODE));
        }
        $this->assertNotSame('', $text);
    }

    public function test_a_merchant_never_reaches_abaads_documents_and_a_link_opens_its_own_only(): void
    {
        $a = $this->issue();
        $b = $this->issue();
        $merchant = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);

        // ولو كان المستندُ لنشاطه هو: عقودُ المنصّة تُدار من المنصّة
        foreach (['super-admin.documents.show', 'super-admin.documents.pdf'] as $name) {
            $this->actingAs($merchant)->get(route($name, $a->id))->assertForbidden();
        }
        $this->actingAs($merchant)->post(route('super-admin.documents.cancel', $a->id))->assertForbidden();
        auth()->logout();

        $this->get(route('documents.sign', $a->token))->assertSee($a->number)->assertDontSee($b->number);
        $this->send($a)->assertOk();
        $this->assertSame(PlatformDocument::SENT, $b->fresh()->status, 'توقيعُ رابطٍ لا يمسّ غيرَ مستنده');
        // ونسخةُ الموقِّع لرابطه وحده: كوكيه على مسار رابطه، فلا يفتح ورقةَ غيره
        $this->get(route('documents.sign.pdf', $b->token))->assertNotFound();
    }
}
