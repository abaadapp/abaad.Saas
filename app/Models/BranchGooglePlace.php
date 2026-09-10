<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربطُ فرعٍ بملفّه على خرائط Google.
 *
 * ولا نصَّ تقييمٍ في هذا الصفّ ولا في هذا الجدول: شروطُ Google تمنع الاحتفاظ
 * بمحتوى الأماكن. المحفوظُ معرّفٌ واسمٌ ورقمان — والنصوص تُسحب حيّةً وتسقط.
 */
class BranchGooglePlace extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating' => 'float',
        'review_count' => 'integer',
        'synced_at' => 'datetime',
        'linked_at' => 'datetime',
        'unlinked_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /** المربوطُ الآن — والمفكوكُ يبقى صفًّا للأثر لا للقراءة */
    public function scopeLinked(Builder $q): Builder
    {
        // مُقيَّدٌ باسم جدوله: تُستعمل بعد ضمٍّ مع `branches`
        return $q->whereNull('branch_google_places.unlinked_at');
    }

    public function isLinked(): bool
    {
        return $this->unlinked_at === null;
    }
}
