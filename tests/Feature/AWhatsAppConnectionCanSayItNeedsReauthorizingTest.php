<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * كلُّ حالةٍ يعرفها الكود تُكتب في العمود — وأطولُها `reauthorization_required`.
 *
 * العمودُ كان عشرين حرفًا وهذه أربعةٌ وعشرون، فسقط حفظُها على PostgreSQL
 * وحدها؛ وSQLite لا تفرض الطول فلم يرَه اختبار. فالفحصُ الحاسم هنا يجري
 * على PostgreSQL، والقراءةُ من الجدول خامًا لا عبر النموذج.
 */
class AWhatsAppConnectionCanSayItNeedsReauthorizingTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_09_160000_a_whatsapp_connection_can_say_it_needs_reauthorizing.php';

    /** @return array<string, string> كلّ ثابتٍ في النموذج قيمتُه حالة */
    private function statuses(): array
    {
        $constants = (new ReflectionClass(WhatsAppConnection::class))->getConstants();

        return array_filter(
            $constants,
            fn ($value, $name) => is_string($value) && in_array($name, [
                'ACTIVE', 'INACTIVE', 'EXPIRED', 'REVOKED', 'ERROR', 'PENDING', 'REAUTH',
            ], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * عرضُ العمود على PostgreSQL — وهي وحدها تفرضه.
     *
     * SQLite لا تقطع ولا ترفض، وتعيد بناء الجدول عند `change()` بنوعٍ بلا
     * طول؛ فلا معنى لسؤالها عنه.
     */
    private function width(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('طولُ النصّ يُفرض على PostgreSQL وحدها');
        }

        return (int) DB::table('information_schema.columns')
            ->where('table_name', 'whatsapp_connections')
            ->where('column_name', 'status')
            ->value('character_maximum_length');
    }

    private function connection(string $status): WhatsAppConnection
    {
        $business = Business::create(['name' => 'محل '.$status, 'status' => 'نشط']);

        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $business->id,
            'phone_number_id' => 'PN-'.$business->id,
            'display_phone_number' => '+96890000000',
            'access_token' => 'shop-token-value-0123456789',
            'status' => $status,
            'connected_at' => now(),
        ]);
    }

    public function test_the_column_holds_the_longest_status_the_model_knows(): void
    {
        $longest = max(array_map('strlen', $this->statuses()));

        $this->assertSame(24, strlen(WhatsAppConnection::REAUTH), 'تغيّرت قيمة REAUTH — وهذه الهجرة لا تغيّرها');
        $this->assertSame(64, $this->width());
        $this->assertGreaterThanOrEqual($longest, $this->width());
    }

    public function test_every_official_status_is_saved_and_read_back_unchanged(): void
    {
        $this->assertCount(7, $this->statuses(), 'حالةٌ جديدة في النموذج؟ أضِفها هنا');

        foreach ($this->statuses() as $name => $status) {
            $id = $this->connection($status)->id;

            $this->assertSame($status, DB::table('whatsapp_connections')->where('id', $id)->value('status'), $name);
            $this->assertSame($status, WhatsAppConnection::find($id)->status, $name);
        }
    }

    public function test_an_existing_connection_can_be_moved_to_reauthorization_required(): void
    {
        $connection = $this->connection(WhatsAppConnection::ACTIVE);

        $connection->update(['status' => WhatsAppConnection::REAUTH]);

        $this->assertSame(WhatsAppConnection::REAUTH, DB::table('whatsapp_connections')->where('id', $connection->id)->value('status'));
    }

    /** و«يحتاج إعادة تفويض» يُرسل — كما كان */
    public function test_reauthorization_required_is_still_usable(): void
    {
        $connection = WhatsAppConnection::find($this->connection(WhatsAppConnection::REAUTH)->id);

        $this->assertTrue($connection->isUsable());

        $connection->update(['token_expires_at' => now()->subMinute()]);
        $this->assertFalse($connection->fresh()->isUsable());
    }

    /** الافتراضيُّ والفهرسان كما كانا */
    public function test_the_default_and_the_indexes_are_kept(): void
    {
        $business = Business::create(['name' => 'محل', 'status' => 'نشط']);
        $id = DB::table('whatsapp_connections')->insertGetId([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $business->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(WhatsAppConnection::INACTIVE, DB::table('whatsapp_connections')->where('id', $id)->value('status'));

        $indexed = collect(Schema::getIndexes('whatsapp_connections'))
            ->filter(fn ($index) => in_array('status', $index['columns'], true))
            ->map(fn ($index) => $index['columns'])
            ->values()->all();

        $this->assertEqualsCanonicalizing([['owner_type', 'status'], ['business_id', 'status']], $indexed);
    }

    /** الرجوعُ لا يُصغّر العمود — فلا يسقط ولا يقطع حالةً محفوظة */
    public function test_rolling_back_keeps_the_width_and_the_saved_status(): void
    {
        $id = $this->connection(WhatsAppConnection::REAUTH)->id;

        (require base_path(self::MIGRATION))->down();

        $this->assertSame(WhatsAppConnection::REAUTH, DB::table('whatsapp_connections')->where('id', $id)->value('status'));
        $this->assertSame(64, $this->width());
    }
}
