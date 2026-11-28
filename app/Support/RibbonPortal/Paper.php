<?php

namespace App\Support\RibbonPortal;

use App\Http\Controllers\PdfController;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Support\InvoiceBranding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * الفاتورةُ الأصليّةُ لصاحبها — الورقةُ نفسُها التي تطبعها اللوحة.
 *
 * طلبُ الصندوق أو الموقع بـ`PdfController::salePdf`، وفاتورةُ العميل
 * بـ`customerInvoicePdf` — المحرّكُ واحدٌ ورقمُها وتاريخُها وبنودُها وحالُها من
 * صفّها. ولا يُكتب بها شيءٌ في فاتورةٍ ولا دفتر.
 *
 * وتُسأل الملكيّةُ هنا في كلّ عرضٍ وتنزيل: متجرُه هو **وعميلُها هو**. وما ليس
 * له يُردّ كما يُردّ ما لا وجودَ له.
 */
final class Paper
{
    /** الورقةُ إن كانت لهذا العميل — والمسودّةُ والسلّةُ المعلّقة ليستا بيعًا */
    public static function find(Customer $customer, string $kind, int $id): Order|CustomerInvoice|null
    {
        return match ($kind) {
            'order' => Order::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
                ->where('is_held', false)->with('items')->find($id),
            'invoice' => CustomerInvoice::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
                ->where('status', '!=', CustomerInvoice::DRAFT)->with('items')->find($id),
            default => null,
        };
    }

    public static function number(Order|CustomerInvoice $document): string
    {
        return (string) $document->number;
    }

    /** الورقةُ مرسومةً للشاشة */
    public static function html(Order|CustomerInvoice $document): string
    {
        $bid = (int) $document->business_id;

        return self::drawCodes(self::asTheShopPrints($bid, fn () => $document instanceof Order
            ? PdfController::saleHtml($bid, $document)['html']
            : PdfController::customerInvoiceHtml($bid, $document)));
    }

    /**
     * رمزُ الورقة يُرسم على الشاشة كما يُطبع — `<barcode>` وسمُ محرّك الطباعة وحده.
     *
     * وإلّا بقيت «امسح للتحقّق» بلا ما يُمسح. ومقاسُ الرمز عنده 25 مم للوحدة.
     */
    private static function drawCodes(string $html): string
    {
        return (string) preg_replace_callback('/<barcode\b[^>]*\btype="QR"[^>]*>/u', function (array $m) {
            if (! preg_match('/\bcode="([^"]*)"/u', $m[0], $code) || trim($code[1]) === '') {
                return '';
            }

            $mm = round(25 * (preg_match('/\bsize="([0-9.]+)"/', $m[0], $size) ? (float) $size[1] : 1.0), 1);

            return '<img alt="" style="width:'.$mm.'mm;height:'.$mm.'mm" src="'.Qr::dataUri(html_entity_decode($code[1], ENT_QUOTES)).'">';
        }, $html);
    }

    public static function pdf(Order|CustomerInvoice $document, bool $download): Response
    {
        $bid = (int) $document->business_id;
        $response = self::asTheShopPrints($bid, fn () => $document instanceof Order
            ? PdfController::salePdf($bid, $document)
            : PdfController::customerInvoicePdf($bid, $document));

        if ($download) {
            $response->headers->set('Content-Disposition', preg_replace('/^inline/', 'attachment', (string) $response->headers->get('Content-Disposition')));
        }

        return $response;
    }

    /**
     * كما تُطبع في اللوحة حرفًا: بلغتها وبعنوانها — لا بلغة من يتصفّح ولا بمضيف البوابة.
     *
     * رمزُ الورقة يُبنى من عنوان الطلب (`PublicDocument::url`)، فكانت نسخةُ البوابة
     * تحمل مضيفًا غيرَ الذي على المطبوعة. وفاتورةُ العميل لغتُها مضبوطةٌ في
     * إعداداتها (`InvoiceBranding::render` بداخلها) وتغلب.
     */
    private static function asTheShopPrints(int $businessId, callable $draw): mixed
    {
        $saved = (string) DB::table('settings')->where('business_id', $businessId)->where('key', 'locale')->value('value');

        URL::forceRootUrl((string) config('app.url'));

        try {
            return InvoiceBranding::render($businessId, in_array($saved, Texts::LANGS, true) ? $saved : 'ar', $draw);
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
