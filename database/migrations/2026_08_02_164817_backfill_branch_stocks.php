<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * يُصلح التوازن: مجموع أرصدة الفروع = كمية المنتج.
 *
 * نقطة البيع صارت تحكم على رصيد الفرع بدل مجموع الشركة. وقبل هذه الهجرة
 * كان الجدولان يفترقان لسببين:
 *
 *   1. `BranchStock::adjust` كانت تقصّ الناتج بـmax(0, …)، فكل خصم ينزل
 *      تحت الصفر كان يضيع بصمت ويبقى الرصيد عند صفر.
 *   2. منتجات أُنشئت قبل وجود الفروع لا صفّ لها إطلاقًا.
 *
 * لولا هذا لفتح التاجر شاشة البيع بعد التحديث فوجد أصنافًا «نفد المخزون»
 * وهي في المستودع. الفارق يُنقل إلى الفرع الافتراضي (أوّل فرع للنشاط) —
 * أقرب تقدير ممكن، ويبقى الجرد الفعلي هو ما يصحّح التوزيع بين الفروع.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultBranch = DB::table('branches')
            ->select('business_id', DB::raw('MIN(id) as id'))
            ->groupBy('business_id')->pluck('id', 'business_id');

        $allocated = DB::table('branch_stocks')
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')->pluck('total', 'product_id');

        $now = now();
        $rows = [];

        foreach (DB::table('products')->select('id', 'business_id', 'quantity')->cursor() as $product) {
            $branchId = $defaultBranch[$product->business_id] ?? null;
            if (! $branchId) {
                continue; // نشاط بلا فروع — الكمية الإجمالية هي المتاحة أصلًا
            }

            $drift = (int) $product->quantity - (int) ($allocated[$product->id] ?? 0);
            if ($drift === 0) {
                continue;
            }

            $existing = DB::table('branch_stocks')
                ->where('branch_id', $branchId)->where('product_id', $product->id)->first();

            if ($existing) {
                DB::table('branch_stocks')->where('id', $existing->id)
                    ->update(['quantity' => (int) $existing->quantity + $drift, 'updated_at' => $now]);

                continue;
            }

            $rows[] = [
                'business_id' => $product->business_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'quantity' => $drift,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('branch_stocks')->insert($chunk);
        }
    }

    public function down(): void
    {
        // لا تراجع: الهجرة تُصلح تباعدًا لا تُنشئ بنية، وعكسُها يعيد الخلل.
    }
};
