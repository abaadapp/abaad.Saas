<?php

namespace App\Console\Commands;

use App\Support\WebsiteLeadWhatsAppAlert;
use Illuminate\Console\Command;

/**
 * نسألُ ميتا: أاعتمدتِ قالبَ تنبيه طلبات الموقع على خطّ المبيعات؟
 *
 * قراءةٌ لا إرسال — ثمّ قائمةُ الجاهزيّة نعم/لا، بلا رمزٍ ولا رقم. ويُجدوَل كلَّ
 * ساعة بجوار `whatsapp:sync-templates`: ذاك يسأل حسابَ رقم الإشعارات، وهذا
 * حسابَ خطّ المبيعات، والقالبُ هنا لا يُكتب في جدول قوالب المحلّات.
 */
class SyncWebsiteLeadAlertTemplate extends Command
{
    protected $signature = 'crm:sync-website-lead-alert';

    protected $description = 'قراءة حال قالب تنبيه طلبات الموقع على خطّ مبيعات أبعاد، وطباعة الجاهزيّة';

    public function handle(): int
    {
        $result = WebsiteLeadWhatsAppAlert::syncTemplate();

        $result['ok']
            ? $this->line('قالب '.WebsiteLeadWhatsAppAlert::TEMPLATE.': '.$result['status'])
            // ولا يُطفأ شيء: الحالُ القديم يبقى، والأمرُ المجدول لا يرجع فشلًا
            : $this->warn('لم يُسأل عن القالب: '.$result['reason']);

        foreach (WebsiteLeadWhatsAppAlert::readiness() as $label => $value) {
            $this->line($label.': '.$value);
        }

        return self::SUCCESS;
    }
}
