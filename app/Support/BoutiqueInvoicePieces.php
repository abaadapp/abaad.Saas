<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueInvoiceLine;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * قطعُ البوتيك داخل باقات فاتورة العميل وإضافاتها — بحاسبة الصندوق والموقع نفسِها.
 *
 * ═══ لا قاعدةَ هنا ولا سعر ═══
 *
 * بنودُ الفاتورة تُحوَّل إلى ما يقرؤه `BoutiqueDues::price` ثمّ يُسأل هو: لمن القطعةُ وبكم،
 * وما يُرفض وبأيّ نصّ. فلا يختلف المبلغُ بالقناة — الصندوقُ والموقعُ وفاتورةُ العميل ورابطُ
 * الدفع عليها وطلبُ واتساب الآليّ حسابٌ واحد.
 *
 * ═══ ومفتاحُ القطع كما هو ═══
 *
 * متجرٌ مفتاحُه مطفأ لا يُحسب له شيء، وما صدر وهو مطفأ لا يُحسب بعد فتحه. وما حُسب قبل
 * إطفائه يُسوّى ويُسدَّد.
 *
 * ═══ واللقطةُ بندُ فاتورةٍ نوعُه `piece` ═══
 *
 * سعرُ القطعة وعددُها وصاحبُها وقاعدتُها ساعةَ الإصدار، في `boutique_invoice_lines`: فترث
 * انتظارَ التحصيل والتعليقَ وحمايةَ السداد والورقةَ والسجلّ. وبلا عمولةٍ — القاعدةُ القائمة —
 * إلّا بوتيكًا كُتب له اتّفاقٌ مستقلّ على القطعة داخل الباقة (`bundle_commission_rate`)، وهي
 * فارغةٌ للجميع. وتكلفتُها في الدفتر يومَ الفاتورة: مدين تكلفة البضاعة / دائن 2160.
 *
 * ═══ وإشعارُ الدائن يُسأل عن كلّ قطعة ═══
 *
 * عادت إلى الرفّ: يُنقص مستحقُّها. تلفت أو لم تعد، أو لم يُتحقَّق منها: تبقى للبوتيك — كما
 * يُفعل عند إلغاء طلب (`BoutiqueDues::cancel`). وما لم يُتحقَّق منه يُقال بعدُ (`cameBack`).
 * ولا مخزونَ هنا: فاتورةُ العميل لا تُخرج من الرفّ ولا تُعيد إليه.
 */
final class BoutiqueInvoicePieces
{
    /* ═══════════ الحساب ═══════════ */

