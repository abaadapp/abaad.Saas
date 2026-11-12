<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Support\Document\Snapshot;
use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * فواتيرُ العملاء: إنشاؤها، وإصدارُها، وترحيلُها، وإلغاؤها.
 *
 * ═══ مالكُ ترحيل الإيراد ═══
 *
 * لكلّ حدثٍ اقتصاديٍّ مالكُ ترحيلٍ **واحد**:
 *
 *  • بيعةُ نقطة البيع → `Books::recordSale($order)`. تكتب قيدَ الإيراد
 *    وقيدَ التكلفة لحظةَ البيع، وتُدين `receivable` من نفسها حين تكون
 *    الفاتورة غير مدفوعة. فالبيعُ الآجل من نقطة البيع **لا يحتاج قيدًا
 *    ثانيًا** — يحتاج أن يُقال للطلب إنّه غير مدفوع.
 *
 *  • فاتورةٌ يدويّةٌ بلا طلب → تُرحَّل من هنا عند الإصدار:
 *    مدين ذمم العملاء / دائن المبيعات + الضريبة المستحقّة.
 *
 *  • فاتورةٌ **مولودةٌ من طلب** → **لا تُرحَّل من هنا**. هي مستندٌ فوق حدثٍ
 *    رُحّل، لا حدثٌ جديد. ولو رحّلت لظهر الإيراد مرّتين وذمّتان لدَينٍ واحد.
 *
 * ولا تكلفةَ مبيعاتٍ من هنا بحال: التكلفةُ تتبع خروجَ البضاعة، والفاتورةُ
 * ليست مستندَ مخزون. فاتورةٌ يدويّةٌ لا تُخرج شيئًا من الرفّ ولا تُقيَّد
 * تكلفتُها؛ وما خرج بطلبٍ قُيّدت تكلفتُه مع الطلب.
 */
final class CustomerInvoices
{
    public const SOURCE = 'فاتورة عميل';

    public const SOURCE_CREDIT = 'إشعار دائن';

    /** إشعارٌ كتبه التاجر — يُقيَّد */
    public const NOTE_MANUAL = 'يدوي';

    /** إشعارٌ وُلد من إلغاء طلبٍ مفوتَر — لا يُقيَّد، فقيدُه سبقه */
    public const NOTE_ORDER_CANCELLED = 'إلغاء طلب';

    /**
     * «آجل» — الورقةُ تُصدَر ولا مالَ يقع معها.
     *
     * وليست وسيلةَ سدادٍ كأخواتها: لا إيصالَ لها ولا قيدَ صندوق. وهي في
     * القائمة لأنّ من يكتب الفاتورة يختار بين «قُبض» و«لم يُقبض» في المكان
     * نفسه — لا في شاشتين.
     */
    public const CREDIT = 'آجل';

    /**
     * ما يُعرض في شاشة الفاتورة وسيلةً — «آجل» ثمّ ما يقبله التحصيل نفسُه.
     *
     * ═══ ولماذا تُشتقّ ولا تُكتب ═══
     *
     * كانت ثلاثَ قوائم: هذه مكتوبةً في `create`، ومثلُها في قاعدة المصادقة
     * `in:آجل,نقدي,بطاقة,تحويل`، وثالثةٌ مكتوبةٌ بيدها في شاشة الفاتورة
     * المفتوحة. وقائمةُ التحصيل الحقيقيّة `CustomerPayments::METHODS` فيها
     * **«شيك»** — ولم تكن في شاشة الإنشاء أصلًا. فمن قبض شيكًا مع الفاتورة
     * لم يجد وسيلته: إمّا يكتبها «تحويل» فيكذب الدفتر، وإمّا يحفظها آجلةً
     * ثمّ يفتح الورقة ويسجّل التحصيل من شاشةٍ ثانية.
     *
     * وهنا قائمةٌ واحدة تُشتقّ من مالكها: ما يقبله `CustomerPayments::record`
     * هو ما تعرضه الشاشة وهو ما تقبله المصادقة — تُضاف وسيلةٌ هناك فتظهر في
     * الثلاثة معًا.
     *
     * @return array<int, string>
     */
    public static function methods(): array
    {
        return array_merge([self::CREDIT], CustomerPayments::METHODS);
    }

    /**
     * الوسائلُ التي يدخل مالُها بنكًا — فتُسأل عن الحساب بعينه.
     *
     * والجوابُ من `CustomerPayments::sideFor` لا من شرطٍ مكتوبٍ في الشاشة:
     * كانت تقول `بطاقة || تحويل`، و«شيك» تدخل البنك مثلَهما — فتُسجَّل بلا
     * حسابٍ منسوب، ولا يجد `bank/rematch` لها ما يُسندها إليه.
     *
     * @return array<int, string>
     */
    public static function bankMethods(): array
    {
        return array_values(array_filter(
            CustomerPayments::METHODS,
            fn (string $m) => CustomerPayments::sideFor($m) === 'bank',
        ));
    }

    /** رقمٌ متسلسلٌ داخل المتجر — لا عشوائيّ، ولا يُعاد استعمالُ رقمٍ أُلغي */
    public static function nextNumber(int $businessId): string
    {
        return self::sequence($businessId, CustomerInvoice::class, 'CINV-');
    }

    /**
     * التسلسل — تحت قفلٍ على صفّ المتجر.
     *
     * وبلا قفل: نافذتان تُصدران في اللحظة نفسها تقرآن آخر رقمٍ فتكتبانه
     * كلتاهما، فيسقط أحدهما على فهرس التفرّد أو — وهو أسوأ — يمرّان على
     * قاعدةٍ بلا فهرس فيصير رقمان لورقتين.
     */
    private static function sequence(int $businessId, string $model, string $prefix): string
    {
        return DB::transaction(function () use ($businessId, $model, $prefix) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            /*
             * والأكبرُ عددًا لا الأحدثُ صفًّا.
             *
             * كان يقرأ آخرَ معرّفٍ لأنّ الرقم كان يُقطع لحظةَ الإنشاء، فترتيبُ
             * المعرّفات هو ترتيبُ الأرقام. ولم يعد: الرقمُ يُقطع عند الإصدار،
             * ومسودّةٌ كُتبت أمس تُصدَر اليوم بعد واحدةٍ كُتبت صباحًا — فآخرُ
             * صفٍّ ليس أكبرَ رقم، وقراءتُه تُعيد رقمًا مستعمَلًا يردّه فهرسُ
             * التفرّد في وجه من ضغط «إصدار».
             *
             * والقراءةُ في PHP لا `CAST` في SQL: نصٌّ غير رقميّ يتساهل معه
             * SQLite ويرفضه PostgreSQL، فرقمٌ شاذٌّ واحد من نسخةٍ مستعادة
             * يُعطّل الإصدار كلَّه بعد النقل.
             */
            $highest = (int) $model::where('business_id', $businessId)
                ->where('number', 'like', $prefix.'%')
                ->get(['number'])
                ->map(fn ($r) => (int) substr((string) $r->number, strlen($prefix)))
                ->max();

            return $prefix.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * حسابُ البنود — في الخادم دائمًا.
     *
     * ولا يُقرأ إجماليٌّ من الواجهة: من يستطيع فتح أدوات المتصفّح يستطيع
     * إرسال إجماليٍّ صفرٍ لفاتورةٍ بمئة، فتُرحَّل ذمّةٌ لا تساوي ورقتَها.
     *
     * والضريبةُ لقطة: النسبةُ تُقرأ من إعدادات المتجر لحظةَ الكتابة وتُحفظ
     * في السطر. وتغييرُها الشهرَ القادم لا يمسّ ورقةً صدرت.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float}
     */
    public static function compute(int $businessId, array $lines, ?float $taxRate = null): array
    {
        /*
         * ونسبةُ الضريبة تُقرأ لمتجر الفاتورة لا لمتجر القارئ.
         *
         * `Demo::vatSettings()` تقرأ متجرَ الجلسة — فلو أُنشئت فاتورةٌ لمتجرٍ
         * آخر (أمرُ صيانة، أو لوحةُ المنصّة) لحُسبت بنسبة متجرٍ غيره.
         *
         * ═══ ومن `Vat::rate` لا بقراءةٍ تُكتب هنا ═══
         *
         * كانت مكتوبةً بيدها: صفُّ المتجر، وإلّا خمسة. و`Vat::rate` تهبط
         * إلى **افتراضيّ المنصّة** قبل الخمسة — وهي التي تقرؤها نقطةُ البيع.
         * فمتجرٌ لم يضبط نسبتَه ونسبةُ المنصّة عشرة: الكاشيرُ يجبي عشرة
         * وفاتورةُ العميل تطلب خمسة، في المتجر نفسه واليوم نفسه. وقُيس.
         *
         * والإطفاءُ داخلها: `rate()` تردّ صفرًا للمتجر المطفأ.
         */
        $rate = $taxRate ?? Vat::rate($businessId);

        /* ويُقرأ مرّةً لا لكلّ بند: استعلامٌ في حلقةٍ على فاتورةٍ بأربعين بندًا */
        $inclusive = Vat::inclusive($businessId);

        $items = [];
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $sort = 0;

        foreach ($lines as $line) {
            $qty = round((float) ($line['quantity'] ?? 1), 3);
            $price = round((float) ($line['unit_price'] ?? 0), 3);
            $lineDiscount = round((float) ($line['discount'] ?? 0), 3);

            if ($qty <= 0) {
                continue;
            }

            $gross = round($qty * $price, 3);

            /*
             * ═══ والخصمُ المحتسَب هو المطبَّق، لا المطلوب ═══
             *
             * كان البندُ يُقصَر على صفرٍ (`max(0, ...)`) بينما يُجمَع الخصمُ
             * **كما طُلب** في رأس الفاتورة. فبندٌ بعشرةٍ وخصمٍ بخمسةٍ وعشرين
             * يُخرج سطرًا مجموعُه صفرٌ وإجماليًّا في الرأس **سالبًا بخمسة
             * عشر** — ورقةٌ بنودُها لا تجمع إلى مجموعها.
             *
             * والخصمُ السالب أسوأ: `items.*.discount` يقبل `numeric` بلا
             * `min:0`، فقيمةٌ سالبة كانت تُنقص من الخصم فتضخّم الإجماليَّ
             * بلا سطرٍ يقابلها.
             *
             * فيُحسب مرّةً ما **وقع فعلًا**، ويُجمَع هو في الرأس. فيصير
             * `subtotal − discount + tax` مساويًا لمجموع البنود دائمًا،
             * وهي الخاصّيّةُ التي تُراجَع بها الورقة.
             */
            $lineDiscount = round(min(max(0.0, $lineDiscount), $gross), 3);
            $net = round($gross - $lineDiscount, 3);
            // بندٌ معفًى يُكتب بنسبة صفر — والإعفاء لقطةٌ في السطر لا قاعدةٌ عامّة
            $lineRate = array_key_exists('tax_rate', $line) ? (float) $line['tax_rate'] : $rate;
            /*
             * ═══ و«مشمولة في السعر» تُستخرَج من المبلغ لا تُضاف فوقه ═══
             *
             * المفتاح في «الإعدادات ‹ الضرائب» يحكم المتجرَ كلَّه، وتقرؤه
             * نقطةُ البيع (`PosController`) وتصحيحُ الطلب (`OrderCorrection`)
             * بالصيغة نفسِها. وكانت هذه الدالّةُ وحدَها تُضيف دائمًا.
             *
             * فتاجرٌ يسعّر شاملًا — وهو حالُ أكثر المحلّات في عُمان — يكتب
             * ١٠٥ فتخرج ورقتُه بـ١١٠٫٢٥: يُطالَب عميلُه بضريبةٍ مرّتين، ويُقرّ
             * هو بضريبةٍ لم يقبضها. وقُيس قبل الإصلاح.
             *
             * والصيغةُ صيغةُ الموضعين الآخرين حرفًا بحرف: `net × r ÷ (100+r)`.
             */
            $lineTax = $inclusive
                ? round($net * $lineRate / (100 + $lineRate), 3)
                : round($net * $lineRate / 100, 3);

            $subtotal = round($subtotal + $gross, 3);
            $discount = round($discount + $lineDiscount, 3);
            $tax = round($tax + $lineTax, 3);

            $items[] = [
                'product_id' => $line['product_id'] ?? null,
                // منتجٌ أو إضافةٌ أو لا شيء — والفراغُ من الشاشة فراغٌ لا صفر
                'addon_id' => ! empty($line['addon_id']) ? (int) $line['addon_id'] : null,
                'order_item_id' => $line['order_item_id'] ?? null,
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $lineDiscount,
                // ومنه نصيبُ النقاط — داخلَ الخصم لا فوقه (`withLoyalty`)
                'loyalty_discount' => round(min(max(0.0, (float) ($line['loyalty_discount'] ?? 0)), $lineDiscount), 3),
                'tax_rate' => $lineRate,
                'tax_amount' => $lineTax,
                /*
                 * والمبلغُ ما يدفعه العميل عن هذا البند.
                 *
                 * في «غير الشامل» هو الصافي والضريبةُ فوقه؛ وفي «الشامل»
                 * الصافي **هو** المبلغ، وضريبتُه مستخرَجةٌ منه. وإضافتُها
                 * ثانيةً تجعل مجموعَ البنود أكبرَ من إجمالي الورقة — وهي
                 * الخاصّيّةُ التي يُراجَع بها ورقٌ رسميّ.
                 */
                'line_total' => $inclusive ? $net : round($net + $lineTax, 3),
                'sort_order' => $sort++,
                /*
                 * وما لا مالَ فيه يمرّ كما جاء — لقطةُ ما اختير مع البند، ومن
                 * يتبع منها مَن (`InvoiceLineDetails::expand`). لا يُحسب منه رقم.
                 */
                'details' => ! empty($line['details']) ? $line['details'] : null,
            ] + array_filter([
                'group' => $line['group'] ?? null,
                'parent' => $line['parent'] ?? null,
            ], fn ($v) => $v !== null);
        }

        /*
         * والمجموعُ الفرعيُّ صافٍ من الضريبة حين تكون مشمولة.
         *
         * وهي صيغةُ `OrderCorrection` نفسُها: `gross − tax`. فيبقى
         * `subtotal − discount + tax` مساويًا لمجموع البنود في الحالين —
         * وهي القاعدةُ التي تُراجَع بها الورقة سطرًا سطرًا.
         */
        $subtotal = $inclusive ? round($subtotal - $tax, 3) : $subtotal;

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => round($subtotal - $discount + $tax, 3),
        ];
    }

