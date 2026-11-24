<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ‏‏طلبُ حذف بياناتٍ أرسلته Meta باسم صاحب حساب إنستقرام — برمز تأكيدٍ وصفحةِ حال.
 */
class InstagramDataDeletion extends Model
{
    public const RECEIVED = 'received';

    public const COMPLETED = 'completed';

    protected $guarded = [];

    protected $casts = ['completed_at' => 'datetime'];
}
