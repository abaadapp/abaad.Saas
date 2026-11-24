<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppCampaignTemplate;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppOptOut;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ‏‏حملاتُ واتساب — عرضٌ يكتبه التاجر بقالبٍ معتمَد، فيخرج من رقمه إلى عملائه دفعةً بعد دفعة.
 *
 * ═══ من يدفع ═══
 *
 * كلُّ رسالةٍ تصل تُحتسب على حساب المتجر عند Meta — من رقمه وحده، ولا تخرج حملةٌ من رقم أبعاد أبدًا.
 * فلا تبدأ إلّا بعد أن يرى التاجرُ العددَ ويكتبه، ويقرّ بموافقة عملائه. ولو تغيّر العدد بعد أن رآه رُدّت.
 *
 * ═══ كيف تخرج ═══
 *
 * أمرٌ كلَّ دقيقة (`whatsapp:run-campaigns`) يأخذ دفعةً صغيرة: لا تزاحم طابورَ إشعارات الطلبات، ولا
 * تُثقل الخادم. ولا تخرج رسالةٌ بين العاشرة مساءً والثامنة صباحًا. وحدُّ Meta اليوميّ لهذا الرقم يُقرأ
 * منها ويُحترم: ما زاد يكمل في اليوم التالي وحده.
 *
 * ═══ ولا رسالتان ═══
 *
 * كلُّ رقمٍ يُحجز قبل النداء (`claim`)، فلا يأخذه تشغيلان. وما انقطعت شبكتُه لا يُعاد: قد يكون وصل،
 * والرسالةُ الثانية تُحتسب. وما رُدّ لحدّ سرعةٍ قبل تنفيذه يعود إلى دوره.
 *
 * ═══ وما يوقفها ═══
 *
 * رفضٌ يصيب كلَّ ما بعده — وسيلةُ دفعٍ أو قالبٌ أو رقمٌ أو تقييد — يوقف الحملة عنده بسببه، ولا يُحرق
 * بقيّةُ القائمة. وكذلك إن فشل نصفُ ما خرج. ويستأنفها التاجر حين يصلحه.
 */
class WhatsAppCampaigns
{
    public const PER_RUN = 25;

    public const RUN_SECONDS = 40;

    public const QUIET_FROM = 22;

    public const QUIET_TO = 8;

    /** ‏‏حدُّ Meta لعملاء اليوم بحروفها — وما لم تقله يُحسب أضيقَها */
    public const TIERS = ['TIER_50' => 50, 'TIER_250' => 250, 'TIER_1K' => 1000, 'TIER_2K' => 2000, 'TIER_10K' => 10000, 'TIER_100K' => 100000, 'TIER_UNLIMITED' => PHP_INT_MAX];

    public const TIER_UNKNOWN = 250;

    /** ‏‏حجزٌ لم يُكتب جوابُه في هذه المدّة انقطع — لا يُعاد */
    public const STUCK_MINUTES = 10;

    public const MIN_TO_JUDGE = 20;

    public const SCHEDULE_DAYS = 30;

    public const LIST_LIMIT = 30;

    public const STOP_MANY_FAILURES = 'many_failures';

    public const STOP_BY_MERCHANT = 'merchant';

    public const STOP_CLOSED = 'closed';

    public const STOP_RESTORED = 'restored';

    /** ‏‏ليس وقوفًا: بلغ حدَّ اليوم عند Meta ويكمل وحده */
    public const WAITS_DAILY_LIMIT = 'daily_limit';

    /** ‏‏حدُّ الرقم عند Meta يُقرأ مرّةً كلَّ هذه المدّة — لا في كلّ دقيقة */
    public const LIMITS_TTL_MINUTES = 10;

    /** ‏‏رموزٌ تصيب كلَّ ما بعدها — تقف الحملةُ عندها والرقمُ يعود إلى دوره */
    public const FATAL = ['0', '3', '10', '190', '200', '299', '368', '131005', '131031', '131042', '131048', '131063', '131064', '132000', '132001', '132005', '132007', '132012', '132015', '132016', '133010'];

    /** ‏‏حدُّ سرعةٍ قبل التنفيذ — يعود الرقمُ إلى دوره وتُترك بقيّةُ الدفعة للدقيقة التالية */
    public const THROTTLED = ['4', '80007', '130429', '131056', '131057', '429'];

