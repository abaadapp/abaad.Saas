<?php

namespace App\Console\Commands;

use App\Support\SubscriptionWhatsApp;
use Illuminate\Console\Command;

/**
 * قوالبُ إنذار الاشتراك في حساب أبعاد عند Meta — تُقرأ، وتُرسَل للمراجعة بأمرٍ صريح.
 *
 * بلا `--create` قراءةٌ وحدها: أهي موجودةٌ وما حالُها. ومعه تُرسَل الناقصةُ إلى
 * Meta لتراجعها، وذلك قرارُ صاحب المنصّة لا النشر: فلا يُنادى هذا من جدولٍ ولا
 * من هجرة. وما لم تعتمده Meta لا يُرسَل به شيء (`SubscriptionWhatsApp::send`).
 */
class SubscriptionWhatsAppTemplates extends Command
{
    protected $signature = 'subscriptions:whatsapp-templates {--create : يرسل القوالب الناقصة إلى Meta لتراجعها}';

    protected $description = 'حال قوالب إنذار الاشتراك على رقم أبعاد، وإرسال الناقص منها للمراجعة';

    public function handle(): int
    {
        $line = SubscriptionWhatsApp::line();

        if ($line === null) {
            $this->error('لا رقم إشعارات نشطًا لأبعاد.');

            return self::FAILURE;
        }

        $this->line('الرقم: '.SubscriptionWhatsApp::tail(preg_replace('/\D/', '', (string) $line->display_phone_number)));

        $there = SubscriptionWhatsApp::statuses($line);

        if ($there === null) {
            $this->error('تعذّرت قراءة القوالب من Meta.');

            return self::FAILURE;
        }

        foreach (SubscriptionWhatsApp::TEMPLATES as $stage => $template) {
            $this->line("  {$stage} · {$template['name']}: ".($there[$template['name']] ?? 'غير موجود'));
        }

        if (! $this->option('create')) {
            return self::SUCCESS;
        }

        $sent = SubscriptionWhatsApp::submitMissing($line, $there);

        if ($sent === []) {
            $this->info('لا ناقص — القوالب كلّها في الحساب.');
        }

        foreach ($sent as $name => $r) {
            $r['ok']
                ? $this->info("  أُرسل {$name} للمراجعة: ".($r['status'] ?? '—'))
                : $this->warn("  ✗ {$name}: ".($r['message'] ?? 'رفضته Meta'));
        }

        return collect($sent)->every(fn ($r) => $r['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
