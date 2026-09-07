<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** تحصيلٌ من عميل — مستندٌ قائمٌ بذاته يُخصَّص على فاتورةٍ أو أكثر */
class CustomerPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    public function scopeLive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    /** ما وُزّع منها على فواتير */
    public function allocatedTotal(): float
    {
        return round((float) $this->allocations()->sum('amount'), 3);
    }

    /** ما بقي منها رصيدًا للعميل — دفعةٌ زادت عن فواتيره لا تضيع */
    public function unallocated(): float
    {
        return round((float) $this->amount - $this->allocatedTotal(), 3);
    }
}
