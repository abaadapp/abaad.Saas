<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Document\Snapshot;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كلُّ مقبضٍ في «قوالب الأوراق» يُدير شيئًا — وإلّا فلا يُعرض.
 *
 * ═══ ما وُجد ═══
 *
 * سبعةٌ وأربعون مقبضًا في ثمانية أنواع، وثلاثةٌ منها كانت ميّتة:
 *
 *  ١. `show_logo` على A4 كلِّها: `partials/identity` يرسم الشعارَ بشرطِ
 *     وجوده وحدَه ولا يسأل عن المقبض. والشريطُ الحراريّ يسأل — فيخرج
 *     الإيصالُ بلا شعارٍ والفاتورةُ للطلب نفسِه تحمله.
 *
 *  ٢. `show_items_count` على A4: لم يكن يُقرأ إلّا في الشريط. وثلاثةٌ من
 *     الأربعة التي تعلنه لا شريطَ لها أصلًا — ومتجرٌ على الإنتاج حفظ
 *     `tpl_purchase_show_items_count = 1`، فأشعل مقبضًا لا يُدير شيئًا.
 *
 *  ٣. وأخطرُها: **فاتورةُ البيع على A4 لم تحمل شعارَ متجرٍ قطّ.** لقطةُ
 *     البائع (`Snapshot::capture`) تحمل الاسمَ والعنوانَ والهاتف ولا تحمل
 *     `logo`، و`saleSheet` تبني الورقةَ منها. فالشعارُ يُرى على الإيصال
 *     الحراريّ وعلى أمر الشراء، ويغيب عن الورقة التي تُرسَل إلى الشركات.
 *
 * ═══ ولمَ حارسٌ يمسح الأنواعَ كلَّها ═══
 *
 * قائمةٌ تُكتب باليد تنسى التاليَ دائمًا: يُضاف حقلٌ إلى `TYPES` غدًا
 * ويُعرض في المحرّر ولا يصل قالبًا، فيقرأ التاجر مقبضًا يضغطه ولا يقع شيء.
 * وهذا الحارس يقرأ السجلَّ نفسَه، فما يُضاف يُقاس يومَ يُضاف.
 */
class EveryKnobOnThePaperTurnsSomethingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    /**
     * مقابضُ لا تُقاس بالمعاينة — ولكلٍّ سببٌ مكتوبٌ وحارسٌ آخر يقيسها.
     *
     * ولا تُعفى بلا بديل: `show_qr` يقيسه
     * `test_the_receipt_code_answers_its_knob_on_the_printed_paper` على
     * طريق الطباعة، حيث يوجد رمزٌ أصلًا.
     */
    private const NOT_IN_PREVIEW = [
        // المعاينةُ لا تحمل رمزًا: طلبٌ مُخترعٌ برمزٍ يقود إلى ٤٠٤ في يد التاجر
        'sale.show_qr',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * والورقةُ تُقرأ بالعربيّة.
         *
         * لغةُ مُشغِّل الاختبارات إنجليزيّة، و`__('عدد الأصناف')` تُخرج
         * ترجمتَها — فيفشل حارسٌ سليمٌ لأنّه يبحث عن نصٍّ لم يُطلب رسمُه.
         */
        $this->app->setLocale('ar');

        Storage::fake('public');
        Storage::disk('public')->put('logos/shop.png', 'PNG');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($owner);

        /*
         * ومتجرٌ مجهَّز: مقبضٌ لا بياناتَ له لا يُغيّر شيئًا وليس ذلك عطبَه.
         * فالشعارُ موجود، والرقمُ الضريبيُّ مضبوط، والفرعُ والعميلُ والمورّد.
         */
        DB::table('businesses')->where('id', $this->business->id)->update(['logo' => 'logos/shop.png']);

        foreach ([
            'vat_number' => 'OM1100000000', 'vat_enabled' => '1', 'cr_number' => '1031484',
            'address' => 'مسقط، الخوير', 'phone' => '+968 90000000',
        ] as $key => $value) {
            Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
        }

        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Customer::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'phone' => '90000001']);
        Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل الربيع', 'phone' => '90000002']);
    }

    /** @return array<string, array<string, mixed>> */
    private function types(): array
    {
        return (new \ReflectionClass(DocumentTemplates::class))->getConstant('TYPES');
    }

    public function test_every_declared_knob_changes_the_paper(): void
    {
        $dead = [];

        foreach ($this->types() as $type => $spec) {
            foreach (array_keys($spec['fields']) as $field) {
                if (in_array("{$type}.{$field}", self::NOT_IN_PREVIEW, true)) {
                    continue;
                }

                $on = DocumentRenderer::preview($this->business->id, $type, [$field => true]);
                $off = DocumentRenderer::preview($this->business->id, $type, [$field => false]);

                if ($on === $off) {
                    $dead[] = "{$type}.{$field}";
                }
            }
        }

        $this->assertSame(
            [],
            $dead,
            'مقابضُ تُعرض في المحرّر ولا تُدير شيئًا — ومقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض',
        );
    }

    public function test_no_paper_falls_over_when_a_knob_is_thrown(): void
    {
        foreach ($this->types() as $type => $spec) {
            foreach (array_keys($spec['fields']) as $field) {
                foreach ([true, false] as $on) {
                    $html = DocumentRenderer::preview($this->business->id, $type, [$field => $on]);
                    $this->assertNotSame('', trim($html), "{$type}.{$field} يُخرج ورقةً خاوية");
                }
            }
        }
    }

    /*
     * ═══ والشعارُ على فاتورة A4 — وكان يغيب عنها وحدها ═══
     *
     * تُبنى من `Snapshot`، وكانت لقطةُ البائع بلا `logo`. فالمقبضُ يُشعَل
     * ولا يقع شيء، والتاجرُ يرى شعارَه على الإيصال ولا يراه على الورقة
     * التي تحمل اسمَه إلى جهةٍ أخرى.
     */
    public function test_the_sale_sheet_carries_the_shop_logo(): void
    {
        $shown = DocumentRenderer::preview($this->business->id, 'sale', ['show_logo' => true]);
        $hidden = DocumentRenderer::preview($this->business->id, 'sale', ['show_logo' => false]);

        $this->assertStringContainsString('<img', $shown, 'فاتورةُ A4 بلا شعارٍ والمقبضُ مُشعَل');
        $this->assertStringNotContainsString('<img', $hidden);
    }

    /*
     * ═══ والشعارُ لا يدخل لقطةَ البائع ═══
     *
     * مفاتيحُ اللقطة تطغى على ما تبنيه `InvoiceBranding` لفاتورة العميل
     * (`array_intersect_key` في `CustomerInvoiceController::paper`) — فشعارٌ
     * فيها يستبدل الصورةَ المُضمَّنة برابطٍ لا يقرؤه mpdf. ولأنّه ليس من
     * الهويّة التي تُجمَّد: الشريطُ يقرؤه حيًّا، فالورقتان تتبعان ملفَّ اليوم.
     */
    public function test_the_stamp_does_not_freeze_the_logo(): void
    {
        $seller = Snapshot::capture($this->business->id)['seller'];

        $this->assertArrayNotHasKey(
            'logo',
            $seller,
            'اللقطةُ تحمل شعارًا — فتطغى على الصورة المُضمَّنة في فاتورة العميل',
        );
    }

    /** والمُضمَّنُ لا الرابط: الورقةُ تُقرأ في إطارٍ معزولٍ وفي mpdf بلا جلسة */
    public function test_the_sale_sheet_embeds_the_logo_it_draws(): void
    {
        $html = DocumentRenderer::preview($this->business->id, 'sale', ['show_logo' => true]);

        $this->assertStringContainsString('data:image/', $html, 'الشعارُ برابطٍ يسقط في mpdf بلا صوت');
    }

    /*
     * ═══ وعددُ الأصناف على الورقة كما هو على الشريط ═══
     *
     * أربعةُ أنواعٍ تعلنه، وثلاثةٌ منها لا شريطَ لها — فكان ميّتًا فيها.
     */
    public function test_the_item_count_reaches_the_sheet_not_only_the_strip(): void
    {
        foreach (['sale', 'delivery', 'purchase', 'grn'] as $type) {
            $on = DocumentRenderer::preview($this->business->id, $type, ['show_items_count' => true]);
            $off = DocumentRenderer::preview($this->business->id, $type, ['show_items_count' => false]);

            $this->assertStringContainsString('عدد الأصناف', $on, "{$type}: المقبضُ مُشعَلٌ ولا عدد");
            $this->assertStringNotContainsString('عدد الأصناف', $off, "{$type}: المقبضُ مُطفأٌ والعددُ يُطبع");
        }
    }

    /** ولا يُقحَم على ورقةٍ لم تعلنه: فاتورةُ العميل وإشعارُ الدائن وسندُ القبض */
    public function test_a_paper_that_never_asked_for_a_count_does_not_get_one(): void
    {
        foreach (['customer_invoice', 'credit_note', 'customer_receipt', 'supplier_invoice'] as $type) {
            $this->assertStringNotContainsString(
                'عدد الأصناف',
                DocumentRenderer::preview($this->business->id, $type),
                "{$type}: سطرٌ لم يطلبه أحد",
            );
        }
    }

    /*
     * ═══ وجزءُ الأصناف لا يفترض رأيًا لم يُقَل ═══
     *
     * `partials/items` يُضمَّن اليوم في الأربعة التي تُعلن الحقل وحدَها،
     * فالافتراضُ فيه لا يُبلَغ من طريقها. ويُبلَغ يومَ يُضمَّن في ورقةٍ
     * خامسة — وعندها إمّا أن يصمت وإمّا أن يُقحم سطرًا لم يطلبه أحد.
     * فيُقاس العقدُ على الجزء مباشرةً لا على من يضمّنه.
     */
    public function test_the_items_block_stays_silent_when_no_one_asked(): void
    {
        $html = view('documents.v1.partials.items', [
            'items' => [['name' => 'وردة', 'qty' => '1', 'unit' => '10.000', 'total' => '10.000']],
            'tpl' => [],
            'showPrices' => true,
        ])->render();

        $this->assertStringContainsString('وردة', $html, 'الجزءُ لم يُرسم — فالحارسُ لا يقيس شيئًا');
        $this->assertStringNotContainsString('عدد الأصناف', $html, 'سطرٌ يُقحَم على ورقةٍ لم تعلن الحقل');
    }

    /*
     * ═══ ورمزُ الفاتورة يُقاس على الطباعة لا المعاينة ═══
     *
     * المعاينةُ لا تحمل رمزًا عمدًا — طلبٌ مُخترعٌ برمزٍ يضع في يد التاجر
     * صورةَ رمزٍ لا تقابلها فاتورة. فيُقاس حيث يوجد رمز.
     */
    public function test_the_receipt_code_answers_its_knob_on_the_printed_paper(): void
    {
        $order = $this->order();
        $codes = fn (bool $on) => substr_count(
            DocumentRenderer::saleSheet(
                $this->business->id,
                $order,
                DocumentTemplates::settings($this->business->id, 'sale', ['show_qr' => $on]),
                ['qr' => 'TEST-QR-PAYLOAD'],
            ),
            'qr-block',
        );

        $this->assertGreaterThan($codes(false), $codes(true), 'رمزُ الفوترة لا يسمع مقبضَه على الورق');
    }

    private function order(): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-77', 'customer_name' => 'نقدي',
            'status' => 'مكتمل', 'is_held' => false, 'subtotal' => 10, 'tax' => 0.5,
            'total' => 10.5, 'ordered_at' => now(),
        ]);
        $order->items()->create(['name' => 'وردة', 'quantity' => 1, 'price' => 10, 'total' => 10]);

        return $order->fresh('items');
    }
}
