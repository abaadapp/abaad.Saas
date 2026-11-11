<?php

namespace Tests\Support;

use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Support\CustomerInvoices;
use ReflectionMethod;

/**
 * إشعارُ إرجاعٍ يدويّ — كما يكتبه التاجر من شاشة الفاتورة.
 *
 * طلبُ #202 يجعل سببَ الإشعار اليدويّ إلزاميًّا (`basis`)، وما قبله لا يعرفه.
 * فيُسأل هنا إن كانت `creditNote` تأخذه، فتجري اختباراتُ النقاط نفسُها قبل
 * دمجه وبعده بلا تعديل. والسببُ «إرجاع» لأنّه ما تختبره: بضاعةٌ رُدّت فتعود
 * نقاطُها بنسبة ما رُدّ.
 *
 * ورفضُ الإشعار يأتي `RuntimeException` قبل #202 و`ValidationException`
 * بعده — فمن يلتقط الرفضَ يلتقطهما معًا (`REFUSED`).
 */
final class ReturnNote
{
    /** ما يُلتقط به رفضُ الإشعار في النصوص التي تجري في عمليّةٍ أخرى */
    public const REFUSED = 'RuntimeException|Illuminate\Validation\ValidationException';

    public static function issue(CustomerInvoice $invoice, float $amount, ?int $userId = null): CustomerCreditNote
    {
        return CustomerInvoices::creditNote($invoice, $amount, 0.0, 'إرجاع', $userId, ...self::basis());
    }

    /** @return array<string, string> */
    private static function basis(): array
    {
        foreach ((new ReflectionMethod(CustomerInvoices::class, 'creditNote'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'basis') {
                return ['basis' => 'return'];
            }
        }

        return [];
    }
}
