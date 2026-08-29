<?php

namespace App\Models;

use App\Support\WhatsAppMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** اسم القالب المعتمَد عند ميتا لكلّ حدث — قوالب أبعاد، وقوالب كلّ محلّ */
class WhatsAppTemplateMapping extends Model
{
    protected $table = 'whatsapp_template_mappings';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'variable_mapping' => 'array'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function scopePlatform($query)
    {
        return $query->where('scope_type', WhatsAppMode::OWNER_PLATFORM)->whereNull('business_id');
    }
}
