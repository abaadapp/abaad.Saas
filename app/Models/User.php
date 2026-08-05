<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

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
        return [
            'super_admin' => __('مدير المنصة'),
            'admin' => __('مدير'),
            'manager' => __('مدير'),
            'cashier' => __('كاشير'),
            'sales' => __('موظف مبيعات'),
            'accountant' => __('محاسب'),
            'inventory' => __('مسؤول مخزون'),
            'delivery' => __('مندوب توصيل'),
        ][$this->role] ?? $this->role;
    }
}
