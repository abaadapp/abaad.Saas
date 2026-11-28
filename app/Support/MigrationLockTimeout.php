<?php

namespace App\Support;

use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * كلُّ هجرةٍ تنتظر قفلَها مهلةً قصيرةً ثمّ تتراجع — لا تقف في الطابور بلا حدّ.
 *
 * ═══ لماذا ═══
 *
 * تعديلُ جدولٍ في PostgreSQL يطلب قفلًا عليه. فإن كان تقريرٌ طويلٌ يقرأ الجدولَ
 * انتظرته الهجرة — وكلُّ بيعةٍ تصل بعدها تنتظر خلف الهجرة، لا خلف التقرير. فيقف
 * الصندوقُ ما وقف التقرير. وهجرات البوتيك تمسّ `businesses` و`order_items` معًا.
 *
 * فكانت هجرةٌ واحدةٌ تحمي نفسها بمهلة (`2026_11_11_090000`)، وما سواها بلا مهلة.
 * وهذا يجعل المهلةَ لكلّ هجرة، من غير أن تُكتب في كلّ ملفّ:
 *
 *   - `SET LOCAL lock_timeout` داخل معاملة الهجرة نفسِها، فتنتهي معها ولا تمسّ
 *     ما بعدها في الاتّصال نفسِه.
 *   - والهجرةُ التي تُدير معاملتها بنفسها (`$withinTransaction = false`) لا تُمسّ:
 *     تلك تضع مهلتها وتعيد بنفسها.
 *   - وإن لم يُتَح القفلُ سقطت الهجرةُ كلُّها (معاملةٌ واحدة) بالخطأ 55P03، وما
 *     قبلها باقٍ. و`scripts/deploy.sh` يعيد `migrate` مرّاتٍ محدودة حين يكون
 *     السقوطُ قفلًا لا غيره، فتُكمل الهجراتُ الباقيةُ من حيث وقفت.
 *
 * والمهلةُ من الإعداد (`database.migrations.lock_timeout`، و`DB_MIGRATION_LOCK_TIMEOUT`):
 * أرقامٌ بـms أو s، و`0` يُطفئها صراحةً. وما سوى ذلك يُرفض قبل أن تجري هجرة —
 * إعدادٌ خاطئٌ لا يُقرأ «بلا مهلة» بصمت.
 */
final class MigrationLockTimeout
{
    public static function register(): void
    {
        Event::listen(MigrationStarted::class, fn (MigrationStarted $event) => self::apply($event->migration));
    }

    public static function value(): string
    {
        $value = trim((string) config('database.migrations.lock_timeout', '3s'));

        if (! preg_match('/^(0|[1-9]\d*(ms|s))$/', $value)) {
            throw new InvalidArgumentException('lock_timeout للهجرات غيرُ مقروء: «'.$value.'» — أرقامٌ بـms أو s، أو 0 لإطفائها');
        }

        return $value;
    }

    public static function apply(object $migration): void
    {
        $value = self::value();

        $connection = DB::connection(method_exists($migration, 'getConnection') ? $migration->getConnection() : null);

        // SQLite وغيرُها: لا مهلةَ أقفالٍ بهذا الاسم — والهجرةُ كما كانت
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        // هجرةٌ خارج معاملة تدير قفلَها بنفسها؛ و`SET LOCAL` خارج معاملةٍ لا أثرَ له
        if ($connection->transactionLevel() === 0) {
            return;
        }

        $connection->statement("SET LOCAL lock_timeout = '".$value."'");
    }
}
