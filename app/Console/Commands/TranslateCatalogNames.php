<?php

namespace App\Console\Commands;

use App\Support\Lexicon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * يملأ الأسماء الإنجليزية لما كُتب قبل وجود المعجم.
 *
 * الكتالوج القائم كُتب كلّه بالعربية و`name_en` فيه فارغ، فالشاشة
 * الإنجليزية تعرضه عربيًّا. والحفظُ من الآن يملؤه — لكنّ ذلك لا يمسّ
 * صنفًا لا يُعاد حفظه، وأكثرُ الأصناف كذلك.
 *
 * ويعمل بحذر:
 *   - لا يمسّ اسمًا إنجليزيًّا مكتوبًا — ما كتبه التاجر أَولى من المعجم.
 *   - لا يمسّ ما لا يعرفه المعجم — يبقى عربيًّا كما كُتب.
 *   - `--dry-run` يعرض ما سيفعل ولا يكتب حرفًا.
 */
class TranslateCatalogNames extends Command
{
    protected $signature = 'catalog:translate-names
                            {--business= : نشاطٌ بعينه بدل الجميع}
                            {--dry-run : اعرض ولا تكتب}';

    protected $description = 'يملأ name_en من معجم الكتالوج لما تُرك فارغًا';

    /** @var array<string, class-string<Model>> */
    private const TABLES = [
        'المنتجات' => \App\Models\Product::class,
        'الأقسام' => \App\Models\Category::class,
        'الإضافات' => \App\Models\Addon::class,
        'المقاسات' => \App\Models\ProductVariant::class,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $business = $this->option('business');
        $total = 0;
        $skipped = 0;

        foreach (self::TABLES as $label => $class) {
            $rows = $class::query()
                ->when($business, fn ($q) => $q->where('business_id', $business))
                ->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''))
                ->get(['id', 'name', 'name_en']);

            $filled = 0;

            foreach ($rows as $row) {
                $english = Lexicon::translate($row->name);

                if ($english === null) {
                    $skipped++;

                    continue;
                }

                $this->line(sprintf('  %-28s → %s', $row->name, $english));
                $filled++;

                if (! $dry) {
                    $row->forceFill(['name_en' => $english])->save();
                }
            }

            $this->info(sprintf('%s: %d من %d', $label, $filled, $rows->count()));
            $total += $filled;
        }

        $this->newLine();
        $this->info($dry
            ? "عرضٌ فقط — {$total} اسمًا سيُملأ، و{$skipped} يبقى كما كُتب."
            : "مُلئ {$total} اسمًا، وبقي {$skipped} كما كُتب.");

        return self::SUCCESS;
    }
}
