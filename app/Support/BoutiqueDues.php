<?php

namespace App\Support;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueCreditOffset;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ما يُستحقّ للبوتيك عن صنفه حين يدخل باقةً (وصفة) أو يُضاف إليها (إضافة).
 *
 * ═══ القاعدةُ في سطر ═══
 *
 * المستحقُّ ثمنُ الصنف (`products.price`) في الكمّيّة التي خرجت من رفّه —
 * لا تكلفتُه، ولا ما بعد خصم المتجر، ولا ما بعد عمولة بيعه منفردًا. ويُلتقط
 * ساعةَ البيع: سعرٌ يتغيّر غدًا لا يمسّ ما بيع اليوم.
 *
 * ═══ ولا تكلفةَ مرّتين ═══
 *
 * المستحقُّ هو تكلفةُ المكوّن في لقطة البند (`order_items.cost`) ولقطة
 * الإضافة (`order_item_addons.cost`) — بدل تكلفة بطاقته. فالدفترُ
 * (`Books::costOf`) والتقريرُ (`Demo::cogsFor`) يقرآن رقمًا واحدًا. ويُقسَم
 * قيدُ التكلفة: ما خرج من مخزون المتجر دائنٌ في «المخزون»، وما للبوتيك
 * دائنٌ في «مستحقّات البوتيكات» (2160). والسدادُ يُدين 2160 — لا مصروف.
 *
 * ═══ وما لا يُحسب بدقّةٍ يُمنع ═══
 *
 * صنفُ بوتيكٍ له مقاسات، أو بنسبة فاقد، أو بكمّيّةٍ كسريّة، أو بلا سعر، أو
 * داخلَ صنف بوتيكٍ آخر، أو في طلبٍ مخصَّص، أو في متجرٍ يحتسب الضريبة: تُردّ
 * البيعةُ برسالة — لا تُحسب بمبلغٍ قد يكون خطأ. إلّا دفعةً قبضها البنك
 * (`SaleLines::SETTLING`): تُقبل ويُحسب مستحقُّها كما هو، فلا مالَ بلا طلب.
 *
 * والمفتاحُ مطفأٌ لكلّ متجر (`businesses.boutique_components_enabled`)، وما
 * دام مطفأً فكلُّ شيءٍ كما كان قبله.
 */
class BoutiqueDues
{
    /** مصدرُ قيود الأرصدة والسداد في الدفتر */
    public const SOURCE = 'مستحقّات البوتيكات';

    public static function active(int $businessId): bool
    {
        return DB::table('businesses')->where('id', $businessId)
            ->where('boutiques_enabled', true)
            ->where('boutique_components_enabled', true)
            ->exists();
    }

