<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * بيعةٌ رُدّت لأنّ مبلغَها في الخادم غيرُ ما قرأه الكاشيرُ على الشاشة.
 *
 * لم يُكتب شيء: لا طلب ولا نقاط ولا فرصةُ كوبون. والمبلغُ الصحيح يُردّ معه
 * (`$totals`) فيؤكّده الكاشيرُ قبل أن يُقبض — انظر `PosController::assertScreenTotal`.
 */
class TotalChanged extends ValidationException
{
    /** @var array{total: float, shown: float, stale_screen: bool} */
    public array $totals = [];
}
