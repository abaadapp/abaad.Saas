<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\PageController;
use App\Models\Setting;
use App\Models\User;
use App\Support\Archive\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحةُ المنصّة تعرض سياسةَ الأرشفة التي يعمل بها النظام — وتحفظ ما يُكتب.
 *
 * ═══ العطبُ الذي وُجد ═══
 *
 * `SETTING_DEFAULTS` تحمل عشرين مفتاحًا، **ولا واحدَ منها للأرشفة**.
 * و`platformSettings` تقرأ من القاعدة ما كان مفتاحُه فيها وحدَه. فكانت
 * حقولُ الأرشفة لا تصلها قيمةٌ أبدًا:
 *
 *  ١. «مدة الاحتفاظ» و«الأسبوعيّة» و«أقصى حجم» تُرسم فارغةً دائمًا،
 *     والنظامُ يعمل بـ١٢ و١٢ و٥٠٠.
 *  ٢. ما يُحفظ لا يعود إلى الشاشة.
 *  ٣. والنموذجُ واحدٌ لتبويبات الشاشة كلِّها، فحفظةٌ من تبويب «عامة» ترسل
 *     الحقلَ الفارغ فتمحو ما حُفظ — ويعود النظامُ إلى افتراضه صامتًا.
 *
 * ولم يمسكه حارسٌ قائم: `ThePlatformScreenCarriesOnlyItsOwnSettingsTest`
 * يقيس أنّ الشاشة لا تحمل ما ليس لها، ولا يقيس أنّها تحمل ما لها.
 */