    /**
     * بنودٌ مسعَّرة (`SaleLines::priceItems`) يُحمَّل كلٌّ منها مستحقَّ بوتيكاته.
     *
     * يكتب على البند `boutique_dues` (وصفةٌ أو إضافةٌ مستقلّة) وعلى الإضافة
     * `boutique_due`، ويضع المستحقَّ مكانَ تكلفة البطاقة في `cost`.
     *
     * ═══ وما ثُبِّت قبل البنك يبقى بعده ═══
     *
     * `$pinned` أسعارُ أصناف البوتيك وأصحابُها ساعةَ فُتحت صفحةُ البنك
     * (`pinned`)، تُحفظ في نيّة الدفع على الخادم. فإن تغيّر سعرُ القطعة
     * والزبونُ يدفع لم يتغيّر مستحقُّها: ما فُحص قبل البنك هو ما يُكتب بعده.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int|string, array{price: float, boutique_id: int}>|null  $pinned
     * @return array<int, array<string, mixed>>
     */
    public static function price(int $businessId, array $lines, ?string $channel = null, ?array $pinned = null): array
    {
        if (! $lines || ! self::active($businessId)) {
            return $lines;
        }

        $pinned = $channel === SaleLines::SETTLING ? ($pinned ?? []) : [];
        $owned = self::owned($businessId, $lines, array_keys($pinned));
        if ($owned->isEmpty()) {
            return $lines;
        }

        $settling = $channel === SaleLines::SETTLING;
        $taxed = Vat::enabled($businessId);
        $cards = self::cards($businessId, $lines);
        $errors = [];

        foreach ($lines as $idx => $l) {
            $parent = $l['product'] ?? null;

            foreach ($l['components'] ?? [] as $c) {
                $p = $owned->get((int) ($c['product_id'] ?? 0));
                if ($p && ! $settling) {
                    $errors["items.$idx"] = __('«:name» صنفُ بوتيك، ولا يدخل طلبًا مخصَّصًا بعد.', ['name' => $p->name]);
                }
            }

            if ($l['has_recipe'] ?? false) {
                $dues = [];
                $shop = 0.0;

                foreach (Recipe::forLine($parent, $l['variant'] ?? null, $l['recipe']) as $r) {
                    $p = $owned->get((int) $r->component_product_id);
                    if (! $p) {
                        $shop += $r->effectiveQuantity() * (float) ($cards[(int) $r->component_product_id] ?? 0);

                        continue;
                    }

                    $why = self::refusal($p, (float) $r->quantity, (float) $r->wastage_percent, $parent, $taxed);
                    if ($why !== null && ! $settling) {
                        $errors["items.$idx"] = $why;

                        continue;
                    }

                    // الفاقدُ لا يولّد مستحقًّا: الكمّيّةُ المسجّلة في الوصفة وحدها
                    $dues[] = self::due(BoutiqueComponentDue::RECIPE, $p, (float) $r->quantity, recipeItemId: (int) $r->id, pin: $pinned[$p->id] ?? null);
                }

                if ($dues) {
                    $lines[$idx]['boutique_dues'] = $dues;
                    $lines[$idx]['cost'] = round(round($shop, 3) + array_sum(array_column($dues, 'unit_amount')), 3);
                }
            }

            $standalone = $l['standalone_addon'] ?? null;
            if ($standalone && ($p = $owned->get((int) $standalone->inventory_product_id))) {
                $each = AddonStock::each($standalone);
                $why = self::refusal($p, $each, 0.0, null, $taxed);
                if ($why !== null && ! $settling) {
                    $errors["items.$idx"] = $why;
                } else {
                    $due = self::due(BoutiqueComponentDue::ADDON, $p, $each, addonId: (int) $standalone->id, pin: $pinned[$p->id] ?? null);
                    $lines[$idx]['boutique_dues'] = [$due];
                    $lines[$idx]['cost'] = $due['unit_amount'];
                }
            }

            foreach ($l['addons'] ?? [] as $k => $a) {
                $p = $owned->get((int) ($a['inventory_product_id'] ?? 0));
                if (! $p) {
                    continue;
                }

                $each = (float) ($a['each'] ?? 0);
                $why = self::refusal($p, $each, 0.0, $parent, $taxed);
                if ($why !== null && ! $settling) {
                    $errors["items.$idx.addons"] = $why;

                    continue;
                }

                $due = self::due(BoutiqueComponentDue::ADDON, $p, $each, addonId: (int) $a['addon']->id, pin: $pinned[$p->id] ?? null);
                $lines[$idx]['addons'][$k]['boutique_due'] = $due;
                $lines[$idx]['addons'][$k]['cost'] = $due['unit_amount'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /** مجموعُ ما تُحمَّله هذه البنود للبوتيكات */
    public static function total(array $lines): float
    {
        $sum = 0.0;

        foreach ($lines as $l) {
            foreach ($l['boutique_dues'] ?? [] as $d) {
                $sum += $d['unit_amount'] * (int) $l['qty'];
            }
            foreach ($l['addons'] ?? [] as $a) {
                if ($d = $a['boutique_due'] ?? null) {
                    $sum += $d['unit_amount'] * (int) $a['qty'];
                }
            }
        }

        return round($sum, 3);
    }

    /**
     * أسعارُ أصناف البوتيك في هذه البنود وأصحابُها — تُحفظ في نيّة الدفع.
     *
     * @return array<int, array{price: float, boutique_id: int}>
     */
    public static function pinned(array $lines): array
    {
        $out = [];
        $take = function (array $d) use (&$out) {
            $out[(int) $d['product_id']] = ['price' => (float) $d['unit_price'], 'boutique_id' => (int) $d['boutique_id']];
        };

        foreach ($lines as $l) {
            foreach ($l['boutique_dues'] ?? [] as $d) {
                $take($d);
            }
            foreach ($l['addons'] ?? [] as $a) {
                if ($d = $a['boutique_due'] ?? null) {
                    $take($d);
                }
            }
        }

        return $out;
    }

    /**
     * يتحمّل المتجرُ خصمَه — فما يبقى من الطلب بعده يجب أن يسع ما للبوتيكات.
     *
     * والتوصيلُ والضريبةُ ليسا منه: الأوّل أجرةُ خدمةٍ للمتجر، والثانية
     * ليست له أصلًا.
     */
    public static function assertCovered(int $businessId, array $lines, float $subtotal, float $discount): void
    {
        $due = self::total($lines);
        if ($due <= 0) {
            return;
        }

        $left = round($subtotal - $discount, 3);
        if ($left + 0.0005 >= $due) {
            return;
        }

        $currency = Money::of($businessId);

        throw ValidationException::withMessages([
            'items' => __('ما يبقى من الطلب بعد الخصم (:left) أقلّ ممّا يُستحقّ للبوتيكات عن أصنافها فيه (:due). خفّف الخصم أو راجع الأسعار.', [
                'left' => Money::format($left, $currency),
                'due' => Money::format($due, $currency),
            ]),
        ]);
    }

    /* ═══════════ الكتابة ═══════════ */

    /** مستحقُّ الوصفة أو الإضافة المستقلّة لبندٍ كُتب الآن */
    public static function forItem(Order $order, OrderItem $item, array $line): void
    {
        foreach ($line['boutique_dues'] ?? [] as $d) {
            $key = $d['source'] === BoutiqueComponentDue::RECIPE
                ? "i{$item->id}:r{$d['recipe_item_id']}"
                : "i{$item->id}:s{$d['addon_id']}";

            self::write($order, $item->id, null, $d, (int) $item->quantity, $key);
        }
    }

    /** مستحقُّ إضافةٍ كُتبت الآن على بندها */
    public static function forAddon(Order $order, OrderItem $item, OrderItemAddon $row, array $addon): void
    {
        if ($d = $addon['boutique_due'] ?? null) {
            self::write($order, $item->id, $row->id, $d, (int) $row->quantity, "a{$row->id}");
        }
    }

    /** ما على هذا الطلب للبوتيكات الآن — المستحقُّ ناقصًا ما عُكس منه */
    public static function owedFor(Order $order): float
    {
        return round((float) BoutiqueComponentDue::where('order_id', $order->id)->sum('amount'), 3);
    }

    /* ═══════════ العكس ═══════════ */

    /** حالاتُ قطعة البوتيك عند إلغاء طلبها */
    public const RETURNED = 'returned';

    public const LOST = 'lost';

    public const UNVERIFIED = 'unverified';

    /**
     * الطلبُ أُلغي — والسؤالُ لكلّ قطعة: هل تعود إلى الرفّ فعلًا؟
     *
     * يُجاب لكلّ مكوّنٍ وبكمّيّته، لا للطلب جملةً: باقةٌ فيها فضّةٌ عادت
     * وعطرٌ تلف ليستا حالًا واحدة. و`$pieces`:
     *
     *   - `true`  كلُّ القطع عادت، و`false` كلُّها لم تعد، و`null` لم يُتحقَّق من شيء.
     *   - أو `[رقمُ صفّ المستحقّ => ['returned' => n, 'lost' => m]]` — وما بقي
     *     من كمّيّة الصفّ لم يُتحقَّق منه. وصفٌّ لم يُذكر لم يُتحقَّق منه.
     *
     * ما عاد يُعكس مستحقُّه (ويبقى على الرفّ الذي ردّه إليه الإلغاء). وما لم
     * يعد أو لم يُتحقَّق منه **لا يُعكس تلقائيًّا**: يبقى للبوتيك بسببه
     * (`kept_reason`)، ويُرفع عن الرفّ ثانيةً، ويُقيَّد ثمنُه خسارةً على المتجر
     * (مدين «مصروفات أخرى» / دائن 2160 — كما `StockLosses`). فإن عاد بعدُ
     * فـ`returned` يعكسه بقيدٍ موثَّق.
     *
     * @param  bool|array<int, array{returned?: int, lost?: int}>|null  $pieces
     */
    public static function cancel(Order $order, ?string $reason = null, bool|array|null $pieces = null): void
    {
        $rows = self::live()->where('order_id', $order->id)->lockForUpdate()->orderBy('id')->get();

        foreach ($rows as $row) {
            $qty = (int) round((float) $row->quantity);
            [$back, $lost] = match (true) {
                $pieces === true => [$qty, 0],
                $pieces === false => [0, $qty],
                is_array($pieces) => [(int) ($pieces[$row->id]['returned'] ?? 0), (int) ($pieces[$row->id]['lost'] ?? 0)],
                default => [0, 0],
            };

            if ($back < 0 || $lost < 0 || $back + $lost > $qty) {
                throw ValidationException::withMessages([
                    'pieces' => __('«:name»: ما عاد وما لم يعد أكثرُ من :qty قطعة في الطلب.', ['name' => $row->product_name, 'qty' => $qty]),
                ]);
            }

            $open = $qty - $back - $lost;
            $states = array_filter([self::LOST => $lost, self::UNVERIFIED => $open]);

            // حالٌ واحدةٌ للصفّ كلِّه: يُعكس كلُّه أو يبقى كما هو — بلا صفوفٍ جديدة
            if ($back === $qty) {
                self::undo($row, $reason);

                continue;
            }
            if ($back === 0 && count($states) === 1) {
                self::keep($order, $row, array_key_first($states), offShelf: true);

                continue;
            }

            // وإلّا يُعكس ويُكتب ما بقي للبوتيك صفًّا لكلّ حال
            $key = (string) $row->live_key;
            self::undo($row, $reason);
            foreach ($states as $state => $n) {
                self::keep($order, self::part($row, $key, $n, $state), $state, offShelf: true);
            }
        }
    }

    /**
     * قطعٌ بقي مستحقُّها عند الإلغاء عادت إلى الرفّ — يُعكس الآن ما عاد منها.
     *
     * `$pieces`: `[رقمُ الصفّ => كم عاد]`، أو `null` لكلّ ما بقي. يُردّ قيدُ
     * خسارتها، وتعود إلى الرفّ مرّةً واحدة، ويُعكس مستحقُّها — فإن كانت ورقتُه
     * قد صدرت صار العكسُ رصيدًا للمتجر على البوتيك (انظر `undo`). وما لم يعد
     * من الصفّ يبقى صفًّا بحاله وخسارتِه.
     *
     * @param  array<int, int>|null  $pieces
     */
    public static function returned(Order $order, string $reason, ?array $pieces = null): void
    {
        DB::transaction(function () use ($order, $reason, $pieces) {
            $rows = self::live()->where('order_id', $order->id)->whereNotNull('kept_reason')
                ->lockForUpdate()->orderBy('id')->get();

            $shelf = [];
            foreach ($rows as $row) {
                $qty = (int) round((float) $row->quantity);
                $back = $pieces === null ? $qty : (int) ($pieces[$row->id] ?? 0);
                if ($back <= 0) {
                    continue;
                }
                if ($back > $qty) {
                    throw ValidationException::withMessages([
                        'pieces' => __('«:name»: ما عاد أكثرُ من :qty قطعة بقيت للبوتيك.', ['name' => $row->product_name, 'qty' => $qty]),
                    ]);
                }

                $shelf[(int) $row->product_id] = ($shelf[(int) $row->product_id] ?? 0) + $back;
                $key = (string) $row->live_key;
                $state = $row->kept_state ?? self::UNVERIFIED;
                self::undo($row, $reason);

                if ($qty > $back) {
                    // والباقي غائبٌ عن الرفّ أصلًا — لا يُرفع عنه ثانيةً
                    self::keep($order, self::part($row, $key, $qty - $back, $state), $state, offShelf: false);
                }
            }

            StockLedger::move((int) $order->business_id, $order->branch_id, $shelf, StockLedger::CORRECTION,
                PosCashier::name() ?? auth()->user()?->name, $order->number);
        });
    }

    /** ما لم يُتحقَّق منه تأكّد أنّه لن يعود — تتغيّر حالُه وسببُه، ولا يتغيّر مالٌ ولا رفّ */
    public static function confirmLost(Order $order, array $rowIds): void
    {
        self::live()->where('order_id', $order->id)->whereIn('id', $rowIds)
            ->where('kept_state', self::UNVERIFIED)
            ->update(['kept_state' => self::LOST, 'kept_reason' => self::why(self::LOST)]);
    }

    /**
     * قطعُ البوتيك الحيّة في طلب — لشاشة الإلغاء والإرجاع.
     *
     * @return list<array{id: int, product: string, boutique: ?string, quantity: int, unit_price: float, source: string, state: ?string}>
     */
    public static function piecesOf(Order $order): array
    {
        return self::live()->where('order_id', $order->id)->orderBy('id')->get()
            ->map(fn (BoutiqueComponentDue $r) => [
                'id' => (int) $r->id, 'product' => $r->product_name, 'boutique' => $r->boutique_name,
                'quantity' => (int) round((float) $r->quantity), 'unit_price' => (float) $r->unit_price,
                'source' => $r->source, 'state' => $r->kept_state,
            ])->values()->all();
    }

    /**
     * كمّيّةُ البند تغيّرت: يُعكس مستحقُّه ويُكتب بالكمّيّة الجديدة — بسعره يومَ بيع.
     *
     * والصفرُ حذفٌ: يُعكس البندُ بإضافاته ولا يُكتب شيء.
     */
    public static function resizeItem(OrderItem $item, int $quantity, ?string $reason = null): void
    {
        if ($quantity <= 0) {
            self::reverse(self::live()->where('order_item_id', $item->id), $reason);

            return;
        }

        self::rewrite(self::live()->where('order_item_id', $item->id)->whereNull('order_item_addon_id'), $quantity, $reason);
    }

    public static function resizeAddon(OrderItemAddon $row, int $quantity, ?string $reason = null): void
    {
        $rows = self::live()->where('order_item_addon_id', $row->id);

        $quantity <= 0 ? self::reverse($rows, $reason) : self::rewrite($rows, $quantity, $reason);
    }

    /* ═══════════ الكشف والتسوية والسداد ═══════════ */

    /**
     * جزءُ المكوّنات من كشف بوتيكٍ عن شهر — بطريقته هو لا بطريقة المنفرد.
     *
     * المنفردُ إجماليٌّ تُقتطع منه العمولة. وهذا ثمنُ قطعٍ دخلت باقات:
     * لا عمولة عليه، ولا يُعاد حسابُه — يُجمع من صفوف الدفتر كما كُتبت.
     *
     *   - `due`: ما لم تحمله ورقةٌ من مستحقّات الشهر وعكوسها (يتقاصّان).
     *   - `credit`: ما يُقاصّ من رصيد المتجر على البوتيك — الأقدمُ أوّلًا،
     *     ولو جزءًا من رصيد، حتى يبلغ `due` ولا يجاوزه: رصيدٌ بسبعةٍ على
     *     مستحقٍّ بثلاثة يُقاصّ ثلاثةً ويبقى منه أربعة لورقةٍ بعدها أو لسند
     *     استرداد (`refund`). فالصافي لا يكون سالبًا أبدًا.
     *
     * @return array{lines: list<array<string, mixed>>, due: float, credit: float, credit_available: float, net: float, count: int, from_at: ?string, to_at: ?string, ids: list<int>, credit_plan: array<int, float>}
     */
    public static function statement(int $businessId, int $boutiqueId, string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();

        $rows = BoutiqueComponentDue::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->whereNull('boutique_settlement_id')
            ->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])
            ->whereBetween('period_at', [$from, $to])
            ->orderBy('period_at')->orderBy('id')->get();

        $credits = self::credits($businessId, $boutiqueId, $to);
        $due = round((float) $rows->sum('amount'), 3);

        // يُقاصّ من الأقدم، ولو جزءًا من رصيد — ولا يجاوز الصافي صفرًا
        $credit = 0.0;
        $plan = [];
        foreach ($credits as $id => $left) {
            $take = round(min($left, $due - $credit), 3);
            if ($take <= 0) {
                break;
            }
            $credit = round($credit + $take, 3);
            $plan[$id] = $take;
        }

        $lines = [];
        foreach ($rows as $r) {
            $k = $r->product_id.'|'.$r->unit_price.'|'.$r->source;
            $lines[$k] ??= [
                'product_id' => (int) $r->product_id, 'name' => $r->product_name,
                'source' => $r->source, 'unit_price' => (float) $r->unit_price,
                'quantity' => 0.0, 'amount' => 0.0,
            ];
            $lines[$k]['quantity'] = round($lines[$k]['quantity'] + (float) $r->quantity, 3);
            $lines[$k]['amount'] = round($lines[$k]['amount'] + (float) $r->amount, 3);
        }

        return [
            'lines' => array_values($lines),
            'due' => $due,
            'credit' => $credit,
            'credit_available' => round(array_sum($credits), 3),
            'net' => round($due - $credit, 3),
            'count' => $rows->count(),
            'from_at' => $rows->first()?->period_at?->toDateTimeString(),
            'to_at' => $rows->last()?->period_at?->toDateTimeString(),
            'ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'credit_plan' => $plan,
        ];
    }

    /**
     * الورقةُ تحجز صفوفَها — كما تحجز بنودَ المنفرد: `update … whereNull`.
     *
     * ولا قيدَ عند الإصدار: التكلفةُ كُتبت يومَ البيع، والالتزامُ قائمٌ في 2160.
     *
     * @throws SettlementRefused
     */
    public static function claim(int $settlementId, array $part): void
    {
        $ids = $part['ids'];
        if ($ids) {
            $claimed = BoutiqueComponentDue::whereIn('id', $ids)->whereNull('boutique_settlement_id')
                ->update(['boutique_settlement_id' => $settlementId]);

            if ($claimed !== count($ids)) {
                throw new SettlementRefused(__('صدرت ورقةٌ لهذه البيعات قبل لحظة — افتح الشاشة من جديد.'));
            }
        }

        self::offset($settlementId, $part['credit_plan'] ?? []);
    }

    /**
     * سطرُ مقاصّةٍ لكلّ رصيدٍ أُخذ منه — بعد قفل صفوفه وقراءة باقيها من جديد.
     *
     * ضغطتان متقاربتان تقرآن الباقي نفسَه: تقفل الأولى الصفوفَ فتكتب،
     * وتقرأ الثانية بعدها باقيًا أقلّ ممّا خطّطت له فتُردّ. والفهرسُ الفريد
     * (رصيدٌ وورقة) يمنع السطرَ نفسَه مرّتين.
     *
     * @param  array<int, float>  $plan  [رقمُ صفّ الرصيد => كم يُقاصّ منه]
     *
     * @throws SettlementRefused
     */
    private static function offset(int $settlementId, array $plan): void
    {
        if (! $plan) {
            return;
        }

        $credits = BoutiqueComponentDue::whereIn('id', array_keys($plan))
            ->where('kind', BoutiqueComponentDue::CREDIT)->lockForUpdate()->orderBy('id')->get()->keyBy('id');
        $used = self::used(array_keys($plan));

        foreach ($plan as $id => $amount) {
            $c = $credits->get($id);
            $left = $c ? round(-(float) $c->amount - ($used[$id] ?? 0.0), 3) : 0.0;
            if ($amount <= 0 || $amount > $left + 0.0005) {
                throw new SettlementRefused(__('تغيّر رصيدُ المتجر على البوتيك قبل لحظة — افتح الشاشة من جديد.'));
            }

            BoutiqueCreditOffset::create([
                'business_id' => $c->business_id, 'boutique_id' => $c->boutique_id,
                'credit_id' => $c->id, 'boutique_settlement_id' => $settlementId, 'amount' => round($amount, 3),
            ]);
        }
    }

    /**
     * أرصدةُ المتجر على بوتيكٍ حتى يومٍ ما — بباقي كلٍّ منها، الأقدمُ أوّلًا.
     *
     * @return array<int, float> [رقمُ صفّ الرصيد => باقيه]
     */
    public static function credits(int $businessId, int $boutiqueId, ?Carbon $through = null): array
    {
        $rows = BoutiqueComponentDue::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->where('kind', BoutiqueComponentDue::CREDIT)
            ->when($through, fn ($q) => $q->where('period_at', '<=', $through))
            ->orderBy('period_at')->orderBy('id')->get(['id', 'amount']);
        $used = self::used($rows->pluck('id')->all());

        $out = [];
        foreach ($rows as $r) {
            $left = round(-(float) $r->amount - ($used[(int) $r->id] ?? 0.0), 3);
            if ($left > 0) {
                $out[(int) $r->id] = $left;
            }
        }

        return $out;
    }

    /** ما سُجّل على كلّ رصيدٍ من مقاصّةٍ أو استرداد */
    private static function used(array $creditIds): array
    {
        return $creditIds
            ? BoutiqueCreditOffset::whereIn('credit_id', $creditIds)->groupBy('credit_id')
                ->selectRaw('credit_id, SUM(amount) as used')->pluck('used', 'credit_id')
                ->map(fn ($v) => (float) $v)->all()
            : [];
    }

    /**
     * سدادُ جزء المكوّنات: يُدين 2160 بما حملته الورقة — لا مصروفَ ثانٍ.
     *
     * مدين 2160 بـ`components_due`، دائن 1370 بما قاصّته من رصيد المتجر،
     * دائن الصندوق أو البنك بالباقي. ومرّةً واحدة: الورقةُ تُقفل صفَّها أوّلًا.
     * وجزءُ المنفرد يبقى على طريقته: مصروفٌ يُسدَّد من شاشة المستحقّات.
     */
    public static function pay(BoutiqueSettlement $settlement, string $method, ?Carbon $at = null): void
    {
        DB::transaction(function () use ($settlement, $method, $at) {
            $s = BoutiqueSettlement::whereKey($settlement->id)->lockForUpdate()->first();
            if (! $s || $s->components_paid_at !== null || (float) $s->components_due <= 0) {
                return;
            }

            $at ??= now();
            $net = round((float) $s->components_net, 3);

            Ledger::post(
                (int) $s->business_id,
                __('سداد مستحقّات مكوّنات — ورقة ').$s->number,
                array_values(array_filter([
                    ['account' => 'boutique_payable', 'debit' => (float) $s->components_due],
                    (float) $s->components_credit > 0 ? ['account' => 'boutique_receivable', 'credit' => (float) $s->components_credit] : null,
                    $net > 0 ? ['account' => Books::payingAccount($method), 'credit' => $net] : null,
                ])),
                $at, self::SOURCE, null, PosCashier::id() ?? auth()->id(), $s,
            );

            $s->update(['components_paid_at' => $at, 'components_method' => $method]);
        });
    }

    /**
     * سندُ استرداد: البوتيكُ ردّ نقدًا ما للمتجر عليه — مدين الصندوق / دائن 1370.
     *
     * يحجز كلَّ رصيدٍ للمتجر لم تقاصّه ورقة، ولا يمسّ الأرباح: الإلغاءُ
     * أنقصها يومَ وقع، وهذا مالٌ عاد لا إيرادٌ جديد.
     *
     * @throws SettlementRefused
     */
    public static function refund(Business $business, Boutique $boutique, string $method, ?Carbon $at = null, ?string $employee = null, ?float $amount = null): BoutiqueSettlement
    {
        return DB::transaction(function () use ($business, $boutique, $method, $at, $employee, $amount) {
            $at ??= now();
            // يُقفل ثمّ يُقرأ الباقي — كما تفعل الورقة
            BoutiqueComponentDue::where('business_id', $business->id)->where('boutique_id', $boutique->id)
                ->where('kind', BoutiqueComponentDue::CREDIT)->lockForUpdate()->get(['id']);
            $credits = self::credits((int) $business->id, (int) $boutique->id);
            $available = round(array_sum($credits), 3);

            $amount = $amount === null ? $available : round($amount, 3);
            if ($available <= 0 || $amount <= 0) {
                throw new SettlementRefused(__('لا رصيدَ للمتجر على هذا البوتيك يُستردّ.'));
            }
            if ($amount > $available + 0.0005) {
                throw new SettlementRefused(__('المستردُّ أكثرُ من رصيد المتجر على البوتيك (:left).', ['left' => $available]));
            }

            $plan = [];
            $left = $amount;
            foreach ($credits as $id => $have) {
                if ($left <= 0) {
                    break;
                }
                $plan[$id] = round(min($have, $left), 3);
                $left = round($left - $plan[$id], 3);
            }

            $period = $at->format('Y-m');
            $n = BoutiqueSettlement::where('business_id', $business->id)->where('period', $period)->count() + 1;
            $first = BoutiqueComponentDue::whereIn('id', array_keys($plan))->min('period_at');
            $last = BoutiqueComponentDue::whereIn('id', array_keys($plan))->max('period_at');

            $doc = BoutiqueSettlement::create([
                'business_id' => $business->id, 'boutique_id' => $boutique->id,
                'number' => 'BQR-'.$period.'-'.$n, 'kind' => BoutiqueSettlement::REFUND, 'period' => $period,
                'covered_from' => $first, 'covered_to' => $last,
                'gross' => 0, 'commission' => 0, 'net' => 0, 'lines_count' => 0,
                'components_due' => 0, 'components_credit' => $amount, 'components_net' => -$amount,
                'components_paid_at' => $at, 'components_method' => $method, 'issued_at' => now(),
            ]);

            self::offset((int) $doc->id, $plan);

            Ledger::post(
                (int) $business->id,
                __('استرداد من :name — سند ', ['name' => $boutique->name]).$doc->number,
                [
                    ['account' => Books::payingAccount($method), 'debit' => $amount],
                    ['account' => 'boutique_receivable', 'credit' => $amount],
                ],
                $at, self::SOURCE, null, PosCashier::id() ?? auth()->id(), $doc,
            );

            Activity::log('created', 'استرداد من بوتيك '.$boutique->name.' بمبلغ '.$amount.($employee ? ' — '.$employee : ''), [
                'business_id' => $business->id, 'subject_id' => $doc->id, 'subject_type' => 'boutique_settlement',
            ]);

            return $doc;
        });
    }

    /**
     * الرصيدُ بين المتجر وبوتيكٍ — من الصفوف، بما يطابق 2160 و1370.
     *
     *   - `owed_unsettled`: مستحقّاتُ مكوّناتٍ لم تحملها ورقة (كلُّ الأشهر).
     *   - `owed_issued`: جزءُ المكوّنات في ورقاتٍ صدرت ولم يُسدَّد.
     *     والاثنان معًا حصّةُ هذا البوتيك من 2160.
     *   - `standalone_unpaid`: صافي المنفرد في ورقاتٍ صدرت ومصروفُها لم يُسدَّد —
     *     يُقرأ من «المبالغ المستحقة» على طريقته، لا من 2160.
     *   - `credit`: ما للمتجر على البوتيك — حصّتُه من 1370.
     *   - `movements`: العكوسُ والقطعُ التي لم تعد والمقاصّاتُ والاستردادات، الأحدثُ أوّلًا.
     *
     * @return array<string, mixed>
     */
    public static function ledgerFor(int $businessId, int $boutiqueId): array
    {
        $rows = BoutiqueComponentDue::where('business_id', $businessId)->where('boutique_id', $boutiqueId);

        $unsettled = round((float) (clone $rows)->whereNull('boutique_settlement_id')
            ->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])->sum('amount'), 3);

        $issued = BoutiqueSettlement::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->where('kind', BoutiqueSettlement::SETTLEMENT)->with('expense:id,status')->get();
        $owedIssued = round((float) $issued->whereNull('components_paid_at')->sum(fn ($s) => (float) $s->components_due), 3);
        $standalone = round((float) $issued->filter(fn ($s) => (int) $s->lines_count > 0
            && ($s->expense === null || $s->expense->status !== Expense::PAID))->sum(fn ($s) => (float) $s->net), 3);

        $moves = (clone $rows)->where(fn ($q) => $q->whereIn('kind', [BoutiqueComponentDue::REVERSAL, BoutiqueComponentDue::CREDIT])
            ->orWhereNotNull('kept_state'))
            ->with('order:id,number')->latest('id')->limit(30)->get()
            ->map(fn (BoutiqueComponentDue $r) => [
                'at' => $r->occurred_at?->format('Y-m-d H:i'),
                'type' => $r->kept_state !== null && $r->kind === BoutiqueComponentDue::DUE ? 'kept' : $r->kind,
                'state' => $r->kept_state,
                'product' => $r->product_name, 'quantity' => (float) $r->quantity, 'amount' => (float) $r->amount,
                'order' => $r->order?->number, 'reason' => $r->reason ?? $r->kept_reason,
            ]);

        $offsets = BoutiqueCreditOffset::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->with('settlement:id,number,kind')->latest('id')->limit(30)->get()
            ->map(fn (BoutiqueCreditOffset $o) => [
                'at' => $o->created_at?->format('Y-m-d H:i'),
                'type' => $o->settlement?->kind === BoutiqueSettlement::REFUND ? 'refund' : 'offset',
                'state' => null, 'product' => null, 'quantity' => null,
                'amount' => (float) $o->amount, 'order' => $o->settlement?->number, 'reason' => null,
            ]);

        return [
            'owed_unsettled' => $unsettled,
            'owed_issued' => $owedIssued,
            'owed' => round($unsettled + $owedIssued, 3),
            'standalone_unpaid' => $standalone,
            'credit' => round(array_sum(self::credits($businessId, $boutiqueId)), 3),
            'movements' => $moves->concat($offsets)->sortByDesc('at')->values()->all(),
        ];
    }

