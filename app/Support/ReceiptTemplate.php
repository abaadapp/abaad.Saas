<?php

namespace App\Support;

use App\Models\Setting;

/**
 * قالب الإيصال — إعدادات «قوالب» مقروءةً بشكلٍ يفهمه العرض.
 *
 * والترشيح في PHP لا في SQL عن قصد: `LIKE 'tpl\_%'` يعمل على MySQL وحده،
 * لأن الشرطة المائلة ليست رمز الهروب الافتراضي في SQLite ولا PostgreSQL —
 * فيعود الاستعلام فارغًا هناك، ويطبع الإيصال متجاهلًا كل ما ضبطه التاجر
 * بلا خطأ ولا أثر. وإعدادات النشاط عشرات لا آلاف، فالفرق لا يُقاس.
 */
class ReceiptTemplate
{
    /** @return array<string, string|bool> */
    public static function forBusiness(int $businessId): array
    {
        return Setting::where('business_id', $businessId)
            ->pluck('value', 'key')
            ->filter(fn ($v, $k) => str_starts_with($k, 'tpl_') || in_array($k, ['paper', 'vat_number'], true))
            // مفاتيح tpl_show_* أعلامٌ لا نصوص: «0» نصًّا صادقةٌ في PHP
            ->map(fn ($v, $k) => str_starts_with($k, 'tpl_show_') ? $v === '1' : $v)
            ->all();
    }
}
