<?php

namespace App\Console\Commands;

use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use App\Support\PrintJobs;
use App\Support\TillHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تدقيقُ صناديق كلّ المتاجر وطابعاتها — قراءةٌ فقط.
 *
 * ═══ ما يُعدّ ═══
 *
 * لكلّ متجرٍ له صندوق: ما يقوله `TillHealth` عن كلّ صندوقٍ نشط (مؤكَّدٌ ومشتبه)،
 * وأوراقُ الطابور العالقة (حجزٌ فاتت مهلتُه، وورقةٌ لم يستلمها صندوقٌ في
 * `PrintJobs::PENDING_HOURS`)، وما هو **مشروعٌ** لا يُعدّ عطلًا: متجرٌ لا طابعةَ
 * فيه أصلًا (يطبع من المتصفّح أو لا يطبع)، وصندوقٌ بلا طابعةٍ لا يقول جارُه
 * إنّ طابعتَه عنده.
 *
 * ═══ وما لا يُفعل ═══
 *
 * لا كتابةَ في القاعدة: على PostgreSQL معاملةٌ `READ ONLY`، وعلى SQLite
 * `query_only` — فلو أُضيف يومًا سطرٌ يكتب سقط ولم يكتب. ولا يُعيد ربط طابعة
 * ولا يُبطل صندوقًا ولا يمسّ ورقة. ولا يُخرج عنوانًا ولا اسمَ زبونٍ ولا رمزًا:
 * معرّفاتُ المتاجر والصناديق والرموزُ والأعداد وحدها.
 *
 * والتشغيلُ على الإنتاج بموافقة المالك وحده.
 *
 *     php artisan pos:printing-audit             # ملخّص
 *     php artisan pos:printing-audit --detail    # ومعه: متجر ← صندوق ← رموز
 *     php artisan pos:printing-audit --json
 *     php artisan pos:printing-audit --watch     # الفحص الدوريّ: سطرٌ في السجلّ حين يتغيّر شيءٌ مؤكَّد
 */
class PrintingAudit extends Command
{
    protected $signature = 'pos:printing-audit
        {--business= : متجرٌ واحد بمعرّفه}
        {--detail : الصناديقُ ورموزُها لكلّ متجر}
        {--json : الناتجُ JSON}
        {--watch : الفحص الدوريّ — يكتب في السجلّ حين يتغيّر المؤكَّد وحده}';

    protected $description = 'تدقيق صناديق نقاط البيع وطابعاتها في كل المتاجر — قراءة فقط';

    /** آخرُ ما رآه الفحصُ الدوريّ — بصمةٌ في الذاكرة المؤقّتة، لا في القاعدة */
    public const WATCH_KEY = 'pos:printing-audit:confirmed';

    /** ما يُوصى به لكلّ رمز — للمالك، لا للتاجر */
    public const ADVICE = [
        TillHealth::ORPHANED_PRINTER => 'يُعاد تفعيل الجهاز بخيار «يحلّ محلّ» الصندوق الملغى، فيعود بطابعته. لا يُنقل شيء آليًّا.',
        TillHealth::STALE_TILL_HOLDS_PRINTER => 'يُسأل التاجر: إن كان الجهاز الأحدث بديلًا فليُفعَّل بخيار «يحلّ محلّ». وإن كانا صندوقين فلا شيء.',
        TillHealth::AMBIGUOUS_RECEIPT => 'يحدّد التاجر «طابعة الإيصال» من الإعدادات. يُطبع حتى ذلك على الأقدم كما كان.',
        TillHealth::RECEIPT_DISABLED => 'يفعّل التاجر طابعة الإيصال أو يحدّد غيرها.',
        TillHealth::INVALID_ADDRESS => 'يصحّح التاجر العنوان (عنوان محلّي فقط، والمنفذ في حقله) ثم «اختبار الطابعة» من الجهاز نفسه.',
        TillHealth::DUPLICATE_PRINTER => 'يحذف التاجر المكرّرة أو يعطّلها بعد التأكّد أيّها تعمل.',
        TillHealth::AUTO_PRINT_ELSEWHERE => 'تُنقل «طباعة تلقائية بعد البيع» إلى طابعة الإيصال.',
        'claim_expired' => 'تُفتح لوحة التجهيز: الورقة «لم تُطبع» — تُعاد أو يُؤكَّد خروجها بيد.',
        'pending_stale' => 'يُعيَّن صندوقٌ بطابعة لفواتير العملاء في الفرع، ثم تُعاد الورقة من اللوحة.',
    ];