    /* ═══════════ الداخل ═══════════ */

    private static function live()
    {
        return BoutiqueComponentDue::query()->whereNotNull('live_key');
    }

    /**
     * صفٌّ واحدٌ لكلّ استعمال: `live_key` فريدٌ في القاعدة، ونداءٌ ثانٍ لا يكتب ثانيًا.
     */
    private static function write(Order $order, int $itemId, ?int $addonRowId, array $d, int $times, string $key): void
    {
        if (BoutiqueComponentDue::where('live_key', $key)->exists()) {
            return;
        }

        BoutiqueComponentDue::create([
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'order_item_id' => $itemId,
            'order_item_addon_id' => $addonRowId,
            'boutique_id' => $d['boutique_id'],
            'boutique_name' => $d['boutique_name'],
            'source' => $d['source'],
            'recipe_item_id' => $d['recipe_item_id'] ?? null,
            'addon_id' => $d['addon_id'] ?? null,
            'product_id' => $d['product_id'],
            'product_name' => $d['product_name'],
            'unit_price' => $d['unit_price'],
            'unit_quantity' => $d['unit_quantity'],
            'quantity' => round($d['unit_quantity'] * $times, 3),
            'amount' => round($d['unit_amount'] * $times, 3),
            'kind' => BoutiqueComponentDue::DUE,
            'live_key' => $key,
            'user_id' => auth()->id(),
            'occurred_at' => $order->ordered_at ?? now(),
            'period_at' => $order->ordered_at ?? now(),
        ]);
    }

