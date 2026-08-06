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

    /**
     * الهاتف يعرّف الشخص — فلا يتكرّر داخل المتجر الواحد.
     *
     * سجلّان بالهاتف نفسه يعنيان شخصًا واحدًا برصيدَي نقاط: يشتري فتُضاف
     * نقاطه إلى أحدهما، ويأتي ليستبدل فيُقرأ الآخر، فيُقال له «رصيدك صفر»
     * وقد اشترى للتوّ. ولا يظهر شيء من ذلك في أي شاشة — لأن كلا السجلّين
     * صحيح على حدة.
     *
     * الفراغ مسموح: عابرٌ بلا هاتف عميلٌ صحيح، والقيد على القيم المكتوبة.
     */
    public static function phoneRule(int $businessId, ?int $exceptId = null): array
    {
        $rule = \Illuminate\Validation\Rule::unique('customers', 'phone')
            ->where(fn ($q) => $q->where('business_id', $businessId));

        return ['nullable', 'string', 'max:50', $exceptId ? $rule->ignore($exceptId) : $rule];
    }
}
