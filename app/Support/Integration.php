<?php

namespace App\Support;

/**
 * شكلُ خطوةِ الربط — واحدٌ لكلّ أداةٍ تُربط بأبعاد.
 *
 * واتساب وخرائط Google وما يأتي بعدهما تُربط كلُّها بالطريقة نفسها: بابٌ
 * مغلقٌ حتى تُفتح مراحلُه بترتيبها، ولكلّ مرحلةٍ حالٌ وسببٌ ومن يملك إصلاحها.
 * فلو كتبت كلُّ أداةٍ شكلَها لَاختلفت الشاشتان في اسم حقلٍ أو في معنى علامة،
 * ولَصار على الواجهة أن تعرف كلَّ أداةٍ على حدة.
 *
 * وأهمُّ ما في الشكل حقلُ `theirs`: هل هذه خطوةٌ ينتظر فيها التاجر أبعاد، أم
 * خطوةٌ بيده؟ خلطُهما يجعله ينتظر ما عليه أن يفعله، أو يحاول ما لا يملكه.
 */
class Integration
{
    /**
     * خطوةٌ واحدة.
     *
     * @param  bool  $theirs  خطوةٌ على أبعاد لا على التاجر — فلا تُقال بصيغة الأمر
     * @return array{key:string, label:string, done:bool, detail:?string, fix:?string, theirs:bool}
     */
    public static function step(
        string $key,
        string $label,
        bool $done,
        ?string $detail = null,
        ?string $fix = null,
        bool $theirs = false,
    ): array {
        return [
            'key' => $key,
            'label' => __($label),
            'done' => $done,
            'detail' => $done ? $detail : null,
            // وما تمّ لا يُقال كيف يُصلَح — نصيحةٌ تحت خطوةٍ مكتملة ضجيج
            'fix' => $done ? null : ($fix === null ? null : __($fix)),
            'theirs' => $theirs,
        ];
    }

    /**
     * حمولةُ الأداة كما تقرؤها الشاشة.
     *
     * و`connected` غير `ready`: الأوّل يعني أنّ التاجر بدأ — ضغط «ربط» وقطع
     * شوطًا — والثاني أنّ كلّ شيءٍ تمّ. والبوّابة تُقاس بالأوّل: من لم يبدأ
     * يرى أيقونةً وزرًّا لا قائمةَ مراحلَ لم يطلبها.
     *
     * @param  list<array{key:string, label:string, done:bool, detail:?string, fix:?string, theirs:bool}>  $steps
     * @return array{connected:bool, ready:bool, steps:list<array<string,mixed>>}
     */
    public static function payload(bool $connected, array $steps): array
    {
        return [
            'connected' => $connected,
            'ready' => $steps !== [] && ! collect($steps)->contains(fn ($s) => ! $s['done']),
            'steps' => $steps,
        ];
    }
}
