<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobTitle extends Model
{
    protected $guarded = [];

    /** أدوار النظام المسموح ربط الوظيفة بها (تحدّد صلاحيات الدخول) */
    public const ROLES = [
        'manager' => 'مدير',
        'cashier' => 'كاشير',
        'sales' => 'موظف مبيعات',
        'accountant' => 'محاسب',
        'inventory' => 'مسؤول مخزون',
        'delivery' => 'مندوب توصيل',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
