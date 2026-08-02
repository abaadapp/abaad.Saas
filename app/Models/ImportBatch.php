<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'undone_at' => 'datetime',
    ];

    /** آخر استيراد قابل للتراجع لنشاطٍ — واحد فقط: التراجع خطوة للخلف لا سجلّ */
    public static function lastUndoable(int $businessId, string $type = 'products'): ?self
    {
        return static::where('business_id', $businessId)
            ->where('type', $type)
            ->whereNull('undone_at')
            ->latest('id')
            ->first();
    }
}
