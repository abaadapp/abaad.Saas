<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use App\Support\Ledger;
use App\Support\PurchaseOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * أمرُ الشراء نيّةٌ لا حدث — يقول كيف يُنوى السداد ولا يسدّد.
 *
 * ═══ لماذا هذا الملفّ ═══
 *
 * صار على شاشة أمر الشراء حقلٌ اسمه **«طريقة الدفع»**، مطلوبٌ، وفيه «نقدي»،
 * وتحته زرٌّ اسمه «إصدار». وهذا كلُّه يُقرأ دفعًا — ومن اختار «نقدي» ثمّ فتح
 * الصندوقَ في المالية فوجده كما هو يظنّ النظامَ معطوبًا.
 *
 * والصحيحُ أنّ دورةَ الشراء أربعُ محطّات، ولكلٍّ أثرُها:
 *
 *   أمرُ شراء        → لا مخزون، ولا ذمّة، ولا قيد
 *   استلامٌ يُعتمد    → البضاعةُ تدخل الرفّ — ولا ذمّة
 *   سندُ مورّدٍ يُعتمد → الذمّةُ تنشأ — ولا مال يخرج
 *   سدادٌ             → المالُ يخرج والذمّةُ تنقص
 *
 * فهنا تُحرَس المحطّةُ الأولى وحدها: **أنّ اختيار أيّ وسيلةٍ من الأربع لا
 * يُحرّك صندوقًا ولا بنكًا ولا ذمّةً ولا رفًّا**. وحقلٌ يُضاف إلى شاشةٍ
 * ماليّةٍ بلا حارسٍ يُثبت أنّه لا يفعل شيئًا هو أخطرُ ما يُضاف.
 */
