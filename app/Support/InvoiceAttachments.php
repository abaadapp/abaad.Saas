<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * مرفقاتُ فاتورة العميل — قاعدةُ الرفع والحذف في موضعٍ واحد.
 *
 * ═══ ولمَ صنفٌ لا سطران في المتحكّم ═══
 *
 * الرفعُ يقع من بابين: مع إنشاء الفاتورة، وبعدها من صفحتها. وقاعدةٌ تُكتب
 * في البابين تفترق — فيقبل أحدُهما ملفًّا يردّه الآخر، أو يُحذف صفٌّ في
 * أحدهما ويبقى ملفُّه على القرص.
 *
 * ═══ والقرصُ الخاصّ لا العامّ ═══
 *
 * `public` يُخدَم من الخادم مباشرةً بلا Laravel وبلا جلسة. وأمرُ شراء
 * وزارةٍ أو عقدُ شركةٍ ليس مستندًا يُفتح برابطٍ يُخمَّن. فالكلُّ على
 * `local`، ويُقرأ من `FinancialAttachmentController` وحده.
 */
final class InvoiceAttachments
{
    /** ما يُقبل رفعُه — والقائمةُ مصدرٌ واحد يقرأ منه التحقّق والشاشة */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic'];

    /** بالكيلوبايت — عشرةُ ميجابايت كأخواتها في المشتريات والمصروفات */
    public const MAX_KB = 10240;

    /** وستّةٌ تكفي أمرَ شراءٍ وعقدًا وملحقًا وطلبًا موقَّعًا وصورتين */
    public const MAX_FILES = 6;

    /** @return array<string, mixed> قواعدُ التحقّق لحقلٍ اسمه $field */
    public static function rules(string $field): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_FILES],
            $field.'.*' => ['file', 'max:'.self::MAX_KB, 'extensions:'.implode(',', self::EXTENSIONS)],
        ];
    }

    /**
     * حفظُ ملفٍّ واحد على الورقة.
     *
     * ويُكتب الصفُّ بعد أن يستقرّ الملفّ على القرص: صفٌّ يشير إلى ملفٍّ لم
     * يُكتب رابطٌ ميّت، وملفٌّ بلا صفٍّ نفايةٌ تُنظَّف — انظر `discard`.
     */
    public static function store(CustomerInvoice $invoice, UploadedFile $file, ?int $userId = null): CustomerInvoiceAttachment
    {
        $path = $file->store('customer-invoices/'.$invoice->business_id, 'local');

        return CustomerInvoiceAttachment::create([
            'business_id' => $invoice->business_id,
            'customer_invoice_id' => $invoice->id,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);
    }

    /** حذفُ الصفّ وملفِّه معًا — ولا يُترك أحدُهما بلا الآخر */
    public static function remove(CustomerInvoiceAttachment $attachment): void
    {
        $path = $attachment->path;
        $attachment->delete();

        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * تنظيفُ ملفّاتٍ رُفعت قبل معاملةٍ سقطت.
     *
     * @param  list<string>  $paths
     */
    public static function discard(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
