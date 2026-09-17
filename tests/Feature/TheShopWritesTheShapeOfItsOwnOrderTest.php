<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomOrderField;
use App\Models\CustomOrderFieldOption;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomArrangement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المتجرُ يكتب شكلَ طلبه — وأبعادُ لا يعرف ما يبيع.
 *
 * ═══ العطب ═══
 *
 * الطلبُ المخصَّص كان مكتوبًا لمحلّ ورد: «قيمة الورد» و«ألوان الورد»
 * و«ملاحظات المنسّق»، ونوعا مادّةٍ `flower` و`packaging`. فبائعُ العطر يجد
 * شاشةً تسأله عن ألوان الورد، ومؤجّرُ الكراسي يجد أنّ موادَّه «لا تعود».
 *
 * ═══ والحلُّ ليس قائمةَ صناعات ═══
 *
 * `business_type` كان سيعني أنّ في النظام فرعًا لكلّ صناعةٍ يعرفها، وأنّ
 * كلَّ صناعةٍ لا يعرفها لا شكلَ لها — «قائمةٌ تُكتب باليد تنسى التاليَ
 * دائمًا». فلا يعرف النظامُ صناعةً: يعرف **قالبًا** كتبه صاحبُ النشاط.
 *
 * وهذه الحرّاسُ تُثبت ذلك بثلاثة قوالبَ من ثلاث صناعاتٍ على المحرّك نفسِه،
 * ولا سطرَ في النظام يعرف أيَّها.
 */
class TheShopWritesTheShapeOfItsOwnOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private User $stranger;

    private Product $material;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'صاحب', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->stranger = User::create([
            'business_id' => $this->other->id, 'name' => 'غريب', 'email' => 's@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->material = Product::create([
            'business_id' => $this->shop->id, 'name' => 'مادة', 'sku' => 'M-1',
            'price' => 1, 'cost' => 0.5, 'quantity' => 100, 'active' => true,
        ]);
    }

    /** حمولةُ قالبٍ كما ترسلها شاشةُ الإعدادات */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'طلب على المقاس',
            'modes' => [CustomArrangement::MODE_VALUE, CustomArrangement::MODE_BUDGET],
            'default_mode' => CustomArrangement::MODE_VALUE,
            'base_label' => 'قيمة الطلب',
            'allow_components' => true,
            'allow_addons' => true,
            'components_restockable_default' => false,
            'active' => true,
            'fields' => [],
        ], $over);
    }

    private function save(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->post('/admin/custom-order-templates', $this->payload($over));
    }

    /* ══════════════ ١ · القالبُ يُكتب ويُقرأ ══════════════ */

    public function test_a_shop_writes_a_template_with_its_own_fields(): void
    {
        $this->save(['fields' => [
            ['label' => 'التركيز', 'type' => CustomOrderField::SELECT, 'required' => true, 'options' => [
                ['label' => 'خفيف'], ['label' => 'ثقيل'],
            ]],
            ['label' => 'ملاحظة العطّار', 'type' => CustomOrderField::LONG_TEXT, 'internal' => true],
        ]])->assertRedirect();

        $template = CustomOrderTemplate::where('name', 'طلب على المقاس')->firstOrFail();

        $this->assertSame($this->shop->id, $template->business_id);
        $this->assertCount(2, $template->fields);
        $this->assertSame('التركيز', $template->fields[0]->label);
        $this->assertTrue($template->fields[0]->required);
        $this->assertCount(2, $template->fields[0]->options);
        $this->assertTrue($template->fields[1]->internal, 'الحقلُ الداخليّ لم يُحفظ داخليًّا');
    }

    /**
     * والترتيبُ يُكتب من موضع الصفّ لا من رقمٍ يرسله المتصفّح.
     *
     * رقمٌ يُرسَل يُكرَّر — صفّان بالرتبة نفسِها — فيصير ترتيبُ الشاشة رهنَ
     * ما يقرّره المحرّك، ويختلف بين فتحةٍ وأخرى أمام الكاشير نفسِه.
     */
    public function test_field_order_follows_the_order_they_arrived_in(): void
    {
        $this->save(['fields' => [
            ['label' => 'ثالث', 'type' => CustomOrderField::SHORT_TEXT],
            ['label' => 'أول', 'type' => CustomOrderField::SHORT_TEXT],
        ]])->assertRedirect();

        $template = CustomOrderTemplate::where('name', 'طلب على المقاس')->firstOrFail();

        $this->assertSame(['ثالث', 'أول'], $template->fields->pluck('label')->all());
        $this->assertSame([0, 1], $template->fields->pluck('sort_order')->all());
    }

    /**
     * والتعديلُ يُبقي معرّفَ الحقل — لا يمحو ويُعيد البناء.
     *
     * إعادةُ البناء تُغيّر المعرّفات، فسلّةٌ معلَّقةٌ تُستأنف على معرّفاتٍ
     * لم تعد موجودة — يجدها الكاشيرُ بلا إجاباتٍ ولا سببَ يُقال.
     */
    public function test_editing_a_template_keeps_the_field_ids(): void
    {
        $this->save(['fields' => [['label' => 'اللون', 'type' => CustomOrderField::SHORT_TEXT]]]);
        $template = CustomOrderTemplate::where('name', 'طلب على المقاس')->firstOrFail();
        $id = $template->fields->first()->id;

        $this->actingAs($this->owner)->put("/admin/custom-order-templates/{$template->id}", $this->payload([
            'fields' => [['id' => $id, 'label' => 'اللون المطلوب', 'type' => CustomOrderField::SHORT_TEXT]],
        ]))->assertRedirect();

        $this->assertSame($id, $template->fresh()->fields->first()->id, 'المعرّفُ تبدّل فسقطت السلّاتُ المعلَّقة');
        $this->assertSame('اللون المطلوب', $template->fresh()->fields->first()->label);
    }

    /** وما لم يصل حُذف — وإلّا نمت الشاشةُ بحقولٍ لا يُزيلها شيء */
    public function test_a_field_left_out_of_the_payload_is_removed(): void
    {
        $this->save(['fields' => [
            ['label' => 'أ', 'type' => CustomOrderField::SHORT_TEXT],
            ['label' => 'ب', 'type' => CustomOrderField::SHORT_TEXT],
        ]]);
        $template = CustomOrderTemplate::where('name', 'طلب على المقاس')->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/custom-order-templates/{$template->id}", $this->payload([
            'fields' => [['label' => 'أ', 'type' => CustomOrderField::SHORT_TEXT]],
        ]))->assertRedirect();

        $this->assertSame(['أ'], $template->fresh()->fields->pluck('label')->all());
    }

    /** ونوعٌ لا يقبل خياراتٍ تُمحى خياراتُه، فلا تبقى قائمةٌ لا تُعرض */
    public function test_changing_a_field_away_from_select_drops_its_options(): void
    {
        $this->save(['fields' => [
            ['label' => 'المقاس', 'type' => CustomOrderField::SELECT, 'options' => [['label' => 'كبير']]],
        ]]);
        $template = CustomOrderTemplate::where('name', 'طلب على المقاس')->firstOrFail();
        $field = $template->fields->first();
        $this->assertCount(1, $field->options);

        $this->actingAs($this->owner)->put("/admin/custom-order-templates/{$template->id}", $this->payload([
            'fields' => [['id' => $field->id, 'label' => 'المقاس', 'type' => CustomOrderField::SHORT_TEXT]],
        ]))->assertRedirect();

        $this->assertCount(0, $field->fresh()->options);
    }

    /** وقالبٌ بلا طريقةِ تسعيرٍ واحدةٍ يُردّ — قالبٌ لا يُسعَّر لا يُباع به */
    public function test_a_template_with_no_pricing_mode_is_refused(): void
    {
        $this->save(['modes' => []])->assertSessionHasErrors('modes');
    }

    /** ونوعُ حقلٍ لا يعرفه النظام يُردّ — ولا يُكتب في العمود كما وصل */
    public function test_an_unknown_field_type_is_refused(): void
    {
        $this->save(['fields' => [['label' => 'س', 'type' => 'signature']]])
            ->assertSessionHasErrors('fields.0.type');
    }

    /* ══════════════ ٢ · لا يُقرأ متجرٌ متجرًا ══════════════ */

    public function test_a_stranger_cannot_edit_a_template(): void
    {
        $mine = CustomOrderTemplate::ensureDefault($this->shop->id);

        $this->actingAs($this->stranger)
            ->put("/admin/custom-order-templates/{$mine->id}", $this->payload())
            ->assertNotFound();

        $this->assertNotSame('طلب على المقاس', $mine->fresh()->name);
    }

    public function test_a_stranger_cannot_delete_a_template(): void
    {
        $mine = CustomOrderTemplate::ensureDefault($this->shop->id);

        $this->actingAs($this->stranger)
            ->delete("/admin/custom-order-templates/{$mine->id}")
            ->assertNotFound();

        $this->assertNull($mine->fresh()->deleted_at);
    }

    /**
     * ولا يُنقل حقلُ متجرٍ إلى قالب متجرٍ آخر بمعرّفٍ يُرسَل.
     *
     * `find($id)` المجرّدة كانت ستنقل الملكيّة: يُرسل صاحبُ متجرٍ معرّفَ حقلٍ
     * من متجرٍ آخر، فيُحدَّث `template_id` فيه — فيفقد الجارُ حقلَه ويكسبه هو.
     */
    public function test_a_field_id_from_another_shop_is_not_adopted(): void
    {
        $theirs = CustomOrderTemplate::ensureDefault($this->other->id);
        $theirField = $theirs->fields()->create([
            'label' => 'حقلهم', 'type' => CustomOrderField::SHORT_TEXT, 'sort_order' => 0,
        ]);

        $this->save(['fields' => [
            ['id' => $theirField->id, 'label' => 'صار لي', 'type' => CustomOrderField::SHORT_TEXT],
        ]])->assertRedirect();

        $theirField->refresh();
        $this->assertSame($theirs->id, $theirField->template_id, 'حقلُ متجرٍ آخر انتقل بمعرّفٍ مُرسَل');
        $this->assertSame('حقلهم', $theirField->label);
    }

    /** ولا يُعاد ترتيبُ قوالب متجرٍ آخر بمعرّفٍ يُدسّ في القائمة */
    public function test_reorder_ignores_ids_from_another_shop(): void
    {
        $theirs = CustomOrderTemplate::ensureDefault($this->other->id);
        $theirs->update(['sort_order' => 7]);
        $mine = CustomOrderTemplate::ensureDefault($this->shop->id);

        $this->actingAs($this->owner)
            ->post('/admin/custom-order-templates/reorder', ['ids' => [$theirs->id, $mine->id]])
            ->assertRedirect();

        $this->assertSame(7, (int) $theirs->fresh()->sort_order, 'رتبةُ متجرٍ آخر تبدّلت');
        $this->assertSame(1, (int) $mine->fresh()->sort_order);
    }

    /* ══════════════ ٣ · البوّابة ══════════════ */

    /**
     * الميزةُ تُطفأ فيُردّ الطلبُ في الخادم لا في الشاشة وحدها.
     *
     * إخفاءُ زرٍّ لا يمنع طلبًا: من يعرف شكلَ الحمولة يرسلها. ومن أطفأها
     * أطفأها لسبب — كاشيرٌ يكتب أسعارًا بيده — فالبابُ يُقفل حيث يُحسب.
     */
    public function test_a_disabled_shop_refuses_a_custom_order(): void
    {
        CustomOrderTemplate::ensureDefault($this->shop->id);
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => CustomArrangement::ENABLED_KEY],
            ['value' => '0'],
        );

        $this->sell()->assertStatus(422)->assertJsonValidationErrors('items.0.custom');
    }

    /** وتعمل حين لا إعداد — الميزةُ قائمةٌ اليوم، وترقيةٌ تُطفئها عطب */
    public function test_a_shop_with_no_setting_still_sells(): void
    {
        CustomOrderTemplate::ensureDefault($this->shop->id);

        $this->sell()->assertOk();
    }

    /** والقالبُ الموقوف لا يُباع به ولو أُرسل معرّفُه */
    public function test_an_inactive_template_cannot_be_sold(): void
    {
        $template = CustomOrderTemplate::ensureDefault($this->shop->id);
        $template->update(['active' => false]);

        $this->sell(['template_id' => $template->id])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.custom.template_id');
    }

    /** وقالبُ متجرٍ آخر لا يُباع به — ولا يُقال أيُّ متجرٍ يملكه */
    public function test_another_shops_template_cannot_be_sold(): void
    {
        CustomOrderTemplate::ensureDefault($this->shop->id);
        $theirs = CustomOrderTemplate::ensureDefault($this->other->id);

        $this->sell(['template_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.custom.template_id');
    }

    /* ══════════════ ٤ · الحقولُ في الصندوق ══════════════ */

    /** حقلٌ إلزاميٌّ فارغٌ يُردّ — وإلّا خرج الطلبُ ناقصَ ما لا يُصنع بدونه */
    public function test_a_required_field_left_empty_is_refused(): void
    {
        $template = $this->templateWithField(['required' => true]);

        $this->sell(['template_id' => $template->id, 'fields' => []])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.custom.fields.0.value');
    }

    /** وخيارُ حقلٍ آخر يُردّ — الخيارُ يُفحص من حقله لا من الجدول كلِّه */
    public function test_an_option_from_another_field_is_refused(): void
    {
        $template = $this->templateWithField(['type' => CustomOrderField::SELECT], ['أحمر']);
        $intruder = $template->fields()->create([
            'label' => 'آخر', 'type' => CustomOrderField::SELECT, 'sort_order' => 1,
        ]);
        $alien = $intruder->options()->create(['label' => 'دخيل', 'sort_order' => 0]);

        $this->sell([
            'template_id' => $template->id,
            'fields' => [['field_id' => $template->fields->first()->id, 'value' => [$alien->id]]],
        ])->assertStatus(422);
    }

    /** و«اختيارٌ واحد» لا يقبل اثنين — وإلّا كان الاسمُ يكذب */
    public function test_a_single_select_refuses_two_options(): void
    {
        $template = $this->templateWithField(['type' => CustomOrderField::SELECT], ['أحمر', 'أزرق']);
        $ids = $template->fields->first()->options->pluck('id')->all();

        $this->sell([
            'template_id' => $template->id,
            'fields' => [['field_id' => $template->fields->first()->id, 'value' => $ids]],
        ])->assertStatus(422);
    }

    /** و«اختيارٌ متعدّد» يقبلهما */
    public function test_a_multi_select_takes_both(): void
    {
        $template = $this->templateWithField(['type' => CustomOrderField::MULTI_SELECT], ['أحمر', 'أزرق']);
        $ids = $template->fields->first()->options->pluck('id')->all();

        $this->sell([
            'template_id' => $template->id,
            'fields' => [['field_id' => $template->fields->first()->id, 'value' => $ids]],
        ])->assertOk();

        $this->assertSame(
            ['أحمر', 'أزرق'],
            CustomArrangement::view($this->soldItem()->custom_details)['fields'][0]['values'],
        );
    }

    /**
     * واللقطةُ تنجو من تعديل القالب.
     *
     * التسميةُ تُكتب مع الطلب لا تُقرأ من الصفّ الحيّ. ولو قُرئ الحيُّ
     * لَتبدّلت فاتورةُ الأمس حين يُعيد التاجرُ تسميةَ حقلٍ اليوم — ولَصار
     * حذفُ حقلٍ يمحو ما كُتب في طلباتٍ سُلّمت.
     */
    public function test_a_sold_order_keeps_its_wording_after_the_template_changes(): void
    {
        $template = $this->templateWithField(['label' => 'لون الشريطة', 'type' => CustomOrderField::SHORT_TEXT]);
        $field = $template->fields->first();

        $this->sell([
            'template_id' => $template->id,
            'fields' => [['field_id' => $field->id, 'value' => 'ذهبي']],
        ])->assertOk();

        $field->update(['label' => 'شيء آخر تمامًا']);
        $field->delete();
        $template->update(['name' => 'اسم جديد']);

        $view = CustomArrangement::view($this->soldItem()->custom_details);

        $this->assertSame('طلب على المقاس', $view['template']);
        $this->assertSame('لون الشريطة', $view['fields'][0]['label']);
        $this->assertSame(['ذهبي'], $view['fields'][0]['values']);
    }

    /* ══════════════ ٥ · ثلاثُ صناعاتٍ على محرّكٍ واحد ══════════════ */

    /**
     * ورودٌ وعطرٌ وتأجيرُ كراسٍ — ولا سطرَ في النظام يعرف أيَّها.
     *
     * وهذا هو الحارسُ الذي يقول إنّ التعميم تمّ: ثلاثةُ قوالبَ تختلف في
     * الأسئلة وفي التسعير وفي مصير الموادّ، وتمرّ كلُّها على `components`
     * و`fieldValues` و`details` أنفسِها.
     */
    public function test_three_trades_pass_through_the_same_engine(): void
    {
        $trades = [
            ['اسم' => 'باقة ورد', 'حقل' => 'ألوان الورد', 'يعود' => false, 'وضع' => CustomArrangement::MODE_VALUE],
            ['اسم' => 'عطر مركّب', 'حقل' => 'التركيز', 'يعود' => false, 'وضع' => CustomArrangement::MODE_BUDGET],
            ['اسم' => 'تأجير كراسٍ', 'حقل' => 'مدة الإيجار', 'يعود' => true, 'وضع' => CustomArrangement::MODE_VALUE],
        ];

        foreach ($trades as $trade) {
            $template = CustomOrderTemplate::create([
                'business_id' => $this->shop->id,
                'name' => $trade['اسم'],
                'modes' => CustomArrangement::MODES,
                'default_mode' => $trade['وضع'],
                'base_label' => 'قيمة '.$trade['اسم'],
                'allow_components' => true,
                'allow_addons' => true,
                'components_restockable_default' => $trade['يعود'],
                'active' => true,
                'sort_order' => 0,
            ]);
            $field = $template->fields()->create([
                'label' => $trade['حقل'], 'type' => CustomOrderField::SHORT_TEXT, 'sort_order' => 0,
            ]);

            $this->sell([
                'template_id' => $template->id,
                'mode' => $trade['وضع'],
                'fields' => [['field_id' => $field->id, 'value' => 'قيمة ما']],
                // ولا `restockable` تُرسَل: الافتراضُ يُقرأ من القالب
                'components' => [['product_id' => $this->material->id, 'quantity' => 2]],
            ])->assertOk();

            $item = $this->soldItem();

            $this->assertSame($trade['اسم'], $item->name, 'اسمُ البند لم يتبع القالب');
            $this->assertSame($trade['حقل'], CustomArrangement::view($item->custom_details)['fields'][0]['label']);
            $this->assertSame(
                $trade['يعود'],
                (bool) $item->components->first()->restockable,
                'سياسةُ الإرجاع لم تتبع القالب في: '.$trade['اسم'],
            );
        }
    }

    /**
     * وما يقوله الموظّفُ لمادّةٍ بعينها يعلو على افتراض القالب.
     *
     * الافتراضُ حكمُ عادةٍ لا حكمُ حالة: مؤجّرُ الكراسي يعود عنده كلُّ شيء
     * إلّا الكرسيَّ الذي انكسر، ومحلُّ الورد لا يعود عنده شيءٌ إلّا المزهريّةَ
     * التي أعادها الزبون. فالمقبضُ على الصفّ يعلو، في الاتّجاهين معًا.
     */
    public function test_the_clerk_overrides_the_templates_default_both_ways(): void
    {
        foreach ([[true, false], [false, true]] as [$default, $said]) {
            $template = CustomOrderTemplate::create([
                'business_id' => $this->shop->id,
                'name' => 'قالب '.($default ? 'يعود' : 'لا يعود'),
                'modes' => CustomArrangement::MODES,
                'default_mode' => CustomArrangement::MODE_VALUE,
                'allow_components' => true,
                'allow_addons' => true,
                'components_restockable_default' => $default,
                'active' => true,
                'sort_order' => 0,
            ]);

            $this->sell([
                'template_id' => $template->id,
                'components' => [[
                    'product_id' => $this->material->id, 'quantity' => 1, 'restockable' => $said,
                ]],
            ])->assertOk();

            $this->assertSame(
                $said,
                (bool) $this->soldItem()->components->first()->restockable,
                'ما قاله الموظّفُ لم يعلُ على افتراض القالب',
            );
        }
    }

    /**
     * ولا كلمةَ «ورد» في شيءٍ يكتبه النظام لمتجرٍ لم يقل «ورد».
     *
     * الحارسُ الأخير: لو بقي افتراضٌ مخبّأ — نوعُ مادّةٍ `flower` أو تسميةٌ
     * مكتوبة — لَظهر هنا في متجرٍ لم يُسمِّ قالبَه ولا حقلَه بذلك.
     */
    public function test_nothing_the_system_writes_names_a_trade(): void
    {
        $template = $this->templateWithField(['label' => 'التركيز', 'type' => CustomOrderField::SHORT_TEXT]);

        $this->sell([
            'template_id' => $template->id,
            'fields' => [['field_id' => $template->fields->first()->id, 'value' => 'ثقيل']],
        ])->assertOk();

        $item = $this->soldItem();

        $this->assertNull($item->components->first()->kind, 'نوعُ المادّة كُتب بلا أن يُقال');
        $this->assertStringNotContainsString('ورد', json_encode($item->custom_details, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('ورد', $item->name);
    }

    /* ══════════════ أدوات ══════════════ */

    private function templateWithField(array $over = [], array $options = []): CustomOrderTemplate
    {
        $template = CustomOrderTemplate::create([
            'business_id' => $this->shop->id,
            'name' => 'طلب على المقاس',
            'modes' => CustomArrangement::MODES,
            'default_mode' => CustomArrangement::MODE_VALUE,
            'allow_components' => true,
            'allow_addons' => true,
            'components_restockable_default' => false,
            'active' => true,
            'sort_order' => 0,
        ]);

        $field = $template->fields()->create(array_merge([
            'label' => 'سؤال', 'type' => CustomOrderField::SHORT_TEXT, 'sort_order' => 0,
        ], $over));

        foreach ($options as $at => $label) {
            $field->options()->create(['label' => $label, 'sort_order' => $at]);
        }

        return $template->fresh();
    }

    private function sell(array $custom = [])
    {
        $cashier = User::firstWhere('email', 'k@abaadapp.om') ?: User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'k@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        return $this->actingAs($cashier)->postJson('/pos/checkout', [
            'items' => [[
                'name' => 'مخصص',
                'qty' => 1,
                'custom' => array_merge([
                    'mode' => CustomArrangement::MODE_VALUE,
                    'price' => 20,
                    'base_value' => 20,
                    'components' => [
                        ['product_id' => $this->material->id, 'quantity' => 2, 'restockable' => false],
                    ],
                ], $custom),
            ]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
        ]);
    }

    private function soldItem()
    {
        return Order::latest('id')->with('items.components')->firstOrFail()->items->first();
    }

    /**
     * وكلُّ تسميةِ نوعِ حقلٍ لها ترجمة — ولا يمسكها فحصُ التغطية.
     *
     * ═══ العطب الذي كُتب هذا الحارس بعده ═══
     *
     * `TranslationCoverageTest` تقرأ المصدرَ وتلتقط `__('نصّ')` المكتوبةَ
     * حرفًا. وهذه التسمياتُ في ثابتٍ يُقرأ بمتغيّر — `__(TYPE_LABELS[$t])` —
     * فلا يراها الفحص. فمرّت كلُّها بلا ترجمة، وظهرت قائمةُ أنواع الحقول
     * عربيّةً وسطَ شاشةٍ إنجليزيّةٍ كاملة.
     *
     * والقاعدةُ: ما يُقرأ بمتغيّر يُسأل عنه بمصدره لا بنصِّه.
     */
    public function test_every_field_type_label_has_an_english_word(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true);

        $this->assertNotEmpty(CustomOrderField::TYPE_LABELS);

        foreach (CustomOrderField::TYPE_LABELS as $type => $label) {
            $this->assertArrayHasKey($label, $en, "تسميةُ النوع «{$type}» بلا ترجمة");
            $this->assertNotSame('', trim((string) $en[$label]));
        }
    }

    /** وحدُّ الخيارات لكلّ حقلٍ ثابتٌ واحد — لا رقمان في موضعين */
    public function test_the_option_limit_is_read_from_one_place(): void
    {
        $this->assertSame(100, CustomOrderFieldOption::MAX_PER_FIELD);
    }
}
