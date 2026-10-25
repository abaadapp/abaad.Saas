<?php

namespace App\Support;

use App\Models\PlatformDocumentTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * نماذجُ مستندات أبعاد — اتفاقياتٌ فيها حقولٌ وقوائمُ وجداول، لا بنودٌ فقط.
 *
 * ═══ الشكل ═══
 *
 * `{intro, highlights[], sections[], consent, agree, footer}`، وكلُّ قسم
 * `{title, after, parts[]}`، وكلُّ جزءٍ واحدٌ من:
 *
 *   نصّ:   p · list · note · cols · amount (مبلغُ المستند)
 *   إدخال: field · checks · table · yesno — ولكلٍّ `by`: client أو team، ومفتاحٌ `k`
 *          فريدٌ في النموذج.
 *
 * يعبّئ العميلُ إدخالاتِ client في صفحة التوقيع، ويعبّئ الفريقُ إدخالاتِ team في
 * نافذة الإصدار. والقسمُ `after` يُعبّأ بعد تنفيذ الخدمة (محضرُ الاستلام): يُطبع
 * فارغًا، ولا يقبل إدخالًا من أحدٍ هنا.
 *
 * ═══ ما يُقبل ═══
 *
 * `clean` لا يُبقي إلّا مفاتيحَ النموذج لصاحبها: العميلُ لا يكتب حقلَ فريق، ولا
 * أحدٌ يكتب قسمَ «بعد التنفيذ»، وما لا يعرفه النموذجُ يُترك. والقيمُ نصوصٌ
 * محدودةُ الطول، والقوائمُ أرقامُ بنودها، والجداولُ صفوفُها وأعمدتُها المعروفة.
 */
class DocumentForms
{
    /** نماذجُ المالك المزروعة — بأسماء ملفّاتها في `database/data/abaad-documents` */
    public const SEEDED = ['subscription-agreement', 'store-setup', 'website-customization', 'payment-integration', 'whatsapp-integration'];

    public const INPUTS = ['field', 'checks', 'table', 'yesno'];

    /** أجزاءُ القسم كلُّها — نصوصٌ ثمّ إدخالات */
    public const PARTS = ['p', 'list', 'note', 'cols', 'amount', 'field', 'checks', 'table', 'yesno'];

