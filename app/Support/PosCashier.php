<?php

namespace App\Support;

use App\Models\User;

/**
 * الموظف الذي يقف على الصندوق الآن.
 *
 * قبل هذا كانت كل بيعة تُنسب إلى `auth()->user()` — أي إلى صاحب النشاط إن
 * كان هو من فتح الشاشة، مهما كان الواقف عليها. الصندوق في المحل جهازٌ واحد
 * يتناوب عليه الموظفون بحساب واحد، فنسبة البيع إلى صاحب الحساب تجعل تقارير
 * أداء الموظفين بلا معنى.
 *
 * الاختيار يُحفظ في الجلسة لا في الحساب: تبديل الموظف لا يُخرج أحدًا ولا
 * يغيّر الصلاحيات — الصلاحيات تبقى للمستخدم المسجَّل دخوله. هذا اختيارُ
 * «من يُنسب إليه البيع» فقط، وليس دخولًا.
 */
class PosCashier
{
    private const KEY = 'pos_cashier_id';

    /**
     * الموظف المختار، أو null إن لم يُختر بعد أو لم يعد صالحًا.
     *
     * التحقّق من business_id في كل قراءة مقصود: جلسة قديمة قد تحمل معرّف
     * موظف نُقل أو حُذف أو يخصّ متجرًا آخر، فلا يكفي أن نثق بما في الجلسة.
     */
    public static function current(): ?User
    {
        $id = session(self::KEY);
        if (! $id) {
            return null;
        }

        $user = self::selectable()->firstWhere('id', (int) $id);

        if (! $user) {
            session()->forget(self::KEY);
        }

        return $user;
    }

    /** يضبط الموظف الحالي، ويرفض من ليس من موظفي هذا المتجر */
    public static function set(int $id): ?User
    {
        $user = self::selectable()->firstWhere('id', $id);

        if ($user) {
            session([self::KEY => $user->id]);
        }

        return $user;
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * من يظهر في قائمة الاختيار: موظفو هذا المتجر النشطون.
     *
     * يُستبعد مدير المنصّة وصاحب النشاط/المدير — لأن الغرض من الشاشة أصلًا
     * ألّا تُنسب المبيعات إلى صاحب النشاط. وإن لم يكن في المتجر موظفٌ بعد،
     * ترجع القائمة فارغة وتتكفّل الشاشة بإرشاده إلى إضافة موظف.
     */
    public static function selectable()
    {
        return User::where('business_id', Demo::bid())
            ->whereNotIn('role', ['super_admin', 'admin'])
            ->where('status', 'نشط')
            ->orderBy('name')
            ->get();
    }

    /**
     * هل تُطلب شاشة الاختيار قبل البيع؟
     *
     * لا تُطلب إن لم يكن في المتجر موظفٌ يُختار: متجرٌ يديره صاحبه وحده كان
     * سيقف أمام شاشة فارغة لا يتجاوزها — أي أن الميزة تُعطّل الصندوق بدل أن
     * تنظّمه. حين يُضاف أول موظف تبدأ الشاشة بالظهور من تلقائها.
     */
    public static function required(): bool
    {
        if (self::current()) {
            return false;
        }

        // الكاشير الداخل بحسابه هو نفسه الواقف على الصندوق — سؤاله «من على
        // الصندوق؟» شاشةٌ زائدة عند كل فتح، والسؤال أصلًا موجَّه إلى صاحب
        // النشاط الذي يفتح الصندوق نيابةً عن غيره.
        if (self::selectable()->contains('id', auth()->id())) {
            return false;
        }

        return self::selectable()->isNotEmpty();
    }

    /** الاسم الذي يُكتب على الطلب والحركة والمعاملة */
    public static function name(): string
    {
        /*
         * بلا مستخدمٍ مسجَّل يبقى اسمٌ محايد لا خطأ فادح.
         *
         * كانت تقرأ `auth()->user()->name` مباشرةً، فأيّ استدعاءٍ من أمرٍ
         * مجدول أو عاملِ طابور — لا جلسة فيه — يُسقط العملية كلّها بـ«قراءة
         * خاصيّة من null» بدل أن يكتب اسمًا مجهولًا في سطرٍ واحد.
         */
        return self::current()?->name ?? auth()->user()?->name ?? __('غير معروف');
    }

    /** معرّف الموظف للطلب — يُبنى عليه تقرير أداء الموظفين */
    public static function id(): ?int
    {
        return self::current()?->id ?? auth()->id();
    }
}
