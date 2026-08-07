<?php

namespace Tests\Feature;

use App\Console\Commands\BackupRun;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * نسخةٌ لم تُقرأ ليست نسخة.
 *
 * كان الأمر يكتب الملف ويمضي: لا يفتحه، ولا يحذف القديم، ولا يترك أثرًا يقول
 * إنه عمل. فقرصٌ ممتلئ أو ترميزٌ منكسر يُنتج ملفًا بحجمٍ معقول لا يُفتح، ولا
 * يُكتشف إلا يوم الاستعادة — وهو آخر يومٍ يصلح للاكتشاف.
 */
class BackupIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * قرصٌ وهمي لا القرص الحقيقي.
         *
         * كانت الاختبارات تكتب في storage/app/private/backups فعلًا، فتتراكم
         * الملفات في مجلّد اليوم بين تشغيلٍ وآخر ويصير عدّها متقلّبًا — اختبارٌ
         * ينجح صباحًا ويفشل ظهرًا بلا تغييرٍ في الكود.
         */
        Storage::fake('local');

        $this->business = Business::create(['name' => 'متجر النسخ', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        for ($i = 0; $i < 3; $i++) {
            Order::create([
                'business_id' => $this->business->id, 'branch_id' => $branch->id,
                'number' => 'INV-'.$i, 'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10,
                'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
            ]);
        }
    }

    public function test_it_writes_a_file_that_can_actually_be_read_back(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $disk = Storage::disk('local');
        $files = $disk->files('backups/'.now()->format('Y-m-d'));
        $this->assertCount(1, $files);

        $data = json_decode($disk->get($files[0]), true);
        $this->assertSame($this->business->id, $data['meta']['business_id']);
        $this->assertCount(3, $data['orders']);
    }

    public function test_it_leaves_a_stamp_so_preflight_can_see_the_scheduler_ran(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $stamp = json_decode(Storage::disk('local')->get(BackupRun::STAMP), true);
        $this->assertSame(1, $stamp['written']);
        $this->assertSame([], $stamp['failed']);
        $this->assertNotEmpty($stamp['finished_at']);
    }

    public function test_preflight_complains_when_no_backup_was_ever_taken(): void
    {
        Storage::disk('local')->delete(BackupRun::STAMP);

        $this->artisan('abaad:preflight')
            ->expectsOutputToContain('لم تُنشأ أي نسخة احتياطية قط')
            ->assertFailed();
    }

    public function test_preflight_complains_when_the_scheduler_went_quiet(): void
    {
        Storage::disk('local')->put(BackupRun::STAMP, json_encode([
            'finished_at' => now()->subDays(9)->toIso8601String(),
            'written' => 1, 'failed' => [],
        ]));

        // جدولةٌ في الملف لا تعني تشغيلًا على الخادم
        $this->artisan('abaad:preflight')
            ->expectsOutputToContain('المجدول متوقّف على الأرجح')
            ->assertFailed();
    }

    public function test_it_deletes_folders_older_than_the_retention_window(): void
    {
        $disk = Storage::disk('local');
        $old = 'backups/'.now()->subDays(30)->format('Y-m-d');
        $recent = 'backups/'.now()->subDays(3)->format('Y-m-d');
        $disk->put($old.'/x.json', '{}');
        $disk->put($recent.'/x.json', '{}');

        $this->artisan('backup:run', ['--keep' => 14])->assertSuccessful();

        // نسخةٌ لا تُحذف تملأ القرص، فيتوقّف النسخ لأن لا مكان
        $this->assertFalse($disk->exists($old.'/x.json'));
        $this->assertTrue($disk->exists($recent.'/x.json'));
    }

    public function test_a_folder_that_is_not_a_date_is_left_alone(): void
    {
        $disk = Storage::disk('local');
        $disk->put('backups/manual/keep-me.json', '{}');

        $this->artisan('backup:run', ['--keep' => 1])->assertSuccessful();

        // الحذف الخاطئ لا يُستدرك، فما لا يُفهم اسمه يُترك
        $this->assertTrue($disk->exists('backups/manual/keep-me.json'));
    }
}
