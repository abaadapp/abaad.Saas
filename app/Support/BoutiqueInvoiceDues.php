<?php

namespace App\Support;

use App\Models\Account;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueInvoiceReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * مستحقُّ البوتيك عن فواتير العملاء بلا طلب — اليدويّةِ وطلبِ واتساب الآليّ.
 *
 * ═══ ثلاثةُ أبواب ═══
 *
 *   ١. **الإصدار** يكتب اللقطة (`snapshot`): لمن البند، وبكم، وبأيّ نسبة، وما للمتجر
 *      منه وما للبوتيك. لا تتغيّر بنسبةٍ أو سعرٍ أو صاحبٍ يتبدّل بعدها.
 *   ٢. **اكتمالُ التحصيل** يجعلها قابلةً للتسوية (`sync`): حتّى ذلك «بانتظار التحصيل»،
 *      لا تحملها ورقة. والشيكُ تحت التحصيل لم يُقبض بعد.
 *   ٣. **الورقة** تحملها مرّةً مع بنود الطلبات (`ready` و`claim`، من `Boutiques`).
 *
 * ═══ ولا يُحسب شيءٌ مرّتين ═══
 *
 * فاتورةٌ تغطّي طلبًا لا تُكتب لها لقطة (بيعُها في الطلب)، وبندٌ مولودٌ من بند طلبٍ
 * كذلك، والبندُ لقطةٌ واحدة بمفتاحٍ فريد. ولا إيرادَ هنا ولا مخزون: الفاتورةُ قُيّدت
 * يومَ صدورها كما كانت.
 *
 * ═══ الاعترافُ غيرُ التسوية ═══
 *
 * نصيبُ البوتيك يُقيَّد يومَ صدور الفاتورة مع إيرادها (مدين حساب تسويته / دائن 2160) —
 * فلا يُقرأ ربحُ الشهر بإيرادٍ بلا ما يقابله. ولا يُسوّى ولا يُسدَّد قبل اكتمال التحصيل.
 * والورقةُ لا تقيّده ثانيةً: تقيّد ما لم يُقيَّد (`boutique_settlements.accrued_at_sale`).
 * وما رُدّ أو أُلغي قبل ورقته يُردّ من القيد بيومه. و`config('boutiques.recognise')` = `sheet`
 * يعيد القيدَ إلى الورقة لما يصدر بعده — والبندُ يحمل أيَّهما كان (`accrued_on`).
 *
 * ═══ والخصمُ على المتجر ═══
 *
 * خصمُ البند ونقاطُه لا تُنقص ما للبوتيك: العمولةُ والصافي من الكمّيّة × السعر قبل
 * الخصم — كما في الصندوق والموقع (كوبونٌ أو نقاطٌ على الطلب لا تمسّ بندَه).
 */
final class BoutiqueInvoiceDues
{
    /** صدرت فاتورتُه ولم يكتمل تحصيلُها */
    public const AWAITING = 'awaiting';

    /** اكتمل تحصيلُها — تنتظر ورقة */
    public const READY = 'pending';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    /** رُدّ كلُّه قبل ورقته */
    public const RETURNED = 'returned';

    public const VOID = 'void';

    /** مصدرُ قيد الاعتراف بنصيب البوتيك من فاتورة عميل، وردِّه قبل ورقته */
    public const ACCRUAL = 'استحقاق فاتورة بوتيك';

    /** وقطعُه داخل الباقات: تكلفةُ بضاعةٍ مباعة لا مصروف — كما في الصندوق (`Books::recordSale`) */
    public const PIECE_ACCRUAL = 'تكلفة قطع فاتورة بوتيك';

    /** متى يُعترف به — `config('boutiques.recognise')` */
    public const AT_ISSUE = 'issue';

    public const AT_SHEET = 'sheet';

    /* ═══════════ ١. اللقطة ═══════════ */

    /**
     * يكتب لقطةَ بنود البوتيكات في فاتورةٍ تُصدَر — داخل معاملة الإصدار وتحت قفلها.
     */
    public static function snapshot(CustomerInvoice $invoice): void
    {
        if ($invoice->coversOrders()) {
            return;
        }

        $items = CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)
            ->whereNotNull('product_id')->whereNull('order_item_id')->orderBy('sort_order')->orderBy('id')->get();

        if ($items->isEmpty()) {
            return;
        }

        // ولو حُذف الصنفُ بين كتابة المسودّة وإصدارها: صاحبُه صاحبُه
        $products = Product::withTrashed()->where('business_id', $invoice->business_id)
            ->whereIn('id', $items->pluck('product_id')->unique())->whereNotNull('boutique_id')->get()->keyBy('id');

        if ($products->isEmpty()) {
            return;
        }

