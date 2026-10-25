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
 * لا يُرسل للتوقيع نصٌّ لم يعتمده صاحبُ المنصّة — ولا رسومٌ لم يعتمدها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الاتفاقياتُ الخمس تصل مسوّداتٍ معطَّلة: لا تُعرض في نافذة الإصدار ولا يُصدر
 *     منها ولو فُعِّلت بيدٍ في القاعدة.
 *   - الاعتمادُ لمدير المنصّة وحده، بكلمة مروره، على بصمة ما رآه. والاعتمادُ لا
 *     يفعّل؛ والتفعيلُ لا يسبق الاعتماد.
 *   - وبياناتُ مقدّم الخدمة (السجلّ والعنوان) لا تمنع اعتمادًا ولا إصدارًا — قرارُ
 *     المالك: ما كُتب منها يُطبع، وما لم يُكتب لا يُطبع.
 *   - تعديلُ نصٍّ معتمد أو رسومه إصدارٌ جديدٌ معطَّل — وما صدر قبله لا يتغيّر.
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

    private function approve(PlatformDocumentTemplate $t, array $body = [])
    {
        return $this->actingAs($this->super)->post(route('super-admin.documents.templates.approve', $t->id), $body + [
            'hash' => $t->fresh()->contentHash(), 'password' => 'password',
        ]);
    }

    private function activate(PlatformDocumentTemplate $t)
    {
        $t = $t->fresh();

        return $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'service' => $t->service, 'title' => $t->title, 'title_en' => $t->title_en,
            'terms' => $t->terms ?: null, 'includes_plan' => $t->includes_plan, 'includes_amount' => $t->includes_amount,
            'default_amount' => $t->default_amount, 'default_amount_label' => $t->default_amount_label, 'amount_rule' => $t->amount_rule,
            'active' => true,
        ]);
    }

    public function test_a_draft_agreement_is_neither_offered_nor_issued(): void
    {
        $draft = $this->template('whatsapp-integration');

        $this->actingAs($this->super)->get(route('super-admin.documents.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('templates', fn ($list) => collect($list)->pluck('id')->doesntContain($draft->id)));
        $this->issueRequest($draft)->assertSessionHasErrors('template_id');

        // ولو فُعِّلت بيدٍ في القاعدة: نصٌّ لم يُعتمد لا يُصدر منه
        DB::table('platform_document_templates')->where('id', $draft->id)->update(['active' => true]);
        $this->issueRequest($draft)->assertSessionHasErrors(['document' => 'هذا القالب لم يُعتمد بنصّه الحاليّ — لا يُصدر منه مستند حتى يعتمده مدير المنصّة.']);
        $this->assertSame(0, PlatformDocument::count());

        // ولا يُفعَّل من الشاشة قبل اعتماده
        DB::table('platform_document_templates')->where('id', $draft->id)->update(['active' => false]);
        $this->activate($draft)->assertSessionHasErrors('active');
        $this->assertFalse($draft->fresh()->active);
    }

    public function test_approval_is_by_password_on_what_was_seen(): void
    {
        $t = $this->template('payment-integration');
        $this->completeProvider();

        // كلمةُ مرورٍ خاطئة، أو قالبٌ تغيّر بعد أن فُتح
        $this->approve($t, ['password' => 'wrong'])->assertSessionHasErrors('password');
        $seen = $t->contentHash();
        DB::table('platform_document_templates')->where('id', $t->id)->update(['default_amount' => 13]);
        $this->approve($t, ['hash' => $seen])->assertSessionHasErrors(['approve' => 'تغيّر القالب منذ فتحته — راجعه من جديد ثمّ اعتمده.']);
        $this->assertSame('draft', $t->fresh()->approvalState());
        DB::table('platform_document_templates')->where('id', $t->id)->update(['default_amount' => 12]);

        $this->approve($t)->assertSessionHasNoErrors();
        $t = $t->fresh();
        $this->assertSame('approved', $t->approvalState());
        $this->assertSame($this->super->id, (int) $t->approved_by);
        $this->assertSame('مدير المنصة', $t->approved_by_name);
        $this->assertFalse($t->active, 'الاعتمادُ لا يفعّل — التفعيلُ قرارٌ بعده');
        $this->assertTrue(ActivityLog::where('subject_id', $t->id)->where('subject_type', PlatformDocumentTemplate::class)
            ->where('description', 'like', 'اعتمد قالب مستند%')->exists());

        $this->activate($t)->assertSessionHasNoErrors();
        $this->issueRequest($t)->assertSessionHasNoErrors()->assertRedirect();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame(1, $doc->template_version);
        $this->assertSame('TEST-CR-0001', $doc->provider['cr'], 'مقدّمُ الخدمة نسخةٌ في المستند');

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

        $this->approve($t)->assertSessionHasNoErrors();
        $this->assertSame('approved', $t->fresh()->approvalState());
        $this->activate($t)->assertSessionHasNoErrors();
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

    public function test_only_a_platform_admin_approves(): void
    {
        $this->completeProvider();
        $t = $this->template('store-setup');
        $merchant = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);

        $this->actingAs($merchant)->post(route('super-admin.documents.templates.approve', $t->id), ['hash' => $t->contentHash(), 'password' => 'password'])->assertForbidden();
        auth()->logout();
        $this->post(route('super-admin.documents.templates.approve', $t->id), ['hash' => $t->contentHash(), 'password' => 'password'])->assertRedirect();
        $this->assertSame('draft', $t->fresh()->approvalState());
    }

    public function test_editing_an_approved_text_is_a_new_version_and_what_was_issued_stays(): void
    {
        $this->completeProvider();
        $this->actingAs($this->super)->post(route('super-admin.documents.templates.store'), [
            'name' => 'تطبيق خاص', 'title' => 'اتفاقية تطبيق خاص', 'terms' => [['h' => 'التسليم:', 'b' => 'خلال ثلاثين يومًا.']],
            'includes_plan' => false, 'includes_amount' => true, 'active' => true, 'default_amount' => '100', 'amount_rule' => 'fixed',
        ])->assertSessionHasNoErrors();
        $t = PlatformDocumentTemplate::where('name', 'تطبيق خاص')->firstOrFail();
        $this->assertFalse($t->active, 'القالبُ الجديد مسوّدةٌ ولو طُلب تفعيلُه');
        $this->assertSame('draft', $t->approvalState());

        $this->approve($t)->assertSessionHasNoErrors();
        $this->activate($t)->assertSessionHasNoErrors();
        $this->issueRequest($t, ['client' => ['phone' => '+96891234567']])->assertSessionHasNoErrors();
        $first = PlatformDocument::latest('id')->firstOrFail();
        $this->post(route('documents.sign.submit', $first->token), [
            'agree' => '1', 'signature' => $this->signature(), 'client' => ['cr' => '1234567', 'civil' => '98765432'],
        ]);
        $first = $first->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $first->status);

        // نصٌّ جديد: الإصدارُ الثاني، معطَّلٌ حتى يُعتمد
        $t = $t->fresh();
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => $t->title, 'terms' => [['h' => 'التسليم:', 'b' => 'خلال ستين يومًا.']],
            'includes_plan' => false, 'includes_amount' => true, 'default_amount' => '100', 'amount_rule' => 'fixed', 'active' => true,
        ])->assertSessionHasNoErrors();
        $t = $t->fresh();
        $this->assertSame(2, $t->version);
        $this->assertFalse($t->active);
        $this->assertSame('draft', $t->approvalState());
        $this->issueRequest($t)->assertSessionHasErrors('template_id');

        // والموقَّعُ قبله كما وُقِّع: نصُّه وبصمتُه وإصدارُه وورقتُه
        $first = $first->fresh();
        $this->assertSame('خلال ثلاثين يومًا.', $first->terms[0]['b']);
        $this->assertSame(1, $first->template_version);
        $this->assertTrue(PlatformDocuments::intact($first));
        $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $first->id))->assertOk();

        // وتعطيلُ القالب لا يمسّ مستنداته
        $this->assertSame(1, $t->documents()->count());
    }

    public function test_the_short_contract_keeps_issuing_and_needs_approval_once_edited(): void
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
    }

    public function test_a_fixed_fee_is_the_amount_and_the_text_says_it(): void
    {
        $this->completeProvider();
        $t = $this->approveTemplate($this->template('whatsapp-integration'));

        $this->issueRequest($t, ['amount' => '20'])->assertSessionHasErrors(['document' => 'رسوم هذه الخدمة ثابتة: 25.000 ر.ع كما اعتُمدت. لتغييرها عدّل القالب واعتمده من جديد.']);
        // وبلوحةٍ عربيّة هو الرقمُ نفسُه، وبلا رقمٍ يُؤخذ المعتمد
        $this->issueRequest($t, ['amount' => '٢٥'])->assertSessionHasNoErrors();
        $this->issueRequest($t, ['amount' => ''])->assertSessionHasNoErrors();
        $old = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('25.000', (string) $old->amount);
        $this->assertStringContainsString('رسوم الربط 25.000 ر.ع', json_encode($old->form, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('تشمل رسوم الـ 25 ر.ع', json_encode($old->form, JSON_UNESCAPED_UNICODE));

        // رسومٌ جديدة من اللوحة: إصدارٌ يُعتمد، ثمّ يقولها النصُّ والمبلغُ معًا — والقديمُ بسعره
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'service' => $t->service, 'title' => $t->title, 'title_en' => $t->title_en,
            'includes_plan' => false, 'includes_amount' => true, 'default_amount' => '٢٧٫٥٠٠', 'default_amount_label' => $t->default_amount_label,
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

        $this->issueRequest($t, ['amount' => '59.999'])->assertSessionHasErrors(['document' => 'سعر هذه الخدمة يبدأ من 60.000 ر.ع كما اعتُمد.']);
        $this->issueRequest($t, ['amount' => '75.1234'])->assertSessionHasErrors('amount');
        $this->issueRequest($t, ['amount' => '٧٥٫٢٥٠'])->assertSessionHasNoErrors();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('75.250', (string) $doc->amount);
        $this->assertStringContainsString('يبدأ من 60 ر.ع', json_encode($doc->form, JSON_UNESCAPED_UNICODE));

        // ورسومُ القالب بثلاث خاناتٍ لا أكثر — والعربيّةُ تُقرأ
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => $t->title, 'includes_amount' => true, 'default_amount' => '60.0001',
        ])->assertSessionHasErrors('default_amount');
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), [
            'name' => $t->name, 'title' => $t->title, 'includes_amount' => true, 'default_amount' => 'ستون',
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
