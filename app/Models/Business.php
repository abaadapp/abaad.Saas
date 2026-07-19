<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date'];

    /** رابط الشعار: يدعم الروابط الخارجية والملفات المرفوعة */
    public function getLogoAttribute($value): string
    {
        if (! $value) {
            return 'https://picsum.photos/seed/biz' . $this->id . '/120/120';
        }
        if (str_starts_with($value, 'http')) {
            return $value;
        }
        return \Illuminate\Support\Facades\Storage::url($value);
    }

    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function branches(): HasMany { return $this->hasMany(Branch::class); }
    public function categories(): HasMany { return $this->hasMany(Category::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function expenses(): HasMany { return $this->hasMany(Expense::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
}
