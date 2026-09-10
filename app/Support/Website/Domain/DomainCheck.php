<?php

namespace App\Support\Website\Domain;

/**
 * جوابُ سؤالٍ واحد: أيعمل هذا العنوان؟
 *
 * وثلاثةُ أجوبةٍ لا اثنان. «لا» وحدها تجعل الشاشة تقول «فشل الربط» لمن
 * أضاف سجلَّه قبل دقيقتين ولم ينتشر بعد — فيحذفه ويعيده ويظنّ أنّ عندنا
 * عطبًا. و«لم يصل بعد» تقول له الصواب: انتظر.
 */
final class DomainCheck
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {}

    /** وصل التوجيه وجهزت الشهادة — العنوان يفتح */
    public static function active(): self
    {
        return new self(\App\Support\Website\Domains::ACTIVE);
    }

    /** السجلّ صحيحٌ ولم ينتشر بعد، أو الشهادة تُصدَر */
    public static function waiting(?string $reason = null): self
    {
        return new self(\App\Support\Website\Domains::VERIFYING, $reason);
    }

    /** لا يشير إلينا — والسببُ يُقال للتاجر */
    public static function failed(string $reason): self
    {
        return new self(\App\Support\Website\Domains::FAILED, $reason);
    }
}
