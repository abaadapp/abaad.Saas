<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ كتالوجُ الصندوق على الجهاز: ما تغيّر وحده ═══
 *
 * صفحةُ الصندوق تحمل أصنافَ المتجر كلَّها في كلّ فتح (`Demo::tillProducts`):
 * عند عشرة آلاف صنف 4.5MB يبنيها الخادمُ في 810ms و58MB من ذاكرته، ولم يتغيّر
 * منها شيء منذ أمس. فيحفظ الجهازُ الكتالوجَ ويسأل هنا عمّا تغيّر منذ ما حفظ.
 *
 * ═══ ما يُقارَن — المحتوى لا التاريخ ═══
 *
 * الصفُّ يُبنى من سبعة مصادر: عمودُ الصنف، واسمُ قسمه، ومقاساتُه، وروابطُ
 * إضافاته، وكونُه ذا وصفة، وإضافاتُ المتجر، وإعدادُ ضريبته — ومعها لغةُ الشاشة
 * والكودُ الذي يبني. وبعضُ ما يكتبها `DB::table` لا يمسّ `updated_at` (روابطُ
 * الإضافات كلُّها)، وجدولُ القسم والإعداد لا يلمسان صفَّ الصنف. فلا يُسأل «ما
 * تغيّر منذ وقت كذا»: يُقرأ ما في القاعدة الآن ويُقارَن بما عند الجهاز.
 *
 * ‏ثلاثُ بصماتٍ، من الأرخص إلى الأصدق:
 *
 *   - **المؤشّرات (`markers`)** — عددُ الصفوف وأكبرُ معرّفٍ وآخرُ تعديلٍ في كلّ
 *     جدول. 1.3ms عند عشرة آلاف، ولا تقرأ صفًّا. إن لم تتحرّك منذ آخر فحصٍ وكان
 *     الفحصُ قريبًا (`CHECK_EVERY`) فلا شيء تغيّر يُعرف به — ويُجاب «كما هو».
 *     وهي لا تُعتمد وحدها: كتابةٌ بلا `updated_at` لا تحرّكها، وتعديلان في
 *     الثانية نفسِها يتركان «آخرَ تعديلٍ» كما هو، ومعاملةٌ ثُبّتت متأخّرةً قد
 *     تختبئ خلف أحدث منها. فلا يُوثق بها في ثانيتَي آخرِ كتابة (`SETTLED`)، ولا
 *     أكثرَ من `CHECK_EVERY` بعد آخر قراءةٍ للمحتوى.
 *   - **بصمةُ المُدخلات (`ih`)** لكلّ سلّة — من الصفوف الخامّ في الجداول الخمسة،
 *     كلِّ عمودٍ فيها إلّا الكميّةَ والطوابع: عمودٌ يُضاف غدًا يدخلها وحده.
 *     28ms عند عشرة آلاف، ولا تبني صفًّا. سلّةٌ تغيّرت مُدخلاتُها تُبنى وحدها.
 *   - **بصمةُ الصفّ المبنيّ (`oh`)** لكلّ سلّة — من الصفّ كما يُرسل. بها يُقرَّر
 *     ما يُرسَل: سلّةٌ تغيّرت مُدخلاتُها وصفُّها هو هو (عمودٌ للموقع وحده) تُرسل
 *     بصمتُها لا أصنافُها.
 *
 * ‏والسياقُ (`context`) ما يمسّ الأصنافَ كلَّها معًا: ضريبةُ المتجر، وإضافاتُه،
 * واللغة، والإصدارُ المنشور. إن تغيّر — أو لم يُعرف بأيّ سياقٍ بُنيت نسخةُ
 * الجهاز — يُبنى الكلُّ ويُقارَن الصفُّ المبنيّ نفسُه (`deep`): لا يُفترض أنّ
 * الأصناف التي لم تتغيّر مُدخلاتُها لم يتغيّر صفُّها.
 *
 * ═══ ولا يُفوَّت شيء ═══
 *
 *   - **الترتيب:** المؤشّراتُ والسياقُ أوّلًا، ثمّ المُدخلات، ثمّ الصفوف. فما
 *     يصل الجهازَ ليس أقدمَ من البصمة التي يُعطاها معه: كتابةٌ ثُبّتت بينهما
 *     تظهر في السؤال التالي فرقًا، ولا تختبئ خلف بصمةٍ أحدث من صفّها.
 *   - **الرمز (`t`)** يقول ما فُحص ومتى — بساعة الخادم، موقَّعًا بمفتاح التطبيق،
 *     ومربوطًا ببصمة نسخة الجهاز (`v`). نسخةٌ لا تطابق رمزَها تُفحص كلُّها.
 *   - **الجوابُ القديم** يحمل بصمةَ ما سُئل عنه (`base`): لا يُطبَّق على غيره.
 *
 * والبصماتُ لا تفتح بابًا: المتجرُ والفرعُ واللغةُ من الجلسة وحدها، وما يرسله
 * الجهازُ يُقارَن به ولا يُقرأ به شيء. والكميّةُ ليست هنا: لها تغذيتُها
 * (`TillStock`)، فبيعةٌ لا تُعيد صفَّ صنفٍ لم يتغيّر فيه إلّا رصيدُه.
 */
