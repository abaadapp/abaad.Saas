<?php

namespace App\Support\Website;

use Illuminate\Support\Facades\Storage;

/**
 * روابط الصور كما يقرؤها عارضٌ في نطاقٍ آخر.
 *
 * الصور في النظام تُخزَّن مساراتٍ (`products/12.jpg`) وتُقرأ روابطَ نسبيّة
 * (`/storage/products/12.jpg`). وهذا يعمل ما دام القارئ في نطاق أبعاد نفسه.
 *
 * لكنّ الموقع المنشور يُقرأ من نطاق التاجر: رابطٌ نسبيّ هناك يعني
 * `https://متجره.com/storage/products/12.jpg` — عنوانٌ لا شيء عليه. فكلّ
 * رابطٍ يخرج في المستند يُجعل مطلقًا هنا، مرّةً واحدةً وفي موضعٍ واحد.
 *
 * وأيّ رابطٍ خارجيّ يُترك كما هو: التاجر قد يلصق صورةً من مستضيفٍ آخر،
 * والحقل يقبل الأمرين منذ بُني.
 */
class Media
{
    /**
     * رابطٌ مطلق — أو null إن لا صورة.
     *
     * وnull لا فراغ: العارض يرسم مكانًا محايدًا للصورة الغائبة، والفراغُ
     * يجعله يرسم `<img src="">` فيطلب الصفحةَ نفسها صورةً.
     */
    public static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        // ما بدأ بـ`data:` صورةٌ مضمّنة تعمل في أي نطاق
        if (str_starts_with($value, 'data:image/')) {
            return $value;
        }

        $path = str_starts_with($value, '/') ? $value : Storage::url($value);

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * مفاتيح الصور في نوعٍ من الأقسام — من وصف الحقول لا من قائمةٍ مكتوبة.
     *
     * قائمةٌ مكتوبة بيدها تُنسى يوم يُضاف قسمٌ فيه صورة، فتخرج صورتُه
     * برابطٍ نسبيّ لا يُرى إلا في موقع التاجر.
     *
     * @return array{flat: array<int, string>, lists: array<string, array<int, string>>}
     */
    public static function fields(string $type): array
    {
        $flat = [];
        $lists = [];

        foreach (Sections::CATALOGUE[$type]['fields'] ?? [] as $key => $field) {
            if (($field['type'] ?? '') === 'image') {
                $flat[] = $key;

                continue;
            }

            foreach ($field['item'] ?? [] as $sub => $spec) {
                if (($spec['type'] ?? '') === 'image') {
                    $lists[$key][] = $sub;
                }
            }
        }

        return ['flat' => $flat, 'lists' => $lists];
    }

    /**
     * بيانات قسمٍ وقد صارت روابطُ صورها مطلقة.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function absolute(string $type, array $data): array
    {
        $fields = self::fields($type);

        foreach ($fields['flat'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = self::url((string) $data[$key]) ?? '';
            }
        }

        foreach ($fields['lists'] as $key => $subs) {
            if (! is_array($data[$key] ?? null)) {
                continue;
            }

            $data[$key] = array_map(function ($row) use ($subs) {
                foreach ($subs as $sub) {
                    if (is_array($row) && isset($row[$sub])) {
                        $row[$sub] = self::url((string) $row[$sub]) ?? '';
                    }
                }

                return $row;
            }, $data[$key]);
        }

        return $data;
    }
}
