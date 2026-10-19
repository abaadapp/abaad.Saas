<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Demo;
use App\Support\Lexicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المعجمُ يترجم الألفاظَ العامّة — الأقسامَ والمقاسات — ولا يترجم بضاعةَ التاجر.
 *
 * ولا نقلَ صوتيًّا ولا آلةَ ترجمة: معجمٌ مقفَل، وقاعدةٌ واحدة صارمة —
 * لفظٌ مجهول يُسقط الترجمة كلَّها. لأنّ نصفَ ترجمةٍ («Bouquet سالم») أسوأ
 * من لا ترجمة، والاسمُ يجب أن يبقى اسمًا.
 *
 * والصنفُ والإضافةُ لا يُترجمان عند الحفظ (قرار المالك، مراجعة #131): اسمُهما
 * الإنجليزيُّ ما كتبه التاجر أو لا يكون — انظر
 * `AShopsProductNamesAreNeverTranslatedForItTest`.
 */
class LexiconTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'x@test.local', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@x.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ الترتيب ------------------------------ */

    /**
     * الإنجليزية تقدّم الوصف على الموصوف، والعربية تؤخّره.
     *
     * فترجمةٌ لفظًا بلفظٍ تعطي «Bouquet roses red» — كلماتٌ إنجليزية
     * بترتيبٍ عربيّ، تُقرأ ولا تُفهم.
     */
    public function test_the_english_word_order_is_english_not_arabic(): void
    {
        $this->assertSame('Red rose bouquet', Lexicon::translate('باقة ورد أحمر'));
        $this->assertSame('Red roses', Lexicon::translate('الورود الحمراء'));
        $this->assertSame('Premium wrapping', Lexicon::translate('تغليف فاخر'));
        $this->assertSame('Gold ribbon', Lexicon::translate('شريط ذهبي'));
        $this->assertSame('Premium chocolate box', Lexicon::translate('علبة شوكولاتة فاخرة'));
    }

    /**
     * والأوصاف تُقلَب هي أيضًا.
     *
     * العربية تُلحق الأقربَ وصفًا بالموصوف أوّلًا — الوظيفة ثمّ اللون؛
     * والإنجليزية تعكسه فيسبق اللونُ الوظيفة.
     */
    public function test_two_adjectives_come_out_in_english_order(): void
    {
        $this->assertSame('Red potting soil', Lexicon::translate('تربة زراعية حمراء'));
        $this->assertSame('Red scented candle', Lexicon::translate('شمعة معطّرة حمراء'));
    }

    /**
     * والرقم يمرّ كما هو.
     *
     * «باقة ورد أحمر ١٥» رقمُها تسلسلٌ لا لغة له، ورفضُ العبارة لأجله
     * يترك مئةَ صنفٍ بلا ترجمة لسببٍ لا علاقة له باللغة.
     */
    public function test_a_trailing_number_passes_through_untouched(): void
    {
        $this->assertSame('Red rose bouquet 15', Lexicon::translate('باقة ورد أحمر 15'));
        $this->assertSame('White ornamental plant 21', Lexicon::translate('نبتة زينة أبيض 21'));
    }

    /**
     * والاسمُ الشاعريّ يبقى كما هو — وهو الصواب.
     *
     * «شمس الورد» و«لؤلؤة التوليب» أسماءُ أصنافٍ اختارها صاحبُ المحلّ،
     * وترجمتُها لفظًا بلفظٍ تعطي «Rose sun» — عبارةٌ لا يقصدها ولا يعرفها
     * زبونُه. فتبقى كما سُمّيت.
     */
    public function test_a_poetic_product_name_is_left_as_the_shop_named_it(): void
    {
        foreach (['شمس الورد', 'لؤلؤة التوليب', 'غيمة وردية', 'باقة همس الربيع'] as $name) {
            $this->assertNull(Lexicon::translate($name), $name);
        }
    }

    /** والاسمُ يُفرَد حين يصف غيره: «باقة ورد» لا تُقال «Roses bouquet» */
    public function test_a_noun_used_as_a_modifier_is_singular(): void
    {
        $this->assertSame('Rose bouquet', Lexicon::translate('باقة ورد'));
        $this->assertSame('Gift box', Lexicon::translate('صندوق هدايا'));
    }

    /** و«ال» و«من» لا أثر لهما في الإنجليزية */
    public function test_the_article_and_the_genitive_particle_vanish(): void
    {
        $this->assertSame('White rose bouquet', Lexicon::translate('باقة من ورد أبيض'));
    }

    /** وحرف الوصل يقسم العبارة ولو كُتب ملتصقًا */
    public function test_a_joined_conjunction_splits_the_phrase(): void
    {
        $this->assertSame('Roses and chocolate', Lexicon::translate('ورد وشوكولاتة'));
        // و«ورد» تبدأ بواوٍ وليست وصلًا — تُجرَّب كاملةً قبل أن تُشقّ
        $this->assertSame('Roses', Lexicon::translate('ورد'));
    }

    /* ------------------------------ الامتناع ----------------------------- */

    /**
     * لفظٌ واحد مجهول يُسقط العبارة كلَّها — وهذا هو المطلوب.
     */
    public function test_one_unknown_word_drops_the_whole_phrase(): void
    {
        $this->assertNull(Lexicon::translate('باقة سالم الخاصة'));
        $this->assertNull(Lexicon::translate('محمد سالم'));
        $this->assertNull(Lexicon::translate('عطر ليالي الشرق'));
    }

    /** وما كُتب باللاتينية لا يُترجَم — هو إنجليزيّ أصلًا */
    public function test_latin_input_is_left_alone(): void
    {
        $this->assertNull(Lexicon::translate('Red Roses'));
        $this->assertNull(Lexicon::translate(''));
        $this->assertNull(Lexicon::translate(null));
    }

    /* ------------------------------ التطبيق ------------------------------ */

    /**
     * وصنفٌ يعرف المعجمُ كلَّ لفظٍ فيه لا يُترجم له — اسمُه كما كتبه.
     *
     * كان يُحفظ «Red rose bouquet» بلا أن يكتبه أحد، فيقرأه الكاشيرُ والإيصالُ
     * الإنجليزيّ اسمًا تجاريًّا من اختراع النظام.
     */
    public function test_a_product_saved_in_arabic_keeps_no_invented_english_name(): void
    {
        $this->assertSame('Red rose bouquet', Lexicon::translate('باقة ورد أحمر'), 'المعجمُ يعرفه — فالامتناعُ قرارٌ لا عجز');

        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة ورد أحمر', 'price' => 15,
        ])->assertSessionHasNoErrors();

        $product = Product::firstOrFail();
        $this->assertNull($product->name_en);

        app()->setLocale('en');
        $this->assertSame('باقة ورد أحمر', Demo::ln($product->name, $product->name_en));
    }

    /**
     * وما يكتبه التاجر بيده يعلو على المعجم — هو أعلمُ ببضاعته.
     */
    public function test_a_hand_written_english_name_beats_the_lexicon(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة ورد أحمر', 'name_en' => 'Signature Red', 'price' => 15,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Signature Red', Product::firstOrFail()->name_en);
    }

    /** واسمٌ لا يعرفه المعجم يبقى كما كُتب، ويُقرأ بلغته في الشاشتين */
    public function test_an_unknown_product_name_stays_exactly_as_typed(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة سالم الخاصة', 'price' => 15,
        ])->assertSessionHasNoErrors();

        $product = Product::firstOrFail();
        $this->assertNull($product->name_en);

        app()->setLocale('en');
        $this->assertSame('باقة سالم الخاصة', Demo::ln($product->name, $product->name_en));
    }

    public function test_a_category_created_from_the_product_form_is_translated(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertOk();

        $this->assertSame('Bouquets', Category::firstOrFail()->name_en);
    }

    public function test_a_size_is_translated_too(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه', 'price' => 15, 'cost' => 5, 'quantity' => 5,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.variants.store', $product->id), ['name' => 'كبير', 'price' => 25])
            ->assertSessionHasNoErrors();

        $this->assertSame('Large', ProductVariant::firstOrFail()->name_en);
    }

    /* ------------------------------ الملء الرجعيّ ------------------------------ */

    /** والملءُ الرجعيُّ للأقسام والمقاسات وحدها — ولا يمسّ صنفًا ولا إضافة ولا مكتوبًا بيد */
    public function test_the_backfill_command_fills_only_categories_and_sizes_it_knows(): void
    {
        $roses = Product::create(['business_id' => $this->business->id, 'name' => 'ورد أبيض', 'price' => 2, 'cost' => 1, 'quantity' => 5]);
        $addon = \App\Models\Addon::create(['business_id' => $this->business->id, 'name' => 'تغليف', 'price' => 1, 'active' => true]);
        Category::create(['business_id' => $this->business->id, 'name' => 'باقات']);
        Category::create(['business_id' => $this->business->id, 'name' => 'هدايا', 'name_en' => 'Our Gifts']);
        Category::create(['business_id' => $this->business->id, 'name' => 'ركن سالم']);
        ProductVariant::create(['business_id' => $this->business->id, 'product_id' => $roses->id, 'name' => 'كبير', 'price' => 3, 'active' => true]);

        $this->artisan('catalog:translate-names')->assertSuccessful();

        $this->assertSame('Bouquets', Category::where('name', 'باقات')->value('name_en'));
        $this->assertSame('Large', ProductVariant::where('name', 'كبير')->value('name_en'));
        $this->assertNull(Category::where('name', 'ركن سالم')->value('name_en'), 'ما لا يعرفه المعجم يبقى');
        // والمكتوب بيدٍ لا يُمَسّ، والصنفُ والإضافةُ لا يُترجمان
        $this->assertSame('Our Gifts', Category::where('name', 'هدايا')->value('name_en'));
        $this->assertNull($roses->fresh()->name_en);
        $this->assertNull($addon->fresh()->name_en);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        Category::create(['business_id' => $this->business->id, 'name' => 'باقات']);

        $this->artisan('catalog:translate-names --dry-run')->assertSuccessful();

        $this->assertNull(Category::firstOrFail()->name_en);
    }
}
