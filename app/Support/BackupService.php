<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Archive\Policy as ArchivePolicy;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * حمولةُ النسخة الاحتياطية لمتجرٍ واحد — للتنزيل اليدويّ وللجدولة معًا.
 *
 * كانت الجداولُ مكتوبةً هنا بأسمائها سبعةَ عشرَ سطرًا، وفي القاعدة أكثرُ من
 * ستّين. فما لم يُذكر لم يُنسخ، ولا شيء يقول ذلك: يفتح التاجر الملفَّ فيراه
 * ممتلئًا، ويكتشف يوم الاستعادة أنّ مخزون فروعه وصناديقَه ودفترَ أستاذه لم
 * تكن فيه — وهو آخرُ يومٍ يصلح للاكتشاف.
 *
 * فصارت تُقرأ من `TenantTables` — قائمةٌ واحدة يقرؤها هذا الملفُّ والاستعادةُ
 * معًا، ويحرسها اختبارٌ يسقط يوم يُضاف جدولٌ لا يُصنَّف.
 *
 * وما لا يُنسخ منصوصٌ عليه بسببه في `TenantTables::NOT_MINE`، والأعمدةُ
 * السرّيّة تُنزع في `SECRETS`: ملفٌّ يُنزَّل على جهازٍ ليس مكانَ رمزِ وصول.
 *
 * ═══ والنسخةُ المحفوظة على الخادم ═══
 *
 * تُكتب مضغوطةً (`.json.gz`) في `storage/app/private/backups/<اليوم>/`،
 * ويُقرأ اسمُها وحده ليُعرف متجرُها ونوعُها ووقتُها — فلا سجلَّ ثانيًا في
 * القاعدة يفترق عن القرص. والقديمةُ غيرُ المضغوطة (`.json`) تُقرأ كما هي.
 */
class BackupService
{
    /**
     * النسخةُ الثالثة: الجداولُ كلُّها لا سبعةَ عشرَ.
     *
     * والرقمُ يُقرأ عند الاستعادة: ملفٌّ من الثانية ينقصه أربعون جدولًا،
     * واستعادتُه تحذف ما لا تُعيد — فيُقال ذلك قبل الحذف لا بعده.
     */
    public const VERSION = 3;

    /** مجلّدُ النسخ على القرص الخاصّ — والأيامُ تحته مجلّداتٌ بتاريخها */
    public const DIR = 'backups';

    /**
     * ما عُرف عن آخر ما كُتب لكلّ متجر — أنُسخ بعيدًا أم لا.
     *
     * القرصُ يقول أيُّ نسخةٍ هي الأخيرة، ولا يقول أبلغت القرصَ البعيد. وسؤالُ
     * المزوّد البعيد في كلّ فتحةٍ للشاشة نداءٌ شبكيّ يُعلّقها — فيُحفظ الجوابُ
     * ساعةَ النسخ، ويُقرأ فقط إن طابق الملفَّ الأخير نفسَه.
     */
    public const STATE_DIR = 'backups/state';

    /** إعدادُ المتجر الذي يقول متى يُنسخ تلقائيًّا */
    public const FREQUENCY = 'backup_frequency';

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const MANUAL = 'manual';

