<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\Setting;
use App\Models\User;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AbaadSubscriptionContract;
use Tests\TestCase;

/**
 * مديران يُصدران في اللحظة نفسها — كلُّ مستندٍ برقمه، ولا رقمَ لاثنين.
 *
 * الرقمُ من معرّف الصفّ (`PlatformDocuments::number`) لا من عدّادٍ يُقرأ ثمّ يُزاد:
 * فلا يقرأ اثنان العدّادَ نفسه. وهنا ثلاثُ عمليّاتٍ حقيقيّة باتّصالاتٍ مستقلّة تبدأ
 * معًا وتُصدر كلٌّ منها أربعة — على PostgreSQL، فـSQLite لا تُبقي معاملتين معًا.
 */
class TwoHandsIssuingAtOnceNeverShareADocumentNumberTest extends TestCase
{
    use AbaadSubscriptionContract;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /**
     * يدٌ تُصدر — عمليّةٌ أخرى بتطبيقها واتّصالها، تنتظر ساعةَ البدء ثمّ تُصدر `$count`.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function hand(int $userId, array $data, int $count, float $startAt): array
    {
        $c = DB::connection()->getConfig();
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_TIMEZONE' => PlatformDocuments::TIMEZONE,
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ];
        $code = sprintf(
            'require %s; $app = require %s; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' $by = App\Models\User::findOrFail(%d); $data = %s;'
            .' while (microtime(true) < %F) { usleep(500); }'
            .' $out = []; for ($i = 0; $i < %d; $i++) { $out[] = App\Support\PlatformDocuments::issue($by, $data)->number; }'
            .' echo json_encode($out);',
            var_export(base_path('vendor/autoload.php'), true), var_export(base_path('bootstrap/app.php'), true),
            $userId, var_export($data, true), $startAt, $count,
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc, 'تعذّر تشغيل اليد الأخرى');

        return [$proc, $pipes];
    }

    public function test_three_hands_issuing_at_the_same_moment_get_twelve_different_numbers(): void
    {
        $pro = Plan::create([
            'name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000,
        ]);
        $shop = Business::create([
            'name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'city' => 'مسقط',
            'status' => 'نشط', 'plan_id' => $pro->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-08-31',
        ]);
        $super = User::create([
            'name' => 'مدير المنصة', 'email' => 'super@two-hands-docs.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        // ما تزرعه الهجرة يمحوه التفريغ — يُزرع هنا
        foreach (['legal_name' => 'مشاريع الذهلي الجديدة', 'legal_trade_name' => 'أبعاد'] as $key => $value) {
            Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
        }
        $contract = $this->subscriptionContract();
        $data = $this->confirmed(['template_id' => $contract->id, 'business_id' => $shop->id, 'cycle' => 'yearly']);

        $startAt = microtime(true) + 4;
        $hands = [];
        for ($h = 0; $h < 3; $h++) {
            $hands[] = $this->hand($super->id, $data, 4, $startAt);
        }

        $numbers = [];
        foreach ($hands as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'سقطت يدٌ: '.mb_substr((string) $err, 0, 600));
            $numbers = [...$numbers, ...json_decode((string) $out, true, flags: JSON_THROW_ON_ERROR)];
        }

        $this->assertCount(12, $numbers);
        $this->assertCount(12, array_unique($numbers), 'رقمٌ واحدٌ لمستندين');
        $docs = PlatformDocument::orderBy('id')->get();
        $this->assertCount(12, $docs);
        foreach ($docs as $doc) {
            $this->assertSame(PlatformDocuments::number($doc), $doc->number);
            $this->assertStringStartsNotWith('TMP-', $doc->number);
        }
        $this->assertEqualsCanonicalizing($docs->pluck('number')->all(), $numbers);
    }
}
