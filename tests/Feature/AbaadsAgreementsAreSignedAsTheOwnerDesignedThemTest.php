<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\User;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\AbaadSubscriptionContract;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * اتفاقياتُ أبعاد كما صمّمها المالك — اتفاقيةُ الاشتراك وطلباتُ الخدمات الأربع.
 *
 * كلُّ اتفاقيةٍ نموذجٌ بحقوله وقوائمه وجداوله (`DocumentForms`): يعبّئ الفريقُ
 * حقولَه عند الإصدار، والعميلُ حقولَه عند التوقيع، ولا يكتب أحدٌ في حقل غيره،
 * ولا في قسم «بعد التنفيذ». وما كُتب داخلُ البصمة، وورقتُه تحمله.
 *
 * ومستنداتُ البنود التي صدرت قبل النماذج تبقى بصمتُها كما وُقِّعت.
 */
class AbaadsAgreementsAreSignedAsTheOwnerDesignedThemTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private Plan $pro;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        $this->travelTo('2026-10-25 10:00:00');

        $this->pro = Plan::create([
            'name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000,
        ]);
        $this->shop = Business::create([
            'name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'city' => 'مسقط',
            'status' => 'نشط', 'plan_id' => $this->pro->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31',
        ]);
        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'super@abaadapp.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        // اختبارُ تفريغٍ سابقٌ في قسم PostgreSQL يمحو ما زرعته الهجرة — فتُزرع من جديد
        DocumentForms::seedTemplates();
    }

    private function template(string $slug): PlatformDocumentTemplate
    {
        return PlatformDocumentTemplate::where('name', DocumentForms::seed($slug)['name'])->firstOrFail();
    }

    private function issue(string $slug, array $data = []): PlatformDocument
    {
        $template = $this->template($slug);
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $template->id, 'business_id' => $this->shop->id,
            'amount' => $template->default_amount, 'cycle' => 'yearly',
        ])->assertSessionHasNoErrors()->assertRedirect();
        auth()->logout();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function signature(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagesetthickness($img, 3);
        imageline($img, 20, 150, 480, 40, imagecolorallocate($img, 15, 15, 15));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function send(PlatformDocument $doc, array $body)
    {
        return $this->postJson(route('documents.sign.submit', $doc->token), $body + ['agree' => '1', 'signature' => $this->signature()]);
    }

    /** ما يكمله عميلُ واتساب من حقوله الإلزاميّة */
    private const WHATSAPP_CLIENT = [
        'org' => 'مؤسسة زهور الريان', 'person' => 'سالم الهنائي', 'phone' => '+96891234567',
        'email' => 'salem@rayan.om', 'store' => 'زهور الريان', 'wa' => '+96891234567', 'biz' => 'زهور الريان',
    ];

    public function test_the_owners_five_agreements_are_ready_and_the_short_contract_steps_aside(): void
    {
        $short = $this->subscriptionContract();
        DocumentForms::seedTemplates();

        foreach (DocumentForms::SEEDED as $slug) {
            $seed = DocumentForms::seed($slug);
            $t = $this->template($slug);
            $this->assertTrue($t->active, $slug);
            $this->assertSame($seed['form'], $t->form, 'النموذج كما خرج من التصميم: '.$slug);
            $this->assertSame([], $t->terms);
        }
        $this->assertFalse($short->fresh()->active, 'العقدُ القصير يُعطَّل للإصدار ولا يُحذف');
        $this->assertSame('25.000', (string) $this->template('whatsapp-integration')->default_amount);
        $this->assertSame('15.000', (string) $this->template('store-setup')->default_amount);
        $this->assertSame('12.000', (string) $this->template('payment-integration')->default_amount);
        $this->assertTrue($this->template('subscription-agreement')->includes_plan);

        // نصٌّ من اتفاقية المالك نفسها، والطرفُ الأوّل بكيانه — ولا يدّعي تحقّقًا من الهويّة
        $sub = $this->template('subscription-agreement')->form;
        $this->assertSame('الخدمة والاشتراك', $sub['sections'][0]['title']);
        $this->assertStringContainsString('مشاريع الذهلي الجديدة', json_encode($sub, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('التحقق من هوية', json_encode($sub, JSON_UNESCAPED_UNICODE));

        // والزرعُ ثانيةً لا يكرّر ولا يمسّ ما عدّله المدير
        $this->template('store-setup')->update(['title' => 'عنوانٌ عدّله المدير']);
        DocumentForms::seedTemplates();
        $this->assertSame(1, PlatformDocumentTemplate::where('name', DocumentForms::seed('store-setup')['name'])->count());
        $this->assertSame('عنوانٌ عدّله المدير', $this->template('store-setup')->title);
    }

    public function test_an_agreement_is_issued_with_its_fee_and_the_teams_fields_only(): void
    {
        $doc = $this->issue('whatsapp-integration', ['team' => [
            'itype' => 'WhatsApp Cloud API', 'eta' => '2026-11-01', 'repName' => 'مدير المنصة',
            'wa' => '+968 0000', 'nothing' => 'x', 'tpl4' => [[1, 'x', 'y']],
        ]]);

        $this->assertSame($this->template('whatsapp-integration')->form, $doc->form);
        $this->assertSame(['itype' => 'WhatsApp Cloud API', 'eta' => '2026-11-01', 'repName' => 'مدير المنصة'], $doc->team,
            'الفريقُ يكتب حقولَه وحدها: لا حقلَ عميل، ولا مفتاحَ لا يعرفه النموذج');
        $this->assertSame('25.000', (string) $doc->amount);
        $this->assertSame('رسوم الربط (مرة واحدة)', $doc->amount_label);
        $this->assertSame([], $doc->terms);
        $this->assertNull($doc->answers);
    }

    public function test_the_client_page_shows_the_agreement_its_inputs_and_the_teams_values_read_only(): void
    {
        $doc = $this->issue('whatsapp-integration', ['team' => ['itype' => 'WhatsApp Cloud API']]);

        $page = $this->get(route('documents.sign', $doc->token))->assertOk();
        $page->assertSee('نموذج طلب واتفاقية خدمة ربط واتساب برقم المتجر الخاص')
            ->assertSee('متطلبات العميل')->assertSee('قوالب رسائل واتساب')->assertSee('اعتماد ممثل أبعاد')
            ->assertSee('25.000 ر.ع تُدفع مرة واحدة')
            ->assertSee('name="answers[wa]"', false)->assertSee('name="answers[c2_0][]"', false)
            ->assertSee('name="answers[tpl4][0][0]"', false)->assertSee('name="answers[y7]"', false)
            ->assertSee('WhatsApp Cloud API')
            ->assertDontSee('name="answers[itype]"', false)
            // بيانُ العميل في حقول النموذج — لا يُسأل عن رقمه المدنيّ مرّتين
            ->assertDontSee('name="client[civil]"', false)
            ->assertSee('أوافق على شروط هذا النموذج.')->assertSee('id="consent-lead"', false);

        // ما يُعرف عنه يُقترح له ويعدّله
        $page->assertSee('name="answers[org]" value="زهور الريان"', false);
    }

    public function test_signing_needs_the_required_fields_and_keeps_only_the_clients_answers(): void
    {
        $doc = $this->issue('whatsapp-integration', ['team' => ['itype' => 'WhatsApp Cloud API']]);

        $this->send($doc, ['answers' => array_diff_key(self::WHATSAPP_CLIENT, ['wa' => 1])])
            ->assertStatus(422)->assertJsonFragment(['message' => 'أكمل: رقم واتساب المطلوب ربطه مع رمز الدولة']);
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);

        $this->send($doc, ['answers' => self::WHATSAPP_CLIENT + [
            'c2_0' => ['0', '2', '99', 'x'],
            'tpl4' => [['1', 'تم استلام طلبك', ''], ['', '', ''], ['on', 'فاتورتك', 'z']],
            'y7' => 'yes',
            'itype' => 'تزوير', 'repName' => 'أنا', 'nothing' => 'x',
        ]])->assertOk()->assertJson(['ok' => true]);

        $signed = $doc->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $signed->status);
        $answers = $signed->answers;
        $this->assertSame('+96891234567', $answers['wa']);
        $this->assertSame([0, 2], $answers['c2_0'], 'بنودُ القائمة وحدها، بلا تكرارٍ ولا غريب');
        $this->assertSame('yes', $answers['y7']);
        $this->assertSame(['1', 'تم استلام طلبك', ''], $answers['tpl4'][0]);
        $this->assertSame(['1', 'فاتورتك', 'z'], $answers['tpl4'][2]);
        $this->assertCount(5, $answers['tpl4']);
        $this->assertArrayNotHasKey('itype', $answers, 'العميلُ لا يكتب حقلَ الفريق');
        $this->assertArrayNotHasKey('repName', $answers);
        $this->assertSame(['itype' => 'WhatsApp Cloud API'], $signed->team);

        // الإقرارُ نصُّ هذه الاتفاقية وخانتُها، والعميلُ كما أصدره المدير
        $form = $signed->form;
        $this->assertSame($form['consent']."\n".$form['agree'], $signed->consent_text);
        $this->assertSame('زهور الريان', $signed->client['biz']);

        // ما كُتب داخلُ البصمة: إجابةٌ تتغيّر بعد التوقيع تكسرها، وحقلُ فريقٍ كذلك
        $this->assertTrue(PlatformDocuments::intact($signed));
        DB::table('platform_documents')->where('id', $doc->id)->update(['answers' => json_encode(['wa' => '+96800000000'] + $answers, JSON_UNESCAPED_UNICODE)]);
        $this->assertFalse(PlatformDocuments::intact($doc->fresh()));
        DB::table('platform_documents')->where('id', $doc->id)->update([
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'team' => json_encode(['itype' => 'غيره'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->assertFalse(PlatformDocuments::intact($doc->fresh()));
    }

    public function test_what_is_filled_on_delivery_is_left_blank_by_everyone(): void
    {
        $doc = $this->issue('store-setup', ['team' => [
            'visitDate' => '2026-11-02', 'rcvName' => 'قبل الأوان', 'c11_0' => [0], 't10' => [['ناجح', 'x']],
            't4' => [['جهازان', '1'], ['', '']],
        ]]);
        $this->assertSame('2026-11-02', $doc->team['visitDate']);
        $this->assertSame(['جهازان', '1'], $doc->team['t4'][0]);
        $this->assertArrayNotHasKey('rcvName', $doc->team, 'محضرُ الاستلام لا يُعبّأ عند الإصدار');
        $this->assertArrayNotHasKey('c11_0', $doc->team);
        $this->assertArrayNotHasKey('t10', $doc->team);

        $this->get(route('documents.sign', $doc->token))
            ->assertSee('يُعبّأ هذا القسم بعد تنفيذ الخدمة')->assertDontSee('name="answers[c11_0][]"', false);

        $this->send($doc, ['answers' => [
            'org' => 'مؤسسة زهور الريان', 'person' => 'سالم', 'phone' => '91234567', 'email' => 's@r.om', 'store' => 'زهور الريان',
            'addr' => 'الخوير', 'onsite' => 'سالم', 'onsitePhone' => '91234567',
            't2' => [['1', 'Epson TM-T20', '1', '', '']], 'c3_0' => [0, 1],
            'c11_0' => [2], 'rcvName' => 'العميل',
        ]])->assertOk();
        $answers = $doc->fresh()->answers;
        $this->assertSame(['1', 'Epson TM-T20', '1', '', ''], $answers['t2'][0]);
        $this->assertArrayNotHasKey('c11_0', $answers);
        $this->assertArrayNotHasKey('rcvName', $answers);
    }

    public function test_the_signed_paper_carries_the_answers_the_team_and_blank_lines(): void
    {
        $doc = $this->issue('whatsapp-integration', ['team' => ['itype' => 'WhatsApp Cloud API']]);
        $this->send($doc, ['answers' => self::WHATSAPP_CLIENT + ['c2_0' => [1]]])->assertOk();

        $admin = $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id));
        $admin->assertOk();
        $text = implode(' ', array_column(PdfText::pages($admin->getContent()), 'text'));
        // مستخرِجُ النصّ يقلب العربيّة — فالقيمُ اللاتينيّة والأرقامُ شاهدُ ما كُتب
        foreach (['WhatsApp Cloud API', '+96891234567', 'salem@rayan.om', '25.000'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringContainsString('______', $text, 'حقلٌ لم يُعبّأ سطرٌ يُكتب عليه');
        $this->assertStringContainsString('☑', $text, 'البندُ المؤشَّر عليه يُرى في الورقة');
        $this->assertStringContainsString('☐', $text);
        $this->assertStringContainsString((string) $doc->fresh()->content_hash, $text);
    }

    public function test_the_subscription_agreement_asks_the_usual_client_details_with_its_plan(): void
    {
        $doc = $this->issue('subscription-agreement');
        $this->assertSame('299.000', $doc->plan['price']);
        $this->assertSame('2026-12-31', $doc->term_starts_on->toDateString());

        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('اتفاقية الاشتراك واستخدام منصة أبعاد')->assertSee('ملكية البيانات والخصوصية')
            ->assertSee('name="client[civil]"', false)->assertSee('299.000')
            ->assertSee('قرأت وأوافق على اتفاقية الاشتراك وسياسة الخصوصية.')
            ->assertDontSee('name="answers[', false);

        $this->send($doc, ['client' => ['cr' => '1234567']])->assertStatus(422)->assertJsonFragment(['message' => 'أكمل: الرقم المدني']);
        $this->send($doc, ['client' => ['cr' => '1234567', 'civil' => '98765432']])->assertOk();

        $signed = $doc->fresh();
        $this->assertSame('98765432', $signed->client['civil']);
        $this->assertNull($signed->answers);
        $this->assertSame($signed->form['consent']."\n".$signed->form['agree'], $signed->consent_text);
        $this->assertTrue(PlatformDocuments::intact($signed));
    }

    public function test_a_contract_signed_before_the_forms_keeps_its_fingerprint(): void
    {
        $short = $this->subscriptionContract();
        $doc = $this->issue('subscription-agreement', ['template_id' => $short->id]);
        $this->assertNull($doc->form);
        $this->send($doc, ['client' => ['cr' => '1234567', 'civil' => '98765432']])->assertOk();
        $signed = $doc->fresh();

        // بصمةُ مستندات البنود كما كانت تُحسب قبل النماذج — حرفًا بحرف
        $png = (string) Storage::disk('local')->get($signed->signature_path);
        $legacy = hash('sha256', json_encode([
            'number' => $signed->number,
            'issued' => $signed->created_at->format('Y-m-d'),
            'renews' => $signed->renews_document_id,
            'provider' => $signed->provider,
            'service' => $signed->service,
            'title' => $signed->title,
            'title_en' => $signed->title_en,
            'terms' => $signed->terms,
            'plan' => $signed->plan,
            'term' => [$signed->term_starts_on->format('Y-m-d'), $signed->term_ends_on->format('Y-m-d')],
            'amount' => null,
            'amount_label' => $signed->amount_label,
            'client' => $signed->client,
            'consent' => $signed->consent_text,
            'agreed_at' => $signed->agreed_at->format('Y-m-d H:i:s'),
            'signed_at' => $signed->signed_at->format('Y-m-d H:i:s'),
            'signer_ip' => $signed->signer_ip,
            'signer_agent' => $signed->signer_agent,
            'signature' => hash('sha256', $png),
        ], JSON_UNESCAPED_UNICODE));
        $this->assertSame($legacy, $signed->content_hash);
        $this->assertSame(PlatformDocuments::CONSENT, $signed->consent_text);
        $this->assertTrue(PlatformDocuments::intact($signed));
    }

    public function test_an_agreement_template_keeps_its_form_when_edited(): void
    {
        $t = $this->template('payment-integration');
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => 'ربط الدفع', 'service' => $t->service,
            'includes_plan' => false, 'includes_amount' => true, 'active' => true,
            'default_amount' => '14', 'default_amount_label' => 'رسوم الربط',
        ])->assertSessionHasNoErrors();

        $t = $t->fresh();
        $this->assertSame('ربط الدفع', $t->title);
        $this->assertSame('14.000', (string) $t->default_amount);
        $this->assertSame(DocumentForms::seed('payment-integration')['form'], $t->form, 'النموذجُ كما صُمِّم — لا تمسّه الشاشة');
    }

    public function test_rolling_back_refuses_once_an_agreement_was_issued(): void
    {
        $this->issue('payment-integration');
        $migration = require database_path('migrations/2026_10_27_090000_abaads_service_agreements_arrive_as_forms.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
