<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * النطاق يخصّ متجرًا واحدًا — كما يخصّه عنوانُ متجره.
 *
 * ═══ ما كان يقع ═══
 *
 * `site_slug` (عنوان متجر أبعاد) يُفحص تفرّدُه عند الحفظ ويُردّ الثاني
 * بكلمةٍ يفهمها. و`site_domain` — النطاق الذي يملكه التاجر — كان يُقبل من
 * أيّ عدد من المتاجر بلا كلمة.
 *
 * وثمنُه أنّ العارض الخارجيّ يقرأ النطاق ليعرف صاحبه
 * (`PublishedSiteController`): صفٌّ واحد يُختار من صفّين متطابقين، بترتيبٍ
 * لا يضمنه محرّكُ قاعدةٍ لأحد. فالثاني يضبط نطاقه، ويوجّه DNS إليه، وينشر
 * موقعه — ويفتح الرابط فيرى **موقع متجرٍ آخر**. ولا شيء في لوحته يقول
 * لماذا: هي تقول «منشور»، والرابط يعمل، والمحتوى لغيره.
 *
 * والاتّجاه الآخر أسوأ: لو وقع الاختيار على الثاني لَصار زوّار الأوّل يرون
 * متجر من كتب نطاقه بعده.
 *
 * ═══ والقاعدة الأولى: من سبق ═══
 *
 * وهي القاعدة نفسها المطبَّقة على `site_slug` منذ بُني. ولا مِلكيّةَ نطاقٍ
 * تُتحقَّق هنا — تلك DNS لا قاعدة بيانات — وإنّما يُمنع صفّان لعنوانٍ واحد.
 */
class ADomainBelongsToOneShopTest extends TestCase
{
    use RefreshDatabase;

    private Business $first;

    private Business $second;

    private User $one;

    private User $two;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Business::create(['name' => 'محل أ', 'type' => 'عام', 'status' => 'نشط']);
        $this->second = Business::create(['name' => 'محل ب', 'type' => 'عام', 'status' => 'نشط']);

