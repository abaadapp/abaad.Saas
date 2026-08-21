<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobTitle extends Model
{
    protected $guarded = [];

    /** أدوار النظام المسموح ربط الوظيفة بها (تحدّد صلاحيات الدخول) */
    /** أدوار الموظّفين — مصدرها الوحيد `App\Support\Roles` */
    public static function roles(): array
    {
        return \App\Support\Roles::staffLabels();
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
