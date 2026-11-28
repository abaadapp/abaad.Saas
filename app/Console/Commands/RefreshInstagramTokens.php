<?php

namespace App\Console\Commands;

use App\Models\InstagramConnection;
use App\Support\InstagramLink;
use App\Support\InstagramWebhooks;
use Illuminate\Console\Command;

/**
 * ‏‏يجدّد رموزَ إنستقرام قبل انتهاء أيّامها الستّين — ويحذف الإشعاراتِ الخامَ القديمة.
 *
 * رمزٌ لا يُجدَّد في ستّين يومًا ينتهي ولا يُجدَّد بعدها: يعيد التاجر الربط. فيُجدَّد ما بقي له
 * خمسةَ عشرَ يومًا أو أقلّ كلَّ يوم، فتبقى أمامه أيّامٌ كثيرة إن تعثّر يومًا.
 */
class RefreshInstagramTokens extends Command
{
    protected $signature = 'instagram:refresh-tokens';

    protected $description = 'Refresh Instagram long-lived tokens before they expire and prune old raw webhook events';

    public function handle(): int
    {
        $refreshed = 0;
        $failed = 0;

        InstagramConnection::where('status', InstagramConnection::ACTIVE)
            ->whereNotNull('access_token')
            ->where('token_expires_at', '<', now()->addDays(InstagramLink::REFRESH_WITHIN_DAYS))
            ->orderBy('id')
            ->each(function (InstagramConnection $c) use (&$refreshed, &$failed) {
                $r = InstagramLink::refresh($c);
                $refreshed += $r['ok'] ? 1 : 0;
                $failed += ! $r['ok'] && ! $r['skipped'] ? 1 : 0;
            });

        $pruned = InstagramWebhooks::prune();

        $this->info("refreshed={$refreshed} failed={$failed} pruned={$pruned}");

        return self::SUCCESS;
    }
}
