<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AbaadSubscriptionContract;
use Tests\TestCase;

/**
 * لا يُرسل للتوقيع نصٌّ لم ينشره صاحبُ المنصّة — ولا رسومٌ لم ينشرها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - القوالبُ الستّة كلُّها تُعرض في نافذة الإصدار وتُختار (قرارُ المالك 2026-10-25).
 *     وما لم يُنشر إصدارُه الحاليّ لا يُصدر منه إلّا بتأكيد نشره، على بصمة ما رآه
 *     المدير — والنشرُ والمستندُ معًا: إن رُدّ أحدُهما لم يقع الآخر.
 *   - النشرُ لمدير المنصّة وحده، بتأكيدٍ واحدٍ بلا كلمة مرور، ويُسجَّل باسمه
 *     ووقته وإصداره وبصمته. ولا يُنشر قالبٌ بنشر تحديثٍ برمجيّ.
 *   - وبياناتُ مقدّم الخدمة (السجلّ والعنوان) لا تمنع نشرًا ولا إصدارًا — قرارُ
 *     المالك: ما كُتب منها يُطبع، وما لم يُكتب لا يُطبع.
 *   - تعديلُ نصٍّ منشور أو رسومه إصدارٌ جديدٌ غيرُ منشور — وما صدر قبله لا يتغيّر.
 *   - الرسومُ الثابتة هي المبلغ ويذكرها النصّ، و«يبدأ من» حدٌّ أدنى. وتوقيعُ
 *     اتفاقيةٍ لا يكتب فاتورةً ولا دفعةً ولا قيدًا.
 *   - العقدُ القصير القائم يبقى يعمل كما كان، ويحتاج اعتمادًا إن عُدِّل.
 *   - ونشاطٌ جديد يأخذ الاتفاقيةَ المعتمدة بلا شيءٍ يُكتب له.
 */