    /**
     * بنودُ الفاتورة كما تصل من `SaleLines::priceItems` — الصنفُ ومقاسُه ووصفتُه، ومعه إضافاتُه
     * المدفوعةُ (سطورًا تحته) والمشمولةُ (في لقطته)، والإضافةُ المستقلّةُ سطرًا وحدها.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lines(CustomerInvoice $invoice): array
    {
        $items = CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)->whereNull('order_item_id')->orderBy('sort_order')->orderBy('id')->get();
        $bid = (int) $invoice->business_id;
        // ولو حُذف الصنفُ بين كتابة المسودّة وإصدارها
        $products = Product::withTrashed()->where('business_id', $bid)->whereIn('id', $items->pluck('product_id')->filter()->unique())->get()->keyBy('id');
        $recipes = RecipeItem::where('business_id', $bid)->whereIn('product_id', $products->keys())->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');
        $variants = ProductVariant::where('business_id', $bid)->whereIn('id', $items->map(fn ($i) => (int) ($i->details['variant']['id'] ?? 0))->filter()->unique())->get()->keyBy('id');
        $addonIds = $items->pluck('addon_id')->merge($items->flatMap(fn ($i) => array_column((array) ($i->details['included'] ?? []), 'addon_id')))->filter()->unique();
        $addons = Addon::where('business_id', $bid)->whereIn('id', $addonIds)->get()->keyBy('id');

        $out = [];
        $at = [];

        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            $addon = $addons->get($item->addon_id);

            // إضافةٌ بثمنها تحت صنفها: على بنده، وكمّيّتُها كمّيّتُها
            if ($addon !== null && isset($at[$item->parent_item_id])) {
                $out[$at[$item->parent_item_id]]['addons'][] = self::addon($addon, (float) $item->quantity, (int) $item->id);
                $out[$at[$item->parent_item_id]]['paid_b'] += Baisa::of((float) $item->line_total);

                continue;
            }

            $variant = $variants->get((int) ($item->details['variant']['id'] ?? 0));
            $recipe = $product ? ($recipes->get($product->id) ?? collect()) : collect();
            $at[$item->id] = count($out);
            $out[] = [
                'item_id' => (int) $item->id, 'description' => (string) $item->description, 'paid_b' => Baisa::of((float) $item->line_total),
                'product' => $product, 'variant' => $variant, 'qty' => (float) $item->quantity,
                'has_recipe' => $product !== null && Recipe::has($product, $variant, $recipe), 'recipe' => $recipe,
                'standalone_addon' => $product === null ? $addon : null, 'components' => [],
                'addons' => collect((array) ($item->details['included'] ?? []))
                    ->map(fn ($i) => ($a = $addons->get((int) ($i['addon_id'] ?? 0))) ? self::addon($a, (float) ($i['quantity'] ?? 1), null) : null)->filter()->values()->all(),
            ];
        }

        return $out;
    }

    private static function addon(Addon $a, float $qty, ?int $itemId): array
    {
        return ['addon' => $a, 'inventory_product_id' => $a->inventory_product_id, 'each' => AddonStock::each($a), 'qty' => $qty, 'item_id' => $itemId];
    }

    /**
     * قطعُ البوتيكات في فاتورةٍ بقاعدة الصندوق — أو لا شيء لفاتورةٍ تغطّي طلبًا أو متجرٍ مفتاحُه مطفأ.
     *
     * @return list<array{item_id: int, parent: string, key: string, due: array<string, mixed>, quantity: float}>
     *
     * @throws ValidationException بنصّ رفض الصندوق نفسِه
     */
    public static function of(CustomerInvoice $invoice): array
    {
        if ($invoice->coversOrders() || ! BoutiqueDues::active((int) $invoice->business_id)) {
            return [];
        }

        $out = [];
        foreach (BoutiqueDues::price((int) $invoice->business_id, self::lines($invoice)) as $l) {
            $add = function (array $d, float $times, string $key, int $itemId) use (&$out, $l) {
                $out[] = ['item_id' => $itemId, 'line' => $l['item_id'], 'parent' => $l['description'], 'key' => $key, 'due' => $d, 'quantity' => self::whole($d, $times)];
            };
            foreach ($l['boutique_dues'] ?? [] as $d) {
                $add($d, (float) $l['qty'], $d['source'] === BoutiqueComponentDue::RECIPE ? 'r'.$d['recipe_item_id'] : 's'.$d['addon_id'], $l['item_id']);
            }
            foreach ($l['addons'] ?? [] as $n => $a) {
                if ($d = $a['boutique_due'] ?? null) {
                    // إضافةٌ بثمنها على بندها هي، والمشمولةُ على بند صنفها
                    $add($d, (float) $a['qty'], 'a'.$d['addon_id'].($a['item_id'] === null ? ':'.$n : ''), $a['item_id'] ?? $l['item_id']);
                }
            }
        }

        return $out;
    }

    /** عددُ القطع — كاملًا كما يشترطه الصندوق */
    private static function whole(array $d, float $times): float
    {
        $quantity = round($d['unit_quantity'] * $times, 3);

        if ($quantity <= 0 || abs($quantity - round($quantity)) > 0.0001) {
            throw ValidationException::withMessages(['items' => __('«:name» صنفُ بوتيك بكمّيّةٍ كسريّة — يُؤخذ قطعًا كاملة.', ['name' => $d['product_name']])]);
        }

        return $quantity;
    }