class APurchaseOrderIsAnIntentionNotAnEventTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private Supplier $supplier;

    private Product $product;

    private User $owner;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'أبعاد للورود ش.م.م', 'type' => 'محل ورود', 'status' => 'نشط',
            'city' => 'مسقط', 'phone' => '96871141624',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->seller = User::create([
            'business_id' => $this->business->id, 'name' => 'البائع', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->supplier = Supplier::create([
            'business_id' => $this->business->id, 'name' => 'مشتل الباطنة',
            'phone' => '96890000000',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة حمراء',
            'price' => 2, 'cost' => 1, 'quantity' => 40,
        ]);

        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '0'],
        );
    }

    /* ═══════════════════ أدواتٌ ═══════════════════ */

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'نقدي',
            'ordered_at' => '2026-09-10',
            'expected_delivery_at' => '2026-09-24',
            'items' => [[
                'product_id' => $this->product->id, 'name' => 'وردة حمراء',
                'cost' => 5, 'quantity' => 10,
            ]],
        ], $extra);
    }

    private function previewHtml(array $override = []): string
    {
        $res = $this->actingAs($this->owner)
            ->postJson(route('admin.purchases.preview'), $this->payload($override));

        $res->assertOk();

        return (string) $res->json('html');
    }

    /** رصيدُ الدفتر كلِّه لقطةً — لا حسابٌ واحد يُنظر إليه */
    private function books(): array
    {
        return [
            'cash' => Ledger::balance($this->business->id, 'cash'),
            'bank' => Ledger::balance($this->business->id, 'bank'),
            'payable' => Ledger::balance($this->business->id, 'payable'),
            'inventory' => Ledger::balance($this->business->id, 'inventory'),
            'entries' => DB::table('journal_entries')->count(),
            'stock' => (float) DB::table('products')->where('id', $this->product->id)->value('quantity'),
        ];
    }

    /* ═══════════════ وسيلةٌ تُقال ولا تُنفَّذ ═══════════════ */

    /** ولا أمرَ بلا وسيلةٍ تُختار — و«آجل» اختيارٌ يُقال لا صمتٌ يُفسَّر */
    public function test_the_payment_method_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['payment_method']);

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $payload)
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, PurchaseOrder::count());
    }

    /** والأربعُ تُقبل — لا ثلاثٌ ولا خمس */
    public function test_the_four_methods_are_the_ones_accepted(): void
    {
        $this->assertSame(['نقدي', 'تحويل بنكي', 'بطاقة', 'آجل'], PurchaseOrders::METHODS);

        foreach (PurchaseOrders::METHODS as $i => $method) {
            $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
                'payment_method' => $method,
                'form_token' => 'tok-'.$i,
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(
            PurchaseOrders::METHODS,
            DB::table('purchase_orders')->orderBy('id')->pluck('payment_method')->all(),
        );
    }

    /** ووسيلةٌ مخترَعة تُردّ — ولا تُكتب على الورقة */
    public function test_an_invented_method_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'payment_method' => 'عملة رقمية',
        ]))->assertSessionHasErrors('payment_method');

        $this->assertSame(0, PurchaseOrder::count());
    }

    /**
     * ═══ وأثقلُ دعوى في هذا الملفّ ═══
     *
     * كلُّ وسيلةٍ من الأربع، مسودّةً وصادرة: **الدفترُ كما هو، والرفُّ كما هو**.
     *
     * ولا يُنظر إلى حسابٍ واحد: تُقرأ الأربعةُ وعددُ القيود والكميّةُ معًا.
     * حارسٌ يفحص الصندوقَ وحده يمرّ على قيدٍ كُتب في البنك.
     */
    public function test_no_method_on_a_purchase_order_moves_money_or_stock(): void
    {
        $before = $this->books();

        $n = 0;
        foreach (PurchaseOrders::METHODS as $method) {
            foreach ([true, false] as $draft) {
                $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
                    'payment_method' => $method,
                    'draft' => $draft,
                    'form_token' => 'tok-'.(++$n),
                ]))->assertSessionHasNoErrors();

                $this->assertSame(
                    $before,
                    $this->books(),
                    "«{$method}» ".($draft ? 'مسودّةً' : 'صادرةً').' حرّكت الدفتر أو الرفّ',
                );
            }
        }

        // وثمانيةُ أوامرَ كُتبت فعلًا — لا حارسٌ يقيس على قاعدةٍ فارغة
        $this->assertSame(8, PurchaseOrder::count());
    }

    /** والصفرُ يبقى صفرًا: لا قيدَ واحد يُكتب من هذا الباب */
    public function test_the_purchase_order_door_writes_no_journal_entry(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('journal_lines')->count());
    }

    /* ═══════════════ ما يُحفظ على الورقة ═══════════════ */

    /** وخطّةُ السداد تُحفظ كما اختِيرت — وإلّا فحقلٌ يُملأ ولا يُقرأ */
    public function test_the_payment_plan_is_stored_on_the_order(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'payment_method' => 'تحويل بنكي',
            'payment_terms_days' => 30,
            'payment_reference' => 'AGR-4471',
            'notes' => 'يرجى التأكيد على توفر الأصناف قبل الشحن.',
            'internal_notes' => 'هذا المورّد يتأخّر في المواسم',
        ]))->assertSessionHasNoErrors();

        $row = DB::table('purchase_orders')->latest('id')->first();

        $this->assertSame('تحويل بنكي', $row->payment_method);
        $this->assertSame(30, (int) $row->payment_terms_days);
        $this->assertSame('AGR-4471', $row->payment_reference);
        $this->assertSame('هذا المورّد يتأخّر في المواسم', $row->internal_notes);
    }

    /**
     * والملاحظةُ الداخليّة لا تبلغ ورقةَ المورّد.
     *
     * كان الحقلُ واحدًا يُطبع على الورقة ونصُّه الإرشاديُّ يقول «داخلية» —
     * فمن كتب لنفسه «هذا المورّد يتأخّر» كتبها حيث يقرؤها المورّد.
     */
    public function test_internal_notes_never_reach_the_suppliers_paper(): void
    {
        /*
         * ═══ وتُفحص الورقةُ **المحفوظة** لا المعاينة وحدها ═══
         *
         * `preview` لا تقبل `internal_notes` أصلًا، فحارسٌ يفحصها هناك يقول
         * «سليم» أبدًا — ولو طُبعت في الـPDF. وقد نجت منه طفرةٌ ألحقت
         * الملاحظةَ الداخليّة بملاحظة المورّد. فيُكتب أمرٌ يحملها ثمّ تُبنى
         * ورقتُه ببانيها هو.
         */
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'notes' => 'ملاحظةٌ للمورّد',
            'internal_notes' => 'سرٌّ لا يُطبع',
        ]))->assertSessionHasNoErrors();

        $po = PurchaseOrder::with('items', 'supplier')->latest('id')->firstOrFail();

        $this->assertSame('سرٌّ لا يُطبع', $po->internal_notes, 'الملاحظةُ الداخليّة لم تُحفظ أصلًا');

        $printed = DocumentRenderer::generic(
            $this->business->id,
            'purchase',
            DocumentPaper::forPurchase($po),
        );

        $this->assertStringContainsString('ملاحظةٌ للمورّد', $printed);
        $this->assertStringNotContainsString('سرٌّ لا يُطبع', $printed, 'الملاحظةُ الداخليّة تُطبع على ورقة المورّد');

        // والمعاينةُ لا تحملها كذلك — والحقلُ لا يبلغ بابَها أصلًا
        $this->assertStringNotContainsString(
            'سرٌّ لا يُطبع',
            $this->previewHtml(['notes' => 'ملاحظةٌ للمورّد', 'internal_notes' => 'سرٌّ لا يُطبع']),
        );
    }

    /* ═══════════════ المورّدُ الافتراضيّ ═══════════════ */

    /** يُختار وحدَه عند فتح الشاشة */
    public function test_the_default_supplier_is_preselected(): void
    {
        $second = Supplier::create(['business_id' => $this->business->id, 'name' => 'مزرعة صلالة']);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.defaultSupplier'), ['supplier_id' => $second->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.purchases.create'))
            ->assertInertia(fn ($p) => $p->where('defaultSupplierId', $second->id)->etc());
    }

    /**
     * ومورّدٌ واحد يُختار وحدَه بلا ضبط — قائمةٌ ذاتُ خيارٍ واحد ليست خيارًا.
     *
     * ويسقط الاختيارُ التلقائيُّ متى صار للمتجر مورّدان: عندها يصير سؤالًا
     * حقيقيًّا، واختيارُ أحدهما بالنيابة يجعل أمرًا يمضي إلى غير من قُصد.
     */
    public function test_a_lone_supplier_is_preselected_and_a_second_one_ends_that(): void
    {
        $this->assertSame(
            (int) $this->supplier->id,
            PurchaseOrders::defaultSupplierId($this->business->id),
        );

        Supplier::create(['business_id' => $this->business->id, 'name' => 'مزرعة صلالة']);

        $this->assertNull(PurchaseOrders::defaultSupplierId($this->business->id));
    }

    /** ومورّدٌ حُذف يترك رقمًا لا صفَّ له — فيُهمَل ولا تُفتح شاشةٌ عليه */
    public function test_a_default_supplier_that_no_longer_exists_is_ignored(): void
    {
        Supplier::create(['business_id' => $this->business->id, 'name' => 'مزرعة صلالة']);

        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => PurchaseOrders::DEFAULT_SUPPLIER],
            ['value' => '999999'],
        );

        $this->assertNull(PurchaseOrders::defaultSupplierId($this->business->id));
    }

    /** ولا يُضبط مورّدُ متجرٍ آخر — يُردّ على حقله ولا يُكتب */
    public function test_a_supplier_from_another_shop_cannot_be_made_the_default(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.defaultSupplier'), ['supplier_id' => $theirs->id])
            ->assertSessionHasErrors('supplier_id');
    }

    /**
     * وضبطُه إعدادُ متجرٍ لا فعلُ أمرِ شراء.
     *
     * و«أمينُ المخزن» هو المقياس لا «البائع»: البائعُ لا يفتح قسمَ المشتريات
     * أصلًا، فردُّه لا يُثبت شيئًا عن هذا الباب بعينه. وأمينُ المخزن يكتب
     * الأوامر ولا يملك «الإعدادات» — وهو الحدُّ الذي يُختبر.
     */
    public function test_a_buyer_without_settings_may_not_set_the_default_supplier(): void
    {
        $buyer = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'w@abaad.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);

        // وهو يفتح الشاشة فعلًا — وإلّا كان الردُّ عن القسم لا عن هذا الباب
        $this->actingAs($buyer)->get(route('admin.purchases.create'))
            ->assertInertia(fn ($p) => $p->where('mayBrand', false)->etc());

        $this->actingAs($buyer)
            ->post(route('admin.purchases.defaultSupplier'), ['supplier_id' => $this->supplier->id])
            ->assertForbidden();

        $this->assertSame(
            0,
            DB::table('settings')->where('business_id', $this->business->id)
                ->where('key', PurchaseOrders::DEFAULT_SUPPLIER)->count(),
        );
    }

    /* ═══════════════ المعاينةُ والقالب ═══════════════ */

    /** والمعاينةُ لا تكتب شيئًا — لا أمرًا، ولا رقمًا من التسلسل، ولا قيدًا */
    public function test_previewing_writes_nothing(): void
    {
        $this->previewHtml();

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, DB::table('purchase_order_items')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    /**
     * ═══ والمعاينةُ تُرسم بقالب «قوالب الأوراق» نفسِه ═══
     *
     * لا بنسخةٍ ثانية داخل شاشة أمر الشراء. والحجّةُ أنّ تبديلَ القالب في
     * الإعدادات يُبدّل المعاينة — ولو كانت مرسومةً في JSX لبقيت على حالها
     * ولم يقل شيءٌ إنّها افترقت.
     */
    public function test_the_preview_follows_the_paper_template_from_settings(): void
    {
        $this->actingAs($this->owner)->post(
            route('admin.settings.templates.update', 'purchase'),
            ['header' => 'أجمل الورود في مسقط', 'footer' => 'شكرًا لتعاونكم', 'font' => 'كبير'],
        )->assertSessionHasNoErrors();

        $html = $this->previewHtml();

        $this->assertStringContainsString('أجمل الورود في مسقط', $html, 'سطرُ الترويسة لا يبلغ المعاينة');
        $this->assertStringContainsString('شكرًا لتعاونكم', $html, 'التذييلُ لا يبلغ المعاينة');
        $this->assertStringContainsString('font-size: 11.4pt', $html, 'حجمُ الخطّ لا يُدير شيئًا');
    }

    /** ومقبضُ «إظهار الشعار» في القالب يُطفئه على أمر الشراء كذلك */
    public function test_the_template_logo_flag_governs_the_purchase_paper(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(
            route('admin.settings.templates.update', 'purchase'),
            ['show_logo' => true],
        )->assertSessionHasNoErrors();

        $this->assertStringContainsString('logo', $this->previewHtml(), 'الشعارُ لا يبلغ ورقة الشراء');

        $this->actingAs($this->owner)->post(
            route('admin.settings.templates.update', 'purchase'),
            ['show_logo' => false],
        )->assertSessionHasNoErrors();

        $this->assertStringNotContainsString(
            '<img',
            $this->previewHtml(),
            'مفتاحُ الشعار لا يُطفئه على ورقة الشراء',
        );
    }

    /** وزرُّ «تخصيص التصميم» يقود إلى مالك القالب لا إلى نافذةٍ من عنده */
    public function test_the_customize_button_points_at_the_settings_template(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/Purchases/Create.tsx'));

        $this->assertStringContainsString("route('admin.settings.templates.edit', 'purchase')", $screen);
        $this->assertStringContainsString('srcDoc={html}', $screen, 'المعاينةُ ليست في إطارٍ يقرأ ردَّ الخادم');
        $this->assertStringContainsString("route('admin.purchases.preview')", $screen);

        // و«أمر الشراء» ورقةٌ معروفةٌ في السجلّ — وإلّا كان الزرُّ يقود إلى ٤٠٤
        $this->assertTrue(DocumentTemplates::exists('purchase'));
        $this->actingAs($this->owner)
            ->get(route('admin.settings.templates.edit', 'purchase'))->assertOk();
    }

    /* ═══════════════ اللغتان ═══════════════ */

    /** العربيةُ بمسمّياتها كما تُقرأ */
    public function test_the_arabic_paper_carries_arabic_labels(): void
    {
        $html = $this->previewHtml(['lang' => 'ar', 'payment_terms_days' => 30]);

        foreach (['أمر شراء', 'تاريخ الاستلام المتوقع', 'شروط الدفع', 'صافي 30 يومًا', 'المورّد'] as $label) {
            $this->assertStringContainsString($label, $html, "«{$label}» ليست في الورقة العربية");
        }
    }

    /**
     * والإنجليزيةُ إنجليزيّةٌ كلُّها — عناوينَ **وقيمًا مغلقة**.
     *
     * «تحويل بنكي» ليست نصًّا كتبه التاجر: هي قيمةٌ من قائمةٍ يكتبها النظام.
     * وورقةٌ تقول «Payment method: تحويل بنكي» نصفُها بلغةٍ ونصفُها بأخرى.
     * أمّا اسمُ الصنف وملاحظةُ المورّد فنصٌّ كتبه صاحبُه — يبقى كما كتبه.
     */
    public function test_the_english_paper_carries_english_labels_and_values(): void
    {
        $html = $this->previewHtml([
            'lang' => 'en', 'payment_method' => 'تحويل بنكي', 'payment_terms_days' => 30,
        ]);

        foreach (['Purchase order', 'Expected delivery date', 'Payment terms', 'Net 30 days', 'Bank Transfer'] as $label) {
            $this->assertStringContainsString($label, $html, "«{$label}» ليست في الورقة الإنجليزية");
        }

        foreach (['تاريخ الاستلام المتوقع', 'شروط الدفع', 'تحويل بنكي'] as $label) {
            $this->assertStringNotContainsString($label, $html, "«{$label}» عربيّةٌ في ورقةٍ إنجليزية");
        }

        $this->assertMatchesRegularExpression(
            '/body\s*\{[^}]*direction:\s*ltr/',
            $html,
            'جسدُ الورقة الإنجليزية يُرسم من اليمين',
        );
    }

    /** وتقليبُ لغة المعاينة ليس حفظًا */
    public function test_previewing_in_another_language_does_not_save_it(): void
    {
        $this->previewHtml(['lang' => 'en']);

        $this->assertNull(
            DB::table('settings')->where('business_id', $this->business->id)
                ->where('key', InvoiceBranding::LANGUAGE)->value('value'),
        );
    }

    /* ═══════════════ العزلُ بين المتاجر ═══════════════ */

    /** ومورّدُ متجرٍ آخر لا يُرسَم على ورقتنا — ولا يُردّ الطلبُ بـ٤٠٤ في شاشةٍ تكتب */
    public function test_another_shops_supplier_never_reaches_the_paper(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدُ الجار']);

        $html = $this->previewHtml(['supplier_id' => $theirs->id]);

        $this->assertStringNotContainsString('مورّدُ الجار', $html);
    }

    /** والحفظُ يردّه على حقله */
    public function test_another_shops_supplier_is_refused_at_the_door(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدُ الجار']);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.store'), $this->payload(['supplier_id' => $theirs->id]))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, PurchaseOrder::count());
    }

    /* ═══════════════ ضغطتان أمرٌ واحد ═══════════════ */

    /** ونموذجٌ أُرسل مرّتين يُردّ إلى أمره الأوّل — لا أمران لمورّدٍ واحد */
    public function test_two_clicks_write_one_order(): void
    {
        /*
         * ═══ ويُردّ إلى أمره الأوّل — لا يُرمى في وجهه خطأٌ من القاعدة ═══
         *
         * على العمود فهرسُ تفرّدٍ `(business_id, form_token)`، وهو الحارسُ
         * الأخير. فحارسٌ يفحص «كم أمرًا كُتب» وحدَه يمرّ ولو نُزع فحصُ
         * المتحكّم كلُّه — تسقط الكتابةُ الثانية على الفهرس فيبقى العددُ
         * واحدًا، ويرى التاجرُ صفحةَ خطأٍ لا يفهمها. وقد نجت من ذلك طفرة.
         *
         * فيُفحص **الردُّ** كذلك: تحويلٌ هادئ إلى القائمة لا خمسُمئة.
         */
        foreach ([1, 2] as $_) {
            $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
                'form_token' => 'one-and-the-same',
            ]))->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, DB::table('purchase_order_items')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }
}
