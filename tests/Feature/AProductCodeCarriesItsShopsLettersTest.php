<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رمزُ الصنف يحمل حروفَ محلّه — لا حروفَ محلٍّ آخر.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * المولّدُ كان يكتب `FLW-#####` لكلّ متجر مهما كان: «FLW» من `flowers`،
 * كُتبت يومَ كان في النظام محلُّ وردٍ واحد. فمحلُّ الذهب يطبع على بطاقة
 * سوارٍ رمزًا يقول «زهور»، ومخزنُ قطع الغيار كذلك.
 *
 * ═══ وما يقع حين يقع ═══
 *
 * الرمزُ لا يبقى في الشاشة: يُطبع على الملصق، ويُقرأ في الجرد، ويُرسل في
 * ملفّ الاستيراد إلى المحاسب، ويُنسخ في جهاز المورّد. فنسبةٌ كاذبة تُكتب
 * مرّةً وتُقرأ سنين، ولا أحد يُصلحها بعدُ.
 *
 * ═══ وحدُّ الإصلاح ═══
 *
 * **ما كُتب لا يُمسّ.** متجرٌ رموزُه `FLW-` اليوم يبقى عليها: الإصلاحُ لا
 * يخلط بادئتين في متجرٍ واحد، ولا يلمس رمزًا محفوظًا ولا باركودًا مطبوعًا.
 * الحروفُ الجديدة لمن لم يُكتب له رمزٌ بعد وحده.
 */
class AProductCodeCarriesItsShopsLettersTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $name, ?string $nameEn = null): User
    {
        $business = Business::create([
            'name' => $name, 'name_en' => $nameEn, 'type' => 'عام', 'status' => 'نشط',
        ]);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        return User::create([
            'business_id' => $business->id, 'name' => 'المالك',
            'email' => 'o'.$business->id.'@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** يُنشئ صنفًا بلا رمز — فيُولّده النظام */
    private function addProduct(User $owner, string $name = 'صنف'): Product
    {
        $this->actingAs($owner)
            ->post(route('admin.products.store'), ['name' => $name, 'price' => 10])
            ->assertSessionHasNoErrors();

        return Product::where('business_id', $owner->business_id)->latest('id')->firstOrFail();
    }

    /* ═════════════ حروفُ المحلّ ═════════════ */

    public function test_a_shop_does_not_get_another_shops_letters(): void
    {
        $product = $this->addProduct($this->shop('محل الذهب', 'Gold House'));

        $this->assertStringStartsNotWith('FLW-', (string) $product->sku, 'رمزُ محلِّ ذهبٍ يقول «زهور»');
        $this->assertSame('GH-', substr((string) $product->sku, 0, 3));
    }

    /** واسمٌ من كلمةٍ واحدة يُؤخذ من أوّله */
    public function test_a_one_word_name_gives_its_first_letters(): void
    {
        $product = $this->addProduct($this->shop('RIBBON'));

        $this->assertSame('RIB-', substr((string) $product->sku, 0, 4));
    }

    /** والإنجليزيُّ أولى من العربيّ حين يُكتبان معًا */
    public function test_the_latin_name_is_preferred(): void
    {
        $product = $this->addProduct($this->shop('محل الورد', 'Rose Garden Muscat'));

        $this->assertSame('RGM-', substr((string) $product->sku, 0, 4));
    }

    /** والاسمُ الإنجليزيُّ هو المعتمَد ولو كان العربيُّ مكتوبًا بحروفٍ لاتينيّة */
    public function test_the_english_field_wins_over_a_transliterated_name(): void
    {
        $product = $this->addProduct($this->shop('Zahrat Muscat', 'Muscat Flowers'));

        $this->assertSame('MF-', substr((string) $product->sku, 0, 3), 'أُخذت الحروفُ من حقلِ الاسم لا من الإنجليزيّ');
    }

    /** واسمٌ بلا حرفٍ لاتينيّ يأخذ حياديًّا — ملصقٌ لا يقرأ العربيّة */
    public function test_an_arabic_only_name_falls_back_to_a_neutral_code(): void
    {
        $product = $this->addProduct($this->shop('محل العطور'));

        $this->assertSame('SKU-', substr((string) $product->sku, 0, 4));
    }

    /* ═════════════ وما كُتب لا يُمسّ ═════════════ */

    /** متجرٌ رموزُه `FLW-` يبقى عليها — ولا تُخلط بادئتان في محلٍّ واحد */
    public function test_a_shop_that_already_codes_one_way_keeps_its_way(): void
    {
        $owner = $this->shop('محل الورد', 'Rose Garden');

        Product::create([
            'business_id' => $owner->business_id, 'name' => 'وردة', 'price' => 5, 'sku' => 'FLW-00012',
        ]);

        $product = $this->addProduct($owner, 'وردة أخرى');

        $this->assertSame('FLW-', substr((string) $product->sku, 0, 4), 'بادئتان في متجرٍ واحد');
    }

    /** ولا تُقرأ بادئةُ محلٍّ من أصناف محلٍّ آخر — الرمزُ لصاحبه */
    public function test_one_shops_codes_do_not_leak_into_another(): void
    {
        $neighbour = $this->shop('محل الورد', 'Flower Land');
        Product::create([
            'business_id' => $neighbour->business_id, 'name' => 'وردة', 'price' => 5, 'sku' => 'FLW-00012',
        ]);

        $product = $this->addProduct($this->shop('Gold House'));

        $this->assertSame('GH-', substr((string) $product->sku, 0, 3), 'بادئةُ جارٍ تسرّبت إلى رموز محلٍّ آخر');
    }

    /** والأكثرُ شيوعًا هو المتَّبع — لا آخرُ رمزٍ كُتب مهما شذّ */
    public function test_the_common_prefix_wins_not_the_odd_one(): void
    {
        $owner = $this->shop('Gold House');

        foreach (['GLD-0001', 'GLD-0002', 'GLD-0003'] as $sku) {
            Product::create(['business_id' => $owner->business_id, 'name' => $sku, 'price' => 5, 'sku' => $sku]);
        }
        Product::create(['business_id' => $owner->business_id, 'name' => 'شاذّ', 'price' => 5, 'sku' => 'XX-9999']);

        $this->assertSame('GLD-', substr((string) $this->addProduct($owner)->sku, 0, 4));
    }

    /** ورمزٌ كتبه التاجرُ بيده لا يُولَّد فوقه */
    public function test_a_code_written_by_hand_is_kept(): void
    {
        $owner = $this->shop('Gold House');

        $this->actingAs($owner)
            ->post(route('admin.products.store'), ['name' => 'سوار', 'price' => 10, 'sku' => 'MY-CODE-7'])
            ->assertSessionHasNoErrors();

        $this->assertSame('MY-CODE-7', Product::where('business_id', $owner->business_id)->latest('id')->first()->sku);
    }

    /* ═════════════ وما كان يحرسه المولّدُ يبقى محروسًا ═════════════ */

    /** الرمزُ فريدٌ داخل المحلّ — ولو تكرّرت البادئة */
    public function test_generated_codes_do_not_repeat_inside_a_shop(): void
    {
        $owner = $this->shop('Gold House');

        $codes = [];

        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->addProduct($owner, 'صنف '.$i)->sku;
        }

        $this->assertCount(12, array_unique($codes), 'رمزان متطابقان في محلٍّ واحد');
    }

    /** ومحلّان يحملان الحروفَ نفسَها لا يتزاحمان: الفرادةُ داخل المحلّ */
    public function test_two_shops_may_share_letters(): void
    {
        $one = $this->addProduct($this->shop('Gold House'));
        $two = $this->addProduct($this->shop('Gold House'));

        $this->assertSame('GH-', substr((string) $one->sku, 0, 3));
        $this->assertSame('GH-', substr((string) $two->sku, 0, 3));
    }

    /** والرمزُ رقمٌ بعد الحروف — خمسُ خاناتٍ كما كان */
    public function test_the_shape_of_the_code_is_unchanged(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Z]{2,5}-\d{5}$/',
            (string) $this->addProduct($this->shop('Gold House'))->sku,
        );
    }
}