    private static function reverse($query, ?string $reason): void
    {
        foreach ($query->lockForUpdate()->orderBy('id')->get() as $row) {
            self::undo($row, $reason);
        }
    }

    private static function rewrite($query, int $times, ?string $reason): void
    {
        foreach ($query->lockForUpdate()->orderBy('id')->get() as $row) {
            $key = $row->live_key;
            self::undo($row, $reason);

            // بسعر البيعة ولقطتها — لا بسعر اليوم
            self::write($row->order, (int) $row->order_item_id, $row->order_item_addon_id, [
                'source' => $row->source, 'recipe_item_id' => $row->recipe_item_id, 'addon_id' => $row->addon_id,
                'product_id' => $row->product_id, 'product_name' => $row->product_name,
                'boutique_id' => $row->boutique_id, 'boutique_name' => $row->boutique_name,
                'unit_price' => (float) $row->unit_price, 'unit_quantity' => (float) $row->unit_quantity,
                'unit_amount' => round((float) $row->unit_price * (float) $row->unit_quantity, 3),
            ], $times, $key);
        }
    }

    /**
     * صفٌّ عكسيٌّ يشير إلى أصله — والأصلُ يبقى كما كُتب إلّا علامةَ «حيّ».
     *
     * ═══ وعكسُ ما سُوِّي رصيدٌ للمتجر لا التزامٌ سالب ═══
     *
     * أصلٌ لم تحمله ورقةٌ بعدُ يُعكس في الشهر نفسِه (`period_at` أصلِه)،
     * فيحملهما كشفٌ واحد ويتقاصّان. وأصلٌ حملته ورقةٌ (سُدِّدت أو لم
     * تُسدَّد) لا تُمسّ ورقتُه: العكسُ رصيدٌ للمتجر (`credit`) بيوم العكس،
     * يُنقل من 2160 إلى 1370 بقيدٍ مسمّى — فيبقى 2160 بما على الورقة
     * الصادرة، ويقرأ المحاسبُ في 1370 مالًا للمتجر عند البوتيك.
     *
     * وقيدُ «لم تعد» (`keep`) يُردّ قبل ذلك إن كان له.
     */
    private static function undo(BoutiqueComponentDue $row, ?string $reason): void
    {
        $row->update(['live_key' => null]);
        $settled = $row->boutique_settlement_id !== null;
        $user = PosCashier::id() ?? auth()->id();

        Books::liveEntriesFor($row)->each(fn ($e) => Ledger::reverse($e, null, $user, $reason));

        $back = BoutiqueComponentDue::create([
            'business_id' => $row->business_id,
            'order_id' => $row->order_id,
            'order_item_id' => $row->order_item_id,
            'order_item_addon_id' => $row->order_item_addon_id,
            'boutique_id' => $row->boutique_id,
            'boutique_name' => $row->boutique_name,
            'source' => $row->source,
            'recipe_item_id' => $row->recipe_item_id,
            'addon_id' => $row->addon_id,
            'product_id' => $row->product_id,
            'product_name' => $row->product_name,
            'unit_price' => $row->unit_price,
            'unit_quantity' => $row->unit_quantity,
            'quantity' => -(float) $row->quantity,
            'amount' => -(float) $row->amount,
            'reverses_id' => $row->id,
            'kind' => $settled ? BoutiqueComponentDue::CREDIT : BoutiqueComponentDue::REVERSAL,
            'reason' => $reason,
            'user_id' => auth()->id(),
            'occurred_at' => now(),
            'period_at' => $settled ? now() : $row->period_at,
        ]);

        if ($settled && (float) $row->amount > 0) {
            Ledger::post(
                (int) $row->business_id,
                __('رصيد للمتجر على :name — :product', ['name' => $row->boutique_name, 'product' => $row->product_name]),
                [
                    ['account' => 'boutique_receivable', 'debit' => (float) $row->amount],
                    ['account' => 'boutique_payable', 'credit' => (float) $row->amount],
                ],
                now(), self::SOURCE, $row->order?->branch_id, $user, $back,
            );
        }
    }

