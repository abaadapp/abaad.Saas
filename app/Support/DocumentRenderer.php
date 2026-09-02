<?php

namespace App\Support;

use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use Mpdf\Mpdf;

/**
 * الرسمُ — قالبٌ واحد يخدم الطباعة والمعاينة معًا.
 *
 * والمعاينةُ في المحرّر تُرسم بالقالب الذي يُطبع لا بنسخةٍ ثانية منه في
 * الشاشة. وكانت الشاشة ترسم إيصالًا بيدها في JSX: صندوقٌ يشبه الورقة ولا
 * يقرأ ملفَّ الرسم — فيُصلَح سطرٌ في الورقة ولا يتغيّر في المعاينة، ويضبط
 * التاجر قالبَه على شكلٍ لا يخرج من الطابعة.
 */
class DocumentRenderer
{
    /** حجمُ الخطّ بالبكسل من اسمه — في موضعٍ واحد لا في كلّ قالب */
    public static function base(string $font): int
    {
        return match ($font) {
            'صغير' => 11, 'كبير' => 14, default => 12
        };
    }

    /**
     * إعداداتُ ورقة البيع بالأسماء التي تقرؤها قوالبُها القديمة.
     *
     * قوالبُ الإيصال والفاتورة والفاتورة الضريبية تقرأ `tpl_show_logo`
     * و`tpl_header`، والسجلُّ يسمّيها `show_logo` و`header`. والتحويلُ هنا
     * في دالّةٍ واحدة: نسختان من الخريطة تفترقان عند أوّل حقلٍ يُضاف.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public static function legacy(int $businessId, array $values): array
    {
        $out = [];

        foreach ($values as $field => $value) {
            $out[$field === 'paper' ? 'paper' : 'tpl_'.$field] = $value;
        }

        // الرقم الضريبي يُقرأ في الورقة ولا يُضبط من «قوالب»
        $out['vat_number'] = (string) Setting::where('business_id', $businessId)
            ->where('key', 'vat_number')->value('value');

        return $out;
    }

    /**
     * ورقةٌ عامّة مرسومةً — أمرُ شراءٍ أو سندُ استلامٍ أو نقلٍ أو تسليم.
     *
     * @param  array<string,mixed>  $doc  بيانُ المستند من `DocumentPaper`
     * @param  array<string,mixed>|null  $override  قيمٌ لم تُحفظ بعد — للمعاينة
     */
    public static function generic(int $businessId, string $type, array $doc, ?array $override = null): string
    {
        $tpl = DocumentTemplates::settings($businessId, $type, $override);
        $business = DocumentPaper::business($businessId);

        return view('pdf.document', [
            'doc' => $doc,
            'tpl' => $tpl,
            'business' => $business,
            'base' => self::base((string) $tpl['font']),
            'logo' => $business?->logo,
            'vatNumber' => (string) Setting::where('business_id', $businessId)
                ->where('key', 'vat_number')->value('value'),
            /*
             * والسعرُ علمٌ واحد يحكم العمودين والمجموع معًا: لو حُسب في كلٍّ
             * منها لخرجت ورقةٌ بلا أسعارٍ في السطور وبمجموعٍ في أسفلها.
             */
            'showPrices' => (bool) ($tpl['show_prices'] ?? true),
            'showParties' => (bool) (($tpl['show_customer'] ?? false) || ($tpl['show_supplier'] ?? false)),
        ])->render();
    }

    /**
     * ورقةُ البيع مرسومةً — بالقالب الذي يُطبع فعلًا.
     *
     * وبطلبٍ حقيقيّ من دفتر المتجر إن وُجد: التاجر يحكم على قالبه بما يراه،
     * وأسماءُ أصنافه وأطوالُ سطوره تُظهر له الورقة كما ستخرج. فإن لم يبِع
     * بعدُ رُسمت بمثال.
     */
    public static function sale(int $businessId, ?array $override = null): string
    {
        $values = DocumentTemplates::settings($businessId, 'sale', $override);
        $tpl = self::legacy($businessId, $values);

        $order = Order::where('business_id', $businessId)
            ->where('is_held', false)
            ->with('items')
            ->latest('id')
            ->first() ?? self::sampleOrder($businessId);

        return view(($values['paper'] ?? '80mm') === 'A4' ? 'pdf.invoice' : 'pdf.receipt', [
            'order' => $order,
            'tpl' => $tpl,
            /*
             * ولا رمزَ فوترةٍ في المعاينة: `EInvoice` تبني رمزًا يحمل رقم
             * المتجر الضريبي والمبلغ، ورسمُه لطلبٍ مُخترع يضع في يد التاجر
             * صورةَ رمزٍ لا تُقابله فاتورة.
             */
            'qr' => null,
            'customerTax' => null,
            'googleReview' => null,
        ])->render();
    }

    /** طلبٌ للمعاينة وحدها — لا يُحفظ ولا يُعدّ في بيع */
    private static function sampleOrder(int $businessId): Order
    {
        $order = new Order([
            'business_id' => $businessId,
            'number' => 'INV-000123',
            'customer_name' => __('زبون تجريبي'),
            'employee_name' => __('موظف المبيعات'),
            'branch' => __('الفرع الرئيسي'),
            'payment_method' => 'نقدي',
            'subtotal' => 13.500, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 13.500,
            'ordered_at' => now(),
        ]);

        $order->setRelation('items', collect([
            new OrderItem(['name' => __('باقة ورد'), 'price' => 4.500, 'quantity' => 1, 'total' => 4.500]),
            new OrderItem(['name' => __('صندوق هدايا'), 'price' => 4.500, 'quantity' => 2, 'total' => 9.000]),
        ]));
        $order->setRelation('business', DocumentPaper::business($businessId));

        return $order;
    }

    /** الورقة كما تُعاين في المحرّر — أيًّا كان نوعها */
    public static function preview(int $businessId, string $type, ?array $override = null): string
    {
        if ($type === 'sale') {
            return self::sale($businessId, $override);
        }

        return self::generic($businessId, $type, self::latestOrSample($businessId, $type), $override);
    }

    /**
     * أحدثُ مستندٍ من نوعه — أو مثالٌ إن لم يُنشأ بعد.
     *
     * @return array<string,mixed>
     */
    private static function latestOrSample(int $businessId, string $type): array
    {
        $record = match ($type) {
            'delivery' => Order::where('business_id', $businessId)->where('is_held', false)
                ->with('items')->latest('id')->first(),
            'purchase' => PurchaseOrder::where('business_id', $businessId)
                ->with('items', 'supplier')->latest('id')->first(),
            'grn' => GoodsReceiptNote::where('business_id', $businessId)
                ->with('items', 'supplier', 'branch', 'purchaseOrder')->latest('id')->first(),
            default => null,
        };

        if ($record === null) {
            return DocumentPaper::sample($businessId, $type);
        }

        return match ($type) {
            'delivery' => DocumentPaper::forDelivery($record),
            'purchase' => DocumentPaper::forPurchase($record),
            'grn' => DocumentPaper::forGrn($record),
        };
    }

    /** ملفُّ PDF من HTML — بإعداد A4 العربيّ نفسه في كلّ النظام */
    public static function pdf(string $html, string $name)
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 14,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output($name.'.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
        ]);
    }
}
