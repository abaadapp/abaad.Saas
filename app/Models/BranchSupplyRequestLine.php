<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchSupplyRequestLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_qty' => 'integer',
        'approved_qty' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(BranchSupplyRequest::class, 'branch_supply_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }
}