    /**
     * المستحقُّ باقٍ والقطعةُ لم تعد (أو لم يُتحقَّق منها) — وثمنُها خسارةٌ على المتجر.
     *
     * `$offShelf`: الإلغاءُ ردّها إلى الرفّ فتُرفع عنه ثانيةً. وصفٌّ يُعاد
     * كتابتُه بعد إرجاعٍ جزئيّ قطعُه غائبةٌ أصلًا فلا تُرفع مرّتين.
     */
    private static function keep(Order $order, BoutiqueComponentDue $row, string $state, bool $offShelf): void
    {
        $row->update(['kept_state' => $state, 'kept_reason' => self::why($state)]);

        if ($offShelf) {
            StockLedger::move((int) $order->business_id, $order->branch_id,
                [(int) $row->product_id => -(int) round((float) $row->quantity)], StockLedger::CORRECTION,
                PosCashier::name() ?? auth()->user()?->name, $order->number);
        }

        if ((float) $row->amount > 0) {
            Ledger::post(
                (int) $order->business_id,
                __('قطعة بوتيك لم تعد بعد الإلغاء — فاتورة ').$order->number,
                [
                    ['account' => 'other_expenses', 'debit' => (float) $row->amount, 'memo' => $row->product_name],
                    ['account' => 'boutique_payable', 'credit' => (float) $row->amount],
                ],
                now(), self::SOURCE, $order->branch_id, PosCashier::id() ?? auth()->id(), $row,
            );
        }
    }

