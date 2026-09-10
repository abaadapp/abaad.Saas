<?php

namespace App\Http\Controllers;

use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Support\Document\Branding;
use App\Support\InvoiceBranding;
use App\Support\Paper;
use App\Support\PublicDocument;
use Illuminate\Contracts\View\View;

/**
 * الورقةُ أونلاين — ما يفتحه من مسح الرمز أسفل ورقته.
 *
 * ولا تسجيلَ دخولٍ هنا: الواقف أمام الشاشة زبونٌ لا موظّف، ولا حساب له
 * في النظام ولا يجب أن يكون. وحارسُها أنّ رمزها لا يُخمَّن — اثنان
 * وعشرون حرفًا من ستّةٍ وستّين احتمالًا — لا أنّ أحدًا يُسأل من هو.
 *
 * وهي صفحةٌ قائمةٌ بذاتها لا شاشةٌ من اللوحة: لا Inertia ولا قائمةٌ
 * جانبية ولا أصولُ البناء. تُفتح على هاتفٍ في المحلّ بشبكةٍ ضعيفة، وكلُّ
 * ما فيها في ملفٍّ واحد.
 *
 * ═══ وصفحتان لا واحدة، ولكلٍّ سببُها ═══
 *
 * • **الطلب** يُعرض كاملًا (`public.paper`): الإيصالُ الحراريّ يبهت في
 *   جيبٍ خلال أشهر ويُبلَّل ويضيع، وزبونٌ يعود بضمانٍ بعد سنةٍ يحمل قصاصةً
 *   لا تُقرأ. فما يريده هو **نسختُه** لا التحقّق منها.
 *
 * • **فاتورةُ العميل** تُعرض ملخّصًا (`public.verify`): هي تصل الجهةَ
 *   كاملةً بالبريد أو على ورق، والسؤالُ عند مسح الرمز واحد — أهذه صحيحة؟
 *   فلا تُحمَّل الصفحةُ ببنودٍ وآيبانٍ ومراكزِ تكلفةٍ لا يحتاجها من يسأل.
 *
 * وما عداهما لا يبلغ هنا أصلًا: `PublicDocument::allows` لا يبني رمزًا
 * لأمر الشراء ولا لسند الاستلام — انظر سياستَها هناك.
 */
class PublicDocumentController extends Controller
{
    public function show(string $token): View
    {
        $link = PublicDocument::find($token);

        abort_if($link === null, 404);

        $document = $link->linkable;

        /*
         * ورقةٌ فقدت أصلها لا تُعرض.
         *
         * الطلب يُحذف حذفًا ناعمًا، ونشاطُه قد يُحذف كلُّه — و`morphTo` تردّ
         * null بلا شكوى. فبلا هذا كانت الصفحة تنهار بـ500 في يد زبونٍ لا
         * يعرف ما الذي كسر، بدل «هذه الورقة لم تعد متاحة».
         */
        abort_if($document === null || $link->business === null, 404);

        /*
         * وقفلُ الاشتراك لا يقفل إيصالًا سُلّم.
         *
         * متجرُ من انتهى اشتراكه يُغلق متجرَه ولوحتَه — وهذا صحيح: كلاهما
         * خدمةٌ تُباع. أمّا هذه فسجلُّ معاملةٍ تمّت ووصلت يدَ الزبون قبل
         * أن ينتهي شيء، ومنعُها يجعل مشكلةَ الفوترة بين التاجر وأبعاد
         * تقع على من لا شأن له بها.
         */
        if ($document instanceof Order) {
            $document->loadMissing('items');

            return view('public.paper', [
                'order' => $document,
                'brand' => Paper::brand($link->business, Paper::vatNumber($link->business_id)),
                'stampedAt' => now()->format('Y-m-d H:i'),
            ]);
        }

        abort_unless($document instanceof CustomerInvoice, 404);

        /*
         * وبلغة الورقة لا بلغة المتصفّح.
         *
         * من يمسح الرمزَ يحمل ورقةً مطبوعة. وصفحةٌ بغير لغتها تجعله يقارن
         * نصّين مختلفين ليعرف أنّهما الشيءُ نفسُه.
         */
        return InvoiceBranding::render($link->business_id, null, fn () => view('public.verify', [
            'doc' => $this->summary($document),
            'brand' => Paper::brand(InvoiceBranding::paper($link->business_id)),
            'primary' => Branding::tokens($link->business_id)['primary_ink'],
            'stampedAt' => now()->format('Y-m-d H:i'),
        ]));
    }

    /**
     * حقائقُ التحقّق من فاتورةٍ — ما يُعرض، ولا شيء سواه.
     *
     * و«الحال» تُحسب هنا من الدفتر لا تُقرأ عمودًا: `outstanding()` يجمع
     * ما سُدِّد وما رُدّ. فورقةٌ سُدِّدت أمسِ تقول «مسدَّدة» اليوم بلا أن
     * يكتب أحدٌ شيئًا في صفّها.
     *
     * @return array<string, string>
     */
    private function summary(CustomerInvoice $invoice): array
    {
        $outstanding = $invoice->outstanding();

        [$state, $status] = match (true) {
            $invoice->status === CustomerInvoice::CANCELLED => ['void', __('ملغاة')],
            $invoice->status === CustomerInvoice::DRAFT => ['void', __('مسودة')],
            $outstanding <= 0 => ['ok', __('مسدَّدة بالكامل')],
            $invoice->due_at && $invoice->due_at->endOfDay()->isPast() => ['late', __('متأخرة عن الاستحقاق')],
            default => ['due', __('مستحقة')],
        };

        return [
            'type' => $invoice->tax_total > 0 ? __('فاتورة ضريبية') : __('فاتورة'),
            'number' => (string) ($invoice->number ?: __('مسودة')),
            'date' => (string) (optional($invoice->issued_at)->format('Y-m-d') ?: ''),
            'due' => (string) (optional($invoice->due_at)->format('Y-m-d') ?: ''),
            'total' => number_format((float) $invoice->total, 3).' '.__('ر.ع'),
            'state' => $state,
            'status' => $status,
        ];
    }
}
