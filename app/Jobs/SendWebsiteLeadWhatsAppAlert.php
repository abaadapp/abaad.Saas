<?php

namespace App\Jobs;

use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Support\CrmWebsiteInquiries;
use App\Support\MetaWhatsAppClient;
use App\Support\WebsiteLeadWhatsAppAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * يُرسل تنبيهَ طلبِ الموقع إلى فريق أبعاد — بعد أن حُفظ الطلب.
 *
 * يحمل معرّفين لا غير: بياناتُ الزائر لا تُكتب في جدول الطابور، وتُقرأ من
 * ملاحظتها وقتَ التنفيذ. ولا يُرمى منه شيءٌ يُعيده الطابور إلى ما لا نهاية:
 * ما يُعاد يُعاد بنفسه (`release`) حتّى حدّه، وما لا يُعاد يُكتب ويقف.
 *
 * ═══ ولا يخرج التنبيه مرّتين ═══
 *
 * يُحجز مفتاحٌ باسم الملاحظة **قبل** النداء (`Cache::add` — ذرّيّة). فإن قبلت
 * ميتا ثمّ سقط العاملُ قبل أن يكتب، وجدت المحاولةُ التالية المفتاحَ فوقفت:
 * تنبيهٌ ضاع أهونُ من تنبيهٍ تكرّر، والطلبُ نفسُه في CRM على كلّ حال. ولا
 * يُفكّ المفتاح إلّا لخطأٍ يُعاد — ميتا ردّته فلم يخرج شيء.
 */
class SendWebsiteLeadWhatsAppAlert implements ShouldQueue
{
    use Queueable;

    /** ثلاثٌ للخطأ العابر وحده (٤٢٩، ٥xx، انقطاع) — والدائمُ يقف من أوّل مرّة */
    public $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** مدّةُ مفتاح منع التكرار — أطولُ من كلّ المحاولات بكثير */
    private const CLAIM_DAYS = 7;

    public function __construct(public int $leadId, public int $noteId)
    {
        // لا يبدأ قبل أن يُثبَّت ما أنشأه — ولا يقرأ طلبًا قد يُلغى
        $this->afterCommit();
        $this->onQueue((string) config('whatsapp.queue', 'whatsapp'));
    }

    public static function claimKey(int $noteId): string
    {
        return 'website-lead-alert:'.$noteId;
    }

    public function handle(): void
    {
        $lead = CrmLead::find($this->leadId);
        $note = CrmNote::where('id', $this->noteId)
            ->where('lead_id', $this->leadId)
            ->whereNull('user_id')
            ->where('user_name', CrmWebsiteInquiries::AUTHOR)
            ->first();

        if (! $lead || ! $note) {
            WebsiteLeadWhatsAppAlert::skipped($this->leadId, $this->noteId, 'inquiry_missing');

            return;
        }

        // ما صلح عند الصفّ يُسأل ثانيةً: خطٌّ انقطع أو قالبٌ أُوقف بينهما
        $ready = WebsiteLeadWhatsAppAlert::ready();

        if (isset($ready['skip'])) {
            WebsiteLeadWhatsAppAlert::skipped($lead->id, $note->id, $ready['skip']);

            return;
        }

        $variables = WebsiteLeadWhatsAppAlert::variables($lead, $note);

        if ($variables === null) {
            WebsiteLeadWhatsAppAlert::skipped($lead->id, $note->id, 'inquiry_unreadable');

            return;
        }

        $key = self::claimKey($note->id);

        if (! Cache::add($key, 'sending', now()->addDays(self::CLAIM_DAYS))) {
            return;
        }

        $result = MetaWhatsAppClient::sendTemplate(
            $ready['line'],
            $ready['to'],
            WebsiteLeadWhatsAppAlert::TEMPLATE,
            WebsiteLeadWhatsAppAlert::LANGUAGE,
            $variables,
        );

        if ($result['ok']) {
            Cache::put($key, 'sent', now()->addDays(self::CLAIM_DAYS));
            Log::info('website lead alert: sent', ['lead' => $lead->id, 'note' => $note->id]);

            return;
        }

        $context = ['lead' => $lead->id, 'note' => $note->id, 'code' => (string) $result['code']];

        if ($result['retryable'] && $this->attempts() < $this->tries) {
            Cache::forget($key);
            Log::warning('website lead alert: will retry', $context);
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }

        Cache::put($key, 'failed', now()->addDays(self::CLAIM_DAYS));
        Log::warning('website lead alert: failed', $context);
    }

    /** سقوطٌ باستثناءٍ بعد آخر محاولة — نوعُه وحده، لا نصُّه */
    public function failed(\Throwable $e): void
    {
        Log::error('website lead alert: job failed', [
            'lead' => $this->leadId, 'note' => $this->noteId, 'error' => class_basename($e),
        ]);
    }
}