        $this->one = User::create([
            'business_id' => $this->first->id, 'name' => 'أ', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->two = User::create([
            'business_id' => $this->second->id, 'name' => 'ب', 'email' => 'b@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function save(User $as, string $domain)
    {
        return $this->actingAs($as)->post(route('admin.marketing.website.save'), [
            'site_on' => true, 'site_domain' => $domain,
        ]);
    }

    private function saved(Business $of): ?string
    {
        return Setting::where('business_id', $of->id)->where('key', 'site_domain')->value('value');
    }

    /* ------------------------------ الحجز ------------------------------ */

    public function test_the_first_shop_keeps_the_domain(): void
    {
        $this->save($this->one, 'wrood.om')->assertSessionHasNoErrors();

        $this->assertSame('wrood.om', $this->saved($this->first));
    }

    public function test_a_second_shop_is_refused(): void
    {
        $this->save($this->one, 'wrood.om')->assertSessionHasNoErrors();

        $this->save($this->two, 'wrood.om')->assertSessionHasErrors('site_domain');

        $this->assertNull($this->saved($this->second), 'كُتب النطاق للثاني رغم الرفض');
    }

    /** ولا صفّان لعنوانٍ واحد في القاعدة بحال */
    public function test_no_two_rows_carry_the_same_domain(): void
    {
        $this->save($this->one, 'wrood.om');
        $this->save($this->two, 'wrood.om');

        $this->assertSame(1, Setting::where('key', 'site_domain')->where('value', 'wrood.om')->count());
    }

    /** والحرفُ الكبير ليس نطاقًا آخر: النطاقات لا تفرّق بين الحالتين */
    public function test_the_same_domain_in_capitals_is_the_same_domain(): void
    {
        $this->save($this->one, 'wrood.om');

        $this->save($this->two, 'WROOD.OM')->assertSessionHasErrors('site_domain');
    }

    /**
     * ونطاقٌ قديمٌ حُفظ بحرفٍ كبير يُمسك أيضًا.
     *
     * ما حُفظ قبل هذا الحارس لم يُصغَّر عند الكتابة، والعارض يبحث بـ
     * `LOWER(value)`. فلو قارن الفحصُ النصَّ كما هو لَمرّ الثاني على نطاقٍ
     * محجوزٍ سلفًا — ثمّ اصطدما عند العارض حيث لا شاشة تقول شيئًا.
     */
    public function test_a_legacy_row_saved_in_capitals_is_still_taken(): void
    {
        Setting::create([
            'business_id' => $this->first->id, 'key' => 'site_domain', 'value' => 'WROOD.OM',
        ]);

        $this->save($this->two, 'wrood.om')->assertSessionHasErrors('site_domain');
    }

    /* ------------------------------ وما يجوز ------------------------------ */

    /** وصاحبُ النطاق يحفظ صفحته ثانيةً بلا أن يُردّ عن نطاقه هو */
    public function test_a_shop_may_save_its_own_domain_again(): void
    {
        $this->save($this->one, 'wrood.om');

        $this->save($this->one, 'wrood.om')->assertSessionHasNoErrors();

        $this->assertSame('wrood.om', $this->saved($this->first));
    }

    public function test_a_shop_may_change_its_domain(): void
    {
        $this->save($this->one, 'wrood.om');

        $this->save($this->one, 'zuhoor.om')->assertSessionHasNoErrors();

        $this->assertSame('zuhoor.om', $this->saved($this->first));
    }

    /** والذي أخلى نطاقَه يُخليه لغيره */
    public function test_a_released_domain_is_free_again(): void
    {
        $this->save($this->one, 'wrood.om');
        $this->actingAs($this->one)->post(route('admin.marketing.website.save'), [
            'site_on' => true, 'site_domain' => '',
        ])->assertSessionHasNoErrors();

        $this->save($this->two, 'wrood.om')->assertSessionHasNoErrors();

        $this->assertSame('wrood.om', $this->saved($this->second));
    }

    /**
     * والفراغُ ليس نطاقًا محجوزًا.
     *
     * كلُّ متجرٍ حفظ شاشة الموقع بلا نطاق له صفٌّ قيمتُه فارغة. فلو فُحص
     * الفراغُ كما يُفحص النطاق لَوجد صاحبَه عند جاره — ولَما استطاع أحدٌ أن
     * يُخلي نطاقه بعد أن ضبطه. قفلٌ يُغلق ولا يُفتح.
     */
    public function test_clearing_a_domain_is_never_refused_by_a_neighbours_blank(): void
    {
        $this->save($this->two, '')->assertSessionHasNoErrors();
        $this->save($this->one, 'wrood.om');

        $this->save($this->one, '')->assertSessionHasNoErrors();

        $this->assertSame('', $this->saved($this->first));
    }

    /** وحفظُ الصفحة بلا حقل النطاق أصلًا لا يمسّ ما ضُبط */
    public function test_saving_without_the_field_keeps_what_was_set(): void
    {
        $this->save($this->one, 'wrood.om');

        $this->actingAs($this->one)->post(route('admin.marketing.website.save'), ['site_on' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('wrood.om', $this->saved($this->first));
    }

    /* ------------------------- والعارضُ يجد صاحبه ------------------------- */

    /**
     * ومن حجز نطاقَه يصل زائرُه إلى موقعه هو.
     *
     * الرحلةُ كاملةً: يُبنى الموقع، ويُنشر، ويُحفظ النطاق — ثمّ يُطلب من
     * العارض الخارجيّ بذلك النطاق، فيردّ لقطةَ صاحبه باسمه.
     */
    public function test_the_viewer_serves_the_shop_that_claimed_the_domain(): void
    {
        $site = Builder::create($this->first, Blueprints::STORE, 'modern', $this->one->id);
        Publisher::publish($site, $this->one->id);
        $this->save($this->one, 'wrood.om');

        $body = $this->get(route('site.published', 'wrood.om'))->assertSuccessful()->json();

        $this->assertSame($site->name, $body['site']['name'] ?? null);
    }

    /** ولا يصل زائرُ نطاقٍ لم يحجزه أحد إلى موقع أحد */
    public function test_an_unclaimed_domain_finds_nobody(): void
    {
        $site = Builder::create($this->first, Blueprints::STORE, 'modern', $this->one->id);
        Publisher::publish($site, $this->one->id);
        $this->save($this->one, 'wrood.om');

        $this->get(route('site.published', 'other.om'))->assertNotFound();
    }
}
