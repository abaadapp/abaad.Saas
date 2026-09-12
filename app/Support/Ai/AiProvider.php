<?php

namespace App\Support\Ai;

/**
 * مزوّدُ النموذج — واجهةٌ واحدة، وتبديلُه سطرٌ في البيئة.
 *
 * ولمَ واجهة: النداءُ المباشر في المتحكّم يعني أنّ تبديلَ المزوّد يمسّ كلَّ
 * موضعٍ يسأل — ويعني أنّ الاختبار لا يستطيع أن يسأل بلا شبكة.
 */
interface AiProvider
{
    /**
     * @param  string  $system  التعليماتُ التي لا يُطاع ما يخالفها
     * @param  list<array{role:string,content:string}>  $messages
     */
    public function complete(string $system, array $messages): AiReply;

    /** هل المزوّد صالحٌ للنداء فعلًا؟ — ومفتاحٌ ناقصٌ ليس صلاحًا */
    public function ready(): bool;

    public function name(): string;
}
