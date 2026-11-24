<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\WhatsAppCampaignTemplate;
use App\Support\WhatsAppCampaigns;
use App\Support\WhatsAppCampaignTemplates;
use Illuminate\Console\Command;

/**
 * ‏‏دفعةُ الدقيقة من حملات واتساب — ومع `--templates` يُسأل عن قوالبَ تنتظر مراجعة Meta.
 */
class RunWhatsAppCampaigns extends Command
{
    protected $signature = 'whatsapp:run-campaigns {--templates : Ask Meta about templates still under review instead of sending}';

    protected $description = 'Send the next small batch of WhatsApp campaign messages, or refresh templates under review';

    public function handle(): int
    {
        if ($this->option('templates')) {
            $changed = 0;

            foreach (WhatsAppCampaignTemplate::whereIn('status', WhatsAppCampaignTemplates::UNDER_REVIEW)->distinct()->pluck('business_id') as $id) {
                $business = Business::find($id);
                $changed += $business !== null && WhatsAppCampaigns::available($business) ? WhatsAppCampaignTemplates::sync($business)['changed'] : 0;
            }

            $this->info("changed={$changed}");

            return self::SUCCESS;
        }

        $r = WhatsAppCampaigns::run();
        $this->info("campaigns={$r['campaigns']} sent={$r['sent']}");

        return self::SUCCESS;
    }
}
