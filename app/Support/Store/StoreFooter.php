<?php

namespace App\Support\Store;

use Illuminate\Validation\ValidationException;

/**
 * تذييلُ متجر RIBBON كما يرتّبه صاحبُه — أعمدتُه وعناوينُها وسطرُه الأخير.
 *
 * ═══ ما يُرتَّب وما يبقى ═══
 *
 * الشعارُ أوّلًا دائمًا: هو توقيعُ المتجر، ويُختار تحته إظهارُ النبذة وزرَّي
 * التواصل. ثمّ ثلاثةُ أعمدةٍ تُرتَّب وتُسمّى وتُخفى ولا تُحذف — «الموقع»
 * (روابطُ القائمة، ويُختار منها ما يظهر)، و«تسوّق» (كلُّ المنتجات، والفئات،
 * ومجموعاتٌ يختارها)، و«تواصل معنا» (الهاتف وواتساب والبريد والعنوان
 * والساعات، كلٌّ بمفتاحه). ويُضاف إليها عمودا نصٍّ قصيرٍ لا أكثر.
 *
 * ═══ وبياناتُ التواصل لا تُكتب هنا ثانيةً ═══
 *
 * الهاتفُ والبريدُ والعنوانُ من بيانات النشاط، وواتساب والساعاتُ من
 * إعدادات المتجر (`MerchantData::identity` و`StorePage::hours`). فالتذييلُ
 * يختار ما يُظهر منها — ولا يحمل نسخةً تفترق عن الأصل.
 *
 * ═══ والفراغُ هو التذييلُ كما كان ═══
 *
 * مفتاحٌ لم يُكتب (`store_footer`) يُرسم التذييلَ القائمَ حرفًا بحرف، وما
 * يساوي الأصليَّ يُحفظ فراغًا — فلا يتبدّل متجرٌ لم يرتّب تذييلَه. والمفتاحُ
 * في عقد المسوّدة والنشر (`StoreContent::VERSIONED`): يُعايَن ثمّ يُنشر.
 */
final class StoreFooter
{
    public const KEY = 'store_footer';

    /** الأعمدةُ الثابتة — تُرتَّب وتُخفى ولا تُحذف */
    public const COLUMNS = ['pages', 'shop', 'contact'];

    /** سطورُ «تواصل معنا» — كلٌّ بمفتاحه */
    public const CONTACT = ['phone', 'whatsapp', 'email', 'address', 'hours'];

    /** أكثرُ أعمدة النصّ المضافة */
    public const MAX_TEXT = 2;

    public const TITLE_MAX = 40;

    public const BODY_MAX = 400;

    public const COPYRIGHT_MAX = 80;

    /** أطولُ ما يُقبل من النصّ المحفوظ — حمايةٌ قبل أن يُفكّ */
    public const RAW_MAX = 8000;

    private const TEXT_ID = '/^[a-z0-9]{1,16}$/';

    /** مفتاحُ رابطٍ في عمود «الموقع» — صفحةٌ أساسيّة أو رابطٌ من القائمة */
    private const LINK_KEY = '/^(?:home|shop|about|contact|link-[a-z0-9]{1,16})$/';

    /* ═══════════ القراءة ═══════════ */

    /**
     * التذييلُ كما رتّبه — أو الأصليّ.
     *
     * @return array<string, mixed>
     */
    public static function settings(string $raw): array
    {
        $cfg = self::defaults();
        $in = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($in) || array_is_list($in)) {
            return $cfg;
        }

        foreach (['about', 'social', 'line', 'copyright'] as $k) {
            if (array_key_exists($k, $in)) {
                $cfg[$k] = (bool) $in[$k];
            }
        }

        foreach (['copyright_ar', 'copyright_en'] as $k) {
            $cfg[$k] = self::text($in[$k] ?? '');
        }

        $columns = [];
        $seen = [];
        $texts = 0;

        foreach (is_array($in['columns'] ?? null) ? array_values($in['columns']) : [] as $c) {
            $col = is_array($c) ? self::readColumn($c) : null;

            if ($col === null) {
                continue;
            }

            $id = $col['key'] === 'text' ? 'text:'.$col['id'] : $col['key'];

            if (isset($seen[$id]) || ($col['key'] === 'text' && ++$texts > self::MAX_TEXT)) {
                continue;
            }

            $seen[$id] = true;
            $columns[] = $col;
        }

        // والعمودُ الثابتُ الغائبُ يُلحق آخرَها كما كان
        foreach (self::COLUMNS as $key) {
            if (! isset($seen[$key])) {
                $columns[] = self::column($key);
            }
        }

        $cfg['columns'] = $columns;