    /**
     * يتحمّل المتجرُ خصمَه — فما يبقى من الباقة بعده يجب أن يسع ما للبوتيكات عن قطعها.
     *
     * يُسأل عن كلّ بندٍ بما يدفعه العميلُ عنه (ومعه إضافاتُه بثمنها)، ثمّ عن الفاتورة كلِّها
     * كما يُسأل عنها الصندوق (`BoutiqueDues::assertCovered`): خصمٌ على بندٍ لا يستره ثمنُ
     * بندٍ آخر. والرفضُ يقول الباقةَ وما بقي منها وما لهم وكم يُخفَّف.
     *
     * @param  list<array<string, mixed>>  $pieces
     */
    private static function assertCovered(CustomerInvoice $invoice, array $pieces): void
    {
        $currency = Money::of((int) $invoice->business_id);
        $paid = collect(self::lines($invoice))->keyBy('item_id');
        $left = fn (int $b) => Money::format(Baisa::toMoney($b), $currency);

        foreach (collect($pieces)->groupBy('line') as $line => $group) {
            $due = (int) $group->sum(fn ($p) => self::amountB($p['due'], $p['quantity']));
            $has = (int) $paid[$line]['paid_b'];

            if ($has < $due) {
                throw ValidationException::withMessages(['items' => __('«:name»: يبقى منها بعد الخصم :left، وللبوتيكات عن قطعها :due. خفّف خصمها :by على الأقلّ أو راجع سعرها.', [
                    'name' => $paid[$line]['description'], 'left' => $left($has), 'due' => $left($due), 'by' => $left($due - $has),
                ])]);
            }
        }

        $due = (int) collect($pieces)->sum(fn ($p) => self::amountB($p['due'], $p['quantity']));
        $has = Baisa::of(max(0.0, round((float) $invoice->subtotal - (float) $invoice->discount_total, 3)));

        if ($has < $due) {
            throw ValidationException::withMessages(['items' => __('ما يبقى من الطلب بعد الخصم (:left) أقلّ ممّا يُستحقّ للبوتيكات عن أصنافها فيه (:due). خفّف الخصم أو راجع الأسعار.', [
                'left' => $left($has), 'due' => $left($due),
            ])]);
        }
    }

    private static function amountB(array $due, float $quantity): int
    {
        return BoutiqueMoney::gross($quantity, $due['unit_price']);
    }

    /* ═══════════ اللقطة ═══════════ */

    /**
     * يكتب لقطةَ قطع فاتورةٍ تُصدَر — داخل معاملة الإصدار، وقبل أن يُسأل عن تحصيلها.
     *
     * وما يرفضه الصندوق يُرفض به الإصدارُ بنصّه: لا فاتورةَ بقطعةٍ لا يُعرف مستحقُّها.
     *
     * @throws RuntimeException
     */
    public static function snapshot(CustomerInvoice $invoice): void
    {
        try {
            $pieces = self::of($invoice);
            if ($pieces === []) {
                return;
            }
            self::assertCovered($invoice, $pieces);
        } catch (ValidationException $e) {
            throw new RuntimeException((string) collect($e->errors())->flatten()->first(), 0, $e);
        }

        $rates = Boutique::where('business_id', $invoice->business_id)->whereIn('id', collect($pieces)->pluck('due.boutique_id')->unique())->pluck('bundle_commission_rate', 'id');
        $now = now();

        foreach ($pieces as $p) {
            $d = $p['due'];
            $rateH = BoutiqueMoney::rate($rates[$d['boutique_id']] ?? 0);
            $split = BoutiqueMoney::split(self::amountB($d, $p['quantity']), $rateH);

            $line = BoutiqueInvoiceLine::create([
                'business_id' => $invoice->business_id,
                'boutique_id' => $d['boutique_id'],
                'boutique_name' => (string) $d['boutique_name'],
                'customer_invoice_id' => $invoice->id,
                'customer_invoice_item_id' => $p['item_id'],
                'branch_id' => $invoice->branch_id,
                'product_id' => $d['product_id'],
                'kind' => BoutiqueInvoiceLine::PIECE,
                'piece_key' => $p['key'],
                'source' => $d['source'],
                'recipe_item_id' => $d['recipe_item_id'] ?? null,
                'addon_id' => $d['addon_id'] ?? null,
                'parent_name' => mb_substr($p['parent'], 0, 255),
                'unit_quantity' => $d['unit_quantity'],
                'name' => $d['product_name'],
                'quantity' => $p['quantity'],
                'unit_price' => $d['unit_price'],
                'list_price' => $d['unit_price'],
                'gross' => Baisa::toMoney($split['gross']),
                'discount' => 0,
                'tax_rate' => 0,
                'tax_inclusive' => false,
                'tax_amount' => 0,
                // ثمنُ القطعة كاملًا، وعمولتُها داخل الباقة إن كُتب لها اتّفاق — لا عمولةُ بيعها منفردة
                'rule' => BoutiqueInvoiceLine::RULE_PIECE,
                'base' => Baisa::toMoney($split['base']),
                'rate' => $rateH / 100,
                'commission' => Baisa::toMoney($split['commission']),
                'net' => Baisa::toMoney($split['net']),
                'issued_at' => $now,
            ]);

            BoutiqueInvoiceDues::accrue($line, $invoice);
        }
    }

