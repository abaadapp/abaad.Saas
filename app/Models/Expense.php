<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    // قيدٌ مالي لا يُمحى بضغطة — انظر الهجرة add_soft_deletes_to_products_and_expenses
    use SoftDeletes;

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'spent_at' => 'date'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
