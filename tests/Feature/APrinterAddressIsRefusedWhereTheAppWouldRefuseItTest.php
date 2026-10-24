<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\User;
use App\Rules\PrinterAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * عنوانُ طابعة الشبكة يُردّ عند الحفظ بما يردّه به التطبيقُ عند الطباعة.
 *
 * والمتّجهاتُ هي متّجهاتُ التطبيقين (`mobile/shared/fixtures/printing-vectors.json`)
 * — لا نسخةٌ تُكتب هنا فتفترق.
 */
class APrinterAddressIsRefusedWhereTheAppWouldRefuseItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private PosDevice $device;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'status' => 'نشط', 'password' => 'x',
        ]);
        $this->device = $this->activatePosDevice($this->business->id);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function vectors(): array
    {
        $file = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/mobile/shared/fixtures/printing-vectors.json'), true);

        $cases = [];
        foreach ($file['address']['cases'] as [$input, $kind]) {
            $cases["«{$input}» {$kind}"] = [$input, $kind !== 'invalid'];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function test_the_rule_matches_the_apps_vectors(string $address, bool $accepted): void
    {
        $this->assertSame($accepted, PrinterAddress::allows($address));
    }

    private function save(array $over): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.devices.peripherals.store', $this->device->id), $over + [
            'name' => 'طابعة الكاشير', 'type' => PosPeripheral::PRINTER, 'connection' => 'network',
        ]);
    }

    public function test_an_address_with_its_port_inside_is_refused_with_a_reason(): void
    {
        $this->save(['address' => '192.168.1.50:9100'])->assertSessionHasErrors('address');
        $this->assertSame(0, PosPeripheral::count());
    }

    public function test_a_public_address_is_refused(): void
    {
        $this->save(['address' => '8.8.8.8'])->assertSessionHasErrors('address');
    }

    public function test_a_local_address_is_saved(): void
    {
        $this->save(['address' => '192.168.100.235'])->assertSessionHasNoErrors();
        $this->assertSame('192.168.100.235', PosPeripheral::sole()->address);
    }

    /** وماسحٌ أو ميزانٌ على الشبكة لا يمرّ بقاعدة الطابعة — التطبيقُ لا يطبع عليه */
    public function test_a_network_scale_is_not_held_to_the_printer_rule(): void
    {
        $this->save(['type' => PosPeripheral::SCALE, 'address' => 'scale.example.com'])->assertSessionHasNoErrors();
    }

    /**
     * والمنفذُ يُحفظ حتى 65535 — كما يقبله التحقّقُ والتطبيقان.
     *
     * كان العمودُ `smallint` على PostgreSQL (حدُّه 32767)، فمنفذٌ كهذا يمرّ من
     * التحقّق ثمّ يسقط الحفظُ بخطأ خادم. وعلى SQLite يمرّ صامتًا.
     */
    public function test_any_tcp_port_is_kept(): void
    {
        $this->save(['address' => '10.0.0.7', 'port' => 65535])->assertSessionHasNoErrors();
        $this->assertSame(65535, PosPeripheral::sole()->port);
    }

    /** والمنفذُ من 1 إلى 65535 — لا يُفترض 9100، ولا يُقبل ما لا يكون */
    public function test_the_port_runs_from_one_to_65535(): void
    {
        $this->save(['address' => '10.0.0.7', 'port' => 1])->assertSessionHasNoErrors();
        $this->save(['address' => '10.0.0.8', 'port' => 0])->assertSessionHasErrors('port');
        $this->save(['address' => '10.0.0.9', 'port' => 65536])->assertSessionHasErrors('port');

        $this->assertSame([1], PosPeripheral::pluck('port')->all());
    }

    /** والتراجعُ عن الهجرة لا يقصّ منفذًا: يتوقّف ويُسمّي الصفوف */
    public function test_rolling_back_stops_before_cutting_a_wide_port(): void
    {
        $this->save(['address' => '10.0.0.7', 'port' => 40000])->assertSessionHasNoErrors();
        $migration = require base_path('database/migrations/2026_10_25_120000_a_printer_port_holds_every_tcp_port.php');

        try {
            $migration->down();
            $this->fail('تراجعت الهجرةُ فوق منفذٍ لا يتّسع له smallint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) PosPeripheral::sole()->id, $e->getMessage());
        }

        $this->assertSame(40000, PosPeripheral::sole()->port);
    }
}
