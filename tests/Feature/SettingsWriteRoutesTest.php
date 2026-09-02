<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثلاثةُ أبوابِ كتابةٍ في الإعدادات لم يكن يحرسها اختبار.
 *
 * مسحُ مسارات الكتابة في القسم أخرج أربعةً وأربعين، ثلاثةٌ منها بلا حالةٍ
 * واحدة: إنشاءُ حسابٍ في شجرة الحسابات، وحذفُ مسمًّى وظيفيّ، وحذفُ تقييم.
 * وثلاثتُها تعمل اليوم — وهذا بالضبط ما يجعل غيابَ الحارس خطرًا: لا شيء
 * يقول إنّ شيئًا انكسر حتى يكسره تعديلٌ بعيد.
 *
 * وأخطرُها الحذف: ما يُحذف لا يُستعاد بضغطةٍ ثانية، ومسارُ حذفٍ بلا قيدِ
 * مستأجرٍ يمحو دفترَ جارٍ برقمٍ مُخمَّن ولا يظهر في أيّ سجلّ.
 */
class SettingsWriteRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Business $neighbour;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /* -------------------------- شجرة الحسابات -------------------------- */

    public function test_an_account_is_created_from_the_settings_screen(): void
    {
        $this->post(route('admin.finance.chart.store'), [
            'code' => '1200', 'name' => 'حساب فحص', 'type' => 'أصل', 'normal_side' => 'debit',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'business_id' => $this->business->id, 'code' => '1200', 'name' => 'حساب فحص',
        ]);
    }

    public function test_the_same_account_code_is_not_used_twice(): void
    {
        /*
         * ورمزان متطابقان يجعلان القيد يُنسَب إلى أيّهما صادفه الاستعلام —
         * فيظهر المبلغ في حسابٍ ويغيب عن آخر، والميزان يتّزن وهو كاذب.
         */
        foreach ([1, 2] as $ignored) {
            $this->post(route('admin.finance.chart.store'), [
                'code' => '1300', 'name' => 'حساب', 'type' => 'أصل', 'normal_side' => 'debit',
            ]);
        }

        $this->assertSame(1, Account::where('business_id', $this->business->id)->where('code', '1300')->count());
    }

    public function test_a_neighbours_account_is_not_deleted_by_a_guessed_id(): void
    {
        $theirs = Account::create([
            'business_id' => $this->neighbour->id, 'code' => '9999', 'name' => 'حسابهم',
            'type' => 'أصل', 'normal_side' => 'debit',
        ]);

        $this->delete(route('admin.finance.chart.destroy', $theirs->id))->assertNotFound();

        $this->assertDatabaseHas('accounts', ['id' => $theirs->id]);
    }

    /* ------------------------- المسمّيات الوظيفية ------------------------- */

    public function test_an_unused_job_title_is_deleted(): void
    {
        $title = JobTitle::create(['business_id' => $this->business->id, 'name' => 'سائق', 'role' => 'cashier']);

        $this->delete(route('admin.jobTitles.destroy', $title->id))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('job_titles', ['id' => $title->id]);
    }

    public function test_a_job_title_someone_carries_is_kept(): void
    {
        /*
         * وحذفُه يترك موظّفًا بمسمًّى لا وجود له: تُفتح صفحتُه فلا يُعرف
         * موقعُه، وتُحسب صلاحيّاتُه على مسمًّى محذوف.
         */
        $title = JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => 'e@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'job_title' => $title->name,
        ]);

        $this->delete(route('admin.jobTitles.destroy', $title->id));

        $this->assertDatabaseHas('job_titles', ['id' => $title->id]);
    }

    public function test_a_neighbours_job_title_is_out_of_reach(): void
    {
        $theirs = JobTitle::create(['business_id' => $this->neighbour->id, 'name' => 'مسمّاهم', 'role' => 'cashier']);

        $this->delete(route('admin.jobTitles.destroy', $theirs->id))->assertNotFound();

        $this->assertDatabaseHas('job_titles', ['id' => $theirs->id]);
    }

    /* ------------------------------ التقييمات ------------------------------ */

    public function test_my_own_review_is_deleted(): void
    {
        $mine = Review::create([
            'business_id' => $this->business->id, 'author_name' => 'زبوني',
            'rating' => 4, 'comment' => 'جيد', 'status' => 'منشور',
        ]);

        $this->delete(route('admin.marketing.reviews.destroy', $mine->id))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('reviews', ['id' => $mine->id]);
    }

    public function test_a_neighbours_review_is_not_deleted_by_a_guessed_id(): void
    {
        /*
         * وهذا أشدُّ من اطّلاع: تقييمُ زبونٍ يُمحى من دفتر متجرٍ آخر، فينقص
         * متوسّطُ تقييمه ولا يعرف صاحبُه أنّ شيئًا ذهب.
         */
        $theirs = Review::create([
            'business_id' => $this->neighbour->id, 'author_name' => 'زبونهم',
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);

        $this->delete(route('admin.marketing.reviews.destroy', $theirs->id))->assertNotFound();

        $this->assertDatabaseHas('reviews', ['id' => $theirs->id]);
    }
}
