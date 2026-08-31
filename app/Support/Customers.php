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
        return ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) use ($businessId, $exceptId) {
            if (blank($value)) {
                return;
            }

            $clash = \App\Models\Customer::withTrashed()
                ->where('business_id', $businessId)
                ->where('phone', $value)
                ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
                ->first();

            if (! $clash) {
                return;
            }

            /*
             * والمحذوف يُقال إنه محذوف.
             *
             * القيد كان يقرأ الجدول كلَّه — والمحذوف ناعمًا صفٌّ باقٍ فيه —
             * فيردّ «مسجَّل لعميل آخر» عن عميلٍ لا تعرضه أيّ شاشة. والكاشير
             * واقفٌ والعميل أمامه يبحث عن اسمٍ ليس في القائمة ولا في البحث،
             * فلا مخرج له إلا أن يترك الرقم. والمخرج موجود — استعادةٌ من
             * المحذوفات تردّ العميل بنقاطه — لكنّ الرسالة لم تكن تدلّ عليه.
             */
            $fail($clash->trashed()
                ? __('هذا الرقم مسجَّل لعميل محذوف: «:name» — استعِده من الإعدادات ← المحذوفات.', ['name' => $clash->name])
                : __('هذا الرقم مسجَّل لعميل آخر — نقاط الولاء تتبع الرقم.'));
        }];
    }
}
