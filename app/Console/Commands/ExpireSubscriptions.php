<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * يقلب الشركات التي انتهى اشتراكها إلى «منتهي».
 *
 *   php artisan subscriptions:expire          # يطبّق
 *   php artisan subscriptions:expire --dry    # يعرض ولا يكتب
 *
 * الانتهاء كان يعتمد على أن ينتبه أحدهم إلى تاريخٍ في جدول: التاريخ يمرّ،
 * والحالة تبقى «نشط»، والمتجر يعمل شهورًا بلا اشتراك. والمنع وحده لا يكفي —
 * حارس الحالة يقرأ التاريخ فيمنع، لكن لوحة المنصة تظلّ تعدّ المتجر نشطًا
 * وتحسبه في الإيراد الشهري. فيفترق ما يراه المالك عمّا يجري فعلًا.
 */
class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire {--dry : اعرض ولا تكتب}';

    protected $description = 'قلبُ الشركات المنتهية اشتراكاتها إلى حالة «منتهي»';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $flipped = 0;

        Business::whereNotNull('ends_at')->where('status', 'نشط')->chunkById(200, function ($businesses) use ($dry, &$flipped) {
            foreach ($businesses as $business) {
                if (! Tenancy::expired($business)) {
                    continue;
                }

                $this->line("  {$business->name} — انتهى في ".$business->ends_at->format('Y-m-d'));
                $flipped++;

                if ($dry) {
                    continue;
                }

                $business->update(['status' => 'منتهي']);
                \App\Support\Activity::log('status', 'انتهى اشتراك: '.$business->name, [
                    'business_id' => null,
                    'subject_id' => $business->id,
                ]);
            }
        });

        $this->info($dry
            ? "{$flipped} شركة ستُقلب إلى «منتهي»."
            : "{$flipped} شركة قُلبت إلى «منتهي».");

        return self::SUCCESS;
    }
}
