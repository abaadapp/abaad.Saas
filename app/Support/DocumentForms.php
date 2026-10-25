<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
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

    /** ما يُعرف عن العميل يُقترح في حقول النموذج المماثلة — ويعدّله هو */
    private const PREFILL = ['org' => 'biz', 'person' => 'name', 'store' => 'biz', 'biz' => 'biz', 'phone' => 'phone', 'email' => 'email', 'cr' => 'cr'];

    /**
     * يزرع قوالبَ المالك الخمسة إن غابت — ويعطّل العقدَ القصير الأوّل للإصدار.
     *
     * لا يمسّ قالبًا موجودًا باسمه (ربما عدّله المدير)، ولا يحذف شيئًا: العقدُ
     * القصير يبقى لما صدر منه، ويُفعَّل من شاشة القوالب إن احتيج.
     */
    public static function seedTemplates(): void
    {
        foreach (self::SEEDED as $slug) {
            $t = self::seed($slug);
            if (DB::table('platform_document_templates')->where('name', $t['name'])->exists()) {
                continue;
            }
            DB::table('platform_document_templates')->insert([
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
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('platform_document_templates')->where('name', 'عقد اشتراك أبعاد')->whereNull('form')
            ->update(['active' => false, 'updated_at' => now()]);
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
