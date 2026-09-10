<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إذنُ متجرٍ على ملفّه في Google Business Profile.
 *
 * والرمزان معمَّيان في العمود لا مقروءَين: من نسخ القاعدة لا ينسخ معها إذنًا
 * يردّ باسم التاجر على زبائنه. و`$hidden` حارسٌ ثانٍ حتّى لا يتسرّبا في
 * `toArray` — وهي طريق خصائص Inertia كلِّها إلى المتصفّح.
 */
class GoogleBusinessAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'linked_at' => 'datetime',
        'revoked_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** موصولٌ: لم يُفصل، وله رمزُ تجديد */
    public function isLive(): bool
    {
        return $this->revoked_at === null && filled($this->refresh_token);
    }

    /**
     * هل انتهى رمزُ الوصول؟
     *
     * وبدقيقةٍ احتياطًا: رمزٌ يبقى له عشرُ ثوانٍ ينتهي بين فحصِنا ووصولِ
     * الطلب إلى Google — فيُردّ بـ٤٠١ ويُقيَّد عطلًا لا وجود له.
     */
    public function tokenExpired(): bool
    {
        return $this->token_expires_at === null
            || $this->token_expires_at->lt(now()->addMinute());
    }
}
