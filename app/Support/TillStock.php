<?php

namespace App\Support;

use App\Models\Product;

/**
 * ═══ مخزونُ الصندوق: ما تغيّر وحده ═══
 *
 * يسأل الصندوقُ عن الكميّات كلّ عشرين ثانية (`PosController::stockFeed`). وكان
 * الجوابُ الأصنافَ كلَّها في كلّ مرّة، تغيّر شيءٌ أو لم يتغيّر: عند عشرة آلاف
 * صنف 709KB (27KB مضغوطة) لكلّ صندوق، ثلاثَ مرّاتٍ في الدقيقة، والصندوقُ يعيد
 * بناءَ كلّ صنفٍ عنده ليجد أنّ لا شيء تغيّر.
 *
 * ═══ ولماذا لا «ما تغيّر منذ وقت كذا» ═══
 *
 * لأنّ الكميّة لا يكتبها بابٌ واحد: البيعُ والإرجاعُ والإلغاءُ والشراءُ
 * والتسويةُ والتحويلُ بين الفروع والجردُ وتعديلُ الصنف — وبعضُها يكتب بـ
 * `DB::table` فلا يمسّ `updated_at`. ورصيدُ الفرع في جدولٍ آخر. ورقمُ إصدارٍ
 * يُزاد عند كلّ كتابة يُنسى في باب، وتسلسلٌ يُقرأ بين معاملتين يفوته ما
 * كُتب قبله وثُبّت بعده. وكلُّ ذلك يُفقد تحديثًا بصمت.
 *
 * ═══ فالجوابُ يُبنى من الكميّات نفسِها ═══
 *
 * يُحسب المخزونُ كما يُحسب اليوم (`rows`، المصدرُ نفسُه لشاشة الصندوق حين
 * تُفتح)، ثمّ يُقسَم الأصنافُ سلالًا بمعرّفاتها، ولكلّ سلّةٍ بصمةٌ من كميّاتها.
 * الصندوقُ يحفظ البصماتِ التي أُعطيها ويُعيدها مع سؤاله؛ فما اختلفت بصمتُه
 * تُرسل سلّتُه كاملةً (فيُعرف ما حُذف منها كما يُعرف ما تغيّر)، وما لم يختلف
 * لا يُرسل. ولا يُقرأ وقتٌ ولا رقمُ إصدار: ما في القاعدة الآن هو ما يُقارَن.
 *
 *   - **البصمةُ الكاملة (`v`)** من الكميّات كلّها لا من بصمات السلال. صندوقٌ
 *     بصمتُه هي بصمةُ الخادم عنده ما عند الخادم بعينه.
 *   - **وما لا يُطابق** (سلالٌ بعددٍ آخر، أو بصماتٌ مشوّهة، أو سؤالٌ بلا بصمة)
 *     يُجاب بالقائمة كاملة. والصندوقُ يطرح ما عنده ويأخذها.
 *
 * والبصماتُ لا تفتح بابًا: المتجرُ والفرعُ من الجلسة وحدها، وما يرسله
 * الصندوقُ يُقارَن به ولا يُقرأ به شيء.
 */
final class TillStock
{
    /** ‏‏سلالٌ لا تزيد: 256 بصمةً × 16 حرفًا = 4KB يُعيدها الصندوقُ مع كلّ سؤال */
    public const MAX_BUCKETS = 256;

    /** ‏أصنافُ السلّة الواحدة تقريبًا — صنفٌ تغيّر يُرسل معه نحوُ هذا العدد */
    private const PER_BUCKET = 32;

    /** ‏طولُ بصمة السلّة — 64 بتًّا: اختلافُ سلّةٍ يُخفيه تطابقُ بصمتين احتمالُه 2^-64 */
    public const HASH = 16;

    /** ‏المتجرُ والفرع اللذان بُنيت لهما شاشةُ الصندوق — يُقارَن بالجلسة، ولا يُقرأ به */
    public static function scope(int $businessId, ?int $branchId): string
    {
        return $businessId.':'.($branchId ?? 0);
    }

