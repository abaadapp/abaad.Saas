<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\User;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AbaadSubscriptionContract;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * اتفاقيةٌ تُحرَّر كلُّها من الشاشة — ولا يتغيّر بتحريرها مستندٌ صدر.
 *
 * ═══ ما يُحرس ═══
 *
 *   - أقسامُها ونصوصُها وحقولُها وقوائمُها وجداولُها ورسومُها تُعدَّل وتُضاف وتُحذف
 *     وتُرتَّب، وتُحفظ إصدارًا جديدًا غيرَ منشور، والرموزُ (`{fee}`) باقيةٌ في نصّها.
 *   - ونموذجٌ مكسور (مفتاحٌ مكرّر أو غيرُ صالح، جدولُ اختيارٍ بلا خيارات، إقرارٌ
 *     فارغ) يُردّ قبل الحفظ، والقالبُ كما كان.
 *   - والإصدارُ الجديد يُوقَّع بحقوله الجديدة: يُطلب إلزاميُّها، وتُحفظ إجاباتُها،
 *     وتُطبع ورقتُه، وبصمتُه سليمة.
 *   - وما صدر ووُقِّع قبله لا يتغيّر: صفُّه ورقمُه ورابطُه وصورةُ توقيعه وورقتُه وبصمتُه.
 *   - والمعاينةُ صفحةُ العميل وورقتُه من الإصدار الحاليّ، بلا مستندٍ يُكتب ولا توقيعٍ يُرسل.
 *   - والبذورُ لا تستبدل ما عدّله المدير ولا تزرع بجانبه ما أعاد تسميتَه — ولو قبل المفتاح.
 *   - والرجوعُ عن الهجرة يُرفض ما دام في سجلّ الإصدارات ما لا يُعاد بناؤه من القوالب.
 *   - ولا يمسّ شيءٌ من ذلك مالًا ولا اشتراكًا.
 */
