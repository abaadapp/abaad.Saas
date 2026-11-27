<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * خصمُ النقاط الذي وافق عليه الكاشير لم يعد هو ما يُعتمد — فلا تُكتب البيعة.
 *
 * الصندوقُ يحسب العرضَ من رصيدٍ وقواعدَ قرأها حين فُتحت الشاشة. وبين ذلك
 * والدفع قد يُصرف الرصيدُ في صندوقٍ آخر أو فاتورة، أو يغيّر صاحبُ المحلّ
 * القواعد. فيُردّ الطلبُ بلا كتابة، ومعه العرضُ الصحيح الآن (`$offer`):
 * الرصيدُ، وأقصى ما يُستبدل وقيمتُه، والقواعدُ كما يقرؤها الصندوق — فيسأل
 * الكاشيرُ من جديد قبل أن يُصدر الفاتورة. انظر `PosController::checkout`.
 *
 * ولا يقع على بيعةٍ اكتملت ووصلت بعد انقطاع: تلك لا تُردّ (`replay`).
 */
class RedemptionChanged extends ValidationException
{
    /** @var array{balance: int, points: int, discount: float, reason: ?string, settings: array<string, mixed>} */
    public array $offer = [];
}
