<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AbaadSubscriptionContract;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * الطرفُ الأوّل في العقد بما كتبه المالك وحده — لا اسمٌ يُستعار حين يغيب.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الاسمُ القانونيّ من `legal_name` وحده: لا «اسم الشركة المالكة» (`company`،
 *     افتراضُه في الشاشة «شركة أبعاد للتقنية») ولا اسمُ التطبيق ولا «أبعاد» من الكود.
 *   - وما لم يُكتب لا يُطبع في صفحة التوقيع ولا في الورقة ولا في رسالة واتساب: لا
 *     سطرٌ فارغ ولا شَرطة ولا «نظام Abad POS» في الترويسة — والجملةُ تُقال بلا اسم.
 *   - والسجلُّ والعنوانُ والاسمُ القانونيّ لا تمنع اعتمادًا ولا إصدارًا.
 *   - والمستندُ الذي صدر يبقى بما نُسخ فيه يومَ صدر، وبصمتُه كما هي.
 */
class AContractNamesItsProviderOnlyAsWrittenTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private const LEGAL_KEYS = ['legal_name', 'legal_trade_name', 'legal_cr', 'legal_address'];

    private User $super;

    private Business $shop;

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
        $this->subscriptionContract();

        // الأسماءُ التي كانت تُستعار — مكتوبةٌ كلُّها، والطرفُ الأوّل فارغٌ كلُّه
        Setting::whereNull('business_id')->whereIn('key', self::LEGAL_KEYS)->delete();
        foreach (['company' => 'Company Field LLC', 'app_name' => 'App Name POS'] as $key => $value) {
            Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
        }
    }

    private function agreement(): PlatformDocumentTemplate
    {
        return PlatformDocumentTemplate::where('name', DocumentForms::seed('subscription-agreement')['name'])->firstOrFail();
    }

    private function issue(PlatformDocumentTemplate $t): PlatformDocument
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $this->confirmed([
            'template_id' => $t->id, 'business_id' => $this->shop->id, 'amount' => $t->default_amount, 'cycle' => 'yearly',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function paperHtml(PlatformDocument $doc): string
    {
        return view('pdf.platform-document', [
            'doc' => $doc, 'provider' => PlatformDocuments::providerOf($doc), 'fields' => PlatformDocuments::FIELDS, 'signature' => null,
        ])->render();
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

    public function test_the_legal_name_is_read_from_legal_name_alone(): void
    {
        $provider = PlatformDocuments::provider();
        $this->assertSame('', $provider['name'], 'لا اسمَ يُستعار من company أو app_name أو الكود');
        $this->assertSame('', $provider['legal_name']);
        $this->assertSame('', PlatformDocuments::providerLine($provider));
        $this->assertSame('', PlatformDocuments::providerName($provider));

        // والافتراضُ الذي تعرضه شاشةُ الإعدادات للشركة لا يصل إلى العقد ولو حُفظ
        Setting::whereNull('business_id')->where('key', 'company')->update(['value' => 'شركة أبعاد للتقنية']);
        $this->assertSame('', PlatformDocuments::provider()['name']);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_name'], ['value' => 'Legal Entity LLC']);
        $provider = PlatformDocuments::provider();
        $this->assertSame('Legal Entity LLC', $provider['name']);
        $this->assertSame('Legal Entity LLC', $provider['legal_name']);
        $this->assertSame('Legal Entity LLC', PlatformDocuments::providerLine($provider));
    }

    public function test_with_no_provider_details_an_agreement_is_issued_and_prints_none(): void
    {
        $t = $this->agreement();
        // لا اسمَ ولا سجلَّ ولا عنوان — والإصدارُ يمرّ
        $this->assertTrue($t->fresh()->issuable());
        $doc = $this->issue($t->fresh());

        $this->assertSame(['name' => '', 'legal_name' => '', 'trade_name' => '', 'cr' => '', 'address' => ''], array_intersect_key($doc->provider, array_flip(['name', 'legal_name', 'trade_name', 'cr', 'address'])));
        $this->assertNotContains('مقدم الخدمة', array_column($doc->form['highlights'] ?? [], 'label'), 'سطرُ «مقدم الخدمة» يسقط ولا يُكتب فيه «—»');
        $this->assertStringNotContainsString('{provider}', json_encode($doc->form, JSON_UNESCAPED_UNICODE));

        auth()->logout();
        $page = $this->get(route('documents.sign', $doc->token))->assertOk();
        foreach (['Company Field LLC', 'App Name POS', 'شركة أبعاد للتقنية', 'نظام Abad POS', 'السجل التجاري رقم', 'الاسم التجاري للمنصة', 'نظام سحابي متكامل', '©'] as $borrowed) {
            $page->assertDontSee($borrowed, false);
        }
        $page->assertDontSee('id="provider-name"', false)->assertDontSee('<div class="brand">', false)
            ->assertSee('<title>'.e($doc->title).'</title>', false);

        $html = $this->paperHtml($doc);
        foreach (['Company Field LLC', 'App Name POS', 'شركة أبعاد للتقنية', 'نظام Abad POS', 'صادر عن', 'السجل التجاري رقم', 'نظام سحابي متكامل', 'class="p-brand"'] as $borrowed) {
            $this->assertStringNotContainsString($borrowed, $html, $borrowed);
        }
        $this->assertStringContainsString('يُوقَّع إلكترونيًا عبر رابطٍ خاصّ بالعميل.', $html);
        $text = implode(' ', array_column(PdfText::pages($this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->getContent()), 'text'));
        $this->assertStringNotContainsString('Company Field LLC', $text);
        $this->assertStringNotContainsString('App Name POS', $text);
        $this->assertStringNotContainsString('Abad POS', $text);

        $message = PlatformDocuments::whatsappText($doc, 'https://abaad.test/sign/x');
        $this->assertStringContainsString('يسعدنا إرسال '.$doc->title, $message);
        $this->assertStringNotContainsString('Company Field LLC', $message);
        $this->assertStringNotContainsString('App Name POS', $message);
        $this->assertStringNotContainsString('في  ', $message);
        $this->assertStringEndsWith('مع التحية،', $message, 'لا سطرَ فارغًا مكانَ الاسم');
    }

    public function test_a_closed_link_says_who_to_contact_without_borrowing_a_name(): void
    {
        $doc = $this->issue($this->subscriptionContract());
        $doc->forceFill(['status' => PlatformDocument::CANCELLED])->save();
        auth()->logout();

        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('تواصل مع مُرسِل المستند')->assertDontSee('Company Field LLC')->assertDontSee('App Name POS');
    }

    public function test_what_is_written_is_printed_and_only_that(): void
    {
        // الاسمُ التجاريّ وحده: يُخاطَب به العميل، ولا يُطبع مكانَ الاسم القانونيّ
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_trade_name'], ['value' => 'Trade Only']);
        $t = $this->agreement();
        $doc = $this->issue($t);

        $this->assertSame('', $doc->provider['legal_name']);
        $this->assertSame('الاسم التجاري للمنصة: Trade Only', $doc->form['highlights'][0]['text']);
        auth()->logout();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('<div class="brand">Trade Only</div>', false)->assertSee('© Trade Only', false)
            ->assertDontSee('id="provider-name"', false)->assertDontSee('id="provider-cr"', false)->assertDontSee('id="provider-address"', false);
        $this->assertStringContainsString('صادر عن Trade Only', $this->paperHtml($doc));
        $this->assertStringContainsString('يسعدنا في Trade Only إرسال', PlatformDocuments::whatsappText($doc, 'https://abaad.test/sign/x'));

        // والبيانُ كاملًا يُطبع كلُّه
        $this->completeProvider();
        $full = $this->issue($t);
        auth()->logout();
        $this->get(route('documents.sign', $full->token))->assertOk()
            ->assertSee('<div class="who" id="provider-name">مشاريع الذهلي الجديدة</div>', false)
            ->assertSee('TEST-CR-0001')->assertSee('id="provider-address"', false)->assertDontSee('Company Field LLC');
    }

    public function test_an_issued_contract_keeps_the_provider_it_carried_and_its_fingerprint(): void
    {
        // مستندٌ صدر قبل `legal_*` يحمل الطرفَ الأوّل باسمه يومَها — يُعرض به ولا يُفرَّغ
        $doc = $this->issue($this->subscriptionContract());
        $old = ['name' => 'Old Company LLC', 'email' => 'old@abaad.test', 'phone' => '', 'website' => ''];
        DB::table('platform_documents')->where('id', $doc->id)->update(['provider' => json_encode($old, JSON_UNESCAPED_UNICODE)]);
        auth()->logout();
        $this->post(route('documents.sign.submit', $doc->token), [
            'client' => ['cr' => '1234567', 'civil' => '98765432'], 'agree' => '1', 'signature' => $this->signature(),
        ])->assertSessionHasNoErrors();
        $row = DB::table('platform_documents')->where('id', $doc->id)->first();
        $this->assertNotNull($row->content_hash);

        // ولا يغيّره ما يُكتب اليوم ولا ما يُمحى
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_name'], ['value' => 'New Legal LLC']);
        $doc = $doc->fresh();
        $this->assertSame('Old Company LLC', PlatformDocuments::providerOf($doc)['name']);
        $this->assertSame('Old Company LLC', PlatformDocuments::providerName(PlatformDocuments::providerOf($doc)));
        $text = implode(' ', array_column(PdfText::pages($this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->getContent()), 'text'));
        $this->assertStringContainsString('Old Company LLC', $text);
        $this->assertStringNotContainsString('New Legal LLC', $text);

        $after = DB::table('platform_documents')->where('id', $doc->id)->first();
        $this->assertSame($row->provider, $after->provider, 'بيانُ الطرف الأوّل في الصفّ كما كان');
        $this->assertSame($row->content_hash, $after->content_hash, 'والبصمةُ المحفوظة كما كانت');
        $this->assertTrue(PlatformDocuments::intact($doc->fresh()));
    }
}
