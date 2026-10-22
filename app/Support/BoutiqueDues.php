<?php

namespace App\Support;

use App\Models\BoutiqueComponentDue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
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
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function price(int $businessId, array $lines, ?string $channel = null): array
    {
        if (! $lines || ! self::active($businessId)) {
            return $lines;
        }

        $owned = self::owned($businessId, $lines);
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
                    $dues[] = self::due(BoutiqueComponentDue::RECIPE, $p, (float) $r->quantity, recipeItemId: (int) $r->id);
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
                    $due = self::due(BoutiqueComponentDue::ADDON, $p, $each, addonId: (int) $standalone->id);
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

                $due = self::due(BoutiqueComponentDue::ADDON, $p, $each, addonId: (int) $a['addon']->id);
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

    /** الطلبُ أُلغي: يُعكس كلُّ ما لم يُعكس */
    public static function cancel(Order $order, ?string $reason = null): void
    {
        self::reverse(self::live()->where('order_id', $order->id), $reason);
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
            'live_key' => $key,
            'user_id' => auth()->id(),
            'occurred_at' => $order->ordered_at ?? now(),
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

    /** صفٌّ عكسيٌّ يشير إلى أصله — والأصلُ يبقى كما كُتب إلّا علامةَ «حيّ» */
    private static function undo(BoutiqueComponentDue $row, ?string $reason): void
    {
        $row->update(['live_key' => null]);

        BoutiqueComponentDue::create([
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
            'reason' => $reason,
            'user_id' => auth()->id(),
            'occurred_at' => now(),
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

    private static function due(string $source, Product $p, float $unitQuantity, ?int $recipeItemId = null, ?int $addonId = null): array
    {
        $price = round((float) $p->price, 3);

        return [
            'source' => $source,
            'recipe_item_id' => $recipeItemId,
            'addon_id' => $addonId,
            'product_id' => (int) $p->id,
            'product_name' => (string) $p->name,
            'boutique_id' => (int) $p->boutique_id,
            'boutique_name' => $p->boutique?->name,
            'unit_price' => $price,
            'unit_quantity' => $unitQuantity,
            'unit_amount' => round($price * $unitQuantity, 3),
        ];
    }

    /** أصنافُ البوتيك بين مكوّنات هذه البنود وإضافاتها — بمقاساتها وبوتيكها */
    private static function owned(int $businessId, array $lines): Collection
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

        return Product::where('business_id', $businessId)->whereIn('id', $ids)
            ->whereNotNull('boutique_id')
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
