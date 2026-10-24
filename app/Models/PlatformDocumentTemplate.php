<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قالبُ مستندٍ لخدمةٍ من خدمات أبعاد — يكتبه مدير المنصّة.
 *
 * بنودُه `[{h, b}]`: عنوانُ البند ونصُّه. و`includes_plan` يضع في المستند
 * الباقةَ ودورتَها وسعرَها من باقات النظام، و`includes_amount` مبلغًا مقطوعًا
 * لخدمةٍ لا باقةَ لها (تخصيص موقع، تطبيق، أجهزة).
 */
class PlatformDocumentTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['terms' => 'array', 'includes_plan' => 'boolean', 'includes_amount' => 'boolean', 'active' => 'boolean'];

    public function documents(): HasMany
    {
        return $this->hasMany(PlatformDocument::class, 'template_id');
    }
}
