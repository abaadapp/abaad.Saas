<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\BoutiqueHolds;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueReturns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ما عُلّق من أوراق البوتيكات — يُقرأ قبل الرجوع بالكود وبعد العودة منه.
 *
 * بلا خيارٍ يقرأ ولا يكتب حرفًا: أقائمةٌ حمايةُ السداد في قاعدة البيانات؟ وكم ورقةً تحمل
 * بنودَ فواتير ولم تُسدَّد؟ وكم المعلَّق؟ وما الذي تغيّر تحصيلُه والكودُ القديم يعمل فلم يُعلَّق؟
 *
 * و`--resync` بعد العودة: يُقيم كلَّ فاتورةٍ حملت ورقةٌ بندَها على حال تحصيلها الآن — يفتح ما
 * فات ويُغلق ما أُعيد (`BoutiqueInvoiceDues::sync`). لا يسدّد ولا يكتب قيدًا ولا يمسّ ورقة.
 */
class BoutiqueHoldsCheck extends Command
{
    protected $signature = 'boutiques:holds {--business= : متجرٌ واحد بمعرّفه} {--resync : يُقيم التعليقات على حال التحصيل الآن} {--json : المخرجُ JSON}';

    protected $description = 'ما عُلّق من أوراق البوتيكات، وحمايةُ سدادها — قبل الرجوع بالكود وبعده';

    public function handle(): int
    {
        $only = $this->option('business') !== null ? (int) $this->option('business') : null;

        if ($this->option('resync')) {
            foreach (BoutiqueHolds::drifted($only) as $line) {
                BoutiqueInvoiceDues::sync((int) $line->customer_invoice_id);
            }
        }

        $guarded = BoutiqueHolds::guarded();
        $shops = BoutiqueHolds::overview($only);
        // ومرتجعاتٌ كتبها كودٌ أقدم على أوراقٍ قُرّبت بندًا بندًا — قد تخالف قاعدتَها ببيسة
        $older = BoutiqueReturns::olderRounding($only);

        if ($this->option('json')) {
            $this->line((string) json_encode(['guarded' => $guarded, 'shops' => $shops, 'older_rounding_notes' => $older], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $guarded ? self::SUCCESS : self::FAILURE;
        }

        $guarded
            ? $this->info('حماية السداد قائمة في قاعدة البيانات: كودٌ لا يقرأ المعلّق لا يسدّد ورقةً فيها فواتير عملاء.')
            : $this->error('حماية السداد غير قائمة في قاعدة البيانات. لا ترجع بالكود.');

        foreach ($shops as $id => $s) {
            $this->newLine();
            $this->info("متجر {$id} — ".(string) Business::whereKey($id)->value('name'));
            $this->line("  أوراق تحمل فواتير عملاء ولم تُسدَّد: {$s['unpaid_sheets']} بمبلغ ".number_format($s['unpaid_amount'], 3));
            $this->line("  معلّق فيها الآن: {$s['held']} بمبلغ ".number_format($s['held_amount'], 3).' · سُدّدت الورقة دونه ولم يُسدَّد: '.$s['withheld'].' بمبلغ '.number_format($s['withheld_amount'], 3));
            $s['drifted'] === 0 ?: $this->warn("  تغيّر تحصيلها ولم يُقيَّم: {$s['drifted']} بندًا — boutiques:holds --resync");
            $s['unnamed_notes'] === [] ?: $this->warn('  إشعارات بلا بيان ما رُدّ (كُتبت بكود أقدم) — تُراجَع بيد قبل سداد أوراقها: '.implode('، ', $s['unnamed_notes']));
        }

        $shops !== [] ?: $this->line('لا ورقة تحمل فاتورة عميل في أيّ متجر.');

        $older === [] ?: $this->warn('مرتجعات كتبها كود أقدم على أوراق قُرّبت بندًا بندًا — تُراجَع بيد (قد تخالف ببيسة): '
            .implode('، ', array_map(fn ($r) => $r['sheet'].' «'.$r['name'].'» '.number_format($r['amount'], 3), $older)));

        return $guarded ? self::SUCCESS : self::FAILURE;
    }
}
