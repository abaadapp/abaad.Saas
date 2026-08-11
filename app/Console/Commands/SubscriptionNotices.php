<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionNoticeMail;
use App\Models\Business;
use App\Models\Setting;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * ينذر التاجر قبل أن يقف صندوقه — لا بعده.
 *
 * كان الإقفال يقع بلا خبرٍ يصل: المهمّة اليومية تقلب الحالة وتقيّد في السجلّ
 * ولا ترسل شيئًا، والشريط في اللوحة يحذّر من يفتح اللوحة — ومن انشغل أسبوعًا
 * لا يفتحها. فيجد صباحًا بابًا مقفلًا ولا يعرف السبب.
 *
 * والمواعيد أربعة: قبل أسبوع، قبل يوم، يومَ الانتهاء، ثم يوم الإقفال بعد
 * انقضاء المهلة. لا يوميًّا: إنذارٌ يصل كل يومٍ يُقرأ زخرفةً في ثالثه، ثم
 * يُصنَّف مزعجًا فلا يُقرأ يوم يجب أن يُقرأ.
 */
class SubscriptionNotices extends Command
{
    protected $signature = 'subscriptions:notify {--dry : يعرض ولا يرسل}';

    protected $description = 'إنذار أصحاب المتاجر قبل انتهاء الاشتراك وعنده وعند توقّف النظام';

    /** أيّامٌ قبل الانتهاء يُرسَل فيها إنذار — وما عداها صمت */
    private const BEFORE = [7, 1];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $contact = Setting::whereNull('business_id')
            ->whereIn('key', ['company', 'official_email', 'phone'])
            ->pluck('value', 'key');

        $sent = 0;

        Business::whereNotNull('ends_at')->whereNotNull('email')->each(
            function (Business $business) use ($dry, $contact, &$sent) {
                $stage = $this->stageFor($business);

                if ($stage === null) {
                    return;
                }

                [$name, $days] = $stage;

                $this->line("  {$business->name} — {$name} ({$days})");

                if ($dry) {
                    $sent++;

                    return;
                }

                /*
                 * فشل رسالةٍ لا يوقف البقيّة: عنوانٌ خاطئ في متجرٍ واحد كان
                 * سيمنع الإنذار عن كل من بعده في القائمة — وهم من يحتاجونه.
                 */
                try {
                    Mail::to($business->email)->send(new SubscriptionNoticeMail(
                        $name,
                        $business->name,
                        $business->ends_at->format('Y-m-d'),
                        $days,
                        [
                            'company' => $contact['company'] ?? null,
                            'email' => $contact['official_email'] ?? null,
                            'phone' => $contact['phone'] ?? null,
                        ],
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                    $this->warn("  ✗ {$business->name} — تعذّر الإرسال");
                }
            }
        );

        $this->info($dry ? "{$sent} إنذارًا ستُرسَل." : "أُرسل {$sent} إنذارًا.");

        return self::SUCCESS;
    }

    /**
     * المرحلة التي يقف عندها هذا المتجر اليوم — أو null فلا يُرسل شيء.
     *
     * @return array{0: string, 1: int}|null
     */
    private function stageFor(Business $business): ?array
    {
        // المعطَّلة يدويًّا خارج هذا: أمرها قرارٌ لا موعد، وإنذارُ تجديدٍ فيها تضليل
        if (in_array((string) $business->status, ['معطل', 'معطّل'], true)) {
            return null;
        }

        $daysLeft = Tenancy::daysLeft($business);

        if ($daysLeft > 0) {
            return in_array($daysLeft, self::BEFORE, true) ? ['before', $daysLeft] : null;
        }

        if ($daysLeft === 0) {
            return ['today', 0];
        }

        $graceLeft = Tenancy::graceLeft($business);

        // يوم الإقفال نفسه وحده، لا كل يومٍ بعده إلى الأبد
        if (Tenancy::locked($business)) {
            return $this->lockedToday($business) ? ['locked', 0] : null;
        }

        return in_array($graceLeft, self::BEFORE, true) ? ['grace', $graceLeft] : null;
    }

    /**
     * أُقفل اليوم؟ — لا «هل هو مقفل».
     *
     * `locksAt` آخرُ لحظةٍ يعمل فيها المتجر، فيوم الإقفال هو اليوم الذي
     * يليها. ومقارنتها باليوم مباشرةً تجعل الرسالة تُرسل في آخر يومٍ يعمل
     * فيه — أو لا تُرسل أبدًا.
     */
    private function lockedToday(Business $business): bool
    {
        return Tenancy::locksAt($business)?->copy()->addDay()->isSameDay(now()) ?? false;
    }
}
