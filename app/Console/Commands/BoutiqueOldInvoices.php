<?php

namespace App\Console\Commands;

use App\Models\Boutique;
use App\Models\Business;
use App\Support\BoutiqueInvoiceReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * فواتيرُ عملاءٍ قديمةٌ فيها أصنافُ بوتيك ولم تدخل حسابَه — جردٌ لكلّ متجر، لا يكتب حرفًا.
 *
 * يقرأ في معاملةٍ للقراءة وحدها (على PostgreSQL تردّ القاعدةُ أيّ كتابة)، ويُرجعها. ولا
 * يطبع اسمَ عميلٍ ولا رقمَه. و«المحتمل» لما عُلم صاحبُه ونسبتُه يومَها من لقطتين
 * متطابقتين وحده (`BoutiqueInvoiceReview::dryRun`) — رقمٌ للقرار لا مستحقٌّ مسجَّل.
 */
class BoutiqueOldInvoices extends Command
{
    protected $signature = 'boutiques:old-invoices {--business= : متجرٌ واحد بمعرّفه} {--json : المخرجُ JSON}';

    protected $description = 'جردٌ للقراءة: فواتير عملاء قديمة فيها أصناف بوتيك لم تدخل حسابه';

    public function handle(): int
    {
        $ids = Boutique::query()
            ->when($this->option('business') !== null, fn ($q) => $q->where('business_id', (int) $this->option('business')))
            ->distinct()->orderBy('business_id')->pluck('business_id');

        $report = [];
        foreach ($ids as $id) {
            $report[(int) $id] = $this->readOnly(fn () => ['name' => (string) Business::whereKey($id)->value('name')] + BoutiqueInvoiceReview::dryRun((int) $id));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($report === []) {
            $this->info('لا متجرَ عنده بوتيكات.');
        }

        foreach ($report as $id => $r) {
            $this->newLine();
            $this->info("متجر {$id} — {$r['name']}");
            $this->line("  فواتير {$r['invoices']} · بنود {$r['lines']} · قطع {$r['quantity']} · كُتبت بمبلغ ".number_format($r['amount'], 3));
            $this->line("  معلومٌ صاحبُه ونسبتُه يومَها: {$r['reliable']['lines']} بندًا — إجماليٌّ ".number_format($r['reliable']['gross'], 3).' · عمولة '.number_format($r['reliable']['commission'], 3).' · صافٍ محتملٌ غيرُ مسجَّل '.number_format($r['reliable']['net'], 3));
            foreach ($r['reliable']['boutiques'] as $name => $b) {
                $this->line("    {$name}: {$b['lines']} بندًا — صافٍ محتمل ".number_format($b['net'], 3));
            }
            $why = [
                BoutiqueInvoiceReview::BEFORE_ONLY => 'لا بيعَ محفوظًا بعدها', BoutiqueInvoiceReview::CONFLICT => 'ما قبلها غيرُ ما بعدها',
                BoutiqueInvoiceReview::NONE => 'لا بيعَ محفوظًا قبلها', BoutiqueInvoiceReview::CREDITED => 'عليها إشعارُ دائن', BoutiqueInvoiceReview::UNPAID => 'لم يكتمل تحصيلُها',
            ];
            foreach ($r['manual'] as $class => $m) {
                if ($m['lines'] > 0) {
                    $this->warn("  للمراجعة بيد ({$why[$class]}): {$m['lines']} بندًا — كُتبت ".number_format($m['amount'], 3));
                }
            }
            if ($r['shop']['lines'] > 0) {
                $this->line("  كان للمتجر يومَها (لا بوتيك): {$r['shop']['lines']} بندًا — ".number_format($r['shop']['amount'], 3));
            }
        }
        $this->newLine();
        $this->line('جردٌ للقراءة: لم يُكتب شيء، ولا مستحقَّ سُجّل.');

        return self::SUCCESS;
    }

    /** يقرأ داخل معاملةٍ تُرجع — وعلى PostgreSQL للقراءة وحدها بحكم القاعدة */
    private function readOnly(callable $read): mixed
    {
        DB::beginTransaction();
        try {
            if (DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            return $read();
        } finally {
            DB::rollBack();
        }
    }
}
