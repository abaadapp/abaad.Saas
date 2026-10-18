<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Support\Demo;
use App\Support\PaymentLinks;
use App\Support\Permissions;
use App\Support\UncertainPaymentLink;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * طلبُ الدفع برابطٍ من صفحة الفاتورة أو العميل — بلا قسم «المالية».
 *
 * ═══ لمَ بابٌ هنا ═══
 *
 * بابُ «المالية ← روابط الدفع» يفتح معه أرصدةَ البنوك وتسوياتِ Paymob
 * وقائمةَ العملاء كلِّهم. والبائعُ الذي يُصدر فاتورةً ويرسل رابطَ دفعها لا
 * يحتاج شيئًا من ذلك. فهذان البابان يطلبان رابطًا لفاتورةٍ أو عميلٍ بعينه،
 * بقسم صفحته (`customerInvoices` ← «المبيعات»، `customers` ← «العملاء»)
 * وبفعل الطلب وحده (`PAYMENT_LINK_CREATE`).
 *
 * ولا يردّان إلّا الرابطَ الذي طُلب: لا قائمةَ روابط، ولا تسوية، ولا بنك،
 * ولا مفتاح. والإلغاءُ ليس هنا — يبقى في «المالية» لمن يملك التحصيل.
 *
 * والإذنُ قبل البحث: من لا يملك الطلبَ يُردّ بـ٤٠٣ ولو كتب معرّفًا لا يوجد —
 * فلا يُعرف منه أيُّ الفواتير موجود.
 */
class PaymentRequestController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function mayRequest(): void
    {
        abort_if(! auth()->user()?->may(Permissions::PAYMENT_LINK_CREATE), 403);
    }

    /**
     * رابطٌ لباقي الفاتورة — أو القائمُ إن كان بالمبلغ نفسِه.
     *
     * و`replace_uncertain` قرارٌ صريحٌ بعد محاولةٍ لا تُعرف نتيجتُها: «أعرف
     * أنّ الأوّل قد يكون عند Paymob، وسأرسل الجديدَ وحده».
     */
    public function forInvoice(Request $request, int|string $id)
    {
        $this->mayRequest();

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:250'],
            'replace_uncertain' => ['nullable', 'boolean'],
        ], [], [
            'amount' => __('المبلغ'),
            'description' => __('الوصف'),
            'replace_uncertain' => __('طلب رابط جديد'),
        ]);

        $invoice = CustomerInvoice::where('business_id', $this->bid())->findOrFail($id);

        try {
            [$link, $reused] = PaymentLinks::forInvoice(
                $invoice,
                auth()->id(),
                isset($data['amount']) ? (float) $data['amount'] : null,
                $data['description'] ?? null,
                $request->boolean('replace_uncertain'),
            );
        } catch (UncertainPaymentLink $e) {
            throw ValidationException::withMessages(['payment_link' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => $reused
                ? __('على الفاتورة رابطٌ قائم بالمبلغ نفسه — لم يُنشأ رابطٌ ثانٍ')
                : __('تم إنشاء رابط الدفع — انسخه وأرسله للعميل'),
            'type' => $reused ? 'info' : 'success',
        ])->with('paymentLink', PaymentLinks::row($link->load(['customer', 'invoice'])));
    }

    /** طلبُ دفعةٍ بمبلغٍ حرٍّ من عميل — بلا فاتورة، فيُكتب رصيدًا له حين يُدفع */
    public function forCustomer(Request $request, int|string $id)
    {
        $this->mayRequest();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:250'],
        ], [], [
            'amount' => __('المبلغ'),
            'description' => __('الوصف'),
        ]);

        $bid = $this->bid();
        $customer = Customer::where('business_id', $bid)->findOrFail($id);

        try {
            $link = PaymentLinks::create($bid, $customer, null, (float) $data['amount'], $data['description'] ?? null, auth()->id());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('تم إنشاء رابط الدفع — انسخه وأرسله للعميل'),
            'type' => 'success',
        ])->with('paymentLink', PaymentLinks::row($link->load(['customer', 'invoice'])));
    }
}
