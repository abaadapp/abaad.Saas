<?php

namespace App\Support;

use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Rules\PrinterAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * صحّةُ الصناديق وطابعاتها — ما يمنع الإيصالَ من الخروج، يُقرأ ولا يُصلَح.
 *
 * ═══ ولمَ صنفٌ واحد ═══
 *
 * السؤالُ نفسُه يُسأل في ثلاثة مواضع: شاشةُ الأجهزة (يرى التاجرُ ما في صندوقه)،
 * وأداةُ التدقيق (`pos:printing-audit` — كلُّ المتاجر، قراءةً فقط)، وفحصُ الصحّة
 * الدوريّ. وقاعدتان لسؤالٍ واحد تفترقان يومًا فتقول الشاشةُ «سليم» والتقريرُ
 * «معطوب». فالقاعدةُ هنا وحدها، على صفوفٍ خام لا على نماذج: التدقيقُ يقرأ
 * بـ`DB::table` بلا محوّلات، والشاشةُ تمرّر نماذجها.
 *
 * ═══ ولا يكتب شيئًا ═══
 *
 * لا يُعيد ربط طابعة، ولا يُبطل صندوقًا، ولا يحذف. يقول ما رأى ويترك القرار
 * لصاحبه — والإصلاحُ الآليّ لصندوقٍ ملتبس يبدّل طابعةَ تاجرٍ بصمت.
 *
 * ═══ ودرجتان ═══
 *
 * - `confirmed` — واقعةٌ تمنع الطباعةَ أو تحرفها، تُقرأ من الصفوف وحدها:
 *   عنوانٌ يرفضه التطبيق، طابعتان ولا طابعةَ إيصالٍ مسمّاة، طابعةٌ مكرّرة،
 *   طابعةٌ بقيت على صندوقٍ ملغى وخلفُه بلا طابعة.
 * - `suspected` — نمطٌ يحتمل الخطأ ويحتمل القصد، يحتاج عينَ صاحبه: صندوقٌ
 *   قديمٌ نشطٌ لم يُرَ منذ فُعّل صندوقٌ أحدثُ بلا طابعة.
 *
 * وصندوقٌ بلا طابعةٍ ليس معطوبًا بذاته — كثيرٌ من المتاجر تطبع من المتصفّح
 * أو لا تطبع. فلا يُعدّ إلّا حين يقول جارُه إنّ طابعتَه هناك.
 */
final class TillHealth
{
    public const CONFIRMED = 'confirmed';

    public const SUSPECTED = 'suspected';

    /** صندوقٌ نشطٌ بلا طابعة، وطابعتُه على صندوقٍ ملغى في فرعه — أثرُ تفعيلٍ من متصفّحٍ آخر */
    public const ORPHANED_PRINTER = 'orphaned_printer';

    /** صندوقٌ أحدثُ بلا طابعة، وأقدمُ نشطٌ بطابعته لم يُرَ منذ فُعّل الأحدث */
    public const STALE_TILL_HOLDS_PRINTER = 'stale_till_holds_printer';

    /** طابعتان مفعّلتان أو أكثر ولا «طابعة إيصال» مسمّاة — يُطبع على الأقدم */
    public const AMBIGUOUS_RECEIPT = 'ambiguous_receipt';

    /** «طابعة الإيصال» المسمّاة معطّلة — يُطبع على غيرها أو لا يُطبع */
    public const RECEIPT_DISABLED = 'receipt_disabled';

    /** طابعةُ شبكةٍ بعنوانٍ يرفضه التطبيق (`PrinterAddress`) */
    public const INVALID_ADDRESS = 'invalid_address';

    /** طابعتان مفعّلتان بالعنوان والمنفذ نفسيهما، أو بالاسم والتوصيل نفسيهما */
    public const DUPLICATE_PRINTER = 'duplicate_printer';

    /** «طباعة تلقائية بعد البيع» على طابعةٍ ليست طابعةَ الإيصال — لا أثرَ لها */
    public const AUTO_PRINT_ELSEWHERE = 'auto_print_elsewhere';

    public const LEVELS = [
        self::ORPHANED_PRINTER => self::CONFIRMED,
        self::STALE_TILL_HOLDS_PRINTER => self::SUSPECTED,
        self::AMBIGUOUS_RECEIPT => self::CONFIRMED,
        self::RECEIPT_DISABLED => self::CONFIRMED,
        self::INVALID_ADDRESS => self::CONFIRMED,
        self::DUPLICATE_PRINTER => self::CONFIRMED,
        self::AUTO_PRINT_ELSEWHERE => self::CONFIRMED,
    ];

    /** المنفذُ حين لا يُكتب — كما في التطبيقين (`mobile/PROTOCOL.md` §4) */
    private const DEFAULT_PORT = 9100;

