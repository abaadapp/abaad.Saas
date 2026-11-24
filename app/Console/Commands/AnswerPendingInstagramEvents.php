<?php

namespace App\Console\Commands;

use App\Support\InstagramAutomations;
use Illuminate\Console\Command;

/**
 * ‏‏يلتقط ما بقي من تعليقات إنستقرام ورسائله بلا ردّ — عاملٌ توقّف، أو حدُّ ساعةٍ امتلأ.
 */
class AnswerPendingInstagramEvents extends Command
{
    protected $signature = 'instagram:answer-pending';

    protected $description = 'Answer Instagram comments and messages left waiting by a stopped worker or a full hourly limit';

    public function handle(): int
    {
        $r = InstagramAutomations::sweep();

        $this->info("answered={$r['answered']} left={$r['left']}");

        return self::SUCCESS;
    }
}
