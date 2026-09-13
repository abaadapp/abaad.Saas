<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلُّ دورٍ يُجرَّب على كلّ مسارٍ — عبر النواة لا بنداءٍ مباشر.
 *
 * النداءُ المباشر يتخطّى الوسطاء، فيشهد على دالّةٍ لا على بابٍ مقفل.
 */
class EveryRoleIsProbedOnEveryRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_role_reaches_a_section_it_was_not_granted(): void
    {
        $shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);

        $leaks = [];
        $checked = 0;

        foreach (array_keys(Permissions::MAP) as $role) {
            if (Permissions::MAP[$role] === ['*']) {
                continue;
            }

            $user = User::create([
                'business_id' => $shop->id, 'name' => 'م '.$role, 'email' => $role.'@abaad.om',
                'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            ]);

            foreach (app('router')->getRoutes() as $route) {
                $name = $route->getName();

                if (! $name || ! (str_starts_with($name, 'admin.') || str_starts_with($name, 'pos.'))) {
                    continue;
                }

                if (Permissions::isShell($name)) {
                    continue;
                }

                /*
                 * وأفعالُ الكتابة تُجرَّب كما تُجرَّب القراءة — بل هي الأولى.
                 *
                 * بابٌ مقفلٌ على العرض ومفتوحٌ على الحذف أسوأ من بابين
                 * مفتوحين: من يقرأ يعرف، ومن يكتب يُتلف. والحارسُ يقع قبل
                 * المتحكّم، فلا يمسّ هذا النداءُ صفًّا واحدًا — وإن مسّه
                 * فذاك هو العطبُ الذي نبحث عنه.
                 */
                $verb = collect($route->methods())
                    ->first(fn ($m) => in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true));

                if ($verb === null) {
                    continue;
                }

                // مسارات ذات معاملات: تحتاج بيانات، والحارس يقع قبلها على كلّ حال
                if (str_contains($route->uri(), '{')) {
                    continue;
                }

                $section = Permissions::sectionFromRoute($name);

                if ($user->allows($section)) {
                    continue;
                }

                $checked++;
                $status = $this->actingAs($user)
                    ->call($verb, '/'.ltrim($route->uri(), '/'))
                    ->getStatusCode();

                // 403 هو المطلوب؛ و302 إعادةُ توجيهٍ مقبولةٌ (حارسٌ آخر سبقه)
                /*
                 * و٤٠٣ هو المطلوب. و٣٠٢ مقبولة: حارسٌ آخر سبقه إلى الردّ.
                 * وما عداهما تسرُّب — ٤٢٢ تعني أنّ الطلب وصل التحقّقَ من
                 * البيانات، أي أنّه عبَر الحارس.
                 */
                if (! in_array($status, [403, 302], true)) {
                    $leaks[] = $role.' → '.$verb.' '.$name.' ('.$section.') = '.$status;
                }
            }
        }

        $this->assertGreaterThan(700, $checked, 'لم يُجرَّب ما يكفي — الفحصُ نفسُه معطوب');
        $this->assertSame([], $leaks, "أدوارٌ وصلت إلى أقسامٍ لم تُمنح لها:\n".implode("\n", $leaks));
    }

    /**
     * وما مُنح لكلّ دورٍ مثبَّتٌ هنا بنصّه.
     *
     * ═══ ولمَ لا يكفي الاختبارُ أعلاه ═══
     *
     * ذاك يسأل: «أوصل دورٌ إلى ما لم يُمنح؟». فلو أُضيفت «الإعدادات» إلى
     * المحاسب لَصارت **ممنوحةً** — فلا يراها ذاك تسرُّبًا، ويمرّ صامتًا.
     * جُرّبت الطفرتان فنجتا، وهو ما كشف الثغرة.
     *
     * فتوسيعُ صلاحيةٍ قرارٌ يُتّخذ لا يقع. ومن يوسّعها يجب أن يمرّ من هنا
     * ويكتب ما وسّع — وإلّا فقد وُسّعت بسهوٍ أو بيدٍ لا تُريد أن تُرى.
     *
     * وتغييرُ هذه القائمة مشروع: عدّلها **عمدًا** حين توسّع عن قصد.
     */
    public function test_what_each_role_was_granted_is_pinned(): void
    {
        $this->assertSame([
            'admin' => ['*'],
            'manager' => ['*'],
            'accountant' => ['dashboard', 'orders', 'customers', 'finance', 'expenses',
                'employees', 'pos', 'reports', 'suppliers', 'purchases'],
            'inventory' => ['dashboard', 'products', 'inventory', 'suppliers', 'purchases', 'pos', 'preparation'],
            'sales' => ['dashboard', 'orders', 'customers', 'products', 'pos', 'preparation'],
            'cashier' => ['dashboard', 'pos'],
            'delivery' => ['dashboard', 'orders', 'pos', 'preparation'],
        ], Permissions::MAP, 'تغيّر ما يُمنح لدورٍ — إن كان عمدًا فحدّث هذا الحارس');
    }

    /**
     * ولا دورَ ثالثٌ يملك كلَّ شيء.
     *
     * `'*'` تفتح كلّ قسمٍ يُضاف بعد اليوم بلا أن يمرّ أحدٌ على قرار. فمن
     * يملكها اثنان يُعرفان بالاسم — والثالثُ يُكتب هنا أو لا يكون.
     */
    public function test_only_two_roles_own_everything(): void
    {
        $all = array_keys(array_filter(Permissions::MAP, fn ($s) => in_array('*', $s, true)));

        $this->assertSame(['admin', 'manager'], $all, 'دورٌ جديد مُنح كلَّ الأقسام');
    }
}
