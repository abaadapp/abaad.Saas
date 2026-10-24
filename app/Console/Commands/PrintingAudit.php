<?php

namespace App\Console\Commands;

use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use App\Support\PrintJobs;
use App\Support\TillHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 * والتصنيفُ (`classification`) لكلّ صندوقٍ نشطٍ ولكلّ متجر: «مؤكَّد» إن كان فيه
 * ما ثبت، وإلّا «مشتبه» إن كان فيه ما يحتاج مراجعة، وإلّا «سليم».
 *
 * ═══ وما لا يُفعل ═══
 *
 * لا كتابةَ في القاعدة: على PostgreSQL معاملةٌ `READ ONLY`، وعلى SQLite
 * `query_only` — فلو أُضيف يومًا سطرٌ يكتب سقط ولم يكتب. ولا يُعيد ربط طابعة
 * ولا يُبطل صندوقًا ولا يمسّ ورقة. ولا يُخرج عنوانًا ولا اسمَ زبونٍ ولا رمزًا:
 * معرّفاتُ المتاجر والصناديق والرموزُ والأعداد وحدها.
 *
 * ولا يكتب في غير القاعدة أيضًا: لا كاشَ ولا سجلّ. المراقبةُ اليوميّة التي
 * تتذكّر وتكتب أمرٌ آخر (`pos:printing-watch`)، خلف مفتاحها.
 *
 * والتشغيلُ على الإنتاج بموافقة المالك وحده، ولا يُجدوَل.
 *
 *     php artisan pos:printing-audit             # ملخّص
 *     php artisan pos:printing-audit --detail    # ومعه: متجر ← صندوق ← رموز
 *     php artisan pos:printing-audit --json
 */
class PrintingAudit extends Command
{
    protected $signature = 'pos:printing-audit
        {--business= : متجرٌ واحد بمعرّفه}
        {--detail : الصناديقُ ورموزُها لكلّ متجر}
        {--json : الناتجُ JSON}';

    protected $description = 'تدقيق صناديق نقاط البيع وطابعاتها في كل المتاجر — قراءة فقط';

    /** ما يُوصى به لكلّ رمز — للمالك، لا للتاجر */
    public const ADVICE = [
        TillHealth::PRINTER_ON_REVOKED_TILL => 'يُسأل التاجر: إن كان هذا الجهاز بديلَ الصندوق الملغى فليُفعَّل بخيار «يحلّ محلّ» ذلك الصندوق فيعود بطابعته. وإلّا فلا شيء. لا يُنقل شيء آليًّا.',
        TillHealth::STALE_TILL_HOLDS_PRINTER => 'يُسأل التاجر: إن كان الجهاز الأحدث بديلًا فليُفعَّل بخيار «يحلّ محلّ». وإن كانا صندوقين فلا شيء.',
        TillHealth::AMBIGUOUS_RECEIPT => 'يحدّد التاجر «طابعة الإيصال» من الإعدادات. يُطبع حتى ذلك على الأقدم كما كان.',
        TillHealth::SAME_NAME_PRINTER => 'يتحقّق التاجر: إن كانتا الطابعة نفسها فليحذف المكرّرة أو يعطّلها. وإن كانتا طابعتين فليغيّر اسم إحداهما.',
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
     * - SQLite: `query_only`.
     * - PostgreSQL خارج معاملة: معاملةٌ `READ ONLY` لنا.
     * - PostgreSQL داخل معاملةٍ ليست لنا (الاختبارات، أو نداءٌ من داخل عمل):
     *   نقطةُ حفظٍ تُقلب للقراءة ثمّ يُتراجع إليها — فيسقط ما يكتب، ويعود من
     *   فتح المعاملةَ قابلًا للكتابة بعدنا. ولا يضيع شيء: لم يُكتب فيها شيء.
     *
     * وقاعدةٌ غيرهما لا تُقرأ: «قراءةٌ فقط» لا يحرسها إلّا القاعدة.
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

        if ($driver !== 'pgsql') {
            throw new \RuntimeException("pos:printing-audit يقرأ على PostgreSQL وSQLite وحدهما — لا حارسَ للقراءة على {$driver}.");
        }

        if (DB::transactionLevel() === 0) {
            return DB::transaction(function () use ($work) {
                DB::statement('SET TRANSACTION READ ONLY');

                return $work();
            });
        }

        DB::beginTransaction();

        try {
            DB::statement('SET LOCAL transaction_read_only = on');

            return $work();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{summary: array<string, int>, classification: array{tills: array<string, int>, businesses: array<string, int>}, issues: array<string, array{level: string, tills: int, businesses: int, advice: string}>, queue: array<string, array{jobs: int, businesses: int, advice: string}>, legitimate: array<string, int>, detail: array<int, array<int, list<string>>>}
     */
    public static function collect(?int $only = null): array
    {
        $businesses = DB::table('pos_devices')
            ->when($only, fn ($q) => $q->where('business_id', $only))
            ->distinct()->orderBy('business_id')->pluck('business_id')->map(fn ($id) => (int) $id);

        $summary = ['businesses_with_tills' => $businesses->count(), 'active_tills' => 0, 'revoked_tills' => 0, 'printers_active' => 0];
        $blank = [TillHealth::CONFIRMED => 0, TillHealth::SUSPECTED => 0, 'healthy' => 0];
        $classification = ['tills' => $blank, 'businesses' => $blank];
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

            /*
             * الصندوقُ بأسوأ ما فيه، والمتجرُ بأسوأ صناديقه: مؤكَّدٌ إن ثبت فيه
             * شيء، وإلّا مشتبهٌ إن كان فيه ما يُراجَع، وإلّا سليم.
             */
            $worst = 'healthy';

            foreach ($devices->where('status', PosDevice::ACTIVE) as $d) {
                $levels = collect($found[(int) $d->id] ?? [])->pluck('level');
                $level = $levels->contains(TillHealth::CONFIRMED) ? TillHealth::CONFIRMED
                    : ($levels->isNotEmpty() ? TillHealth::SUSPECTED : 'healthy');
                $classification['tills'][$level]++;

                if ($level === TillHealth::CONFIRMED || ($level === TillHealth::SUSPECTED && $worst === 'healthy')) {
                    $worst = $level;
                }
            }

            $classification['businesses'][$worst]++;

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
            'classification' => $classification,
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

    private function print(array $report): void
    {
        $this->info('صناديق نقاط البيع وطابعاتها — قراءة فقط');
        $this->table(['المقياس', 'العدد'], collect($report['summary'])->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->line('');
        $this->info('التصنيف — مؤكَّد · مشتبه · سليم');
        $this->table(['', TillHealth::CONFIRMED, TillHealth::SUSPECTED, 'healthy'], collect($report['classification'])
            ->map(fn ($c, $what) => [$what, $c[TillHealth::CONFIRMED], $c[TillHealth::SUSPECTED], $c['healthy']])->values()->all());

        $this->line('');
        $this->info('ما يمنع الطباعة أو يحرفها، وما يحتاج مراجعة');
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
