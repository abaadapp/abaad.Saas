<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockAdjustment;
use App\Support\Ledger;
use App\Support\StockLosses;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * يستدرك فاقدَ الجرد الذي كُتب مصروفًا ولم يصل دفترَ الأستاذ.
 *
 * ═══ العطبُ الذي خلّف هذه الصفوف ═══
 *
 * كانت تسويةُ الجرد تكتب صفَّ مصروفٍ **ولا تُرحّل شيئًا**. فيبقى المخزون في
 * الميزانية بقيمة بضاعةٍ ليست في الرفّ، ويقرأ المتجرُ نفسَه أغنى ممّا هو.
 * وأُصلح البابُ في `StockLosses::record` — لكنّ ما كُتب قبله بقي نصفَ قيدٍ.
 *
 * وقياسُه على الإنتاج يوم كُتب هذا الأمر: أحدَ عشرَ صفًّا في «متجري» بـ
 * ٢٩٧٫٠١٨ ر.ع، وصفّان في المتجر التجريبيّ بـ ٢٦٨٫٨٠٨ — وصفرٌ في الميزان
 * يقابلها.
 *
 * ═══ ولا يُضاعِف ═══
 *
 * كلُّ قيدٍ يُكتب مشيرًا إلى صفّ المصروف نفسِه (`sourceable`). فمن رُحّل مرّةً
 * لا يُرحَّل ثانية — ولو نُودي الأمرُ كلَّ ليلة. وإصلاحُ الماضي يُخطئ مرّةً
 * واحدةً فيصير الخطأُ ضعفَ الأوّل.
 */
class PostMissingStockLosses extends Command
{
    protected $signature = 'finance:post-missing-stock-losses
        {--business= : متجرٌ بعينه}
        {--dry-run : عدٌّ بلا كتابة}';

    protected $description = 'ترحيل فاقد الجرد الذي كُتب مصروفًا ولم يصل دفتر الأستاذ';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $posted = 0;
        $value = 0.0;

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            $missing = Expense::where('business_id', $business->id)
                ->where('type', StockAdjustment::STOCKTAKE_LOSS)
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('journal_entries')
                        ->whereColumn('journal_entries.sourceable_id', 'expenses.id')
                        ->where('journal_entries.sourceable_type', Expense::class);
                })
                ->orderBy('id')->get();

            if ($missing->isEmpty()) {
                continue;
            }

            $sum = round((float) $missing->sum('amount'), 3);

            $this->line('  '.$business->name.' — '.$missing->count().' صفًّا بـ'.number_format($sum, 3).' ر.ع');

            if ($dry) {
                $posted += $missing->count();
                $value += $sum;

                continue;
            }

            foreach ($missing as $expense) {
                $amount = round((float) $expense->amount, 3);

                // صفرٌ لا يُقيَّد — قيدٌ بلا مبلغٍ يملأ الدفترَ ولا يقول شيئًا
                if ($amount <= 0) {
                    continue;
                }

                Ledger::post(
                    $business->id,
                    (string) $expense->description,
                    [
                        ['account' => 'other_expenses', 'debit' => $amount],
                        ['account' => 'inventory', 'credit' => $amount],
                    ],
                    Carbon::parse($expense->spent_at),
                    StockLosses::SOURCE,
                    null,
                    null,
                    $expense,
                );

                $posted++;
                $value += $amount;
            }
        }

        if ($posted === 0) {
            $this->info('لا فاقدَ ينتظر ترحيلًا.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(($dry ? 'عدٌّ بلا كتابة — ' : '').'رُحّل '.$posted.' صفًّا بـ'.number_format($value, 3).' ر.ع');

        /*
         * والميزانُ يُقرأ بعد الكتابة لا يُفترض.
         *
         * ويُجمع في PHP لا في SQL: `::numeric` لهجةٌ لبوستجرس وحدها، وأمرٌ
         * يُنادى في اختبارٍ على SQLite يسقط بها. والجمعُ هنا على صفوفٍ
         * معدودةٍ لا يكلّف شيئًا.
         */
        foreach ($businesses as $business) {
            $ids = JournalEntry::where('business_id', $business->id)->pluck('id');

            $d = round((float) JournalLine::whereIn('journal_entry_id', $ids)->sum('debit'), 3);
            $c = round((float) JournalLine::whereIn('journal_entry_id', $ids)->sum('credit'), 3);

            $this->line('  '.$business->name.' — مدين '.number_format($d, 3).' / دائن '.number_format($c, 3)
                .(abs($d - $c) < 0.005 ? ' ✓' : ' ✗ فرق '.number_format($d - $c, 3)));
        }

        return self::SUCCESS;
    }
}