    /**
     * أعمدةُ الورقة كما تُكتب — من موضعٍ واحد تقرؤه المحفوظةُ والمعاينةُ معًا.
     *
     * ═══ ولماذا خرجت من `create` ═══
     *
     * المعاينةُ الحيّة في شاشة الإنشاء تُرسم بالقالب الذي يُطبع
     * (`pdf.customer-invoice`)، والقالبُ يقرأ صفَّ فاتورة. فلو بُنيت المعاينةُ
     * صفًّا ثانيًا مكتوبًا بيده لافترق عن المحفوظ عند أوّل عمودٍ يُضاف: يُقرأ
     * «القسم» من العميل في الحفظ ولا يُقرأ في المعاينة، فيرى التاجر ورقةً
     * ويستلم عميلُه غيرَها. وهي القاعدةُ نفسُها المكتوبة في `DocumentRenderer`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $totals  ما ردّته `compute`
     * @return array<string, mixed>
     */
    private static function attributes(int $businessId, ?Customer $customer, array $data, array $totals): array
    {
        $terms = $data['payment_terms_days'] ?? $customer?->payment_terms_days;
        $issuedAt = isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : now();

        return [
            'business_id' => $businessId,
            'customer_id' => $customer?->id,
            'branch_id' => $data['branch_id'] ?? null,
            /*
             * ولا رقمَ للمسودّة.
             *
             * كان يُقطع هنا، فمسودّةٌ تُهجر تأخذ رقمَها معها ويقفز التسلسل
             * في دفترٍ يُقرأ عند الضريبة. والرقمُ يُقطع عند الإصدار — حيث
             * يقع القيد. انظر `issue`.
             */
            'number' => null,
            'status' => CustomerInvoice::DRAFT,
            'issued_at' => $issuedAt->toDateString(),
            'due_at' => $data['due_at']
                ?? ($terms !== null ? $issuedAt->copy()->addDays((int) $terms)->toDateString() : null),
            'payment_terms_days' => $terms,
            /*
             * وعملةُ الورقة عملةُ المتجر لا عملةُ عرض القارئ.
             *
             * `displayCurrency` تفضيلُ مشاهدةٍ يُبدَّل من الترويسة — وورقةٌ
             * صدرت لا يغيّر عملتَها من فتحها. ولا صرفَ أجنبيًّا هنا: النظام
             * يمسك عملةً تشغيليّةً واحدة، والباقي عرضٌ.
             */
            'currency' => Currency::where('business_id', $businessId)
                ->where('is_base', true)->value('code'),
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount'],
            'tax_total' => $totals['tax'],
            'total' => $totals['total'],
            'customer_name' => $customer?->legal_name ?: $customer?->name,
            'customer_tax_number' => $customer?->tax_number,
            'customer_cr' => $customer?->commercial_registration,
            'customer_address' => $customer?->billing_address ?: $customer?->address,
            'po_number' => $data['po_number'] ?? null,
            'contract_number' => $data['contract_number'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'department' => $data['department'] ?? $customer?->department,
            'cost_center' => $data['cost_center'] ?? null,
            'attention_to' => $data['attention_to'] ?? $customer?->contact_person,
            'notes' => $data['notes'] ?? null,
            // وما لا يُطبع: عمودٌ آخر لا يبلغ ورقةَ العميل
            'internal_notes' => $data['internal_notes'] ?? null,
        ];
    }

    /**
     * ورقةٌ **غيرُ محفوظة** — لمعاينة ما لم يُكتب بعد.
     *
     * ولا تلمس القاعدة: لا صفَّ يُكتب، ولا رقمَ يُقطع، ولا قيدَ يقع. من فتح
     * شاشة الإنشاء وكتب بندًا ثمّ تركها لا يجب أن يترك خلفه مسودّةً في
     * دفتره ولا فجوةً في تسلسل أرقامه.
     *
     * والعميلُ قد لا يكون اختير بعد — والمعاينةُ تُرسم على أيّ حال: من يكتب
     * البنودَ قبل أن يختار الجهة يرى ورقتَه تتشكّل، ولا تُقفل عليه الشاشةُ
     * حتى يملأ حقلًا بعينه.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function draft(int $businessId, ?Customer $customer, array $data, array $lines): CustomerInvoice
    {
        if ($customer !== null && (int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء متجرك.'));
        }

        /*
         * والمعاينةُ تُرسم بخصم النقاط كما سيُكتب — بالحساب نفسِه، ولا تُخصم
         * نقطة: لا شيء يُكتب هنا. وما لا يجوز يُعرض بلا خصم، والكتابةُ هي
         * التي تردّ وتقول لماذا.
         */
        $loyalty = ['points' => 0, 'discount' => 0.0];
        if ($customer !== null && (int) ($data['redeem_points'] ?? 0) > 0) {
            $quote = self::loyaltyQuote($businessId, $customer, $lines, (int) $data['redeem_points']);
            if ($quote['points'] > 0) {
                $loyalty = $quote;
                $lines = self::withLoyalty($lines, $quote['discount']);
            }
        }

        $totals = self::compute($businessId, $lines);

        $invoice = new CustomerInvoice(self::attributes($businessId, $customer, $data, $totals) + [
            'loyalty_points' => $loyalty['points'],
            'loyalty_discount' => $loyalty['discount'],
        ]);

        /*
         * والبنودُ تُعلَّق بلا حفظ.
         *
         * `setRelation` يجعل `$invoice->items` تردّ ما بُني هنا بدل أن تسأل
         * القاعدةَ عن صفوفٍ لا وجود لها — والقالبُ يمرّ عليها كما يمرّ على
         * بنود ورقةٍ محفوظة، فهو قالبٌ واحد لا اثنان.
         */
        $invoice->setRelation('items', collect($totals['items'])->map(
            fn (array $item) => new CustomerInvoiceItem($item)
        ));

        if ($customer !== null) {
            $invoice->setRelation('customer', $customer);
        }

        return $invoice;
    }