    /**
     * ما في كلّ صندوقٍ نشط — مفهرسٌ بمعرّفه، وما لا شيءَ فيه لا يظهر.
     *
     * @param  iterable<object|array|Model>  $devices  id, business_id, branch_id, status, activated_at, last_seen_at
     * @param  iterable<object|array|Model>  $peripherals  id, pos_device_id, name, type, connection, address, port, active, auto_print, is_receipt_printer
     * @return array<int, list<array{code: string, level: string, peripheral: int|null, other: int|null}>>
     */
    public static function assess(iterable $devices, iterable $peripherals): array
    {
        $devices = collect($devices)->map(fn ($d) => self::row($d))->keyBy('id');
        $byDevice = collect($peripherals)->map(fn ($p) => self::row($p))->groupBy('pos_device_id');

        $printersOf = fn (int $id) => ($byDevice->get($id) ?? collect())
            ->filter(fn ($p) => $p['type'] === PosPeripheral::PRINTER)
            ->sortBy('id')->values();
        $activeOf = fn (int $id) => $printersOf($id)->filter(fn ($p) => (bool) $p['active'])->values();

        $found = [];
        $note = function (int $device, string $code, ?int $peripheral = null, ?int $other = null) use (&$found) {
            $found[$device][] = ['code' => $code, 'level' => self::LEVELS[$code], 'peripheral' => $peripheral, 'other' => $other];
        };

        foreach ($devices as $id => $d) {
            if ($d['status'] !== PosDevice::ACTIVE) {
                continue;
            }

            $all = $printersOf($id);
            $active = $activeOf($id);
            $named = $all->first(fn ($p) => (bool) $p['is_receipt_printer']);

            if ($named && ! $named['active']) {
                $note($id, self::RECEIPT_DISABLED, $named['id']);
            }

            if ($active->count() > 1 && ! $active->contains(fn ($p) => (bool) $p['is_receipt_printer'])) {
                $note($id, self::AMBIGUOUS_RECEIPT);
            }

            $receipt = $active->first(fn ($p) => (bool) $p['is_receipt_printer']) ?? $active->first();

            foreach ($active as $p) {
                if ($p['connection'] === 'network' && ! PrinterAddress::allows((string) $p['address'])) {
                    $note($id, self::INVALID_ADDRESS, $p['id']);
                }

                if ($p['auto_print'] && $receipt && $p['id'] !== $receipt['id']) {
                    $note($id, self::AUTO_PRINT_ELSEWHERE, $p['id']);
                }
            }

            foreach (self::duplicates($active) as $p) {
                $note($id, self::DUPLICATE_PRINTER, $p['id']);
            }

            if ($active->isEmpty()) {
                self::holder($d, $devices, $activeOf, $note);
            }
        }

        return $found;
    }

    /**
     * صندوقٌ نشطٌ بلا طابعة: أطابعتُه على صندوقٍ آخر في فرعه؟
     *
     * - على صندوقٍ **ملغى** — مؤكَّد: الطابعةُ لا تخدم أحدًا، وهذا أثرُ تفعيلٍ
     *   من متصفّحٍ أو تطبيقٍ آخر وُلد فيه صفٌّ جديد.
     * - على صندوقٍ **نشطٍ أقدمَ** لم يُرَ منذ فُعّل هذا — مشتبه: قد يكون جهازًا
     *   هُجر، وقد يكون صندوقًا ثانيًا مطفأً منذ أيّام.
     * - على صندوقٍ نشطٍ يُستعمل — لا شيء: صندوقان في المحلّ، واحدٌ بلا طابعة.
     */
    private static function holder(array $d, $devices, callable $activeOf, callable $note): void
    {
        $since = $d['activated_at'] ? Carbon::parse($d['activated_at']) : null;

        foreach ($devices as $otherId => $o) {
            if ($otherId === $d['id'] || $o['business_id'] !== $d['business_id'] || $o['branch_id'] !== $d['branch_id']) {
                continue;
            }

            if ($activeOf($otherId)->isEmpty()) {
                continue;
            }

            if ($o['status'] !== PosDevice::ACTIVE) {
                $note($d['id'], self::ORPHANED_PRINTER, null, $otherId);

                return;
            }

            $seen = $o['last_seen_at'] ? Carbon::parse($o['last_seen_at']) : null;

            if ($since && $otherId < $d['id'] && ($seen === null || $seen->lt($since))) {
                $note($d['id'], self::STALE_TILL_HOLDS_PRINTER, null, $otherId);

                return;
            }
        }
    }

    /**
     * المكرّرةُ بين طابعات صندوقٍ مفعّلة — كلُّ ما بعد الأولى من كلّ مجموعة.
     *
     * طابعتا شبكةٍ بالعنوان والمنفذ الفعليّ نفسيهما (والفارغُ 9100) طابعةٌ
     * واحدةٌ سُجّلت مرّتين. وطابعتان بالاسم والتوصيل نفسيهما كذلك.
     */
    private static function duplicates($active): array
    {
        $seen = [];
        $dupes = [];

        foreach ($active as $p) {
            $keys = ['name:'.$p['connection'].':'.mb_strtolower(trim((string) $p['name']))];

            if ($p['connection'] === 'network' && trim((string) $p['address']) !== '') {
                $keys[] = 'net:'.mb_strtolower(trim((string) $p['address'])).':'.((int) $p['port'] ?: self::DEFAULT_PORT);
            }

            if (array_intersect($keys, array_keys($seen))) {
                $dupes[] = $p;
            }

            foreach ($keys as $key) {
                $seen[$key] = true;
            }
        }

        return $dupes;
    }

