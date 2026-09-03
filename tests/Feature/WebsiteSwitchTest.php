<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\MarketingSettings;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تفعيل الموقع الإلكتروني — ومفتاحٌ يُدير ما يقول إنّه يديره.
 *
 * كان الإطفاء الوحيد أن يمحو التاجر نطاقه: من أراد أن يُخفي موقعه شهرًا —
 * يُعاد بناؤه، أو انتهى حجزُه — خسر ما كتبه ثمّ عاد يبحث عنه. وكانت في
 * الإعدادات بطاقتان تَعِدان بموقع ولا تحملان مفتاحًا: «إعدادات الدومين»
 * توعد بـ«نشره للزوّار»، و«إعدادات الموقع» توعد بـ«ما يراه زائر موقعك»
 * وتفتح على رافع شعار.
 *
 * وما يفحصه هذا الملفّ ليس أنّ المفتاح يُحفظ، بل أنّ **ما وعدت به البطاقة
 * تحته يقع**: زرُّ الترويسة يختفي، والفحص يقول «مُطفأ» لا «بلا نطاق»،
 * والنطاق يبقى محفوظًا فلا يُعاد كتابتُه عند التشغيل.
 */
class WebsiteSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.website.save'), $data);
    }

    /* --------------------------- الحفظ --------------------------- */

    public function test_the_switch_and_the_domain_are_saved_together(): void
    {
        $this->save(['site_on' => true, 'site_domain' => 'mystore.om'])->assertSessionHasNoErrors();

        $saved = MarketingSettings::group($this->business->id, 'website');

        $this->assertSame('1', $saved['site_on']);
        $this->assertSame('mystore.om', $saved['site_domain']);
    }

    /**
     * والإطفاء يُخزَّن '0' لا سلسلةً فارغة.
     *
     * `false` يُكتب فارغًا، و`group` تعدّ الفارغةَ قصدًا لا غيابًا فتردّها كما
     * هي — ثمّ تُقارن بـ'1' فتكون خطأً بالمصادفة لا بالضبط. والمصادفة تنقلب.
     */
    public function test_switching_off_is_written_as_a_zero(): void
    {
        $this->save(['site_on' => false, 'site_domain' => 'mystore.om'])->assertSessionHasNoErrors();

        $this->assertSame('0', Setting::where('business_id', $this->business->id)
            ->where('key', 'site_on')->value('value'));
    }

    /** والنطاق لا يُمحى بالإطفاء: من أطفأ شهرًا يُشغّل بضغطة لا بكتابةٍ من جديد */
    public function test_the_domain_survives_being_switched_off(): void
    {
        $this->save(['site_on' => true, 'site_domain' => 'mystore.om']);
        $this->save(['site_on' => false, 'site_domain' => 'mystore.om']);

        $this->assertSame(
            'mystore.om',
            MarketingSettings::group($this->business->id, 'website')['site_domain'],
        );
    }

    /* ----------------------- وما يُديره المفتاح ----------------------- */

    /** زرُّ «الموقع الإلكتروني» في الشريط العلوي */
    public function test_the_header_button_follows_the_switch(): void
    {
        $this->actingAs($this->owner);

        $this->save(['site_on' => true, 'site_domain' => 'mystore.om']);
        $this->assertSame('https://mystore.om', Demo::websiteUrl());

        $this->save(['site_on' => false, 'site_domain' => 'mystore.om']);
        $this->assertNull(Demo::websiteUrl(), 'الزرّ يفتح موقعًا أطفأه صاحبُه');
    }

    /** والشاشةُ تقرؤه من المصدر نفسه لا من عمودٍ ثانٍ */
    public function test_the_shared_context_carries_the_same_answer(): void
    {
        $this->save(['site_on' => false, 'site_domain' => 'mystore.om']);

        $props = $this->actingAs($this->owner)->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($props['context']['website']);
    }

    /**
     * وفحصُ «الظهور في البحث» يقول «مُطفأ» لا «بلا نطاق».
     *
     * الأولى قرارٌ يُلغى بمفتاح، والثانية نقصٌ يُكمَل بكتابة نطاق. وجمعُهما
     * في رسالةٍ واحدة يجعل من أطفأ موقعه يبحث عن نطاقٍ كتبه بالفعل.
     */
    public function test_the_search_audit_names_the_switch_not_a_missing_domain(): void
    {
        $this->actingAs($this->owner);

        $this->save(['site_on' => false, 'site_domain' => 'mystore.om']);
        $this->assertSame('off', Seo::check($this->business->id)['state']);

        $this->save(['site_on' => true, 'site_domain' => '']);
        $this->assertSame('nodomain', Seo::check($this->business->id)['state']);
    }

    /* --------------------------- الميّت يبقى ميّتًا --------------------------- */

    /**
     * و`site_enabled` القديم لا يُبعث.
     *
     * كان معناه «انشر متجري للزوّار» ولا متجرَ يُنشر، فرُفع. وصفوفُه قد تبقى
     * في قاعدة متجرٍ رفعها يومًا أو في نسخةٍ تُستعاد — فلو حمل المفتاحُ الجديد
     * اسمَه لَأطفأت قيمةٌ قديمة موقعًا لم يطلب صاحبُه إطفاءه.
     */
    public function test_the_old_dead_key_never_governs_the_new_switch(): void
    {
        $this->actingAs($this->owner);

        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_enabled', 'value' => '0',
        ]);
        $this->save(['site_on' => true, 'site_domain' => 'mystore.om']);

        $this->assertSame('https://mystore.om', Demo::websiteUrl(), 'مفتاحٌ ميّت أطفأ موقعًا مفعَّلًا');
    }

    /** ومن ضبط نطاقه قبل المفتاح يبقى زرُّه يعمل بلا أن يفتح شيئًا */
    public function test_a_domain_set_before_the_switch_existed_keeps_working(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'old.om',
        ]);

        $this->actingAs($this->owner);

        $this->assertSame('https://old.om', Demo::websiteUrl());
    }
}
