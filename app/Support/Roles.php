<?php

namespace App\Support;

/**
 * أدوار النظام: مفتاحُها وتسميتُها في موضعٍ واحد.
 *
 * كانت في ثلاثة: `User::roleLabel()` وفيها ثمانية أدوار، و`JobTitle::ROLES`
 * وفيها ستّة، و`roleOptions()` في لوحة المنصّة وفيها ستّة أُخر بتسمياتٍ
 * ثالثة. فاختلفت الشاشة عن مرشّحها: عمود «الدور» يكتب «مدير» لصاحب النشاط
 * ولمدير الفرع معًا — لا يُفرَّق بينهما — بينما مرشّح الأدوار فوقه يعرض
 * «مدير نشاط» و«مدير فرع». ويُختار من المرشّح اسمٌ لا يظهر في أيّ صفّ.
 *
 * وأخطر من اختلاف الأسماء اختلافُ القوائم: دوران — `inventory` و`delivery` —
 * يعملان في النظام ولا يظهران في قائمة لوحة المنصّة. فمن فتح موظّف مخزونٍ
 * ليصلح هاتفه وجد خانة الدور فارغةً ومطلوبة، فلا يحفظ حتى يغيّر دوره:
 * تعديلُ رقمٍ يُنقص صلاحيات.
 */
class Roles
{
    /** كل أدوار النظام بترتيب النفوذ: المفتاح ← التسمية */
    public const LABELS = [
        'super_admin' => 'مدير المنصة',
        'admin' => 'مدير نشاط',
        'manager' => 'مدير فرع',
        'accountant' => 'محاسب',
        'inventory' => 'مسؤول مخزون',
        'sales' => 'موظف مبيعات',
        'delivery' => 'مندوب توصيل',
        'cashier' => 'كاشير',
    ];

    /**
     * أدوار الموظّفين: ما يُسنَد إلى وظيفةٍ داخل نشاطٍ تجاريّ.
     *
     * دون `super_admin` — فليس من النشاط — ودون `admin` لأنه صاحب النشاط
     * نفسه: وظيفةٌ تمنحه لأيّ موظّف تمنحه كلَّ شيء.
     */
    public const STAFF = ['manager', 'accountant', 'inventory', 'sales', 'delivery', 'cashier'];

    /** كل المفاتيح — يُتحقَّق بها من المُدخل فلا يُحفظ دورٌ لا يعرفه النظام */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** تسمية الدور، والمجهول يُعرض كما هو لا يُخفى */
    public static function label(?string $role): string
    {
        return isset(self::LABELS[$role]) ? __(self::LABELS[$role]) : (string) $role;
    }

    /** خيارات قائمةٍ منسدلة — الكلّ أو مجموعةٌ بعينها */
    public static function options(?array $keys = null): array
    {
        return collect($keys ?? self::keys())
            ->map(fn ($key) => ['label' => self::label($key), 'value' => $key])
            ->all();
    }

    /** أدوار الموظّفين: المفتاح ← التسمية (لقوائم الوظائف) */
    public static function staffLabels(): array
    {
        return collect(self::STAFF)->mapWithKeys(fn ($key) => [$key => self::label($key)])->all();
    }
}
