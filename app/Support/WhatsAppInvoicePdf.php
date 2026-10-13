<?php

namespace App\Support;

use App\Http\Controllers\PdfController;
use App\Models\CustomerInvoice;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فاتورةُ العميل ملفًّا على واتساب — تُرسم ثمّ تُرفع إلى ميتا، ويُردّ معرّفُها.
 *
 * ═══ ولمَ رفعٌ لا رابط ═══
 *
 * رأسُ القالب «مستند» يقبل رابطًا تجلبه ميتا، أو معرّفَ ملفٍّ رُفع إليها.
 * والرابطُ يعني فاتورةً كاملةً — بنودَها وآيبانَها — خلف عنوانٍ عامّ، وصفحةُ
 * الفاتورة العامّة ملخّصٌ عن قصد (`public/verify`). فتُرفع الورقةُ من الخادم
 * إلى حساب الرقم الذي ترسل منه، ولا يبقى منها على القرص شيء.
 *
 * والورقةُ ورقةُ التصدير نفسُها (`PdfController::customerInvoiceHtml`) بما
 * سُدّد وما بقي ساعةَ الرسم — فمن دفع يقرأ دفعتَه عليها.
 */
final class WhatsAppInvoicePdf
{
    /**
     * @return array{ok: bool, document: ?array{id: string, filename: string}, code: ?string, message: ?string, retryable: bool}
     */
    public static function upload(WhatsAppConnection $connection, CustomerInvoice $invoice): array
    {
        $bytes = Pdf::a4(PdfController::customerInvoiceHtml((int) $invoice->business_id, $invoice), 'invoice')->getContent();
        // واسمُ الملفّ رقمُ الفاتورة كما يعرفه العميل — بحروفٍ يقبلها كلُّ هاتف
        $filename = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->number), '-') ?: 'invoice';
        $path = 'whatsapp-outbox/'.Str::ulid().'.pdf';

        Storage::disk('local')->put($path, (string) $bytes);

        try {
            $result = WhatsAppMedia::upload($connection, 'local', $path, 'application/pdf', $filename.'.pdf');
        } finally {
            Storage::disk('local')->delete($path);
        }

        if ($result['ok']) {
            return ['ok' => true, 'document' => ['id' => (string) $result['id'], 'filename' => $filename.'.pdf'], 'code' => null, 'message' => null, 'retryable' => false];
        }

        $code = (string) $result['code'];

        return [
            'ok' => false,
            'document' => null,
            'code' => $code,
            'message' => $result['message'],
            // انقطاعٌ أو عطلٌ عند ميتا يُعاد؛ وما سواه لا تُصلحه الإعادة
            'retryable' => $code === 'network_error' || (ctype_digit($code) && (int) $code >= 500 && (int) $code < 600),
        ];
    }
}
