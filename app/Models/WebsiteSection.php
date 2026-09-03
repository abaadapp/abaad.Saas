<?php

namespace App\Models;

use App\Support\Website\Sections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قسمٌ في صفحة — أو قسمٌ عامٌّ في كلّ صفحة.
 *
 * `type` مفتاحُه في مكتبة الأقسام، و`data` محتواه كما يصفه وصفُ نوعه. ولا
 * يُكتب في `data` إلا ما مرّ بـ`Content::clean` — انظره لِمَ.
 */
class WebsiteSection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'visible' => 'boolean',
    ];

    public function website(): BelongsTo { return $this->belongsTo(Website::class); }

    public function page(): BelongsTo { return $this->belongsTo(WebsitePage::class, 'page_id'); }

    public function label(): string
    {
        return Sections::label($this->type);
    }

    /** من أين يقرأ محتواه — أو null إن كتبه التاجر كلَّه */
    public function source(): ?string
    {
        return Sections::source($this->type);
    }
}
