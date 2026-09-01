<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Support\Books;
use Illuminate\Console\Command;

/**
 * يستدرك قيود المبيعات التي لم تصل إلى دفتر الأستاذ.
 *
 * البيع صار يُرحَّل مع وقوعه — انظر `Books::recordSale`. لكنّ ما بيع قبل
 * ذلك لا قيدَ له، وشجرةُ حسابات المتجر تعرض «إيراد المبيعات» صفرًا وقد باع
 * صاحبُها. وإصلاحُ المستقبل وحده يترك الماضي كاذبًا.
 *
 * ويستدرك كذلك ما أخفق ترحيله لحظةَ البيع: الترحيل لا يُسقط بيعةً وقعت،
 * فإخفاقُه يُقيَّد في السجلّ ويُعالَج هنا.
 *
 * ولا يُضاعف: `Books::recordSale` تفحص وجود القيد قبل أن تكتب.
 */
class PostMissingSales extends Command
{
    protected $signature = 'finance:post-missing-sales {--business= : متجرٌ بعينه} {--dry-run : عدٌّ بلا كتابة}';

    protected $description = 'ترحيل قيود المبيعات الغائبة عن دفتر الأستاذ';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        $totalPosted = 0;

        foreach ($businesses as $business) {
            $orders = Order::where('business_id', $business->id)
                ->where('is_held', false)
                ->where('status', '!=', \App\Support\OrderStatus::CANCELLED)
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('journal_entries')
                        ->whereColumn('journal_entries.sourceable_id', 'orders.id')
                        ->where('journal_entries.sourceable_type', Order::class);
                })
                ->orderBy('id')->get();

            if ($orders->isEmpty()) {
                continue;
            }

            $this->line("{$business->name}: {$orders->count()} فاتورة بلا قيد");

            if ($dry) {
                continue;
            }

            $bar = $this->output->createProgressBar($orders->count());

            foreach ($orders as $order) {
                try {
                    Books::recordSale($order);
                    $totalPosted++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("  {$order->number}: {$e->getMessage()}");
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $this->info($dry ? 'عدٌّ بلا كتابة — لم يُكتب شيء' : "رُحّلت {$totalPosted} فاتورة");

        // والميزان يُقرأ بعدها: قيدٌ صحيح لا يُخلّ به، وقراءتُه هنا شاهد
        foreach ($businesses as $business) {
            $tb = \App\Support\Ledger::trialBalance($business->id);
            $this->line(sprintf(
                '%s — مدين %s / دائن %s%s',
                $business->name,
                number_format($tb['total_debit'], 3),
                number_format($tb['total_credit'], 3),
                $tb['balanced'] ? ' ✓' : ' ✗ غير متوازن',
            ));
        }

        return self::SUCCESS;
    }
}
