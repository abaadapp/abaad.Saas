<?php

namespace App\Support;

class Customers
{
    /**
     * توطين اسم العميل: إن أُدخل بالإنجليزية، نُنشئ نسخة عربية صحيحة (name)
     * ونحتفظ بالأصل الإنجليزي (name_en). وإن لم يُفهم الاسم يبقى إنجليزيًا كما هو.
     * الإدخال العربي يبقى كما هو بلا تغيير.
     */
    public static function localizeName(array $data): array
    {
        $input = trim((string) ($data['name'] ?? ''));
        if ($input === '') {
            return $data;
        }

        if (NameTransliterator::isLatin($input)) {
            $arabic = NameTransliterator::toArabic($input);
            $data['name'] = $arabic ?? $input;   // العربية إن فُهمت، وإلا يبقى الإنجليزي
            $data['name_en'] = $input;            // نحتفظ دائمًا بالأصل الإنجليزي
        } else {
            $data['name'] = $input;
            $data['name_en'] = null;              // إدخال عربي (أو غيره): يبقى كما هو
        }

        return $data;
    }
}
