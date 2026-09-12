<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مهمّةُ متابعةٍ على عميلٍ محتمَل — بموعدٍ دائمًا، وإلّا لم تتأخّر قطّ */
class CrmTask extends Model
{
    protected $table = 'crm_tasks';

    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function overdue(): bool
    {
        return $this->status === 'open' && $this->due_at->isPast();
    }
}