class ThePlatformPanelShowsTheArchivePolicyItRunsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->super);
    }

    /** @return array<string, mixed> */
    private function shown(): array
    {
        return $this->get(route('super-admin.settings.index'))
            ->assertOk()->viewData('page')['props']['settings'];
    }

    /** حفظٌ كما ترسله الشاشة — ونموذجُها واحدٌ لتبويباتها كلِّها */
    private function save(array $data): void
    {
        $this->post(route('super-admin.settings.update'), ['default_plan' => ''] + $data)
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /** المقابضُ الثمانية — تُقرأ من خريطة الشاشة لا تُكتب هنا باليد */
    private function knobs(): array
    {
        return [
            Policy::ENABLED, Policy::MANUAL, Policy::RETENTION_MONTHS,
            Policy::WEEKLY, Policy::WEEKLY_RETENTION_WEEKS, Policy::MAX_MB,
            Policy::REMOTE_DISK, Policy::BACKUP_REMOTE,
        ];
    }

    public function test_the_screen_carries_every_archive_knob(): void
    {
        $shown = $this->shown();

        foreach ($this->knobs() as $key) {
            $this->assertArrayHasKey(
                $key,
                $shown,
                "المقبض «{$key}» يُعرض في الشاشة ولا تصله قيمة — فيُرسم فارغًا ويُمحى عند أوّل حفظ",
            );
        }
    }

    public function test_what_the_screen_shows_is_what_the_system_runs(): void
    {
        $shown = $this->shown();

        // ولا رقمان يقولان الشيء نفسه: المعروضُ هو ما تقرؤه السياسة
        $this->assertSame((string) Policy::retentionMonths(), (string) $shown[Policy::RETENTION_MONTHS]);
        $this->assertSame((string) Policy::retentionWeeks(), (string) $shown[Policy::WEEKLY_RETENTION_WEEKS]);
        $this->assertSame((string) Policy::DEFAULT_MAX_MB, (string) $shown[Policy::MAX_MB]);
        $this->assertSame(Policy::enabled(), $shown[Policy::ENABLED] !== '0');
        $this->assertSame(Policy::manualAllowed(), $shown[Policy::MANUAL] !== '0');
        $this->assertSame(Policy::weeklyEnabled(), $shown[Policy::WEEKLY] !== '0');

        /*
         * ولا يُدَّعى نسخٌ بعيدٌ بلا قرص.
         *
         * مقبضٌ يُرى مُشعَلًا يقول «نسخُك يخرج من هذا الخادم» — وكلُّ نسخةٍ
         * على قرصه نفسِه. وطمأنينةٌ كاذبة عن نسخةٍ احتياطيّة أسوأ ما يُقال
         * لصاحب منصّة: لا يكتشف كذبَها إلّا يوم يحتاجها.
         */
        $this->assertSame(Policy::remoteConfigured(), Policy::remoteDisk() !== null);
        $this->assertFalse(Policy::backupRemoteEnabled(), 'التهيئةُ بلا قرصٍ — والحارسُ لا يقيس شيئًا');
        $this->assertSame('0', $shown[Policy::BACKUP_REMOTE], 'الشاشةُ تقول «يُنسخ بعيدًا» ولا قرصَ ولا نسخ');
        $this->assertSame('', (string) $shown[Policy::REMOTE_DISK], 'الشاشةُ تسمّي قرصًا لا وجود له');
    }

    public function test_a_saved_policy_comes_back_to_the_screen(): void
    {
        $this->save([Policy::RETENTION_MONTHS => 24, Policy::WEEKLY_RETENTION_WEEKS => 8, Policy::MAX_MB => 900]);

        $this->assertSame(24, Policy::retentionMonths());

        $shown = $this->shown();
        $this->assertSame('24', (string) $shown[Policy::RETENTION_MONTHS], 'ما حُفظ لا يعود إلى الشاشة');
        $this->assertSame('8', (string) $shown[Policy::WEEKLY_RETENTION_WEEKS]);
        $this->assertSame('900', (string) $shown[Policy::MAX_MB]);
    }

    /*
     * ═══ وحفظةٌ من تبويبٍ آخر لا تمحو سياسةَ الأرشفة ═══
     *
     * النموذجُ واحدٌ للتبويبات كلِّها: من يبدّل اسمَ التطبيق يرسل معه كلَّ
     * حقلٍ في الشاشة. فلو بُنيت حقولُ الأرشفة من فراغٍ لَمُحيت مدّةُ
     * الاحتفاظ التي كتبها بالأمس، وعاد النظامُ إلى افتراضه بلا كلمة.
     */
    public function test_saving_another_tab_does_not_wipe_the_archive_policy(): void
    {
        $this->save([Policy::RETENTION_MONTHS => 24]);

        // ثمّ يُفتح التبويبُ العامّ ويُحفظ — بالقيم التي تصل الشاشةَ فعلًا
        $shown = $this->shown();
        $this->save([
            'app_name' => 'أبعاد',
            Policy::RETENTION_MONTHS => $shown[Policy::RETENTION_MONTHS],
        ]);

        $this->assertSame(24, Policy::retentionMonths(), 'حفظةٌ من تبويبٍ آخر محت سياسةَ الأرشفة');
    }

    public function test_the_written_defaults_did_not_drift_from_the_policy(): void
    {
        /*
         * والأرقامُ مكتوبةٌ حرفًا في `SETTING_DEFAULTS` لأنّ `const` لا يقبل
         * التحويل إلى نصّ في PHP 8.4. فيبقى ما يمنع افتراقَها: هذا الحارس.
         */
        $defaults = (new \ReflectionClass(PageController::class))->getConstant('SETTING_DEFAULTS');

        $this->assertSame((string) Policy::DEFAULT_RETENTION_MONTHS, $defaults[Policy::RETENTION_MONTHS]);
        $this->assertSame((string) Policy::DEFAULT_WEEKLY_RETENTION_WEEKS, $defaults[Policy::WEEKLY_RETENTION_WEEKS]);
        $this->assertSame((string) Policy::DEFAULT_MAX_MB, $defaults[Policy::MAX_MB]);
        $this->assertSame('', $defaults[Policy::REMOTE_DISK], 'قرصٌ بعيدٌ يُدَّعى وجودُه ولا وجود له');
        $this->assertSame('0', $defaults[Policy::BACKUP_REMOTE], 'نسخٌ بعيدٌ يُدَّعى وقوعُه بلا قرص');
    }

    public function test_every_knob_the_screen_saves_is_one_the_door_accepts(): void
    {
        /*
         * مفتاحٌ تُرسله الشاشةُ ولا يقبله `KEYS` يسقط صامتًا في `validate`:
         * يضغط المشغّل «حفظ» فيقرأ «تم الحفظ» ولا يُكتب شيء.
         */
        $accepted = array_keys(
            (new \ReflectionClass(\App\Http\Controllers\SuperAdmin\SettingController::class))->getConstant('KEYS')
        );

        foreach ($this->knobs() as $key) {
            $this->assertContains($key, $accepted, "الشاشةُ ترسل «{$key}» والبابُ لا يقبله");
        }
    }

    public function test_a_shop_owner_cannot_change_the_platform_policy(): void
    {
        $business = \App\Models\Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)
            ->post(route('super-admin.settings.update'), ['archive_retention_months' => 1])
            ->assertForbidden();

        $this->assertNull(Setting::whereNull('business_id')->where('key', Policy::RETENTION_MONTHS)->value('value'));
    }
}