class AnAgreementIsNotSentBeforeItsOwnerApprovesItTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

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
        // بياناتُ مقدّم الخدمة كما تتركها الهجرة: الاسمان وحدهما — لا سجلّ ولا عنوان
        foreach (['legal_cr', 'legal_address'] as $key) {
            Setting::whereNull('business_id')->where('key', $key)->delete();
        }
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_name'], ['value' => 'مشاريع الذهلي الجديدة']);
    }

    private function template(string $slug): PlatformDocumentTemplate
    {
        return PlatformDocumentTemplate::where('name', DocumentForms::seed($slug)['name'])->firstOrFail();
    }

    private function issueRequest(PlatformDocumentTemplate $t, array $data = [])
    {
        return $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $t->id, 'business_id' => $this->shop->id, 'amount' => $t->default_amount, 'cycle' => 'yearly',
        ]);
    }

    private function publish(PlatformDocumentTemplate $t, array $body = [])
    {
        return $this->actingAs($this->super)->post(route('super-admin.documents.templates.publish', $t->id), $body + [
            'hash' => $t->fresh()->contentHash(),
        ]);
    }

    /** يُصدر ويؤكّد نشرَ الإصدار الحاليّ في الطلب نفسه — كما تفعل نافذةُ الإصدار */
    private function publishAndIssue(PlatformDocumentTemplate $t, array $data = [])
    {
        return $this->issueRequest($t, $data + ['publish' => true, 'template_hash' => $t->fresh()->contentHash()]);
    }

    public function test_every_agreement_is_offered_and_a_draft_is_issued_only_once_its_publishing_is_confirmed(): void
    {
        $draft = $this->template('whatsapp-integration');

        // الستّةُ كلُّها في نافذة الإصدار — والمسوّدةُ منها بإصدارها وبصمتها
        $this->actingAs($this->super)->get(route('super-admin.documents.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('templates', 6)
                ->where('templates', fn ($list) => collect($list)->firstWhere('id', $draft->id)['published'] === false
                    && collect($list)->firstWhere('id', $draft->id)['hash'] === $draft->contentHash()));

        // بلا تأكيد النشر: لا مستندَ ولا نشر
        $this->issueRequest($draft)->assertSessionHasErrors(['document' => 'الإصدار 1 من هذا القالب لم يُنشر بعد — أكّد نشره وإصدار المستند.']);
        // وتأكيدٌ على نصٍّ تغيّر منذ فُتح يُردّ
        $this->issueRequest($draft, ['publish' => true, 'template_hash' => str_repeat('a', 64)])
            ->assertSessionHasErrors(['document' => 'تغيّر القالب منذ فتحته — راجعه من جديد ثمّ انشره.']);
        // ومستندٌ يُردّ لنقصٍ فيه لا ينشر قالبَه
        $this->publishAndIssue($draft, ['client' => ['phone' => 'x'], 'business_id' => null])->assertSessionHasErrors('document');
        $this->assertSame(0, PlatformDocument::count());
        $this->assertSame('draft', $draft->fresh()->approvalState());
        $this->assertFalse($draft->fresh()->active);

        $this->publishAndIssue($draft)->assertSessionHasNoErrors()->assertRedirect();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $draft = $draft->fresh();
        $this->assertTrue($draft->issuable(), 'نُشر الإصدار وصدر منه المستند معًا');
        $this->assertSame($this->super->id, (int) $draft->approved_by);
        $this->assertSame('مدير المنصة', $draft->approved_by_name);
        $this->assertNotNull($draft->approved_at);
        $this->assertSame(1, $doc->template_version);
        $version = $draft->versions()->where('version', 1)->firstOrFail();
        $this->assertSame($draft->contentHash(), $version->content_hash);
        $this->assertSame('مدير المنصة', $version->published_by_name);
        $this->assertTrue(ActivityLog::where('subject_id', $draft->id)->where('subject_type', PlatformDocumentTemplate::class)
            ->where('description', 'like', 'نشر قالب مستند (الإصدار 1%')->exists());

        // والمنشورُ يُصدر منه بلا تأكيدٍ ثانٍ
        $this->issueRequest($draft)->assertSessionHasNoErrors();
        $this->assertSame(2, PlatformDocument::count());
    }

    public function test_publishing_from_the_templates_screen_is_one_confirmation_on_what_was_seen(): void
    {
        $t = $this->template('payment-integration');
        $this->completeProvider();

        // قالبٌ تغيّر بعد أن فُتح
        $seen = $t->contentHash();
        DB::table('platform_document_templates')->where('id', $t->id)->update(['default_amount' => 13]);
        $this->publish($t, ['hash' => $seen])->assertSessionHasErrors(['publish' => 'تغيّر القالب منذ فتحته — راجعه من جديد ثمّ انشره.']);
        $this->assertSame('draft', $t->fresh()->approvalState());
        DB::table('platform_document_templates')->where('id', $t->id)->update(['default_amount' => 12]);

        // بلا كلمة مرور — تأكيدٌ واحد
        $this->publish($t)->assertSessionHasNoErrors();
        $t = $t->fresh();
        $this->assertTrue($t->issuable());
        $this->assertSame($this->super->id, (int) $t->approved_by);
        $this->assertTrue(ActivityLog::where('subject_id', $t->id)->where('subject_type', PlatformDocumentTemplate::class)
            ->where('description', 'like', 'نشر قالب مستند%')->exists());

        $this->issueRequest($t)->assertSessionHasNoErrors()->assertRedirect();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame(1, $doc->template_version);
        $this->assertSame('TEST-CR-0001', $doc->provider['cr'], 'مقدّمُ الخدمة نسخةٌ في المستند');

        // وإيقافُ النشر يعيده إلى التأكيد — ومستندُه كما صدر
        $this->actingAs($this->super)->post(route('super-admin.documents.templates.unpublish', $t->id))->assertSessionHasNoErrors();
        $this->assertFalse($t->fresh()->issuable());
        $this->issueRequest($t)->assertSessionHasErrors('document');
        $this->assertTrue($doc->fresh()->is($doc));
        $this->publishAndIssue($t)->assertSessionHasNoErrors();
    }

    public function test_a_fee_the_text_names_is_needed_before_publishing(): void
    {
        $t = $this->template('store-setup');
        DB::table('platform_document_templates')->where('id', $t->id)->update(['default_amount' => null]);
        $t = $t->fresh();

        $this->publish($t)->assertSessionHasErrors(['publish' => 'اكتب رسوم القالب قبل نشره — نصّه يذكرها.']);
        $this->publishAndIssue($t)->assertSessionHasErrors(['document' => 'اكتب رسوم القالب قبل نشره — نصّه يذكرها.']);
        $this->assertSame(0, PlatformDocument::count());
        $this->assertFalse($t->fresh()->issuable());
    }

    /**
     * وبياناتُ مقدّم الخدمة لا تقيّد المالك — قرارُه (2026-10-25).
     *
     * بلا سجلٍّ ولا عنوان يُعتمد القالبُ ويُصدر منه، ولا يُطبع في العقد سطرٌ فارغ
     * ولا رقمٌ يُخترع. وما يُكتب بعدها يظهر في المستندات التالية وحدها.
     */
    public function test_the_providers_cr_and_address_never_block_approval_or_issuing(): void
    {
        $t = $this->template('subscription-agreement');
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_trade_name'], ['value' => 'أبعاد']);

        $this->publish($t)->assertSessionHasNoErrors();
        $this->assertSame('approved', $t->fresh()->approvalState());
        $this->issueRequest($t)->assertSessionHasNoErrors()->assertRedirect();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('', $doc->provider['cr']);
        $this->assertSame('', $doc->provider['address']);
        $this->assertSame('مشاريع الذهلي الجديدة — الاسم التجاري للمنصة: أبعاد', $doc->form['highlights'][0]['text'], 'الطرفُ الأوّل بما كُتب منه وحده');
        auth()->logout();
        $this->get(route('documents.sign', $doc->token))->assertOk()
            ->assertSee('مشاريع الذهلي الجديدة')->assertDontSee('السجل التجاري رقم')->assertDontSee('id="provider-address"', false);

        // ويُكتب السجلُّ بعدها فيظهر فيما يصدر بعده — والأوّلُ كما صدر
        $this->completeProvider();
        $this->issueRequest($t)->assertSessionHasNoErrors();
        $next = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('TEST-CR-0001', $next->provider['cr']);
        $this->assertStringContainsString('السجل التجاري رقم TEST-CR-0001', $next->form['highlights'][0]['text']);
        $this->assertSame('', $doc->fresh()->provider['cr']);
    }

    public function test_only_a_platform_admin_publishes_edits_previews_or_issues(): void
    {
        $this->completeProvider();
        $t = $this->template('store-setup');
        $merchant = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $calls = [
            ['post', route('super-admin.documents.templates.publish', $t->id), ['hash' => $t->contentHash()]],
            ['post', route('super-admin.documents.templates.unpublish', $t->id), []],
            ['put', route('super-admin.documents.templates.update', $t->id), ['name' => 'X', 'title' => 'X', 'form' => $t->form]],
            ['post', route('super-admin.documents.templates.store'), ['name' => 'X', 'title' => 'X', 'terms' => [['h' => '', 'b' => 'X']]]],
            ['get', route('super-admin.documents.templates.preview', $t->id), []],
            ['get', route('super-admin.documents.templates.preview-pdf', $t->id), []],
            ['post', route('super-admin.documents.store'), ['template_id' => $t->id, 'business_id' => $this->shop->id, 'publish' => true, 'template_hash' => $t->contentHash()]],
        ];

        foreach ($calls as [$verb, $url, $body]) {
            $this->actingAs($merchant)->{$verb}($url, $body)->assertForbidden();
        }
        auth()->logout();
        foreach ($calls as [$verb, $url, $body]) {
            $this->{$verb}($url, $body)->assertRedirect(route('login'));
        }
        $t = $t->fresh();
        $this->assertSame('draft', $t->approvalState());
        $this->assertSame(1, $t->version);
        $this->assertSame(0, PlatformDocument::count());
        $this->assertSame(0, PlatformDocumentTemplate::where('name', 'X')->count());
    }

    public function test_editing_an_approved_text_is_a_new_version_and_what_was_issued_stays(): void
    {
        $this->completeProvider();
        $this->actingAs($this->super)->post(route('super-admin.documents.templates.store'), [
            'name' => 'تطبيق خاص', 'title' => 'اتفاقية تطبيق خاص', 'terms' => [['h' => 'التسليم:', 'b' => 'خلال ثلاثين يومًا.']],
            'includes_plan' => false, 'includes_amount' => true, 'active' => true, 'default_amount' => '100', 'amount_rule' => 'fixed',
        ])->assertSessionHasNoErrors();
        $t = PlatformDocumentTemplate::where('name', 'تطبيق خاص')->firstOrFail();
        $this->assertFalse($t->active, 'القالبُ الجديد غيرُ منشورٍ ولو طُلب تفعيلُه');
        $this->assertSame('draft', $t->approvalState());
        $this->assertSame(1, $t->versions()->count());

        $this->publish($t)->assertSessionHasNoErrors();
        $this->issueRequest($t, ['client' => ['phone' => '+96891234567']])->assertSessionHasNoErrors();
        $first = PlatformDocument::latest('id')->firstOrFail();
        $this->post(route('documents.sign.submit', $first->token), [
            'agree' => '1', 'signature' => $this->signature(), 'client' => ['cr' => '1234567', 'civil' => '98765432'],
        ]);
        $first = $first->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $first->status);

        // نصٌّ جديد: الإصدارُ الثاني، غيرُ منشورٍ حتى يُؤكَّد نشرُه
        $t = $t->fresh();
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => $t->title, 'terms' => [['h' => 'التسليم:', 'b' => 'خلال ستين يومًا.']],
            'includes_plan' => false, 'includes_amount' => true, 'default_amount' => '100', 'amount_rule' => 'fixed', 'active' => true,
        ])->assertSessionHasNoErrors();
        $t = $t->fresh();
        $this->assertSame(2, $t->version);
        $this->assertFalse($t->active);
        $this->assertSame('draft', $t->approvalState());
        $this->assertSame([2, 1], $t->versions()->pluck('version')->all(), 'الإصدارُ الأوّل باقٍ في سجلّه');
        $this->assertSame('خلال ثلاثين يومًا.', $t->versions()->where('version', 1)->firstOrFail()->content['terms'][0]['b']);
        $this->issueRequest($t)->assertSessionHasErrors('document');

        // والموقَّعُ قبله كما وُقِّع: نصُّه وبصمتُه وإصدارُه وورقتُه
        $first = $first->fresh();
        $this->assertSame('خلال ثلاثين يومًا.', $first->terms[0]['b']);
        $this->assertSame(1, $first->template_version);
        $this->assertTrue(PlatformDocuments::intact($first));
        $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $first->id))->assertOk();

        // وإصدارٌ جديدٌ غيرُ منشور لا يمسّ مستنداته
        $this->assertSame(1, $t->documents()->count());
    }

    public function test_the_short_contract_keeps_issuing_and_needs_publishing_once_edited(): void
    {
        $short = $this->subscriptionContract();
        $this->assertSame('legacy', $short->approvalState());
        $this->issueRequest($short)->assertSessionHasNoErrors();

        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $short->id), [
            'name' => $short->name, 'service' => $short->service, 'title' => 'عقد اشتراك معدَّل', 'title_en' => $short->title_en,
            'terms' => $short->terms, 'includes_plan' => true, 'includes_amount' => false, 'active' => true,
        ])->assertSessionHasNoErrors();
        $short = $short->fresh();
        $this->assertSame('draft', $short->approvalState());
        $this->assertFalse($short->active);
        $this->issueRequest($short)->assertSessionHasErrors('document');
        $this->publishAndIssue($short)->assertSessionHasNoErrors();
        $this->assertSame('عقد اشتراك معدَّل', PlatformDocument::latest('id')->firstOrFail()->title);
        $this->assertSame('عقد اشتراك', PlatformDocument::oldest('id')->firstOrFail()->title, 'العقدُ الذي صدر قبله بعنوانه');
    }

    public function test_a_fixed_fee_is_the_amount_and_the_text_says_it(): void
    {
        $this->completeProvider();
        $t = $this->approveTemplate($this->template('whatsapp-integration'));

        $this->issueRequest($t, ['amount' => '20'])->assertSessionHasErrors(['document' => 'رسوم هذه الخدمة ثابتة: 25.000 ر.ع كما نُشرت. لتغييرها عدّل القالب وانشره من جديد.']);
        // وبلوحةٍ عربيّة هو الرقمُ نفسُه، وبلا رقمٍ يُؤخذ المعتمد
        $this->issueRequest($t, ['amount' => '٢٥'])->assertSessionHasNoErrors();
        $this->issueRequest($t, ['amount' => ''])->assertSessionHasNoErrors();
        $old = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('25.000', (string) $old->amount);
        $this->assertStringContainsString('رسوم الربط 25.000 ر.ع', json_encode($old->form, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('تشمل رسوم الـ 25 ر.ع', json_encode($old->form, JSON_UNESCAPED_UNICODE));

        // رسومٌ جديدة من اللوحة: إصدارٌ يُعتمد، ثمّ يقولها النصُّ والمبلغُ معًا — والقديمُ بسعره
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'service' => $t->service, 'title' => $t->title, 'title_en' => $t->title_en, 'form' => $t->form,
            'includes_plan' => false, 'includes_amount' => true, 'default_amount' => '٢٧٫٥٠٠', 'default_amount_label' => $t->default_amount_label, 'amount_rule' => $t->amount_rule,
        ])->assertSessionHasNoErrors();
        $t = $this->approveTemplate($t->fresh());
        $this->assertSame('27.500', (string) $t->default_amount);
        $this->issueRequest($t, ['amount' => ''])->assertSessionHasNoErrors();
        $new = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('27.500', (string) $new->amount);
        $this->assertSame('27.500 ر.ع تُدفع مرة واحدة', $new->form['highlights'][0]['text']);
        $this->assertStringContainsString('تشمل رسوم الـ 27.5 ر.ع', json_encode($new->form, JSON_UNESCAPED_UNICODE));
        $this->assertSame('25.000', (string) $old->fresh()->amount, 'سعرُ العقد التاريخيّ لا يتغيّر');
        $this->assertSame('25.000 ر.ع تُدفع مرة واحدة', $old->fresh()->form['highlights'][0]['text']);

        // والإقرارُ نفسُه يذكر الرسوم المعتمدة في اتفاقية الدفع
        $pay = $this->approveTemplate($this->template('payment-integration'));
        $this->issueRequest($pay, ['amount' => ''])->assertSessionHasNoErrors();
        $this->assertStringContainsString('البالغة 12 ريالًا عُمانيًا', PlatformDocument::latest('id')->firstOrFail()->form['consent']);
    }

    public function test_a_starting_price_is_a_floor_and_three_decimals_are_kept(): void
    {
        $this->completeProvider();
        $t = $this->approveTemplate($this->template('website-customization'));

        $this->issueRequest($t, ['amount' => '59.999'])->assertSessionHasErrors(['document' => 'سعر هذه الخدمة يبدأ من 60.000 ر.ع كما نُشر.']);
        $this->issueRequest($t, ['amount' => '75.1234'])->assertSessionHasErrors('amount');
        $this->issueRequest($t, ['amount' => '٧٥٫٢٥٠'])->assertSessionHasNoErrors();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('75.250', (string) $doc->amount);
        $this->assertStringContainsString('يبدأ من 60 ر.ع', json_encode($doc->form, JSON_UNESCAPED_UNICODE));

        // ورسومُ القالب بثلاث خاناتٍ لا أكثر — والعربيّةُ تُقرأ
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => $t->title, 'form' => $t->form, 'includes_amount' => true, 'default_amount' => '60.0001',
        ])->assertSessionHasErrors('default_amount');
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => $t->title, 'form' => $t->form, 'includes_amount' => true, 'default_amount' => 'ستون',
        ])->assertSessionHasErrors('default_amount');
    }

    public function test_signing_an_agreement_writes_no_invoice_payment_or_entry(): void
    {
        $this->completeProvider();
        $t = $this->approveTemplate($this->template('subscription-agreement'));
        $this->issueRequest($t)->assertSessionHasNoErrors();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        auth()->logout();

        $tables = array_values(array_filter(['invoices', 'subscriptions', 'customer_invoices', 'customer_payments', 'journal_entries', 'journal_lines', 'store_payment_intents'], fn ($t) => Schema::hasTable($t)));
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);
        $ends = $this->shop->fresh()->ends_at;

        $this->postJson(route('documents.sign.submit', $doc->token), [
            'agree' => '1', 'signature' => $this->signature(), 'client' => ['cr' => '1234567', 'civil' => '98765432'],
        ])->assertOk();

        $this->assertSame(PlatformDocument::SIGNED, $doc->fresh()->status);
        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables), 'التوقيعُ لا يكتب مالًا ولا اشتراكًا');
        $this->assertEquals($ends, $this->shop->fresh()->ends_at, 'ولا يمدّد الاشتراك');
    }

    public function test_a_new_business_or_a_prospect_takes_an_approved_agreement_without_code(): void
    {
        $this->completeProvider();
        $t = $this->approveTemplate($this->template('store-setup'));
        $newcomer = Business::create(['name' => 'مخبز الفجر', 'owner_name' => 'خالد', 'phone' => '92222222', 'status' => 'نشط']);

        $this->issueRequest($t, ['business_id' => $newcomer->id])->assertSessionHasNoErrors();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame((int) $newcomer->id, (int) $doc->client_business_id, 'المستندُ لنشاطه لا لغيره');
        $this->assertSame('مخبز الفجر', $doc->client['biz']);

        $this->issueRequest($t, ['business_id' => null, 'client' => ['name' => 'مريم', 'biz' => 'حلويات مريم', 'phone' => '+96899000000']])->assertSessionHasNoErrors();
        $this->assertNull(PlatformDocument::latest('id')->firstOrFail()->client_business_id);
    }

    public function test_the_migration_keeps_what_the_owner_wrote_and_rolls_back_cleanly_before_any_agreement(): void
    {
        $short = $this->subscriptionContract();
        $this->issueRequest($short)->assertSessionHasNoErrors();
        $legacy = PlatformDocument::latest('id')->firstOrFail();
        Setting::whereNull('business_id')->where('key', 'legal_name')->update(['value' => 'اسمٌ كتبه المالك']);

        $migration = require database_path('migrations/2026_10_27_090000_abaads_service_agreements_arrive_as_forms.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('platform_documents', 'answers'));
        $this->assertSame(0, PlatformDocumentTemplate::query()->whereIn('name', array_map(fn ($s) => DocumentForms::seed($s)['name'], DocumentForms::SEEDED))->count());
        $this->assertTrue(PlatformDocument::whereKey($legacy->id)->exists(), 'التراجعُ لا يمسّ مستندًا صدر');

        $migration->up();
        $this->assertSame('اسمٌ كتبه المالك', Setting::whereNull('business_id')->where('key', 'legal_name')->value('value'), 'الهجرةُ لا تكتب فوق ما كتبه المالك');
        $this->assertSame('legacy', PlatformDocumentTemplate::find($short->id)->approvalState());
        $this->assertSame(5, PlatformDocumentTemplate::whereNotNull('form')->where('active', false)->count());
    }

    /**
     * والنشرُ لا يمسّ عقدًا موقَّعًا ولا ملفّيه ولا اشتراكَ تاجر — ذهابًا وإيابًا.
     *
     * الهجرةُ تضيف أعمدةً على جدول العقود نفسه: فيُحرس أنّ صفَّ عقدٍ وُقِّع قبلها
     * بقي بصمتُه وصورةُ توقيعه وورقتُه، وأنّ الأنشطةَ واشتراكاتِها كما كانت.
     */
    public function test_the_migration_leaves_signed_contracts_their_files_and_subscriptions_as_they_were(): void
    {
        $this->shop->forceFill(['starts_at' => '2026-01-01', 'ends_at' => '2026-12-31'])->save();
        $short = $this->subscriptionContract();
        $this->issueRequest($short)->assertSessionHasNoErrors();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        auth()->logout();
        $this->postJson(route('documents.sign.submit', $doc->token), [
            'agree' => '1', 'signature' => $this->signature(), 'client' => ['cr' => '1234567', 'civil' => '98765432'],
        ])->assertOk();
        $signed = $doc->fresh();
        $this->assertNotNull($signed->pdf_path);

        $snapshot = fn () => [
            'businesses' => DB::table('businesses')->orderBy('id')->get(['id', 'plan_id', 'status', 'starts_at', 'ends_at'])->toArray(),
            'subscriptions' => Schema::hasTable('subscriptions') ? DB::table('subscriptions')->orderBy('id')->get()->toArray() : [],
            'document' => (array) DB::table('platform_documents')->where('id', $doc->id)
                ->first(['number', 'status', 'terms', 'client', 'provider', 'consent_text', 'signed_at', 'signature_path', 'content_hash', 'pdf_path', 'pdf_hash']),
        ];
        $before = $snapshot();

        $migration = require database_path('migrations/2026_10_27_090000_abaads_service_agreements_arrive_as_forms.php');
        $migration->down();
        $migration->up();

        $this->assertEquals($before, $snapshot(), 'تغيّر عقدٌ موقَّع أو نشاطٌ أو اشتراك');
        $after = $doc->fresh();
        $this->assertTrue(PlatformDocuments::intact($after), 'بصمةُ العقد الموقَّع بعد الهجرة');
        $this->assertTrue(Storage::disk('local')->exists($after->signature_path));
        $this->assertSame($after->pdf_hash, hash('sha256', (string) Storage::disk('local')->get($after->pdf_path)), 'الورقةُ المحفوظة كما هي');
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
}