class AnAgreementIsEditedWholeFromTheScreenTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    /** ما يكمله عميلُ واتساب من حقوله الإلزاميّة */
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
        $this->shop = Business::create(['name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'status' => 'نشط', 'plan_id' => $plan->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $this->super = User::create(['name' => 'مدير المنصة', 'email' => 'super@abaadapp.test', 'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط']);
        DocumentForms::seedTemplates();
        $this->subscriptionContract();
        $this->completeProvider();
    }

    private function whatsapp(): PlatformDocumentTemplate
    {
        return PlatformDocumentTemplate::where('seed_key', 'whatsapp-integration')->firstOrFail();
    }

    private function save(PlatformDocumentTemplate $t, array $form, array $over = [])
    {
        return $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $t->id), $over + [
            'name' => $t->name, 'service' => $t->service, 'title' => $t->title, 'title_en' => $t->title_en,
            'includes_plan' => $t->includes_plan, 'includes_amount' => $t->includes_amount,
            'default_amount' => $t->default_amount, 'default_amount_label' => $t->default_amount_label, 'amount_rule' => $t->amount_rule,
            'form' => $form,
        ]);
    }

    private function publishAndIssue(PlatformDocumentTemplate $t): PlatformDocument
    {
        $t = $t->fresh();
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), [
            'template_id' => $t->id, 'business_id' => $this->shop->id, 'amount' => $t->default_amount,
            'publish' => true, 'template_hash' => $t->contentHash(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        auth()->logout();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function send(PlatformDocument $doc, array $answers)
    {
        return $this->postJson(route('documents.sign.submit', $doc->token), ['answers' => $answers, 'agree' => '1', 'signature' => $this->signature()]);
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

    /** النموذجُ بعد تحريرٍ يمسّ كلَّ نوع: عنوانٌ وحذفٌ وترتيبٌ وقسمٌ جديدٌ بحقلٍ وقائمةٍ وسؤالٍ وجدول */
    private function edited(array $form): array
    {
        $form['sections'][0]['title'] = 'بيانات العميل الأساسية';
        // حقلُ السجلّ التجاريّ يصير إلزاميًّا، والملاحظةُ الأخيرة في قسم المتطلبات تُحذف
        $form['sections'][0]['parts'][4]['req'] = true;
        array_pop($form['sections'][2]['parts']);
        // وقسما السياسة والملكية يتبادلان موضعيهما
        [$form['sections'][5], $form['sections'][6]] = [$form['sections'][6], $form['sections'][5]];
        // وعناوينُ جدول القوالب تُعدَّل
        $form['sections'][4]['parts'][0]['head'][1] = 'اسم القالب المطلوب';
        $form['highlights'][1]['text'] = 'ربط الرقم واختبار رسالة.';
        $form['consent'] = 'أقر بأنني اطلعت على تفاصيل خدمة ربط واتساب ورسومها البالغة {fee_short} ريالًا عُمانيًا وأوافق عليها.';
        $form['sections'][] = [
            'title' => 'بيانات إضافية للربط',
            'after' => false,
            'parts' => [
                ['kind' => 'p', 'text' => 'نحتاج هذه البيانات قبل موعد التنفيذ.'],
                ['kind' => 'field', 'by' => 'client', 'k' => 'metaId', 'label' => 'معرّف حساب Meta', 'req' => true, 'type' => 'text', 'dir' => 'ltr', 'ph' => ''],
                ['kind' => 'checks', 'by' => 'client', 'k' => 'hours', 'head' => 'أوقات التواصل المناسبة', 'items' => ['صباحًا', 'مساءً']],
                ['kind' => 'yesno', 'by' => 'client', 'k' => 'hasAdmin', 'label' => 'هل لديك صلاحية مدير على الحساب؟'],
                ['kind' => 'table', 'by' => 'client', 'k' => 'numbers', 'head' => ['الرقم', 'الاستخدام', 'الأولوية'], 'types' => ['text', 'pick'], 'ph' => ['مبيعات', ''], 'rows' => ['الرقم الأول', 'الرقم الثاني'], 'picks' => ['عالية', 'عادية']],
            ],
        ];

        return $form;
    }

    public function test_every_part_of_an_agreement_is_edited_and_saved_as_a_new_version(): void
    {
        $t = $this->whatsapp();
        $original = $t->form;
        $this->save($t, $this->edited($original))->assertSessionHasNoErrors();

        $t = $t->fresh();
        $this->assertSame(2, $t->version);
        $this->assertFalse($t->issuable(), 'الإصدارُ الجديد غيرُ منشور');
        $this->assertSame('بيانات العميل الأساسية', $t->form['sections'][0]['title']);
        $this->assertTrue($t->form['sections'][0]['parts'][4]['req']);
        $this->assertCount(count($original['sections'][2]['parts']) - 1, $t->form['sections'][2]['parts']);
        $this->assertSame($original['sections'][6]['title'], $t->form['sections'][5]['title']);
        $this->assertSame('اسم القالب المطلوب', $t->form['sections'][4]['parts'][0]['head'][1]);
        $added = collect($t->form['sections'])->last();
        $this->assertSame('بيانات إضافية للربط', $added['title']);
        $this->assertSame(['عالية', 'عادية'], $added['parts'][4]['picks']);
        // والرموزُ في نصّ القالب كما كُتبت — تُملأ ساعةَ الإصدار
        $this->assertStringContainsString('{fee}', json_encode($t->form, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('{fee_short}', $t->form['consent']);

        // والإصداران في سجلّه: الأوّلُ بنموذجه كما كان
        $this->assertSame([2, 1], $t->versions()->pluck('version')->all());
        $this->assertSame($original, $t->versions()->where('version', 1)->firstOrFail()->content['form']);
        $this->assertSame('مدير المنصة', $t->versions()->where('version', 2)->firstOrFail()->saved_by_name);

        // والشاشةُ تعرض النموذجَ برموزه للمحرّر
        $this->actingAs($this->super)->get(route('super-admin.documents.templates'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('templates', fn ($list) => collect($list)->firstWhere('id', $t->id)['form']['consent'] === $t->form['consent']
                && collect($list)->firstWhere('id', $t->id)['status'] === 'draft'
                && count(collect($list)->firstWhere('id', $t->id)['versions']) === 2));
    }

    public function test_saving_an_unchanged_form_is_not_a_new_version(): void
    {
        $t = $this->approveTemplate($this->whatsapp());
        $this->save($t, $t->form)->assertSessionHasNoErrors();
        $this->assertSame(1, $t->fresh()->version);
        $this->assertTrue($t->fresh()->issuable(), 'ما زال منشورًا');

        // ونموذجٌ حُفظ بترتيب مفاتيحٍ آخر يبقى كما حُفظ — لا يصير إصدارًا بترتيبه
        $reordered = $t->form;
        $reordered['sections'] = array_map(fn ($sec) => array_reverse($sec, true), $reordered['sections']);
        DB::table('platform_document_templates')->where('id', $t->id)->update(['form' => json_encode(array_reverse($reordered, true), JSON_UNESCAPED_UNICODE)]);
        $t = $this->approveTemplate($t->fresh());
        $stored = DB::table('platform_document_templates')->where('id', $t->id)->value('form');
        $this->save($t, $t->form)->assertSessionHasNoErrors();
        $this->assertSame(1, $t->fresh()->version);
        $this->assertTrue($t->fresh()->issuable());
        $this->assertSame($stored, DB::table('platform_document_templates')->where('id', $t->id)->value('form'));
    }

    public function test_a_broken_form_is_refused_before_it_is_saved(): void
    {
        $t = $this->whatsapp();
        $form = $t->form;
        $broken = [
            'مفتاحٌ مكرّر' => fn ($f) => [...$f, 'sections' => [...$f['sections'], ['title' => 'قسم', 'after' => false, 'parts' => [['kind' => 'field', 'by' => 'client', 'k' => 'org', 'label' => 'مكرّر', 'type' => 'text']]]]],
            'مفتاحٌ غيرُ صالح' => fn ($f) => [...$f, 'sections' => [...$f['sections'], ['title' => 'قسم', 'after' => false, 'parts' => [['kind' => 'yesno', 'by' => 'client', 'k' => '1 مفتاح', 'label' => 'سؤال']]]]],
            'جدولُ اختيارٍ بلا خيارات' => fn ($f) => [...$f, 'sections' => [...$f['sections'], ['title' => 'قسم', 'after' => false, 'parts' => [['kind' => 'table', 'by' => 'client', 'k' => 'tb', 'head' => ['', 'أ'], 'types' => ['pick'], 'rows' => ['صف'], 'picks' => []]]]]],
            'إقرارٌ فارغ' => fn ($f) => [...$f, 'consent' => '  '],
            'نوعٌ غيرُ معروف' => fn ($f) => [...$f, 'sections' => [...$f['sections'], ['title' => 'قسم', 'after' => false, 'parts' => [['kind' => 'script', 'text' => 'x']]]]],
            'بلا أقسام' => fn ($f) => [...$f, 'sections' => []],
            'حقلٌ بلا اسم' => fn ($f) => [...$f, 'sections' => [...$f['sections'], ['title' => 'قسم', 'after' => false, 'parts' => [['kind' => 'field', 'by' => 'client', 'k' => 'nameless', 'label' => '', 'type' => 'text']]]]],
        ];
        foreach ($broken as $what => $break) {
            $this->save($t, $break($form))->assertSessionHasErrors('form', $what);
        }

        $t = $t->fresh();
        $this->assertSame(1, $t->version);
        $this->assertSame($form, $t->form, 'القالبُ كما كان');
        $this->assertSame(1, $t->versions()->count());
    }

    public function test_the_edited_agreement_is_signed_with_its_new_fields_and_printed(): void
    {
        $t = $this->whatsapp();
        $this->save($t, $this->edited($t->form))->assertSessionHasNoErrors();
        $doc = $this->publishAndIssue($t);
        $this->assertSame(2, $doc->template_version);
        $this->assertStringContainsString('البالغة 25 ريالًا عُمانيًا', $doc->form['consent'], 'الرمزُ مملوءٌ في المستند');

        $page = $this->get(route('documents.sign', $doc->token))->assertOk();
        $page->assertSee('بيانات إضافية للربط')->assertSee('معرّف حساب Meta')->assertSee('اسم القالب المطلوب')->assertSee('هل لديك صلاحية مدير على الحساب؟');

        // إلزاميُّه الجديد يُطلب: معرّفُ Meta والسجلُّ التجاريّ
        $missing = $this->send($doc, self::CLIENT)->assertStatus(422)->json('message');
        $this->assertStringContainsString('معرّف حساب Meta', (string) $missing);
        $this->assertStringContainsString('السجل التجاري', (string) $missing);
        $this->assertSame(PlatformDocument::SENT, $doc->fresh()->status);

        $this->send($doc, self::CLIENT + [
            'cr' => '1234567', 'metaId' => '1029384756', 'hours' => [1], 'hasAdmin' => 'yes',
            'numbers' => [['+96891234567', 'عالية'], ['', 'غير موجود']],
        ])->assertOk();
        $doc = $doc->fresh();
        $this->assertSame(PlatformDocument::SIGNED, $doc->status);
        $this->assertSame('1029384756', $doc->answers['metaId']);
        $this->assertSame([1], $doc->answers['hours']);
        $this->assertSame('yes', $doc->answers['hasAdmin']);
        $this->assertSame([['+96891234567', 'عالية'], ['', '']], $doc->answers['numbers'], 'خيارٌ ليس في القائمة لا يُحفظ');
        $this->assertTrue(PlatformDocuments::intact($doc));

        $text = implode(' ', array_column(PdfText::pages($this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->getContent()), 'text'));
        $this->assertStringContainsString('1029384756', $text);
    }

    public function test_editing_and_publishing_never_touch_what_was_issued_or_signed(): void
    {
        $t = $this->whatsapp();
        $signed = $this->publishAndIssue($t);
        $this->send($signed, self::CLIENT)->assertOk();
        $sent = $this->publishAndIssue($t);
        $short = $this->subscriptionContract();
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), ['template_id' => $short->id, 'business_id' => $this->shop->id, 'cycle' => 'yearly'])->assertSessionHasNoErrors();
        auth()->logout();

        $rows = fn () => DB::table('platform_documents')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $files = fn () => collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($f) => [$f => hash('sha256', (string) Storage::disk('local')->get($f))])->all();
        $before = [$rows(), $files()];

        // تحريرٌ ونشرٌ وإصدارٌ جديد، وتحريرُ العقد القصير
        $this->save($t->fresh(), $this->edited($t->fresh()->form))->assertSessionHasNoErrors();
        $this->publishAndIssue($t);
        $this->actingAs($this->super)->put(route('super-admin.documents.templates.update', $short->id), [
            'name' => $short->name, 'service' => $short->service, 'title' => 'عقد اشتراك معدَّل', 'title_en' => $short->title_en,
            'terms' => $short->terms, 'includes_plan' => true, 'includes_amount' => false,
        ])->assertSessionHasNoErrors();

        $after = $rows();
        $this->assertSame($before[0], array_slice($after, 0, count($before[0])), 'صفوفُ المستندات الصادرة كما هي — نصُّها ورقمُها ورابطُها وصلاحيّتُه وبصمتُها');
        $this->assertSame($before[1], array_intersect_key($files(), $before[1]), 'صورةُ التوقيع والورقةُ المحفوظة كما هما');
        $this->assertTrue(PlatformDocuments::intact($signed->fresh()));
        $this->assertTrue($sent->fresh()->signable(), 'الرابطُ الذي أُرسل يبقى يعمل');
        $this->assertSame(1, $signed->fresh()->template_version);
    }

    public function test_the_preview_is_the_clients_page_and_paper_and_writes_nothing(): void
    {
        $t = $this->whatsapp();
        $this->save($t, $this->edited($t->form))->assertSessionHasNoErrors();
        $counts = fn () => [PlatformDocument::count(), ActivityLog::count(), DB::table('platform_document_template_versions')->count()];
        $before = $counts();

        $page = $this->actingAs($this->super)->get(route('super-admin.documents.templates.preview', $t->id))->assertOk();
        $page->assertSee('id="preview-banner"', false)->assertSee('بيانات إضافية للربط')->assertSee('معرّف حساب Meta')
            ->assertSee('25.000')->assertDontSee('{fee}')->assertDontSee('{fee_short}')
            ->assertDontSee(route('documents.sign.submit', str_repeat('0', 32)), false)->assertDontSee('fetch(form.action', false);
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));

        $pdf = $this->actingAs($this->super)->get(route('super-admin.documents.templates.preview-pdf', $t->id))->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('Meta', implode(' ', array_column(PdfText::pages($pdf->getContent()), 'text')));

        $this->assertSame($before, $counts(), 'المعاينةُ لا تكتب مستندًا ولا سجلًّا');
        $this->assertFalse($t->fresh()->issuable(), 'ولا تنشر');
    }

    public function test_seeding_again_neither_replaces_nor_duplicates_an_edited_template(): void
    {
        $t = $this->whatsapp();
        $this->save($t, $this->edited($t->form), ['name' => 'اتفاقية واتساب — معدّلة'])->assertSessionHasNoErrors();
        $count = PlatformDocumentTemplate::count();
        $edited = $t->fresh()->form;

        DocumentForms::seedTemplates();

        $this->assertSame($count, PlatformDocumentTemplate::count(), 'لا يُزرع بجانبه قالبٌ بالاسم القديم');
        $this->assertSame($edited, $t->fresh()->form, 'ولا يُستبدل نصُّه');
        $this->assertSame('اتفاقية واتساب — معدّلة', $t->fresh()->name);

        // وما يُزرع بعد سجلّ الإصدارات يدخله بإصداره الأوّل — لا يبدأ تاريخُه من الثاني
        DB::table('platform_document_templates')->where('seed_key', 'payment-integration')->delete();
        DocumentForms::seedTemplates();
        $seeded = PlatformDocumentTemplate::where('seed_key', 'payment-integration')->firstOrFail();
        $this->assertSame([1], $seeded->versions()->pluck('version')->all());
        $this->assertSame($seeded->contentHash(), $seeded->versions()->firstOrFail()->content_hash);
    }

    public function test_a_seed_renamed_before_it_had_a_key_is_still_known_and_never_seeded_twice(): void
    {
        $count = PlatformDocumentTemplate::count();
        $whatsapp = $this->whatsapp();
        $setup = PlatformDocumentTemplate::where('seed_key', 'store-setup')->firstOrFail();
        // كما كان قبل الهجرة: بلا مفتاح، وقد أعاد المديرُ تسميتَه على الكود القديم
        DB::table('platform_document_templates')->where('id', $whatsapp->id)->update(['seed_key' => null, 'name' => 'واتساب المتجر — اسمٌ جديد']);

        DocumentForms::keySeeds();
        $this->assertSame('whatsapp-integration', DB::table('platform_document_templates')->where('id', $whatsapp->id)->value('seed_key'));

        // قالبان لبذرةٍ واحدة لا يُختار أحدُهما — ولا يُزرع بجانبهما ثالث
        DB::table('platform_document_templates')->where('id', $setup->id)->update(['seed_key' => null, 'name' => 'تجهيز المتجر — أ']);
        DB::table('platform_document_templates')->insert(collect((array) DB::table('platform_document_templates')->find($setup->id))
            ->except('id')->merge(['name' => 'تجهيز المتجر — ب'])->all());
        DocumentForms::keySeeds();
        $this->assertNull(DB::table('platform_document_templates')->where('id', $setup->id)->value('seed_key'));

        DocumentForms::seedTemplates();
        $this->assertSame($count + 1, PlatformDocumentTemplate::count(), 'لا يُزرع قالبٌ أُعيدت تسميتُه بجانبه');
    }

    public function test_rolling_the_migration_back_never_erases_a_real_version_history(): void
    {
        $migration = require database_path('migrations/2026_10_28_090000_document_templates_keep_every_version.php');
        $refused = function () use ($migration): string {
            try {
                $migration->down();
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $this->fail('رُجع عن الهجرة وفي السجلّ ما لا يُعاد بناؤه');
        };
        $t = $this->whatsapp();
        $name = $t->name;

        // اسمٌ جديد وحده لا يصنع إصدارًا — لكنّ البذرة لا تعرفه بعد المفتاح
        $this->save($t, $t->form, ['name' => 'اتفاقية واتساب — معدّلة'])->assertSessionHasNoErrors();
        $this->assertSame(1, $t->fresh()->version);
        $message = $refused();
        $this->assertStringContainsString('أُعيدت تسميتُها: '.$t->id, $message);
        $this->assertStringNotContainsString('إصداراتُ القوالب', $message);

        $this->save($t->fresh(), $t->form, ['name' => $name])->assertSessionHasNoErrors();

        // قالبٌ بُني على الشاشة: نصُّه في صفّه، ومن حفظه في السجلّ وحده
        $this->actingAs($this->super)->post(route('super-admin.documents.templates.store'), [
            'name' => 'تطبيق خاص', 'service' => 'تطبيق', 'title' => 'طلب تطبيق خاص', 'includes_plan' => false, 'includes_amount' => false,
            'form' => ['intro' => null, 'highlights' => [], 'sections' => [['title' => 'الطلب', 'after' => false, 'parts' => [['kind' => 'p', 'text' => 'نصّ الطلب.']]]],
                'consent' => 'أقر بأنني اطلعت على النموذج.', 'agree' => 'أوافق.', 'footer' => null],
        ])->assertSessionHasNoErrors();
        $built = PlatformDocumentTemplate::where('name', 'تطبيق خاص')->firstOrFail();
        $this->assertStringContainsString('إصداراتُ القوالب: '.$built->id.')', $refused());

        $this->save($t->fresh(), $this->edited($t->form))->assertSessionHasNoErrors();
        $message = $refused();
        $this->assertStringContainsString('إصداراتُ القوالب: '.$t->id.', '.$built->id.')', $message);
        $this->assertStringContainsString('Revert PR', $message);

        $this->assertTrue(Schema::hasTable('platform_document_template_versions'));
        $this->assertTrue(Schema::hasColumn('platform_document_templates', 'seed_key'));
        $this->assertSame(2, (int) $t->versions()->max('version'), 'والإصدارُ المحفوظ باقٍ في سجلّه');
    }

    public function test_a_new_agreement_is_built_from_sections_on_the_screen(): void
    {
        $form = [
            'intro' => 'نموذج طلب تطبيق خاص.', 'highlights' => [['label' => 'الرسوم', 'text' => '{fee} ر.ع']],
            'sections' => [['title' => 'بيانات الطلب', 'after' => false, 'parts' => [
                ['kind' => 'field', 'by' => 'client', 'k' => 'appName', 'label' => 'اسم التطبيق', 'req' => true, 'type' => 'text', 'dir' => 'rtl', 'ph' => ''],
                ['kind' => 'amount', 'label' => 'رسوم التطبيق'],
            ]]],
            'consent' => 'أقر بأنني اطلعت على النموذج.', 'agree' => 'أوافق.', 'footer' => null,
        ];
        $this->actingAs($this->super)->post(route('super-admin.documents.templates.store'), [
            'name' => 'تطبيق خاص', 'service' => 'تطبيق', 'title' => 'طلب تطبيق خاص', 'includes_plan' => false, 'includes_amount' => true,
            'default_amount' => '150', 'amount_rule' => 'min', 'form' => $form,
        ])->assertSessionHasNoErrors();

        $t = PlatformDocumentTemplate::where('name', 'تطبيق خاص')->firstOrFail();
        $this->assertSame('بيانات الطلب', $t->form['sections'][0]['title']);
        $this->assertSame([], $t->terms);
        $this->assertFalse($t->issuable());
        $doc = $this->publishAndIssue($t);
        $this->assertSame('150.000 ر.ع', $doc->form['highlights'][0]['text']);
    }

    public function test_none_of_it_moves_money_or_a_subscription(): void
    {
        $tables = array_values(array_filter(['invoices', 'subscriptions', 'customer_invoices', 'customer_payments', 'journal_entries', 'journal_lines', 'store_payment_intents', 'payments'], fn ($t) => Schema::hasTable($t)));
        $before = [array_map(fn ($t) => DB::table($t)->count(), $tables), DB::table('businesses')->orderBy('id')->get(['id', 'plan_id', 'status', 'starts_at', 'ends_at'])->toArray()];

        $t = $this->whatsapp();
        $this->save($t, $this->edited($t->form))->assertSessionHasNoErrors();
        $doc = $this->publishAndIssue($t);
        $this->send($doc, self::CLIENT + ['cr' => '1234567', 'metaId' => '1'])->assertOk();

        $after = [array_map(fn ($t) => DB::table($t)->count(), $tables), DB::table('businesses')->orderBy('id')->get(['id', 'plan_id', 'status', 'starts_at', 'ends_at'])->toArray()];
        $this->assertEquals($before, $after);
    }
}
