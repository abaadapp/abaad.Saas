<?php

namespace App\Support;

use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;

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
    /**
     * معاملُ حجم الخطّ من اسمه — في موضعٍ واحد لا في كلّ قالب.
     *
     * ومعاملٌ لا مقاسٌ بالبكسل: الورقة تُقاس بالنقطة، ومقاسٌ واحد للجسد
     * كان يكبر وحده فيصير الجدول والترويسة أصغر ممّا حولهما. والمعامل
     * يضرب المقاسات كلَّها فتكبر الورقة معًا — انظر pdf/partials/style.
     */
    public static function scale(string $font): float
    {
        return match ($font) {
            'صغير' => 0.9, 'كبير' => 1.14, default => 1.0
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

        // الرقم الضريبي يُقرأ في الورقة ولا يُضبط من «قوالب» — ومن موضعٍ واحد
        $out['vat_number'] = Paper::vatNumber($businessId);

        return $out;
    }

    /**
     * ورقةٌ عامّة مرسومةً — أمرُ شراءٍ أو سندُ استلامٍ أو نقلٍ أو تسليم.
     *
     * @param  array<string,mixed>  $doc  بيانُ المستند من `DocumentPaper`
     * @param  array<string,mixed>|null  $override  قيمٌ لم تُحفظ بعد — للمعاينة
     * @param  Model|null  $source  الصفُّ الذي رُسمت منه — لبناء رابطها العامّ
     */
    public static function generic(int $businessId, string $type, array $doc, ?array $override = null, ?Model $source = null): string
    {
        $tpl = DocumentTemplates::settings($businessId, $type, $override);
        $business = DocumentPaper::business($businessId);

        return view('pdf.document', [
            'doc' => $doc,
            'tpl' => $tpl,
            'business' => $business,
            'scale' => self::scale((string) $tpl['font']),
            'logo' => $business?->logo,
            'vatNumber' => Paper::vatNumber($businessId),
            /*
             * ورمزُ الورقة لسند التسليم وحده — انظر PublicDocument.
             *
             * أمرُ الشراء وسندُ الاستلام يحملان تكلفةَ البضاعة ويمضيان إلى
             * المورّد، فلا يُفتح لهما بابٌ عامّ.
             */
            'paperUrl' => $type === 'delivery' ? (PublicDocument::url($source) ?? '') : '',
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
        $paper = (string) ($values['paper'] ?? '80mm');

        $order = Order::where('business_id', $businessId)
            ->where('is_held', false)
            ->with('items')
            ->latest('id')
            ->first() ?? self::sampleOrder($businessId);

        return view($paper === 'A4' ? 'pdf.invoice' : 'pdf.receipt', [
            'order' => $order,
            'tpl' => $tpl,
            // عرضُ الشريط في المعاينة كما اختاره التاجر — انظر pdf/partials/strip-style
            'width' => self::stripWidth($paper),
            /*
             * ولا رابطَ في المعاينة: الطلبُ المعروض قد يكون مُخترعًا، ورمزٌ
             * له يقود إلى ٤٠٤ في يد التاجر. والمحفوظُ منه لا يُفتح بابُه
             * لمجرّد أنّ أحدًا فتح محرّر القوالب.
             */
            'paperUrl' => '',
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

    /** عرضُ الشريط بالمليمتر من اسم المقاس في القالب */
    public static function stripWidth(string $paper): int
    {
        return $paper === '58mm' ? 58 : 80;
    }

    /**
     * ملفُّ PDF من HTML — بالمحرّك الواحد.
     *
     * وكان يبني mpdf بيده بهوامش تخصّه: ١٢ مم هنا و١٤ هناك و١٥ في ثالث،
     * وستّةُ مواضع في النظام تفعل مثله. انظر App\Support\Pdf.
     */
    public static function pdf(string $html, string $name)
    {
        return Pdf::a4($html, $name);
    }
}
