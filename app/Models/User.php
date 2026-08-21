<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'pin'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'sales_total' => 'decimal:3',
            'permissions' => 'array',
        ];
    }

    /** هل لدى المستخدم رمز دخول سريع (٤ أرقام) */
    public function hasPin(): bool
    {
        return ! empty($this->getRawOriginal('pin'));
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

    /** رابط الصورة الرمزية: يدعم الروابط الخارجية والملفات المرفوعة */
    public function getAvatarAttribute($value): string
    {
        if (! $value) {
            return 'https://picsum.photos/seed/user' . $this->id . '/100/100';
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
