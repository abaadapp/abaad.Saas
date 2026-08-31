<?php

namespace App\Support;

class Customers
{
    /**
     * توطين اسم العميل — والقاعدة مشتركةٌ مع المورّدين.
     *
     * @see LocalName::apply
     */
    public static function localizeName(array $data): array
    {
        return LocalName::apply($data);
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
