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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AbaadSubscriptionContract;
use Tests\TestCase;

/**
 * اتفاقيةٌ تُحفظ صالحةً فيُصدر منها — بلا اعتمادٍ ولا نشرٍ ولا تفعيل (قرارُ المالك 2026-10-25).
 *
 * ═══ ما يُحرس ═══
 *
 *   - القوالبُ الستّة كلُّها في نافذة الإصدار تُختار ويُصدر منها، ولا يقول شيءٌ
 *     «غير منشور». والخمسةُ القائمة جاهزةٌ كما هي — بلا صفٍّ يُكتب فيها.
 *   - والإصدارُ بتأكيد المدير على بصمة ما عاينه: بلا تأكيدٍ لا مستند، وإن تغيّر
 *     القالبُ بعد المعاينة رُدّ.
 *   - حفظُ قالبٍ جديدٍ أو تعديلُه يجعله جاهزًا فورًا، إصدارًا جديدًا في سجلّه بمن
 *     حفظه — ولا يُنشئ مستندًا ولا يرسل شيئًا لأحد.
 *   - وما فيه خطأ (رسومٌ يذكرها نصُّه بلا رقم) يُردّ حفظُه بما هو، وقالبٌ قديمٌ ناقص
 *     لا يُصدر منه حتى يُصحَّح، ويُقال لماذا.
 *   - مسارا النشر والإيقاف أُزيلا، ولمدير المنصّة وحده التحريرُ والمعاينةُ والإصدار.
 *   - وبياناتُ مقدّم الخدمة (السجلّ والعنوان) لا تمنع إصدارًا.
 *   - الرسومُ الثابتة هي المبلغ ويذكرها النصّ، و«يبدأ من» حدٌّ أدنى. وتوقيعُ
 *     اتفاقيةٍ لا يكتب فاتورةً ولا دفعةً ولا قيدًا.
 *   - والموقَّعُ قبل تعديل قالبه بنصّه وإصداره وبصمته.
 */