        return $cfg;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'about' => true,
            'social' => true,
            'columns' => array_map(fn (string $key) => self::column($key), self::COLUMNS),
            'line' => true,
            'copyright' => true,
            'copyright_ar' => '',
            'copyright_en' => '',
        ];
    }

    /** @return array<string, mixed> */
    private static function column(string $key): array
    {
        $base = ['key' => $key, 'visible' => true, 'title_ar' => '', 'title_en' => ''];

        return $base + match ($key) {
            'pages' => ['hide' => []],
            'shop' => ['all' => true, 'categories' => true, 'collections' => []],
            'contact' => array_fill_keys(self::CONTACT, true),
            default => [],
        };
    }

    /** عمودٌ محفوظ ← عمودٌ بحقول نوعه — أو `null` لما لا يُقرأ */
    private static function readColumn(array $c): ?array
    {
        $key = is_string($c['key'] ?? null) ? $c['key'] : '';

        if ($key === 'text') {
            $id = is_string($c['id'] ?? null) ? $c['id'] : '';

            return preg_match(self::TEXT_ID, $id) !== 1 ? null : [
                'key' => 'text', 'id' => $id, 'visible' => (bool) ($c['visible'] ?? true),
                'title_ar' => self::text($c['title_ar'] ?? ''), 'title_en' => self::text($c['title_en'] ?? ''),
                'body_ar' => self::text($c['body_ar'] ?? ''), 'body_en' => self::text($c['body_en'] ?? ''),
            ];
        }

        if (! in_array($key, self::COLUMNS, true)) {
            return null;
        }

        $col = self::column($key);
        $col['visible'] = (bool) ($c['visible'] ?? true);
        $col['title_ar'] = self::text($c['title_ar'] ?? '');
        $col['title_en'] = self::text($c['title_en'] ?? '');

        foreach ($col as $k => $v) {
            if (is_bool($v) && $k !== 'visible' && array_key_exists($k, $c)) {
                $col[$k] = (bool) $c[$k];
            }
        }

        if ($key === 'pages') {
            $col['hide'] = self::strings($c['hide'] ?? null, self::LINK_KEY);
        }

        if ($key === 'shop') {
            $col['collections'] = self::strings($c['collections'] ?? null, StoreCollections::SLUG);
        }

        return $col;
    }

    /**
     * التذييلُ كما يُرسم بلغة الصفحة.
     *
     * وعمودٌ لا شيءَ فيه لا يُرسم — إلّا «تواصل معنا» كما كان دائمًا. وعمودُ
     * النصّ بلغته وحدها، ولا يقع على الأخرى.
     *
     * @param  array<string, mixed>  $cfg  `settings`
     * @param  list<array<string, mixed>>  $nav  روابطُ القائمة — `StoreNav::links`
     * @param  list<array{id: int, name: string}>  $categories  فئاتُ التذييل
     * @param  list<array<string, mixed>>  $collections  المجموعاتُ الظاهرة
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر
     * @return array{about: bool, social: bool, columns: list<array<string, mixed>>, line: bool, copyright: string|null}
     */
    public static function view(array $cfg, string $lang, array $t, string $base, array $nav, array $categories, array $collections, string $brand): array
    {
        $sfx = $lang === 'en' ? '_en' : '_ar';
        $titles = ['pages' => $t['footPages'], 'shop' => $t['footShop'], 'contact' => $t['footContact']];
        $bySlug = [];

        foreach ($collections as $c) {
            $bySlug[$c['slug']] = $c;
        }

        $columns = [];

        foreach ($cfg['columns'] as $col) {
            if (! $col['visible']) {
                continue;
            }

            $title = $col['title'.$sfx] !== '' ? $col['title'.$sfx] : ($titles[$col['key']] ?? '');

            if ($col['key'] === 'pages') {
                // والعنوانُ الذي لا يُضغط في القائمة لا يُنسخ إلى التذييل رابطًا
                $links = array_values(array_filter($nav, fn (array $l) => $l['href'] !== null && ! in_array($l['key'], $col['hide'], true)));

                if ($links !== []) {
                    $columns[] = ['key' => 'pages', 'title' => $title, 'links' => $links];
                }

                continue;
            }

            if ($col['key'] === 'shop') {
                $links = [];

                if ($col['all']) {
                    $links[] = ['href' => $base.'/shop', 'label' => $t['all'], 'testid' => null];
                }

                foreach ($col['collections'] as $slug) {
                    if (isset($bySlug[$slug])) {
                        $links[] = [
                            'href' => $base.'/shop?'.StoreCollections::QUERY.'='.$slug,
                            'label' => StoreCollections::title($bySlug[$slug], $lang),
                            'testid' => 'rb-foot-col-'.$slug,
                        ];
                    }
                }

                if ($col['categories']) {
                    foreach ($categories as $c) {
                        $links[] = ['href' => $base.'/shop?cat='.$c['id'], 'label' => $c['name'], 'testid' => null];
                    }
                }

                if ($links !== []) {
                    $columns[] = ['key' => 'shop', 'title' => $title, 'links' => $links];
                }

                continue;
            }

            if ($col['key'] === 'contact') {
                $columns[] = ['key' => 'contact', 'title' => $title] + array_intersect_key($col, array_flip(self::CONTACT));

                continue;
            }

            // وعمودُ النصّ بلغة الصفحة وحدها
            if ($col['title'.$sfx] !== '' || $col['body'.$sfx] !== '') {
                $columns[] = ['key' => 'text', 'id' => $col['id'], 'title' => $col['title'.$sfx], 'body' => $col['body'.$sfx]];
            }
        }

        $mine = $cfg['copyright'.$sfx];

        return [
            'about' => $cfg['about'],
            'social' => $cfg['social'],
            'columns' => $columns,
            'line' => $cfg['line'],
            // وسطرُ الحقوق كما كتبه — وإلّا السنةُ واسمُ المتجر كما كان
            'copyright' => ! $cfg['copyright'] ? null : ($mine !== '' ? $mine : '© '.now()->year.' '.$brand),
        ];
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * ما أرسلته الشاشة ← نصُّ JSON يُحفظ — أو رفضٌ برسالة.
     *
     * والأعمدةُ الثابتةُ كلُّها فيه مرّةً مرّة، والغائبةُ تُلحق آخرَه. ومجموعةٌ
     * حُذفت بعد أن اختيرت للتذييل تسقط منه ولا تردّ الحفظ: اسمٌ لا يقود إلى
     * شيءٍ لا يُرسم، وردُّ الحفظ من أجله يحبس صاحبَه عند شاشةٍ لا يفهمها.
     *
     * @param  list<array<string, mixed>>  $collections  ما يُقاس عليه — ما يُحفظ معه، وإلّا المسوّدة
     */
    public static function validated(mixed $raw, array $collections): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $in = is_string($raw) && strlen($raw) <= self::RAW_MAX ? json_decode($raw, true) : null;

        if (! is_array($in) || array_is_list($in) || ! is_array($in['columns'] ?? null) || ! array_is_list($in['columns'])) {
            self::refuse(__('إعداد التذييل غير صالح — أعد فتح المحرّر واحفظ من جديد.'));
        }

        $seen = [];
        $texts = 0;

        foreach ($in['columns'] as $c) {
            $key = is_array($c) && is_string($c['key'] ?? null) ? $c['key'] : '';
            $id = $key === 'text' ? 'text:'.(is_string($c['id'] ?? null) ? $c['id'] : '') : $key;

            if (! in_array($key, [...self::COLUMNS, 'text'], true) || isset($seen[$id])
                || ($key === 'text' && preg_match(self::TEXT_ID, substr($id, 5)) !== 1)) {
                self::refuse(__('إعداد التذييل غير صالح — أعد فتح المحرّر واحفظ من جديد.'));
            }

            if ($key === 'text' && ++$texts > self::MAX_TEXT) {
                self::refuse(__('أضف حتى :max عمودَي نصّ في التذييل.', ['max' => self::MAX_TEXT]));
            }

            $seen[$id] = true;

            foreach (['title_ar', 'title_en'] as $k) {
                if (mb_strlen(self::text($c[$k] ?? '')) > self::TITLE_MAX) {
                    self::refuse(__('عنوان عمود التذييل أطول من :max حرفًا.', ['max' => self::TITLE_MAX]));
                }
            }

            foreach (['body_ar', 'body_en'] as $k) {
                if (mb_strlen(self::text($c[$k] ?? '')) > self::BODY_MAX) {
                    self::refuse(__('نصّ عمود التذييل أطول من :max حرفًا.', ['max' => self::BODY_MAX]));
                }
            }
        }

        foreach (['copyright_ar', 'copyright_en'] as $k) {
            if (mb_strlen(self::text($in[$k] ?? '')) > self::COPYRIGHT_MAX) {
                self::refuse(__('سطر الحقوق أطول من :max حرفًا.', ['max' => self::COPYRIGHT_MAX]));
            }
        }

        $cfg = self::settings(json_encode($in));
        $known = array_map(fn (array $c) => $c['slug'], $collections);

        // والمجموعةُ التي لم تعد في قائمته تسقط بصمت — انظر أعلاه
        foreach ($cfg['columns'] as $i => $col) {
            if ($col['key'] === 'shop') {
                $cfg['columns'][$i]['collections'] = array_values(array_intersect($col['collections'], $known));
            }
        }

        return $cfg === self::defaults() ? '' : json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ═══════════ أدوات ═══════════ */

    /**
     * قائمةُ نصوصٍ فريدةٍ تطابق قاعدتها — وما سواها يسقط.
     *
     * @return list<string>
     */
    private static function strings(mixed $raw, string $pattern): array
    {
        $out = [];

        foreach (is_array($raw) ? array_slice(array_values($raw), 0, 40) : [] as $v) {
            if (is_string($v) && preg_match($pattern, $v) === 1 && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages([self::KEY => $message]);
    }
}
