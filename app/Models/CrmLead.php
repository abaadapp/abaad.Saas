<?php

namespace App\Models;

use App\Support\Crm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * عميلٌ محتمَلٌ لأبعاد — تاجرٌ لم يشترِ بعد.
 *
 * وهو **ليس** زبونَ متجر: أولئك في `customers` تحت `business_id` ولا سبيلَ
 * من هنا إليهم. انظر `CrmStaysOutOfTenantDataTest`.
 */
class CrmLead extends Model
{
    protected $table = 'crm_leads';

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'branches_count' => 'integer',
        'expected_value' => 'decimal:3',
        'assigned_at' => 'datetime',
        'first_contact_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'converted_at' => 'datetime',
        'whatsapp_window_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'interested_plan_id');
    }

    /** المتجرُ الذي صارَه — لا نسخةٌ منه */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'converted_business_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CrmNote::class, 'lead_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CrmTask::class, 'lead_id');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(CrmStageEvent::class, 'lead_id');
    }

    /** خيطُ واتساب — والعميلُ هو الخيط، فلا جدولَ محادثاتٍ بينهما */
    public function messages(): HasMany
    {
        return $this->hasMany(CrmMessage::class, 'lead_id');
    }

    /** ما لم يُحسم بعد */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', Crm::ACTIVE);
    }

    /**
     * ما يُعرض اسمًا — ولا يُخترع.
     *
     * بلا اسمٍ يُعرض الرقم: سطرٌ فارغٌ في القائمة لا يُنقر، و«عميل محتمل»
     * مكرَّرةً في عشرين صفًّا لا تُفرّق بينها.
     */
    public function displayName(): string
    {
        return (string) ($this->name ?: $this->business_name ?: ($this->phone_raw ?: $this->phone));
    }

    /** متأخّرُ المتابعة — موعدٌ مضى ولم يُحسم الصفّ */
    public function followUpOverdue(): bool
    {
        return $this->status === Crm::ACTIVE
            && $this->next_follow_up_at !== null
            && $this->next_follow_up_at->isPast();
    }
}