final class TillCatalog
{
    /**
     * ‏‏بنيةُ الكتالوج — يُزاد حين يتغيّر شكلُ ما يُحفظ في الجهاز.
     *
     * والكودُ الذي يبني الصفَّ يُحرس بالإصدار المنشور (`release`) لا بهذا الرقم
     * وحده: تعديلُ قاعدةٍ في `Demo::productRows` بلا زيادته يُفحص بعد النشر.
     */
    public const FORMAT = 1;

    /** ‏‏سلالٌ لا تزيد: 256 × 16 حرفًا × بصمتان = 8KB يُعيدها الجهازُ مع سؤاله */
    public const MAX_BUCKETS = 256;

    /** ‏أصنافُ السلّة الواحدة تقريبًا — صنفٌ تغيّر يُرسَل معه نحوُ هذا العدد */
    private const PER_BUCKET = 32;

    /** ‏طولُ بصمة السلّة — 64 بتًّا */
    public const HASH = 16;

    /** ‏‏أطولُ ما يُوثق فيه بالمؤشّرات وحدها، بثواني الخادم — ثمّ تُقرأ المُدخلات */
    public const CHECK_EVERY = 300;

    /**
     * ‏‏وكم بعد آخر كتابةٍ يجب أن يكون الفحصُ ليُوثق بمؤشّراته — بالثواني.
     *
     * `updated_at` بالثانية: تعديلان في الثانية نفسِها لا يحرّكان «آخرَ تعديل»،
     * وجهازٌ فُحص بينهما لا يرى الثاني. فإن كان الفحصُ في ثانيتَي آخرِ كتابةٍ
     * لا يُعفي شيئًا، وتُقرأ المُدخلاتُ في السؤال التالي. ولا يبقى خلف المؤشّرات
     * إلّا معاملةٌ ثُبّتت بعد أكثرَ من ثانيتين من كتابتها — وتلك يراها `CHECK_EVERY`.
     */
    public const SETTLED = 2;

    /** ‏ما ليس من الكتالوج في صفّ الصنف: رصيدُه (لتغذية المخزون) وطوابعُه */
    private const NOT_INPUT = ['quantity' => 1, 'created_at' => 1, 'updated_at' => 1];

