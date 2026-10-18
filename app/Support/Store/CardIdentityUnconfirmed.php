<?php

namespace App\Support\Store;

use Illuminate\Validation\ValidationException;

/**
 * رسالةُ البطاقة تبدو حاملةً اسمَ من أخفى اسمَه — ولم يُقرّ بها بعد.
 *
 * ردُّ تحقّقٍ كسائر ردوده (فلا طلبَ ولا دفعة)، ومعه ما تحتاجه الصفحة لتسأل:
 * الرمزُ الذي يُرسَل إن اختار «أرسلها كما هي»، ومن أين جاء النصّ ليُفتح
 * محرّرُه إن اختار «تعديل الرسالة». ولا شيءَ من الرسالة نفسِها.
 * انظر `CardIdentity`.
 */
final class CardIdentityUnconfirmed extends ValidationException
{
    public string $token = '';

    public string $source = CardIdentity::FROM_CARD;

    public static function offer(string $token, string $source): self
    {
        $e = self::withMessages([
            CardIdentity::FIELD => __('رسالة البطاقة قد تحتوي على اسمك أو رقمك مع أنك اخترت إخفاء اسم المرسل — عدّلها أو أكّد إرسالها كما هي.'),
        ]);
        $e->token = $token;
        $e->source = $source;

        return $e;
    }
}
