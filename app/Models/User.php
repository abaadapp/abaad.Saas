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

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'sales_total' => 'decimal:3',
        ];
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

    /** هل يملك المستخدم صلاحية القسم؟ */
    public function allows(string $section): bool
    {
        return \App\Support\Permissions::allows($this->role, $section);
    }

    /** الاسم العربي للدور */
    public function roleLabel(): string
    {
        return [
            'super_admin' => 'مدير المنصة',
            'admin' => 'مدير',
            'manager' => 'مدير',
            'cashier' => 'كاشير',
            'sales' => 'موظف مبيعات',
            'accountant' => 'محاسب',
            'inventory' => 'مسؤول مخزون',
            'delivery' => 'مندوب توصيل',
        ][$this->role] ?? $this->role;
    }
}