    /** ‏وما ليس منه في الصفّ المبنيّ — الكميّةُ وحالتُها تأتيان من `TillStock` */
    private const NOT_OUTPUT = ['qty' => 1, 'stock_status' => 1];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * ‏المتجرُ والفرعُ ولغةُ الشاشة وبنيةُ الكتالوج — مفتاحُ نسخة الجهاز.
     *
     * يُقارَن بالجلسة ولا يُقرأ به: نسخةٌ لمفتاحٍ آخر لا تُقرأ، وسؤالٌ بمفتاحٍ
     * غيرِ مفتاح الجلسة يُردّ (409).
     */
    public static function scope(int $businessId, ?int $branchId, string $locale): string
    {
        return $businessId.':'.($branchId ?? 0).':'.$locale.':'.self::FORMAT;
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
     * ‏‏الإصدارُ المنشور — ما تغيّر به الكودُ الذي يبني الصفّ.
     *
     * النشرُ يكتب الإعدادَ المخبّأ في كلّ مرّة (`config:cache` في `scripts/deploy.sh`)،
     * فوقتُه يتغيّر مع كلّ نشر. وبلا إعدادٍ مخبّأ (محلّيًّا وفي الاختبارات) فارغ.
     */
    public static function release(): string
    {
        $pinned = config('pos.catalog_release');
        if ($pinned !== null) {
            return (string) $pinned;
        }

        $path = app()->getCachedConfigPath();

        return is_file($path) ? (string) filemtime($path) : '';
    }

    /**
     * ‏‏المؤشّراتُ الخفيفة — تتحرّك مع كلّ إضافةٍ وحذفٍ وتعديلٍ يكتب `updated_at`.
     *
     * سؤالٌ واحد لا يقرأ صفًّا: أعدادٌ وأكبرُ قيمٍ في الجداول الخمسة. وما لا
     * تراه (كتابةٌ بلا طابع، معاملةٌ ثُبّتت متأخّرة) تراه بصمةُ المُدخلات
     * بعد `CHECK_EVERY` على الأكثر.
     */
    /** @return array{m: string, latest: int} البصمةُ، وآخرُ تعديلٍ في الجداول كلّها (ثواني الخادم) */
    public static function markers(int $businessId): array
    {
        $parts = [];
        $bindings = [];
        foreach (['products' => true, 'product_variants' => true, 'product_addons' => false, 'categories' => false, 'recipe_items' => false] as $table => $soft) {
            foreach (['c' => 'count(*)', 'i' => 'max(id)', 'u' => 'max(updated_at)', ...($soft ? ['d' => 'count(deleted_at)'] : [])] as $k => $agg) {
                $parts[] = "(select {$agg} from {$table} where business_id = ?) as {$table}__{$k}";
                $bindings[] = $businessId;
            }
        }

        $row = (array) DB::selectOne('select '.implode(', ', $parts), $bindings);
        $latest = 0;
        foreach ($row as $key => $value) {
            if (str_ends_with($key, '__u') && $value !== null) {
                $latest = max($latest, Carbon::parse($value)->getTimestamp());
            }
        }

        return ['m' => md5(implode('|', array_map(fn ($v) => (string) $v, $row))), 'latest' => $latest];
    }

    /**
     * ‏‏السياق — ما يمسّ صفوفَ الأصناف كلِّها معًا.
     *
     *   - **ضريبةُ المتجر** كما تُحسب (`Vat::enabled` و`Vat::rate`) — لا الإعدادُ
     *     الخامّ: غيابُه يُقرأ بتاريخ المتجر.
     *   - **إضافاتُ المتجر** صفًّا صفًّا: حالُها وقناتُها ومداها تقرّر `addon_ids`
     *     لكلّ صنف (`ProductAddons::for`).
     *   - **لغةُ الشاشة**، و**بنيةُ الكتالوج**، و**الإصدارُ المنشور**.
     */
    public static function context(int $businessId): string
    {
        $addons = [];
        foreach (DB::table('addons')->where('business_id', $businessId)->orderBy('id')->get() as $a) {
            $addons[] = array_diff_key((array) $a, self::NOT_INPUT);
        }

        return md5(json_encode([
            self::FORMAT,
            app()->getLocale(),
            self::release(),
            Vat::enabled($businessId),
            Vat::rate($businessId),
            $addons,
        ], self::JSON));
    }

    /**
     * ‏‏بصمةُ المُدخلات لكلّ سلّة — من الجداول خامًّا، بلا بناء صفّ.
     *
     * لكلّ صنفٍ غيرِ محذوف: أعمدتُه كلُّها إلّا الكميّةَ والطوابع، واسمُ قسمه،
     * ومقاساتُه كلُّها (والموقوفُ والمحذوفُ منها: تغيُّرُ حاله تغيُّرٌ)، وروابطُ
     * إضافاته بترتيبها، وكونُه ذا وصفة.
     *
     * @return array{b: int, ih: list<string>, count: int}
     */
    public static function inputs(int $businessId): array
    {
        $cats = DB::table('categories')
            ->whereIn('id', DB::table('products')->where('business_id', $businessId)->whereNotNull('category_id')->select('category_id'))
            ->pluck('name', 'id')->all();

        $variants = [];
        foreach (DB::table('product_variants')->where('business_id', $businessId)->orderBy('product_id')->orderBy('sort_order')->orderBy('id')->get() as $v) {
            $variants[$v->product_id][] = array_diff_key((array) $v, self::NOT_INPUT);
        }

        $links = [];
        foreach (DB::table('product_addons')->where('business_id', $businessId)->orderBy('sort_order')->orderBy('id')->get(['product_id', 'addon_id', 'sort_order']) as $l) {
            $links[$l->product_id][] = [$l->addon_id, $l->sort_order];
        }

        $recipes = array_flip(DB::table('recipe_items')->where('business_id', $businessId)->distinct()->pluck('product_id')->all());

        $products = DB::table('products')->where('business_id', $businessId)->whereNull('deleted_at')->orderBy('id')->get();
        $b = self::bucketsFor($products->count());
        $parts = array_fill(0, $b, '');

        foreach ($products as $p) {
            $id = (int) $p->id;
            $parts[$id % $b] .= json_encode([
                array_diff_key((array) $p, self::NOT_INPUT),
                $p->category_id !== null ? ($cats[$p->category_id] ?? null) : null,
                $variants[$id] ?? [],
                $links[$id] ?? [],
                isset($recipes[$id]),
            ], self::JSON)."\n";
        }

        return ['b' => $b, 'ih' => array_map(fn (string $s) => self::hash($s), $parts), 'count' => $products->count()];
    }

    /**
     * ‏‏صفوفُ الصندوق لبعض السلال — أو كلِّها — بالدالّة التي تبني صفحته.
     *
     * @param  list<int>|null  $buckets
     * @return list<array<string, mixed>>
     */
    public static function rows(?int $branchId, int $b, ?array $buckets): array
    {
        if ($buckets === null) {
            return Demo::tillProducts($branchId);
        }

        if ($buckets === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $buckets));

        return Demo::tillProducts($branchId, fn ($q) => $q->whereRaw('(products.id % '.(int) $b.') in ('.$in.')'));
    }