class ASavedAgreementIsIssuedWithoutPublishingTest extends TestCase
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
        return PlatformDocumentTemplate::where('seed_key', $slug)->firstOrFail();
    }

    /** كما تفعل نافذةُ الإصدار بعد المعاينة: على بصمة الإصدار الذي عاينه المدير */
    private function issueRequest(PlatformDocumentTemplate $t, array $data = [])
    {
        return $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $t->id, 'template_hash' => $t->fresh()->contentHash(),
            'business_id' => $this->shop->id, 'amount' => $t->default_amount, 'cycle' => 'yearly',
        ]);
    }

    private function save(PlatformDocumentTemplate $t, array $over = [])
    {
        return $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), $over + [
            'name' => $t->name, 'service' => $t->service, 'title' => $t->title, 'title_en' => $t->title_en,
            'includes_plan' => $t->includes_plan, 'includes_amount' => $t->includes_amount,
            'default_amount' => $t->default_amount, 'default_amount_label' => $t->default_amount_label, 'amount_rule' => $t->amount_rule,
        ] + ($t->form ? ['form' => $t->form] : ['terms' => $t->terms]));
    }

    /** صفوفُ القوالب كما هي في القاعدة — ليُرى أنّ الإصدارَ لا يكتب فيها شيئًا */
    private function templateRows(): array
    {
        return DB::table('platform_document_templates')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_every_template_is_offered_and_issued_without_publishing_or_writing_to_it(): void
    {
        // كما على الإنتاج: الخمسةُ معطّلةٌ لم تُعتمد، والقصيرُ قائمٌ قبل الاعتماد
        $this->assertSame(5, PlatformDocumentTemplate::whereNotNull('form')->where('active', false)->whereNull('approved_hash')->count());
        $rows = $this->templateRows();

        $this->actingAs($this->super)->get(route('super-admin.documents.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('templates', 6)
                ->where('templates', fn ($list) => collect($list)->every(fn ($t) => $t['problems'] === [] && ! array_key_exists('published', $t)
                    && $t['hash'] === PlatformDocumentTemplate::find($t['id'])->contentHash())));

        foreach (PlatformDocumentTemplate::orderBy('id')->get() as $t) {
            $this->assertTrue($t->issuable(), "القالب {$t->id} جاهز");
            $this->issueRequest($t)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($t->id, (int) PlatformDocument::latest('id')->firstOrFail()->template_id);
        }
        $this->assertSame(6, PlatformDocument::count());
        $this->assertSame([1], PlatformDocument::distinct()->pluck('template_version')->all());
        $this->assertEquals($rows, $this->templateRows(), 'الإصدارُ لا ينشر قالبًا ولا يفعّله ولا يكتب فيه');
    }

    public function test_issuing_still_waits_for_the_admin_to_confirm_what_was_previewed(): void
    {
        $t = $this->template('whatsapp-integration');

        // بلا تأكيد: لا مستند
        $this->issueRequest($t, ['template_hash' => ''])->assertSessionHasErrors(['template_hash' => 'عاين المستند ثمّ أكّد إصداره.']);
        // وتأكيدٌ على إصدارٍ تغيّر بعد المعاينة يُردّ
        $this->issueRequest($t, ['template_hash' => str_repeat('a', 64)])
            ->assertSessionHasErrors(['document' => 'تغيّر القالب منذ عاينت المستند — راجعه من جديد ثمّ أكّد إصداره.']);
        $this->assertSame(0, PlatformDocument::count());

        $this->issueRequest($t)->assertSessionHasNoErrors();
        $this->assertSame(1, PlatformDocument::count());
    }

    public function test_saving_a_new_template_makes_it_ready_records_who_saved_it_and_sends_nothing(): void
    {
        $this->completeProvider();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake();

        $this->actingAs($this->super)->post(route('super-admin.documents.templates.store'), [
            'name' => 'تطبيق خاص', 'title' => 'اتفاقية تطبيق خاص', 'terms' => [['h' => 'التسليم:', 'b' => 'خلال ثلاثين يومًا.']],
            'includes_plan' => false, 'includes_amount' => true, 'default_amount' => '100', 'amount_rule' => 'fixed',
        ])->assertSessionHasNoErrors()->assertSessionHas('toast.msg', 'أُضيف القالب — يُصدر منه الآن');

        $t = PlatformDocumentTemplate::where('name', 'تطبيق خاص')->firstOrFail();
        $this->assertTrue($t->issuable(), 'يُصدر منه فور حفظه');
        $version = $t->versions()->firstOrFail();
        $this->assertSame([1, 'مدير المنصة', $this->super->id, $t->contentHash()], [$version->version, $version->saved_by_name, (int) $version->saved_by, $version->content_hash]);
        $this->assertSame(0, PlatformDocument::count(), 'الحفظُ لا يُصدر مستندًا');
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->assertFalse(ActivityLog::where('description', 'like', '%شارك%')->exists());

        $this->issueRequest($t, ['client' => ['phone' => '+96891234567']])->assertSessionHasNoErrors();
        $this->assertSame('100.000', (string) PlatformDocument::latest('id')->firstOrFail()->amount);
    }

    public function test_editing_a_text_is_a_new_version_ready_at_once_and_what_was_signed_stays(): void
    {
        $this->completeProvider();
        $t = $this->subscriptionContract();
        $this->issueRequest($t)->assertSessionHasNoErrors();
        $first = PlatformDocument::latest('id')->firstOrFail();
        auth()->logout();
        $this->post(route('documents.sign.submit', $first->token), [
            'agree' => '1', 'signature' => $this->signature(), 'client' => ['cr' => '1234567', 'civil' => '98765432'],
        ]);
        $first = $first->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $first->status);
        $signed = (array) DB::table('platform_documents')->where('id', $first->id)->first();

        $terms = $t->terms;
        $terms[0]['b'] = 'نصٌّ جديدٌ للبند الأوّل.';
        $this->save($t, ['terms' => $terms, 'title' => 'عقد اشتراك معدَّل'])->assertSessionHasNoErrors()
            ->assertSessionHas('toast.msg', 'حُفظ الإصدار 2 — يُصدر منه الآن. المستندات التي صدرت لا تتغيّر');
        $t = $t->fresh();
        $this->assertSame(2, $t->version);
        $this->assertTrue($t->issuable(), 'الإصدارُ الجديد يُصدر منه فورًا');
        $this->assertSame(2, (int) $t->versions()->max('version'));
        $this->assertSame('مدير المنصة', $t->versions()->where('version', 2)->firstOrFail()->saved_by_name);

        $this->issueRequest($t)->assertSessionHasNoErrors();
        $next = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame([2, 'عقد اشتراك معدَّل', 'نصٌّ جديدٌ للبند الأوّل.'], [$next->template_version, $next->title, $next->terms[0]['b']]);

        // والموقَّعُ قبله كما وُقِّع: صفُّه كلُّه وبصمتُه وورقتُه
        $this->assertEquals($signed, (array) DB::table('platform_documents')->where('id', $first->id)->first());
        $this->assertSame([1, 'عقد اشتراك'], [$first->fresh()->template_version, $first->fresh()->title]);
        $this->assertTrue(PlatformDocuments::intact($first->fresh()));
        $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $first->id))->assertOk();
    }

    public function test_a_broken_template_is_refused_with_its_reason_and_can_be_fixed(): void
    {
        $t = $this->template('store-setup');

        // نصٌّ يذكر رسومه بلا رقم: لا يُحفظ، والقالبُ كما كان
        $this->save($t, ['default_amount' => null, 'amount_rule' => null])->assertSessionHasErrors(['default_amount' => 'اكتب رسوم القالب — نصّه يذكرها.']);
        $this->save($t, ['default_amount' => null])->assertSessionHasErrors(['default_amount' => 'اكتب الرسوم — قاعدة المبلغ تحتاجها.']);
        $this->assertSame('15.000', (string) $t->fresh()->default_amount);

        // وقالبٌ قديمٌ ناقصٌ في القاعدة: يُختار، ويُقال ما فيه، ولا يُصدر منه
        DB::table('platform_document_templates')->where('id', $t->id)->update(['default_amount' => null]);
        $this->actingAs($this->super)->get(route('super-admin.documents.index'))
            ->assertInertia(fn ($p) => $p->where('templates', fn ($list) => collect($list)->firstWhere('id', $t->id)['problems'] === ['اكتب رسوم القالب — نصّه يذكرها.']));
        $this->actingAs($this->super)->get(route('super-admin.documents.templates'))
            ->assertInertia(fn ($p) => $p->where('templates', fn ($list) => collect($list)->firstWhere('id', $t->id)['problems'] === ['اكتب رسوم القالب — نصّه يذكرها.']));
        $this->issueRequest($t)->assertSessionHasErrors(['document' => 'لا يُصدر من هذا القالب حتى يُصحَّح: اكتب رسوم القالب — نصّه يذكرها.']);
        $this->assertSame(0, PlatformDocument::count());

        // ويُصحَّح من شاشته فيُصدر منه
        $this->save($t->fresh(), ['default_amount' => '15'])->assertSessionHasNoErrors();
        $this->assertTrue($t->fresh()->issuable());
        $this->issueRequest($t)->assertSessionHasNoErrors();
        $this->assertSame(1, PlatformDocument::count());
    }

    /**
     * وبياناتُ مقدّم الخدمة لا تقيّد المالك — قرارُه (2026-10-25).
     *
     * بلا سجلٍّ ولا عنوان يُصدر من القالب، ولا يُطبع في العقد سطرٌ فارغ ولا رقمٌ
     * يُخترع. وما يُكتب بعدها يظهر في المستندات التالية وحدها.
     */
    public function test_the_providers_cr_and_address_never_block_issuing(): void
    {
        $t = $this->template('subscription-agreement');
        Setting::updateOrCreate(['business_id' => null, 'key' => 'legal_trade_name'], ['value' => 'أبعاد']);

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

    public function test_publishing_is_gone_and_only_a_platform_admin_edits_previews_or_issues(): void
    {
        $this->completeProvider();
        $t = $this->template('store-setup');
        $this->assertFalse(Route::has('super-admin.documents.templates.publish'));
        $this->assertFalse(Route::has('super-admin.documents.templates.unpublish'));
        $this->actingAs($this->super)->post('/super-admin/documents/templates/'.$t->id.'/publish', ['hash' => $t->contentHash()])->assertNotFound();

        $merchant = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $calls = [
            ['put', route('super-admin.documents.templates.update', $t->id), ['name' => 'X', 'title' => 'X', 'form' => $t->form, 'includes_amount' => true, 'default_amount' => '15', 'amount_rule' => 'fixed']],
            ['post', route('super-admin.documents.templates.store'), ['name' => 'X', 'title' => 'X', 'terms' => [['h' => '', 'b' => 'X']]]],
            ['get', route('super-admin.documents.templates.preview', $t->id), []],
            ['get', route('super-admin.documents.templates.preview-pdf', $t->id), []],
            ['post', route('super-admin.documents.store'), ['template_id' => $t->id, 'template_hash' => $t->contentHash(), 'business_id' => $this->shop->id]],
        ];

        foreach ($calls as [$verb, $url, $body]) {
            $this->actingAs($merchant)->{$verb}($url, $body)->assertForbidden();
        }
        auth()->logout();
        foreach ($calls as [$verb, $url, $body]) {
            $this->{$verb}($url, $body)->assertRedirect(route('login'));
        }
        $this->assertSame([1, 'نموذج طلب واتفاقية خدمة تجهيز النظام داخل المتجر'], [$t->fresh()->version, $t->fresh()->name]);
        $this->assertSame(0, PlatformDocument::count());
        $this->assertSame(0, PlatformDocumentTemplate::where('name', 'X')->count());
    }

    public function test_a_fixed_fee_is_the_amount_and_the_text_says_it(): void
    {
        $this->completeProvider();
        $t = $this->template('whatsapp-integration');

        $this->issueRequest($t, ['amount' => '20'])->assertSessionHasErrors(['document' => 'رسوم هذه الخدمة ثابتة: 25.000 ر.ع كما في القالب. لتغييرها عدّل القالب.']);
        // وبلوحةٍ عربيّة هو الرقمُ نفسُه، وبلا رقمٍ تُؤخذ رسومُ القالب
        $this->issueRequest($t, ['amount' => '٢٥'])->assertSessionHasNoErrors();
        $this->issueRequest($t, ['amount' => ''])->assertSessionHasNoErrors();
        $old = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('25.000', (string) $old->amount);
        $this->assertStringContainsString('رسوم الربط 25.000 ر.ع', json_encode($old->form, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('تشمل رسوم الـ 25 ر.ع', json_encode($old->form, JSON_UNESCAPED_UNICODE));

        // رسومٌ جديدة من اللوحة: إصدارٌ يُصدر منه فورًا، يقولها النصُّ والمبلغُ معًا — والقديمُ بسعره
        $this->save($t, ['default_amount' => '٢٧٫٥٠٠'])->assertSessionHasNoErrors();
        $t = $t->fresh();
        $this->assertSame('27.500', (string) $t->default_amount);
        $this->issueRequest($t, ['amount' => ''])->assertSessionHasNoErrors();
        $new = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('27.500', (string) $new->amount);
        $this->assertSame('27.500 ر.ع تُدفع مرة واحدة', $new->form['highlights'][0]['text']);
        $this->assertStringContainsString('تشمل رسوم الـ 27.5 ر.ع', json_encode($new->form, JSON_UNESCAPED_UNICODE));
        $this->assertSame('25.000', (string) $old->fresh()->amount, 'سعرُ العقد التاريخيّ لا يتغيّر');
        $this->assertSame('25.000 ر.ع تُدفع مرة واحدة', $old->fresh()->form['highlights'][0]['text']);

        // والإقرارُ نفسُه يذكر رسوم القالب في اتفاقية الدفع
        $this->issueRequest($this->template('payment-integration'), ['amount' => ''])->assertSessionHasNoErrors();
        $this->assertStringContainsString('البالغة 12 ريالًا عُمانيًا', PlatformDocument::latest('id')->firstOrFail()->form['consent']);
    }

    public function test_a_starting_price_is_a_floor_and_three_decimals_are_kept(): void
    {
        $this->completeProvider();
        $t = $this->template('website-customization');

        $this->issueRequest($t, ['amount' => '59.999'])->assertSessionHasErrors(['document' => 'سعر هذه الخدمة يبدأ من 60.000 ر.ع كما في القالب.']);
        $this->issueRequest($t, ['amount' => '75.1234'])->assertSessionHasErrors('amount');
        $this->issueRequest($t, ['amount' => '٧٥٫٢٥٠'])->assertSessionHasNoErrors();
        $doc = PlatformDocument::latest('id')->firstOrFail();
        $this->assertSame('75.250', (string) $doc->amount);
        $this->assertStringContainsString('يبدأ من 60 ر.ع', json_encode($doc->form, JSON_UNESCAPED_UNICODE));

        // ورسومُ القالب بثلاث خاناتٍ لا أكثر — والعربيّةُ تُقرأ
        $this->save($t, ['default_amount' => '60.0001'])->assertSessionHasErrors('default_amount');
        $this->save($t, ['default_amount' => 'ستون'])->assertSessionHasErrors('default_amount');
    }

    public function test_signing_an_agreement_writes_no_invoice_payment_or_entry(): void
    {
        $this->completeProvider();
        $this->issueRequest($this->template('subscription-agreement'))->assertSessionHasNoErrors();
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

    public function test_a_new_business_or_a_prospect_takes_an_agreement_without_code(): void
    {
        $this->completeProvider();
        $t = $this->template('store-setup');
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
        $this->assertTrue(PlatformDocumentTemplate::find($short->id)->issuable());
        $this->assertSame(5, PlatformDocumentTemplate::whereNotNull('form')->get()->filter->issuable()->count(), 'والخمسةُ جاهزةٌ كما زُرعت');
    }

    /**
     * والهجرةُ لا تمسّ عقدًا موقَّعًا ولا ملفّيه ولا اشتراكَ تاجر — ذهابًا وإيابًا.
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