    /**
     * بنودُ الإضافات كما يكتبها الخادم — لا كما أرسلتها الشاشة.
     *
     * فاتورةُ العميل اليدويّة تقبل **أيَّ** إضافةٍ نشطةٍ للمتجر بندًا مستقلًّا:
     * لا تُسأل `product_addons` ولا مدى الإضافة (`scope`)، فتلك قيودُ ما يُعرض
     * مع منتجٍ في الصندوق والموقع. والورقةُ مستندٌ ماليٌّ يكتبه صاحبُ المحلّ،
     * وقد يفوتر شوكولاتةً وحدَها بلا بوكيه.
     *
     * ═══ والسعرُ والاسمُ من القاعدة ═══
     *
     * بندٌ يحمل `addon_id` يقول «هذه الإضافة من الكتالوج» — فثمنُه ثمنُها
     * واسمُه اسمُها، يُقرآن هنا من متجر الفاتورة ويُكتبان لقطةً. وما أرسلته
     * أدواتُ المتصفّح في `unit_price` لا يُقرأ. ومن أراد سعرًا بيده يكتب
     * بندًا يدويًّا («إضافة بند»)، لا إضافةً من الكتالوج بثمنٍ غير ثمنها.
     *
     * والكميّةُ والخصمُ والضريبةُ كسائر البنود: الخصمُ تخفيضٌ ظاهرٌ في
     * الورقة لا ثمنٌ مزوَّر، والضريبةُ لقطةٌ تُقرأ من المتجر إن لم تُرسَل.
     *
     * ═══ وإضافةُ متجرٍ آخر أو موقوفةٌ ═══
     *
     * الحفظُ يردّها (`$strict`) — والمعاينةُ تُسقط بندَها: ورقةٌ تُرسم وهي
     * تُكتب لا تُردّ بخطأ، ولا تُرسم بإضافةِ غيرِ متجرها اسمًا ولا ثمنًا.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function addonsFromCatalog(int $businessId, array $lines, bool $strict = true): array
    {
        $ids = collect($lines)->pluck('addon_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return $lines;
        }

        $addons = Addon::where('business_id', $businessId)
            ->where('active', true)
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'price'])
            ->keyBy('id');

        $out = [];

        foreach ($lines as $line) {
            if (empty($line['addon_id'])) {
                $out[] = $line;

                continue;
            }

            $addon = $addons->get((int) $line['addon_id']);

            if (! $addon) {
                if ($strict) {
                    throw new RuntimeException(__('هذه الإضافة ليست من إضافات متجرك النشطة.'));
                }

                continue;
            }

            $out[] = array_merge($line, [
                'addon_id' => (int) $addon->id,
                'product_id' => null,
                'description' => (string) $addon->name,
                'unit_price' => round((float) $addon->price, 3),
            ]);
        }

        return $out;
    }

    /**
     * إنشاءُ فاتورةٍ — مسودّةً أو صادرة.
     *
     * والمنتجُ يُقرأ من متجر الفاتورة لا من الطلب: معرّفٌ من متجرٍ آخر يُرفض
     * هنا لا في الشاشة.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function create(int $businessId, Customer $customer, array $data, array $lines, ?int $userId = null): CustomerInvoice
    {
        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء متجرك.'));
        }

        foreach ($lines as $line) {
            // والبندُ منتجٌ أو إضافة — لا إضافةٌ تلبس منتجًا ولا العكس
            if (! empty($line['product_id']) && ! empty($line['addon_id'])) {
                throw new RuntimeException(__('البند منتجٌ أو إضافة — لا الاثنان معًا.'));
            }

            if (! empty($line['product_id'])) {
                $ok = Product::where('business_id', $businessId)->whereKey($line['product_id'])->exists();
                if (! $ok) {
                    throw new RuntimeException(__('هذا الصنف ليس من أصناف متجرك.'));
                }
            }

            /*
             * والإضافةُ كالمنتج: من متجر الفاتورة أو لا تُكتب.
             *
             * ولا يُسأل هنا عن نشاطها: الفاتورةُ المولودة من طلبٍ تنقل إضافةً
             * بيعت يومَها ثمّ أُوقفت — ولقطتُها هي الحقيقة. والنشاطُ يُسأل حيث
             * تُختار الإضافةُ من الكتالوج (`addonsFromCatalog`).
             */
            if (! empty($line['addon_id'])) {
                $ok = Addon::where('business_id', $businessId)->whereKey($line['addon_id'])->exists();
                if (! $ok) {
                    throw new RuntimeException(__('هذه الإضافة ليست من إضافات متجرك.'));
                }
            }
        }

        return DB::transaction(function () use ($businessId, $customer, $data, $lines, $userId) {
            /*
             * ═══ ونقاطُ الولاء تُحسب مع الورقة — وتُخصم عند إصدارها ═══
             *
             * المسودّةُ تحمل الخصمَ في بنودها ولا تمسّ الرصيد: مسودّةٌ تُهجر لا
             * تحبس نقاطَ العميل إلى أجلٍ لا ينتهي. والخصمُ من الرصيد يقع في
             * `issue` — تحت قفل العميل، بشرطٍ في جملة التحديث وبمفتاحٍ فريد،
             * وبعد إعادة فحص الرصيد والقواعد. وإصدارُ الورقة مع كتابتها (وهو
             * الغالب) يقع في المعاملة نفسِها.
             *
             * والمطلوبُ فوق ما يجوز يُردّ لا يُحصر: الشاشةُ عرضت مبلغًا، وورقةٌ
             * تُكتب بغيره يقرؤها العميلُ بعد أن وافق على الأوّل.
             *
             * ═══ والمقياسُ النقاطُ وقيمتُها معًا — كما في الصندوق ═══
             *
             * الشاشةُ ترسل ما وافق عليه التاجر: عددَ النقاط (`redeem_points`)
             * وقيمتَها (`redeem_discount`). وكان يُقارن العددُ وحده، فقاعدةٌ
             * غيّرت قيمةَ النقطة بين العرض والحفظ تُكتب بها الورقةُ بخصمٍ غيرِ
             * الذي رآه — في صمت. فيُقارنان معًا، وما اختلف يُردّ بالعرض الصحيح
             * الآن ليختار من جديد (`PosController::resolveRedemption` أخوه).
             * ومن لا يرسل القيمةَ (طلبُ واتساب، والفاتورةُ من طلب) يُقارن عددُه.
             */
            $loyalty = ['points' => 0, 'discount' => 0.0];
            $requested = (int) ($data['redeem_points'] ?? 0);
            $agreed = isset($data['redeem_discount']) && $data['redeem_discount'] !== ''
                ? round((float) $data['redeem_discount'], 3)
                : null;

            if ($requested > 0) {
                $fresh = $customer->fresh() ?? $customer;
                $quote = self::loyaltyQuote($businessId, $fresh, $lines, $requested);
                if ($quote['points'] !== $requested || ($agreed !== null && abs($quote['discount'] - $agreed) > 0.0005)) {
                    $now = self::loyaltyQuote($businessId, $fresh, $lines);
                    $currency = Money::of($businessId);

                    throw ValidationException::withMessages([
                        'redeem_points' => $now['max_points'] > 0
                            ? __('تغيّر رصيد نقاط العميل أو قواعد الاستبدال — الرصيد الآن :balance نقطة، والخصم المتاح :discount مقابل :points نقطة. اختر من جديد قبل الحفظ.', [
                                'balance' => (int) $now['balance'], 'discount' => Money::format($now['max_discount'], $currency), 'points' => $now['max_points'],
                            ])
                            : __('تغيّر رصيد نقاط العميل أو قواعد الاستبدال — الرصيد الآن :balance نقطة، ولا خصمَ بالنقاط على هذه الفاتورة. احفظها بلا خصم.', [
                                'balance' => (int) $now['balance'],
                            ]),
                    ]);
                }

                $loyalty = $quote;
                $lines = self::withLoyalty($lines, $quote['discount']);
            }

            $totals = self::compute($businessId, $lines);

            $invoice = CustomerInvoice::create(
                self::attributes($businessId, $customer, $data, $totals) + [
                    'created_by' => $userId,
                    'loyalty_points' => $loyalty['points'],
                    'loyalty_discount' => $loyalty['discount'],
                ]
            );


            /*
             * والإضافةُ المدفوعةُ تعرف صنفَها — بمجموعته في النموذج، تُقرأ هنا
             * مفتاحًا بعد أن يُكتب الصنفُ قبلها. والمالُ في سطرها كما حُسب.
             */
            $written = [];

            foreach ($totals['items'] as $item) {
                $group = $item['group'] ?? null;
                $parent = $item['parent'] ?? null;
                unset($item['group'], $item['parent']);

                $row = CustomerInvoiceItem::create($item + [
                    'customer_invoice_id' => $invoice->id,
                    'parent_item_id' => $parent !== null ? ($written[$parent] ?? null) : null,
                ]);

                if ($group !== null) {
                    $written[$group] = $row->id;
                }
            }

            // ومتجرُ الورقة لا متجرُ الجلسة — فاتورةٌ يكتبها الطلبُ الآليّ لا جلسةَ له (`WhatsAppOrderCheckout`)
            Activity::log('created', 'أنشأ مسودّة فاتورة عميل — '.$customer->name, [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice', 'business_id' => $businessId,
            ]);

            return $invoice->fresh('items');
        });
    }

    /**
     * إصدارُ الفاتورة — وهنا يقع القيد، إن كانت هي مالكةَ الحدث.
     *
     * ولا يُصدَر ما صدر: نداءان لا يكتبان قيدين.
     */
    public static function issue(CustomerInvoice $invoice, ?int $userId = null): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            /*
             * والحالةُ تُقرأ تحت قفلٍ داخل المعاملة لا قبلها.
             *
             * ضغطتان على «إصدار» — أو ردٌّ يبطؤ فيُعاد الطلب — كانتا تقرآن
             * «مسودة» كلتاهما خارج المعاملة فتمضيان: قيدان لفاتورةٍ واحدة،
             * وإيرادٌ مضاعف، وذمّتان لدَينٍ واحد. والقفلُ يجعل الثانيةَ تنتظر
             * ثمّ تجدها صادرةً فتخرج بها.
             */
            $locked = CustomerInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === CustomerInvoice::CANCELLED) {
                throw new RuntimeException(__('لا تُصدَر فاتورةٌ ملغاة.'));
            }
            if ($locked->status === CustomerInvoice::ISSUED) {
                return $locked;
            }
            /*
             * ولا تُصدَر ورقةٌ بلا مبلغ — إلّا ما غطّته نقاطُ العميل كلَّه.
             *
             * سقوفُ الاستبدال لصاحب النشاط، فقد يجعلها تغطّي الفاتورةَ كلَّها
             * كما يبيع الصندوقُ بلا مبلغ. فتُصدَر بصفر: تُصرف نقاطُها هنا مرّةً،
             * ولا قيدَ لها (`post`)، وتُقرأ مسدَّدةً لأنّ باقيها صفر، ولا يُطلب لها
             * رابطُ دفع (`PaymentLinks`). وما عداها من صفرٍ يُردّ كما كان.
             */
            if ((float) $locked->total <= 0 && ! self::coveredByPoints($locked)) {
                throw new RuntimeException(__('لا تُصدَر فاتورةٌ بلا مبلغ.'));
            }

            // ونقاطُها تُخصم الآن — مرّةً، وبعد أن يُعاد فحصُ الرصيد والقواعد
            self::spendPoints($locked);

            /*
             * وهنا يُقطع الرقم — لا قبلُ.
             *
             * `?:` لا إسنادٌ مطلق: مسودّةٌ كُتبت تحت القاعدة القديمة تحمل
             * رقمًا حجزته، فتُصدَر به ولا تُرقَّم ثانيةً. وورقةٌ لا تُرقَّم
             * مرّتين ولو أُعيد نداءُ الإصدار عليها.
             */
            $locked->update([
                'number' => $locked->number ?: self::nextNumber((int) $locked->business_id),
                'status' => CustomerInvoice::ISSUED,
                'issued_by_at' => now(),
                /*
                 * وتُختم بحال متجرها لحظةَ الإصدار — لا لحظةَ الكتابة.
                 *
                 * المسودّةُ تُكتب اليوم وتُصدَر بعد أسبوع، وما بينهما قد
                 * يتغيّر اسمُ المتجر أو رقمُه الضريبيّ أو عملتُه. والورقةُ
                 * الرسميّة هي التي صدرت، فحالُها حالُ ساعتها.
                 * انظر `Document\Snapshot`.
                 */
                Snapshot::COLUMN => Snapshot::capture((int) $locked->business_id),
            ]);

            self::post($locked, $userId);

            Activity::log('updated', 'أصدر فاتورة عميل '.$locked->number.' بمبلغ '.$locked->total, [
                'subject_id' => $locked->id, 'subject_type' => 'customer_invoice', 'business_id' => (int) $locked->business_id,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * القيد — ولا يُكتب لفاتورةٍ وُلدت من طلب.
     *
     * `Books::recordSale` رحّلت ذلك الطلب لحظةَ وقوعه: إيرادَه وضريبتَه
     * وتكلفتَه، وأدانت `receivable` إن كان آجلًا. فقيدٌ ثانٍ هنا يعني إيرادًا
     * مضاعفًا وذمّتين لدَينٍ واحد.
     */
    private static function post(CustomerInvoice $invoice, ?int $userId): void
    {
        if ($invoice->coversOrders()) {
            return;
        }

        $net = round((float) $invoice->subtotal - (float) $invoice->discount_total, 3);
        $tax = round((float) $invoice->tax_total, 3);

        /*
         * ═══ وورقةٌ غطّتها النقاطُ كلُّها لا قيدَ لها ═══
         *
         * خصمُ النقاط يُنقص الإيرادَ في البنود (لا حسابَ له في الدليل)، فيُقيَّد
         * ما بقي: ذمّةٌ وإيرادٌ وضريبة. وحين لا يبقى شيء فكلُّها أصفار، والقيدُ
         * لا يُكتب بسطرٍ واحدٍ صفريّ (`Ledger::post`). وهي قاعدةُ الصندوق في
         * إيراد بيعةٍ إجماليُّها صفر (`Books::recordSale`) — والصندوقُ يكتب بعدها
         * تكلفةَ ما خرج من الرفّ إن كانت له تكلفة؛ وفاتورةُ العميل اليدويّة لا
         * تُخرج مخزونًا ولا تكتب تكلفةً بأيّ مبلغ، فلا يسقط هنا شيءٌ غيرُ الأصفار.
         * والإلغاءُ لا يجد قيدًا يعكسه فلا يعكس شيئًا، ويعيد النقاطَ وحدها.
         *
         * ═══ وما لا قيدَ له يسأل عن الفترة بنفسه ═══
         *
         * الفترةُ المقفلة (أو المقدَّمُ إقرارُها) يسألها الدفترُ عند الكتابة
         * (`Ledger::post` ← `BooksPeriod::assertOpen`). وورقةٌ لا تبلغ الدفتر
         * لا يسألها أحد: فاتورةٌ بتاريخ شهرٍ مقفل تُصدَر وتصرف نقاطها، وأختُها
         * بمبلغٍ تُردّ. فيُسأل هنا السؤالُ نفسُه، والردُّ يُرجع صرفَ النقاط مع
         * المعاملة كلّها.
         */
        if (round((float) $invoice->total, 3) <= 0 && $net <= 0 && $tax <= 0) {
            BooksPeriod::assertOpen((int) $invoice->business_id, Carbon::parse($invoice->issued_at));

            return;
        }

        $lines = [['account' => 'receivable', 'debit' => round((float) $invoice->total, 3)]];

        if ($net > 0) {
            $lines[] = ['account' => 'sales', 'credit' => $net];
        }
        if ($tax > 0) {
            $lines[] = ['account' => 'tax_payable', 'credit' => $tax];
        }

        Ledger::post(
            (int) $invoice->business_id,
            __('فاتورة عميل ').$invoice->number,
            $lines,
            Carbon::parse($invoice->issued_at),
            self::SOURCE,
            $invoice->branch_id,
            $userId,
            $invoice,
        );
    }

    /**
     * سطورُ الدفتر لما يرحّله هذا الصنفُ بنفسه — وعكوسُها.
     *
     * الفاتورةُ اليدويّة (`post`)، وإشعارُ الدائن اليدويّ (`creditNote`)،
     * والقيدُ العكسيّ لإلغاء الفاتورة (`cancel` → `Ledger::reverse`) في يومه.
     *
     * ═══ وبهذا لا تُعدّ بيعةٌ مرّتين ═══
     *
     * الفاتورةُ المولودة من طلبٍ — واحدًا كان أو ورقةَ شهرٍ — لا قيدَ لها
     * (`post`)، وإشعارُ إلغاء الطلب لا قيدَ له (`NOTE_ORDER_CANCELLED`): قيدُ
     * الطلب وعكسُه في `Books`. فمن قرأ الطلبات ثمّ هذه السطور لا يقرأ بيعةً
     * مرّتين — وهو ما يفعله إقرارُ الضريبة وملخّصُ المبيعات.
     *
     * والعكسُ يُعرف بقيده الأصليّ لا باسمه: اسمُ العكس يُكتب بلغة من ألغى
     * (`__('عكس ')`).
     *
     * @return Builder
     */
    public static function ledgerLines(int $businessId)
    {
        $sources = [self::SOURCE, self::SOURCE_CREDIT];

        return DB::table('journal_lines')
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->leftJoin('journal_entries as orig', 'orig.id', '=', 'je.reverses_id')
            ->where('je.business_id', $businessId)
            ->where('a.business_id', $businessId)
            ->where('je.posted', true)
            ->where(fn ($q) => $q->where(fn ($own) => $own->whereNull('je.reverses_id')->whereIn('je.source', $sources))
                ->orWhereIn('orig.source', $sources));
    }

    /**
     * الإلغاء — عكسٌ لا محو.
     *
     * ومستندٌ ماليٌّ صدر لا يُحذف: يبقى مقروءًا موسومًا بالإلغاء، ويُكتب
     * مقابله عكسُه في الدفتر. ومن ألغى ومتى ولمَ — كلُّه في السجلّ.
     *
     * ولا تُلغى فاتورةٌ سُدِّد منها شيء: ذاك إرجاعٌ يحتاج إشعارَ دائنٍ وردَّ
     * مال، لا شطبًا صامتًا يترك مالًا مقبوضًا بلا مقابل.
     */
    public static function cancel(CustomerInvoice $invoice, string $reason, ?int $userId = null): CustomerInvoice
    {
        if ($invoice->status === CustomerInvoice::CANCELLED) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $reason, $userId) {
            /*
             * ═══ والحالةُ تُقرأ تحت القفل، لا من النسخة التي في اليد ═══
             *
             * النسخةُ قُرئت قبل المعاملة. فمسودّةٌ يُصدرها أحدٌ في اللحظة التي
             * يُلغيها فيها آخر: الإصدارُ يكتب قيدَه ويُغلق، والإلغاءُ يقرأ
             * «مسودّة» من يده فلا يعكس شيئًا، ثمّ يكتب «ملغاة» فوق «صادرة».
             * فاتورةٌ ملغاةٌ وذمّتُها وإيرادُها باقيان في الدفتر. فيُقفل الصفّ
             * (والإصدارُ يقفله أيضًا) ويُقرأ من جديد، وعليه يُبنى كلُّ ما بعده.
             */
            $invoice = CustomerInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status === CustomerInvoice::CANCELLED) {
                return $invoice;
            }
            if ($invoice->paidTotal() > 0) {
                throw new RuntimeException(__('سُدِّد من هذه الفاتورة :n — ألغِ التحصيل أو أصدر إشعار دائن قبل الإلغاء.', ['n' => $invoice->paidTotal()]));
            }

            if ($invoice->status === CustomerInvoice::ISSUED && ! $invoice->coversOrders()) {
                $entry = JournalEntry::where('business_id', $invoice->business_id)
                    ->where('sourceable_type', CustomerInvoice::class)
                    ->where('sourceable_id', $invoice->id)
                    ->where('source', self::SOURCE)
                    ->latest('id')->first();

                if ($entry) {
                    Ledger::reverse($entry, now(), $userId, __('إلغاء فاتورة عميل ').$invoice->number);
                }
            }

            $invoice->update([
                'status' => CustomerInvoice::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            // وما صُرف عليها من نقاطٍ يعود إلى صاحبه — كما يعيده إلغاءُ بيعة الصندوق
            self::returnPoints($invoice);

            Activity::log('deleted', 'ألغى فاتورة عميل '.$invoice->number.' — '.$reason, [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice',
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة.
     *
     * فاتورةٌ بمئة سُدِّد منها ثلاثون ورُدّ بعشرين: الباقي خمسون. ولو خُفّض
     * الإجماليُّ إلى ثمانين لقالت الورقةُ في يد الزبون غيرَ ما يقوله النظام.
     */
    public static function creditNote(
        CustomerInvoice $invoice,
        float $amount,
        float $taxAmount,
        string $reason,
        ?int $userId = null,
        string $source = self::NOTE_MANUAL,
        ?int $orderId = null,
    ): CustomerCreditNote {
        $amount = round($amount, 3);

        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغ إشعار الدائن يجب أن يكون أكبر من صفر.'));
        }

        return DB::transaction(function () use ($invoice, $amount, $taxAmount, $reason, $userId, $source, $orderId) {
            /*
             * والحدُّ يُقرأ تحت قفل المتجر، من الورقة كما هي الآن.
             *
             * كان يُقرأ قبل المعاملة: إشعاران معًا يقرآن «الباقي» نفسَه فيمرّان،
             * ومجموعُهما فوق الفاتورة. والقفلُ نفسُه يأخذه الترقيمُ بعد سطرين.
             *
             * والإشعارُ اليدويّ لا يتجاوز «الإجماليّ − ما رُدّ» ولو سُدّدت
             * الورقة: `outstanding` تُحصر عند الصفر، فكان «الباقي + المسدَّد»
             * يسمح لفاتورةٍ مدفوعةٍ بإشعارٍ كاملٍ بعد إشعارٍ كامل. وإشعارُ
             * إلغاء الطلب حدُّه كما كان (`onOrderCancelled`) — ولا نقاطَ على
             * ورقةٍ تجمع طلبات. يحرسه `TwoHandsOnOneCustomersPointsAtOnceTest`.
             */
            Business::whereKey($invoice->business_id)->lockForUpdate()->first();
            $invoice = CustomerInvoice::where('business_id', $invoice->business_id)->findOrFail($invoice->id);

            $room = $invoice->outstanding() + $invoice->paidTotal();
            if ($source === self::NOTE_MANUAL) {
                $room = min($room, (float) $invoice->total - $invoice->creditedTotal());
            }
            if ($amount > round($room, 3)) {
                throw new RuntimeException(__('لا يتجاوز إشعارُ الدائن قيمةَ الفاتورة.'));
            }

            $note = CustomerCreditNote::create([
                'business_id' => $invoice->business_id,
                'customer_invoice_id' => $invoice->id,
                'number' => self::sequence((int) $invoice->business_id, CustomerCreditNote::class, 'CN-'),
                'amount' => $amount,
                'tax_amount' => round($taxAmount, 3),
                'issued_at' => now()->toDateString(),
                'reason' => $reason,
                'source' => $source,
                'order_id' => $orderId,
                'created_by' => $userId,
            ]);

            // وما صُرف عليها من نقاطٍ يعود بنسبة ما رُدّ — في المعاملة نفسِها
            self::returnPointsForCredit($invoice, $note);

            /*
             * والقيدُ يتبع المصدر.
             *
             * إشعارٌ يكتبه التاجر بيده حدثٌ ماليٌّ جديد فيُقيَّد. وإشعارٌ يولد
             * من إلغاء طلبٍ لا يُقيَّد: `Books::unpostSale` عكست قيدَ ذلك الطلب
             * لحظةَ إلغائه — إيرادَه وضريبتَه وذمّتَه — فقيدٌ ثانٍ هنا يُنقص
             * الذمّة مرّتين ويجعل الدفتر يقول غيرَ ما تقوله الفواتير.
             */
            if ($source !== self::NOTE_MANUAL) {
                Activity::log('created', 'أُصدر إشعار دائن '.$note->number.' بإلغاء طلب — '.$reason, [
                    'subject_id' => $note->id, 'subject_type' => 'customer_credit_note',
                ]);

                return $note;
            }

            $net = round($amount - $taxAmount, 3);
            $lines = [];
            if ($net > 0) {
                $lines[] = ['account' => 'sales_returns', 'debit' => $net];
            }
            if ($taxAmount > 0) {
                $lines[] = ['account' => 'tax_payable', 'debit' => round($taxAmount, 3)];
            }
            $lines[] = ['account' => 'receivable', 'credit' => $amount];

            Ledger::post(
                (int) $invoice->business_id,
                __('إشعار دائن ').$note->number.' — '.$invoice->number,
                $lines,
                now(),
                self::SOURCE_CREDIT,
                $invoice->branch_id,
                $userId,
                $note,
            );

            Activity::log('created', 'أصدر إشعار دائن '.$note->number.' بمبلغ '.$amount.' على '.$invoice->number, [
                'subject_id' => $note->id, 'subject_type' => 'customer_credit_note',
            ]);

            return $note;
        });
    }

    /**
     * فوترةُ الشهر — ورقةٌ واحدة تغطّي عدّة طلبات.
     *
     * شركةٌ تشتري ثلاث مرّاتٍ في الشهر لا تريد ثلاثَ فواتير: تريد ورقةً
     * واحدة بمجموعها آخرَ الشهر.
     *
     * ولا تُفوتَر إلّا البيعاتُ الآجلة: بيعةٌ نقديّةٌ قُبض ثمنُها ولا ذمّةَ
     * لها، ووضعُها في فاتورةٍ يُنشئ التزامًا لا وجود له في الدفتر — فتفترق
     * الذمّةُ التشغيليّة عن رصيد `receivable`.
     *
     * ولا طلبَ في ورقتين: الطلبُ يُقفل بقفل الكتابة ويُفحص، فلا تُطالَب
     * شركةٌ بمبلغٍ مرّتين.
     *
     * ولا قيدَ لهذه الورقة: كلُّ طلبٍ فيها رُحّل لحظةَ وقوعه.
     *
     * @param  array<int, int>  $orderIds
     */
    public static function consolidate(Customer $customer, array $orderIds, array $data = [], ?int $userId = null): CustomerInvoice
    {
        $businessId = (int) $customer->business_id;

        return DB::transaction(function () use ($businessId, $customer, $orderIds, $data, $userId) {
            /*
             * وبترتيب وقوعها لا بترتيب قاعدة البيانات.
             *
             * كانت تُقرأ بلا `ORDER BY`، فتخرج على PostgreSQL بترتيبٍ لا يُوعد
             * به: ورقةُ الشهر نفسُها تُطبع مرّتين فتقول مرّتين ترتيبًا مختلفًا،
             * والشركةُ التي تُطابق ورقتَها بطلباتها تقرأ سطورًا مبعثرة. وكشفه
             * اختبارٌ سقط على PostgreSQL وحده — وهو DEC-003 نفسُه:
             * «لا يُفترض ترتيبٌ لم يُطلب».
             */
            $orders = Order::where('business_id', $businessId)
                ->where('customer_id', $customer->id)
                ->whereIn('id', $orderIds)
                ->orderBy('ordered_at')->orderBy('id')
                ->lockForUpdate()->with('items.addons')->get();

            if ($orders->isEmpty()) {
                throw new RuntimeException(__('لا طلبات لفوترتها.'));
            }

            foreach ($orders as $order) {
                if ((string) $order->payment_status !== 'غير مدفوع') {
                    throw new RuntimeException(__('الطلب :n مدفوعٌ — ولا يُفوتَر إلّا البيعُ الآجل.', ['n' => $order->number]));
                }

                $taken = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))
                    ->where('status', '!=', CustomerInvoice::CANCELLED)->exists();

                if ($taken) {
                    throw new RuntimeException(__('الطلب :n مفوتَرٌ سلفًا.', ['n' => $order->number]));
                }
            }

            $lines = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    // ورقمُ الطلب في البيان: الشركةُ تُطابق ورقتَها بطلباتها
                    foreach (self::orderItemLines($item, ' — '.$order->number) as $line) {
                        $lines[] = $line + ['discount' => 0.0];
                    }
                }
            }

            /*
             * ولا نقاطَ تُستبدل على ورقةٍ تجمع طلبات: خصمُ كلّ طلبٍ وقع عند
             * بيعه ورُحّل في دفتره، وخصمٌ ثانٍ هنا ورقةٌ بغير ما في الدفتر.
             */
            unset($data['redeem_points']);

            $invoice = self::create($businessId, $customer, $data, $lines, $userId);
            $invoice->orders()->attach($orders->pluck('id')->all());

            /*
             * والمجاميعُ تتبع الطلبات لا حسابَ البنود.
             *
             * الطلبُ يحمل خصمًا على مستواه وأجرةَ توصيلٍ وضريبةً حُسبت بقواعده.
             * وإعادةُ الحساب هنا تُنتج ورقةً بمبلغٍ غير الذي رُحّل في الدفتر.
             */
            $invoice->update([
                'subtotal' => round($orders->sum(fn ($o) => (float) $o->subtotal + (float) $o->delivery_fee), 3),
                'discount_total' => round($orders->sum(fn ($o) => (float) $o->discount), 3),
                'tax_total' => round($orders->sum(fn ($o) => (float) $o->tax), 3),
                'total' => round($orders->sum(fn ($o) => (float) $o->total), 3),
            ]);

            return self::issue($invoice->fresh('items'), $userId);
        });
    }

    /**
     * إلغاءُ طلبٍ مفوتَر — الورقةُ تعرف بذلك.
     *
     * وكان الإلغاء يُنقص الدفتر ولا يُنقص الفاتورة: يُلغى طلبٌ في فاتورةِ
     * شهرٍ فيبقى مبلغُه مطلوبًا من الشركة، ويُرسَل لها تذكيرٌ بدَينٍ لم يعد
     * عليها. والفارقُ يظهر في شريط المطابقة ولا يُعرف سببُه.
     *
     * ولا تُعاد كتابة الورقة: يُصدَر إشعارُ دائنٍ بمبلغ الطلب — والورقةُ في
     * يد الشركة تبقى كما استلمتها.
     *
     * ولا قيدَ له: `Books::unpostSale` عكست قيدَ الطلب لحظةَ إلغائه.
     */
    public static function onOrderCancelled(Order $order, ?int $userId = null, ?string $reason = null): ?CustomerCreditNote
    {
        $invoice = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))
            ->where('status', CustomerInvoice::ISSUED)->first();

        if (! $invoice) {
            return null;
        }

        // ولا يُعكس ما عُكس: إلغاءان لا يكتبان إشعارين
        $already = CustomerCreditNote::where('customer_invoice_id', $invoice->id)
            ->where('order_id', $order->id)->exists();

        if ($already) {
            return null;
        }

        $amount = round(min((float) $order->total, $invoice->outstanding() + $invoice->paidTotal()), 3);

        if ($amount <= 0) {
            return null;
        }

        return self::creditNote(
            $invoice,
            $amount,
            round((float) $order->tax, 3),
            $reason ?: __('إلغاء الطلب ').$order->number,
            $userId,
            self::NOTE_ORDER_CANCELLED,
            (int) $order->id,
        );
    }

    /**
     * فاتورةٌ من طلبٍ قائم — لقطةُ بنوده، بلا مخزونٍ وبلا إيرادٍ ثانٍ.
     *
     * ولا تُنشأ مرّتين للطلب نفسه: ورقتان لدَينٍ واحد تُحصَّل إحداهما ويبقى
     * الأخرى تُطالب.
     */
    public static function fromOrder(Order $order, array $data = [], ?int $userId = null): CustomerInvoice
    {
        $existing = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))
            ->where('status', '!=', CustomerInvoice::CANCELLED)->first();

        if ($existing) {
            return $existing;
        }

        $customer = Customer::where('business_id', $order->business_id)
            ->whereKey($order->customer_id)->first();

        if (! $customer) {
            throw new RuntimeException(__('لا فاتورةَ عميلٍ لطلبٍ بلا عميل مسجَّل.'));
        }

        /*
         * ═══ وسطرُ الورقة يحمل ضريبةَ صنفه، لا نسبةَ المتجر ═══
         *
         * `compute` تسقط إلى `Vat::rate` حين لا تُعطى نسبةَ بند. ورأسُ
         * الفاتورة يُكتب من الطلب بعد قليل — فتخرج ورقةٌ **تناقض نفسها**:
         * خبزٌ صفريُّ الضريبة يُباع آجلًا، فيقول سطرُه «نسبة ٥٪، ضريبة ٥،
         * المبلغ ١٠٥» ويقول ذيلُها «الضريبة صفر، الإجمالي ١٠٠». وقِيست.
         *
         * وهي ورقةٌ ضريبيّة: يُقدّمها العميلُ في إقراره هو، ويقرأ منها سطرًا
         * لم يُدفع. فالنسبةُ تُقرأ بالقارئ نفسِه الذي قرأها لحظة البيع
         * (`Vat::rateFor`).
         *
         * ولا `withTrashed` هنا خلافًا لـ`OrderCorrection::recompute`: هذه
         * تُنادى لحظةَ البيع وحدها (`PosController`)، و`create` ترفض أصلًا
         * بندًا لصنفٍ ليس في متجر الفاتورة — فلا تبلغها لقطةُ صنفٍ محذوف.
         *
         * وخصمُ الطلب يُوزَّع على البنود بحصّتها كما وزّعه
         * `PosController::taxFor`: فبلا توزيعٍ تُحسب ضريبةُ السطر على مبلغٍ
         * لم يدفعه أحد، ويقول السطرُ غيرَ ما يقول الذيل ثانيةً.
         */
        $order->loadMissing('items.addons');

        $products = Product::where('business_id', $order->business_id)
            ->whereIn('id', $order->items->pluck('product_id')->filter())
            ->get()->keyBy('id');

        /*
         * والإضافةُ بضريبة بندها: `PosController::taxFor` تحتسب نسبةَ الصنف على
         * `price × qty + addons_total` معًا. فسطرُ الإضافة يحمل نسبةَ منتجه.
         */
        $lines = [];
        foreach ($order->items as $item) {
            $rate = Vat::rateFor($products->get($item->product_id), (int) $order->business_id);

            foreach (self::orderItemLines($item) as $line) {
                $lines[] = $line + ['tax_rate' => $rate];
            }
        }

        /*
         * وحصّةُ الخصم تُقاس على كلّ ما دُفع ثمنُه — الإضافاتُ معه.
         *
         * كما في `PosController::taxFor`: الوعاءُ `price × qty + addons_total`.
         * وكانت تُقاس على ثمن الأصناف وحده يومَ لم تكن الإضافةُ سطرًا هنا.
         */
        $gross = round(collect($lines)->sum(fn ($l) => $l['unit_price'] * $l['quantity']), 3);
        $orderDiscount = round((float) $order->discount, 3);

        $lines = array_map(function (array $line) use ($gross, $orderDiscount) {
            $net = round($line['unit_price'] * $line['quantity'], 3);
            $share = $gross > 0 ? $net / $gross : 0.0;

            return $line + ['discount' => round($orderDiscount * $share, 3)];
        }, $lines);

        /*
         * والإجماليُّ يتبع الطلبَ لا حسابَ البنود.
         *
         * الطلبُ يحمل خصمًا على مستواه وأجرةَ توصيلٍ وضريبةً حُسبت بقواعده.
         * وإعادةُ الحساب هنا تُنتج ورقةً بمبلغٍ غير الذي رُحّل في الدفتر —
         * فتفترق الذمّةُ التشغيليّة عن رصيد `receivable`.
         */
        // ولا استبدالَ على ورقة طلبٍ واحد — خصمُه وقع عند بيعه (انظر `consolidate`)
        unset($data['redeem_points']);

        $invoice = self::create((int) $order->business_id, $customer, $data + [
            'branch_id' => $order->branch_id,
            'issued_at' => $order->ordered_at ?? $order->created_at,
        ], $lines, $userId);

        $invoice->orders()->attach($order->id);

        $invoice->update([
            'subtotal' => round((float) $order->subtotal + (float) $order->delivery_fee, 3),
            'discount_total' => round((float) $order->discount, 3),
            'tax_total' => round((float) $order->tax, 3),
            'total' => round((float) $order->total, 3),
        ]);

        return $invoice->fresh('items');
    }

    /**
     * بندُ طلبٍ كما يُكتب في الورقة — وإضافاتُه من لقطتها لا من الكتالوج.
     *
     * كانت الورقةُ تكتب الصنفَ بثمنه (`price × qty`) وتُسقط إضافاته، والطلبُ
     * يجبيها (`addons_total`). فورقةُ بوكيهٍ بعشرين وشوكولاتةٍ بثلاثة تقول
     * سطورُها عشرين ويقول ذيلُها ثلاثةً وعشرين — والذيلُ من الطلب
     * (`fromOrder` و`consolidate`). فلا تُرى الإضافةُ ولا يُعرف من أين الفرق.
     *
     * ═══ والمالُ يُكتب مرّةً — من `addons_total` ═══
     *
     * `price` ثمنُ الصنف بمقاسه **بلا** إضافاته (انظر `OrderItem::lineTotal`).
     * فالإضافاتُ تُضاف سطورًا مستقلّةً ولا تُضاف إلى سعر الصنف: مرّةً واحدة.
     *
     * والذي يُقرأ ليُعرف هل جُبيت فوق الثمن هو `addons_total` لا مجموعُ
     * اللقطات: الطلبُ المخصَّص بـ«ميزانيةٍ نهائيّة» يكتب إضافاتِه بأثمانها
     * ويجعل `addons_total` صفرًا — هي **داخل** ثمنه (`SaleLines`). فهناك
     * تُسمّى في بيان الصنف ولا تُكتب مالًا، وإلّا دفع الزبونُ ثمنَها مرّتين.
     *
     * ═══ ولا يُقرأ كتالوجُ اليوم ═══
     *
     * الاسمُ والثمنُ والكميّةُ من `order_item_addons` كما كانت ساعةَ البيع.
     * إضافةٌ غُيّر ثمنُها أو حُذفت بعدها لا تُعيد كتابة بيعٍ مضى.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function orderItemLines(OrderItem $item, string $suffix = ''): array
    {
        /*
         * والمقاسُ مع اسمه — من لقطة البيع (`variant_name`).
         *
         * كان يُكتب الاسمُ وحده، فباقةٌ «كبيرة» وأختُها «صغيرة» تخرجان في
         * الفاتورة سطرين بالاسم نفسِه وبثمنين — ولا يُعرف لماذا. نصٌّ لا مال:
         * الثمنُ والكميّةُ كما بيعا، والفواتيرُ الصادرةُ قبله لا تُمسّ.
         */
        /*
         * ونصُّ الوصف لقطةٌ دائمة — بلغة الدفتر الأصليّة لا بلغة شاشة من ضغط.
         *
         * كان `__('بند')` و«إضافات:» و«تشمل:» تُترجَم بلغة الكاشير ساعةَ البيع
         * الآجل، فيكتب كاشيرٌ إنجليزيُّ الواجهة «Add-ons: شوكولاتة» في ورقةٍ
         * عربيّة إلى الأبد. فتُكتب بالعربيّة دائمًا (`'ar'`)، والإضافةُ تحمل
         * اسمَها الإنجليزيّ في لقطتها (`InvoiceLineDetails::fromOrderAddon`).
         */
        $name = ($item->name ?: __('بند', [], 'ar')).(filled($item->variant_name) ? ' — '.$item->variant_name : '');

        $main = [
            'product_id' => $item->product_id,
            // والإضافةُ المبيعةُ وحدَها من شريط الصندوق تحمل هويّتَها هنا كذلك
            'addon_id' => $item->product_id === null ? $item->standalone_addon_id : null,
            'order_item_id' => $item->id,
            'description' => $name.$suffix,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->price,
            // وملاحظتُه وخياراتُه من لقطة البيع — بلا مال (`InvoiceLineDetails::fromOrderItem`)
            'details' => InvoiceLineDetails::fromOrderItem($item),
            // وإضافاتُه المدفوعةُ تحته في الورقة — والمالُ في سطورها كما كان
            'group' => 'oi'.$item->id,
        ];

        $addons = $item->addons;

        if ($addons->isEmpty()) {
            return [$main];
        }

        $charged = round((float) $item->addons_total, 3);
        $snapshots = round($addons->sum(fn ($a) => (float) $a->total), 3);
        $names = $addons->map(fn ($a) => (int) $a->quantity > 1 ? $a->name.' ×'.(int) $a->quantity : $a->name)->implode('، ');

        // جُبيت فوق الثمن واللقطاتُ تقول كم بالضبط: سطرٌ لكلّ إضافة
        if ($charged > 0 && abs($charged - $snapshots) < 0.0005) {
            return [$main, ...$addons->map(fn ($a) => [
                'product_id' => null,
                'addon_id' => $a->addon_id,
                'order_item_id' => $item->id,
                'description' => $a->name.$suffix,
                'quantity' => (float) $a->quantity,
                'unit_price' => (float) $a->unit_price,
                'details' => InvoiceLineDetails::fromOrderAddon($a),
                'parent' => 'oi'.$item->id,
            ])->all()];
        }

        /*
         * وجُبيت ولا تطابق لقطاتُها ما جُبي — صفٌّ قديمٌ أو مصحَّح.
         *
         * سطرٌ واحدٌ بما جُبي فعلًا، يُسمّي ما فيه. لا يُوزَّع المبلغُ على
         * الأسماء بالظنّ، ولا يُكتب غيرُ ما دُفع.
         */
        if ($charged > 0) {
            return [$main, [
                'product_id' => null,
                'addon_id' => null,
                'order_item_id' => $item->id,
                'description' => Str::limit(__('إضافات: :list', ['list' => $names], 'ar').$suffix, 255, ''),
                'details' => $addons->contains(fn ($a) => filled($a->name_en)) ? ['label_en' => Str::limit('Add-ons: '.$addons->map(
                    fn ($a) => ($a->name_en ?: $a->name).((int) $a->quantity > 1 ? ' ×'.(int) $a->quantity : '')
                )->implode(', ').$suffix, 255, '')] : null,
                'quantity' => 1.0,
                'unit_price' => $charged,
                'parent' => 'oi'.$item->id,
            ]];
        }

        // وداخل الثمن: تُسمّى في بيان الصنف ولا تُكتب مالًا
        $main['description'] = Str::limit($name.' ('.__('تشمل: :list', ['list' => $names], 'ar').')'.$suffix, 255, '');

        return [$main];
    }

    /* ═══════════════════ نقاطُ الولاء على الفاتورة ═══════════════════ */

    /** مفتاحُ حركة الاستبدال — واحدٌ للورقة، وعكسُه بلاحقة */
    public static function redeemKey(CustomerInvoice $invoice): string
    {
        return 'cinv:'.$invoice->id.':redeem';
    }

    /**
     * أساسُ الاستبدال من بنود الورقة — كما يقرؤه الصندوق.
     *
     * `base` مجموعُ البنود قبل خصومها، و`other` ما خُصم منها — مقابلُ
     * المجموع الفرعيّ والكوبون في نقطة البيع. فالقاعدةُ واحدة: نسبةٌ من
     * المجموع، ولا تتجاوز ما بقي بعد خصوم البنود.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: float, 1: float}
     */
    public static function loyaltyBase(array $lines): array
    {
        $base = 0.0;
        $other = 0.0;

        foreach ($lines as $line) {
            $qty = round((float) ($line['quantity'] ?? 1), 3);
            if ($qty <= 0) {
                continue;
            }
            $gross = round($qty * round((float) ($line['unit_price'] ?? 0), 3), 3);
            $base = round($base + $gross, 3);
            $other = round($other + min(max(0.0, round((float) ($line['discount'] ?? 0), 3)), $gross), 3);
        }

        return [$base, $other];
    }

    /**
     * ورقةٌ غطّت نقاطُ العميل إجماليَّها كلَّه — صفرٌ مستحقّ، وهو ما اختاره التاجرُ بسقوفه.
     *
     * المالُ هنا كلُّه خصمُ نقاط: البنودُ تحمل حصصَها حتّى صافيها، فلا إيرادَ
     * ولا ضريبة (`withLoyalty` ثمّ `compute`)، ولا باقيَ يُطلب. وصفرٌ لغير
     * النقاط (بنودٌ بلا قيمة، خصمٌ يدويٌّ كامل) يبقى مردودًا كما كان.
     */
    public static function coveredByPoints(CustomerInvoice $invoice): bool
    {
        return (int) $invoice->loyalty_points > 0
            && (float) $invoice->loyalty_discount > 0
            && abs(round((float) $invoice->total, 3)) < 0.0005;
    }

    /**
     * عرضُ الاستبدال لهذا العميل على هذه البنود — من `Loyalty::quote` وحدَها.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function loyaltyQuote(int $businessId, ?Customer $customer, array $lines, ?int $requested = null): array
    {
        [$base, $other] = self::loyaltyBase($lines);

        return Loyalty::quote(Loyalty::rules($businessId), $customer?->points, $base, $other, $requested);
    }

    /**
     * يوزّع خصمَ النقاط على البنود بنسبة صافيها — ثمّ تحسبها `compute` كما هي.
     *
     * ═══ ولمَ في البنود لا في رأس الورقة ═══
     *
     * الضريبةُ تُحسب لكلّ بند، والقيدُ يقرأ المجموعَ الفرعيّ والخصمَ
     * والضريبة، والإشعارُ الدائن يقرأ البنود. فخصمٌ في الرأس وحده يجعل
     * الضريبةَ تُحسب على ما لم يُدفع، والبنودَ لا تجمع إلى إجماليّها. ونصيبُ
     * كلّ بندٍ من الخصم يمرّ بالصيغة نفسِها التي يمرّ بها خصمُه اليدويّ —
     * شاملةً أو غير شاملة — فلا صيغةَ ثانيةً تُكتب للضريبة.
     *
     * والتقريبُ لا يضيع: يُقصّ نصيبُ كلّ بندٍ إلى البيسة، ويُعطى الباقي لأكبر
     * البنود حتى يستوفى — ولا يتجاوز بندٌ صافيه.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function withLoyalty(array $lines, float $discount): array
    {
        $discount = round($discount, 3);
        if ($discount <= 0) {
            return $lines;
        }

        $nets = [];
        foreach ($lines as $i => $line) {
            $qty = round((float) ($line['quantity'] ?? 1), 3);
            if ($qty <= 0) {
                continue;
            }
            $gross = round($qty * round((float) ($line['unit_price'] ?? 0), 3), 3);
            $own = round(min(max(0.0, round((float) ($line['discount'] ?? 0), 3)), $gross), 3);
            $net = round($gross - $own, 3);
            if ($net > 0) {
                $nets[$i] = ['own' => $own, 'net' => $net];
            }
        }

        /*
         * ═══ والقسمةُ بالبيسات الصحيحة لا بالكسور العائمة ═══
         *
         * الصافي والخصمُ أرقامٌ بثلاث خانات، فيصيران بيساتٍ صحيحة، ونصيبُ كلّ
         * بندٍ قسمةٌ صحيحة (`d × صافيه ÷ المجموع`) مقصوصة — فلا يقع نصيبٌ على
         * حدّ البيسة مرّةً فوقه ومرّةً تحته بحسب تمثيل الرقم. وما بقي من القصّ
         * يذهب إلى أكبر البنود صافيًا كما كان.
         */
        $d = (int) round($discount * 1000);
        $cents = array_map(fn ($n) => (int) round($n['net'] * 1000), $nets);
        $sum = array_sum($cents);
        if ($sum < $d) {
            throw ValidationException::withMessages([
                'redeem_points' => __('خصم النقاط أكبر من قيمة البنود.'),
            ]);
        }

        $shares = [];
        foreach ($cents as $i => $net) {
            $shares[$i] = BigInteger::of($d)->multipliedBy($net)->quotient($sum)->toInt();
        }

        // الباقي من القصّ — لأكبر البنود صافيًا، ولا يتجاوز بندٌ ما بقي منه
        $left = $d - array_sum($shares);
        uasort($cents, fn ($a, $b) => $b <=> $a);
        foreach ($cents as $i => $net) {
            if ($left <= 0) {
                break;
            }
            $add = min($net - $shares[$i], $left);
            $shares[$i] += $add;
            $left -= $add;
        }

        foreach ($shares as $i => $share) {
            $lines[$i]['discount'] = round($nets[$i]['own'] + $share / 1000.0, 3);
            $lines[$i]['loyalty_discount'] = $share / 1000.0;
        }

        return $lines;
    }

    /**
     * يخصم من رصيد العميل نقاطَ الورقة — عند إصدارها، مرّةً واحدة.
     *
     * يُعاد فحصُ ما وافق عليه التاجرُ حين كتب المسودّة: البرنامجُ عاملٌ
     * والاستبدالُ مفتوح، والرصيدُ يكفي، والقواعدُ ما زالت تعطي النقاطَ نفسَها
     * بالقيمة نفسِها على البنود نفسِها. وما لا يصدق يُردّ بسببه، ولا يُعدَّل
     * مبلغُ الورقة في صمت: من وافق على مبلغٍ لا يُصدَر له غيرُه.
     *
     * والخصمُ تحت قفل صفّ العميل وبشرطٍ في جملة التحديث (`Loyalty::spend`)
     * وبمفتاحٍ فريد — فلا فاتورتان ولا فاتورةٌ وصندوقٌ يصرفان النقاطَ نفسَها.
     */
    private static function spendPoints(CustomerInvoice $invoice): void
    {
        $points = (int) $invoice->loyalty_points;
        if ($points <= 0 || self::pointsSpent($invoice) > 0) {
            return;
        }

        /*
         * والمتجرُ قبل العميل — ترتيبُ الأقفال الذي يأخذه الصندوق.
         *
         * بيعةُ الصندوق تُمسك صفَّ المتجر من أوّل صفٍّ تكتبه (مفتاحُه الأجنبيّ)،
         * ثمّ صفَّ العميل حين تكتب طلبَه. والإصدارُ كان يُمسك العميلَ هنا ثمّ
         * يطلب المتجرَ للترقيم والقيد — فينتظر كلٌّ منهما الآخر، وتُسقط
         * PostgreSQL البيعةَ بـ deadlock. والقفلُ مأخوذٌ بعد سطورٍ على أيّ حال،
         * فأخذُه هنا لا يزيد شيئًا. يحرسه `TwoHandsOnOneCustomersPointsAtOnceTest`.
         */
        Business::whereKey($invoice->business_id)->lockForUpdate()->first();

        $customer = Customer::where('business_id', $invoice->business_id)
            ->whereKey($invoice->customer_id)->lockForUpdate()->first();

        if (! $customer) {
            throw new RuntimeException(__('تغيّر رصيد نقاط العميل أو قواعد الاستبدال منذ كُتبت المسودّة — ألغِ المسودّة وأنشئ فاتورةً جديدة.'));
        }

        $base = 0.0;
        $other = 0.0;
        $shares = 0.0;
        foreach ($invoice->items()->get() as $item) {
            $gross = round((float) $item->quantity * (float) $item->unit_price, 3);
            $base = round($base + $gross, 3);
            $other = round($other + (float) $item->discount - (float) $item->loyalty_discount, 3);
            $shares = round($shares + (float) $item->loyalty_discount, 3);
        }

        /*
         * والبنودُ ما زالت تحمل خصمَ النقاط كما كُتب.
         *
         * الخصمُ موزَّعٌ على البنود داخلَ خصمها (`withLoyalty`)، ومنها يُحسب
         * الإجماليُّ وتُطبع الورقة. وبابٌ يعيد كتابةَ البنود ولا يعرف النقاط
         * (تعديلُ المسودّات في #195) يُسقط حصّتَها: فيُصرف الرصيدُ على ورقةٍ
         * إجماليُّها بلا خصمه، أو يُطبع الخصمُ مرّتين. فلا يُصدَر ما لا تتّفق
         * بنودُه وصفُّه — ولا يُصلَح هنا: من وافق على مبلغٍ لا يُصدَر له غيرُه.
         */
        if (abs($shares - (float) $invoice->loyalty_discount) > 0.0005) {
            throw new RuntimeException(__('تغيّرت بنود المسودّة بعد اختيار النقاط فلم تعد تحمل خصمها — ألغِ المسودّة وأنشئ فاتورةً جديدة.'));
        }

        $rules = Loyalty::rules((int) $invoice->business_id);

        /*
         * والردُّ يقول ما صار — لا «تغيّر» وحدها.
         *
         * الرصيدُ الآن وأقصى ما يجوز على هذه البنود بقواعد اليوم: فيعرف
         * التاجرُ بكم يكتب الفاتورةَ الجديدة، ولا يُخمّن. والمسودّةُ لا تُعدَّل
         * هنا — مبلغُها ما وافق عليه حين كتبها، ولا يُصدَر له غيرُه.
         */
        $refuse = function () use ($rules, $customer, $base, $other, $invoice) {
            $balance = (int) $customer->fresh()?->points;
            $now = Loyalty::quote($rules, $balance, $base, $other, null);

            return new RuntimeException($now['points'] > 0
                ? __('تغيّر رصيد نقاط العميل أو قواعد الاستبدال منذ كُتبت المسودّة — الرصيد الآن :balance نقطة، والخصم المتاح الآن :discount مقابل :points نقطة. ألغِ المسودّة وأنشئ فاتورةً جديدة.', [
                    'balance' => $balance, 'discount' => Money::format($now['discount'], Money::of((int) $invoice->business_id)), 'points' => $now['points'],
                ])
                : __('تغيّر رصيد نقاط العميل أو قواعد الاستبدال منذ كُتبت المسودّة — الرصيد الآن :balance نقطة، ولا خصمَ بالنقاط عليها الآن. ألغِ المسودّة وأنشئ فاتورةً جديدة.', [
                    'balance' => $balance,
                ]));
        };

        $quote = Loyalty::quote($rules, (int) $customer->points, $base, $other, $points);
        if ($quote['points'] !== $points || abs($quote['discount'] - (float) $invoice->loyalty_discount) > 0.0005) {
            throw $refuse();
        }

        try {
            Loyalty::spend($customer, $points, 'استبدال على فاتورة عميل '.($invoice->number ?: '#'.$invoice->id), [
                'customer_invoice_id' => $invoice->id,
                'source_key' => self::redeemKey($invoice),
            ]);
        } catch (ValidationException) {
            throw $refuse();
        }
    }

    /** كم نقطةً صُرفت فعلًا على الورقة — صفرٌ لمسودّةٍ لم تُصدَر */
    public static function pointsSpent(CustomerInvoice $invoice): int
    {
        return (int) abs((int) PointTransaction::where('business_id', $invoice->business_id)
            ->where('customer_invoice_id', $invoice->id)
            ->where('source_key', self::redeemKey($invoice))->value('points'));
    }

    /** كم نقطةً أُعيدت منها — بإشعاراتٍ دائنة أو بإلغاء */
    public static function pointsReturned(CustomerInvoice $invoice): int
    {
        return (int) PointTransaction::where('business_id', $invoice->business_id)
            ->where('customer_invoice_id', $invoice->id)
            ->where('source_key', 'like', self::redeemKey($invoice).':%')->sum('points');
    }

    /**
     * سياسةُ الإعادة الواحدة — كم يعود بعد أن رُدّ من الورقة هذا المبلغ.
     *
     * ═══ تراكميّةٌ لا لكلّ إشعارٍ وحده ═══
     *
     * ما يحقّ للعميل بعد كلّ ردٍّ = `floor(المصروف × مجموعُ ما رُدّ ÷ إجماليّ
     * الورقة)`، محصورًا بالمصروف. ويُعطى الفرقُ عمّا أُعيد قبلُ. فإشعاران
     * بنصفين يعيدان كلَّ النقاط — لا نقطةً أقلّ يأكلها التقريبُ مرّتين — ولا
     * يتجاوز المُعادُ ما صُرف مهما تكرّرت الإشعارات. والإلغاءُ وردُّ الإجماليّ
     * كلِّه يعيدان الباقي كلَّه.
     */
    public static function pointsDueBack(int $spent, float $credited, float $total): int
    {
        if ($spent <= 0 || $credited <= 0 || $total <= 0) {
            return 0;
        }
        // بالبيسات الصحيحة: المبلغان بثلاث خانات، والنسبةُ قسمةٌ صحيحة مقصوصة لا كسرٌ عائم
        $creditedB = (int) round($credited * 1000);
        $totalB = (int) round($total * 1000);
        if ($creditedB >= $totalB) {
            return $spent;
        }

        return min($spent, BigInteger::of($spent)->multipliedBy($creditedB)->quotient($totalB)->toInt());
    }

    /**
     * يعيد بنسبة إشعارٍ دائنٍ صدر الآن — تحت قفل الورقة، مرّةً لكلّ إشعار.
     */
    private static function returnPointsForCredit(CustomerInvoice $invoice, CustomerCreditNote $note): void
    {
        $locked = CustomerInvoice::where('business_id', $invoice->business_id)->lockForUpdate()->find($invoice->id);
        if (! $locked || (int) $locked->loyalty_points <= 0) {
            return;
        }

        $spent = self::pointsSpent($locked);
        if ($spent <= 0) {
            return;
        }

        $credited = round((float) CustomerCreditNote::where('customer_invoice_id', $locked->id)->sum('amount'), 3);
        $give = self::pointsDueBack($spent, $credited, (float) $locked->total) - self::pointsReturned($locked);

        self::givePoints($locked, $give, self::redeemKey($locked).':cn:'.$note->id,
            'إعادة نقاط مستبدلة بنسبة إشعار الدائن '.$note->number.' — فاتورة '.$locked->number);
    }

    /**
     * يعيد إلى العميل ما بقي ممّا صُرف على الورقة — عند إلغائها، مرّةً واحدة.
     *
     * والباقي = المصروفُ − ما أُعيد بإشعاراتٍ قبله. ومسودّةٌ لم تُصدَر لم
     * يُصرف عليها شيء، فلا يُعاد شيء.
     */
    public static function returnPoints(CustomerInvoice $invoice): void
    {
        $spent = self::pointsSpent($invoice);
        if ($spent <= 0) {
            return;
        }

        $label = $invoice->number ?: '#'.$invoice->id;

        self::givePoints($invoice, $spent - self::pointsReturned($invoice), self::redeemKey($invoice).':reverse',
            'إعادة نقاط مستبدلة — إلغاء فاتورة '.$label);
    }

    /** حركةُ إعادةٍ واحدة بمفتاحها الفريد — والمفتاحُ يمنع تكرارَها */
    private static function givePoints(CustomerInvoice $invoice, int $points, string $key, string $note): void
    {
        if ($points <= 0 || ! $invoice->customer_id || PointTransaction::where('source_key', $key)->exists()) {
            return;
        }

        $customer = Customer::withTrashed()->where('business_id', $invoice->business_id)
            ->whereKey($invoice->customer_id)->lockForUpdate()->first();
        if (! $customer) {
            return;
        }

        $customer->increment('points', $points);

        PointTransaction::create([
            'business_id' => (int) $invoice->business_id,
            'customer_id' => $customer->id,
            'customer_invoice_id' => $invoice->id,
            'type' => 'earn',
            'points' => $points,
            'balance_after' => (int) $customer->points,
            'note' => mb_substr($note, 0, 250),
            'source_key' => $key,
        ]);
    }
}