    /**
     * ‏‏بصمةُ الصفّ المبنيّ لكلّ سلّة — بلا الكميّة وحالتها.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string> سلّةٌ ← بصمتُها، للسلال التي في `$rows` أو في `$buckets`
     */
    public static function outputs(array $rows, int $b, ?array $buckets = null): array
    {
        $parts = [];
        foreach ($buckets ?? range(0, $b - 1) as $i) {
            $parts[$i] = '';
        }

        foreach ($rows as $row) {
            $parts[$row['id'] % $b] .= json_encode(array_diff_key($row, self::NOT_OUTPUT), self::JSON)."\n";
        }

        return array_map(fn (string $s) => self::hash($s), $parts);
    }

    /** ‏‏بصمةُ نسخةٍ كاملة — من سلالها وبصمتَيها */
    public static function digest(int $b, array $ih, array $oh): string
    {
        return md5($b.'|'.implode('', $ih).'|'.implode('', $oh));
    }

    /**
     * ‏‏الجوابُ على ما يعرفه الجهاز.
     *
     *   - **`same`:** ما عنده هو ما في القاعدة. ومعه رمزٌ (`t`) يُعيده.
     *   - **سلالٌ (`base` + `buckets`):** ما تغيّرت بصمتُه من سلاله، وأصنافُ ما
     *     تغيّر صفُّه منها كاملةً (`rows`) — فيُعرف ما حُذف منها كما يُعرف ما
     *     تغيّر. و`base` بصمةُ ما سأل به: لا يُطبّقها جهازٌ تغيّر ما عنده بعده.
     *   - **`full`:** ما لم يُعرف أساسُه — الكتالوجُ كلُّه وبصماتُه وعددُه.
     *
     * @param  array{t?: mixed, b?: mixed, ih?: mixed, oh?: mixed, deep?: mixed}  $known
     */
    public static function answer(int $businessId, ?int $branchId, string $scope, array $known): array
    {
        // ‏‏المؤشّراتُ والسياقُ قبل المُدخلات، والمُدخلاتُ قبل الصفوف — انظر «ولا يُفوَّت شيء»
        $marks = self::markers($businessId);
        $context = self::context($businessId);
        $now = now()->getTimestamp();

        $theirs = self::theirs($known);
        $digest = $theirs ? self::digest($theirs['b'], $theirs['ih'], $theirs['oh']) : null;
        $token = self::unseal($known['t'] ?? null);
        // ‏‏الرمزُ يصف هذه النسخة بعينها، في هذا المفتاح وهذا السياق
        $vouched = $token !== null && $digest !== null
            && hash_equals($token['s'], $scope) && hash_equals($token['v'], $digest) && hash_equals($token['k'], $context);
        $deep = ($known['deep'] ?? false) === true;

        if ($vouched && ! $deep && hash_equals($token['m'], $marks['m'])
            && $now - $token['c'] >= 0 && $now - $token['c'] < self::CHECK_EVERY
            && $token['c'] - $marks['latest'] >= self::SETTLED) {
            return ['same' => true, 'v' => $digest, 't' => $known['t'], 'scope' => $scope];
        }

        $in = self::inputs($businessId);
        $b = $in['b'];

        if ($theirs === null || $theirs['b'] !== $b) {
            return self::full($branchId, $scope, $in, $marks, $context, $now);
        }

        /*
         * ‏‏لا يُعرف بأيّ سياقٍ بُنيت نسخةُ الجهاز (رمزٌ غائبٌ أو لا يطابق، أو سياقٌ
         * تغيّر)، أو طُلب ذلك: يُبنى الكلُّ ويُقارَن الصفُّ المبنيّ نفسُه.
         */
        if (! $vouched || $deep) {
            $rows = self::rows($branchId, $b, null);
            $oh = self::outputs($rows, $b);
            $send = array_keys(array_filter($oh, fn ($h, $i) => $h !== $theirs['oh'][$i], ARRAY_FILTER_USE_BOTH));
        } else {
            $touched = array_keys(array_filter($in['ih'], fn ($h, $i) => $h !== $theirs['ih'][$i], ARRAY_FILTER_USE_BOTH));
            $rows = self::rows($branchId, $b, $touched);
            $oh = self::outputs($rows, $b, $touched) + $theirs['oh'];
            ksort($oh);
            $send = array_values(array_filter($touched, fn ($i) => $oh[$i] !== $theirs['oh'][$i]));
        }

        $ih = $in['ih'];
        $v = self::digest($b, $ih, $oh);
        $t = self::seal(['s' => $scope, 'v' => $v, 'k' => $context, 'm' => $marks['m'], 'c' => $now]);

        if ($v === $digest) {
            return ['same' => true, 'v' => $v, 't' => $t, 'scope' => $scope];
        }

        $buckets = [];
        foreach (range(0, $b - 1) as $i) {
            if ($ih[$i] !== $theirs['ih'][$i] || $oh[$i] !== $theirs['oh'][$i]) {
                $buckets[] = [$i, $ih[$i], $oh[$i]];
            }
        }

        $wanted = array_flip($send);
        $sent = array_values(array_filter($rows, fn ($r) => isset($wanted[$r['id'] % $b])));

        return [
            'base' => $digest,
            'v' => $v,
            'b' => $b,
            'buckets' => $buckets,
            'rows' => array_values($send),
            'products' => $sent,
            't' => $t,
            'scope' => $scope,
        ];
    }