    private static function why(string $state): string
    {
        return $state === self::LOST
            ? __('أُلغي الطلب ولم تعد القطعة إلى الرفّ')
            : __('أُلغي الطلب ولم يُؤكَّد رجوعُ القطعة إلى الرفّ');
    }

    /**
     * جزءٌ من صفٍّ عُكس يبقى للبوتيك — صفٌّ جديد بلقطة أصله وكمّيّة الجزء.
     *
     * وشهرُه شهرُ أصله ما لم تحمل الأصلَ ورقة؛ فإن حملته فيومُ كتابته —
     * يدخل ورقةً بعدها ويقابله رصيدُ المتجر الذي أنشأه العكس.
     */
    private static function part(BoutiqueComponentDue $from, string $liveKey, int $pieces, string $state): BoutiqueComponentDue
    {
        // مفتاحُ الأصل بلا لاحقة حالٍ سابقة، ثمّ الحالُ ورقمُ الصفّ الذي انقسم — فريدٌ دائمًا
        $key = preg_replace('/:(lost|unverified)\d+$/', '', $liveKey).':'.$state.$from->id;

        return BoutiqueComponentDue::create([
            'business_id' => $from->business_id,
            'order_id' => $from->order_id,
            'order_item_id' => $from->order_item_id,
            'order_item_addon_id' => $from->order_item_addon_id,
            'boutique_id' => $from->boutique_id,
            'boutique_name' => $from->boutique_name,
            'source' => $from->source,
            'recipe_item_id' => $from->recipe_item_id,
            'addon_id' => $from->addon_id,
            'product_id' => $from->product_id,
            'product_name' => $from->product_name,
            'unit_price' => $from->unit_price,
            'unit_quantity' => $from->unit_quantity,
            'quantity' => $pieces,
            'amount' => round((float) $from->unit_price * $pieces, 3),
            'kind' => BoutiqueComponentDue::DUE,
            'live_key' => $key,
            'user_id' => auth()->id(),
            'occurred_at' => now(),
            'period_at' => $from->boutique_settlement_id !== null ? now() : $from->period_at,
        ]);
    }

