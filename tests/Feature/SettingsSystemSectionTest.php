<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * أقسام «النظام» تُفتح داخل صفحة الإعدادات.
 *
 * كانت بطاقاتها الخمس تنقل إلى صفحاتٍ مستقلّة بهيئةٍ أخرى — عمودٌ جانبي لا
 * وجود له في سواها. صارت تُفتح مكان اللوحة كبقيّة الأقسام، والقسم يُطلب في
 * الرابط لأن بياناته تُحسب على الخادم.
 */
class SettingsSystemSectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->admin = User::create([
            'business_id' => $business->id,
            'name' => 'سالم',
            'email' => 'salem@abaad.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);
    }

    public function test_settings_without_a_section_carries_no_section_data(): void
    {
        /*
         * أثقل ما في الصفحة لا يُحسب لمن لم يطلبه: جدول الفروع وسجلّ النشاط
         * يفتحهما القليل، ويدفع ثمنهما كل من فتح الإعدادات لو أُرسلا دائمًا.
         */
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->where('section', null)
                ->missing('branches'));
    }

    public function test_asking_for_the_branches_section_brings_its_data(): void
    {
        Branch::create(['business_id' => $this->admin->business_id, 'name' => 'فرع صحار']);

        $this->actingAs($this->admin)
            ->get(route('admin.settings.index', ['section' => 'branches']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->where('section', 'branches')
                ->has('branches', 2)
                ->where('branches.1.name', 'فرع صحار'));
    }

    /**
     * الأقسام الخمسة كلّها تُفتح هنا — لا واحدة منها تقفز بالمستخدم إلى هيئةٍ
     * أخرى. والاسم في الاختبار هو مفتاح البطاقة نفسه في SETTINGS_NAV.
     *
     * @return array<string, array{string, string}>
     */
    public static function systemSections(): array
    {
        return [
            'الفروع' => ['branches', 'branches'],
            'الموظفون' => ['employees', 'employees'],
            'الأجهزة' => ['devices', 'devices'],
            'سجل النشاط' => ['activity', 'logs'],
            'المحذوفات' => ['trash', 'products'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('systemSections')]
    public function test_every_system_section_opens_inside_settings(string $section, string $prop): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index', ['section' => $section]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->where('section', $section)
                ->has($prop));
    }

    public function test_a_made_up_section_is_ignored_not_obeyed(): void
    {
        // القيمة تصل من شريط العنوان: لا تُصدَّق، ولا تُسقط الصفحة
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index', ['section' => '../../etc/passwd']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('branches'));
    }

    public function test_the_standalone_branches_page_still_answers(): void
    {
        /*
         * الطريق المعتاد صار من داخل الإعدادات، لكن الرابط المباشر منشورٌ في
         * روابط محفوظة — وكسرُ رابطٍ يعمل أسوأ من صفحةٍ لا يزورها إلا القليل.
         */
        $this->actingAs($this->admin)
            ->get(route('admin.branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Branches/Index')->has('branches'));
    }

    public function test_adding_a_branch_returns_to_the_open_section(): void
    {
        /*
         * أهمّ ما في هذا الملفّ.
         *
         * الحفظ يعود بـback()، فلو كان القسم محفوظًا في المرساة وحدها لعاد
         * المستخدم إلى لوحة البطاقات بعد كل إضافة — يبحث عن الفرع الذي أضافه
         * ولا يجده أمامه.
         */
        $from = route('admin.settings.index', ['section' => 'branches']);

        $this->actingAs($this->admin)
            ->from($from)
            ->post(route('admin.branches.store'), [
                'name' => 'فرع نزوى',
                'phone' => '',
                'address' => '',
            ])
            ->assertRedirect($from);

        $this->assertDatabaseHas('branches', [
            'business_id' => $this->admin->business_id,
            'name' => 'فرع نزوى',
        ]);
    }
}
