<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\BackupService;
use App\Support\ReviewInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الاستعادةُ لصاحب النشاط وحده — ورابطُ دعوة التقييم لا يخرج في نسخة.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * كانت الاستعادةُ — آخرُ نسخةٍ بضغطة، والملفُّ المرفوع — خلف قسم
 * «الإعدادات» وحده. وهو قسمٌ يُمنح لموظّفٍ يضبط الطابعة، ويملكه مديرُ
 * الفرع بـ`'*'`. فكان يمحو المتجرَ كلَّه من لم يُرِد صاحبُه أن يملك ذلك.
 *
 * وكان رمزُ دعوة التقييم يخرج في الملفّ خامًا — وهو الرابطُ نفسُه.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الخادمَ يردّ كلَّ من ليس صاحبَ النشاط — موظّفًا مُنح الإعدادات،
 * ومديرَ فرع، وشريكًا ضُيّقت صلاحياتُه بيده — ولا يُقرأ ملفٌّ ولا تُكتب
 * نسخةُ أمانٍ لمن سيُردّ. وأنّ الإنشاءَ والتحميلَ يبقيان لمن كانا له.
 * وأنّ الرمزَ لا يخرج، وأنّ الطلبَ يعود بعد الاستعادة فيُبنى له رابطٌ جديد.
 */