    public function handle(): int
    {
        $report = self::readOnly(fn () => self::collect($this->option('business') ? (int) $this->option('business') : null));

        if ($this->option('watch')) {
            return $this->watch($report);
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->option('detail') ? $report : array_diff_key($report, ['detail' => true]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->print($report);

        return self::SUCCESS;
    }

    /**
     * يقرأ ولا يكتب — والقاعدةُ تحرس ذلك، لا حسنُ النيّة.
     *
     * وداخل معاملةٍ قائمة (الاختبارات) لا تُفرض «قراءةٌ فقط» على PostgreSQL:
     * المعاملةُ ليست لنا، وقلبُها للقراءة يمنع من فتحها أن يكتب بعدنا.
     */
    public static function readOnly(callable $work): mixed
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA query_only = 1');

            try {
                return $work();
            } finally {
                DB::statement('PRAGMA query_only = 0');
            }
        }

        if ($driver === 'pgsql' && DB::transactionLevel() === 0) {
            return DB::transaction(function () use ($work) {
                DB::statement('SET TRANSACTION READ ONLY');

                return $work();
            });
        }

        return $work();
    }

    /**
     * @return array{summary: array<string, int>, issues: array<string, array{level: string, tills: int, businesses: int, advice: string}>, queue: array<string, array{jobs: int, businesses: int, advice: string}>, legitimate: array<string, int>, detail: array<int, array<int, list<string>>>}
     */
    public static function collect(?int $only = null): array
    {
        $businesses = DB::table('pos_devices')
            ->when($only, fn ($q) => $q->where('business_id', $only))
            ->distinct()->orderBy('business_id')->pluck('business_id')->map(fn ($id) => (int) $id);

        $summary = ['businesses_with_tills' => $businesses->count(), 'active_tills' => 0, 'revoked_tills' => 0, 'printers_active' => 0];
        $issues = [];
        $detail = [];
        $legitimate = ['businesses_without_any_printer' => 0, 'active_tills_without_printer_unflagged' => 0];

        foreach ($businesses as $bid) {
            $devices = DB::table('pos_devices')->where('business_id', $bid)
                ->get(['id', 'business_id', 'branch_id', 'name', 'status', 'activated_at', 'last_seen_at']);
            $peripherals = DB::table('pos_peripherals')->where('business_id', $bid)
                ->whereIn('pos_device_id', $devices->pluck('id'))
                ->get(['id', 'pos_device_id', 'name', 'type', 'connection', 'address', 'port', 'active', 'auto_print', 'is_receipt_printer']);
            $printers = $peripherals->where('type', PosPeripheral::PRINTER);
            $activePrinters = $printers->filter(fn ($p) => (bool) $p->active);

            $summary['active_tills'] += $devices->where('status', PosDevice::ACTIVE)->count();
            $summary['revoked_tills'] += $devices->where('status', '!=', PosDevice::ACTIVE)->count();
            $summary['printers_active'] += $activePrinters->count();

            if ($printers->isEmpty()) {
                $legitimate['businesses_without_any_printer']++;
            }

            $found = TillHealth::assess($devices, $peripherals);

            foreach ($found as $till => $list) {
                foreach (collect($list)->pluck('code')->unique() as $code) {
                    $issues[$code]['level'] = TillHealth::LEVELS[$code];
                    $issues[$code]['tills'] = ($issues[$code]['tills'] ?? 0) + 1;
                    $issues[$code]['business_ids'][$bid] = true;
                    $detail[$bid][$till][] = $code;
                }
            }

            if ($printers->isNotEmpty()) {
                $legitimate['active_tills_without_printer_unflagged'] += $devices
                    ->where('status', PosDevice::ACTIVE)
                    ->filter(fn ($d) => ! isset($found[(int) $d->id]) && $activePrinters->where('pos_device_id', $d->id)->isEmpty())
                    ->count();
            }
        }

        $issues = collect($issues)->map(fn ($i, $code) => [
            'level' => $i['level'],
            'tills' => $i['tills'],
            'businesses' => count($i['business_ids']),
            'advice' => self::ADVICE[$code] ?? '',
        ])->sortKeys()->all();

        return [
            'summary' => $summary,
            'issues' => $issues,
            'queue' => self::queue($only),
            'legitimate' => $legitimate,
            'detail' => $detail,
        ];
    }

