<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يعيد التوازن بين إجمالي الصنف ومجموع أرصدة فروعه.
 *
 * القاعدة التي يقوم عليها المخزون كلّه: `products.quantity` يساوي مجموع
 * `branch_stocks` لذلك الصنف. ونقطةُ البيع تبيع من رصيد الفرع وحده —
 * فما زاد في الإجمالي ولم يُنسب إلى فرعٍ **لا يُباع ولا يُرى**، وهو موجودٌ
 * على الرفّ.
 *
 * وقد وقع ذلك فعلًا: «إضافة كمية» يدويّة كانت تُقبل بلا فرع، فترفع
 * الإجمالي ولا تكتب صفَّ فرع. البابُ أُغلق (`branch_id` صار مطلوبًا في
 * `InventoryController::store`)، وبقيت الصفوف التي كُتبت قبله.
 *
 * وهذا الأمر يعالج الأثر لا السبب — ويعالجه **بأثرٍ مكتوب**: كلّ نقلةٍ
 * تُقيَّد حركةَ مخزونٍ باسم «تصحيح توازن» فتُقرأ في سجلّ الصنف، ويستطيع
 * التاجر نقلها إلى فرعها الصحيح من شاشة التحويل إن أخطأنا الفرع.
 *
 * والفرع المختار أوّلُ فروع النشاط: الحركة الأصليّة لم تحمل فرعًا، فلا
 * سبيل إلى معرفته — وهي القاعدة نفسها التي تطبّقها `BranchStock::books`
 * حين لا يكون للصنف صفُّ فرعٍ أصلًا.
 */
class RepairBranchBalance extends Command
{
    protected $signature = 'inventory:repair-balance
                            {--business= : نشاطٌ بعينه بدل الجميع}
                            {--dry-run : اعرض ولا تكتب}';

    protected $description = 'ينسب الفرقَ بين إجمالي الصنف ومجموع فروعه إلى الفرع الأول';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('business');

        $sums = DB::table('branch_stocks')->selectRaw('product_id, sum(quantity) as total')
            ->groupBy('product_id')->pluck('total', 'product_id');

        $firstBranch = Branch::orderBy('id')->get()->groupBy('business_id')
            ->map(fn ($rows) => (int) $rows->first()->id);

        $repaired = 0;
        $skipped = 0;

        foreach (Product::query()->when($only, fn ($q) => $q->where('business_id', $only))->get() as $product) {
            // صنفٌ بلا صفوف فروعٍ إطلاقًا ليس مختلًّا: رصيدُه كلّه في الفرع
            // الأول بحكم `BranchStock::books`، ولا شيء ضائع
            if (! isset($sums[$product->id])) {
                continue;
            }

            $gap = (int) $product->quantity - (int) $sums[$product->id];
            if ($gap === 0) {
                continue;
            }

            $branchId = $firstBranch[$product->business_id] ?? null;
            if (! $branchId) {
                $this->warn("  {$product->name}: لا فرع لهذا النشاط — تُرك كما هو");
                $skipped++;

                continue;
            }

            /*
             * والنقص لا يُطرح من فرعٍ لا يملكه.
             *
             * فرقٌ سالب يعني أنّ مجموع الفروع أكبر من الإجمالي — وطرحُه من
             * الفرع الأول قد يجعل رصيده سالبًا، وهو عطبٌ ثانٍ مكان الأول.
             * فيُبلَّغ عنه ولا يُلمس.
             */
            $book = (int) BranchStock::where('branch_id', $branchId)
                ->where('product_id', $product->id)->value('quantity');

            if ($gap < 0 && $book + $gap < 0) {
                $this->warn("  {$product->name}: فرقٌ سالب ({$gap}) أكبر ممّا في الفرع — يحتاج نظرًا بيد");
                $skipped++;

                continue;
            }

            $this->line(sprintf('  %-28s %+d → فرع %d', $product->name, $gap, $branchId));
            $repaired++;

            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($product, $branchId, $gap) {
                BranchStock::ensureAllocated((int) $product->business_id, $product->id, (int) $product->quantity);
                BranchStock::adjust((int) $product->business_id, $branchId, $product->id, $gap);

                // الإجماليّ لا يتحرّك: هو الصحيح، والفرعُ هو ما كان ناقصًا
                InventoryMovement::create([
                    'business_id' => $product->business_id,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'type' => 'تصحيح توازن',
                    'quantity' => ($gap >= 0 ? '+' : '').$gap,
                    'employee_name' => 'النظام',
                    'note' => __('كميّةٌ أُضيفت بلا فرع فلم تكن تُباع — نُسبت إلى الفرع الأول'),
                ]);
            });
        }

        $this->newLine();
        $this->info($dry
            ? "عرضٌ فقط — {$repaired} صنفًا سيُصحَّح، و{$skipped} يحتاج نظرًا بيد."
            : "صُحِّح {$repaired} صنفًا، و{$skipped} يحتاج نظرًا بيد.");

        return self::SUCCESS;
    }
}
