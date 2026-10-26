<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * هجرةُ مرتجعات البوتيك لا يُتراجع عنها وفيها ما يُفقد — وتُتراجع وهي فارغة.
 *
 * الإشعارُ واستردادُه مستندا قيودٍ في الدفتر، وشرطُ المرتجع مرجعُ اتّفاقية.
 * فـdown() يردّ بأرقامه، والرجوعُ حينها رجوعُ كودٍ وحده تبقى معه الجداول.
 * طلبه المالك قبل دمج PR #177 (2026-10-26).
 */
class ABoutiqueReturnsMigrationIsNotUndoneOverItsDataTest extends TestCase
{
    use RefreshDatabase;

    private static ?Migration $migration = null;

    private function migration(): Migration
    {
        return self::$migration ??= require database_path('migrations/2026_10_29_090000_a_boutique_return_after_its_sheet_is_a_note.php');
    }

    private function boutique(array $attributes = []): Boutique
    {
        $shop = Business::create(['name' => 'متجر الهجرة', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);

        return Boutique::create(['business_id' => $shop->id, 'name' => 'بوتيك', 'commission_rate' => 20, 'active' => true] + $attributes);
    }

    /** الجدولان والعمودان كما أنشأتها الهجرة */
    private function standing(): array
    {
        return [
            Schema::hasTable('boutique_returns'),
            Schema::hasTable('boutique_return_recoveries'),
            Schema::hasColumn('boutiques', 'returns_terms'),
            Schema::hasColumn('boutiques', 'returns_terms_ref'),
        ];
    }

    private function refused(string ...$named): void
    {
        try {
            $this->migration()->down();
            $this->fail('تُراجع عن الهجرة وفيها ما يُفقد');
        } catch (RuntimeException $e) {
            foreach ($named as $what) {
                $this->assertStringContainsString($what, $e->getMessage());
            }
            $this->assertStringContainsString('ALLOW_MIGRATIONS', $e->getMessage());
        }

        $this->assertSame([true, true, true, true], $this->standing(), 'حُذف شيءٌ قبل الرفض');
    }

    public function test_a_return_and_its_recovery_keep_the_migration_and_their_rows(): void
    {
        $boutique = $this->boutique();
        $sheet = BoutiqueSettlement::create([
            'business_id' => $boutique->business_id, 'boutique_id' => $boutique->id, 'number' => 'BQ-T-1', 'period' => '2027-02',
            'covered_from' => '2027-02-01 00:00:00', 'covered_to' => '2027-02-28 00:00:00', 'gross' => 100, 'commission' => 20,
            'net' => 80, 'lines_count' => 1, 'kind' => BoutiqueSettlement::SETTLEMENT, 'issued_at' => now(),
        ]);
        $note = DB::table('boutique_returns')->insertGetId([
            'business_id' => $boutique->business_id, 'boutique_id' => $boutique->id, 'boutique_settlement_id' => $sheet->id,
            'order_id' => 0, 'source' => 'cancel', 'name' => 'عطر', 'rate' => 20, 'unit_price' => 50, 'quantity' => 2, // رقمٌ مقصود: لا مفتاحَ أجنبيّ على الطلب — الصفُّ وحده يكفي للحارس
            'gross' => 100, 'amount' => 80, 'returned_at' => now(), 'condition' => 'sellable', 'status' => 'claim',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('boutique_return_recoveries')->insert([
            'business_id' => $boutique->business_id, 'boutique_id' => $boutique->id, 'boutique_return_id' => $note,
            'kind' => 'receipt', 'amount' => 30, 'reference' => 'TR-1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->refused('boutique_returns: 1', 'boutique_return_recoveries: 1');

        $this->assertSame([1, 1], [DB::table('boutique_returns')->count(), DB::table('boutique_return_recoveries')->count()]);
    }

    public function test_a_saved_returns_term_alone_keeps_the_migration(): void
    {
        $this->boutique(['returns_terms' => 'sellable', 'returns_terms_ref' => 'اتفاقية 1']);

        $this->refused('boutiques.returns_terms: 1');
    }

    public function test_a_reference_without_a_term_is_kept_too(): void
    {
        $this->boutique(['returns_terms_ref' => 'اتفاقية بلا شرط بعد']);

        $this->refused('boutiques.returns_terms: 1');
    }

    /** وفارغةً تُتراجع كاملةً، ثمّ تعود كما كانت */
    public function test_with_nothing_to_lose_it_rolls_back_and_comes_back(): void
    {
        $this->boutique();

        $this->migration()->down();
        $this->assertSame([false, false, false, false], $this->standing());

        $this->migration()->up();
        $this->assertSame([true, true, true, true], $this->standing());
    }
}
