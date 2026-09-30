<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\MerchantAccount;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المسمّى الوظيفيّ الذي يكتبه متجرٌ لا يراه متجرٌ آخر — في أيّ باب.
 *
 * ═══ البلاغ ═══
 *
 * «أضاف تاجرٌ مسمًّى وظيفيًّا جديدًا في متجره، فظهر حين فُتح متجرٌ آخر.»
 *
 * والستُّ الافتراضيّة (مدير فرع، محاسب، مسؤول مخزون، موظف مبيعات، مندوب
 * توصيل، كاشير) ليست دليلًا: تُبذَر لكلّ متجرٍ **صفوفًا له** (`seedDefaults`).
 * فالحارسُ هنا مسمًّى **مخترَع** بعلامةٍ لا تتكرّر، يُبحث عنه في **الجواب
 * كلِّه** لا في خاصيّةٍ بعينها.
 *
 * ═══ وما يثبته هذا الملفّ ═══
 *
 * أنّ الخادم لا يسرّب: الصفُّ يُكتب لصاحبه وحده، وكلُّ شاشةٍ تقرأ متجرَها،
 * وكلُّ بابِ كتابةٍ يُردّ عن صفّ غيره. فإن رأى تاجرٌ مسمّى غيره فمن ذاكرة
 * المتصفّح لا من الخادم — وذاك حارسُه في `EncryptHistoryAcrossShopsTest` وفي
 * `tests/js/employee-form-follows-its-shop.test.tsx`.
 */
class AJobTitleNeverLeavesItsShopTest extends TestCase
{
    use RefreshDatabase;

    private const A_ONLY = 'TENANT-A-ONLY-TITLE-9F31';

