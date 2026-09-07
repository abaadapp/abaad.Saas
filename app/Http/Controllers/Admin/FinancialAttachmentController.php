<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
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
 * القرصُ العامّ يُخدَم من `public/storage` مباشرةً: لا Laravel يُستدعى ولا
 * جلسةَ يُسأل عنها — الخادمُ يقرأ الملفّ ويردّه لمن طلبه. فرابطُ إيصالِ
 * دفعٍ أو فاتورةِ مصروفٍ كان يُفتح بلا تسجيل دخول، ومن عرف رابطًا واحدًا
 * عرف نمطَه. وورقةُ مورّدٍ فيها أسعارُ شرائك — أثمنُ ما في متجرك عند
 * منافسك.
 *
 * فالكلُّ على القرص الخاصّ الآن، ولا يُقرأ إلّا من هنا: البابُ يسأل عن
 * المتجر أوّلًا، فمرفقُ الجار لا يُفتح برقمٍ يُكتب في العنوان.
 *
 * وخمسةُ مستنداتٍ على بابٍ واحد: سندُ المورّد، وورقةُ الشحنة، وفاتورةُ
 * المصروف، وإيصالُ دفعٍ قديمٌ على أمر شراء، ومرفقُ أمر الشراء نفسِه (عرضُ
 * سعرٍ أو مستندُ طلب). وبابٌ لكلٍّ كان يعني خمسةَ حرّاسٍ يفترق أحدُهم يومًا.
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

    /**
     * فاتورةُ المصروف أو إيصالُه — ما يُثبت أنّ المال خرج لهذا.
     *
     * وكانت على القرص العامّ منذ أوّل يوم: مبالغُ إيجارك ورواتبك وفواتيرك
     * تُقرأ برابطٍ يُخمَّن.
     */
    public function expense(int|string $id): StreamedResponse
    {
        $this->mustBeAllowed();

        $expense = Expense::where('business_id', $this->bid())->findOrFail($id);

        return $this->stream($expense->attachment, $expense->attachment_name, 'expense-'.$expense->id);
    }

    /**
     * مرفقُ أمر الشراء — عرضُ سعر المورّد أو مستندُ الطلب.
     *
     * وهو غيرُ إيصال الدفع أدناه: هذا يُرفع قبل الشراء ليقول «هذا ما اتّفقنا
     * عليه»، وذاك كان يُرفع بعده ليقول «دُفع». والدفعُ ليس من عمل أمر
     * الشراء أصلًا — انظر `PurchaseOrderController::store`.
     */
    public function purchaseOrder(int|string $id): StreamedResponse
    {
        $this->mustBeAllowed();

        $po = PurchaseOrder::where('business_id', $this->bid())->findOrFail($id);

        return $this->stream($po->attachment, $po->attachment_name, $po->number);
    }

    /**
     * إيصالُ دفع أمر الشراء — مستندٌ قديمٌ لا يُطلب في الشاشة بعد اليوم.
     *
     * والبابُ يبقى مفتوحًا لما رُفع قبلُ: أوامرُ شراءٍ مضت تحمل إيصالاتِها،
     * ومحوُ الباب يجعلها ملفّاتٍ على القرص لا يقرؤها شيء.
     *
     *
     * هذا يقول «دُفع»، وتلك تقول «وصل». ولكلٍّ عمودُه وبابُه.
     */
    public function purchaseReceipt(int|string $id): StreamedResponse
    {
        $this->mustBeAllowed();

        $po = PurchaseOrder::where('business_id', $this->bid())->findOrFail($id);

        return $this->stream($po->receipt, $po->receipt_name, $po->number);
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
