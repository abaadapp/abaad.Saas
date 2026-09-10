<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محادثةُ دعمٍ بين متجرٍ وأبعاد.
 *
 * وهي **ليست** محادثةَ المتجر مع زبائنه: تلك في `WhatsAppMessage` تحت
 * `business_id` ولا سبيلَ من هنا إليها — انظر `SupportPrivacyTest`.
 */
class SupportConversation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'assigned_at' => 'datetime',
        'last_message_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'external_meta' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(SupportRead::class, 'conversation_id');
    }

    /**
     * ما يراه صاحبُ المتجر — وما لا يراه.
     *
     * والترشيحُ هنا لا في كلّ استعلامٍ يكتبه متحكّم: شرطٌ مكرَّرٌ في خمسة
     * مواضع يُنسى في السادس، والمنسيُّ هنا ملاحظةٌ داخليّةٌ تصل صاحبَها.
     */
    public function businessMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }

    /** المفتوحةُ عند الدعم — ما لم تُحلّ ولم تُغلق */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNotIn('status', ['resolved', 'closed']);
    }
}
