<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Shift;
use App\Support\Shifts;
use Illuminate\Console\Command;

/**
 * إقفال الورديات المنسيّة.
 *
 * الفتح صار لا يرث ورديةً منسيّة (انظر Shifts::open)، لكن ذلك لا يكفي: من
 * لم يفتح غدًا تبقى ورديتُه مفتوحةً أسبوعًا، فتُجمَع فيها مبيعاتُ أيّامٍ
 * ويظهر «المتوقّع» رقمًا لا معنى له في شاشة صاحب النشاط. فتُقفل بلا عدّ في
 * وقتها لا حين يتذكّرها أحد.
 *
 * ولا تُقفل بفرقٍ صفر: لا أحد عدّ الدرج، والصفر يعني «طابق».
 */
class ShiftAutoClose extends Command
{
    protected $signature = 'shifts:auto-close {--dry-run : يعرض ما سيُقفل ولا يُقفل}';

    protected $description = 'يُقفل الورديات التي طال فتحُها بلا عدّ';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $closed = 0;

        // السقف إعدادُ كلّ متجرٍ على حدة، فتُقرأ الورديات متجرًا متجرًا
        foreach (Business::query()->pluck('id') as $businessId) {
            $maxHours = Shifts::maxHours($businessId);

            $open = Shift::where('business_id', $businessId)
                ->where('status', Shift::OPEN)
                ->get()
                ->filter(fn ($shift) => $shift->isStale($maxHours));

            foreach ($open as $shift) {
                $this->line(sprintf(
                    '%s وردية #%d — فُتحت %s (السقف %d ساعة)',
                    $dry ? '[تجربة]' : '↳',
                    $shift->id,
                    $shift->opened_at?->format('Y-m-d H:i') ?? '—',
                    $maxHours,
                ));

                if (! $dry) {
                    Shifts::closeWithoutCount($shift, Shift::BY_SYSTEM, __('أُقفلت تلقائيًّا: تُركت مفتوحة أكثر من :n ساعة', ['n' => $maxHours]));
                    $closed++;
                }
            }
        }

        $this->info($dry ? 'تجربة — لم يُقفل شيء' : "أُقفلت {$closed} وردية بلا عدّ");

        return self::SUCCESS;
    }
}