    /** أنواعُ الحقل كما تقرؤها صفحةُ التوقيع */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'time', 'tel', 'email'];

    /** أنواعُ خانات الجدول: نصّ، وتأشير، واختيارٌ من `picks` */
    public const CELL_TYPES = ['text', 'check', 'pick'];

    /** مفتاحُ الإدخال: حروفٌ إنجليزيّة وأرقام، يبدأ بحرف — به تُحفظ إجاباتُه */
    public const KEY = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    /** ما يُعرف عن العميل يُقترح في حقول النموذج المماثلة — ويعدّله هو */
    private const PREFILL = ['org' => 'biz', 'person' => 'name', 'store' => 'biz', 'biz' => 'biz', 'phone' => 'phone', 'email' => 'email', 'cr' => 'cr'];

    /**
     * يزرع قوالبَ المالك الخمسة إن غابت — يُصدر منها كما يُصدر من كلّ قالبٍ صالح.
     *
     * لا يمسّ قالبًا موجودًا (ربما عدّله المدير أو أعاد تسميتَه)، ولا يحذف شيئًا، ولا
     * يُصدر مستندًا. ويعرف قالبَه بمفتاح بذرته (`seed_key`) إن وُجد العمود، أو باسمه، أو
     * بعنوانه وخدمته معًا — فقالبٌ أُعيدت تسميتُه لا يُزرع بجانبه آخر.
     * وما يُزرع بعد سجلّ الإصدارات يدخل سجلَّه بإصداره الأوّل.
     */
    public static function seedTemplates(): void
    {
        $keyed = Schema::hasColumn('platform_document_templates', 'seed_key');
        $history = Schema::hasTable('platform_document_template_versions');
        foreach (self::SEEDED as $slug) {
            $t = self::seed($slug);
            $exists = DB::table('platform_document_templates')
                ->where(fn ($w) => ($keyed ? $w->where('seed_key', $slug)->orWhere('name', $t['name']) : $w->where('name', $t['name']))
                    ->orWhere(fn ($same) => $same->whereNotNull('form')->where('title', $t['title'])->where('service', $t['service'])))
                ->exists();
            if ($exists) {
                continue;
            }
            $id = DB::table('platform_document_templates')->insertGetId(($keyed ? ['seed_key' => $slug] : []) + [
                'name' => $t['name'],
                'service' => $t['service'],
                'title' => $t['title'],
                'title_en' => $t['title_en'],
                'terms' => json_encode([], JSON_UNESCAPED_UNICODE),
                'form' => json_encode($t['form'], JSON_UNESCAPED_UNICODE),
                'includes_plan' => $t['includes_plan'],
                'includes_amount' => $t['includes_amount'],
                'default_amount' => $t['default_amount'],
                'default_amount_label' => $t['default_amount_label'],
                'amount_rule' => $t['amount_rule'] ?? null,
                'version' => 1,
                'active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($history) {
                PlatformDocumentTemplate::findOrFail($id)->recordVersion(null);
            }
        }
    }

    /**
     * يعرّف قوالبَ البذرة القائمة بمفاتيحها: باسمها كما زُرعت، وإلّا بعنوانها وخدمتها
     * — ما أُعيدت تسميتُه قبل المفتاح يُعرف بهما. وما التبس (قالبان لبذرةٍ واحدة) لا يُخمَّن.
     */
    public static function keySeeds(): void
    {
        foreach (self::SEEDED as $slug) {
            if (DB::table('platform_document_templates')->where('seed_key', $slug)->exists()) {
                continue;
            }
            $t = self::seed($slug);
            $unkeyed = fn () => DB::table('platform_document_templates')->whereNull('seed_key');
            $ids = $unkeyed()->where('name', $t['name'])->pluck('id');
            if ($ids->isEmpty()) {
                $ids = $unkeyed()->whereNotNull('form')->where('title', $t['title'])->where('service', $t['service'])->pluck('id');
            }
            if ($ids->count() === 1) {
                DB::table('platform_document_templates')->where('id', $ids->first())->update(['seed_key' => $slug]);
            }
        }
    }

    /** القالبُ كما خرج من التصميم */
    public static function seed(string $slug): array
    {
        $path = database_path('data/abaad-documents/'.basename($slug).'.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! isset($data['form']['sections'])) {
            throw new RuntimeException('Unknown document form: '.$slug);
        }

        return $data;
    }

    /**
     * النموذجُ بقيمه — يُكتب في المستند ساعةَ إصداره نصًّا تامًّا.
     *
     * نصُّ التصميم يذكر الرسومَ في مواضعَ (الأبرز، والتفاصيل، والإقرار): «12.000
     * ر.ع» و«12 ريالًا». فيُكتب في القالب `{fee}` و`{fee_short}`، ويُملأ من رسوم
     * القالب المعتمدة — فلا يقول النصُّ رقمًا ومبلغُ المستند غيرَه. ومقدّمُ الخدمة
     * `{provider}` من بياناته القانونيّة ساعتَها؛ فإن لم يُكتب منها شيءٌ سقط السطرُ
     * الذي لا يحمل غيرَه — لا شَرطةٌ ولا اسمٌ يُستعار.
     *
     * والمستندُ يحمل النتيجة لا الرموز: ما وقّعه العميل نصٌّ لا يتغيّر بتغيّر
     * القالب ولا الإعدادات.
     *
     * @param  array{fee?:?string, provider?:?string}  $values
     */
    public static function render(?array $form, array $values): ?array
    {
        if (! $form) {
            return null;
        }
        $fee = $values['fee'] ?? null;
        $map = [
            '{fee}' => $fee === null ? '—' : number_format((float) $fee, 3, '.', ''),
            '{fee_short}' => $fee === null ? '—' : rtrim(rtrim(number_format((float) $fee, 3, '.', ''), '0'), '.'),
            '{provider}' => trim((string) ($values['provider'] ?? '')),
        ];
        array_walk_recursive($form, function (&$v) use ($map) {
            if (is_string($v) && str_contains($v, '{')) {
                $v = strtr($v, $map);
            }
        });
        if (isset($form['highlights'])) {
            $form['highlights'] = array_values(array_filter($form['highlights'], fn ($h) => trim((string) ($h['text'] ?? '')) !== ''));
        }

        return $form;
    }

    /** هل في النموذج رموزٌ تُملأ ساعةَ الإصدار؟ — نصُّ القالب لا المستند */
    public static function tokens(?array $form): array
    {
        $found = [];
        $walk = $form ?? [];
        array_walk_recursive($walk, function ($v) use (&$found) {
            if (is_string($v) && preg_match_all('/\{(fee|fee_short|provider)\}/', $v, $m)) {
                $found = array_merge($found, $m[1]);
            }
        });

        return array_values(array_unique($found));
    }

    /**
     * إدخالاتُ صاحبها في النموذج — خارجَ أقسام «بعد التنفيذ».
     *
     * @return list<array<string, mixed>>
     */
    public static function inputs(?array $form, string $by): array
    {
        $out = [];
        foreach ($form['sections'] ?? [] as $section) {
            if (! empty($section['after'])) {
                continue;
            }
            foreach ($section['parts'] ?? [] as $part) {
                if (in_array($part['kind'] ?? '', self::INPUTS, true) && ($part['by'] ?? '') === $by) {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    /** هل يسأل النموذجُ العميلَ عن بياناته بحقوله؟ — فتحلّ محلّ بيان العميل العامّ */
    public static function asksClient(?array $form): bool
    {
        foreach (self::inputs($form, 'client') as $part) {
            if ($part['kind'] === 'field') {
                return true;
            }
        }

        return false;
    }

    /** هل في النموذج مبلغُ المستند؟ — فيُعرض في قسمه لا في رأس الصفحة */
    public static function showsAmount(?array $form): bool
    {
        foreach ($form['sections'] ?? [] as $section) {
            foreach ($section['parts'] ?? [] as $part) {
                if (($part['kind'] ?? '') === 'amount') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ما يُقبل من إدخالٍ لصاحبه — مفاتيحُ النموذج وحدها، بقيمٍ محدودة.
     *
     * @return array<string, mixed>
     */
    public static function clean(?array $form, array $input, string $by): array
    {
        $out = [];
        foreach (self::inputs($form, $by) as $part) {
            $k = $part['k'];
            if (! array_key_exists($k, $input)) {
                continue;
            }
            $v = $input[$k];
            $value = match ($part['kind']) {
                'field' => self::text($v, ($part['type'] ?? 'text') === 'textarea' ? 2000 : 500),
                'yesno' => in_array($v, ['yes', 'no'], true) ? $v : null,
                'checks' => self::picked($v, count($part['items'] ?? [])),
                'table' => self::table($part, $v),
                default => null,
            };
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$k] = $value;
            }
        }

        return $out;
    }

    /** حقولُ العميل الإلزاميّة التي لم يعبّئها — بعناوينها */
    public static function missing(?array $form, array $answers): array
    {
        $missing = [];
        foreach (self::inputs($form, 'client') as $part) {
            if ($part['kind'] === 'field' && ! empty($part['req']) && trim((string) ($answers[$part['k']] ?? '')) === '') {
                $missing[] = $part['label'];
            }
        }

        return $missing;
    }

    /** ما يُقترح للعميل في حقول نموذجه من بيانه المعروف */
    public static function prefill(?array $form, array $client): array
    {
        $out = [];
        foreach (self::inputs($form, 'client') as $part) {
            $from = self::PREFILL[$part['k']] ?? null;
            if ($part['kind'] === 'field' && $from && ($client[$from] ?? '') !== '') {
                $out[$part['k']] = (string) $client[$from];
            }
        }

        return $out;
    }

    /** نصُّ ما أقرّ به العميل: فقرةُ الإقرار وخانتُه — يُحفظ معه حين يوقّع */
    public static function consent(?array $form): ?string
    {
        if (! $form) {
            return null;
        }

        return trim(implode("\n", array_filter([trim((string) ($form['consent'] ?? '')), trim((string) ($form['agree'] ?? ''))]))) ?: null;
    }

    /**
     * النموذجُ كما يُحفظ من المحرّر — مفاتيحُ كلّ جزءٍ المعروفةُ وحدها، نصوصًا مشذّبة.
     *
     * وأعمدةُ الجدول مواضعُ لا قائمة: عنوانُ العمود ونوعُه وتلميحُه تُقرأ بموضعها،
     * فتُكمَّل بفراغٍ ولا تُرصّ. وما لا يعرفه النموذجُ يُترك.
     */
    public static function normalize(array $form): array
    {
        $str = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $lines = fn ($v) => array_values(array_filter(array_map($str, is_array($v) ? $v : []), fn ($x) => $x !== ''));
        $by = fn ($v) => $v === 'team' ? 'team' : 'client';
        $list = fn ($v) => array_values(array_filter(is_array($v) ? $v : [], 'is_array'));

        $part = function (array $p) use ($str, $lines, $by, $list): array {
            $kind = $str($p['kind'] ?? '');
            $input = fn (array $more) => ['kind' => $kind, 'by' => $by($p['by'] ?? ''), 'k' => $str($p['k'] ?? '')] + $more;

            return match ($kind) {
                'p', 'note' => ['kind' => $kind, 'text' => $str($p['text'] ?? '')],
                'list' => ['kind' => 'list', 'items' => $lines($p['items'] ?? [])],
                'cols' => ['kind' => 'cols', 'cols' => array_map(fn ($c) => ['head' => $str($c['head'] ?? ''), 'items' => $lines($c['items'] ?? [])], $list($p['cols'] ?? []))],
                'amount' => ['kind' => 'amount', 'label' => $str($p['label'] ?? '')],
                'field' => $input([
                    'label' => $str($p['label'] ?? ''),
                    'req' => filter_var($p['req'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'type' => $str($p['type'] ?? '') ?: 'text',
                    'dir' => ($p['dir'] ?? '') === 'ltr' ? 'ltr' : 'rtl',
                    'ph' => $str($p['ph'] ?? ''),
                ]),
                'checks' => $input(['head' => $str($p['head'] ?? ''), 'items' => $lines($p['items'] ?? [])]),
                'yesno' => $input(['label' => $str($p['label'] ?? '')]),
                'table' => (function () use ($p, $str, $lines, $input) {
                    $types = array_map(fn ($t) => in_array($t, self::CELL_TYPES, true) ? $t : $str($t), array_values(is_array($p['types'] ?? null) ? $p['types'] : []));
                    $at = fn ($v, int $n) => array_map(fn ($i) => $str((is_array($v) ? array_values($v) : [])[$i] ?? ''), range(0, max(0, $n - 1)));

                    return $input([
                        'head' => $at($p['head'] ?? [], count($types) + 1),
                        'types' => $types,
                        'ph' => $types === [] ? [] : $at($p['ph'] ?? [], count($types)),
                        'rows' => $lines($p['rows'] ?? []),
                        'picks' => $lines($p['picks'] ?? []),
                    ]);
                })(),
                default => ['kind' => $kind],
            };
        };

        $optional = fn ($v) => ($v = $str($v)) === '' ? null : $v;

        return [
            'intro' => $optional($form['intro'] ?? null),
            'highlights' => array_values(array_filter(
                array_map(fn ($h) => ['label' => $str($h['label'] ?? ''), 'text' => $str($h['text'] ?? '')], $list($form['highlights'] ?? [])),
                fn ($h) => $h['label'] !== '' || $h['text'] !== '',
            )),
            'sections' => array_map(fn ($sec) => [
                'title' => $str($sec['title'] ?? ''),
                'after' => filter_var($sec['after'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'parts' => array_map($part, $list($sec['parts'] ?? [])),
            ], $list($form['sections'] ?? [])),
            'consent' => $str($form['consent'] ?? ''),
            'agree' => $str($form['agree'] ?? ''),
            'footer' => $optional($form['footer'] ?? null),
        ];
    }

    /**
     * ما يمنع حفظَ النموذج أو نشرَه — بعباراتٍ يقرؤها المدير، أو قائمةٌ فارغة.
     *
     * يحرس ما تقوم عليه صفحةُ التوقيع وورقتُها: لكلّ إدخالٍ مفتاحٌ صالحٌ فريد (به
     * تُحفظ إجاباتُه وتُقرأ)، ولكلّ حقلٍ اسمٌ ونوعٌ معروف، ولكلّ جدولٍ صفوفٌ وأعمدةٌ
     * بأنواعٍ معروفة، وللإقرار نصٌّ يوقّع عليه العميل.
     *
     * @return list<string>
     */
    public static function problems(array $form): array
    {
        $out = [];
        $sections = $form['sections'] ?? [];
        if ($sections === [] || count($sections) > 60) {
            $out[] = $sections === [] ? __('أضف قسمًا واحدًا على الأقل.') : __('الأقسام أكثر من 60.');
        }
        $keys = [];
        $long = fn ($v) => mb_strlen((string) $v) > 5000;
        foreach ($sections as $i => $section) {
            $where = ($section['title'] ?? '') !== '' ? '«'.$section['title'].'»' : __('القسم :n', ['n' => $i + 1]);
            if (($section['title'] ?? '') === '') {
                $out[] = __('القسم :n بلا عنوان.', ['n' => $i + 1]);
            }
            if (count($section['parts'] ?? []) > 80) {
                $out[] = __('في :where أكثر من 80 جزءًا.', ['where' => $where]);
            }
            foreach ($section['parts'] ?? [] as $p) {
                $kind = $p['kind'] ?? '';
                if (! in_array($kind, self::PARTS, true)) {
                    $out[] = __('في :where جزء من نوع غير معروف.', ['where' => $where]);

                    continue;
                }
                $empty = match ($kind) {
                    'p', 'note' => ($p['text'] ?? '') === '' || $long($p['text']),
                    'list' => ($p['items'] ?? []) === [],
                    'cols' => ($p['cols'] ?? []) === [] || count($p['cols']) > 6
                        || array_filter($p['cols'], fn ($c) => $c['head'] === '' && $c['items'] === []) !== [],
                    'amount', 'yesno' => ($p['label'] ?? '') === '',
                    'field' => ($p['label'] ?? '') === '',
                    'checks' => ($p['items'] ?? []) === [],
                    'table' => ($p['rows'] ?? []) === [] || ($p['types'] ?? []) === [],
                    default => false,
                };
                if ($empty) {
                    $out[] = __('في :where جزء ناقص (:kind) — اكتبه أو احذفه.', ['where' => $where, 'kind' => __(self::LABELS[$kind])]);
                }
                if (! in_array($kind, self::INPUTS, true)) {
                    continue;
                }
                $k = (string) ($p['k'] ?? '');
                if (! preg_match(self::KEY, $k)) {
                    $out[] = __('مفتاح الإدخال «:k» في :where غير صالح — حروف إنجليزية وأرقام، يبدأ بحرف.', ['k' => $k, 'where' => $where]);
                } elseif (isset($keys[$k])) {
                    $out[] = __('المفتاح «:k» مكرر — لكل إدخال مفتاحه.', ['k' => $k]);
                }
                $keys[$k] = true;
                if ($kind === 'field' && ! in_array($p['type'] ?? '', self::FIELD_TYPES, true)) {
                    $out[] = __('نوع الحقل «:label» غير معروف.', ['label' => $p['label'] ?? $k]);
                }
                if ($kind === 'table') {
                    if (count($p['types'] ?? []) > 8 || count($p['rows'] ?? []) > 40) {
                        $out[] = __('الجدول «:k» أكبر من المسموح (8 أعمدة و40 صفًّا).', ['k' => $k]);
                    }
                    if (array_diff($p['types'] ?? [], self::CELL_TYPES) !== []) {
                        $out[] = __('في الجدول «:k» عمود من نوع غير معروف.', ['k' => $k]);
                    }
                    if (in_array('pick', $p['types'] ?? [], true) && ($p['picks'] ?? []) === []) {
                        $out[] = __('في الجدول «:k» عمود اختيار بلا خيارات.', ['k' => $k]);
                    }
                }
            }
        }
        if (($form['consent'] ?? '') === '') {
            $out[] = __('اكتب نص الإقرار — يوقّع عليه العميل.');
        }
        if (($form['agree'] ?? '') === '') {
            $out[] = __('اكتب نص خانة الموافقة.');
        }

        return array_values(array_unique($out));
    }

    /** أهما النموذجُ نفسُه؟ — بعد التنظيف، أيًّا كان ترتيبُ مفاتيحه */
    public static function same(?array $a, ?array $b): bool
    {
        $canon = function ($v) use (&$canon) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($canon, $v);
        };

        return json_encode($canon($a === null ? null : self::normalize($a)), JSON_UNESCAPED_UNICODE)
            === json_encode($canon($b === null ? null : self::normalize($b)), JSON_UNESCAPED_UNICODE);
    }

    /** أسماءُ الأجزاء كما يقرؤها المدير — في المحرّر وفي رسائل الحفظ */
    public const LABELS = [
        'p' => 'فقرة', 'list' => 'قائمة', 'note' => 'ملاحظة', 'cols' => 'أعمدة', 'amount' => 'مبلغ المستند',
        'field' => 'حقل', 'checks' => 'خيارات للتأشير', 'table' => 'جدول', 'yesno' => 'سؤال نعم/لا',
    ];

    private static function text(mixed $v, int $max): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** أرقامُ البنود المؤشَّر عليها — داخل القائمة، بلا تكرار، مرتّبة */
    private static function picked(mixed $v, int $count): array
    {
        if (! is_array($v)) {
            return [];
        }
        $ids = [];
        foreach ($v as $i) {
            if (is_scalar($i) && ctype_digit((string) $i) && (int) $i < $count) {
                $ids[(int) $i] = (int) $i;
            }
        }
        ksort($ids);

        return array_values($ids);
    }

    /** خلايا الجدول — صفٌّ لكلّ سطرٍ وخانةٌ لكلّ عمود، والفارغُ نصٌّ فارغ */
    private static function table(array $part, mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }
        $types = $part['types'] ?? [];
        $picks = $part['picks'] ?? [];
        $rows = [];
        $any = false;
        foreach (array_keys($part['rows'] ?? []) as $r) {
            $in = is_array($v[$r] ?? null) ? $v[$r] : [];
            $row = [];
            foreach ($types as $c => $type) {
                $cell = $in[$c] ?? null;
                $value = match ($type) {
                    'check' => in_array($cell, ['1', 1, true, 'on'], true) ? '1' : '',
                    'pick' => is_string($cell) && in_array($cell, $picks, true) ? $cell : '',
                    default => self::text($cell, 200) ?? '',
                };
                $any = $any || $value !== '';
                $row[] = $value;
            }
            $rows[] = $row;
        }

        return $any ? $rows : [];
    }
}
