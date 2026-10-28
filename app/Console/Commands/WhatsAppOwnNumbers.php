<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConnection;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppLinkCheck;
use App\Support\WhatsAppLinkHealth;
use Illuminate\Console\Command;

/**
 * صحّةُ أرقام المتاجر المستقلّة كلِّها — هل يصلنا ما يكتبه زبائنها؟
 *
 * ═══ ما يُسأل لكلّ متجر ═══
 *
 * من ميتا (قراءةٌ لا غير — `WhatsAppLinkCheck::inspect`): أيُّ تطبيقٍ أصدر
 * الرمز، وصلاحياتُه وحسابُه، وأنّ الرقمَ من أرقام الحساب، وحالُ
 * `subscribed_apps` والعنوانِ البديل، وتوجيهُ المحادثات (`routing_roles`). ومن القاعدة: آخرُ حالةِ تسليم، وآخرُ
 * رسالةِ زبون، ولماذا لم تُحفظ آخرُ رسالة، وآخرُ خطأ. ومرّةً واحدةً للكلّ:
 * إعدادُ الإشعارات في تطبيق أبعاد نفسه (`WhatsAppLinkCheck::appWebhook`).
 *
 * ═══ وما لا يُفعل ═══
 *
 * لا `POST` ولا `DELETE` عند ميتا أبدًا، ولا رسالةٌ تُرسل. ولا كتابةَ في
 * القاعدة افتراضًا: معاملةُ قراءةٍ فقط (`PrintingAudit::readOnly`) — فسطرٌ
 * يكتب يسقط ولا يكتب. و`--record` وحده يكتب، في أعمدة الفحص وحدها
 * (`WhatsAppLinkCheck::recheck`): لا رمزٌ يُستبدل ولا رقمٌ ولا `status`،
 * ولا متجرٌ يُحوَّل إلى الرقم المشترك.
 *
 * ولا يُخرج رمزًا ولا نصَّ رسالة. والتشغيلُ على الإنتاج بموافقة المالك.
 *
 *     php artisan whatsapp:own-numbers
 *     php artisan whatsapp:own-numbers --business=7
 *     php artisan whatsapp:own-numbers --record
 */
class WhatsAppOwnNumbers extends Command
{
    protected $signature = 'whatsapp:own-numbers
        {--business= : متجرٌ واحد بمعرّفه}
        {--record : يكتب نتيجةَ الفحص في أعمدة الفحص — وميتا تبقى قراءةً}
        {--json : الناتجُ JSON}';

    protected $description = 'صحة أرقام واتساب المستقلة للمتاجر عند ميتا وعندنا — قراءة فقط ما لم يُطلب --record';

    public function handle(): int
    {
        $collect = fn () => self::collect($this->option('business') ? (int) $this->option('business') : null, (bool) $this->option('record'));

        $report = $this->option('record') ? $collect() : PrintingAudit::readOnly($collect);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $app = $report['app'];
        $this->line('تطبيق أبعاد ('.WhatsAppLinkCheck::appId().'): '.$app['state'].' · العنوان لنا: '
            .($app['callback_ours'] === null ? '؟' : ($app['callback_ours'] ? 'نعم' : 'لا'))
            .' · الحقول: '.(implode(',', $app['fields']) ?: '—'));

        $this->table(
            ['متجر', 'الرقم', 'مُصدر الرمز', 'العلّة', 'الاشتراك', 'التوجيه', 'آخر حالة تسليم', 'آخر رسالة عميل', 'لم تُحفظ', 'آخر خطأ', 'الحال'],
            array_map(fn ($r) => [
                $r['business_id'].' '.$r['business'],
                $r['phone'] ?? '—',
                $r['live']['app_id'] === null ? ($r['live']['ours'] === false ? 'غير أبعاد' : '؟') : $r['live']['app_id'].($r['live']['ours'] ? ' (أبعاد)' : ' (غير أبعاد)'),
                $r['live']['problem'] ?? '—',
                $r['live']['webhook'] ?? '—',
                $r['live']['routing'] ?? '—',
                $r['last_status_at'] ?? '—',
                $r['last_inbound_at'] ?? '—',
                $r['inbound_problem'] ?? '—',
                $r['last_error_code'] ?? '—',
                $r['health'],
            ], $report['numbers']),
        );

        return self::SUCCESS;
    }

    /**
     * @return array{app:array{state:string, callback_ours:?bool, fields:list<string>}, numbers:list<array<string, mixed>>}
     */
    public static function collect(?int $businessId, bool $record): array
    {
        $app = WhatsAppLinkCheck::appWebhook();

        $numbers = WhatsAppConnections::ownNumbers($businessId)->map(function (WhatsAppConnection $c) use ($record) {
            $live = WhatsAppLinkCheck::inspect($c);

            if ($record) {
                WhatsAppLinkCheck::record($c, $live);
                $c->refresh();
            }

            return [
                'business_id' => (int) $c->business_id,
                'business' => (string) $c->business?->name,
                'phone' => $c->display_phone_number,
                'live' => array_intersect_key($live, array_flip(['app_id', 'ours', 'problem', 'webhook', 'routing', 'verified'])),
                'last_webhook_at' => optional($c->last_webhook_at)->toIso8601String(),
                'last_status_at' => optional($c->last_status_at)->toIso8601String(),
                'last_inbound_at' => optional($c->last_inbound_at)->toIso8601String(),
                'inbound_problem' => $c->inbound_problem,
                'last_error_code' => $c->last_error_code,
                'health' => WhatsAppLinkHealth::state($c),
            ];
        })->values()->all();

        return ['app' => array_intersect_key($app, array_flip(['state', 'callback_ours', 'fields'])), 'numbers' => $numbers];
    }
}