    /** لماذا لا يُحسب لهذا الصنف مستحقٌّ بدقّة — أو `null` */
    private static function refusal(Product $p, float $unitQuantity, float $wastage, ?Product $parent, bool $taxed): ?string
    {
        return match (true) {
            $taxed => __('«:name» صنفُ بوتيك، ومستحقُّ البوتيك لا يُحسب بعد في متجرٍ يحتسب الضريبة.', ['name' => $p->name]),
            $parent?->boutique_id !== null => __('«:parent» صنفُ بوتيك، فلا يحمل صنفَ بوتيكٍ آخر («:name»).', ['parent' => $parent->name, 'name' => $p->name]),
            (int) $p->variants_count > 0 => __('«:name» صنفُ بوتيكٍ له مقاسات — لا يدخل باقةً ولا إضافةً حتى يُحفظ سعرُ مقاسه.', ['name' => $p->name]),
            $wastage > 0 => __('«:name» صنفُ بوتيك بنسبة فاقد — اجعل فاقدَه صفرًا في الوصفة.', ['name' => $p->name]),
            $unitQuantity <= 0 || abs($unitQuantity - round($unitQuantity)) > 0.0001 => __('«:name» صنفُ بوتيك بكمّيّةٍ كسريّة — يُؤخذ قطعًا كاملة.', ['name' => $p->name]),
            (float) $p->price <= 0 => __('«:name» صنفُ بوتيك بلا سعر — لا يُعرف مستحقُّه.', ['name' => $p->name]),
            default => null,
        };
    }