    /** أوراقٌ عالقة — بالعدد والمتاجر، بلا رقم فاتورةٍ ولا اسم زبون */
    private static function queue(?int $only): array
    {
        $base = fn () => DB::table('print_jobs')->when($only, fn ($q) => $q->where('business_id', $only));

        $claimed = $base()->where('status', PrintJob::CLAIMED)->where('claim_expires_at', '<', now());
        $pending = $base()->where('status', PrintJob::PENDING)->where('updated_at', '<', now()->subHours(PrintJobs::PENDING_HOURS));

        return [
            'claim_expired' => ['jobs' => (clone $claimed)->count(), 'businesses' => (clone $claimed)->distinct()->count('business_id'), 'advice' => self::ADVICE['claim_expired']],
            'pending_stale' => ['jobs' => (clone $pending)->count(), 'businesses' => (clone $pending)->distinct()->count('business_id'), 'advice' => self::ADVICE['pending_stale']],
        ];
    }

    /**
     * الفحصُ الدوريّ — سطرٌ في السجلّ حين يتغيّر المؤكَّد، وصمتٌ ما دام كما كان.
     *
     * لا يكتب في القاعدة ولا يراسل تاجرًا: البصمةُ في الذاكرة المؤقّتة،
     * والسطرُ أعدادٌ ورموزٌ ومعرّفاتُ متاجر — بلا اسمٍ ولا عنوان.
     */
    private function watch(array $report): int
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

        foreach (['claim_expired', 'pending_stale'] as $kind) {
            if ($report['queue'][$kind]['jobs'] > 0) {
                $confirmed[] = 'queue:'.$kind.':'.$report['queue'][$kind]['jobs'];
            }
        }

        sort($confirmed);
        $now = hash('sha256', implode('|', $confirmed));
        $before = Cache::get(self::WATCH_KEY);

        if ($before !== $now) {
            Cache::forever(self::WATCH_KEY, $now);

            $counts = collect($report['issues'])->filter(fn ($i) => $i['level'] === TillHealth::CONFIRMED)->map(fn ($i) => $i['tills'])->all();
            $queue = collect($report['queue'])->map(fn ($q) => $q['jobs'])->all();

            Log::warning('pos.printing-health changed', [
                'confirmed_tills_by_code' => $counts,
                'stuck_jobs' => $queue,
                'businesses' => collect($report['detail'])->keys()->values()->all(),
            ]);
            $this->line('changed');
        }

        return self::SUCCESS;
    }

    private function print(array $report): void
    {
        $this->info('صناديق نقاط البيع وطابعاتها — قراءة فقط');
        $this->table(['المقياس', 'العدد'], collect($report['summary'])->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->line('');
        $this->info('ما يمنع الطباعة أو يحرفها');
        $this->table(['الرمز', 'الدرجة', 'صناديق', 'متاجر', 'العلاج'], collect($report['issues'])
            ->map(fn ($i, $code) => [$code, $i['level'], $i['tills'], $i['businesses'], $i['advice']])->values()->all());

        $this->line('');
        $this->info('أوراق الطابور العالقة');
        $this->table(['النوع', 'أوراق', 'متاجر', 'العلاج'], collect($report['queue'])
            ->map(fn ($q, $kind) => [$kind, $q['jobs'], $q['businesses'], $q['advice']])->values()->all());

        $this->line('');
        $this->info('حالات مشروعة — لا تُعدّ عطلًا');
        $this->table(['الحالة', 'العدد'], collect($report['legitimate'])->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($this->option('detail')) {
            $this->line('');
            foreach ($report['detail'] as $bid => $tills) {
                foreach ($tills as $till => $codes) {
                    $this->line("business {$bid} · till {$till} · ".implode(', ', $codes));
                }
            }
        }
    }
}
