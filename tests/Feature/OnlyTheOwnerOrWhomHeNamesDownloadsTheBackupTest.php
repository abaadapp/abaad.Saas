<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\BackupService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * تنزيلُ النسخة الاحتياطيّة لصاحب النشاط — ولمن سمّاه هو وحده.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * كان التنزيلان — النسخةُ تُبنى الآن، وآخرُ نسخةٍ على الخادم — خلف قسم
 * «الإعدادات» وحده. والملفُّ فيه كلُّ جدولٍ في المتجر: الرواتبُ، وأسعارُ
 * الشراء، وكلُّ العملاء، والدفتر. فكان يُخرجه موظّفٌ مُنح الإعدادات ليضبط
 * الطابعة، ومديرُ الفرع بدوره — وفعلُ `business.export` قائمٌ ليحجب أقلَّ منه.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - صاحبُ النشاط يُنزّل من البابين، ونسختُه من متجره وحده.
 *   - من سواه يُردّ بـ٤٠٣ بطلبٍ مباشر — موظّفُ الإعدادات، ومديرُ الفرع،
 *     والشريكُ المضيَّق، والمحاسبُ الذي يقرأ الرواتب — ولا يُبنى ملفٌّ ولا
 *     يُقيَّد تصدير.
 *   - لا دورَ ولا قسمَ يمنحه؛ وصاحبُ النشاط يمنحه بالاسم من «صلاحيات
 *     الموظفين» فيُنزّل الممنوح، ومن لا يملكه لا يمنحه.
 *   - كلُّ بابٍ يُخرج النسخةَ يحمل الفعلَ في مساره — ولو أُضيف بابٌ غدًا.
 *   - والشاشةُ تُخفي التحميلَ عمّن يردّه الخادم.
 *
 * والإنشاءُ على الخادم وتكرارُه والاستعادةُ لصاحب النشاط في
 * `OnlyTheOwnerMayWipeTheShopBackTest`.
 */