    /** ‏‏أوقف العروضَ من إعدادات واتساب عنده */
    public const META_OPTED_OUT = '131050';

    public const INTERRUPTED = 'interrupted';

    public const OPTED_OUT = 'opted_out';

    public static function available(?Business $business): bool
    {
        return $business !== null && WhatsAppCampaignsSwitch::allows((int) $business->id);
    }

    /** ‏‏ما يمنع الحملات الآن — بكلماتٍ يفهمها التاجر، أو `null` */
    public static function obstacle(Business $business): ?string
    {
        $connection = WhatsAppConnections::forBusiness((int) $business->id);

        if ($connection === null || $connection->status === WhatsAppConnection::INACTIVE) {
            return __('الحملات تخرج من رقم متجرك — اربطه أولًا.');
        }

        $reason = WhatsAppAutoReply::blocked($connection);

        if ($reason !== null) {
            return WhatsAppStatus::reason($reason) ?? __('رقم متجرك لا يرسل الآن — راجع صفحة الربط.');
        }

        return blank($connection->waba_id) ? __('لا معرّف حساب أعمال على هذه الوصلة.') : null;
    }

    /**
     * ‏‏يبدأ حملةً أو يجدولها — بعد أن يطابق العددُ ما أقرّه التاجر.
     *
     * @param  array<string, mixed>  $data  name, template_id, audience, confirm_count, consent, scheduled_at
     * @return array{ok: bool, campaign: ?WhatsAppCampaign, field: string, message: ?string}
     */
    public static function launch(Business $business, array $data, User $user): array
    {
        $refused = fn (string $field, string $why) => ['ok' => false, 'campaign' => null, 'field' => $field, 'message' => $why];

        if (($why = self::obstacle($business)) !== null) {
            return $refused('name', $why);
        }

        if (empty($data['consent'])) {
            return $refused('consent', __('أقرّ أولًا بأن عملاءك وافقوا على استلام عروضك.'));
        }

        $connection = WhatsAppCampaignTemplates::connection($business);
        $template = WhatsAppCampaignTemplate::where('business_id', $business->id)->whereKey($data['template_id'] ?? 0)->first();

        if ($connection === null || $template === null || $template->waba_id !== (string) $connection->waba_id) {
            return $refused('template_id', __('اختر قالبًا من قوالب رقمك المربوط.'));
        }

        if ($template->status !== WhatsAppCampaignTemplate::APPROVED) {
            return $refused('template_id', __('هذا القالب لم يعتمده واتساب بعد.'));
        }

        // ‏‏والمتصفّحُ يرسل لحظةً بتوقيت غرينتش — تُردّ إلى توقيت النظام قبل أن تُكتب، وإلّا كُتبت ساعتُها كما هي
        $at = filled($data['scheduled_at'] ?? null) ? Carbon::parse((string) $data['scheduled_at'])->setTimezone((string) config('app.timezone')) : null;

        if ($at !== null && ($at->lt(now()->addMinutes(5)) || $at->gt(now()->addDays(self::SCHEDULE_DAYS)))) {
            return $refused('scheduled_at', __('اختر وقتًا بعد خمس دقائق على الأقل وخلال :n يومًا.', ['n' => self::SCHEDULE_DAYS]));
        }

        $filter = WhatsAppCampaignAudience::clean($business, (array) ($data['audience'] ?? []));
        $people = WhatsAppCampaignAudience::resolve($business, $filter)['people'];
        $count = count($people);

        if ($count === 0) {
            return $refused('audience', __('لا عميل تصله هذه الحملة — وسّع الاختيار.'));
        }

        if ($count > WhatsAppCampaignAudience::MAX_RECIPIENTS) {
            return $refused('audience', __('جمهور الحملة :n أكثر من :max — ضيّق الاختيار.', ['n' => $count, 'max' => WhatsAppCampaignAudience::MAX_RECIPIENTS]));
        }

        // ‏‏العددُ الذي رآه وكتبه هو العددُ الذي يُرسل — وما تغيّر بعده يُعاد عرضُه
        if ((int) ($data['confirm_count'] ?? 0) !== $count) {
            return $refused('confirm_count', __('تغيّر العدد إلى :n منذ عرضناه — راجعه وأكّده.', ['n' => $count]));
        }

        $campaign = DB::transaction(function () use ($business, $connection, $template, $data, $user, $at, $filter, $people, $count) {
            Business::whereKey($business->id)->lockForUpdate()->first();

            // ‏‏ضغطتان على «أرسل» لا تصيران حملتين
            $twin = WhatsAppCampaign::where('business_id', $business->id)->where('template_id', $template->id)
                ->whereIn('status', [WhatsAppCampaign::SCHEDULED, WhatsAppCampaign::SENDING])
                ->where('created_at', '>', now()->subMinutes(2))->exists();

            if ($twin) {
                return null;
            }

            $campaign = WhatsAppCampaign::create([
                'business_id' => $business->id,
                'template_id' => $template->id,
                'whatsapp_connection_id' => $connection->id,
                'name' => mb_substr(trim((string) $data['name']), 0, 80),
                // ‏‏القالبُ كما كان يومَها — لا يتبدّل ما أقرّه التاجر بتعديلٍ بعده
                'message' => ['meta_name' => $template->meta_name, 'language' => $template->language, 'body' => $template->body, 'variables' => (array) $template->variables, 'button' => $template->button],
                'audience' => $filter,
                'status' => $at !== null ? WhatsAppCampaign::SCHEDULED : WhatsAppCampaign::SENDING,
                'total' => $count,
                'scheduled_at' => $at,
                'started_at' => $at === null ? now() : null,
                'created_by' => $user->id,
                'consent_at' => now(),
            ]);

            foreach (array_chunk($people, 500) as $chunk) {
                WhatsAppCampaignRecipient::insert(array_map(fn ($p) => [
                    'business_id' => $business->id, 'campaign_id' => $campaign->id, 'customer_id' => $p['customer_id'],
                    'phone' => $p['phone'], 'name' => $p['name'], 'status' => WhatsAppCampaignRecipient::PENDING,
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            return $campaign;
        });

        if ($campaign === null) {
            return $refused('name', __('بدأت حملة بهذا القالب قبل لحظات — انظر القائمة.'));
        }

        Activity::log('settings', 'حملات واتساب: «'.$campaign->name.'» إلى '.$count.' عميلًا'.($at !== null ? ' في '.$at->format('Y-m-d H:i') : ' الآن').' — أقرّ بموافقة العملاء وبأنّ الكلفة على حسابه', [
            'subject_type' => WhatsAppCampaign::class,
            'subject_id' => $campaign->id,
        ]);

        return ['ok' => true, 'campaign' => $campaign, 'field' => '', 'message' => null];
    }

    /** @return array{ok: bool, message: ?string} */
    public static function pause(WhatsAppCampaign $c, string $reason = self::STOP_BY_MERCHANT): array
    {
        return self::move($c, [WhatsAppCampaign::SENDING, WhatsAppCampaign::SCHEDULED], ['status' => WhatsAppCampaign::PAUSED, 'stop_reason' => mb_substr($reason, 0, 60)], 'أُوقفت');
    }

    /** @return array{ok: bool, message: ?string} */
    public static function resume(Business $business, WhatsAppCampaign $c): array
    {
        if (($why = self::obstacle($business)) !== null) {
            return ['ok' => false, 'message' => $why];
        }

        // ‏‏وحملةٌ مجدولةٌ لم يحن وقتُها تعود إلى جدولها لا إلى الإرسال الآن
        $later = $c->started_at === null && $c->scheduled_at !== null && $c->scheduled_at->isFuture();

        return self::move($c, [WhatsAppCampaign::PAUSED], [
            'status' => $later ? WhatsAppCampaign::SCHEDULED : WhatsAppCampaign::SENDING,
            'stop_reason' => null,
            'started_at' => $later ? null : ($c->started_at ?? now()),
        ], 'استُؤنفت');
    }

    /** @return array{ok: bool, message: ?string} */
    public static function cancel(WhatsAppCampaign $c): array
    {
        return self::move($c, WhatsAppCampaign::OPEN, ['status' => WhatsAppCampaign::CANCELLED, 'finished_at' => now()], 'أُلغيت');
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $to
     * @return array{ok: bool, message: ?string}
     */
    private static function move(WhatsAppCampaign $c, array $from, array $to, string $said): array
    {
        $moved = WhatsAppCampaign::whereKey($c->id)->whereIn('status', $from)->update($to + ['updated_at' => now()]);

        if ($moved !== 1) {
            return ['ok' => false, 'message' => __('تبدّلت حال الحملة — حدّث الصفحة.')];
        }

        $c->refresh();

        Activity::log('settings', 'حملات واتساب: «'.$c->name.'» '.$said.(($to['stop_reason'] ?? null) ? ' ('.$to['stop_reason'].')' : ''), [
            'business_id' => $c->business_id,
            'subject_type' => WhatsAppCampaign::class,
            'subject_id' => $c->id,
        ]);

        return ['ok' => true, 'message' => null];
    }

    /* ═══════════ الإرسال ═══════════ */

    /**
     * ‏‏أثمّة ما يُرسل أو يُنتظر؟ — يسأله المجدوِل كلَّ دقيقة.
     *
     * ولا يرمي أبدًا: المجدوِل يقرأ الكود الجديد لحظةَ سحبه، قبل أن تجري الهجرة — واستثناءٌ هنا يُسقط كلَّ
     * ما بعده في جدول تلك الدقيقة.
     */
    public static function waiting(): bool
    {
        try {
            return WhatsAppCampaign::whereIn('status', [WhatsAppCampaign::SENDING, WhatsAppCampaign::SCHEDULED])->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** ‏‏أثمّة قالبٌ ينتظر مراجعة Meta؟ — ولا يرمي، كأختها */
    public static function templatesWaiting(): bool
    {
        try {
            return WhatsAppCampaignTemplate::whereIn('status', WhatsAppCampaignTemplates::UNDER_REVIEW)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** ‏‏ساعةُ صمتٍ: لا تخرج حملةٌ ليلًا */
    public static function quietNow(?Carbon $at = null): bool
    {
        $hour = ($at ?? now())->hour;

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_TO;
    }

    /**
     * ‏‏جولةُ الدقيقة: تُبدأ المجدولةُ التي حان وقتُها، ثمّ دفعةٌ من كلّ حملةٍ تُرسل.
     *
     * @return array{sent: int, campaigns: int}
     */
    public static function run(): array
    {
        $sent = 0;
        $touched = 0;

        if (self::quietNow()) {
            return ['sent' => 0, 'campaigns' => 0];
        }

        WhatsAppCampaign::where('status', WhatsAppCampaign::SCHEDULED)->where('scheduled_at', '<=', now())
            ->update(['status' => WhatsAppCampaign::SENDING, 'started_at' => now(), 'updated_at' => now()]);

        $until = microtime(true) + self::RUN_SECONDS;

        // ‏‏والأبعدُ عهدًا أوّلًا: حملةٌ كبيرةٌ لا تجوّع صغيرةً بعدها
        foreach (WhatsAppCampaign::where('status', WhatsAppCampaign::SENDING)->orderByRaw('last_run_at is not null')->orderBy('last_run_at')->orderBy('id')->get() as $campaign) {
            if (microtime(true) >= $until) {
                break;
            }

            $touched++;
            $campaign->forceFill(['last_run_at' => now()])->save();

            try {
                $sent += self::batch($campaign, $until);
            } catch (Throwable $e) {
                // ‏‏عطبٌ في حملةٍ لا يوقف ما بعدها — ورقمُها المحجوز يُطوى بعد مدّته ولا يُعاد
                report($e);
            }
        }

        return ['sent' => $sent, 'campaigns' => $touched];
    }

    /** ‏‏دفعةٌ من حملة — كم رسالةً خرجت */
    private static function batch(WhatsAppCampaign $campaign, float $until): int
    {
        $business = Business::find($campaign->business_id);
        $connection = $business === null ? null : WhatsAppCampaignTemplates::connection($business);

        if ($business === null || ! self::available($business)) {
            self::pause($campaign, self::STOP_CLOSED);

            return 0;
        }

        // ‏‏ورقمٌ غيرُ الذي أقرّها عليه التاجر لا يرسلها — ولا رقمٌ انقطع ربطُه
        if ($connection === null || (int) $connection->id !== (int) $campaign->whatsapp_connection_id
            || ($reason = WhatsAppAutoReply::blocked($connection)) !== null) {
            self::pause($campaign, $reason ?? WhatsAppStatus::SKIP_NO_CONNECTION);

            return 0;
        }

        self::closeStuck($campaign);

        $room = self::room($connection);
        $sent = 0;

        // ‏‏بلغ حدَّ اليوم: تبقى في دورها ويُقال ذلك — لا تُوقف
        $waits = $room === 0 ? self::WAITS_DAILY_LIMIT : null;

        if ($campaign->stop_reason !== $waits) {
            WhatsAppCampaign::whereKey($campaign->id)->where('status', WhatsAppCampaign::SENDING)->update(['stop_reason' => $waits, 'updated_at' => now()]);
        }

        while ($room > 0 && $sent < self::PER_RUN && microtime(true) < $until) {
            $next = self::claim($campaign);

            if ($next === null) {
                break;
            }

            if (WhatsAppOptOuts::has((int) $business->id, $next->phone)) {
                $next->forceFill(['status' => WhatsAppCampaignRecipient::SKIPPED, 'error_code' => self::OPTED_OUT])->save();

                continue;
            }

            $outcome = self::send($business, $connection, $campaign, $next);

            if ($outcome === 'stop' || $outcome === 'later') {
                return $sent;
            }

            $sent++;
            $room--;

            if (self::tooManyFailures($campaign)) {
                self::pause($campaign, self::STOP_MANY_FAILURES);

                return $sent;
            }
        }

        self::finishIfDone($campaign);

        return $sent;
    }

    /** ‏‏الرقمُ التالي محجوزًا لهذا التشغيل وحده — أو `null` لا بقيّة، أو توقّفت الحملةُ الآن */
    private static function claim(WhatsAppCampaign $campaign): ?WhatsAppCampaignRecipient
    {
        if (WhatsAppCampaign::whereKey($campaign->id)->value('status') !== WhatsAppCampaign::SENDING) {
            return null;
        }

        for ($i = 0; $i < 5; $i++) {
            $id = WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)->where('status', WhatsAppCampaignRecipient::PENDING)->orderBy('id')->value('id');

            if ($id === null) {
                return null;
            }

            $mine = WhatsAppCampaignRecipient::whereKey($id)->where('status', WhatsAppCampaignRecipient::PENDING)
                ->update(['status' => WhatsAppCampaignRecipient::SENDING, 'claimed_at' => now(), 'updated_at' => now()]);

            if ($mine === 1) {
                return WhatsAppCampaignRecipient::find($id);
            }
        }

        return null;
    }

    /** ‏‏`sent` خرجت، أو `failed` لهذا الرقم وحده، أو `later` عاد إلى دوره، أو `stop` وقفت الحملة */
    private static function send(Business $business, WhatsAppConnection $connection, WhatsAppCampaign $campaign, WhatsAppCampaignRecipient $to): string
    {
        $m = (array) $campaign->message;

        $r = MetaWhatsAppClient::sendTemplate(
            $connection,
            $to->phone,
            (string) $m['meta_name'],
            WhatsAppCampaignTemplates::META_LANGUAGE[$m['language']] ?? 'ar',
            WhatsAppCampaignTemplates::values($business, (array) $m['variables'], (string) $m['language'], $to->name),
        );

        if ($r['ok']) {
            $to->forceFill(['status' => WhatsAppCampaignRecipient::SENT, 'wamid' => mb_substr((string) $r['id'], 0, 191), 'sent_at' => now(), 'error_code' => null])->save();

            return 'sent';
        }

        $code = (string) ($r['code'] ?: 'provider_failed');

        if (in_array($code, self::THROTTLED, true)) {
            self::putBack($to);

            return 'later';
        }

        if (in_array($code, self::FATAL, true) || in_array($code, self::CONNECTION_PROBLEMS, true)) {
            self::putBack($to);
            self::pause($campaign, $code);
            Log::warning('whatsapp.campaign_stopped', ['business_id' => $business->id, 'campaign_id' => $campaign->id, 'meta_code' => $code]);

            return 'stop';
        }

        if ($code === self::META_OPTED_OUT) {
            WhatsAppOptOuts::add((int) $business->id, $to->phone, WhatsAppOptOut::META);
        }

        $to->forceFill(['status' => WhatsAppCampaignRecipient::FAILED, 'error_code' => mb_substr($code, 0, 60)])->save();

        return 'failed';
    }

    /** ‏‏ما يردّه بابُ الإرسال عندنا قبل Meta — يصيب كلَّ ما بعده */
    private const CONNECTION_PROBLEMS = [WhatsAppOwnApp::AWAITS_BILLING, WhatsAppOwnApp::AMBIGUOUS_ACCOUNT];

    private static function putBack(WhatsAppCampaignRecipient $to): void
    {
        $to->forceFill(['status' => WhatsAppCampaignRecipient::PENDING, 'claimed_at' => null])->save();
    }

    /** ‏‏حجزٌ انقطع قبل أن يُكتب جوابُه — قد تكون رسالتُه خرجت، فلا تُعاد */
    private static function closeStuck(WhatsAppCampaign $campaign): void
    {
        WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)->where('status', WhatsAppCampaignRecipient::SENDING)
            ->where('claimed_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => WhatsAppCampaignRecipient::FAILED, 'error_code' => self::INTERRUPTED, 'updated_at' => now()]);
    }

    /**
     * ‏‏كم عميلًا بقي من حدّ Meta لهذا الرقم في آخر ٢٤ ساعة — بهامشٍ عُشر، وإشعاراتُ الطلبات تأخذ منه أيضًا.
     */
    public static function room(WhatsAppConnection $connection): int
    {
        $tier = Cache::remember('whatsapp_campaign_tier:'.$connection->id.':'.$connection->phone_number_id, now()->addMinutes(self::LIMITS_TTL_MINUTES),
            fn () => (string) MetaWhatsAppClient::limits($connection)['tier']);
        $cap = self::TIERS[$tier] ?? self::TIER_UNKNOWN;

        if ($cap === PHP_INT_MAX) {
            return self::PER_RUN;
        }

        $used = WhatsAppCampaignRecipient::where('business_id', $connection->business_id)
            ->whereIn('status', WhatsAppCampaignRecipient::OUT)->where('sent_at', '>', now()->subDay())->distinct()->count('phone');

        return max(0, (int) floor($cap * 0.9) - $used);
    }

    /** ‏‏نصفُ ما خرج فشل — فالعطبُ ليس في رقمٍ بعينه */
    private static function tooManyFailures(WhatsAppCampaign $campaign): bool
    {
        $counts = self::counts($campaign->id);
        $tried = $counts['out'] + $counts['failed'];

        return $tried >= self::MIN_TO_JUDGE && $tried < $counts['failed'] * 2;
    }

    private static function finishIfDone(WhatsAppCampaign $campaign): void
    {
        // ‏‏حملةٌ بلا أرقام: متجرٌ استُعيد من نسخة (أرقامُها لا تُنسخ) — لا تُعاد على من وصلته قبل النسخة
        if (! WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)->exists()) {
            WhatsAppCampaign::whereKey($campaign->id)->where('status', WhatsAppCampaign::SENDING)
                ->update(['status' => WhatsAppCampaign::CANCELLED, 'stop_reason' => self::STOP_RESTORED, 'finished_at' => now(), 'updated_at' => now()]);

            return;
        }

        $left = WhatsAppCampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', [WhatsAppCampaignRecipient::PENDING, WhatsAppCampaignRecipient::SENDING])->exists();

        if (! $left) {
            WhatsAppCampaign::whereKey($campaign->id)->where('status', WhatsAppCampaign::SENDING)
                ->update(['status' => WhatsAppCampaign::DONE, 'finished_at' => now(), 'stats' => json_encode(self::counts($campaign->id)), 'updated_at' => now()]);
        }
    }

    /* ═══════════ ما يصل من Meta بعد الإرسال ═══════════ */

    /**
     * ‏‏حالُ رسالةٍ من حملة — بمعرّفها ووصلتها معًا. `false`: ليست من حملة.
     *
     * @param  array<string, mixed>  $status
     */
    public static function applyStatus(WhatsAppConnection $connection, string $wamid, array $status): bool
    {
        $row = WhatsAppCampaignRecipient::where('wamid', $wamid)
            ->whereHas('campaign', fn ($q) => $q->where('whatsapp_connection_id', $connection->id))->first();

        if ($row === null) {
            return false;
        }

        $stamp = isset($status['timestamp']) ? now()->setTimestamp((int) $status['timestamp']) : now();
        $rank = [WhatsAppCampaignRecipient::SENT => 1, WhatsAppCampaignRecipient::DELIVERED => 2, WhatsAppCampaignRecipient::READ => 3];
        $state = (string) ($status['status'] ?? '');

        if ($state === 'failed') {
            $code = (string) ($status['errors'][0]['code'] ?? 'provider_failed');
            $row->forceFill(['status' => WhatsAppCampaignRecipient::FAILED, 'error_code' => mb_substr($code, 0, 60)])->save();

            if ($code === self::META_OPTED_OUT) {
                WhatsAppOptOuts::add((int) $row->business_id, $row->phone, WhatsAppOptOut::META);
            }
        } elseif (isset($rank[$state])) {
            // ‏‏والحالُ لا ترجع إلى الوراء: «قُرئت» قد تصل قبل «وصلت»
            $row->forceFill(array_filter([
                'status' => $rank[$state] > ($rank[$row->status] ?? 99) ? $state : null,
                'delivered_at' => $state === 'delivered' ? ($row->delivered_at ?? $stamp) : null,
                'read_at' => $state === 'read' ? ($row->read_at ?? $stamp) : null,
            ]))->save();
        }

        return true;
    }

    /* ═══════════ ما تعرضه الشاشة ═══════════ */

    /** @return array{pending: int, out: int, delivered: int, read: int, failed: int, skipped: int} */
    public static function counts(int $campaignId): array
    {
        $n = WhatsAppCampaignRecipient::where('campaign_id', $campaignId)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $of = fn (string ...$s) => (int) collect($s)->sum(fn ($x) => (int) ($n[$x] ?? 0));

        return [
            'pending' => $of(WhatsAppCampaignRecipient::PENDING, WhatsAppCampaignRecipient::SENDING),
            'out' => $of(...WhatsAppCampaignRecipient::OUT),
            'delivered' => $of(WhatsAppCampaignRecipient::DELIVERED, WhatsAppCampaignRecipient::READ),
            'read' => $of(WhatsAppCampaignRecipient::READ),
            'failed' => $of(WhatsAppCampaignRecipient::FAILED),
            'skipped' => $of(WhatsAppCampaignRecipient::SKIPPED),
        ];
    }

    /** ‏‏لماذا وقفت — بكلماتٍ يفهمها التاجر */
    public static function stopWhy(?string $reason): ?string
    {
        return match (true) {
            $reason === null, $reason === '', $reason === self::STOP_BY_MERCHANT => null,
            $reason === self::WAITS_DAILY_LIMIT => __('بلغت حدّ اليوم عند واتساب لرقمك — تكمل وحدها حين يتجدد.'),
            $reason === self::STOP_RESTORED => __('استُعيد متجرك من نسخة احتياطية — أنشئ الحملة من جديد.'),
            $reason === self::STOP_MANY_FAILURES => __('فشل أكثر من نصف ما خرج — راجع الأرقام والقالب قبل الاستئناف.'),
            $reason === self::STOP_CLOSED => __('أُغلقت الحملات لمتجرك من إدارة أبعاد.'),
            $reason === '131042' => __('وسيلة الدفع في حسابك عند Meta تحتاج تحديثًا.'),
            in_array($reason, ['368', '131031', '131048', '131064'], true) => __('قيّد واتساب رقمك أو حسابك — راجع جودة الرقم عند Meta.'),
            in_array($reason, ['132000', '132001', '132005', '132007', '132012', '132015', '132016'], true) => __('القالب لم يعد صالحًا عند واتساب — حدّث حال القوالب.'),
            $reason === '131063' => __('رسائل العروض موقوفة لحسابك عند Meta.'),
            default => WhatsAppStatus::reason($reason) ?? __('توقّف الإرسال من واتساب (:code).', ['code' => $reason]),
        };
    }

    /** ‏‏سببُ رقمٍ لم تصله — بكلماتٍ تُقرأ */
    public static function failWhy(string $code): string
    {
        return match ($code) {
            '131026' => __('لا واتساب على الرقم، أو لا يستقبل'),
            '131049', '130472' => __('حدّ واتساب لرسائل العروض التي تصل هذا العميل'),
            self::META_OPTED_OUT, self::OPTED_OUT => __('أوقف رسائل العروض'),
            '130403' => __('حظر رقمك'),
            '131021' => __('رقم المتجر نفسه'),
            'network_error', self::INTERRUPTED => __('انقطع الاتصال — قد تكون وصلت فلم تُعد'),
            default => __('رفضه واتساب (:code)', ['code' => $code]),
        };
    }

    /** @return array<string, mixed> */
    public static function view(Business $business, bool $mayManage): array
    {
        $connection = WhatsAppCampaignTemplates::connection($business);
        $campaigns = WhatsAppCampaign::where('business_id', $business->id)->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        $fails = WhatsAppCampaignRecipient::whereIn('campaign_id', $campaigns->pluck('id'))->whereNotNull('error_code')
            ->selectRaw('campaign_id, error_code, count(*) as n')->groupBy('campaign_id', 'error_code')->get()->groupBy('campaign_id');

        return [
            'may_manage' => $mayManage,
            'obstacle' => self::obstacle($business),
            'number' => $connection?->display_phone_number,
            'store' => (string) $business->name,
            'store_link' => ChannelAutomations::storeLink($business),
            'templates' => WhatsAppCampaignTemplate::where('business_id', $business->id)->orderByDesc('id')->get()->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'language' => $t->language,
                'body' => $t->body,
                'preview' => WhatsAppCampaignTemplates::preview($business, $t->body, $t->language, $t->language === 'en' ? 'Sara' : 'سارة'),
                'footer' => $t->footer,
                'button' => $t->button,
                'opt_out' => WhatsAppCampaignTemplates::OPT_OUT[$t->language] ?? '',
                'status' => $t->status,
                'label' => WhatsAppCampaignTemplates::label($t->status),
                'why' => WhatsAppCampaignTemplates::why($t->rejected_reason),
                // ‏‏قالبٌ من حسابٍ رُبط قبل هذا لا يُرسل من الرقم الحاليّ
                'usable' => $t->status === WhatsAppCampaignTemplate::APPROVED && $connection !== null && $t->waba_id === (string) $connection->waba_id,
                'checked_at' => $t->checked_at?->toIso8601String(),
            ])->values()->all(),
            'campaigns' => $campaigns->map(function (WhatsAppCampaign $c) use ($fails) {
                $live = self::counts($c->id);
                // ‏‏ومتجرٌ استُعيد من نسخة: أرقامُه لا تعود، وما كُتب يومَ انتهت يبقى
                $counts = array_sum($live) === 0 && is_array($c->stats) ? $c->stats : $live;

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'status' => $c->status,
                    'why' => self::stopWhy($c->stop_reason),
                    'total' => $c->total,
                    'counts' => $counts,
                    'body' => (string) ($c->message['body'] ?? ''),
                    'failures' => collect($fails->get($c->id, []))->map(fn ($f) => ['why' => self::failWhy((string) $f->error_code), 'n' => (int) $f->n])
                        ->groupBy('why')->map(fn ($g, $why) => ['why' => (string) $why, 'n' => (int) $g->sum('n')])->sortByDesc('n')->values()->all(),
                    'scheduled_at' => $c->scheduled_at?->toIso8601String(),
                    'started_at' => $c->started_at?->toIso8601String(),
                    'finished_at' => $c->finished_at?->toIso8601String(),
                ];
            })->values()->all(),
            'optouts' => [
                'total' => WhatsAppOptOut::where('business_id', $business->id)->count(),
                'by' => WhatsAppOptOut::where('business_id', $business->id)->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source')->map(fn ($n) => (int) $n)->all(),
            ],
            'branches' => Branch::where('business_id', $business->id)->orderBy('name')->get(['id', 'name'])->all(),
            'limits' => [
                'body' => WhatsAppCampaignTemplates::MAX_BODY,
                'title' => WhatsAppCampaignTemplates::MAX_TITLE,
                'button' => WhatsAppCampaignTemplates::MAX_BUTTON,
                'templates' => WhatsAppCampaignTemplates::MAX_TEMPLATES,
                'days' => WhatsAppCampaignAudience::DAYS,
                'quiet' => WhatsAppCampaignAudience::QUIET,
                'recipients' => WhatsAppCampaignAudience::MAX_RECIPIENTS,
                'per_hour' => self::PER_RUN * 60,
                'quiet_from' => self::QUIET_FROM,
                'quiet_to' => self::QUIET_TO,
                'schedule_days' => self::SCHEDULE_DAYS,
            ],
            'footers' => WhatsAppCampaignTemplates::FOOTER,
            'opt_out_titles' => WhatsAppCampaignTemplates::OPT_OUT,
        ];
    }
}
