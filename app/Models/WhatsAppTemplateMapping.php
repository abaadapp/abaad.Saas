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
        // نصُّ BODY المعتمَد لكلّ لغة — من مزامنة ميتا (`WhatsAppTemplates::sync`)
        'body_by_language' => 'array',
        'meta_synced_at' => 'datetime',
    ];

    /**
     * بأيّ لغةٍ يُرسَل هذا القالبُ لزبونٍ لغتُه `$wanted`؟
     *
     * لغتُه إن كانت ميتا قد اعتمدت القالبَ بها — وإلّا لغةُ القالب الأصل.
     * فلا يُطلب من ميتا ما لم تعتمده فتردّه بـ`132001` وتُقيَّد الرسالةُ
     * فاشلةً على زبونٍ كان يقبل العربيّة.
     *
     * ولغةٌ بإقليمها («en-US»، «ar_OM») تُقرأ بأصلها: يُختار المعتمَدُ بالأصل نفسِه.
     * وكانت تُقارَن حرفيًّا، فيصل زبونًا لغتُه `en-US` قالبٌ عربيّ وإنجليزيُّه معتمَد.
     */
    public function languageFor(?string $wanted): string
    {
        $own = (string) $this->language_code;
        $wanted = trim((string) $wanted);

        if ($wanted === '' || $wanted === $own) {
            return $own;
        }

        $approved = array_map('strval', (array) ($this->approved_languages ?? []));

        if (in_array($wanted, $approved, true)) {
            return $wanted;
        }

        $base = self::baseLanguage($wanted);

        foreach ([$own, ...$approved] as $language) {
            if (self::baseLanguage($language) === $base) {
                return $language;
            }
        }

        return $own;
    }

    /** «en-US» و«en_US» و«EN» كلُّها `en` */
    private static function baseLanguage(string $language): string
    {
        return strtolower(explode('-', str_replace('_', '-', trim($language)))[0]);
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function scopePlatform($query)
    {
        return $query->where('scope_type', WhatsAppMode::OWNER_PLATFORM)->whereNull('business_id');
    }
}