    private const B_ONLY = 'TENANT-B-ONLY-TITLE-7C42';

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->a, $this->ownerA] = $this->shop('متجر A', 'owner-a@abaad.om');
        [$this->b, $this->ownerB] = $this->shop('متجر B', 'owner-b@abaad.om');
    }

    /** متجرٌ حقيقيّ كما يُفتح: فرعٌ، ومالكٌ، ووظائفُه الستّ */
    private function shop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط', 'tier' => 'gold']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي '.$name]);
        $owner = MerchantAccount::provision($shop, $email, bcrypt('password12345'));

        return [$shop, $owner];
    }

    /** يكتب مسمًّى من شاشة صاحبه — كما يكتبه هو */
    private function addTitle(User $as, string $name, array $extra = []): void
    {
        $this->actingAs($as)->post(route('admin.jobTitles.store'), ['name' => $name] + $extra)->assertRedirect();
    }

    private function assertAbsent(string $body, string $sentinel, string $where): void
    {
        $this->assertStringNotContainsString($sentinel, $body, "«{$sentinel}» ظهر في {$where} عند المتجر الآخر");
    }

    /** جوابُ صفحة Inertia كاملًا نصًّا — لا خاصيّةً منه */
    private function page(User $as, string $url): string
    {
        return json_encode($this->actingAs($as)->get($url)->assertOk()->viewData('page'), JSON_UNESCAPED_UNICODE);
    }

    /* ══════════════ ١ · القاعدة ══════════════ */

    /** (١–٣) المسمّى يُكتب لصاحبه وحده */
    public function test_a_custom_title_is_written_for_its_own_shop_only(): void
    {
        $this->addTitle($this->ownerA, self::A_ONLY);

        $row = JobTitle::where('name', self::A_ONLY)->sole();
        $this->assertSame($this->a->id, (int) $row->business_id);
        $this->assertSame(0, JobTitle::where('business_id', $this->b->id)->where('name', self::A_ONLY)->count());
    }

    /** (١١) و`business_id` في الطلب لا يُسمع — المتجرُ من الجلسة */
    public function test_a_hostile_business_id_in_the_payload_is_ignored(): void
    {
        foreach (['business_id', 'bid', 'tenant_id', 'shop_id'] as $field) {
            $this->addTitle($this->ownerA, self::A_ONLY.'-'.$field, [$field => $this->b->id]);

            $row = JobTitle::where('name', self::A_ONLY.'-'.$field)->sole();
            $this->assertSame($this->a->id, (int) $row->business_id, "«{$field}» في الطلب كتب المسمّى لمتجرٍ آخر");
        }

        $this->assertSame(0, JobTitle::where('business_id', $this->b->id)->where('name', 'like', 'TENANT-A%')->count());
    }

    /** (١٢) والاسمُ نفسُه يعيش في المتجرين مستقلًّا */
    public function test_the_same_title_text_may_live_in_both_shops_independently(): void
    {
        $this->addTitle($this->ownerA, 'مصمم باقات');
        $this->addTitle($this->ownerB, 'مصمم باقات');

        $this->assertSame(2, JobTitle::where('name', 'مصمم باقات')->count());
        $this->assertEqualsCanonicalizing(
            [$this->a->id, $this->b->id],
            JobTitle::where('name', 'مصمم باقات')->pluck('business_id')->map(fn ($v) => (int) $v)->all(),
        );
    }

    /* ══════════════ ٢ · ما يُقرأ ══════════════ */

    /** (٤–٧) لا شاشةَ عند B تحمل مسمّى A — في الجواب كلِّه */
    public function test_no_screen_of_shop_b_carries_shop_a_title(): void
    {
        $this->addTitle($this->ownerA, self::A_ONLY);
        $this->addTitle($this->ownerB, self::B_ONLY);

        $employeeB = User::create([
            'business_id' => $this->b->id, 'name' => 'موظف B', 'email' => 'emp-b@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $screens = [
            'قائمة الموظفين' => route('admin.employees.index'),
            'إضافة موظف' => route('admin.employees.create'),
            'تعديل موظف' => route('admin.employees.edit', $employeeB->id),
            'ملفّ موظف' => route('admin.employees.show', $employeeB->id),
            'إعدادات الموظفين' => route('admin.settings.index', ['section' => 'employees']),
        ];

        foreach ($screens as $where => $url) {
            $body = $this->page($this->ownerB, $url);

            $this->assertAbsent($body, self::A_ONLY, $where);
        }

        // والشاشةُ تقرأ متجرَها فعلًا — لا فراغًا يمرّ من الفحص السابق
        $create = $this->actingAs($this->ownerB)->get(route('admin.employees.create'))->viewData('page')['props'];
        $this->assertContains(self::B_ONLY, $create['jobTitles']);
        $this->assertArrayHasKey(self::B_ONLY, $create['titleGrants']);
        $this->assertArrayNotHasKey(self::A_ONLY, $create['titleGrants']);
        $this->assertNotContains(self::A_ONLY, $create['blockedTitles']);

        $edit = $this->actingAs($this->ownerB)->get(route('admin.employees.edit', $employeeB->id))->viewData('page')['props'];
        $this->assertContains(self::B_ONLY, $edit['jobTitles']);
        $this->assertNotContains(self::A_ONLY, $edit['jobTitles']);
        $this->assertArrayNotHasKey(self::A_ONLY, $edit['titleGrants']);
        $this->assertNotContains(self::A_ONLY, $edit['blockedTitles']);
    }

    /* ══════════════ ٣ · ما يُكتب ══════════════ */

    /** (٨) موظّفُ B لا يُسند إليه مسمّى A — لا عند الإنشاء ولا عند التعديل */
    public function test_shop_b_cannot_put_an_employee_on_shop_a_title(): void
    {
        $this->addTitle($this->ownerA, self::A_ONLY);

        $this->actingAs($this->ownerB)->post(route('admin.employees.store'), [
            'name' => 'متسلّل', 'login_username' => 'sneaky.b', 'job_title' => self::A_ONLY,
            'password' => 'password12345', 'password_confirmation' => 'password12345',
        ])->assertSessionHasErrors('job_title');
        $this->assertSame(0, User::where('job_title', self::A_ONLY)->count());

        $employeeB = User::create([
            'business_id' => $this->b->id, 'name' => 'موظف B', 'email' => 'emp-b@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $this->actingAs($this->ownerB)->put(route('admin.employees.update', $employeeB->id), [
            'name' => 'موظف B', 'job_title' => self::A_ONLY,
        ])->assertSessionHasErrors('job_title');
        $this->assertSame('كاشير', $employeeB->fresh()->job_title);
    }

    /** (٩–١٠) معرّفُ مسمّى A بيد B: لا تعديلَ ولا حذف */
    public function test_shop_b_cannot_update_or_delete_shop_a_title_by_id(): void
    {
        $this->addTitle($this->ownerA, self::A_ONLY);
        $row = JobTitle::where('name', self::A_ONLY)->sole();

        $this->actingAs($this->ownerB)
            ->put(route('admin.jobTitles.update', $row->id), ['name' => 'سُرق'])
            ->assertNotFound();
        $this->actingAs($this->ownerB)
            ->delete(route('admin.jobTitles.destroy', $row->id))
            ->assertNotFound();

        $this->assertSame(self::A_ONLY, $row->fresh()?->name, 'مسمّى A تبدّل أو حُذف بيد B');
    }

    /* ══════════════ ٤ · الستُّ الافتراضيّة ══════════════ */

    /** (١٧) صفوفٌ لكلّ متجر — لا صفٌّ مشترك */
    public function test_the_six_defaults_are_separate_rows_per_shop(): void
    {
        foreach (Roles::staffNames() as $name) {
            $rows = JobTitle::where('name', $name)->get();

            $this->assertCount(2, $rows, "«{$name}» ليس صفًّا لكلّ متجر");
            $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $rows->pluck('business_id')->map(fn ($v) => (int) $v)->all());
        }
    }

    /** وإعادةُ تسمية افتراضيٍّ عند A لا تمسّ B */
    public function test_renaming_a_default_in_one_shop_leaves_the_other(): void
    {
        $cashierA = JobTitle::where('business_id', $this->a->id)->where('name', 'كاشير')->sole();

        $this->actingAs($this->ownerA)->put(route('admin.jobTitles.update', $cashierA->id), ['name' => 'أمين صندوق'])->assertRedirect();

        $this->assertSame('أمين صندوق', $cashierA->fresh()->name);
        $this->assertSame(1, JobTitle::where('business_id', $this->b->id)->where('name', 'كاشير')->count());
        $this->assertSame(0, JobTitle::where('business_id', $this->b->id)->where('name', 'أمين صندوق')->count());
    }

    /** وحذفُ مسمًّى مخترَعٍ عند صاحبه يحذفه عنده وحده */
    public function test_deleting_a_custom_title_touches_only_its_owner(): void
    {
        $this->addTitle($this->ownerA, 'مصمم باقات');
        $this->addTitle($this->ownerB, 'مصمم باقات');
        $rowA = JobTitle::where('business_id', $this->a->id)->where('name', 'مصمم باقات')->sole();

        $this->actingAs($this->ownerA)->delete(route('admin.jobTitles.destroy', $rowA->id))->assertRedirect();

        $this->assertNull($rowA->fresh());
        $this->assertSame(1, JobTitle::where('business_id', $this->b->id)->where('name', 'مصمم باقات')->count());
    }

    /** وبذرُ متجرٍ جديدٍ لا ينسخ مخترَعاتِ غيره */
    public function test_seeding_a_new_shop_never_copies_another_shops_custom_title(): void
    {
        $this->addTitle($this->ownerA, self::A_ONLY);

        [$c] = $this->shop('متجر C', 'owner-c@abaad.om');
        JobTitle::seedDefaults($c->id);

        $this->assertSame(0, JobTitle::where('business_id', $c->id)->where('name', self::A_ONLY)->count());
        $this->assertEqualsCanonicalizing(
            array_values(Roles::staffNames()),
            JobTitle::where('business_id', $c->id)->pluck('name')->all(),
        );
    }
}