class OnlyTheOwnerMayWipeTheShopBackTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $at = ['created_at' => now(), 'updated_at' => now()];
        $branch = DB::table('branches')->insertGetId(['business_id' => $this->shop->id, 'name' => 'الرئيسي'] + $at);
        DB::table('expenses')->insert([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'amount' => 100, 'spent_at' => now()->toDateString(),
        ] + $at);
        $this->orderId = DB::table('orders')->insertGetId([
            'business_id' => $this->shop->id, 'branch_id' => $branch, 'number' => 'INV-1',
            'subtotal' => 10, 'tax' => 0, 'total' => 10, 'payment_method' => 'نقدي',
            'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
            // رابطُ دعوةٍ أُرسل إلى الزبون قبل النسخ
            'review_token' => 'SENT-LINK-abcdefghijklm',
        ] + $at);
    }

    /* ═══════════════════════ أدواتٌ ═══════════════════════ */

    /** من ليس صاحبَ النشاط — بأوجهه الثلاثة */
    private function notTheOwner(string $who): User
    {
        return User::create(match ($who) {
            // موظّفٌ مُنح «الإعدادات» بيد صاحب النشاط
            'staff' => ['role' => 'staff', 'permissions' => ['settings']],
            // مديرُ فرعٍ — يملك كلَّ قسم بـ`'*'`
            'manager' => ['role' => 'manager'],
            // شريكٌ دورُه admin وضُيّقت صلاحياتُه بيده — ليس صاحبَ النشاط
            'narrowed' => ['role' => 'admin', 'permissions' => ['settings']],
        } + [
            'business_id' => $this->shop->id, 'name' => $who, 'email' => $who.'@abaadapp.om',
            'password' => bcrypt('secret12345'), 'status' => 'نشط',
        ]);
    }

    private function expenses(): int
    {
        return DB::table('expenses')->where('business_id', $this->shop->id)->count();
    }

    /** ملفّاتُ نوعٍ من النسخ لهذا المتجر على القرص */
    private function files(string $kind): array
    {
        return array_values(array_filter(
            Storage::disk('local')->allFiles('backups'),
            fn ($f) => str_starts_with(basename($f), "abadpos-{$kind}-{$this->shop->id}-"),
        ));
    }

    /* ═════════════ الاستعادةُ لصاحب النشاط وحده ═════════════ */

    /** @return iterable<string, array{string}> */
    public static function outsiders(): iterable
    {
        yield 'موظّفٌ مُنح الإعدادات' => ['staff'];
        yield 'مديرُ فرعٍ يملك كلَّ قسم' => ['manager'];
        yield 'شريكٌ ضُيّقت صلاحياتُه' => ['narrowed'];
    }

    #[DataProvider('outsiders')]
    public function test_no_one_but_the_owner_restores_the_latest_backup(string $who): void
    {
        BackupService::store($this->shop->id);
        DB::table('expenses')->where('business_id', $this->shop->id)->delete();

        $this->actingAs($this->notTheOwner($who))
            ->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertForbidden();

        $this->assertSame(0, $this->expenses(), 'استُعيد المتجرُ لمن ليس صاحبَه');
        // وسُئل أوّلَ شيء: لا نسخةُ أمانٍ كُتبت لمن سيُردّ
        $this->assertSame([], $this->files('safety'));
    }

    #[DataProvider('outsiders')]
    public function test_no_one_but_the_owner_restores_an_uploaded_file(string $who): void
    {
        $file = gzencode(BackupService::json($this->shop->id));
        DB::table('expenses')->where('business_id', $this->shop->id)->delete();

        $this->actingAs($this->notTheOwner($who))
            ->post(route('admin.backup.restore'), [
                'backup' => UploadedFile::fake()->createWithContent('mine.json.gz', $file),
                'confirm' => true,
            ])->assertForbidden();

        $this->assertSame(0, $this->expenses(), 'استُعيد ملفٌّ مرفوعٌ لمن ليس صاحبَ النشاط');
        $this->assertSame([], $this->files('safety'));
    }

    /** والحارسُ لا يسدّ الباب على صاحبه */
    public function test_the_owner_still_restores_both_ways(): void
    {
        BackupService::store($this->shop->id);
        DB::table('expenses')->where('business_id', $this->shop->id)->delete();

        $this->actingAs($this->owner)
            ->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertRedirect()->assertSessionHas('toast.type', 'success');
        $this->assertSame(1, $this->expenses());

        $file = gzencode(BackupService::json($this->shop->id));
        DB::table('expenses')->where('business_id', $this->shop->id)->delete();

        $this->actingAs($this->owner)
            ->post(route('admin.backup.restore'), [
                'backup' => UploadedFile::fake()->createWithContent('mine.json.gz', $file),
                'confirm' => true,
            ])->assertRedirect()->assertSessionHas('toast.type', 'success');
        $this->assertSame(1, $this->expenses());
    }

    /** والإنشاءُ والتحميلُ يبقيان لمن مُنح «الإعدادات» — لا يمحوان شيئًا */
    public function test_an_employee_given_settings_still_creates_and_downloads(): void
    {
        $staff = $this->notTheOwner('staff');

        $this->actingAs($staff)->post(route('admin.backup.create'))
            ->assertRedirect()->assertSessionHas('toast.type', 'success');
        $this->assertCount(1, $this->files('backup'));

        $this->actingAs($staff)->get(route('admin.backup.latest.download'))->assertOk();
    }

    /** والشاشةُ تُخفي البابين عمّن يردّه الخادم — من الحارس نفسِه */
    public function test_the_screen_is_told_who_may_restore(): void
    {
        $this->actingAs($this->owner);
        $this->assertTrue(BackupController::panel($this->shop->id)['can_restore']);

        foreach (['staff', 'manager', 'narrowed'] as $who) {
            $this->actingAs($this->notTheOwner($who));
            $this->assertFalse(BackupController::panel($this->shop->id)['can_restore'], "{$who} يرى بابَ الاستعادة");
        }
    }

    /* ═════════════ رابطُ دعوة التقييم لا يخرج ═════════════ */

    public function test_the_review_link_never_leaves_in_a_backup(): void
    {
        /*
         * والرمزُ في القاعدة قبل النسخ — مقروءًا من العمود نفسِه.
         *
         * وبلا هذا يمرّ الحارسُ على طلبٍ لا رمزَ له أصلًا: «لم يخرج» صادقةٌ
         * لأنّ لا شيءَ كان ليخرج.
         */
        $this->assertSame('SENT-LINK-abcdefghijklm', DB::table('orders')->where('id', $this->orderId)->value('review_token'));

        $record = BackupService::store($this->shop->id);
        $text = gzdecode(Storage::disk('local')->get($record['path']));
        $orders = json_decode($text, true)['orders'];

        $this->assertStringNotContainsString('SENT-LINK-abcdefghijklm', $text, 'رابطُ الدعوة خرج في النسخة');
        // والطلبُ نفسُه في النسخة — نُزع رمزُه ولم يُنزع هو
        $this->assertContains($this->orderId, array_map('intval', array_column($orders, 'id')));

        foreach ($orders as $row) {
            $this->assertArrayNotHasKey('review_token', $row);
        }

        // ولا في الملفّ الذي يُنزَّل بلا ضغط
        $this->assertStringNotContainsString('SENT-LINK-abcdefghijklm', BackupService::json($this->shop->id));
    }

    /**
     * والطلبُ يعود بعد الاستعادة — وثمنُ القرار معلوم.
     *
     * الرابطُ الذي أُرسل قبلها لا يفتح بعدها، ويُبنى رابطٌ جديدٌ عند أوّل
     * دعوة. وتُقاس الثلاثة: أنّ الطلبَ عاد، وأنّ القديمَ ٤٠٤، وأنّ الجديدَ يعمل.
     */
    public function test_a_restored_order_comes_back_and_earns_a_fresh_link(): void
    {
        BackupService::store($this->shop->id);

        $this->actingAs($this->owner)
            ->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertSessionHas('toast.type', 'success');

        $order = Order::find($this->orderId);
        $this->assertNotNull($order, 'لم يعد الطلبُ بعد الاستعادة');
        $this->assertNull($order->review_token);

        $this->get(route('review.write', 'SENT-LINK-abcdefghijklm'))->assertNotFound();

        $fresh = ReviewInvite::token($order);
        $this->assertNotNull($fresh);
        $this->assertNotSame('SENT-LINK-abcdefghijklm', $fresh);
        $this->get(route('review.write', $fresh))->assertOk();
    }
}