        $owners = Boutiques::attribute((int) $invoice->business_id, $items->map(fn ($i) => ['product' => $products->get($i->product_id), 'variant' => (int) ($i->details['variant']['id'] ?? 0) ?: null])->all());
        $variants = ProductVariant::where('business_id', $invoice->business_id)
            ->whereIn('id', $items->map(fn ($i) => (int) ($i->details['variant']['id'] ?? 0))->filter()->unique())->pluck('price', 'id');
        $now = now();
        $rule = BoutiqueMoney::rule((int) $invoice->business_id, $now);

        foreach ($items->values() as $n => $item) {
            if (($owner = $owners[$n] ?? null) === null) {
                continue;
            }

            $product = $products->get($item->product_id);
            $variant = (int) ($item->details['variant']['id'] ?? 0);
            $rateH = BoutiqueMoney::rate($owner['rate']);
            // وبالسياسة المقترحة: من السعر المتّفق عليه، والفرقُ على المتجر أو له (`BoutiquePolicy`)
            $agreed = $owner['agreed'] ?? null;
            $split = BoutiqueMoney::split(BoutiqueMoney::gross($item->quantity, $item->unit_price), $rateH, self::vatInside($item, $rule),
                $agreed === null ? null : BoutiqueMoney::gross($item->quantity, $agreed));

            $line = BoutiqueInvoiceLine::create([
                'business_id' => $invoice->business_id,
                'boutique_id' => $owner['id'],
                'boutique_name' => $owner['name'],
                'customer_invoice_id' => $invoice->id,
                'customer_invoice_item_id' => $item->id,
                'branch_id' => $invoice->branch_id,
                'product_id' => $item->product_id,
                'name' => (string) $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'list_price' => $variants->has($variant) ? round((float) $variants[$variant], 3) : $product->sellingPrice(),
                'gross' => Baisa::toMoney($split['gross']),
                'discount' => $item->discount,
                'tax_rate' => $item->tax_rate,
                'tax_inclusive' => self::inclusive($item),
                'tax_amount' => $item->tax_amount,
                'rule' => $rule,
                'base' => Baisa::toMoney($split['base']),
                'rate' => $rateH / 100,
                'commission' => Baisa::toMoney($split['commission']),
                'net' => Baisa::toMoney($split['net']),
                'issued_at' => $now,
            ] + ($agreed === null ? [] : ['agreed_price' => $agreed]));

            self::accrue($line, $invoice);
        }
    }

    /* ═══════════ الاعتراف ═══════════ */

    /**
     * قيدُ نصيب البوتيك يومَ الفاتورة — بتاريخ إيرادها، وقيدٌ لكلّ بند.
     *
     * بندٌ لكلّ قيد: ما يُردّ منه أو يُلغى يُقابَل قيدُه هو، وورقةٌ تحمل بعضَ بنود
     * فاتورةٍ لا تمسّ أخواتها.
     */
    public static function accrue(BoutiqueInvoiceLine $line, CustomerInvoice $invoice): void
    {
        $net = round((float) $line->net, 3);

        if (config('boutiques.recognise') !== self::AT_ISSUE || $net <= 0) {
            return;
        }

        $at = Carbon::parse($invoice->issued_at);
        $piece = $line->kind === BoutiqueInvoiceLine::PIECE;
        Ledger::post(
            (int) $line->business_id,
            ($piece ? __('تكلفة قطعة بوتيك — فاتورة ') : __('استحقاق بوتيك — فاتورة ')).$invoice->number,
            [
                ['account' => $piece ? 'cogs' : Books::expenseAccount(Boutiques::EXPENSE_TYPE, (int) $line->business_id), 'debit' => $net,
                    'memo' => $line->boutique_name.' — '.$line->name.($piece ? ' / '.$line->parent_name : '')],
                ['account' => 'boutique_payable', 'credit' => $net],
            ],
            $at,
            $piece ? self::PIECE_ACCRUAL : self::ACCRUAL,
            $line->branch_id,
            auth()->id(),
            $line,
        );

        $line->update(['accrued_on' => $at->toDateString()]);
    }

    /** يُردّ من القيد ما سقط من النصيب قبل ورقته — بيومه في الفترة المفتوحة */
    private static function unaccrue(BoutiqueInvoiceLine $line, float $amount, Model $document): void
    {
        $amount = round($amount, 3);

        if ($line->accrued_on === null || $amount <= 0) {
            return;
        }

        Ledger::post(
            (int) $line->business_id,
            __('ردّ استحقاق بوتيك — فاتورة ').(string) CustomerInvoice::whereKey($line->customer_invoice_id)->value('number'),
            [
                ['account' => 'boutique_payable', 'debit' => $amount],
                ['account' => self::account($line), 'credit' => $amount, 'memo' => $line->boutique_name.' — '.$line->name],
            ],
            BooksPeriod::openOr((int) $line->business_id, now()),
            $line->kind === BoutiqueInvoiceLine::PIECE ? self::PIECE_ACCRUAL : self::ACCRUAL,
            $line->branch_id,
            auth()->id(),
            $document,
        );
    }

    /** الحسابُ الذي حُمّل نصيبَ هذا البند — كما قُيّد، لا كما يقول نوعُ المصروف اليوم */
    public static function account(BoutiqueInvoiceLine $line): Account|string
    {
        $entry = JournalEntry::where('sourceable_type', BoutiqueInvoiceLine::class)->where('sourceable_id', $line->id)
            ->whereIn('source', [self::ACCRUAL, self::PIECE_ACCRUAL])->orderBy('id')->first();

        return $entry?->lines()->where('debit', '>', 0)->with('account')->first()?->account
            ?? ($line->kind === BoutiqueInvoiceLine::PIECE ? 'cogs' : Books::expenseAccount(Boutiques::EXPENSE_TYPE, (int) $line->business_id));
    }

    /** ما قُيّد مع الفواتير ولم تحمله ورقةٌ بعد — دَينٌ في 2160 لا يُسدَّد قبل ورقته */
    public static function accruedOpen(int $businessId, ?int $boutiqueId = null): Builder
    {
        return BoutiqueInvoiceLine::query()
            ->where('boutique_invoice_lines.business_id', $businessId)
            ->when($boutiqueId !== null, fn ($q) => $q->where('boutique_invoice_lines.boutique_id', $boutiqueId))
            ->whereNotNull('boutique_invoice_lines.accrued_on')
            ->whereNull('boutique_invoice_lines.boutique_settlement_id')
            ->whereNull('boutique_invoice_lines.voided_at')
            ->whereColumn('boutique_invoice_lines.quantity', '>', 'boutique_invoice_lines.returned_quantity');
    }

    /** ما اعترف به الدفترُ من نصيب البوتيكات مع الفواتير في فترة — بعد ما رُدّ منه، بفرعه */
    public static function recognised(int $businessId, ?Carbon $start, ?Carbon $end)
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.business_id', $businessId)->where('journal_entries.posted', true)
            ->where('journal_entries.source', self::ACCRUAL)->where('accounts.system_key', 'boutique_payable')
            ->when($start, fn ($q) => $q->where('journal_entries.entry_date', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->where('journal_entries.entry_date', '<', $end->toDateString()));
    }

    /**
     * أين ضريبةُ البند من سعره؟ — من البند نفسِه لا من إعداد اليوم.
     *
     * المشمولةُ: ما يدفعه العميل عن البند هو صافيه بعد الخصم. والمضافة: فوقه ضريبتُه.
     * ومسودّةٌ كُتبت والأسعارُ شاملةٌ تُصدَر كما كُتبت ولو بُدّل الإعدادُ بعدها.
     */
    private static function inclusive(CustomerInvoiceItem $item): bool
    {
        return (float) $item->tax_amount > 0
            && Baisa::of((float) $item->line_total) === max(0, BoutiqueMoney::gross($item->quantity, $item->unit_price) - Baisa::of((float) $item->discount));
    }

    /** ما يُخرَج من سعر البند قبل عمولته — بضريبته هو كما على الورقة، لا بإعداد اليوم */
    private static function vatInside(CustomerInvoiceItem $item, string $rule): int
    {
        return $rule === BoutiqueMoney::BEFORE_VAT && self::inclusive($item) ? BoutiqueMoney::rate($item->tax_rate) : 0;
    }

    /* ═══════════ ٢. التحصيل ═══════════ */

    /**
     * «اكتمل تحصيلُها» بلغة القاعدة: صادرةٌ ولم يبقَ عليها شيءٌ **قُبض**.
     *
     * كـ`CustomerInvoice::outstandingSql` إلّا الشيك: هناك الورقةُ تحت التحصيل سدادٌ
     * (الذمّةُ انتقلت إليها)، وهنا لم يُقبض بها شيءٌ بعد — وقد ترتدّ وقد دُفع للبوتيك.
     */
    public static function collectedSql(): string
    {
        $cleared = Cheques::CLEARED;

        return "(customer_invoices.status = '".CustomerInvoice::ISSUED."' AND customer_invoices.total"
            .' - (SELECT COALESCE(SUM(a.amount), 0) FROM customer_payment_allocations a'
            .' JOIN customer_payments p ON p.id = a.customer_payment_id'
            .' WHERE a.customer_invoice_id = customer_invoices.id AND p.cancelled_at IS NULL'
            ." AND (p.cheque_status IS NULL OR p.cheque_status = '{$cleared}'))"
            .' - '.CustomerInvoice::creditedSql().' <= 0.0005)';
    }

    public static function collected(int $invoiceId): bool
    {
        return CustomerInvoice::whereKey($invoiceId)->whereRaw(self::collectedSql())->exists();
    }

    /**
     * يُقيم ما في الفاتورة على حال تحصيلها الآن — يُنادى بعد كلّ ما يغيّره.
     *
     * التحصيلُ وإلغاؤه، وصرفُ الشيك وارتدادُه، وإشعارُ الدائن، والإصدارُ نفسُه (ورقةٌ
     * غطّتها النقاطُ صدرت مسدَّدة). ولا يُمسّ ما حملته ورقة: الورقةُ مجمَّدة، وفاتورةٌ
     * أُلغي تحصيلُها بعدها يُعلَّق سدادُ حصّتها حتّى تُراجَع (`BoutiqueHolds`).
     */
    public static function sync(int $invoiceId, ?CustomerPayment $payment = null): void
    {
        $open = fn () => BoutiqueInvoiceLine::where('customer_invoice_id', $invoiceId)
            ->whereNull('boutique_settlement_id')->whereNull('voided_at');

        $lines = BoutiqueInvoiceLine::where('customer_invoice_id', $invoiceId)->whereNull('voided_at')->exists();
        // وفاتورةٌ تغطّي طلباتٍ بيعت بالسياسة: ما حملته ورقةٌ منها يُعلَّق ويُفكّ بتحصيلها (`BoutiquePolicy`)
        // — بندُها أو قطعةٌ فيها: باقةٌ للمتجر قطعُها لبوتيك لا بندَ بوتيكٍ فيها
        $covered = DB::table('customer_invoice_orders')->where('customer_invoice_id', $invoiceId)->select('order_id');
        $orders = DB::table('order_items')->whereIn('order_id', $covered)->whereNotNull('boutique_policy')->exists()
            || DB::table('boutique_component_dues')->whereIn('order_id', $covered)->where('waits', true)->exists();

        if (! $lines && ! $orders) {
            return;
        }

        DB::transaction(function () use ($invoiceId, $open, $payment, $orders) {
            // المتجرُ أوّلًا — ترتيبُ الأقفال الواحد، وهو ما تمسكه الورقةُ قبل أن تقرأ (`Boutiques::write`)
            Business::whereKey(CustomerInvoice::whereKey($invoiceId)->value('business_id'))->lockForUpdate()->first();

            $done = self::collected($invoiceId);

            if ($orders) {
                BoutiqueHolds::syncOrders($invoiceId, $done, $payment);
            }

            $open()->lockForUpdate()->get()->each(function (BoutiqueInvoiceLine $line) use ($done) {
                if ($done === ($line->collected_at !== null)) {
                    return;
                }
                $line->update(['collected_at' => $done ? now() : null]);
            });

            // وما حملته ورقةٌ: الورقةُ مجمَّدة، ويُعلَّق سدادُ ما أُلغي تحصيلُه أو يُفكّ (`BoutiqueHolds`)
            BoutiqueHolds::sync($invoiceId, $done, $payment);
        });
    }

    /** فواتيرُ هذا التحصيل — بعد تسجيله أو إلغائه أو صرفِ شيكه أو ارتداده */
    public static function syncPayment(CustomerPayment $payment): void
    {
        $payment->allocations()->pluck('customer_invoice_id')->unique()->sort()
            ->each(fn ($id) => self::sync((int) $id, $payment));
    }

    /** أُلغيت الفاتورة: ما لم تحمله ورقةٌ يسقط، وما حملته يُكتب مرتجعُه إشعارًا (`BoutiqueReturns`) */
    public static function cancelled(CustomerInvoice $invoice, string $reason): void
    {
        $lines = BoutiqueInvoiceLine::where('customer_invoice_id', $invoice->id)->whereNull('voided_at')->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            if ($line->boutique_settlement_id === null) {
                $line->update(['voided_at' => now(), 'collected_at' => null]);
                self::unaccrue($line, (float) $line->net - (float) $line->returned_net, $line);

                continue;
            }

            $left = round((float) $line->quantity - (float) $line->returned_quantity, 3);
            if ($left > 0) {
                self::take($line, $left, null, 0.0, 0.0, $reason);
            }
        }
    }

    /* ═══════════ ٣. المرتجع بإشعار دائن ═══════════ */

    /**
     * إشعارُ دائنٍ نقّص كمّيّاتٍ من بنودها: يُنقص ما لأصحابها وحدهم.
     *
     * `$items` بنودُ الإشعار كما تُحفظ عليه: `key` («i» ورقمُ البند)، و`quantity_before`،
     * و`quantity_after` — وهي بنودُ تصحيح الفاتورة نفسُها (#195)، فيُنقص بها ما يصحَّح
     * منها. ومعها إن قيل `sellable` و`damaged`. وما لم تنقص كمّيّتُه (خصمٌ أو سعرٌ) لا
     * يمسّ البوتيك: الخصمُ على المتجر.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function returned(CustomerInvoice $invoice, CustomerCreditNote $note, array $items): void
    {
        foreach ($items as $row) {
            // قطعةٌ في باقة: يُسأل عنها وحدها — كم عاد وكم لم يعد (`BoutiqueInvoicePieces::answered`)
            if (preg_match('/^p(\d+)$/', (string) ($row['key'] ?? ''), $m)) {
                $piece = BoutiqueInvoiceLine::where('customer_invoice_id', $invoice->id)->where('kind', BoutiqueInvoiceLine::PIECE)
                    ->whereKey((int) $m[1])->whereNull('voided_at')->lockForUpdate()->first();
                if ($piece !== null) {
                    BoutiqueInvoicePieces::answered($piece, $note, $row);
                }

                continue;
            }
            if (! preg_match('/^i(\d+)$/', (string) ($row['key'] ?? ''), $m)) {
                continue;
            }

            $qty = round((float) ($row['quantity_before'] ?? 0) - (float) ($row['quantity_after'] ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }

            $line = BoutiqueInvoiceLine::where('customer_invoice_id', $invoice->id)->where('kind', BoutiqueInvoiceLine::LINE)
                ->where('customer_invoice_item_id', (int) $m[1])->whereNull('voided_at')->lockForUpdate()->first();

            if ($line !== null) {
                self::take($line, $qty, $note, round((float) ($row['sellable'] ?? 0), 3), round((float) ($row['damaged'] ?? 0), 3), (string) $note->reason);
            }
        }
    }

    /**
     * يأخذ كمّيّةً مرتجعة من بند — بالفرق بين ما كان وما بقي، فتجمع الأجزاءُ إلى البند بالبيسة.
     */
    public static function take(BoutiqueInvoiceLine $line, float $qty, ?CustomerCreditNote $note, float $sellable, float $damaged, string $reason): void
    {
        $left = round((float) $line->quantity - (float) $line->returned_quantity, 3);

        if ($qty > $left + 0.0005) {
            throw ValidationException::withMessages([
                'pieces' => __('«:name»: بقي منه :left لم يُردّ، والمكتوب :qty.', ['name' => $line->name, 'left' => $left + 0, 'qty' => $qty + 0]),
            ]);
        }
        if ($sellable < 0 || $damaged < 0 || $sellable + $damaged > $qty + 0.0005) {
            throw ValidationException::withMessages([
                'pieces' => __('«:name»: السليمُ والتالفُ أكثرُ من :qty المرتجعة.', ['name' => $line->name, 'qty' => $qty + 0]),
            ]);
        }

        $rateH = BoutiqueMoney::rate($line->rate);
        // بقاعدة لقطته: ما أُخرج منها من ضريبةٍ يُخرَج ممّا بقي
        $vatH = $line->rule === BoutiqueMoney::BEFORE_VAT && $line->tax_inclusive ? BoutiqueMoney::rate($line->tax_rate) : 0;
        $agreed = fn (float $q) => $line->agreed_price === null ? null : BoutiqueMoney::gross($q, (float) $line->agreed_price);
        $before = BoutiqueMoney::split(BoutiqueMoney::gross($left, $line->unit_price), $rateH, $vatH, $agreed($left));
        $after = BoutiqueMoney::split(BoutiqueMoney::gross(round($left - $qty, 3), $line->unit_price), $rateH, $vatH, $agreed(round($left - $qty, 3)));
        $diff = fn (string $k) => Baisa::toMoney($before[$k] - $after[$k]);
        $settled = $line->boutique_settlement_id !== null;

        $return = BoutiqueInvoiceReturn::create([
            'business_id' => $line->business_id,
            'boutique_invoice_line_id' => $line->id,
            'customer_credit_note_id' => $note?->id,
            'quantity' => $qty,
            'gross' => $diff('gross'),
            'commission' => $diff('commission'),
            'net' => $diff('net'),
            'after_sheet' => $settled,
            'user_id' => auth()->id(),
        ]);

        $line->update([
            'returned_quantity' => round((float) $line->returned_quantity + $qty, 3),
            'returned_gross' => round((float) $line->returned_gross + $diff('gross'), 3),
            'returned_commission' => round((float) $line->returned_commission + $diff('commission'), 3),
            'returned_net' => round((float) $line->returned_net + $diff('net'), 3),
        ]);

        // وما حملته ورقةٌ لا يُنقص منها: مرتجعُه إشعارٌ مرتبطٌ بها، بشرط اتّفاقية البوتيك
        if ($settled) {
            BoutiqueReturns::onInvoice($line, $qty, $diff('gross'), $diff('net'), $sellable, $damaged, $reason);
        } else {
            self::unaccrue($line, $diff('net'), $return);
        }
    }

    /* ═══════════ ٤. الورقة ═══════════ */

    /**
     * بنودُ البوتيك التي اكتمل تحصيلُها في الشهر ولم تحملها ورقة.
     *
     * وشهرُها شهرُ اكتمال التحصيل لا شهرُ الإصدار: به صارت مستحقّةَ الدفع. والختمُ
     * وحده لا يكفي: تُسأل القاعدةُ أيضًا هل ما زال التحصيلُ مكتملًا — فلا يحمل ورقةٌ
     * بندًا أُلغي تحصيلُه من بابٍ نُسي فيه `sync`.
     */
    public static function ready(int $businessId, ?int $boutiqueId, ?string $period): Builder
    {
        return BoutiqueInvoiceLine::query()
            ->where('boutique_invoice_lines.business_id', $businessId)
            ->when($boutiqueId !== null, fn ($q) => $q->where('boutique_invoice_lines.boutique_id', $boutiqueId))
            ->whereNull('boutique_invoice_lines.boutique_settlement_id')
            ->whereNull('boutique_invoice_lines.voided_at')
            ->whereColumn('boutique_invoice_lines.quantity', '>', 'boutique_invoice_lines.returned_quantity')
            ->whereNotNull('boutique_invoice_lines.collected_at')
            ->when($period !== null, function ($q) use ($period) {
                $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
                $q->whereBetween('boutique_invoice_lines.collected_at', [$from, (clone $from)->endOfMonth()]);
            })
            ->whereExists(fn ($q) => self::invoiceOf($q)->whereRaw(self::collectedSql()));
    }

    /** بانتظار التحصيل: صدرت فواتيرُها ولم يكتمل ما قُبض عنها */
    public static function awaiting(int $businessId, ?int $boutiqueId = null): Builder
    {
        return BoutiqueInvoiceLine::query()
            ->where('boutique_invoice_lines.business_id', $businessId)
            ->when($boutiqueId !== null, fn ($q) => $q->where('boutique_invoice_lines.boutique_id', $boutiqueId))
            ->whereNull('boutique_invoice_lines.boutique_settlement_id')
            ->whereNull('boutique_invoice_lines.voided_at')
            ->whereColumn('boutique_invoice_lines.quantity', '>', 'boutique_invoice_lines.returned_quantity')
            ->whereNull('boutique_invoice_lines.collected_at');
    }

    /** حملتها ورقةٌ ثمّ أُلغي تحصيلُ فاتورتها — استثناءٌ يُراجَع بيد، لا يُعدَّل به ما صدر */
    public static function reopened(int $businessId, ?int $boutiqueId = null): Builder
    {
        return BoutiqueInvoiceLine::query()
            ->where('boutique_invoice_lines.business_id', $businessId)
            ->when($boutiqueId !== null, fn ($q) => $q->where('boutique_invoice_lines.boutique_id', $boutiqueId))
            ->whereNotNull('boutique_invoice_lines.boutique_settlement_id')
            ->whereColumn('boutique_invoice_lines.quantity', '>', 'boutique_invoice_lines.returned_quantity')
            ->whereExists(fn ($q) => self::invoiceOf($q)->where('customer_invoices.status', CustomerInvoice::ISSUED)->whereRaw('NOT '.self::collectedSql()));
    }

    /** فاتورةُ البند — يُسأل عن تحصيلها لكلّ بندٍ مرشَّح، لا لكلّ فواتير المتجر */
    private static function invoiceOf($query)
    {
        return $query->selectRaw('1')->from('customer_invoices')->whereColumn('customer_invoices.id', 'boutique_invoice_lines.customer_invoice_id');
    }

    /**
     * ما بِيع لبوتيكٍ بفواتير العملاء في فترة — بيوم الفاتورة كسائر مبيعات المتجر.
     *
     * للتقرير لا للدفع: فيه ما لم يكتمل تحصيلُه بعد. وما رُدّ يُنقص من يوم بيعه كما
     * يُنقصه تصحيحُ الطلب. والملغاةُ خارجة.
     */
    public static function sold(int $businessId, int $boutiqueId, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): Builder
    {
        return BoutiqueInvoiceLine::query()
            ->join('customer_invoices', 'customer_invoices.id', '=', 'boutique_invoice_lines.customer_invoice_id')
            ->where('boutique_invoice_lines.business_id', $businessId)
            ->where('boutique_invoice_lines.boutique_id', $boutiqueId)
            // والقطعةُ في باقةٍ ليست بيعًا للبوتيك: ثمنُ الباقة للمتجر، وللبوتيك ثمنُ قطعته — كما في الصندوق
            ->where('boutique_invoice_lines.kind', BoutiqueInvoiceLine::LINE)
            ->where('customer_invoices.status', CustomerInvoice::ISSUED)
            ->whereColumn('boutique_invoice_lines.quantity', '>', 'boutique_invoice_lines.returned_quantity')
            ->when($start, fn ($q) => $q->where('customer_invoices.issued_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->where('customer_invoices.issued_at', '<=', $end->toDateString()))
            ->when($branchId, fn ($q) => $q->where('boutique_invoice_lines.branch_id', $branchId));
    }

    /** مجموعُ استعلامٍ من البنود بما بقي منها — كمّيّةً وإجماليًّا وعمولةً وصافيًا */
    public static function totals(Builder $lines): array
    {
        $row = (clone $lines)->toBase()
            ->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT boutique_invoice_lines.customer_invoice_id) as invoice_count')
            ->selectRaw('COALESCE(SUM(boutique_invoice_lines.quantity - returned_quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(boutique_invoice_lines.gross - returned_gross), 0) as gross')
            ->selectRaw('COALESCE(SUM(boutique_invoice_lines.commission - returned_commission), 0) as commission')
            ->selectRaw('COALESCE(SUM(boutique_invoice_lines.net - returned_net), 0) as net')
            ->first();

        return [
            'lines' => (int) $row->line_count,
            'invoices' => (int) $row->invoice_count,
            'quantity' => round((float) $row->quantity, 3) + 0.0,
            'gross' => round((float) $row->gross, 3) + 0.0,
            'commission' => round((float) $row->commission, 3) + 0.0,
            'net' => round((float) $row->net, 3) + 0.0,
        ];
    }

    /**
     * تحمل الورقةُ هذه البنود — مرّةً، وبما بقي من كلٍّ منها ساعتَها. يُعاد كم حملت.
     *
     * @param  list<int>  $ids
     */
    public static function claim(array $ids, BoutiqueSettlement $sheet): int
    {
        return $ids === [] ? 0 : BoutiqueInvoiceLine::whereIn('id', $ids)
            ->whereNull('boutique_settlement_id')->whereNull('voided_at')->whereNotNull('collected_at')
            ->update([
                'boutique_settlement_id' => $sheet->id,
                'settled_quantity' => DB::raw('quantity - returned_quantity'),
                'settled_gross' => DB::raw('gross - returned_gross'),
                'settled_commission' => DB::raw('commission - returned_commission'),
                'settled_net' => DB::raw('net - returned_net'),
            ]);
    }

    /* ═══════════ للقراءة ═══════════ */

    /**
     * حالُ بندٍ كما تُقرأ — من أعمدته وورقته.
     */
    public static function state(BoutiqueInvoiceLine $line): string
    {
        return match (true) {
            $line->voided_at !== null => self::VOID,
            $line->boutique_settlement_id !== null => $line->settlement?->expense?->status === Expense::PAID ? self::PAID : self::ISSUED,
            (float) $line->returned_quantity >= (float) $line->quantity => self::RETURNED,
            $line->collected_at !== null => self::READY,
            default => self::AWAITING,
        };
    }

    /**
     * بنودُ البوتيكات في فاتورةٍ — لصفحتها، لمن يقرأ مالَ البوتيكات.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(CustomerInvoice $invoice): array
    {
        return self::lines($invoice)->map(fn (BoutiqueInvoiceLine $l) => [
            'id' => (int) $l->id,
            'item_id' => (int) $l->customer_invoice_item_id,
            // صنفٌ منفرد، أو قطعةٌ داخل باقةٍ بثمنها كاملًا — وباقتُها
            'kind' => (string) $l->kind,
            'parent' => $l->parent_name,
            'boutique_id' => (int) $l->boutique_id,
            'boutique' => (string) $l->boutique_name,
            'name' => (string) $l->name,
            'quantity' => round((float) $l->quantity, 3),
            'returned' => round((float) $l->returned_quantity, 3),
            'unit_price' => round((float) $l->unit_price, 3),
            'gross' => round((float) $l->gross - (float) $l->returned_gross, 3) + 0.0,
            'discount' => round((float) $l->discount, 3),
            'rate' => round((float) $l->rate, 2),
            'commission' => round((float) $l->commission - (float) $l->returned_commission, 3) + 0.0,
            'net' => round((float) $l->net - (float) $l->returned_net, 3) + 0.0,
            // كُتب البندُ بأقلَّ من سعر بطاقته: نصيبُ البوتيك من سعر البند — يُرى ليُراجَع
            'under_list' => $l->list_price !== null && round((float) $l->unit_price, 3) < round((float) $l->list_price, 3) ? round((float) $l->list_price, 3) : null,
            'state' => self::state($l),
            'sheet' => $l->settlement?->number,
        ])->all();
    }

    /**
     * قطعُ البوتيكات الباقيةُ في الفاتورة — لمن يكتب إشعارَ دائن: أيُّها عاد؟ بلا نسبةٍ ولا عمولة.
     *
     * @return list<array<string, mixed>>
     */
    public static function pieces(CustomerInvoice $invoice): array
    {
        $all = self::lines($invoice);
        $lines = $all->filter(fn (BoutiqueInvoiceLine $l) => $l->kind === BoutiqueInvoiceLine::LINE && $l->voided_at === null && (float) $l->quantity > (float) $l->returned_quantity);
        $paid = $lines->isEmpty() ? collect() : CustomerInvoiceItem::whereIn('id', $lines->pluck('customer_invoice_item_id'))->pluck('line_total', 'id');

        return $lines
            ->map(fn (BoutiqueInvoiceLine $l) => [
                'key' => 'i'.$l->customer_invoice_item_id,
                'item_id' => (int) $l->customer_invoice_item_id,
                'name' => (string) $l->name,
                'boutique' => (string) $l->boutique_name,
                'left' => round((float) $l->quantity - (float) $l->returned_quantity, 3),
                // ما على العميل عن الواحدة في الورقة (بعد خصمها) — يُقترح به مبلغُ الإشعار
                'each' => round((float) ($paid[$l->customer_invoice_item_id] ?? 0) / (float) $l->quantity, 3),
                // ما حملته ورقةٌ يُسأل عن حال قطعه: بها يُحسم من يتحمّل
                'on_sheet' => $l->boutique_settlement_id !== null,
                'piece' => false,
                'parent' => null,
            ])->values()
            // وقطعُ الباقات: يُسأل عن كلٍّ منها، عادت أم لا — بلا ثمن (`BoutiqueInvoicePieces::askable`)
            ->concat(BoutiqueInvoicePieces::askable($all))->all();
    }

    /** @return Collection<int, BoutiqueInvoiceLine> */
    public static function lines(CustomerInvoice $invoice): Collection
    {
        return BoutiqueInvoiceLine::where('customer_invoice_id', $invoice->id)
            ->with(['settlement:id,number,expense_id', 'settlement.expense:id,status'])->orderBy('id')->get();
    }

    /** ما قيل عن قطعةٍ في باقة — صفٌّ داخليّ في الإشعار بلا مبلغ: لا يُطبع للعميل */
    private static function pieceItem(CustomerInvoice $invoice, int $lineId, array $p): ?array
    {
        $line = BoutiqueInvoiceLine::where('customer_invoice_id', $invoice->id)->where('kind', BoutiqueInvoiceLine::PIECE)->find($lineId);
        $qty = round((float) ($p['quantity'] ?? 0), 3);

        if ($line === null) {
            throw new RuntimeException(__('قطعةٌ ليست من هذه الفاتورة.'));
        }

        return $qty <= 0 ? null : [
            'key' => 'p'.$line->id,
            'piece' => true,
            'description' => $line->name.' — '.$line->parent_name,
            'quantity' => $qty,
            'sellable' => round((float) ($p['sellable'] ?? 0), 3),
            'damaged' => round((float) ($p['damaged'] ?? 0), 3),
        ];
    }

    /**
     * الكمّيّاتُ المرتجعة كما تُكتب على الإشعار — من بنود الفاتورة، لا ممّا أرسلته الشاشة.
     *
     * @param  array<int|string, mixed>  $pieces  `[رقمُ البند => ['quantity', 'sellable', 'damaged']]`
     * @return list<array<string, mixed>>
     */
    public static function noteItems(CustomerInvoice $invoice, array $pieces): array
    {
        // «i12» أو «12» بندٌ منفرد، و«p34» قطعةٌ في باقة
        $byItem = [];
        $out = [];
        foreach ($pieces as $key => $p) {
            if (preg_match('/^p(\d+)$/', (string) $key, $m)) {
                $out[] = self::pieceItem($invoice, (int) $m[1], (array) $p);

                continue;
            }
            $byItem[(int) preg_replace('/^i/', '', (string) $key)] = $p;
        }
        $out = array_values(array_filter($out));
        $pieces = $byItem;
        $items = CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)->whereIn('id', array_map('intval', array_keys($pieces)))->get()->keyBy('id');
        $taken = self::lines($invoice)->where('kind', BoutiqueInvoiceLine::LINE)->keyBy('customer_invoice_item_id');

        foreach ($pieces as $id => $p) {
            $qty = round((float) ($p['quantity'] ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }

            $item = $items->get((int) $id);
            if ($item === null) {
                throw new RuntimeException(__('بندٌ ليس من هذه الفاتورة.'));
            }

            $before = round((float) $item->quantity - (float) ($taken->get($item->id)?->returned_quantity ?? 0), 3);
            $share = (float) $item->quantity > 0 ? $qty / (float) $item->quantity : 0.0;
            $total = round((float) $item->line_total * $share, 3);
            $tax = round((float) $item->tax_amount * $share, 3);

            $out[] = [
                'key' => 'i'.$item->id,
                'change' => $before - $qty <= 0 ? 'removed' : 'changed',
                'description' => (string) $item->description,
                'quantity_before' => $before,
                'quantity_after' => round($before - $qty, 3),
                'unit_price_before' => round((float) $item->unit_price, 3),
                'unit_price_after' => round((float) $item->unit_price, 3),
                'discount_before' => round((float) $item->discount, 3),
                'discount_after' => round((float) $item->discount, 3),
                'tax_rate' => round((float) $item->tax_rate, 3),
                'taxable' => round($total - $tax, 3),
                'tax' => $tax,
                'total' => $total,
                'sellable' => round((float) ($p['sellable'] ?? 0), 3),
                'damaged' => round((float) ($p['damaged'] ?? 0), 3),
            ];
        }

        return $out;
    }
}
