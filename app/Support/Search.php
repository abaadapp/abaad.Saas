<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * البحث لا يفرّق بين حرفٍ كبيرٍ وصغير — والمحرّك يفرّق.
 *
 * كلّ بحثٍ في اللوحة مكتوبٌ بـ`like`. وهي في SQLite — وعليها تجري الاختبارات —
 * لا تفرّق بين `Ahmed` و`ahmed`، وفي PostgreSQL — وعليها يجري الإنتاج — تفرّق.
 * فالحارس أخضرُ عندنا والبحث أعمى عند التاجر:
 *
 *   'Ahmed' LIKE  '%ahmed%'  →  لا شيء
 *   'Ahmed' ILIKE '%ahmed%'  →  يُوجَد
 *
 * وأثرُه على قسم العملاء مباشر: الاسم الإنجليزيّ يُكتب كما يشاء كاتبه،
 * والبريد يُكتب بحروفٍ كبيرة في نصف البطاقات — فمن بحث عن عميلٍ يعرف أنّه
 * موجود لا يجده، فيضيفه ثانيةً. سجلّان لشخصٍ واحد، ونقاطُ ولائه بينهما.
 *
 * ولا يظهر هذا في أيّ اختبار ما دامت الاختبارات على محرّكٍ لا يفرّق.
 */
class Search
{
    /** المُعامِل الذي يفهمه محرّك القاعدة الجاري */
    public static function like(?string $connection = null): string
    {
        return self::operatorFor(DB::connection($connection)->getDriverName());
    }

    /** مفصولٌ عن الاتصال ليُختبَر لكل محرّك دون قاعدةٍ منه */
    public static function operatorFor(string $driver): string
    {
        return $driver === 'pgsql' ? 'ilike' : 'like';
    }
}
