<?php

namespace App\Http\Controllers\Admin;

use App\Models\Business;
use App\Support\ChannelAutomations;

/**
 * ‏‏أتمتةُ واتساب عند التاجر — تُفتح للمتجر بمفتاح المنصّة وحده (`ChannelAutomationsSwitch`)، وغيرُه لا يرى
 * الصفحة (404).
 */
class WhatsAppAutomationsController extends AutomationsController
{
    protected function channel(): string
    {
        return ChannelAutomations::WHATSAPP;
    }

    protected function page(): string
    {
        return 'Admin/Integrations/WhatsappAutomations';
    }

    protected function open(Business $business): bool
    {
        return ChannelAutomations::available($business);
    }
}
