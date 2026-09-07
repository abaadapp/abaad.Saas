<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Models\SupplierInvoice;
use App\Support\Demo;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مرفقاتُ المستندات المالية — تُقرأ ببابٍ يسأل عن صاحبها.
 *
 * ═══ لماذا ليست على القرص العامّ ═══
 *
 * مرفقاتُ المصروفات وأوامر الشراء القديمة على `public`: رابطُها يُخمَّن
 * ويُفتح بلا تسجيل دخول، ومن عرف رابطًا واحدًا عرف نمطَه. وورقةُ مورّدٍ
 * فيها أسعارُ شرائك — أثمنُ ما في متجرك عند منافسك.
 *
 * فالجديدُ على القرص الخاصّ، ولا يُقرأ إلّا من هنا: البابُ يسأل عن المتجر
 * أوّلًا، فمرفقُ الجار لا يُفتح برقمٍ يُكتب في العنوان.
 *
 * والاسمُ المخزَّن عشوائيّ والمعروضُ هو ما سمّاه صاحبه: اسمٌ يُبنى من
 * الأصل يُخمَّن، واسمٌ عشوائيٌّ بلا حفظِ الأصل يُنزَّل إلى المحاسب بلا معنى.
 *
 * والبابُ يسأل سؤالين لا سؤالًا: أهذا مستندُ متجرك؟ وهل مُنحتَ فتحَ
 * المرفقات؟ والسؤالُ الأوّل وحده كان يجعل كلَّ من يفتح شاشةَ السندات يقرأ
 * أوراق المورّد كما وصلت — وفيها أسعارُ الشراء صريحةً بخطّ صاحبها.
 */
class FinancialAttachmentController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * ورقةُ المورّد مع الشحنة — بوليصةٌ أو إشعارُ تسليم.
     *
     * وهي غيرُ فاتورته: هذه تقول «وصل»، وتلك تقول «عليك».
     */
    public function receipt(int|string $id): StreamedResponse
    {
        $this->mustBeAllowed();

        $note = GoodsReceiptNote::where('business_id', $this->bid())->findOrFail($id);

        return $this->stream($note->attachment, $note->attachment_name, $note->number);
    }

    /** فاتورةُ المورّد كما وصلت */
    public function supplierInvoice(int|string $id): StreamedResponse
    {
        $this->mustBeAllowed();

        $invoice = SupplierInvoice::where('business_id', $this->bid())->findOrFail($id);

        return $this->stream($invoice->attachment, $invoice->attachment_name, $invoice->supplier_ref);
    }

    /** فتحُ المرفقات فعلٌ يُمنح باسمه — لا قسمٌ يُفتح */
    private function mustBeAllowed(): void
    {
        abort_if(! auth()->user()?->may(Permissions::ATTACHMENT_VIEW), 403);
    }

    /**
     * يُقدَّم للقراءة لا للتنزيل الإجباريّ.
     *
     * أكثرُ ما يُرفع صورةٌ أو PDF، ومن فتحه يريد أن يراه لا أن يجده في مجلّد
     * التنزيلات. و`download` على الرابط تكفي من أراد حفظه.
     */
    private function stream(?string $path, ?string $name, string $fallback): StreamedResponse
    {
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            $name ?: ($fallback.'.'.pathinfo($path, PATHINFO_EXTENSION)),
            ['Content-Disposition' => 'inline'],
        );
    }
}
