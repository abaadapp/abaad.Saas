<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\GoogleBusinessAccount;
use App\Support\GoogleBusiness;
use App\Support\PlanFeatures;
use Illuminate\Console\Command;
use Throwable;

/**
 * تقييماتُ Google لكلّ متجرٍ ربط ملفَّه — بإذنه هو، وقراءةً لا غير.
 *
 * ═══ ولمَ أمرٌ دوريّ ═══
 *
 * كان السحبُ زرًّا وحده، فالتنبيهُ لا يصل إلّا من ضغط الزرّ — وهو من يعرف أصلًا.
 * وتقييمٌ بنجمةٍ يُترك يومين يقرؤه كلُّ من يفتح ملفَّ المحلّ.
 *
 * ═══ وما لا يفعله ═══
 *
 * لا ينشر ردًّا ولا يكتب عند Google حرفًا. والردُّ قرارُ التاجر من شاشته.
 *
 * وفشلُ متجرٍ لا يوقف غيره، ومتجرٌ لا تشمله باقتُه أو موقوفٌ لا يُسأل عنه. وما رفضته
 * Google لأنّ مشروع أبعاد لم يُعتمد يُعاد كلَّ ست ساعاتٍ لا كلَّ ساعة: الردُّ نفسُه،
 * والنداءُ بلا فائدة يأكل من الحصّة.
 */
class SyncGoogleReviews extends Command
{
    protected $signature = 'google:sync-reviews {--business= : متجرٌ واحدٌ بمعرّفه}';

    protected $description = 'سحب تقييمات Google للفروع المربوطة — قراءةً لا غير';

    private const BLOCKED_RETRY_HOURS = 6;

    public function handle(): int
    {
        if (! GoogleBusiness::configured()) {
            $this->line('عميل OAuth لملفّ الأعمال غير مهيّأ — لا شيء يُسأل عنه.');

            return self::SUCCESS;
        }

        GoogleBusinessAccount::query()
            ->whereNull('revoked_at')->whereNotNull('refresh_token')
            ->where(fn ($q) => $q->whereNull('api_blocked_at')
                ->orWhere('api_blocked_at', '<', now()->subHours(self::BLOCKED_RETRY_HOURS)))
            ->when($this->option('business'), fn ($q, $id) => $q->where('business_id', (int) $id))
            ->with('business')
            ->chunkById(50, function ($accounts) {
                foreach ($accounts as $account) {
                    $this->shop($account);
                }
            });

        return self::SUCCESS;
    }

    private function shop(GoogleBusinessAccount $account): void
    {
        $business = $account->business;

        if ($business === null || $business->status !== 'نشط' || ! PlanFeatures::allows($business, 'integrations')) {
            return;
        }

        $branches = Branch::where('business_id', $business->id)
            ->whereHas('googlePlace', fn ($q) => $q->whereNotNull('gbp_location'))
            ->orderBy('id')->get();

        foreach ($branches as $branch) {
            try {
                $result = GoogleBusiness::syncReviews($branch);
            } catch (Throwable $e) {
                report($e);
                $this->warn("تعثّر سحب الفرع #{$branch->id}");

                continue;
            }

            if ($result['ok']) {
                $this->line("الفرع #{$branch->id}: {$result['new']} جديد من {$result['total']}");

                continue;
            }

            $this->warn("لم يكتمل سحب الفرع #{$branch->id}");

            // إذنٌ انقطع أو مشروعٌ لم يُعتمد: بقيّةُ فروع المتجر تُردّ بالردّ نفسِه
            $state = GoogleBusiness::state($business->id)['state'];

            if (in_array($state, [GoogleBusiness::RECONNECT, GoogleBusiness::API_BLOCKED], true)) {
                return;
            }
        }
    }
}