    /* ═══════════ المرتجع ═══════════ */

    /**
     * ما قيل عن قطعةٍ في إشعارٍ دائن: كم عاد، وكم لم يعد، والباقي لم يُتحقَّق منه.
     *
     * @param  array<string, mixed>  $row  `['quantity', 'sellable', 'damaged']`
     */
    public static function answered(BoutiqueInvoiceLine $line, CustomerCreditNote $note, array $row): void
    {
        $asked = round((float) ($row['quantity'] ?? 0), 3);
        $back = round((float) ($row['sellable'] ?? 0), 3);
        $lost = round((float) ($row['damaged'] ?? 0), 3);

        if ($asked <= 0) {
            return;
        }
        $open = round((float) $line->quantity - (float) $line->asked_quantity, 3);
        if ($asked > $open + 0.0005 || $back < 0 || $lost < 0 || $back + $lost > $asked + 0.0005) {
            throw ValidationException::withMessages([
                'pieces' => __('«:name» في «:parent»: ما عاد وما لم يعد أكثرُ من :qty قطعة لم يُسأل عنها.', ['name' => $line->name, 'parent' => $line->parent_name, 'qty' => $open + 0]),
            ]);
        }
        foreach ([$asked, $back, $lost] as $n) {
            if (abs($n - round($n)) > 0.0001) {
                throw ValidationException::withMessages(['pieces' => __('«:name» صنفُ بوتيك بكمّيّةٍ كسريّة — يُؤخذ قطعًا كاملة.', ['name' => $line->name])]);
            }
        }

        $line->update([
            'asked_quantity' => round((float) $line->asked_quantity + $asked, 3),
            'lost_quantity' => round((float) $line->lost_quantity + $lost, 3),
        ]);

        if ($back > 0) {
            BoutiqueInvoiceDues::take($line, $back, $note, $back, 0.0, (string) $note->reason);
        }
    }

    /** قطعٌ سُئل عنها ولم يُعرف أعادت أم لا */
    public static function unverified(BoutiqueInvoiceLine $line): float
    {
        return max(0.0, round((float) $line->asked_quantity - (float) $line->returned_quantity - (float) $line->lost_quantity, 3));
    }

    /**
     * قطعةٌ لم يُتحقَّق منها عادت (يُنقص مستحقُّها) أو تأكّد أنّها لن تعود (تبقى) — بسببه.
     *
     * @param  array<int, array{returned?: float|string, lost?: float|string}>  $answers  `[رقمُ البند => …]`
     */
    public static function cameBack(CustomerInvoice $invoice, array $answers, string $reason): int
    {
        return DB::transaction(function () use ($invoice, $answers, $reason) {
            // المتجرُ ثمّ الفاتورةُ ثمّ بنودُها — ترتيبُ الأقفال الواحد
            $locked = CustomerInvoices::lockInOrder($invoice);
            $n = 0;
            $lines = BoutiqueInvoiceLine::where('customer_invoice_id', $locked->id)->where('kind', BoutiqueInvoiceLine::PIECE)
                ->whereIn('id', array_map('intval', array_keys($answers)))->whereNull('voided_at')->orderBy('id')->lockForUpdate()->get();

            foreach ($lines as $line) {
                $back = round((float) ($answers[$line->id]['returned'] ?? 0), 3);
                $lost = round((float) ($answers[$line->id]['lost'] ?? 0), 3);
                if ($back <= 0 && $lost <= 0) {
                    continue;
                }
                if ($back < 0 || $lost < 0 || $back + $lost > self::unverified($line) + 0.0005 || abs($back - round($back)) > 0.0001 || abs($lost - round($lost)) > 0.0001) {
                    throw ValidationException::withMessages([
                        'pieces' => __('«:name» في «:parent»: لم يُتحقَّق من :qty قطعة فقط.', ['name' => $line->name, 'parent' => $line->parent_name, 'qty' => self::unverified($line) + 0]),
                    ]);
                }
                if ($lost > 0) {
                    $line->update(['lost_quantity' => round((float) $line->lost_quantity + $lost, 3)]);
                }
                if ($back > 0) {
                    BoutiqueInvoiceDues::take($line, $back, null, $back, 0.0, $reason);
                }
                $n++;
            }

            if ($n > 0) {
                Activity::log('updated', 'سجّل حال قطع بوتيك في الفاتورة '.$locked->number.' — '.$reason, [
                    'business_id' => (int) $locked->business_id, 'subject_id' => $locked->id, 'subject_type' => 'customer_invoice',
                ]);
            }

            return $n;
        });
    }

