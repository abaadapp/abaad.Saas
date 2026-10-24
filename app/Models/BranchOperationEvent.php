<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * صفٌّ في سجلّ مراحل سند النقل أو طلب التزويد — يُضاف ولا يُعدَّل.
 */
class BranchOperationEvent extends Model
{
    public const TRANSFER = 'transfer';

    public const SUPPLY = 'supply';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['data' => 'array'];

    /** السجلُّ يُكتب مرّةً ولا يُعاد كتابتُه — سطرٌ يُعدَّل يُخفي ما جرى */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