class OnlyTheOwnerOrWhomHeNamesDownloadsTheBackupTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        foreach ([['صاحب النشاط', 'admin'], ['مدير فرع', 'manager'], ['محاسب', 'accountant']] as [$name, $role]) {
            JobTitle::create(['business_id' => $this->shop->id, 'name' => $name, 'role' => $role]);
        }

        $this->owner = $this->employee('owner', 'admin');
        Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل متجري', 'phone' => '91111111']);
        Customer::create(['business_id' => $this->neighbour->id, 'name' => 'عميل الجار', 'phone' => '92222222']);
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    private function employee(string $key, string $role, ?array $permissions = null, ?Business $shop = null, array $extra = []): User
    {
        $shop ??= $this->shop;

        return User::create(array_merge([
            'business_id' => $shop->id, 'name' => $key, 'email' => $key.'-'.$shop->id.'@backup.om',
            'password' => bcrypt('secret12345'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ], $extra));
    }

    /** @return iterable<string, array{string, ?array}> */
    public static function outsiders(): iterable
    {
        yield 'موظّفٌ مُنح «الإعدادات» وحدها' => ['staff', ['settings']];
        yield 'مديرُ فرعٍ بدوره (`*`)' => ['manager', null];
        yield 'شريكٌ دورُه admin ضُيّقت صلاحياتُه' => ['admin', ['settings', 'finance', 'employees']];
        yield 'محاسبٌ يقرأ الرواتب' => ['accountant', ['settings', 'finance', 'employees', Permissions::PAYROLL_VIEW]];
    }

    private function exports(): int
    {
        return ActivityLog::where('business_id', $this->shop->id)->where('action', 'backup')->count();
    }

    /* ═══════════════════════ صاحبُ النشاط ═══════════════════════ */

    public function test_the_owner_downloads_from_both_doors_and_only_his_own_shop(): void
    {
        $now = $this->actingAs($this->owner)->get($this->exportRoute('admin.backup.download'))->assertOk()->getContent();
        $this->assertStringContainsString('عميل متجري', $now);
        $this->assertStringNotContainsString('عميل الجار', $now, 'نسخةُ متجرٍ آخر لا تخرج في نسخته');

        BackupService::store($this->shop->id);
        $this->actingAs($this->owner)->get($this->exportRoute('admin.backup.latest.download'))->assertOk();

        // وصاحبُ المتجر الآخر يُنزّل متجرَه هو — لا متجرَ هذا
        $theirs = $this->employee('their-owner', 'admin', null, $this->neighbour);
        $dump = $this->actingAs($theirs)->get($this->exportRoute('admin.backup.download'))->assertOk()->getContent();
        $this->assertStringContainsString('عميل الجار', $dump);
        $this->assertStringNotContainsString('عميل متجري', $dump);
    }

    /* ═══════════════════════ ومن سواه ═══════════════════════ */

    #[DataProvider('outsiders')]
    public function test_no_one_else_downloads_it_by_a_direct_request(string $role, ?array $permissions): void
    {
        BackupService::store($this->shop->id);
        $who = $this->employee('outsider', $role, $permissions);
        $this->assertFalse(Permissions::isOwner($who));
        $before = $this->exports();

        foreach (['admin.backup.download', 'admin.backup.latest.download'] as $door) {
            $response = $this->actingAs($who)->get($this->exportRoute($door))->assertForbidden();
            $this->assertStringNotContainsString('عميل متجري', (string) $response->getContent(), $door);
        }

        $this->assertSame($before, $this->exports(), 'لا تصديرَ قُيّد لمن رُدّ');
    }

    public function test_no_role_and_no_section_grants_it(): void
    {
        foreach (array_keys(Permissions::MAP) as $role) {
            $this->assertSame($role === 'admin', Permissions::allowsAction($role, Permissions::BUSINESS_EXPORT), $role);
        }

        foreach (Permissions::LEGACY_SECTION_ACTIONS as $section => $actions) {
            $this->assertNotContains(Permissions::BUSINESS_EXPORT, $actions, "قسم {$section} يورّثه");
        }

        // ودورُ admin بلا قائمة هو صاحبُ النشاط — والمضيَّقُ ليس هو
        $this->assertTrue($this->owner->may(Permissions::BUSINESS_EXPORT));
        $this->assertFalse($this->employee('narrowed', 'admin', ['settings'])->may(Permissions::BUSINESS_EXPORT));
    }

    /* ═══════════════════════ المنحُ بالاسم ═══════════════════════ */

    public function test_whoever_the_owner_names_on_the_employee_screen_downloads_it(): void
    {
        $manager = $this->employee('manager', 'manager', null, null, ['job_title' => 'مدير فرع']);
        $this->actingAs($manager)->get($this->exportRoute('admin.backup.download'))->assertForbidden();

        $this->actingAs($this->owner)->put(route('admin.employees.update', $manager->id), [
            'name' => 'manager', 'login_username' => 'manager1', 'job_title' => 'مدير فرع',
            'manual_permissions' => true, 'permissions' => ['dashboard', 'settings', Permissions::BUSINESS_EXPORT],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $manager->refresh();
        $this->assertContains(Permissions::BUSINESS_EXPORT, $manager->permissions);
        $this->actingAs($manager)->get($this->exportRoute('admin.backup.download'))->assertOk();
    }

    /** ومن لا يملكه لا يمنحه — ولو كان مديرَ فرعٍ يملك كلَّ قسم */
    public function test_whoever_lacks_it_cannot_hand_it_on(): void
    {
        $manager = $this->employee('manager', 'manager');
        $clerk = $this->employee('clerk', 'accountant', ['dashboard', 'settings'], null, ['job_title' => 'محاسب']);

        $this->assertNotContains(Permissions::BUSINESS_EXPORT, Permissions::grantable($manager));

        $this->actingAs($manager)->put(route('admin.employees.update', $clerk->id), [
            'name' => 'clerk', 'login_username' => 'clerk1', 'job_title' => 'محاسب',
            'manual_permissions' => true, 'permissions' => ['dashboard', 'settings', Permissions::BUSINESS_EXPORT],
        ])->assertForbidden();

        $this->assertSame(['dashboard', 'settings'], $clerk->fresh()->permissions);
    }

    /* ═══════════════════════ الأبوابُ والشاشة ═══════════════════════ */

    /** كلُّ بابٍ يُخرج النسخةَ من المتجر يسأل الفعلَ في مساره — ولو أُضيف غدًا */
    public function test_every_door_that_lets_the_backup_leave_asks_for_it(): void
    {
        $doors = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getActionName(), BackupController::class.'@')
                && in_array('GET', $r->methods(), true));

        $this->assertNotEmpty($doors);

        foreach ($doors as $route) {
            $this->assertContains('may:'.Permissions::BUSINESS_EXPORT, $route->gatherMiddleware(), $route->getName());
        }

        // والبابان المسمَّيان بينها — لا يمرّ الحارسُ على قائمةٍ فارغة
        $this->assertEqualsCanonicalizing(
            ['admin.backup.download', 'admin.backup.latest.download'],
            $doors->map(fn ($r) => $r->getName())->values()->all(),
        );
    }

    public function test_the_screen_shows_the_download_only_to_whoever_the_server_lets_through(): void
    {
        $this->actingAs($this->owner);
        $this->assertTrue(BackupController::panel($this->shop->id)['can_download']);

        foreach (self::outsiders() as $label => [$role, $permissions]) {
            $this->actingAs($this->employee('o'.crc32($label), $role, $permissions));
            $this->assertFalse(BackupController::panel($this->shop->id)['can_download'], $label);
        }

        $this->actingAs($this->employee('named', 'accountant', ['settings', Permissions::BUSINESS_EXPORT]));
        $this->assertTrue(BackupController::panel($this->shop->id)['can_download']);
    }
}
