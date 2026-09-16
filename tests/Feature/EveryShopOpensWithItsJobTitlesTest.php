<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\DemoStore;
use App\Support\MerchantAccount;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كلُّ متجرٍ يُفتح ووظائفُه فيه — ولا مسمًّى يصنع صاحبَ نشاطٍ ثانيًا.
 *
 * ═══ ١ · الستُّ بُذرت مرّةً، ومن جاء بعدها لم يرثها ═══
 *
 * هجرةُ ٢٠٢٦-٠٧-٢١ بذرت الوظائفَ الستَّ لمن كان قائمًا يومَها. وكانت
 * `MerchantAccount::provision` تبذر **واحدةً**: «مدير» دورُها `admin`.
 *
 * فيفتح التاجرُ الجديد «الرواتب والموظفين» فلا يجد مسمًّى لكاشيرٍ ولا لبائعٍ
 * ولا لمحاسب. والوحيدُ الجاهز **يصنع صاحبَ نشاطٍ ثانيًا**: من أُسندت إليه
 * صار دورُه `admin` — يملك كلَّ قسم، ويمسّ كلَّ حساب، ويعيد تعيين كلمة مرور
 * المالك. وعلى الإنتاج متجرٌ كهذا: «تيست 1» بوظيفةٍ واحدة دورُها `admin`.
 *
 * ═══ ٢ · واسمُ الوظيفة يُخزَّن فلا يُترجَم ═══
 *
 * `users.job_title` نصٌّ لا معرّف — هو مفتاحُ الربط. فبذرُه بـ`__()` يكتب في
 * القاعدة ما تقوله لغةُ **لحظة الإنشاء**، ويكتب ما يُبذَر بيدٍ أخرى عربيًّا
 * دائمًا. فيفترق الاسمان ويخرج موظّفٌ على وظيفةٍ لا وجودَ لها.
 *
 * ═══ ٣ · ومسمًّى قديمٌ لا صفَّ له كان يحبس صاحبَه ═══
 *
 * «يوسف السيابي» في المتجر التجريبيّ يحمل «أمين مخزن» — كتبتها `DemoStore`
 * بيدها بينما تبذر الوظائفُ «مسؤول مخزون». فكلُّ حفظةٍ لصفّه تُردّ: لا اسمَه
 * ولا رقمَ هاتفه ولا تعطيلَ حسابه ولا إعادةَ كلمة مروره. حسابٌ يعمل ولا يُدار.
 */
class EveryShopOpensWithItsJobTitlesTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $name = 'متجر جديد'): Business
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        return $shop;
    }

    /* ══════════════ ١ · ما يجده التاجرُ يومَ يفتح ══════════════ */

    /** الستُّ كلُّها — لا واحدةٌ منها */
    public function test_a_new_shop_is_provisioned_with_every_staff_job_title(): void
    {
        $shop = $this->shop();
        MerchantAccount::provision($shop, 'new@abaadapp.om', bcrypt('password12345'));

        $titles = JobTitle::where('business_id', $shop->id)->pluck('role', 'name');

        $this->assertSame(
            collect(Roles::staffNames())->sort()->values()->all(),
            $titles->keys()->sort()->values()->all(),
            'متجرٌ جديد لا يجد صاحبُه مسمّياتِ موظّفيه',
        );

        foreach (Roles::staffNames() as $role => $name) {
            $this->assertSame($role, $titles[$name], "المسمّى «{$name}» لا يحمل دورَه");
        }
    }

    /** ولا مسمًّى فيها يصنع مالكًا */
    public function test_no_seeded_job_title_mints_an_owner(): void
    {
        $shop = $this->shop();
        MerchantAccount::provision($shop, 'new@abaadapp.om', bcrypt('password12345'));

        $this->assertNotContains(
            'admin',
            JobTitle::where('business_id', $shop->id)->pluck('role')->all(),
            'مسمًّى يُسنَد يحمل دورَ صاحب النشاط',
        );
    }

    /**
     * وصاحبُ النشاط لا مسمّى له في الجدول — وهو ما تقوله `Roles::STAFF`.
     *
     * «دون `admin` لأنه صاحب النشاط نفسه: وظيفةٌ تمنحه لأيّ موظّف تمنحه كلَّ
     * شيء». فوظيفتُه نصٌّ يُعرض لا صفٌّ يُسنَد.
     */
    public function test_the_owners_job_title_is_a_label_not_an_assignable_row(): void
    {
        $shop = $this->shop();
        $owner = MerchantAccount::provision($shop, 'new@abaadapp.om', bcrypt('password12345'));

        $this->assertSame(Roles::name('admin'), $owner->job_title);
        $this->assertNull(JobTitle::where('business_id', $shop->id)->where('name', $owner->job_title)->first());
    }

    /* ══════════════ ٢ · الاسمُ يُخزَّن فلا يُترجَم ══════════════ */

    /** لغةُ لحظةِ الإنشاء لا تُكتب في القاعدة */
    public function test_job_titles_are_seeded_in_one_tongue_whatever_the_locale(): void
    {
        $this->app->setLocale('en');
        $english = $this->shop('English shop');
        MerchantAccount::provision($english, 'en@abaadapp.om', bcrypt('password12345'));

        $this->app->setLocale('ar');
        $arabic = $this->shop('متجر عربي');
        MerchantAccount::provision($arabic, 'ar@abaadapp.om', bcrypt('password12345'));

        $this->assertSame(
            JobTitle::where('business_id', $arabic->id)->orderBy('name')->pluck('name')->all(),
            JobTitle::where('business_id', $english->id)->orderBy('name')->pluck('name')->all(),
            'لغةُ الواجهة كتبت أسماءَ الوظائف في القاعدة',
        );
    }

    /** والمتجرُ التجريبيّ لا يُخرج موظّفًا على وظيفةٍ لا وجودَ لها */
    public function test_the_demo_shop_leaves_no_employee_on_a_missing_job_title(): void
    {
        DemoStore::create('متجر العرض', 'صغير');

        $orphans = User::whereNotNull('business_id')->whereNotNull('job_title')
            ->where('role', '!=', 'super_admin')
            ->get()
            ->filter(fn ($u) => $u->role !== 'admin' && ! JobTitle::where('business_id', $u->business_id)
                ->where('name', $u->job_title)->exists())
            ->map(fn ($u) => $u->name.' → «'.$u->job_title.'»');

        $this->assertSame([], $orphans->values()->all(), 'موظّفون على وظيفةٍ لا وجودَ لها');
    }

    /* ══════════════ ٣ · ومسمًّى قديمٌ لا يحبس صاحبَه ══════════════ */

    private function shopWithOwnerAndOrphan(): array
    {
        $shop = $this->shop();
        $owner = MerchantAccount::provision($shop, 'own@abaadapp.om', bcrypt('password12345'));

        $orphan = User::create([
            'business_id' => $shop->id, 'name' => 'يوسف', 'email' => 'y@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'inventory',
            'job_title' => 'أمين مخزن', 'status' => 'نشط', 'phone' => '90000000',
        ]);

        return [$shop, $owner, $orphan];
    }

    /** يُصحَّح رقمُ هاتفه وإن كان مسمّاه لا صفَّ له */
    public function test_an_employee_on_a_vanished_job_title_can_still_be_saved(): void
    {
        [, $owner, $orphan] = $this->shopWithOwnerAndOrphan();

        $this->actingAs($owner)
            ->put(route('admin.employees.update', $orphan->id), [
                'name' => 'يوسف السيابي', 'job_title' => 'أمين مخزن', 'phone' => '99887766',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $orphan->fresh();
        $this->assertSame('99887766', $fresh->phone);
        // ولا دورَ يُشتقّ من مسمًّى لا وجودَ له، ولا مسمًّى يُمحى
        $this->assertSame('inventory', $fresh->role);
        $this->assertSame('أمين مخزن', $fresh->job_title);
    }

    /** وتبديلُه إلى مسمًّى لا صفَّ له يبقى مردودًا — الاسمُ يُختار من قائمة */
    public function test_moving_someone_onto_a_name_that_is_not_a_job_title_is_still_refused(): void
    {
        [, $owner, $orphan] = $this->shopWithOwnerAndOrphan();

        $this->actingAs($owner)
            ->put(route('admin.employees.update', $orphan->id), [
                'name' => 'يوسف', 'job_title' => 'مسمّى مخترَع',
            ])->assertSessionHasErrors('job_title');

        $this->assertSame('أمين مخزن', $orphan->fresh()->job_title);
    }

    /**
     * والحرّاسُ تجري على المسمّى القديم كما تجري على غيره.
     *
     * لولا ذلك لصار المسمّى المفقود **بابًا**: يُفتح صفُّ صاحبه فيُمنح ما لا
     * يملكه المانح، ولا يُقاس شيء.
     */
    public function test_a_vanished_job_title_is_not_a_hole_in_the_grant_guard(): void
    {
        [$shop, , $orphan] = $this->shopWithOwnerAndOrphan();

        /*
         * والمستهدَفُ **دون** الفاعل عمدًا.
         *
         * `refuseTouchingSomeoneAboveMe` تقع أوّلًا؛ فلو كان المستهدَف أوسع
         * لَردّته هي ولَما بلغ الطلبُ حارسَ المنح أصلًا — فيمرّ الاختبارُ
         * بجوابٍ صحيحٍ من بابٍ آخر، ويُقال إنّ الثاني محروسٌ وهو مرفوع.
         * جُرّبت الطفرةُ فنجت، وهو ما كشفه.
         */
        $orphan->update(['permissions' => ['pos']]);

        $clerk = User::create([
            'business_id' => $shop->id, 'name' => 'كاتب', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['employees', 'pos'],
        ]);

        $this->actingAs($clerk)
            ->put(route('admin.employees.update', $orphan->id), [
                'name' => 'يوسف', 'job_title' => 'أمين مخزن',
                'manual_permissions' => true, 'permissions' => ['employees', 'pos', 'finance'],
            ])->assertForbidden();

        $this->assertNotContains('finance', $orphan->fresh()->permissions ?? []);
    }

    /* ══════════════ ٤ · وما كُتب قبل المصدر الواحد يُصلَح ══════════════ */

    /** الهجرةُ تبذر ما نقص ولا تُزدوج ما غُطِّي دورُه باسمٍ آخر */
    public function test_the_backfill_covers_a_role_without_duplicating_an_older_name(): void
    {
        $shop = $this->shop();
        // متجرٌ قديم: «مدير» لدور manager — تسميةُ الهجرة الأولى
        JobTitle::create(['business_id' => $shop->id, 'name' => 'مدير', 'role' => 'manager']);

        JobTitle::seedDefaults($shop->id);

        $titles = JobTitle::where('business_id', $shop->id)->pluck('name')->all();
        $this->assertContains('مدير', $titles);
        $this->assertNotContains('مدير فرع', $titles, 'مسمّيان لدورٍ واحد في شاشة التاجر');
        $this->assertSame(
            count(Roles::staffNames()),
            count($titles),
            'الأدوارُ الستّة تُغطّى مرّةً واحدة',
        );
    }

    /** ومسمًّى دورُه `admin` يُنزَل إلى أعلى دورٍ يُسنَد */
    public function test_the_backfill_disarms_an_owner_minting_job_title(): void
    {
        $shop = $this->shop();
        JobTitle::create(['business_id' => $shop->id, 'name' => 'مدير', 'role' => 'admin']);

        DB::table('migrations')->where('migration', 'like', '%every_shop_gets_the_six_job_titles')->delete();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertSame('manager', JobTitle::where('business_id', $shop->id)->where('name', 'مدير')->firstOrFail()->role);
        $this->assertNotContains('admin', JobTitle::pluck('role')->all());

        // وتبذر ما نقص في الحركة نفسِها — وإلّا بقي المتجرُ بمسمًّى واحد
        $this->assertSame(
            collect(Roles::staffNames())->keys()->sort()->values()->all(),
            JobTitle::where('business_id', $shop->id)->pluck('role')->sort()->values()->all(),
            'الهجرةُ نزعت السلاحَ ولم تبذر ما نقص',
        );
    }
}
