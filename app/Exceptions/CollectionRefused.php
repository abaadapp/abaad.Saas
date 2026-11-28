<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * تحصيلٌ رُفض لأنّه يتجاوز ما على الفاتورة — يُقال للتاجر بسببه ولا يُكتب شيء.
 *
 * فاتورةٌ مدفوعةٌ كلُّها، أو مبلغٌ أكبرُ من باقيها، أو فاتورةٌ لم تعد صادرة:
 * الحكمُ يقع تحت قفل صفّ الفاتورة وفي معاملة التحصيل نفسِها
 * (`CustomerPayments::allocate`)، فلا يُقرأ باقٍ ثمّ يتغيّر قبل الكتابة.
 *
 * وهو صنفٌ خاصٌّ لِما قاله `SettlementRefused`: يُعرض سببُه، وخطأُ القاعدة يصعد.
 */
class CollectionRefused extends RuntimeException {}
