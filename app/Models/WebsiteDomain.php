<?php

namespace App\Models;

use App\Support\Website\Domains;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عنوانٌ يفتح موقعَ تاجر — واحدٌ من عنوانَيه أو أكثر.
 *
 * والصفُّ يحمل حالَ الربط لا نصَّه وحده: أوصل التوجيه؟ متى تُحقّق منه آخر
 * مرّة؟ وما الذي لم يصحّ إن لم يصحّ؟ فالتاجر يقرأ في لوحته جوابًا بدل أن
 * يراسل الدعم، والدعم يقرأ الجواب نفسه بدل أن يسأله.
 */
class WebsiteDomain extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function website(): BelongsTo { return $this->belongsTo(Website::class); }

    public function isPlatform(): bool
    {
        return $this->type === Domains::PLATFORM;
    }

    public function isActive(): bool
    {
        return $this->status === Domains::ACTIVE;
    }

    /** العنوان كاملًا كما يُفتح */
    public function url(): string
    {
        return 'https://'.$this->normalized_hostname;
    }

    /**
     * حالُ الربط بكلمةٍ يفهمها صاحبُ المحلّ.
     *
     * ولا تُقال بلغة النظام: «pending» و«DNS» و«SSL» كلماتٌ تُخيف من لا
     * يعرفها وتدفعه إلى الدعم. والحالُ أربع لأنّ أفعالَ التاجر أربعة:
     * ينتظر، أو يوجّه سجلَّه، أو لا يفعل شيئًا، أو يراسلنا.
     */
    public function label(): string
    {
        return match ($this->status) {
            Domains::ACTIVE => __('متصل'),
            Domains::VERIFYING => __('جارٍ الربط'),
            Domains::FAILED => __('يحتاج إجراء'),
            default => __('بانتظار التوجيه'),
        };
    }
}
