<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تقييمٌ على ملفّ المتجر — مسحوبٌ بإذن صاحبه ليُدار.
 *
 * و`reply` هنا هو الردُّ **عند Google** لا ما كُتب في شاشتنا: لا يُكتب إلا
 * بعد أن تردّ Google بالقبول. وردٌّ يُعرض «منشورًا» ولم يُنشر يجعل التاجر
 * يظنّ أنّه أجاب زبونًا لم يصله شيء.
 */
class GoogleBusinessReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
        'reviewed_at' => 'datetime',
        'updated_google_at' => 'datetime',
        'replied_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'gone_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** القائمُ عند Google — والمحذوفُ يبقى صفًّا ولا يُعرض */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNull('gone_at');
    }

    public function hasReply(): bool
    {
        return filled($this->reply);
    }
}