    /** ‏‏الكتالوجُ كلُّه — وبصماتُه وعددُه، فلا تُعدّ نسخةٌ ناقصةٌ كاملة */
    private static function full(?int $branchId, string $scope, array $in, array $marks, string $context, int $now): array
    {
        $b = $in['b'];
        $rows = self::rows($branchId, $b, null);
        $oh = self::outputs($rows, $b);
        $v = self::digest($b, $in['ih'], $oh);

        return [
            'full' => true,
            'v' => $v,
            'b' => $b,
            'ih' => implode('', $in['ih']),
            'oh' => implode('', $oh),
            'count' => count($rows),
            'products' => $rows,
            't' => self::seal(['s' => $scope, 'v' => $v, 'k' => $context, 'm' => $marks['m'], 'c' => $now]),
            'scope' => $scope,
        ];
    }

    /**
     * ‏‏بصماتُ الجهاز كما أُعطيها — أو `null` إن لم تكن بالعدد والشكل.
     *
     * @return array{b: int, ih: list<string>, oh: list<string>}|null
     */
    private static function theirs(array $known): ?array
    {
        $b = $known['b'] ?? null;
        if (! is_int($b) || $b < 1 || $b > self::MAX_BUCKETS || ($b & ($b - 1)) !== 0) {
            return null;
        }

        $split = function (mixed $h) use ($b): ?array {
            if (! is_string($h) || strlen($h) !== $b * self::HASH || preg_match('/[^0-9a-f]/', $h)) {
                return null;
            }

            return str_split($h, self::HASH);
        };
        $ih = $split($known['ih'] ?? null);
        $oh = $split($known['oh'] ?? null);

        return $ih && $oh ? ['b' => $b, 'ih' => $ih, 'oh' => $oh] : null;
    }