    /**
     * والافتراضُ يوميّ — وهو ما كان يجري لكلّ متجرٍ قبل أن يُسأل.
     *
     * متجرٌ قديمٌ لم يختر شيئًا يبقى على ما اعتاده بلا حرفٍ يتغيّر، ولا
     * يصحو يومًا ليجد أنّ نسخته صارت أسبوعيّةً لأنّ حقلًا لم يُملأ.
     */
    public const DEFAULT_FREQUENCY = self::DAILY;

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::MANUAL];

    /** نسخةٌ عاديّة: مجدولةٌ أو بضغطة «إنشاء نسخة الآن» */
    public const KIND_BACKUP = 'backup';

    /**
     * نسخةُ الأمان: حالُ المتجر لحظةَ قبل الاستعادة.
     *
     * واسمُها غيرُ اسم العاديّة عمدًا: لا تصير «آخرَ نسخة» فتُستعاد بضغطةٍ
     * ثانية على الزرّ نفسه — فيتأرجح المتجر بين حالين كلّما ضُغط.
     */
    public const KIND_SAFETY = 'safety';

    /**
     * أقصى ما يُقبل من JSON بعد فكّ الضغط — خمسون ميجابايت.
     *
     * وهو حدُّ الملفّ المرفوع نفسُه منذ أوّل يوم (`max:51200`). وملفٌّ مضغوطٌ
     * صغيرٌ يفكّ إلى مئات الميجابايتات يتخطّى ذلك الحدَّ من بابٍ خلفيّ —
     * فيُقاس ما بعد الفكّ لا ما قبله، ويُقطع الفكُّ ساعةَ يتجاوزه.
     */
    public const MAX_JSON_BYTES = 50 * 1024 * 1024;

    private const NAME = '/^abadpos-(backup|safety)-(\d+)-(\d{4}-\d{2}-\d{2}-\d{6})\.json(\.gz)?$/';

    public static function payload(int $bid): array
    {
        $data = [
            'meta' => [
                'app' => 'AbadPOS',
                'version' => self::VERSION,
                'business_id' => $bid,
                'exported_at' => now()->toIso8601String(),
                // ما احتواه هذا الملفّ فعلًا — تقرؤه الاستعادةُ ولا تخمّنه
                'tables' => [],
            ],
            'business' => Business::find($bid)?->only(Business::BACKUP_FIELDS),
        ];

        foreach (TenantTables::all() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $data[$table] = self::rows($table, $bid);
            $data['meta']['tables'][] = $table;
        }

        return $data;
    }

    /** سطورُ جدولٍ لهذا المتجر، منزوعةَ ما لا يخرج في ملفّ */
    private static function rows(string $table, int $bid): array
    {
        $strip = TenantTables::SECRETS[$table] ?? [];

        return TenantTables::scope($table, $bid)->orderBy('id')->get()
            ->map(function ($row) use ($strip) {
                $row = (array) $row;

                foreach ($strip as $column) {
                    unset($row[$column]);
                }

                return $row;
            })->all();
    }

    public static function json(int $bid): string
    {
        return json_encode(self::payload($bid), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * الحمولةُ نفسُها مضغوطة — لما يُحفظ على الخادم.
     *
     * والمسافاتُ الجميلة تُترك: ملفٌّ لا يقرؤه إنسانٌ قبل فكّه، والضغطُ
     * يأكلها على كلّ حال.
     */
    public static function gzip(int $bid): string
    {
        $raw = json_encode(self::payload($bid), JSON_UNESCAPED_UNICODE);

        return gzencode($raw, 6);
    }

    /**
     * يقرأ ملفَّ نسخة — مضغوطًا كان أو نصًّا — ويردّ ما فيه أو `null`.
     *
     * ويُعرف الضغطُ من أوّل بايتين لا من الاسم: ملفٌّ أُعيدت تسميتُه على جهاز
     * التاجر، أو متصفّحٌ فكّ الضغطَ في الطريق وأبقى الاسم، يُقرآن معًا.
     *
     * @param  resource  $stream
     *
     * @throws BackupTooLarge حين يتجاوز ما بعد الفكّ `$max`
     */
    public static function read($stream, int $max = self::MAX_JSON_BYTES): ?array
    {
        $raw = self::inflate($stream, $max);

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * يفكّ الملفَّ قطعةً قطعة، ويقف ساعةَ يتجاوز الحدّ — لا بعد أن يفكّه كلَّه.
     *
     * ═══ ولمَ لا `gzdecode` ثمّ قياسُ الطول ═══
     *
     * `gzdecode` تفكّ الملفَّ كلَّه في الذاكرة قبل أن يُسأل عن طوله: ميجابايتٌ
     * مضغوطٌ من الأصفار يصير ألفَ ميجابايت، فتسقط العمليّةُ على حدّ الذاكرة
     * قبل أن يُقرأ سطرُ القياس. فيُقرأ المضغوطُ كيلوبايتًا كيلوبايتًا — وأقصى
     * ما يخرج من كيلوبايتٍ واحد في deflate نحوُ ميجابايت — فلا يتجاوز ما في
     * الذاكرة الحدَّ إلّا بقطعةٍ واحدة.
     *
     * @param  resource  $stream
     */
    private static function inflate($stream, int $max): ?string
    {
        $first = fread($stream, 2);

        if ($first === false) {
            return null;
        }

        $gz = $first === "\x1f\x8b";
        $context = $gz ? inflate_init(ZLIB_ENCODING_GZIP) : null;

        if ($gz && $context === false) {
            return null;
        }

        $out = '';
        $chunk = $first;

        while (true) {
            if ($chunk !== '') {
                $piece = $gz ? @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH) : $chunk;

                if ($piece === false) {
                    return null;
                }

                $out .= $piece;

                if (strlen($out) > $max) {
                    throw new BackupTooLarge($max);
                }
            }

            if (feof($stream)) {
                break;
            }

            $chunk = fread($stream, $gz ? 1024 : 65536);

            if ($chunk === false) {
                return null;
            }
        }

        if ($gz) {
            $tail = @inflate_add($context, '', ZLIB_FINISH);

            if ($tail === false) {
                return null;
            }

            $out .= $tail;

            if (strlen($out) > $max) {
                throw new BackupTooLarge($max);
            }
        }

        return $out;
    }

    /** يقرأ نسخةً من نصٍّ في الذاكرة — بالحدّ نفسِه */
    public static function decode(string $raw, int $max = self::MAX_JSON_BYTES): ?array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        try {
            return self::read($stream, $max);
        } finally {
            fclose($stream);
        }
    }

    /**
     * يقرأ ملفًّا على القرص الخاصّ — بالحدّ نفسِه.
     *
     * @throws BackupTooLarge
     */
    public static function readFile(string $path, int $max = self::MAX_JSON_BYTES): ?array
    {
        $stream = self::disk()->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            return self::read($stream, $max);
        } finally {
            fclose($stream);
        }
    }

    public static function filename(int $bid, string $kind = self::KIND_BACKUP, bool $compressed = false): string
    {
        return 'abadpos-'.$kind.'-'.$bid.'-'.now()->format('Y-m-d-His').'.json'.($compressed ? '.gz' : '');
    }

    /* ============================ التكرار ============================ */

    /** ما اختاره المتجر — وما لا يُعرف يُقرأ يوميًّا */
    public static function frequency(int $bid): string
    {
        $value = Setting::where('business_id', $bid)->where('key', self::FREQUENCY)->value('value');

        return in_array($value, self::FREQUENCIES, true) ? $value : self::DEFAULT_FREQUENCY;
    }

    public static function setFrequency(int $bid, string $frequency): void
    {
        Setting::updateOrCreate(
            ['business_id' => $bid, 'key' => self::FREQUENCY],
            ['value' => $frequency],
        );
    }

    /**
     * أحان موعدُ النسخ المجدول لهذا المتجر؟
     *
     * يُسأل مرّةً كلَّ ليلة. واليوميُّ يُنسخ كلَّ ليلةٍ كما كان. والأسبوعيُّ
     * والشهريُّ يُقاسان من **آخر نسخةٍ عاديّة** — مجدولةً كانت أو يدويّة —
     * لا من يومٍ ثابت في التقويم: من ضغط «إنشاء نسخة الآن» يوم الأربعاء لا
     * يُنسخ له ثانيةً يوم الخميس لأنّ الخميس موعدُه.
     *
     * والمقارنةُ بالأيام لا بالثواني: نسخةُ الثانية فجرًا الأسبوعَ الماضي
     * كُتبت بعد الثانية بدقيقة، فلو قيست بالثواني لبدت أحدثَ من أسبوعٍ بدقيقة
     * وتأخّرت أسبوعًا آخر.
     *
     * واليدويُّ لا يحين أبدًا — لكنّ زرّ «إنشاء نسخة الآن» يعمل له كما لغيره.
     */
    public static function due(int $bid, ?Carbon $now = null): bool
    {
        $frequency = self::frequency($bid);

        if ($frequency === self::MANUAL) {
            return false;
        }

        if ($frequency === self::DAILY) {
            return true;
        }

        $last = self::latest($bid);

        if ($last === null) {
            return true;
        }

        $today = ($now ?? now())->copy()->startOfDay();
        $lastDay = $last['created_at']->copy()->startOfDay();

        $threshold = $frequency === self::WEEKLY
            ? $today->copy()->subDays(7)
            : $today->copy()->subMonthNoOverflow();

        return $lastDay->lte($threshold);
    }

    /* ========================= الكتابة والتحقّق ========================= */

    /**
     * يكتب نسخةً مضغوطة لمتجرٍ على القرص الخاصّ، ويتحقّق منها، وينسخها بعيدًا.
     *
     * ويردّ وصفَها، أو يرمي — ولا يترك ملفًّا معطوبًا خلفه: تركُه يجعله
     * يبدو نسخةً في القائمة، فيُطمأنّ إليه ولا يُفتح إلا في الأزمة.
     *
     * @return array{path: string, name: string, bytes: int, offsite: bool, kind: string, created_at: Carbon}
     */
    public static function store(int $bid, string $kind = self::KIND_BACKUP): array
    {
        $disk = self::disk();
        $path = self::DIR.'/'.now()->format('Y-m-d').'/'.self::filename($bid, $kind, true);

        try {
            if ($disk->put($path, self::gzip($bid)) === false) {
                throw new \RuntimeException(__('الملف لم يُكتب على القرص.'));
            }

            self::verify($disk, $path, $bid);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        $offsite = self::copyOffsite($disk, $path);
        $bytes = (int) $disk->size($path);

        self::remember($bid, $kind, $path, $offsite);

        return [
            'path' => $path,
            'name' => basename($path),
            'bytes' => $bytes,
            'offsite' => $offsite,
            'kind' => $kind,
            'created_at' => now(),
        ];
    }

    /**
     * يُعيد قراءة الملف من القرص ويطابق ما فيه — جدولًا جدولًا.
     *
     * لا يكفي أن تنجح الكتابة: القرص الممتلئ يكتب نصف ملف بلا خطأ في كثيرٍ
     * من أنظمة الملفات. ونصفُ ملفٍّ مضغوط لا يُفكّ أصلًا — فيسقط هنا لا يوم
     * الاستعادة.
     *
     * وكان العدُّ للطلبات وحدها. فصار لكلّ جدولٍ في `TenantTables`: نسخةُ
     * الأمان قبل الاستعادة هي طريقُ الرجوع الوحيد، ونسخةٌ ينقصها دفترُ
     * الأستاذ وتُعدّ طلباتُها صحيحة ليست طريقَ رجوع.
     *
     * ويُقرأ ملفُّنا بلا حدِّ الرفع: كتبناه الآن من القاعدة، ومتجرٌ كبيرٌ
     * تُرفض نسختُه هنا يبقى بلا نسخةٍ أصلًا.
     */
    private static function verify(Filesystem $disk, string $path, int $bid): void
    {
        if (! $disk->exists($path)) {
            throw new \RuntimeException(__('الملف لم يُكتب على القرص.'));
        }

        $data = self::readFile($path, PHP_INT_MAX);

        if ($data === null) {
            throw new \RuntimeException(__('الملف مكتوب لكنه لا يُقرأ (JSON معطوب).'));
        }

        if (($data['meta']['business_id'] ?? null) !== $bid) {
            throw new \RuntimeException(__('الملف يحمل معرّف متجر مختلفًا.'));
        }

        $expected = Order::where('business_id', $bid)->count();
        $actual = count($data['orders'] ?? []);

        if ($actual !== $expected) {
            throw new \RuntimeException(__('عدد الطلبات ناقص: :actual من :expected.', [
                'actual' => $actual, 'expected' => $expected,
            ]));
        }

        foreach (TenantTables::all() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $expected = TenantTables::scope($table, $bid)->count();
            $actual = is_array($data[$table] ?? null) ? count($data[$table]) : -1;

            if ($actual !== $expected) {
                throw new \RuntimeException(__('النسخة لا تطابق المتجر في :table: :actual من :expected.', [
                    'table' => $table, 'actual' => $actual, 'expected' => $expected,
                ]));
            }
        }
    }

    /**
     * ينسخ الملفَّ إلى القرص البعيد إن كان مضبوطًا — ويردّ أنُسخ أم لا.
     *
     * ═══ ولمَ لا يُسقط فشلُه النسخةَ المحلّيّة ═══
     *
     * المحلّيّةُ كُتبت وتُحقّق منها قبل هذا السطر. ورميُ استثناءٍ هنا يجعل
     * الملتقِطَ يحذفها — فينتهي المتجر **بلا نسخةٍ أصلًا** لأنّ مزوّدًا
     * بعيدًا لم يُجب. وهو أن تُفقد نسخةٌ موجودة لأجل نسخةٍ إضافيّة.
     *
     * فالفشلُ يُقيَّد في السجلّ ويُردّ `false`، والعدُّ في البصمة يقول كم
     * نُسخ بعيدًا فعلًا — فيُرى الانقطاعُ ولا يُخفى.
     *
     * والمفاتيحُ لا تُلمس هنا ولا تُطبع: القرصُ باسمه، وما خلفه في `.env`
     * عند المشغّل.
     */
    private static function copyOffsite(Filesystem $disk, string $path): bool
    {
        if (! ArchivePolicy::backupRemoteEnabled()) {
            return false;
        }

        $remote = ArchivePolicy::remoteDisk();

        try {
            $stream = $disk->readStream($path);

            if ($stream === null || $stream === false) {
                return false;
            }

            try {
                $ok = Storage::disk($remote)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $ok !== false;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** يحفظ أنُسخ هذا الملفُّ بعيدًا — يُقرأ في الشاشة */
    private static function remember(int $bid, string $kind, string $path, bool $offsite): void
    {
        $disk = self::disk();
        $file = self::STATE_DIR.'/'.$bid.'.json';
        $state = json_decode((string) ($disk->exists($file) ? $disk->get($file) : ''), true);
        $state = is_array($state) ? $state : [];

        $state[$kind] = ['path' => $path, 'offsite' => $offsite];

        $disk->put($file, json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    /* ============================ القراءة ============================ */

    /**
     * آخرُ نسخةٍ ناجحةٍ لهذا المتجر — أو `null`.
     *
     * والناجحةُ هي الموجودة: ما فشل تحقّقُه حُذف ساعةَ كتابته. والبحثُ في
     * ملفّات هذا المتجر وحدها — باسمٍ يبدأ بمعرّفه — فلا تُقرأ نسخةُ متجرٍ
     * آخر ولا تُسلَّم بخطأٍ في الترتيب.
     *
     * @return array{path: string, name: string, bytes: int, offsite: ?bool, kind: string, compressed: bool, created_at: Carbon}|null
     */
    public static function latest(int $bid, string $kind = self::KIND_BACKUP): ?array
    {
        $best = null;

        foreach (self::scan($bid) as $file) {
            if ($file['kind'] !== $kind) {
                continue;
            }

            if ($best === null || strcmp($file['stamp'], $best['stamp']) > 0) {
                $best = $file;
            }
        }

        if ($best === null) {
            return null;
        }

        $disk = self::disk();
        $state = json_decode((string) ($disk->exists(self::STATE_DIR.'/'.$bid.'.json')
            ? $disk->get(self::STATE_DIR.'/'.$bid.'.json') : ''), true);
        $known = is_array($state) ? ($state[$kind] ?? null) : null;

        return [
            'path' => $best['path'],
            'name' => basename($best['path']),
            'bytes' => (int) $disk->size($best['path']),
            /*
             * و`null` حين لا يُعرف — لا `false`.
             *
             * نسخةٌ كُتبت قبل أن يُحفظ هذا الجوابُ لا يُدرى أبلغت البعيد
             * أم لا، و«لا» كاذبةٌ تُقلق كما تُطمئن «نعم» كاذبة.
             */
            'offsite' => is_array($known) && ($known['path'] ?? null) === $best['path']
                ? (bool) ($known['offsite'] ?? false)
                : null,
            'kind' => $best['kind'],
            'compressed' => $best['compressed'],
            'created_at' => $best['at'],
        ];
    }

    /**
     * ما لا يُحذف في التنظيف: آخرُ نسخةٍ لكلّ متجرٍ من كلّ نوع.
     *
     * متجرٌ شهريٌّ مدّةُ الاحتفاظ عنده أربعةَ عشرَ يومًا تُحذف نسختُه الوحيدة
     * في اليوم الخامس عشر — فيقف أسبوعين بلا نسخةٍ أصلًا. والأخيرةُ لا تُحذف
     * مهما قدمت، حتّى تأتي بعدها أحدثُ منها.
     *
     * @return array<string, true> مساراتٌ مفاتيح
     */
    public static function protectedPaths(): array
    {
        $best = [];

        foreach (self::scan(null) as $file) {
            $key = $file['bid'].':'.$file['kind'];

            if (! isset($best[$key]) || strcmp($file['stamp'], $best[$key]['stamp']) > 0) {
                $best[$key] = $file;
            }
        }

        return array_fill_keys(array_column($best, 'path'), true);
    }

    /**
     * ملفّاتُ النسخ على القرص، مقروءةً من أسمائها.
     *
     * @return list<array{path: string, bid: int, kind: string, stamp: string, at: Carbon, compressed: bool}>
     */
    private static function scan(?int $bid): array
    {
        $disk = self::disk();
        $out = [];

        foreach ($disk->directories(self::DIR) as $dir) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', basename($dir))) {
                continue;
            }

            foreach ($disk->files($dir) as $path) {
                if (! preg_match(self::NAME, basename($path), $m)) {
                    continue;
                }

                if ($bid !== null && (int) $m[2] !== $bid) {
                    continue;
                }

                try {
                    $at = Carbon::createFromFormat('Y-m-d-His', $m[3]);
                } catch (\Throwable) {
                    continue;
                }

                $out[] = [
                    'path' => $path,
                    'bid' => (int) $m[2],
                    'kind' => $m[1],
                    'stamp' => $m[3],
                    'at' => $at,
                    'compressed' => ($m[4] ?? '') === '.gz',
                ];
            }
        }

        return $out;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
