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

    /*
     * و`meta_synced_at` تاريخٌ لا نصّ.
     *
     * بلا التحويل يعود سلسلةً من القاعدة، فـ`->format()` عليها تكسر — وهو
     * ما وقع أوّلَ مرّةٍ قُرئ فيها العمود.
     */
    protected $casts = [
        'enabled' => 'boolean',
        'variable_mapping' => 'array',
        'approved_languages' => 'array',
        'meta_synced_at' => 'datetime',
    ];

    /**
     * بأيّ لغةٍ يُرسَل هذا القالبُ لزبونٍ لغتُه `$wanted`؟
     *
     * لغتُه إن كانت ميتا قد اعتمدت القالبَ بها — وإلّا لغةُ القالب الأصل.
     * فلا يُطلب من ميتا ما لم تعتمده فتردّه بـ`132001` وتُقيَّد الرسالةُ
     * فاشلةً على زبونٍ كان يقبل العربيّة.
     */
    public function languageFor(?string $wanted): string
    {
        $own = (string) $this->language_code;

        if ($wanted === null || $wanted === '' || $wanted === $own) {
            return $own;
        }

        return in_array($wanted, (array) ($this->approved_languages ?? []), true) ? $wanted : $own;
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function scopePlatform($query)
    {
        return $query->where('scope_type', WhatsAppMode::OWNER_PLATFORM)->whereNull('business_id');
    }
}