    private static function hash(string $s): string
    {
        return substr(hash('xxh128', $s), 0, self::HASH);
    }

    /**
     * ‏‏الرمز — ما فُحص ومتى، موقَّعًا. لا يفتح شيئًا: يُعفي من فحصٍ لا غير، ومن
     * يُزوّره لا يُعفي إلّا نفسَه — ولا يزوّره بلا مفتاح التطبيق.
     *
     * @param  array{s: string, v: string, k: string, m: string, c: int}  $claims
     */
    private static function seal(array $claims): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($claims, self::JSON)), '+/', '-_'), '=');

        return $body.'.'.substr(hash_hmac('sha256', 'till-catalog|'.$body, (string) config('app.key')), 0, 32);
    }

    /** @return array{s: string, v: string, k: string, m: string, c: int}|null */
    private static function unseal(mixed $token): ?array
    {
        if (! is_string($token) || strlen($token) > 1024 || substr_count($token, '.') !== 1) {
            return null;
        }

        [$body, $mac] = explode('.', $token);
        if (! hash_equals(substr(hash_hmac('sha256', 'till-catalog|'.$body, (string) config('app.key')), 0, 32), $mac)) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        foreach (['s', 'v', 'k', 'm'] as $key) {
            if (! is_string($claims[$key] ?? null)) {
                return null;
            }
        }

        return is_int($claims['c'] ?? null) ? $claims : null;
    }
}
