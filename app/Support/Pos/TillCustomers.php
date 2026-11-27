<?php

namespace App\Support\Pos;

use App\Models\Customer;
use App\Support\CustomerFlags;
use Illuminate\Support\Facades\DB;

/**
 * زبائنُ الصندوق — ما يبحث به الكاشير ويختار ويبيع، لا ملفُّ العميل كلُّه.
 *
 * ═══ ما في الصفّ ولمَ ═══
 *
 * كان الصندوق يُرسَل إليه صفُّ شاشة العملاء كاملًا (`Demo::customers`): البريدُ
 * والرقمُ الضريبيّ والعنوانُ والفرعُ وعددُ المشتريات وإجماليُّها وآخرُ فاتورة —
 * لخمسة آلاف عميلٍ عند كلّ فتح: ٢٫٢ ميغابايت لا يقرأ الصندوقُ منها شيئًا
 * (`PosCustomer` في `usePosCart.ts`، وكلُّ قراءةٍ في الصندوق فُحصت حقلًا حقلًا).
 * فالصفُّ هنا ما يقرؤه وحده:
 *
 * - `id` — الاختيارُ والبيعُ به (`customer_id`).
 * - `name` و`name_en` و`phone` — البحثُ والعرض، و`label` هو الاسمُ نفسُه
 *   (`Customers::asEntered`) فيُبنى في المتصفّح ولا يُرسَل مرّتين.
 * - `points` — عرضُ الرصيد واستبداله؛ والخادمُ يعيد قراءته عند الدفع ويردّ
 *   البيعةَ إن تغيّر (`PosController`)، فرصيدٌ قديمٌ لا يبيع برصيدٍ خاطئ.
 * - `language` — سؤالُ لغة واتساب قبل البيع، ويُحمل مع البيعة.
 * - `context` — الحظرُ والتحذيرُ وعيدُ الميلاد والملاحظة بإذن الإعدادات
 *   (`CustomerFlags::context`)؛ والحظرُ يُقاس ثانيةً عند الدفع.
 *
 * والفارغُ لا يُرسَل: لا لغة، لا نقاط، لا سياق — والصندوقُ يقرأ غيابَها كما كان
 * يقرأ `null` (`!language`، `?.context`، `points ?? 0`).
 *
 * ═══ والمزامنةُ بالمحتوى لا بالتاريخ ═══
 *
 * يحفظ الصندوقُ القائمةَ في الجهاز (IndexedDB) ولا ينزّلها كاملةً كلَّ مرّة.
 * والفرقُ لا يُعرف بـ`updated_at`: السياقُ يتغيّر بإعدادٍ في المتجر وبمرور
 * الأيّام (عيدُ الميلاد) والصفُّ لم يُلمس. فتُقسَم القائمةُ دلاءً بالمعرّف،
 * ولكلّ دلوٍ بصمةُ ما فيه كما يُرسَل — ويرسل الجهازُ بصماته، فيعود إليه ما
 * تغيّرت بصمتُه وحده. عميلٌ جديد أو معدَّل أو محذوف يغيّر بصمةَ دلوه، ودلوٌ
 * لم يبقَ فيه أحد يغيب من الخريطة فيُمحى من الجهاز.
 */
final class TillCustomers
{
    /** عددُ الدلاء — دلوُ العميل `id % BUCKETS`؛ خمسةُ آلاف عميل ≈ عشرون في الدلو */
    public const BUCKETS = 256;

    /**
     * صفوفُ الصندوق مرتّبةً بالمعرّف — أو صفُّ عميلٍ واحد.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(int $businessId, ?int $id = null): array
    {
        $settings = CustomerFlags::settings($businessId);
        $flags = ['notes', 'alert_type', 'alert_reason', 'birth_day', 'birth_month', 'birth_year'];

        /*
         * من مُنشئ الاستعلام لا من النموذج: خمسةُ آلاف نموذجٍ تُبنى لتُقرأ منها
         * سبعةُ أعمدة. والعميلُ المحذوف لا يُقرأ — كما في `Customer::where`.
         */
        $rows = DB::table('customers')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->when($id !== null, fn ($q) => $q->where('id', $id))
            ->orderBy('id')
            ->get(['id', 'name', 'name_en', 'phone', 'points', 'language', ...$flags]);

        $out = [];

        foreach ($rows as $c) {
            $row = ['id' => (int) $c->id, 'name' => (string) $c->name];

            if (filled($c->name_en)) {
                $row['name_en'] = (string) $c->name_en;
            }

            if (filled($c->phone)) {
                $row['phone'] = (string) $c->phone;
            }

            if ((int) $c->points !== 0) {
                $row['points'] = (int) $c->points;
            }

            if (filled($c->language)) {
                $row['language'] = (string) $c->language;
            }

            // ‏سياقٌ لا يكون إلّا بحقلٍ من الستّة — والباقون بلا شيءٍ يُقال عنهم
            if (filled($c->notes) || filled($c->alert_type) || $c->birth_day !== null) {
                $model = (new Customer)->forceFill(array_intersect_key((array) $c, array_flip($flags)));
                $context = CustomerFlags::context($model, $settings);

                if ($context['alert'] !== null || $context['birthday_in'] !== null || $context['note'] !== null) {
                    $row['context'] = $context;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    public static function bucketOf(int $id): int
    {
        return $id % self::BUCKETS;
    }

    /**
     * ما يحتاجه جهازٌ يعرف هذه البصمات.
     *
     * @param  array<int|string, string>  $have  دلوٌ ← بصمتُه كما حفظها الجهاز
     * @return array{buckets: array<int, string>, rows: array<int, list<array<string, mixed>>>, count: int}
     */
    public static function sync(int $businessId, array $have): array
    {
        $grouped = [];

        foreach (self::rows($businessId) as $row) {
            $grouped[self::bucketOf($row['id'])][] = $row;
        }

        $buckets = [];
        $send = [];
        $count = 0;

        foreach ($grouped as $bucket => $rows) {
            $buckets[$bucket] = self::hash($rows);
            $count += count($rows);

            if (($have[$bucket] ?? $have[(string) $bucket] ?? null) !== $buckets[$bucket]) {
                $send[$bucket] = $rows;
            }
        }

        ksort($buckets);
        ksort($send);

        return ['buckets' => $buckets, 'rows' => $send, 'count' => $count];
    }

    /** بصمةُ دلوٍ كما يُرسَل — أيُّ حرفٍ يتغيّر فيه يغيّرها */
    public static function hash(array $rows): string
    {
        return substr(hash('xxh128', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
    }
}
