<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * ═══ أعمدةٌ متقاعدة في هذا الجدول ═══
 *
 * تبقى في القاعدة بما فيها — لا تُقرأ ولا تُكتب — حتى تُحذفها مهاجرةٌ صريحة.
 * وذِكرُها هنا كي لا يُبنى عليها حسابٌ جديد ظنًّا أنّها حيّة:
 *
 * - `sales_total` — لا بيعةٌ تزيده ولا وردية. مبيعاتُ الموظّف تُحسب من
 *   الطلبات (انظر `Demo::employees`).
 * - `commission_rate` — نسبةٌ كان التاجر يُدخلها ولا يُصرف منها شيء: لا
 *   مسيرةَ رواتبَ تقرؤها ولا كشفَ عمولةٍ في النظام. رُفع حقلُها من شاشة
 *   الموظّف كي لا تَعِد لافتةٌ بما لا يقع.
 * - `pin` — رُفع الدخولُ بالرمز؛ انظر التعليق في `casts`.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'pin'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            /*
             * ختم بريد الاستعادة.
             *
             * وجودُه هو الفرق بين عنوانٍ مكتوب وعنوانٍ يُرسَل إليه رمزُ
             * استعادة — انظر App\Support\RecoveryEmail::verifiedFor.
             */
            'recovery_email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            /*
             * `pin` عمودٌ متقاعد: رُفع الدخول بالرمز، فلا يُقرأ ولا يُكتب في
             * شيء. يبقى العمود بما فيه — لا حاجة إلى محو بيانات — ويبقى
             * مبصومًا ومخفيًّا كما كان حتى يُحذف بمهاجرةٍ صريحة.
             */
            'pin' => 'hashed',
            'sales_total' => 'decimal:3',
            'permissions' => 'array',
        ];
    }

    /**
     * هل لهذا الحساب بريد استعادةٍ مختوم؟
     *
     * تُقرأ في الشاشات لتُعرض الحال، وفي الاستعادة ليُقرَّر الطريق.
     */
    public function hasVerifiedRecoveryEmail(): bool
    {
        return \App\Support\RecoveryEmail::verifiedFor($this) !== null;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * العنوان الذي يصل إليه بريدٌ فعلًا — لا الذي يُكتب في خانة الدخول.
     *
     * حسابات التجّار على نطاق داخلي (@abaadapp.om) لا صناديق بريدٍ خلفه:
     * اسم دخولٍ يُملى في الهاتف لا عنوانٌ يُراسَل. فلو أُرسل رابط استعادة
     * كلمة المرور إليه لسقط في العدم، وقال النظام «أرسلنا» ولم يصل شيء —
     * وهو أسوأ من ألّا يكون هناك استعادة أصلًا: انتظارٌ بلا نهاية.
     *
     * فيُرسَل إلى بريد التواصل المسجَّل للمتجر. ومن كان بريده حقيقيًّا
     * (الموظفون، مدير المنصة) يصله على بريده هو.
     */
    public function contactEmail(): ?string
    {
        if (! str_ends_with(mb_strtolower((string) $this->email), \App\Support\MerchantAccount::DOMAIN)) {
            return $this->email;
        }

        return $this->business?->email ?: null;
    }

    /** الفروع المسموح للموظف بالعمل فيها — انظر worksAt() */
    public function branches(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * هل يعمل هذا الموظف في هذا الفرع؟
     *
     * الفراغ يعني «كل فروع متجره»، لا «لا فرع». موظفوك الحاليون كلّهم بلا
     * صفوف في جدول الإذن، فلو كان الفارغ منعًا لأُقفل كل كاشير صباح النشر —
     * ترقيةٌ تُوقف المحلّ ليست ترقية. ومن يُحدَّد له فرعٌ واحد يُمنع مما عداه.
     *
     * والفحص مقيَّد بالمتجر أيضًا: فرعٌ من متجر الجار مرفوض ولو ورد في الصفوف
     * (وهو ما لا يقع إلا بعبثٍ مباشر في القاعدة — والفحص أرخص من الثقة).
     */
    public function worksAt(?int $branchId): bool
    {
        if (! $branchId) {
            return false;
        }

        if (! Branch::where('id', $branchId)->where('business_id', $this->business_id)->exists()) {
            return false;
        }

        $allowed = $this->branches()->pluck('branches.id');

        return $allowed->isEmpty() || $allowed->contains($branchId);
    }

    /**
     * رابط الصورة الرمزية: يدعم الروابط الخارجية والملفات المرفوعة.
     *
     * ═══ ولا يُخترع وجهٌ لإنسان ═══
     *
     * كان يردّ رابط `picsum.photos` — خدمةَ صورٍ عشوائيّة على الإنترنت —
     * لكلّ من لم يرفع صورته. وهذا أعمقُ موضعٍ للعطب لا أوسعَه فحسب: العمود
     * يُقرأ من هنا في كلّ شاشة، فيظهر الوجهُ المستعار في الشريط العلويّ،
     * وفي «من أنا» بنقطة البيع، وفي شاشة اختيار الكاشير، وفي ملفّ الموظّف —
     * ووجهُ إنسانٍ لا يعرفه أحد فوق اسم صاحب المحلّ.
     *
     * وكان يُبطل كلّ بديلٍ كُتب فوقه: `$u->avatar ?? …` لا تقع أبدًا، ولا
     * `{avatar ? … : …}` في الشاشات — لأنّ هذا السطر لا يُرجع فارغًا قطّ.
     *
     * ويُطلب من خادمٍ خارجيّ في كلّ فتحةِ شاشة: بطيءٌ، وينكسر إن حُجب، ويُخبر
     * طرفًا ثالثًا بمن يتصفّح. وهو العطبُ نفسه الذي رُفع من `Product::image`.
     *
     * فرُدّ الفراغُ فراغًا، ووقعت الحالاتُ المكتوبة: الحرفُ الأول من الاسم.
     */
    public function getAvatarAttribute($value): string
    {
        if (! $value) {
            return '';
        }
        if (str_starts_with($value, 'http')) {
            return $value;
        }
        return \Illuminate\Support\Facades\Storage::url($value);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /**
     * هل يملك المستخدم صلاحية القسم؟
     *
     * قائمةٌ يدوية على الموظف تسبق دوره. NULL تعني «اتبع الدور» — وهو
     * الافتراضي، فالموظفون الذين لم تُخصَّص لهم صلاحيات يبقون كما كانوا.
     *
     * ولا استثناء: القائمة اليدوية هي كل ما يملكه صاحبها. حتى لوحة التحكم
     * ونقطة البيع والفروع تُمنح صراحةً — ما لم يُعلَّم لا يُفتح.
     */
    public function allows(string $section): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $manual = $this->permissions;

        if (is_array($manual)) {
            return in_array($section, $manual, true);
        }

        return \App\Support\Permissions::allows($this->role, $section);
    }

    /**
     * هل يفعل هذا الفعل؟ — سؤالٌ عن الضرر لا عن الشاشة.
     *
     * و`allows` لا تصلح له: من مُنح «نقطة البيع» ليبيع لم يُمنح بها أن يعيد
     * كتابة فاتورةٍ صدرت. والمفتاحان في العمود نفسه ولا يختلطان — انظر
     * `Permissions::isAction`.
     */
    public function may(string $action): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $manual = $this->permissions;

        if (is_array($manual)) {
            return in_array($action, $manual, true);
        }

        return \App\Support\Permissions::allowsAction($this->role, $action);
    }

    /** هل صلاحياته مخصَّصة يدويًّا أم موروثة من الدور؟ */
    public function hasManualPermissions(): bool
    {
        return is_array($this->permissions);
    }

    /** الاسم العربي للدور */
    public function roleLabel(): string
    {
        return \App\Support\Roles::label($this->role);
    }
}