    /* ═══════════ للقراءة ═══════════ */

    /**
     * قطعُ الباقات في فاتورةٍ لمن يكتب إشعارًا: ما لم يُسأل عنه بعد — بلا ثمنٍ ولا نسبة.
     *
     * @return list<array<string, mixed>>
     */
    public static function askable(Collection $lines): array
    {
        return $lines->filter(fn (BoutiqueInvoiceLine $l) => $l->kind === BoutiqueInvoiceLine::PIECE && $l->voided_at === null
            && (float) $l->quantity > (float) $l->asked_quantity)
            ->map(fn (BoutiqueInvoiceLine $l) => [
                'key' => 'p'.$l->id,
                'item_id' => (int) $l->customer_invoice_item_id,
                'name' => (string) $l->name,
                'parent' => (string) $l->parent_name,
                'boutique' => (string) $l->boutique_name,
                'left' => round((float) $l->quantity - (float) $l->asked_quantity, 3),
                // مبلغُ الإشعار للباقة لا لقطعتها: لا يُقترح به شيء
                'each' => 0.0,
                'on_sheet' => true,
                'piece' => true,
            ])->values()->all();
    }

    /**
     * وما سُئل عنه ولم يُتحقَّق منه — يُقال حالُه بعدُ.
     *
     * @return list<array{id: int, name: string, parent: string, boutique: string, left: float}>
     */
    public static function waiting(CustomerInvoice $invoice): array
    {
        return BoutiqueInvoiceLine::where('customer_invoice_id', $invoice->id)->where('kind', BoutiqueInvoiceLine::PIECE)->whereNull('voided_at')->orderBy('id')->get()
            ->map(fn (BoutiqueInvoiceLine $l) => ['id' => (int) $l->id, 'name' => (string) $l->name, 'parent' => (string) $l->parent_name, 'boutique' => (string) $l->boutique_name, 'left' => self::unverified($l)])
            ->filter(fn (array $r) => $r['left'] > 0)->values()->all();
    }

    /**
     * تكلفةُ قطع الفواتير في الدفتر — بيوم قيدها وفرعه، بعد ما رُدّ منها.
     *
     * تقرؤها تقاريرُ التكلفة (`Demo::cogsByBucket`): بنودُ الطلبات تحمل تكلفةَ قطعها في لقطتها،
     * وفاتورةُ العميل بلا طلب لا بندَ لها هناك — فكان ربحُ التقرير أعلى من ربح الدفتر بثمنها.
     */
    public static function cost(int $businessId, ?\Illuminate\Support\Carbon $start, ?\Illuminate\Support\Carbon $end, ?int $branchId)
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.business_id', $businessId)->where('journal_entries.posted', true)
            ->where('accounts.system_key', 'cogs')
            ->whereIn('journal_entries.source', [BoutiqueInvoiceDues::PIECE_ACCRUAL, BoutiqueReturns::SOURCE])
            ->when($start, fn ($q) => $q->where('journal_entries.entry_date', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->where('journal_entries.entry_date', '<', $end->toDateString()))
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId));
    }
}
