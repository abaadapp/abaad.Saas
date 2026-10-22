<?php

namespace App\Support;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
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

    /**
     * الطلبُ أُلغي — والسؤالُ: هل تعود قطعةُ البوتيك إلى الرفّ فعلًا؟
     *
     * `$returned = true`: تعود — يُعكس مستحقُّها، ويبقى ما ردّه الإلغاءُ إلى الرفّ.
     *
     * وإلّا (سُلّمت، أو استُهلكت، أو تلفت، أو لم يُقل): **لا يُعكس تلقائيًّا**.
     * يبقى المستحقُّ للبوتيك ويُقال لماذا (`kept_reason`)، وتُرفع القطعةُ عن
     * الرفّ ثانيةً فلا يُعدّ ما ليس عليه، ويُقيَّد ثمنُها خسارةً على المتجر
     * كخسارة أيّ مخزونٍ لم يعد (مدين «مصروفات أخرى» / دائن 2160 — كما
     * `StockLosses`). فإن عادت بعدُ فـ`returned` يعكس ذلك كلَّه بقيدٍ موثَّق.
     */
    public static function cancel(Order $order, ?string $reason = null, ?bool $returned = null): void
    {
        $rows = self::live()->where('order_id', $order->id);

        if ($returned === true) {
            self::reverse($rows, $reason);

            return;
        }

        $why = $returned === false
            ? __('أُلغي الطلب ولم تعد القطعة إلى الرفّ')
            : __('أُلغي الطلب ولم يُؤكَّد رجوعُ القطعة إلى الرفّ');

        foreach ($rows->lockForUpdate()->orderBy('id')->get() as $row) {
            self::keep($order, $row, $why);
        }
    }

    /**
     * القطعةُ التي بقي مستحقُّها عند الإلغاء عادت إلى الرفّ — يُعكس الآن.
     *
     * يُردّ قيدُ الخسارة، وتعود القطعةُ إلى الرفّ، ويُعكس المستحقّ. فإن كانت
     * ورقتُه قد صدرت صار العكسُ رصيدًا للمتجر على البوتيك (انظر `undo`).
     */
    public static function returned(Order $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            $rows = self::live()->where('order_id', $order->id)->whereNotNull('kept_reason')
                ->lockForUpdate()->orderBy('id')->get();

            $back = [];
            foreach ($rows as $row) {
                $back[(int) $row->product_id] = ($back[(int) $row->product_id] ?? 0) + (int) round((float) $row->quantity);
                self::undo($row, $reason);
            }

            StockLedger::move((int) $order->business_id, $order->branch_id, $back, StockLedger::CORRECTION,
                PosCashier::name() ?? auth()->user()?->name, $order->number);
        });
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
     *   - `credit`: ما يُقاصّ من رصيد المتجر على البوتيك — صفوفًا كاملة،
     *     الأقدمُ أوّلًا، ما دام لا يجاوز `due`. وما زاد يبقى لورقةٍ بعدها
     *     أو لسند استرداد (`refund`)؛ فالصافي لا يكون سالبًا أبدًا.
     *
     * @return array{lines: list<array<string, mixed>>, due: float, credit: float, credit_available: float, net: float, count: int, from_at: ?string, to_at: ?string, ids: list<int>, credit_ids: list<int>}
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

        $credits = BoutiqueComponentDue::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->whereNull('boutique_settlement_id')
            ->where('kind', BoutiqueComponentDue::CREDIT)
            ->where('period_at', '<=', $to)
            ->orderBy('period_at')->orderBy('id')->get();

        $due = round((float) $rows->sum('amount'), 3);

        $credit = 0.0;
        $creditIds = [];
        foreach ($credits as $c) {
            $take = -(float) $c->amount;
            if ($credit + $take <= $due + 0.0005) {
                $credit = round($credit + $take, 3);
                $creditIds[] = (int) $c->id;
            }
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
            'credit_available' => round(-(float) $credits->sum('amount'), 3),
            'net' => round($due - $credit, 3),
            'count' => $rows->count(),
            'from_at' => $rows->first()?->period_at?->toDateTimeString(),
            'to_at' => $rows->last()?->period_at?->toDateTimeString(),
            'ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'credit_ids' => $creditIds,
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
        $ids = array_merge($part['ids'], $part['credit_ids']);
        if (! $ids) {
            return;
        }

        $claimed = BoutiqueComponentDue::whereIn('id', $ids)->whereNull('boutique_settlement_id')
            ->update(['boutique_settlement_id' => $settlementId]);

        if ($claimed !== count($ids)) {
            throw new SettlementRefused(__('صدرت ورقةٌ لهذه البيعات قبل لحظة — افتح الشاشة من جديد.'));
        }
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
    public static function refund(Business $business, Boutique $boutique, string $method, ?Carbon $at = null, ?string $employee = null): BoutiqueSettlement
    {
        return DB::transaction(function () use ($business, $boutique, $method, $at, $employee) {
            $at ??= now();
            $credits = BoutiqueComponentDue::where('business_id', $business->id)->where('boutique_id', $boutique->id)
                ->whereNull('boutique_settlement_id')->where('kind', BoutiqueComponentDue::CREDIT)
                ->lockForUpdate()->orderBy('id')->get();

            $amount = round(-(float) $credits->sum('amount'), 3);
            if ($amount <= 0) {
                throw new SettlementRefused(__('لا رصيدَ للمتجر على هذا البوتيك يُستردّ.'));
            }

            $period = $at->format('Y-m');
            $n = BoutiqueSettlement::where('business_id', $business->id)->where('period', $period)->count() + 1;

            $doc = BoutiqueSettlement::create([
                'business_id' => $business->id, 'boutique_id' => $boutique->id,
                'number' => 'BQR-'.$period.'-'.$n, 'kind' => BoutiqueSettlement::REFUND, 'period' => $period,
                'covered_from' => $credits->first()->period_at, 'covered_to' => $credits->last()->period_at,
                'gross' => 0, 'commission' => 0, 'net' => 0, 'lines_count' => 0,
                'components_due' => 0, 'components_credit' => $amount, 'components_net' => -$amount,
                'components_paid_at' => $at, 'components_method' => $method, 'issued_at' => now(),
            ]);

            self::claim((int) $doc->id, ['ids' => [], 'credit_ids' => $credits->pluck('id')->map(fn ($id) => (int) $id)->all()]);

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

    /** المستحقُّ باقٍ والقطعةُ لم تعد — تُرفع عن الرفّ، وثمنُها خسارةٌ على المتجر */
    private static function keep(Order $order, BoutiqueComponentDue $row, string $why): void
    {
        $row->update(['kept_reason' => $why]);

        StockLedger::move((int) $order->business_id, $order->branch_id,
            [(int) $row->product_id => -(int) round((float) $row->quantity)], StockLedger::CORRECTION,
            PosCashier::name() ?? auth()->user()?->name, $order->number);

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