    /** صفٌّ خامٌ موحَّد — من نموذجٍ أو من `DB::table` */
    private static function row(object|array $r): array
    {
        $a = $r instanceof Model ? $r->getAttributes() : (array) $r;

        foreach (['id', 'business_id', 'branch_id', 'pos_device_id', 'port'] as $int) {
            if (array_key_exists($int, $a) && $a[$int] !== null) {
                $a[$int] = (int) $a[$int];
            }
        }

        foreach (['active', 'auto_print', 'is_receipt_printer'] as $bool) {
            if (array_key_exists($bool, $a)) {
                $a[$bool] = (bool) $a[$bool];
            }
        }

        return $a + ['activated_at' => null, 'last_seen_at' => null, 'is_receipt_printer' => false, 'auto_print' => false];
    }

    /**
     * صناديقُ متجرٍ واحد كما تُقرأ للشاشة والجرس — صفوفٌ خام، بلا محوّلات.
     *
     * @return array<int, list<array{code: string, level: string, peripheral: int|null, other: int|null}>>
     */
    public static function forBusiness(int $businessId): array
    {
        $devices = DB::table('pos_devices')->where('business_id', $businessId)
            ->get(['id', 'business_id', 'branch_id', 'name', 'status', 'activated_at', 'last_seen_at']);

        $peripherals = DB::table('pos_peripherals')->where('business_id', $businessId)
            ->whereIn('pos_device_id', $devices->pluck('id'))
            ->get(['id', 'pos_device_id', 'name', 'type', 'connection', 'address', 'port', 'active', 'auto_print', 'is_receipt_printer']);

        return self::assess($devices, $peripherals);
    }

    /**
     * صفوفُ الجرس — ما ثبت وحده، صفٌّ لكلّ صندوق، بمفتاحٍ ثابتٍ يغيب حين يُصلَح.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bellItems(int $businessId): array
    {
        $names = DB::table('pos_devices')->where('business_id', $businessId)->pluck('name', 'id');
        $items = [];

        foreach (self::forBusiness($businessId) as $device => $issues) {
            $first = collect($issues)->firstWhere('level', self::CONFIRMED);

            if ($first === null) {
                continue;
            }

            $items['till-printer-'.$device] = [
                'text' => __('صندوق «:till»: :what', ['till' => $names[$device] ?? '—', 'what' => self::message($first['code'])]),
                'time' => __('تنبيه طباعة'),
                'icon' => 'printer', 'color' => 'warning',
                'url' => route('admin.devices.index'),
            ];
        }

        return $items;
    }

    /** ما يقوله كلُّ رمزٍ للتاجر — جملةٌ تقول ما يقع وماذا يفعل */
    public static function message(string $code): string
    {
        return match ($code) {
            self::ORPHANED_PRINTER => __('هذا الصندوق بلا طابعة، وطابعته ما زالت على صندوق ملغى في الفرع نفسه. أعد تفعيل الجهاز واختر «يحلّ محلّ» ذلك الصندوق، أو أضف الطابعة هنا.'),
            self::STALE_TILL_HOLDS_PRINTER => __('هذا الصندوق بلا طابعة، وصندوق أقدم في الفرع نفسه يحمل طابعة ولم يُستعمل منذ فُعّل هذا. إن كان هذا الجهاز بديله فأعد التفعيل واختر «يحلّ محلّ» ذلك الصندوق.'),
            self::AMBIGUOUS_RECEIPT => __('على هذا الصندوق أكثر من طابعة مفعّلة ولم تُحدَّد طابعة الإيصال — يُطبع على الأقدم. حدّد طابعة الإيصال أو عطّل الزائدة.'),
            self::RECEIPT_DISABLED => __('طابعة الإيصال المحدّدة معطّلة — فعّلها أو حدّد طابعة أخرى.'),
            self::INVALID_ADDRESS => __('عنوان طابعة الشبكة غير صالح، فلن يطبع عليها تطبيق أبعاد. صحّح العنوان (مثل 192.168.1.50) والمنفذ في حقله.'),
            self::DUPLICATE_PRINTER => __('طابعة مسجّلة مرّتين على هذا الصندوق — احذف المكرّرة أو عطّلها.'),
            self::AUTO_PRINT_ELSEWHERE => __('«طباعة تلقائية بعد البيع» على طابعة ليست طابعة الإيصال — لا أثر لها. شغّلها على طابعة الإيصال.'),
            default => $code,
        };
    }
}
