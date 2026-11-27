<?php

namespace App\Console\Commands;

use App\Support\WhatsAppSender;
use Illuminate\Console\Command;

/**
 * ‏‏يفحص الأرقام التي رُبطت حديثًا ويعتمد ما جهز منها رقمَ إرسالٍ لمتجره — ومع `--watch` يفحص ما اعتُمد.
 */
class AdoptWhatsAppNumbers extends Command
{
    protected $signature = 'whatsapp:adopt-numbers {--watch : Re-check the numbers already adopted instead of the ones waiting}';

    protected $description = 'Adopt newly linked WhatsApp numbers as their shops\' senders once Meta proves them ready';

    public function handle(): int
    {
        if ($this->option('watch')) {
            $r = WhatsAppSender::watch();
            $this->info("checked={$r['checked']} broken={$r['broken']}");

            return self::SUCCESS;
        }

        $r = WhatsAppSender::run();
        $w = WhatsAppSender::watch(dueOnly: true);
        $this->info("checked={$r['checked']} adopted={$r['adopted']} rechecked={$w['checked']}");

        return self::SUCCESS;
    }
}
