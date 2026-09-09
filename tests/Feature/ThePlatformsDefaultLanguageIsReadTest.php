<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «اللغة الافتراضية» في إعدادات المنصّة — حقلٌ كان يُحفظ ولا يقرؤه شيء.
 *
 * ═══ ما كان ═══
 *
 * المشغّل يفتح إعدادات المنصّة، يختار الإنجليزية، يحفظ، ويقرأ «تم حفظ
 * إعدادات المنصة بنجاح». والصفُّ يُكتب فعلًا في `settings[null,'locale']`.
 * ثمّ لا يتغيّر شيء: `SetLocale` تقرأ الجلسةَ فتفضيلَ المستخدم فإعدادَ
 * النشاط ثمّ تسقط إلى العربية — ولا تمرّ بإعداد المنصّة أبدًا.
 *
 * وشاشتُه تَعِد بما لا يقع، بنصٍّ مكتوبٍ فيها: «لغة المنصة لمن لم يختر بعد».
 * ولا رسالةَ عطبٍ تدلّ المشغّل — مقبضٌ لا يُدير شيئًا، وهو أسوأ من غيابه
 * لأنّه يُطمئن.
 *
 * ═══ وأينَ موضعُه من السلسلة ═══
 *
 * بعد النشاط لا قبله: الأخصُّ يغلب. متجرٌ ضبط لغته لا تُبدّلها المنصّة عليه،
 * وموظّفٌ اختار لغته لا يُبدّلها عليه متجرُه.
 */
class ThePlatformsDefaultLanguageIsReadTest extends TestCase
{
    use RefreshDatabase;

    private function platform(string $locale): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'locale'], ['value' => $locale]);
    }

    private function operator(): User
    {
        return User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function shop(): Business
    {
        return Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function owner(Business $b, ?string $own = null): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
            'locale' => $own,
        ]);
    }

    /** لغةُ الواجهة بعد طلبٍ حقيقيّ يمرّ بالوسيط */
    private function localeAfterRequest(?User $as = null): string
    {
        $r = $as ? $this->actingAs($as) : $this;
        $r->get($as ? '/admin/dashboard' : '/login');

        return app()->getLocale();
    }

    /* ═══════════ الحقلُ صار يُقرأ ═══════════ */

    public function test_what_the_operator_saves_is_what_the_screen_speaks(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('super-admin.settings.update'), [
            'app_name' => 'Abad POS', 'locale' => 'en',
        ])->assertSessionHasNoErrors();

        $this->assertSame('en', Setting::whereNull('business_id')->where('key', 'locale')->value('value'));

        // جلسةٌ جديدة: لا اختيارَ فيها ولا تفضيلَ للمستخدم
        $this->flushSession();
        $this->assertSame('en', $this->localeAfterRequest($operator));
    }

    public function test_a_visitor_before_login_follows_the_platform(): void
    {
        // من لا حساب له ولا نشاط — وهو أوّل من يرى الشاشة
        $this->platform('en');

        $this->assertSame('en', $this->localeAfterRequest());
    }

    public function test_without_a_setting_it_is_still_arabic(): void
    {
        $this->assertSame('ar', $this->localeAfterRequest());
    }

    public function test_a_nonsense_value_does_not_break_the_screen(): void
    {
        // صفٌّ قديمٌ أو محرَّرٌ بيدٍ في القاعدة لا يُصدَّق
        $this->platform('fr');

        $this->assertSame('ar', $this->localeAfterRequest());
    }

    /* ═══════════ والأخصُّ يغلب ═══════════ */

    public function test_a_shop_that_set_its_language_is_not_overridden(): void
    {
        $this->platform('en');
        $shop = $this->shop();
        Setting::create(['business_id' => $shop->id, 'key' => 'locale', 'value' => 'ar']);

        $this->assertSame('ar', $this->localeAfterRequest($this->owner($shop)));
    }

    public function test_an_employee_who_chose_keeps_his_choice(): void
    {
        $this->platform('en');
        $shop = $this->shop();

        $this->assertSame('ar', $this->localeAfterRequest($this->owner($shop, own: 'ar')));
    }

    public function test_a_shop_with_no_language_of_its_own_follows_the_platform(): void
    {
        // وهو الحال الغالب: متجرٌ يُضاف ولا يفتح تبويب اللغة قطّ
        $this->platform('en');
        $shop = $this->shop();

        $this->assertSame('en', $this->localeAfterRequest($this->owner($shop)));
    }

    public function test_the_session_beats_everything(): void
    {
        $this->platform('en');

        $this->post(route('language.guest'), ['locale' => 'ar'])->assertRedirect();

        $this->assertSame('ar', $this->localeAfterRequest());
    }

    /* ═══════════ ولا تُسقط اللغةُ صفحةً ═══════════ */

    public function test_a_database_without_a_settings_table_still_serves_a_page(): void
    {
        /*
         * هذا الوسيط يجري على كلّ طلب — بما فيه أوّلُ طلبٍ على تنصيبٍ جديد
         * قبل أن تُهاجَر القاعدة. واستعلامٌ يسقط هنا يجعل أوّلَ ما يراه
         * المنصِّب صفحةَ عطبٍ لا صفحةَ دخول.
         *
         * وقد أسقطتُها فعلًا حين أضفتُ القراءة: `ExampleTest` — وهو يعمل بلا
         * هجرات — ردّ خمسمئة. فصار الحارسُ صريحًا لا مصادفةً في اختبارٍ آخر.
         */
        Schema::drop('settings');

        $this->get('/login')->assertOk();
        $this->assertSame('ar', app()->getLocale());
    }

    /* ═══════════ ولا صفوفَ ميّتةً في البذرة ═══════════ */

    public function test_the_seed_writes_no_platform_row_that_nothing_reads(): void
    {
        /*
         * `platform_name` اسمٌ قديم — الشاشةُ تكتب `app_name` و`PlatformConfig`
         * تقرؤه. و`currency` و`currency_decimals` يُقرآن من صفّ المتجر لا من
         * صفّ المنصّة. ثلاثةُ صفوفٍ تُزرع في كلّ تنصيبٍ جديد ولا يقرؤها سطر.
         */
        foreach (['database/seeders/DatabaseSeeder.php', 'database/seeders/DemoSeeder.php'] as $file) {
            $src = file_get_contents(base_path($file));

            $this->assertStringNotContainsString("[null, 'platform_name'", $src, $file);
            $this->assertStringNotContainsString("[null, 'currency'", $src, $file);
            $this->assertStringNotContainsString("[null, 'currency_decimals'", $src, $file);
            // و`vat_rate` يبقى — يقرؤه `Vat`
            $this->assertStringContainsString("[null, 'vat_rate', '5'],", $src, $file);
        }
    }
}
