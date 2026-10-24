<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ترحيلُ «طابعة الإيصال» لا يبدّل طابعةَ تاجرٍ بصمت.
 *
 * يُعاد تشغيلُ الهجرة على صفوفٍ قائمة: صندوقٌ بطابعةٍ مفعّلةٍ واحدة تُسمّى —
 * هي ما كان يطبع عليه. وصندوقٌ بطابعتين لا تُسمّى أيٌّ منهما — الالتباسُ يُعرض
 * ليختار التاجر، ويبقى الطبعُ على الأقدم كما كان.
 */
class AReceiptPrinterIsNamedOnlyWhereItWasNeverInDoubtTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_25_130000_a_till_names_its_receipt_printer.php';

    private function till(Business $shop, Branch $branch): PosDevice
    {
        return PosDevice::create([
            'business_id' => $shop->id, 'branch_id' => $branch->id, 'name' => 'صندوق '.Str::random(4),
            'token_hash' => hash('sha256', Str::random(40)), 'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]);
    }

    private function peripheral(PosDevice $till, array $over): int
    {
        return (int) DB::table('pos_peripherals')->insertGetId($over + [
            'business_id' => $till->business_id, 'pos_device_id' => $till->id, 'name' => 'طابعة',
            'type' => PosPeripheral::PRINTER, 'connection' => 'usb', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_only_an_unambiguous_printer_is_named(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('pos_peripherals', 'is_receipt_printer'));

        $shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        $single = $this->till($shop, $branch);
        $one = $this->peripheral($single, ['name' => 'الوحيدة']);
        $off = $this->peripheral($single, ['name' => 'معطّلة', 'active' => false]);
        $scanner = $this->peripheral($single, ['name' => 'ماسح', 'type' => PosPeripheral::SCANNER]);

        $pair = $this->till($shop, $branch);
        $a = $this->peripheral($pair, ['name' => 'أ']);
        $b = $this->peripheral($pair, ['name' => 'ب', 'connection' => 'network', 'address' => '10.0.0.7', 'auto_print' => true]);

        $none = $this->till($shop, $branch);
        $dead = $this->peripheral($none, ['name' => 'معطّلة', 'active' => false]);

        $migration->up();

        $named = DB::table('pos_peripherals')->orderBy('id')->pluck('is_receipt_printer', 'id')->map(fn ($v) => (bool) $v)->all();
        $this->assertSame([$one => true, $off => false, $scanner => false, $a => false, $b => false, $dead => false], $named);

        // والطبعُ على الملتبس يبقى على الأقدم — لا على ما عليه الطباعةُ التلقائية
        $this->assertSame($a, PosPeripheral::receiptAmong(PosPeripheral::where('pos_device_id', $pair->id)->get())?->id);

        // والفهرسُ الفريدُ عاد مع الترحيل
        $this->expectException(QueryException::class);
        DB::table('pos_peripherals')->whereIn('id', [$a, $b])->update(['is_receipt_printer' => true]);
    }
}
