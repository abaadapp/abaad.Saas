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
 * ما يكتبه التاجر بالعربية يُقرأ بالإنجليزية — أو يبقى كما كُتب.
 *
 * ولا نقلَ صوتيًّا ولا آلةَ ترجمة: معجمٌ مقفَل، وقاعدةٌ واحدة صارمة —
 * لفظٌ مجهول يُسقط الترجمة كلَّها. لأنّ نصفَ ترجمةٍ («Bouquet سالم») أسوأ
 * من لا ترجمة، والاسمُ يجب أن يبقى اسمًا.
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

    public function test_a_product_saved_in_arabic_gets_an_english_name(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة ورد أحمر', 'price' => 15,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Red rose bouquet', Product::firstOrFail()->name_en);
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

    public function test_the_backfill_command_fills_only_what_it_knows(): void
    {
        Product::create(['business_id' => $this->business->id, 'name' => 'ورد أبيض', 'price' => 2, 'cost' => 1, 'quantity' => 5]);
        Product::create(['business_id' => $this->business->id, 'name' => 'باقة سالم', 'price' => 9, 'cost' => 3, 'quantity' => 5]);
        Product::create([
            'business_id' => $this->business->id, 'name' => 'تغليف', 'name_en' => 'Gift Wrap',
            'price' => 1, 'cost' => 0.5, 'quantity' => 5,
        ]);

        $this->artisan('catalog:translate-names')->assertSuccessful();

        $this->assertSame('White roses', Product::where('name', 'ورد أبيض')->value('name_en'));
        $this->assertNull(Product::where('name', 'باقة سالم')->value('name_en'));
        // والمكتوب بيدٍ لا يُمَسّ
        $this->assertSame('Gift Wrap', Product::where('name', 'تغليف')->value('name_en'));
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        Product::create(['business_id' => $this->business->id, 'name' => 'ورد أبيض', 'price' => 2, 'cost' => 1, 'quantity' => 5]);

        $this->artisan('catalog:translate-names --dry-run')->assertSuccessful();

        $this->assertNull(Product::firstOrFail()->name_en);
    }
}
