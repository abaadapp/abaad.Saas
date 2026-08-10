<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كل صفحةٍ تُفتح بلا سقوط — لا عيّنةٌ منها.
 *
 * الاختبارات تغطّي ما فكّر أحدٌ في تغطيته، والصفحة التي لا اختبار لها تسقط
 * بصمت حتى يفتحها تاجر. وهذا الاختبار لا يفحص المعنى بل الحياة: يطلب كل
 * مسار GET بلا معاملات ويشترط ألّا يردّ 500.
 *
 * ويُشغَّل على قاعدةٍ فارغة عمدًا: المتجر الجديد أوّل من يفتح هذه الشاشات،
 * و«لا بيانات» حالةٌ تسقط فيها الصفحات أكثر من «بيانات كثيرة» — قسمةٌ على
 * صفر، أو `first()` يعود فارغًا فيُقرأ حقلٌ من null.
 */
class EveryPageAnswersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->platform = User::create([
            'name' => 'مدير المنصة', 'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** مسارات GET بلا معاملات — ما يمكن طلبه بلا اختراع معرّفات */
    private function plainGetRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->reject(fn (string $uri) => str_contains($uri, '{')
                || str_starts_with($uri, '_')
                || $uri === 'up'
                || str_starts_with($uri, 'storage'))
            ->unique()
            ->values()
            ->all();
    }

    public function test_no_page_falls_over_on_an_empty_store(): void
    {
        $broken = [];

        foreach ($this->plainGetRoutes() as $uri) {
            $as = str_starts_with($uri, 'super-admin') ? $this->platform : $this->owner;

            try {
                $res = $this->actingAs($as)->get('/'.$uri);
                $status = $res->getStatusCode();
                /*
                 * الردّ يُطلق بعد قراءة حالته: المسح يمرّ على أكثر من مئة صفحة،
                 * وبعضها يبني ملفّ xlsx في الذاكرة. وتركُها معلّقة يرفع أرضية
                 * العملية فتسقط اختباراتٌ بعده لا علاقة لها به — عطبٌ يبدو في
                 * غير موضعه.
                 */
                unset($res);
                gc_collect_cycles();
            } catch (\Throwable $e) {
                $broken[] = "/{$uri} — ".get_class($e).': '.$e->getMessage();

                continue;
            }

            // 200 يعمل، 302 يحرسه حارس، 404 مسارٌ يحتاج سياقًا (جهازًا أو وردية)
            if (! in_array($status, [200, 302, 404], true)) {
                $broken[] = "/{$uri} — {$status}";
            }
        }

        $this->assertSame([], $broken, "صفحات تسقط:\n".implode("\n", $broken));
    }

    public function test_no_page_falls_over_in_english_either(): void
    {
        /*
         * الإنجليزية ليست ترجمةً على السطح: القاموس يُرسل مع كل صفحة، ومفتاحٌ
         * ناقص أو صياغةٌ بمعاملٍ خاطئ (:n بلا قيمة) تسقط الصفحة عند من اختار
         * الإنجليزية وحده. وهم موظفو المتجر الذين لا يقرأون العربية.
         */
        $this->owner->update(['locale' => 'en']);
        $this->platform->update(['locale' => 'en']);
        $broken = [];

        foreach ($this->plainGetRoutes() as $uri) {
            $as = str_starts_with($uri, 'super-admin') ? $this->platform : $this->owner;

            try {
                $res = $this->actingAs($as)->get('/'.$uri);
                $status = $res->getStatusCode();
                /*
                 * الردّ يُطلق بعد قراءة حالته: المسح يمرّ على أكثر من مئة صفحة،
                 * وبعضها يبني ملفّ xlsx في الذاكرة. وتركُها معلّقة يرفع أرضية
                 * العملية فتسقط اختباراتٌ بعده لا علاقة لها به — عطبٌ يبدو في
                 * غير موضعه.
                 */
                unset($res);
                gc_collect_cycles();
            } catch (\Throwable $e) {
                $broken[] = "/{$uri} — ".$e->getMessage();

                continue;
            }

            if (! in_array($status, [200, 302, 404], true)) {
                $broken[] = "/{$uri} — {$status}";
            }
        }

        $this->assertSame([], $broken, "صفحات تسقط بالإنجليزية:\n".implode("\n", $broken));
    }

    public function test_the_english_dictionary_reaches_the_browser(): void
    {
        /*
         * الترجمة في هذا النظام تقع في المتصفّح: t() يقرأ قاموسًا يُرسل مع
         * الصفحة. فاكتمال lang/en.json لا يكفي — يجب أن يصل. ولو انقطع الجسر
         * لعادت الواجهة عربيةً كاملةً بلا خطأٍ واحد.
         */
        $this->owner->update(['locale' => 'en']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.settings.index'))
            ->viewData('page')['props'];

        $this->assertIsArray($props['translations'] ?? null, 'القاموس لا يصل الواجهة');
        $this->assertSame(
            'Manage your business branches',
            $props['translations']['إدارة فروع النشاط'] ?? null,
            'وصف بطاقات الإعدادات كان يُقرأ عربيًّا مهما اختار المستخدم',
        );
    }

    public function test_the_sweep_actually_covers_the_system(): void
    {
        /*
         * حارسٌ على الحارس: لو تغيّر شكل المسارات يومًا وصار المرشِّح يستبعد
         * كل شيء، لمرّ الاختبار الأول أخضرَ وهو لا يفحص صفحةً واحدة — وهو
         * أسوأ من غيابه، لأنه يُطمئن.
         */
        $this->assertGreaterThan(80, count($this->plainGetRoutes()));
    }
}
