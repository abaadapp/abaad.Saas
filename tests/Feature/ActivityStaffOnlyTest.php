<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * سجلّ نشاط التاجر — الموظفون وحدهم.
 *
 * الشاشة ليست أرشيفًا لكل ما جرى، بل جوابٌ عن سؤال: ماذا فعل فريقي في
 * غيابي؟ فيُستثنى صاحب النشاط — أفعالُه هو تدفع أفعالَ من يجب أن يُراقَبوا
 * خارج الصفحة الأولى — ويُستثنى مدير المنصة معه.
 *
 * والأثر لا يُمحى: سجلّ المنصة يحتفظ بكل شيء، وعليه علامة من فعله.
 */
class ActivityStaffOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'صاحب النشاط',
            'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'كاشير',
            'email' => 'cashier@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'status' => 'نشط',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function logsSeenByOwner(): array
    {
        return $this->actingAs($this->owner)
            ->get(route('admin.activity.index'))
            ->assertOk()
            ->viewData('page')['props']['logs'];
    }

    public function test_an_employees_action_is_what_the_screen_is_for(): void
    {
        $this->actingAs($this->cashier);
        Activity::log('checkout', 'أتمّ بيعة');

        $logs = $this->logsSeenByOwner();

        $this->assertCount(1, $logs);
        $this->assertSame('كاشير', $logs[0]['user']);
    }

    public function test_the_owner_does_not_read_their_own_clicks(): void
    {
        /*
         * كل ضغطةٍ من صاحب النشاط كانت تُقيَّد وتظهر له: يفتح الشاشة ليعرف ما
         * فعل فريقه فيجد صفحةً أولى مليئة بأفعاله هو، وقد دُفع ما جاء يقرؤه
         * إلى صفحاتٍ لا يفتحها.
         */
        $this->actingAs($this->owner);
        Activity::log('updated', 'عدّل منتجًا');
        Activity::log('login', 'سجّل الدخول');

        $this->assertSame([], $this->logsSeenByOwner());
    }

    public function test_the_platform_admin_does_not_appear_there_either(): void
    {
        $platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($platform);
        Activity::log('updated', 'عدّل إعدادًا', ['business_id' => $this->business->id]);

        $this->assertSame([], $this->logsSeenByOwner());
    }

    public function test_a_neighbours_employee_never_appears(): void
    {
        // الاستثناء لا يفتح بابًا: الحدّ بين المتاجر يبقى قائمًا كما كان
        $other = Business::create(['name' => 'متجر الجار', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id,
            'name' => 'كاشير الجار',
            'email' => 'stranger@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'status' => 'نشط',
        ]);

        $this->actingAs($stranger);
        Activity::log('checkout', 'أتمّ بيعة');

        $this->assertSame([], $this->logsSeenByOwner());
    }

    public function test_the_platform_log_still_sees_everything(): void
    {
        /*
         * الاستثناء عرضٌ لا حذف. ولو كان حذفًا لضاع ما يُحتجّ به يوم يُنازَع
         * في فعلٍ: من فعله، ومتى، ومن أيّ عنوان.
         */
        $platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
        Activity::log('updated', 'عدّل منتجًا');

        $this->actingAs($platform)
            ->get(route('super-admin.activity.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('logs.0.description', 'عدّل منتجًا'));
    }
}
