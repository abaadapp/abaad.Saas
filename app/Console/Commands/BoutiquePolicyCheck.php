<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\BoutiquePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * قبل أن تسري سياسةُ البوتيك على متجر — قراءةٌ وحدها: لا يكتب يومَها ولا سعرًا ولا شيئًا.
 *
 * لكلّ متجرٍ فيه بوتيكات: يومُ السياسة إن كُتب، وأصنافُ البوتيك التي لا سعرَ متّفقًا عليه لها
 * اليوم (تُحسب بالقاعدة القائمة حتّى يُكتب)، وما بيع بها وما ينتظر تحصيله.
 */
class BoutiquePolicyCheck extends Command
{
    protected $signature = 'boutiques:policy {--business= : متجرٌ بعينه} {--json}';

    protected $description = 'Read-only: the boutique policy day, products without an agreed price, and what waits for collection';

    public function handle(): int
    {
        $shops = DB::table('businesses')->where('boutiques_enabled', true)
            ->when($this->option('business'), fn ($q, $id) => $q->where('id', (int) $id))->orderBy('id')->get(['id', 'name', 'boutique_policy_from']);
        $out = [];

        foreach ($shops as $shop) {
            $missing = Product::where('business_id', $shop->id)->whereNotNull('boutique_id')->orderBy('id')->get()
                ->filter(fn (Product $p) => $shop->boutique_policy_from === null || BoutiquePolicy::agreed((int) $shop->id, $p, null) === null)
                ->map(fn (Product $p) => ['id' => (int) $p->id, 'name' => (string) $p->name])->values()->all();

            $out[] = [
                'business' => (int) $shop->id,
                'name' => (string) $shop->name,
                'policy_from' => $shop->boutique_policy_from,
                'without_agreed_price' => $missing,
                'sold_by_policy' => (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.business_id', $shop->id)->whereNotNull('order_items.boutique_policy')->count(),
                'waiting_lines' => (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.business_id', $shop->id)->whereNotNull('order_items.boutique_policy')->whereNull('order_items.boutique_settlement_id')
                    ->whereRaw('NOT '.BoutiquePolicy::orderCollectedSql())->count(),
                // وقطعُ الباقات: باقةٌ للمتجر قطعُها لبوتيك
                'waiting_pieces' => (int) DB::table('boutique_component_dues')->join('orders', 'orders.id', '=', 'boutique_component_dues.order_id')
                    ->where('orders.business_id', $shop->id)->where('boutique_component_dues.waits', true)->whereNull('boutique_component_dues.boutique_settlement_id')
                    ->whereRaw('NOT '.BoutiquePolicy::orderCollectedSql())->count(),
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($out as $s) {
            $this->line("#{$s['business']} {$s['name']}: ".($s['policy_from'] ? 'السياسة من '.$s['policy_from'] : 'السياسة مطفأة')
                .' · بلا سعر متفق عليه: '.count($s['without_agreed_price']).' · بيع بها: '.$s['sold_by_policy'].' · ينتظر التحصيل: '.$s['waiting_lines'].' بندًا و'.$s['waiting_pieces'].' قطعة');
        }

        return self::SUCCESS;
    }
}
