<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomOrderField;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomArrangement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلبُ المخصَّص يبقى على شكله — والتاجرُ يُعدّل قالبَه والزبونُ واقف.
 *
 * ═══ ما يجمع هذه الحرّاس ═══
 *
 * قالبُ الطلب يُكتب اليوم ويُعدَّل غدًا: حقلٌ يُوقَف، وخيارٌ يُشطب، ومفتاحُ
 * «الموادّ» يُطفأ. وبين التعديل والدفع سلالٌ معلَّقةٌ عند الصناديق — عُلّقت
 * بشكل الأمس وتُدفع بشكل اليوم.
 *
 * فما يُبطل منها بيعةً قائمة يُراجَع: التعديلُ يقول «لا تسأل هذا بعد اليوم»،
 * لا «أبطِل ما أُجيب». وما يمسّ الرفَّ أو الدفترَ يُردّ صراحةً ولا يُبتلع:
 * بيعةٌ تمرّ ناقصةَ موادِّها أسوأُ من بيعةٍ تُردّ.
 */
class TheCustomOrderKeepsItsShapeTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $cashier;

    private Product $rose;

    private CustomOrderTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر', 'status' => 'نشط']);

        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'ورد', 'sku' => 'R-1',
            'price' => 1, 'cost' => 0.5, 'quantity' => 50, 'active' => true,
        ]);

        $this->template = CustomOrderTemplate::ensureDefault($this->shop->id);
    }

    private function sell(array $custom = [])
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['name' => 'مخصص', 'qty' => 1, 'custom' => array_merge([
                'template_id' => $this->template->id,
                'mode' => CustomArrangement::MODE_VALUE,
                'price' => 20,
            ], $custom)]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
        ]);
    }

    /* ══════════ ١ · قالبٌ لا يقبل موادَّ يردّها ولا يبتلعها ══════════ */

    /**
     * ═══ العطب ═══
     *
     * `allow_components = false` كانت تطرح الموادَّ صامتةً: يُقبل البيعُ
     * بسعره، ولا يَنقص الرفُّ شيئًا، وتُكتب التكلفةُ صفرًا. فيخرج الوردُ
     * من الدلو ويبقى في الدفتر، ويُقرأ ربحُ البيعة كاملًا بلا تكلفة.
     *
     * ويقع حين يُطفئ صاحبُ النشاط المفتاحَ وفي الصناديق سلالٌ عُلّقت به
     * مفتوحًا — تُستأنف بموادّها فتُدفع ناقصةَ ما أُخذ.
     */
    public function test_a_template_that_forbids_materials_refuses_them(): void
    {
        $this->template->update(['allow_components' => false]);

        $this->sell(['components' => [['product_id' => $this->rose->id, 'quantity' => 8]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.custom.components');

        $this->assertSame(50, (int) $this->rose->fresh()->quantity, 'الرفُّ تحرّك في بيعةٍ مردودة');
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count(), 'بيعةٌ كُتبت ومادّتُها مطروحة');
    }

    /** ومن لا يرسل موادَّ أصلًا يبيع بالقالب نفسِه — الردُّ على المطروح لا على الجميع */
    public function test_the_same_template_still_sells_without_materials(): void
    {
        $this->template->update(['allow_components' => false]);

        $this->sell()->assertOk()->assertJsonPath('ok', true);
    }

    /* ══════════ ٢ · حقلٌ يُوقَف لا يقتل سلّةً عُلّقت بجوابه ══════════ */

    /**
     * ═══ العطب ═══
     *
     * الحقولُ كانت تُقرأ بـ`active = true`. فحقلٌ يُوقفه صاحبُ النشاط
     * ظهرًا يُبطل كلَّ سلّةٍ عُلّقت بجوابه صباحًا: تُستأنف فترسل معرّفَه،
     * فيُردّ «حقل غير متاح في هذا القالب» — عن حقلٍ لا يراه الكاشيرُ في
     * شاشته ولا يملك حذفَه من سلّته. فيقف الزبون ولا سبيلَ إلى الدفع إلّا
     * بهدم السلّة وبنائها.
     *
     * و«الإيقاف» في الحقل يقول «لا تسأله بعد اليوم»: سؤالٌ في استمارة، لا
     * مالَ فيه ولا بضاعة.
     */
    public function test_a_paused_field_does_not_kill_a_held_basket(): void
    {
        $f = $this->template->fields()->create([
            'label' => 'اسم المهدى إليه', 'type' => CustomOrderField::SHORT_TEXT,
            'active' => true, 'sort_order' => 0,
        ]);

        $f->update(['active' => false]);

        $this->sell(['fields' => [['field_id' => $f->id, 'value' => 'سالم']]])
            ->assertOk()->assertJsonPath('ok', true);

        $details = Order::where('business_id', $this->shop->id)->firstOrFail()
            ->items->first()->custom_details;

        $this->assertSame('سالم', $details['fields'][0]['values'][0]['label'], 'جوابُ الحقل لم يُحفظ');
    }

    /** وخيارٌ يُوقَف كذلك — تسميةٌ تُنسخ في اللقطة لا رصيدٌ يُحجز */
    public function test_a_paused_option_does_not_kill_a_held_basket(): void
    {
        $f = $this->template->fields()->create([
            'label' => 'اللون', 'type' => CustomOrderField::SELECT, 'active' => true, 'sort_order' => 0,
        ]);
        $red = $f->options()->create(['label' => 'أحمر', 'active' => true, 'sort_order' => 0]);

        $red->update(['active' => false]);

        $this->sell(['fields' => [['field_id' => $f->id, 'value' => [$red->id]]]])
            ->assertOk()->assertJsonPath('ok', true);
    }

    /**
     * والموقوفُ لا يُطالَب به: إيقافُ حقلٍ مطلوبٍ إقفالٌ للصندوق لولا هذا.
     */
    public function test_a_paused_required_field_is_not_demanded(): void
    {
        $this->template->fields()->create([
            'label' => 'المناسبة', 'type' => CustomOrderField::SHORT_TEXT,
            'required' => true, 'active' => false, 'sort_order' => 0,
        ]);

        $this->sell()->assertOk()->assertJsonPath('ok', true);
    }

    /** والحقلُ العاملُ المطلوبُ يبقى مطلوبًا — القيدُ رُفع عن الموقوف وحده */
    public function test_a_live_required_field_is_still_demanded(): void
    {
        $this->template->fields()->create([
            'label' => 'المناسبة', 'type' => CustomOrderField::SHORT_TEXT,
            'required' => true, 'active' => true, 'sort_order' => 0,
        ]);

        $this->sell()->assertStatus(422);
    }

    /** وحقلُ قالبٍ آخر يبقى مردودًا — وهو الحارسُ الذي يعني شيئًا */
    public function test_a_field_of_another_template_is_still_refused(): void
    {
        $other = CustomOrderTemplate::create(CustomOrderTemplate::STARTER + [
            'business_id' => $this->shop->id, 'name' => 'قالب آخر',
        ]);
        $f = $other->fields()->create([
            'label' => 'غريب', 'type' => CustomOrderField::SHORT_TEXT, 'active' => true, 'sort_order' => 0,
        ]);

        $this->sell(['fields' => [['field_id' => $f->id, 'value' => 'شيء']]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.custom.fields.0.field_id');
    }

    /* ══════════ ٣ · شكلٌ لا يُقرأ نصًّا لا يُكتب `Array` ══════════ */

    /**
     * ═══ العطب ═══
     *
     * حقلٌ نوعُه «اختيار متعدّد» يُبدَّل إلى «نصّ قصير»، وسلّةٌ عُلّقت قبله
     * تُستأنف بعده: `resumeFields` تقرأ نوعَ اللقطة فتردّ مصفوفةَ معرّفات،
     * والحقلُ الحيُّ صار نصًّا. فـ`(string)` على مصفوفة تُسقط الطلبَ بـ٥٠٠
     * في وجه الكاشير — أو تكتب الكلمةَ `Array` في لقطةٍ تُقرأ بعد سنة.
     */
    public function test_an_array_never_becomes_the_word_array(): void
    {
        $f = $this->template->fields()->create([
            'label' => 'اسم المهدى إليه', 'type' => CustomOrderField::SHORT_TEXT,
            'active' => true, 'sort_order' => 0,
        ]);

        $this->sell(['fields' => [['field_id' => $f->id, 'value' => [3, 4]]]])
            ->assertOk()->assertJsonPath('ok', true);

        $details = Order::where('business_id', $this->shop->id)->firstOrFail()
            ->items->first()->custom_details;

        $this->assertStringNotContainsString(
            'Array',
            json_encode($details, JSON_UNESCAPED_UNICODE),
            'شكلٌ لا يُقرأ نصًّا كُتب في اللقطة',
        );
    }

    /* ══════════ ٤ · وما اختاره الزبونُ يصل شاشةَ طلبه ══════════ */

    /**
     * ═══ العطب ═══
     *
     * البندُ المخصَّص كان يصل شاشةَ «الطلبات» سطرًا واحدًا: «طلب مخصص»
     * وسعرُه. واللونُ والمقاسُ واسمُ المُهدى إليه — كلُّ ما سُئل عنه الزبون
     * ودُوّن — لا أثرَ له فيها. ويقرؤه الورقُ ولوحةُ التجهيز، وتعمى عنه
     * الشاشةُ التي يفتحها صاحبُ المتجر حين يتّصل الزبون يقول «طلبتُ الأحمر».
     *
     * والداخليُّ يصلها خلافًا للورق: هذه شاشةُ صاحب المتجر، وتلك ورقةُ زبونه.
     */
    public function test_the_order_screen_carries_what_the_customer_chose(): void
    {
        $colour = $this->template->fields()->create([
            'label' => 'اللون', 'type' => CustomOrderField::SHORT_TEXT,
            'active' => true, 'sort_order' => 0,
        ]);
        $note = $this->template->fields()->create([
            'label' => 'ملاحظة المنسّق', 'type' => CustomOrderField::SHORT_TEXT,
            'internal' => true, 'active' => true, 'sort_order' => 1,
        ]);

        $this->sell(['fields' => [
            ['field_id' => $colour->id, 'value' => 'أحمر'],
            ['field_id' => $note->id, 'value' => 'الأحمر أكثر'],
        ]])->assertOk();

        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'صاحب', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $number = Order::where('business_id', $this->shop->id)->value('number');

        $item = $this->actingAs($owner)->get(route('admin.orders.show', $number))
            ->assertOk()->viewData('page')['props']['order']['items'][0];

        $this->assertNotNull($item['custom'] ?? null, 'البندُ المخصَّص يصل الشاشةَ بلا وصفه');

        $labels = collect($item['custom']['fields'])->pluck('label');
        $this->assertTrue($labels->contains('اللون'), 'ما اختاره الزبونُ لا يصل شاشةَ طلبه');
        $this->assertTrue($labels->contains('ملاحظة المنسّق'), 'الحقلُ الداخليُّ محجوبٌ عن صاحب المتجر');

        $internal = collect($item['custom']['fields'])->firstWhere('label', 'ملاحظة المنسّق');
        $this->assertTrue($internal['internal'], 'الداخليُّ وصل بلا وسمه فيُطبع للزبون يومًا');
    }

    /**
     * وملاحظةُ البند تصل الشاشةَ التي ترسمها.
     *
     * `Pos/OrderDetails` تكتب `it.note` تحت اسم البند منذ أن كُتبت،
     * و`Demo::orderDetails` — مصدرُها الوحيد — لم تُرسلها قطّ. سطرٌ يُرسم
     * لقيمةٍ لا تصل: تُطبع على الإيصال ولا تُقرأ في شاشة الطلب.
     */
    public function test_a_line_note_reaches_the_screen_that_draws_it(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->rose->id, 'name' => 'ورد', 'qty' => 1, 'note' => 'بلا شريطة']],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertOk();

        $number = Order::where('business_id', $this->shop->id)->value('number');

        $item = $this->actingAs($this->cashier)->get(route('pos.order-details', $number))
            ->assertOk()->viewData('page')['props']['order']['items'][0];

        $this->assertSame('بلا شريطة', $item['note'] ?? null, 'الملاحظةُ تُرسم ولا تصل');
    }

    /** وبندُ الكتالوج لا يحمل وصفًا — فلا تُرسم له قائمةٌ فارغة */
    public function test_a_catalogue_line_carries_no_description(): void
    {
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'صاحب', 'email' => 'o2@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->rose->id, 'name' => 'ورد', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertOk();

        $number = Order::where('business_id', $this->shop->id)->value('number');

        $item = $this->actingAs($owner)->get(route('admin.orders.show', $number))
            ->assertOk()->viewData('page')['props']['order']['items'][0];

        $this->assertNull($item['custom'], 'بندُ كتالوجٍ يحمل وصفَ طلبٍ مخصَّص');
    }

    /** والمطلوبُ يُطالَب به من جديد حين يصل بشكلٍ لا يُقرأ — لا يمرّ فارغًا */
    public function test_a_required_field_is_demanded_again_when_the_shape_is_unreadable(): void
    {
        $f = $this->template->fields()->create([
            'label' => 'المناسبة', 'type' => CustomOrderField::SHORT_TEXT,
            'required' => true, 'active' => true, 'sort_order' => 0,
        ]);

        $this->sell(['fields' => [['field_id' => $f->id, 'value' => ['a', 'b']]]])
            ->assertStatus(422);
    }
}