    private static function due(string $source, Product $p, float $unitQuantity, ?int $recipeItemId = null, ?int $addonId = null, ?array $pin = null): array
    {
        $price = round((float) ($pin['price'] ?? $p->price), 3);
        $boutiqueId = (int) ($pin['boutique_id'] ?? $p->boutique_id);
        $boutiqueName = $boutiqueId === (int) $p->boutique_id
            ? $p->boutique?->name
            : Boutique::whereKey($boutiqueId)->value('name');

        return [
            'source' => $source,
            'recipe_item_id' => $recipeItemId,
            'addon_id' => $addonId,
            'product_id' => (int) $p->id,
            'product_name' => (string) $p->name,
            'boutique_id' => $boutiqueId,
            'boutique_name' => $boutiqueName,
            'unit_price' => $price,
            'unit_quantity' => $unitQuantity,
            'unit_amount' => round($price * $unitQuantity, 3),
        ];
    }

    /** أصنافُ البوتيك بين مكوّنات هذه البنود وإضافاتها — بمقاساتها وبوتيكها */
    private static function owned(int $businessId, array $lines, array $pinnedIds = []): Collection
    {
        $ids = [];

        foreach ($lines as $l) {
            if (($l['has_recipe'] ?? false) && $l['product']) {
                foreach (Recipe::forLine($l['product'], $l['variant'] ?? null, $l['recipe']) as $r) {
                    $ids[] = (int) $r->component_product_id;
                }
            }
            foreach ($l['addons'] ?? [] as $a) {
                $ids[] = (int) ($a['inventory_product_id'] ?? 0);
            }
            if ($s = $l['standalone_addon'] ?? null) {
                $ids[] = (int) $s->inventory_product_id;
            }
            foreach ($l['components'] ?? [] as $c) {
                $ids[] = (int) ($c['product_id'] ?? 0);
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return collect();
        }

        // وما ثُبِّت قبل البنك يُحسب لصاحبه يومئذٍ ولو تغيّر صاحبُه بعدها
        $pinnedIds = array_map('intval', $pinnedIds);

        return Product::where('business_id', $businessId)->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNotNull('boutique_id')->orWhereIn('id', $pinnedIds ?: [0]))
            ->with('boutique:id,name')->withCount('variants')
            ->get()->keyBy('id');
    }

    /** تكاليفُ بطاقات مكوّنات الوصفات — لما يملكه المتجر منها */
    private static function cards(int $businessId, array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) {
            if (($l['has_recipe'] ?? false) && $l['product']) {
                foreach (Recipe::forLine($l['product'], $l['variant'] ?? null, $l['recipe']) as $r) {
                    $ids[] = (int) $r->component_product_id;
                }
            }
        }

        return $ids
            ? Product::where('business_id', $businessId)->whereIn('id', array_unique($ids))
                ->pluck('cost', 'id')->map(fn ($c) => (float) $c)->all()
            : [];
    }
}
