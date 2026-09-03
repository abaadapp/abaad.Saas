<?php

namespace App\Support;

/**
 * اسمٌ يُكتب مرّةً ويُقرأ بلغتين.
 *
 * المحلّ يكتب أسماء الناس كما تأتيه: «Ahmed» من بطاقةٍ لاتينية، و«أحمد»
 * من فم صاحبه. والشاشة تُقرأ بلغةٍ يختارها من يقف أمامها — فكاشيرٌ لا
 * يقرأ العربية كان يرى قائمةً كلّها حروفٌ لا يفكّها.
 *
 * فلكلّ اسمٍ عمودان: `name` هو المعتمَد وما يُبحث به ويُطبع في الفاتورة،
 * و`name_en` صورتُه اللاتينية إن وُجدت. والعرض يختار بينهما بلغة الواجهة
 * (Demo::ln).
 *
 * والقاعدة هنا واحدةٌ للعملاء والموردين: كانت في `Customers` وحدها،
 * فبقي المورّد بلا اسمٍ ثانٍ سنةً كاملة لأنّ أحدًا لم ينقل عشرة أسطر.
 */
class LocalName
{
    /**
     * يملأ `name` و`name_en` من المُدخَل.
     *
     *   - مُدخَلٌ لاتينيّ  →  يُنقل إلى العربية في `name`، والأصل في `name_en`
     *   - مُدخَلٌ عربيّ    →  يبقى كما هو، و`name_en` يبقى فارغًا
     *
     * وما يُكتب بيدٍ في حقل «الاسم بالإنجليزية» يعلو على الاشتقاق: النقل
     * الآليّ تخمينٌ يصيب ويخطئ، ومن كتب اسمه بنفسه أعلمُ بكتابته. وكان
     * يُكتب فوقه بلا استئذان، فلا سبيل إلى تصحيح «Muhammed» إلى
     * «Mohammed» أبدًا.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function apply(array $data): array
    {
        $input = trim((string) ($data['name'] ?? ''));
        if ($input === '') {
            return $data;
        }

        $manual = trim((string) ($data['name_en'] ?? ''));

        if (NameTransliterator::isLatin($input)) {
            $arabic = NameTransliterator::toArabic($input);
            // العربية إن فُهمت، وإلّا يبقى اللاتينيّ كما هو — اسمٌ لا يُفكّ
            // خيرٌ من اسمٍ مشوَّه
            $data['name'] = $arabic ?? $input;
            $data['name_en'] = $manual !== '' ? $manual : $input;
        } else {
            $data['name'] = $input;
            $data['name_en'] = $manual !== '' ? $manual : null;
        }

        return $data;
    }
}