    /**
     * ‏الكميّاتُ كما يقرؤها الصندوقُ حين يُفتح — صنفٌ صنف، مرتّبةً بالمعرّف.
     *
     * المصدرُ هو مصدرُ `Demo::productRows`: رصيدُ الفرع (`Stock::availabilityResolver`)
     * وحالتُه (`Product::statusFor`). ويُقرأ الصفُّ خامًا لا نموذجًا: عشرةُ آلاف
     * نموذجٍ تُبنى لتُقرأ منها ثلاثةُ أعمدة.
     *
     * @return list<array{id: int, qty: int, stock_status: string}>
     */
    public static function rows(int $businessId, ?int $branchId): array
    {
        $available = Stock::availabilityResolver($businessId, $branchId);
        $rows = [];

        foreach (Product::where('business_id', $businessId)->orderBy('id')->toBase()->get(['id', 'quantity', 'alert_qty', 'tracks_stock']) as $p) {
            $qty = $available((int) $p->id, (int) $p->quantity);
            // ‏كما يقرؤه `Product::tracksStock`: الفارغُ يُعدّ
            $tracks = $p->tracks_stock === null ? true : (bool) $p->tracks_stock;
            $rows[] = [
                'id' => (int) $p->id,
                'qty' => $qty,
                'stock_status' => $tracks ? Product::statusFor($qty, (int) $p->alert_qty) : Product::UNTRACKED,
            ];
        }

        return $rows;
    }

    /** ‏عددُ السلال لهذا العدد من الأصناف — قوّةٌ لاثنين، من 1 إلى `MAX_BUCKETS` */
    public static function bucketsFor(int $count): int
    {
        $b = 1;
        while ($b < self::MAX_BUCKETS && $b * self::PER_BUCKET < $count) {
            $b *= 2;
        }

        return $b;
    }

    /**
     * ‏‏السلالُ وبصماتُها، والبصمةُ الكاملة.
     *
     * @param  list<array{id: int, qty: int, stock_status: string}>  $rows
     * @return array{b: int, h: list<string>, v: string, of: list<list<int>>}
     */
    public static function index(array $rows): array
    {
        $b = self::bucketsFor(count($rows));
        $parts = array_fill(0, $b, '');
        $of = array_fill(0, $b, []);

        foreach ($rows as $k => $r) {
            $i = $r['id'] % $b;
            $parts[$i] .= $r['id'].':'.$r['qty'].':'.$r['stock_status'].';';
            $of[$i][] = $k;
        }

        return [
            'b' => $b,
            'h' => array_map(fn (string $s) => substr(md5($s), 0, self::HASH), $parts),
            'v' => md5($b.'|'.implode('', $parts)),
            'of' => $of,
        ];
    }

    /**
     * ‏‏الجوابُ على ما يعرفه الصندوق.
     *
     *   - **`same`:** بصمتُه بصمةُ الخادم — لا شيء يُرسل.
     *   - **سلالٌ (`base` + `buckets`):** ما اختلفت بصمتُه من سلاله، كاملةً.
     *     و`base` بصمتُه هو: لا يُطبّقها صندوقٌ تغيّر ما عنده بعد أن سأل.
     *   - **`full`:** ما لم يُعرف أساسُه — القائمةُ كلُّها وبصماتُها.
     *
     * @param  list<array{id: int, qty: int, stock_status: string}>  $rows
     * @param  array{v?: mixed, b?: mixed, h?: mixed}  $known
     */
    public static function answer(array $rows, array $known, string $scope): array
    {
        $index = self::index($rows);
        $head = ['v' => $index['v'], 'b' => $index['b'], 'scope' => $scope];
        $theirs = is_string($known['v'] ?? null) ? $known['v'] : null;

        if ($theirs !== null && hash_equals($index['v'], $theirs)) {
            return $head + ['same' => true];
        }

        $full = $head + ['full' => true, 'h' => implode('', $index['h']), 'products' => $rows];
        $hashes = $theirs !== null && (int) ($known['b'] ?? 0) === $index['b']
            ? self::hashes($known['h'] ?? null, $index['b'])
            : null;
        if ($hashes === null) {
            return $full;
        }

        $changed = [];
        foreach ($index['h'] as $i => $hash) {
            if ($hashes[$i] !== $hash) {
                $changed[] = $i;
            }
        }
        // ‏البصمةُ الكاملة اختلفت وبصماتُ السلال كلُّها تطابقت: ما عنده ليس ما أُعطيه — يأخذ الكلّ
        if ($changed === []) {
            return $full;
        }

        $sent = [];
        $buckets = [];
        foreach ($changed as $i) {
            $buckets[] = [$i, $index['h'][$i]];
            foreach ($index['of'][$i] as $k) {
                $sent[] = $rows[$k];
            }
        }
        usort($sent, fn ($a, $z) => $a['id'] <=> $z['id']);

        return $head + ['base' => $theirs, 'buckets' => $buckets, 'products' => $sent];
    }

    /** ‏‏بصماتُ الصندوق كما أُعطيها، أو `null` إن لم تكن بالعدد والشكل */
    private static function hashes(mixed $h, int $b): ?array
    {
        if (! is_string($h) || strlen($h) !== $b * self::HASH || preg_match('/[^0-9a-f]/', $h)) {
            return null;
        }

        return str_split($h, self::HASH);
    }
}
