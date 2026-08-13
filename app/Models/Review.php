<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تقييم عميل.
 *
 * يبدأ معلّقًا ولا يُنشر إلا بقرار: تقييمٌ يظهر على الموقع لحظة كتابته يجعل
 * صفحة المنتج بابًا مفتوحًا لأي رسالةٍ يكتبها أيّ أحد.
 */
class Review extends Model
{
    protected $guarded = [];

    protected $casts = ['rating' => 'integer', 'replied_at' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    /** الاسم المعروض: العميل المسجَّل، أو ما كتبه الزائر، أو مجهول */
    public function displayName(): string
    {
        return $this->customer?->name ?: ($this->author_name ?: __('زائر'));
    }
}
