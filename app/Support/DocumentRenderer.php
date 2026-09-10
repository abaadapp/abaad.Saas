<?php

namespace App\Support;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Support\Document\Branding;
use App\Support\Document\PaperSize;
use App\Support\Document\Version;
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
    public static function generic(int $businessId, string $type, array $doc, ?array $override = null, ?Model $source = null, ?string $version = null): string
    {
        $tpl = DocumentTemplates::settings($businessId, $type, $override);
        $business = DocumentPaper::business($businessId);
        $scale = self::scale((string) $tpl['font']);

        /*
         * و«إظهار الشعار» يُطفئ الشعار فعلًا.
         *
         * الترويسةُ تقرأ الشعار من صفّ المتجر مباشرةً، فمقبضٌ مطفأٌ كان
         * يُطبع معه الشعار ولا رسالةَ تقول لماذا. والصفُّ يُنسَخ إلى مصفوفةٍ
         * بلا شعار ولا يُعدَّل: تعديلُ النموذج يُغيّر ما تقرؤه أوراقٌ أخرى
         * في الطلب نفسه.
         */
        if (! ($tpl['show_logo'] ?? false) && $business !== null) {
            $business = collect(['name', 'type', 'city', 'phone', 'email', 'address'])
                ->mapWithKeys(fn (string $k) => [$k => $business->{$k} ?? ''])
                ->all();
        }

        return view(Version::views($version).'.'.self::template($type), [
            /* والأوراقُ العامّة على A4: أمرُ شراءٍ لا يُطبع على شريطٍ حراريّ */
            'paper' => PaperSize::A4,
            'tokens' => Branding::tokens($businessId, $scale),
            'coverImage' => Branding::cover($businessId),
            'doc' => $doc,
            'tpl' => $tpl,
            'business' => $business,
            'headerNote' => trim((string) ($tpl['header'] ?? '')),
            'scale' => $scale,
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
     * ملفُّ رسمِ نوعٍ — من هنا وحده.
     *
     * والأسماءُ في السجلّ لا تصلح أسماءَ ملفّات: `grn` مفتاحُ إعداداتٍ
     * اختير حين كُتب السجلّ، و`customer_invoice` فيه شرطة سفليّة. وخريطةٌ
     * تُكتب في كلّ موضعٍ يرسم تُنسي التاليَ نوعًا.
     */
    private static function template(string $type): string
    {
        return match ($type) {
            'sale' => 'sale',
            'customer_invoice' => 'customer-invoice',
            'delivery' => 'delivery',
            'purchase' => 'purchase',
            'grn' => 'grn',
            default => 'sale',
        };
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
        $paper = (string) ($values['paper'] ?? '80mm');

        $order = Order::where('business_id', $businessId)
            ->where('is_held', false)
            ->with('items', 'customerInvoices')
            ->latest('id')
            ->first() ?? self::sampleOrder($businessId);

        if (! PaperSize::isStrip($paper)) {
            return self::saleSheet($businessId, $order, $values, ['paper' => $paper]);
        }

        /*
         * ولا رمزَ ولا رابطَ في المعاينة.
         *
         * الطلبُ المعروض قد يكون مُخترعًا، ورمزٌ له يقود إلى ٤٠٤ في يد
         * التاجر. و`EInvoice` تبني رمزًا يحمل رقمَ المتجر الضريبيّ والمبلغ،
         * ورسمُه لطلبٍ مُخترع يضع في يده صورةَ رمزٍ لا تُقابله فاتورة.
         */
        return self::saleStrip($businessId, $order, $values, self::stripWidth($paper));
    }

    /**
     * فاتورةُ البيع على A4 — الورقةُ التي تُرسَل إلى منشأةٍ تطلب فاتورة.
     *
     * وليست شريطَ الإيصال مُمدَّدًا: كانت تُرسم بقالبه نفسه فتخرج بمحتوًى
     * منكمشٍ في أعلى الصفحة وثلثيها بياض — وهي الورقة التي تصل جهةً
     * تحكم على المتجر بما تراه.
     *
     * @param  array<string,mixed>  $values  إعداداتُ القالب محلولةً
     * @param  array<string,mixed>  $extra  ما يخصّ الطباعة لا المعاينة
     */
    public static function saleSheet(int $businessId, Order $order, array $values, array $extra = []): string
    {
        $scale = self::scale((string) $values['font']);

        return view(Version::views($extra['version'] ?? null).'.sale', [
            'doc' => DocumentPaper::forSale($order, ['customerTax' => $extra['customerTax'] ?? null]),
            'tpl' => $values,
            /* ومقاسُ الورقة يبلغ القالبَ ليكتب `@page` وصندوقَها — انظر PaperSize */
            'paper' => $extra['paper'] ?? ($values['paper'] ?? PaperSize::A4),
            'tokens' => Branding::tokens($businessId, $scale),
            'coverImage' => Branding::cover($businessId),
            'business' => DocumentPaper::business($businessId),
            'headerNote' => trim((string) ($values['header'] ?? '')),
            'scale' => $scale,
            'vatNumber' => Paper::vatNumber($businessId),
            /*
             * ولا رمزَ ولا رابطَ في المعاينة.
             *
             * `EInvoice` تبني رمزًا يحمل رقمَ المتجر الضريبيّ والمبلغ، ورسمُه
             * لطلبٍ مُخترع يضع في يد التاجر صورةَ رمزٍ لا تُقابله فاتورة.
             * والرابطُ يقود إلى ٤٠٤ — أو أسوأ: يصنع صفًّا يتيمًا في جدول
             * الروابط عند كلّ فتحةٍ للمحرّر.
             */
            'qr' => $extra['qr'] ?? null,
            'paperUrl' => $extra['paperUrl'] ?? '',
            'googleReview' => $extra['googleReview'] ?? null,
            /* والوجهُ الثالث: الرقمُ الضريبيّ بلا مقبض — انظر رأس القالب */
            'taxInvoice' => (bool) ($extra['taxInvoice'] ?? false),
            'generatedAt' => $extra['generatedAt'] ?? null,
        ])->render();
    }

    /**
     * شريطُ الإيصال الحراريّ مرسومًا — بالقالب الذي يُطبع.
     *
     * ═══ ولمَ هنا لا في متحكّم الطباعة ═══
     *
     * الإيصالُ يُرسم من بابين: معاينةُ محرّر القوالب، وزرُّ الطباعة في
     * الصندوق. وكان الثاني يبني القائمةَ بيده، فما يُضاف لأحدهما لا يبلغ
     * الآخر — يُضبط شيءٌ فيُرى في المعاينة ويغيب عن الطابعة، وهو خلافٌ لا
     * يُكتشف إلّا بعد أن يأخذ الزبون ورقته.
     *
     * @param  array<string,mixed>  $values  إعداداتُ القالب محلولةً
     * @param  array<string,mixed>  $extra  ما يخصّ الطباعة لا المعاينة
     */
    public static function saleStrip(int $businessId, Order $order, array $values, int $width, array $extra = []): string
    {
        return view(Version::views($extra['version'] ?? null).'.thermal', [
            'order' => $order,
            /* وعملةُ الورقة من متجرها — انظر `Support\Money` */
            'currency' => Money::of($businessId),
            'paper' => $width <= 60 ? PaperSize::T58 : PaperSize::T80,
            'tpl' => self::legacy($businessId, $values),
            'tokens' => Branding::tokens($businessId, self::scale((string) $values['font'])),
            'width' => $width,
            'qr' => $extra['qr'] ?? null,
            'paperUrl' => $extra['paperUrl'] ?? '',
            'customerTax' => $extra['customerTax'] ?? null,
            'googleReview' => $extra['googleReview'] ?? null,
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

        if ($type === CustomerInvoiceController::PAPER_TYPE) {
            return self::customerInvoice($businessId, $override);
        }

        return self::generic($businessId, $type, self::latestOrSample($businessId, $type), $override);
    }

    /**
     * معاينةُ فاتورة العميل في محرّر القوالب.
     *
     * ═══ وببانيها هو لا بنسخةٍ هنا ═══
     *
     * الورقةُ تُبنى في `CustomerInvoiceController::paper` — تقرؤها منها
     * شاشةُ الإنشاء وزرُّ الطباعة. ولو بُنيت هنا ثالثةً لافترقت الثلاثةُ عند
     * أوّل متغيّرٍ يُضاف: يُضبط الشعارُ فيُرى في موضعين ويغيب عن ثالث.
     *
     * ولا مسودّةَ تُكتب: أحدثُ فاتورةٍ إن وُجدت، وإلّا **ورقةٌ غير محفوظة**
     * بلا صفٍّ ولا رقمٍ من التسلسل ولا قيد — انظر `CustomerInvoices::draft`.
     * ومحرّرُ قوالبَ يترك خلفه مسودّاتٍ في دفتر التاجر عطبٌ لا ميزة.
     *
     * @param  array<string,mixed>|null  $override  قيمٌ لم تُحفظ بعد
     */
    public static function customerInvoice(int $businessId, ?array $override = null): string
    {
        $invoice = CustomerInvoice::where('business_id', $businessId)
            ->with('items', 'customer')->latest('id')->first()
            ?? CustomerInvoices::draft($businessId, Customer::where('business_id', $businessId)->first(), [
                'issued_at' => now()->toDateString(),
                'payment_terms_days' => 30,
                'notes' => __('يُرجى السداد قبل تاريخ الاستحقاق.'),
            ], [[
                'description' => __('بند تجريبي'),
                'quantity' => 2,
                'unit_price' => 75,
                'discount' => 0,
            ]]);

        return InvoiceBranding::render(
            $businessId,
            null,
            fn () => CustomerInvoiceController::paper(
                $businessId,
                $invoice,
                $invoice->exists ? $invoice->paidTotal() : 0.0,
                $invoice->exists ? $invoice->outstanding() : (float) $invoice->total,
                BankAccount::where('business_id', $businessId)->orderBy('id')->first(),
                $override,
            )->render(),
        );
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
