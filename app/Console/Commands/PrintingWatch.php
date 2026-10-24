<?php

namespace App\Console\Commands;

use App\Support\TillHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * المراقبةُ اليوميّة لصحّة الصناديق — سطرٌ في السجلّ حين يتغيّر ما ثبت.
 *
 * ═══ ولمَ أمرٌ وحدَه ═══
 *
 * التدقيقُ اليدويّ (`pos:printing-audit`) قراءةٌ خالصة: لا يكتب شيئًا في أيّ
 * مكان. والمراقبةُ تحتاج أن تتذكّر ما رأت أمس لتصمت ما دام كما كان — وتلك
 * كتابة. فلا تُخلط بالتدقيق: هذا الأمرُ وحدَه يكتب، ووحدَه يُجدوَل.
 *
 * ═══ ما يكتبه — كلُّه، ولا شيءَ في القاعدة ═══
 *
 * 1. بصمةٌ واحدة (sha256 لما ثبت) في مخزن `pos.printing_watch_store` — ملفٌّ
 *    افتراضًا. ومخزنٌ سائقُه `database` مرفوض: صفُّه في جدول `cache` كتابةٌ
 *    في القاعدة.
 * 2. سطرُ `warning` في السجلّ حين تتغيّر البصمة: أعدادٌ ورموزٌ ومعرّفاتُ متاجر،
 *    بلا اسمٍ ولا عنوان.
 *
 * والقراءةُ نفسُها في معاملة READ ONLY (`PrintingAudit::readOnly`)، والكتابتان
 * بعد أن تنتهي. ولا يراسل تاجرًا، ولا يمسّ صندوقًا ولا طابعةً ولا ورقة.
 *
 * ═══ ومطفأ ═══
 *
 * لا يعمل إلّا بـ`pos.daily_printing_audit` (`POS_PRINTING_AUDIT_ENABLED`) —
 * والجدولةُ تفحص المفتاح قبل أن تناديه، وهو يفحصه ثانيةً لمن ناداه بيده.
 */
class PrintingWatch extends Command
{
    protected $signature = 'pos:printing-watch';

    protected $description = 'المراقبة اليومية لصحة الصناديق وطابعاتها — مطفأة إلا بموافقة المالك';

    /** آخرُ ما رأته المراقبة — بصمةٌ في مخزن `pos.printing_watch_store` */
    public const KEY = 'pos:printing-watch:confirmed';

    public function handle(): int
    {
        if (! config('pos.daily_printing_audit')) {
            $this->line(__('المراقبة اليومية مطفأة (POS_PRINTING_AUDIT_ENABLED). لم يُقرأ شيء.'));

            return self::SUCCESS;
        }

        $store = (string) config('pos.printing_watch_store', 'file');

        if (config("cache.stores.{$store}.driver") === 'database') {
            $this->error(__('مخزن المراقبة «:store» في القاعدة — اختر مخزنًا غيرها (POS_PRINTING_WATCH_STORE).', ['store' => $store]));

            return self::FAILURE;
        }

        $report = PrintingAudit::readOnly(fn () => PrintingAudit::collect());
        $now = self::fingerprint($report);
        $cache = Cache::store($store);

        if ($cache->get(self::KEY) === $now) {
            return self::SUCCESS;
        }

        $cache->forever(self::KEY, $now);

        Log::warning('pos.printing-health changed', [
            'confirmed_tills_by_code' => collect($report['issues'])->filter(fn ($i) => $i['level'] === TillHealth::CONFIRMED)->map(fn ($i) => $i['tills'])->all(),
            'suspected_tills_by_code' => collect($report['issues'])->filter(fn ($i) => $i['level'] === TillHealth::SUSPECTED)->map(fn ($i) => $i['tills'])->all(),
            'stuck_jobs' => collect($report['queue'])->map(fn ($q) => $q['jobs'])->all(),
            'businesses_confirmed' => $report['classification']['businesses'][TillHealth::CONFIRMED],
        ]);
        $this->line('changed');

        return self::SUCCESS;
    }

    /**
     * ما ثبت وحده — والمشتبهُ لا يحرّك المراقبة: نمطٌ قد يكون مقصودًا لا يُنذر
     * كلَّ صباح. وورقٌ عالقٌ واقعة، فيدخل بعدده.
     */
    private static function fingerprint(array $report): string
    {
        $confirmed = [];

        foreach ($report['detail'] as $bid => $tills) {
            foreach ($tills as $till => $codes) {
                foreach ($codes as $code) {
                    if (TillHealth::LEVELS[$code] === TillHealth::CONFIRMED) {
                        $confirmed[] = $bid.':'.$till.':'.$code;
                    }
                }
            }
        }

        foreach ($report['queue'] as $kind => $queue) {
            if ($queue['jobs'] > 0) {
                $confirmed[] = 'queue:'.$kind.':'.$queue['jobs'];
            }
        }

        sort($confirmed);

        return hash('sha256', implode('|', $confirmed));
    }
}
