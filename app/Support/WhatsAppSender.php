<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSenderAdoption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ‏‏رقمٌ ربطه التاجر يصير رقمَ إرسال متجره وحده — بعد أن تثبت جاهزيّتُه، وبلا يدِ أحد.
 *
 * ═══ ما كان ═══
 *
 * الربطُ لا يبدّل رقم الإرسال: متجرٌ كان على رقم أبعاد يربط رقمه فتبقى رسائلُه تخرج من رقم أبعاد حتى
 * يبدّلها الفريق بيده — والتبديلُ نفسُه كان ينتظر مراجعةَ دليل فوترة.
 *
 * ═══ وما صار ═══
 *
 * كلُّ ربطٍ عبر تطبيق أبعاد يفتح صفًّا (`linked`) يُفحص من الجدول كلَّ دقائق (`run`) حتى تتمّ مراحلُه
 * (`WhatsAppSenderReadiness`)، ثمّ يُبدَّل الوضعُ إلى رقم المتجر تحت قفله. وبعدها يُفحص كلَّ ساعة (`watch`).
 *
 * ═══ وما لا يفعله ═══
 *
 *   · وصلةُ تطبيق التاجر على Meta لا تُمسّ: لا فحصَ ولا قالبَ ولا تبديل (`tracks`).
 *   · ووصلةٌ رُبطت قبل هذا لا صفَّ لها، فلا يتبدّل إرسالُ متجرٍ قائم إلّا بربطٍ جديدٍ منه.
 *   · ورقمٌ تعطّل لا تُنقل رسائلُه إلى رقم أبعاد ولا إلى رقم غيره: تقف بسببها، ويُقال لصاحبها وللفريق
 *     (`problem`). والرجوعُ إلى رقم أبعاد اختيارٌ صريحٌ من شاشة التاجر أو الفريق.
 *   · ومن اختار رقم أبعاد بيده بعد الربط لا يُبدَّل عليه (`chosen`).
 */
final class WhatsAppSender
{
    /** ‏‏مفتاحُ المنصّة — `0` يوقف الاعتمادَ الآليّ في الدقيقة التالية، وبلا صفٍّ يعمل */
    public const KEY = 'whatsapp_auto_sender';

    public const VIA_EMBEDDED = 'embedded';

    /** ‏‏من رابطٍ أرسله فريقُ أبعاد (`WhatsAppClientLinks`) */
    public const VIA_CLIENT_LINK = 'client_link';

    public const VIA_REGISTERED = 'registered';

    public const VIA_MANUAL = 'manual';

    /** ‏‏أيّامُ الخبر في الجرس — أقلُّ من عمر الإخفاء (`Demo::DISMISSAL_DAYS`) */
    public const BELL_DAYS = 7;

    /** ‏‏كم رقمًا في الدورة الواحدة */
    public const BATCH = 10;

    public const WATCH_BATCH = 40;

    /** ‏‏ما يُعاد فحصُه: كلَّ دقيقةٍ أوّلًا، ثمّ أبعدَ فأبعد — بعدد المحاولات */
    private const PACE = [5 => 1, 20 => 5, 100 => 30];

    private const PACE_LAST = 360;

    /** ‏‏وبعد ساعةٍ من الانتظار يُقال لمن يملك الإصلاح */
    private const TELL_AFTER_MINUTES = 60;

    /** ‏‏وعلّةٌ في رقمٍ يُرسل تُقال بعد أن تُقرأ مرّتين — إلّا ما لا يحتمل الشكّ */
    private const TELL_AFTER_SEEN = 2;

    /**
     * ‏‏أخطاءُ إرسالٍ تقول إنّ الرقم أو حسابَه هو العلّة لا رقمُ العميل:
     * الرمزُ (190)، والإذنُ (10 · 200 · 131005)، والدفعُ (131042)، والحسابُ موقوف (131031 · 368)، والرقمُ غير مسجَّل (133010).
     */
    private const SEND_BROKEN = ['190', '10', '200', '131005', '131042', '131031', '368', '133010'];

    public const CLOSED_REPLACED = 'replaced';

    public const CLOSED_UNLINKED = 'unlinked';

    public const CLOSED_CHOSE_SHARED = 'chose_shared';

    public const CLOSED_OWN_APP = 'own_app';

    /** ‏‏علّةٌ لا يزيلها إلّا ربطٌ جديد — فيُرسل لصاحبها رابطُ ربطٍ ولو بقي رقمُه «مربوطًا» (`WhatsAppClientLinks`) */
    private const RELINK = [WhatsAppLinkCheck::INVALID_TOKEN, WhatsAppLinkCheck::WRONG_APP, WhatsAppLinkCheck::MISSING_SCOPES, WhatsAppLinkCheck::WABA_NOT_GRANTED,
        // ‏‏وحسابٌ ليس للتاجر أو انقطع لا يُصلحه إلّا ربطُ حسابه هو
        WhatsAppBillingLock::LINK_LOST, WhatsAppBillingLock::NO_ACCOUNT, WhatsAppOwnApp::BILLED_TO_ABAAD, WhatsAppOwnApp::PARTNER_OWNED, WhatsAppOwnApp::ACCOUNT_TAKEN, WhatsAppOwnApp::ACCOUNT_UNBOUND,
        'webhook_missing', 'send_190', 'send_10', 'send_200', 'send_131005', 'send_133010'];

    /* ═════════════ مفتاحُ المنصّة ═════════════ */

    /** ‏‏أيُعتمد الرقمُ آليًّا؟ — نعم ما لم يوقفه مديرُ المنصّة. وصفٌّ لا يُقرأ لا يُبدَّل معه شيء */
    public static function enabled(): bool
    {
        try {
            return Setting::whereNull('business_id')->where('key', self::KEY)->orderByDesc('id')->value('value') !== '0';
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** ‏‏يوقف الاعتمادَ الآليّ أو يعيده — وما اعتُمد يبقى كما هو، وما ينتظر يُكمل حين يعود */
    public static function setEnabled(bool $on): bool
    {
        return DB::transaction(function () use ($on) {
            $row = Setting::whereNull('business_id')->where('key', self::KEY)->orderByDesc('id')->lockForUpdate()->first();

            if (($row?->value !== '0') === $on) {
                return false;
            }

            $row ? $row->update(['value' => $on ? '1' : '0']) : $row = Setting::create(['business_id' => null, 'key' => self::KEY, 'value' => $on ? '1' : '0']);

            Activity::log('settings', $on ? 'أعاد اعتماد رقم التاجر رقمًا للإرسال تلقائيًّا' : 'أوقف اعتماد رقم التاجر رقمًا للإرسال تلقائيًّا', [
                'subject_type' => Setting::class, 'subject_id' => $row->id,
            ]);

            return true;
        });
    }

    /* ═════════════ متى يبدأ ومتى يتوقّف ═════════════ */

    /** ‏‏أيُنظر في هذه الوصلة؟ — رقمُ متجرٍ عبر تطبيق أبعاد وحده */
    public static function tracks(?WhatsAppConnection $c): bool
    {
        return $c !== null
            && $c->owner_type === WhatsAppMode::OWNER_BUSINESS
            && filled($c->business_id) && filled($c->phone_number_id)
            && ! WhatsAppOwnApp::is($c);
    }

    /**
     * ‏‏اكتمل ربطُ رقم — يُفتح صفُّه، ويُغلق ما كان يُنتظر قبله. ولا يُسقط عطبٌ هنا ربطًا تمّ.
     */
    public static function linked(?WhatsAppConnection $c, string $via, ?User $actor = null): void
    {
        // ‏‏ومن لم تثبت جهةُ رسومه يُفحص ويُقال ما بقي — ولا يُعتمد ولا يُرسل حتى تثبت
        if (! self::tracks($c) || ! $c->isConnected()) {
            return;
        }

        try {
            DB::transaction(function () use ($c, $via, $actor) {
                Business::whereKey($c->business_id)->lockForUpdate()->first();
                self::close((int) $c->business_id, self::CLOSED_REPLACED);

                WhatsAppSenderAdoption::create([
                    'business_id' => $c->business_id,
                    'whatsapp_connection_id' => $c->id,
                    'phone_number_id' => mb_substr((string) $c->phone_number_id, 0, 64),
                    'phone' => mb_substr((string) ($c->display_phone_number ?: $c->phone_number_id), 0, 40),
                    'via' => $via,
                    'started_by' => $actor?->id,
                    'started_by_name' => $actor ? mb_substr((string) $actor->name, 0, 120) : null,
                    'state' => WhatsAppSenderAdoption::CHECKING,
                    'next_check_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** ‏‏ما يُقال لمن ربط الآن ورسائلُه ما زالت من رقم أبعاد — أو لا شيء */
    public static function said(Business $business): ?string
    {
        return self::open((int) $business->id) !== null && WhatsAppFeature::effectiveMode($business) === WhatsAppMode::ABAAD_SHARED
            ? __('نتحقّق الآن من جاهزيّة رقمك، ثمّ تخرج رسائل متجرك منه تلقائيًّا.')
            : null;
    }

    /** ‏‏اختار أحدٌ رقم الإرسال بيده — فلا يُبدَّل عليه آليًّا */
    public static function chosen(int $businessId, string $mode): void
    {
        if ($mode === WhatsAppMode::ABAAD_SHARED) {
            self::close($businessId, self::CLOSED_CHOSE_SHARED);
        }
    }

    public static function unlinked(int $businessId): void
    {
        self::close($businessId, self::CLOSED_UNLINKED);
    }

    /** ‏‏ضُغط «فحص الربط»، أو قالت Meta إنّ شيئًا تبدّل — فلا يُنتظر الموعد */
    public static function soon(int $businessId): void
    {
        WhatsAppSenderAdoption::where('business_id', $businessId)->where('state', WhatsAppSenderAdoption::CHECKING)
            ->update(['next_check_at' => now()]);

        // ‏‏وآخرُ اعتمادٍ وحده — ما قبله تاريخٌ لا يُفحص
        $latest = WhatsAppSenderAdoption::where('business_id', $businessId)->where('state', WhatsAppSenderAdoption::ADOPTED)->max('id');

        if ($latest !== null) {
            WhatsAppSenderAdoption::whereKey($latest)->update(['health_checked_at' => null]);
        }
    }

    /** ‏‏إشعارٌ من Meta على حساب واتساب — قالبٌ اعتُمد، أو حسابٌ تبدّل حالُه */
    public static function accountChanged(string $waba): void
    {
        if ($waba === '') {
            return;
        }

        try {
            WhatsAppConnection::where('waba_id', $waba)->where('owner_type', WhatsAppMode::OWNER_BUSINESS)
                ->whereNotNull('business_id')->get()->filter(fn (WhatsAppConnection $c) => self::tracks($c))
                ->each(fn (WhatsAppConnection $c) => self::soon((int) $c->business_id));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function close(int $businessId, string $why): int
    {
        return WhatsAppSenderAdoption::where('business_id', $businessId)->where('state', WhatsAppSenderAdoption::CHECKING)
            ->update(['state' => WhatsAppSenderAdoption::CLOSED, 'closed_why' => $why, 'closed_at' => now(), 'next_check_at' => null, 'updated_at' => now()]);
    }

    /** ‏‏أرقمُه قيد التحقّق الآن؟ */
    public static function checking(int $businessId): bool
    {
        return self::open($businessId) !== null;
    }

    private static function open(int $businessId): ?WhatsAppSenderAdoption
    {
        return WhatsAppSenderAdoption::where('business_id', $businessId)->where('state', WhatsAppSenderAdoption::CHECKING)->orderByDesc('id')->first();
    }

    /** ‏‏آخرُ اعتمادٍ لهذه الوصلة وهذا الرقم — أو لا شيء */
    private static function adopted(?WhatsAppConnection $c): ?WhatsAppSenderAdoption
    {
        return $c === null ? null : WhatsAppSenderAdoption::where('whatsapp_connection_id', $c->id)
            ->where('phone_number_id', (string) $c->phone_number_id)
            ->where('state', WhatsAppSenderAdoption::ADOPTED)->orderByDesc('id')->first();
    }

    /* ═════════════ الفحصُ والاعتماد ═════════════ */

    /** ‏‏أفي الجدول ما يُفحص الآن؟ — ولا يرمي قبل الهجرة */
    public static function waiting(): bool
    {
        try {
            $on = self::enabled();

            return WhatsAppSenderAdoption::where(fn ($q) => $q->where('state', $on ? WhatsAppSenderAdoption::CHECKING : '')->where('next_check_at', '<=', now()))
                // ‏‏ورقمٌ اعتُمد وقيل إنّ شيئًا تبدّل فيه (`soon`)
                ->orWhere(fn ($q) => $q->where('state', WhatsAppSenderAdoption::ADOPTED)->whereNull('health_checked_at'))
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{checked: int, adopted: int} */
    public static function run(): array
    {
        if (! self::enabled()) {
            return ['checked' => 0, 'adopted' => 0];
        }

        $rows = WhatsAppSenderAdoption::where('state', WhatsAppSenderAdoption::CHECKING)->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')->limit(self::BATCH)->get();
        $adopted = 0;

        foreach ($rows as $row) {
            try {
                $adopted += self::attempt($row) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
                // ‏‏ورقمٌ أسقط فحصُه لا يوقف غيره ولا يُعاد في الدقيقة نفسها
                WhatsAppSenderAdoption::whereKey($row->id)->update(['attempts' => DB::raw('attempts + 1'), 'next_check_at' => now()->addMinutes(30)]);
            }
        }

        return ['checked' => $rows->count(), 'adopted' => $adopted];
    }

    /** ‏‏يفحص رقمًا واحدًا، ويعتمده إن تمّت مراحلُه — `true` اعتُمد */
    public static function attempt(WhatsAppSenderAdoption $row): bool
    {
        $lock = Cache::lock('whatsapp-sender:'.$row->business_id, 180);

        if (! $lock->get()) {
            return false;
        }

        try {
            $row = WhatsAppSenderAdoption::find($row->id);

            if ($row === null || $row->state !== WhatsAppSenderAdoption::CHECKING) {
                return false;
            }

            $business = Business::find($row->business_id);
            $connection = WhatsAppConnections::forBusiness((int) $row->business_id);

            if (($why = self::stale($row, $connection)) !== null) {
                $row->update(['state' => WhatsAppSenderAdoption::CLOSED, 'closed_why' => $why, 'closed_at' => now(), 'next_check_at' => null]);

                return false;
            }

            $result = WhatsAppSenderReadiness::check($business, $connection);

            $row->update([
                'code' => $result['code'], 'checks' => $result['checks'], 'attempts' => $row->attempts + 1, 'checked_at' => now(),
                'next_check_at' => now()->addMinutes(self::pace($row->attempts + 1)),
            ]);

            return $result['ok'] && self::adopt($row);
        } finally {
            $lock->release();
        }
    }

    /** ‏‏صفٌّ لم يعد يصف ربطَ المتجر الحاليّ — لماذا */
    private static function stale(WhatsAppSenderAdoption $row, ?WhatsAppConnection $c): ?string
    {
        return match (true) {
            $c === null, $c->status === WhatsAppConnection::INACTIVE => self::CLOSED_UNLINKED,
            WhatsAppOwnApp::is($c) => self::CLOSED_OWN_APP,
            (int) $c->id !== (int) $row->whatsapp_connection_id, (string) $c->phone_number_id !== $row->phone_number_id => self::CLOSED_REPLACED,
            default => null,
        };
    }

    /**
     * ‏‏يبدّل رقم الإرسال — تحت قفل المتجر، وبعد أن يُعاد السؤالُ عمّا قد يكون تبدّل في أثناء الفحص.
     *
     * فصلٌ أو ربطٌ آخر أو اختيارٌ بيد التاجر في تلك الثواني يغلب؛ ورسالةٌ دخلت طابورَ رقم أبعاد تُترك
     * لتخرج ثمّ يُبدَّل في الدورة التالية.
     */
    private static function adopt(WhatsAppSenderAdoption $row): bool
    {
        $done = DB::transaction(function () use ($row) {
            $business = Business::whereKey($row->business_id)->lockForUpdate()->first();
            $fresh = WhatsAppSenderAdoption::whereKey($row->id)->lockForUpdate()->first();
            $c = WhatsAppConnections::forBusiness((int) $row->business_id);

            if (! self::enabled() || $business === null || $fresh === null || $fresh->state !== WhatsAppSenderAdoption::CHECKING
                || self::stale($fresh, $c) !== null || ! $c->isUsable()
                // ‏‏وجهةُ رسومه ثابتةٌ الآن — لا كما كانت حين بدأ الفحص
                || ! WhatsAppBillingLock::mayAdopt($c)
                || WhatsAppSenderReadiness::gate($business) !== null
                || WhatsAppSenderReadiness::draining((int) $business->id)) {
                return null;
            }

            $from = WhatsAppFeature::effectiveMode($business);

            if ($business->whatsapp_mode !== WhatsAppMode::BUSINESS_OWN) {
                $business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
            }

            $fresh->update([
                'state' => WhatsAppSenderAdoption::ADOPTED, 'from_mode' => $from, 'adopted_at' => now(),
                'code' => null, 'next_check_at' => null, 'health_checked_at' => now(),
            ]);

            return $from;
        });

        if ($done === null) {
            return false;
        }

        Activity::log('settings', $done === WhatsAppMode::BUSINESS_OWN
            ? 'واتساب — تحقّق النظام من رقم المتجر ('.$row->phone.') وهو رقم إرساله'
            : 'واتساب — صار رقم المتجر ('.$row->phone.') رقم إرساله تلقائيًّا: رقم أبعاد ← رقم المتجر', [
                'business_id' => $row->business_id, 'subject_type' => WhatsAppSenderAdoption::class, 'subject_id' => $row->id, 'by' => 'النظام',
            ]);

        return true;
    }

    private static function pace(int $attempts): int
    {
        foreach (self::PACE as $until => $minutes) {
            if ($attempts < $until) {
                return $minutes;
            }
        }

        return self::PACE_LAST;
    }

    /* ═════════════ وبعد الاعتماد: أما زال يعمل؟ ═════════════ */

    public static function watching(): bool
    {
        try {
            return WhatsAppSenderAdoption::where('state', WhatsAppSenderAdoption::ADOPTED)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  bool  $dueOnly  ما قيل إنّه تبدّل وحده — لا دورةُ الساعة
     * @return array{checked: int, broken: int}
     */
    public static function watch(bool $dueOnly = false): array
    {
        $checked = $broken = 0;

        // ‏‏آخرُ اعتمادٍ لكلّ متجر — وما قبله تاريخ
        $latest = WhatsAppSenderAdoption::where('state', WhatsAppSenderAdoption::ADOPTED)->selectRaw('max(id) as id')->groupBy('business_id')->pluck('id');
        $rows = WhatsAppSenderAdoption::whereIn('id', $latest)
            ->where(fn ($q) => $q->whereNull('health_checked_at')->when(! $dueOnly, fn ($q) => $q->orWhere('health_checked_at', '<=', now()->subMinutes(50))))
            ->orderBy('health_checked_at')->limit(self::WATCH_BATCH)->get();

        foreach ($rows as $row) {
            $c = WhatsAppConnections::forBusiness((int) $row->business_id);

            // ‏‏مفصولٌ أو بدّله صاحبُه — لا يُسأل عنه
            if (self::stale($row, $c) !== null) {
                $row->update(['health_checked_at' => now()]);

                continue;
            }

            try {
                $checked++;
                $broken += self::examine($row, $c) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
                WhatsAppSenderAdoption::whereKey($row->id)->update(['health_checked_at' => now()]);
            }
        }

        return ['checked' => $checked, 'broken' => $broken];
    }

    private static function examine(WhatsAppSenderAdoption $row, WhatsAppConnection $c): bool
    {
        $h = WhatsAppSenderReadiness::health($c);

        // ‏‏وما لم يُقرأ لا يثبت عطبًا ولا ينفيه
        if ($h['state'] === 'unknown') {
            $row->update(['health_checked_at' => now()]);

            return false;
        }

        $was = self::told($row);

        if ($h['state'] === 'ok') {
            $row->update(['health_code' => null, 'health_params' => null, 'health_seen' => 0, 'health_since' => null, 'health_checked_at' => now()]);

            if ($was) {
                self::note($row, 'واتساب — عاد رقم المتجر ('.$row->phone.') يعمل');
            }

            return false;
        }

        $same = $row->health_code === $h['code'];

        $row->update([
            'health_code' => $h['code'], 'health_params' => $h['params'],
            'health_seen' => $h['sure'] ? max(self::TELL_AFTER_SEEN, $row->health_seen + 1) : ($same ? min(60000, $row->health_seen + 1) : 1),
            'health_since' => $same ? ($row->health_since ?? now()) : now(), 'health_checked_at' => now(),
        ]);

        if (! $was && self::told($row)) {
            // ‏‏والسجلُّ بالعربيّة أيًّا كانت لغةُ الجدول الذي يجري منه الفحص
            $why = self::inArabic(fn () => self::say((string) $h['code'], $h['params'])['text']);
            self::note($row, 'واتساب — تعطّل رقم المتجر ('.$row->phone.'): '.$why);
        }

        return self::told($row);
    }

    private static function told(WhatsAppSenderAdoption $row): bool
    {
        return $row->health_code !== null && $row->health_seen >= self::TELL_AFTER_SEEN;
    }

    private static function note(WhatsAppSenderAdoption $row, string $what): void
    {
        Activity::log('settings', $what, ['business_id' => $row->business_id, 'subject_type' => WhatsAppSenderAdoption::class, 'subject_id' => $row->id, 'by' => 'النظام']);
    }

    /* ═════════════ ما يُسأل عنه من خارج ═════════════ */

    /** ‏‏ثبتت جاهزيّةُ هذه الوصلة على هذا الرقم؟ — فيُختار رقمُها بيد صاحبه بلا مراجعةٍ ثانية */
    public static function proven(?WhatsAppConnection $c): bool
    {
        return self::tracks($c) && $c->isUsable() && self::adopted($c) !== null;
    }

    /** ‏‏تعطّل رقمُه بما لا يُصلحه إلّا ربطٌ جديد؟ */
    public static function needsRelink(Business $business, ?WhatsAppConnection $c): bool
    {
        return self::tracks($c) && in_array(self::problem($business, $c)['code'] ?? null, self::RELINK, true);
    }

    /** ‏‏أيُختار رقمُ المتجر رقمًا لإرساله؟ — ثبتت جاهزيّتُه آليًّا، أو رُوجع دليلُ فوترته (`readyForOwnMode`) */
    public static function mayOwn(?WhatsAppConnection $c): bool
    {
        // ‏‏ووصلةٌ مقفلة لا يفتحها إلّا إثباتُ جهة رسومها — لا عمودٌ قديم ولا اعتمادٌ سبق
        if (WhatsAppBillingLock::applies($c)) {
            // ‏‏ورقمٌ قيد الفحص لا يقفز أحدٌ فوق مراحله بيده
            return $c->isUsable() && (self::proven($c) || (WhatsAppBillingLock::proven($c) && ! self::checking((int) $c->business_id)));
        }

        return self::proven($c) || WhatsAppOwnApp::readyForOwnMode($c);
    }

    /**
     * ‏‏عطبٌ في رقمٍ تخرج منه رسائلُ متجره — أو لا شيء. من المخزَّن وحده، بلا نداءٍ لـMeta.
     *
     * لكلّ متجرٍ على رقمه، أيًّا كان طريقُ ربطه: وصلةٌ لا تُرسل، أو علّةٌ قرأها الفحص، أو رسائلُ رُدّت
     * لسببٍ في الرقم لا في العميل ولم تخرج بعدها رسالة.
     *
     * @return array{code: string, params: array<string, string>, since: ?string}|null
     */
    public static function problem(Business $business, ?WhatsAppConnection $c = null): ?array
    {
        $c ??= WhatsAppConnections::forBusiness((int) $business->id);

        if ($c === null) {
            return null;
        }

        return self::problems(collect([$c->setRelation('business', $business)]))[$c->id] ?? null;
    }

    /**
     * ‏‏أعطابُ أرقامٍ كثيرة بثلاثة أسئلةٍ للقاعدة لا بثلاثةٍ لكلّ رقم — الجرسُ يُبنى في كلّ صفحة.
     *
     * @param  Collection<int, WhatsAppConnection>  $connections  ومع كلٍّ متجرُه
     * @return array<int, array{code: string, params: array<string, string>, since: ?string}> برقم الوصلة
     */
    private static function problems(Collection $connections): array
    {
        // ‏‏وأحوالُ الفوترة بسؤالٍ واحد — لا بسؤالٍ لكلّ رقم
        $connections->filter(fn (WhatsAppConnection $c) => WhatsAppBillingLock::applies($c) && ! $c->relationLoaded('billings'))
            ->whenNotEmpty(fn ($list) => (new \Illuminate\Database\Eloquent\Collection($list->all()))->load('billings'));

        $own = $connections->filter(fn (WhatsAppConnection $c) => $c->business !== null && $c->status !== WhatsAppConnection::INACTIVE
            && WhatsAppFeature::effectiveMode($c->business) === WhatsAppMode::BUSINESS_OWN);

        if ($own->isEmpty()) {
            return [];
        }

        // ‏‏آخرُ اعتمادٍ لكلّ وصلة — وعلّةُ ما قبله زالت بإعادة الربط
        $rows = WhatsAppSenderAdoption::whereIn('whatsapp_connection_id', $own->pluck('id')->all())
            ->where('state', WhatsAppSenderAdoption::ADOPTED)->orderBy('id')->get()
            ->keyBy(fn (WhatsAppSenderAdoption $r) => $r->whatsapp_connection_id.'|'.$r->phone_number_id);

        $sends = fn (array $ids) => WhatsAppMessage::whereIn('business_id', $ids)->where('direction', 'outbound')
            ->where('source_mode', WhatsAppMode::BUSINESS_OWN)->where('created_at', '>=', now()->subDay());

        $failed = $sends($own->pluck('business_id')->all())->where('status', WhatsAppStatus::FAILED)->whereIn('error_code', self::SEND_BROKEN)
            ->orderBy('id')->get(['id', 'business_id', 'error_code', 'failed_at', 'created_at'])->keyBy('business_id');

        $after = $failed->isEmpty() ? collect() : $sends($failed->keys()->all())->whereIn('status', [WhatsAppStatus::SENT, WhatsAppStatus::DELIVERED, WhatsAppStatus::READ])
            ->selectRaw('business_id, max(id) as last')->groupBy('business_id')->pluck('last', 'business_id');

        $out = [];

        foreach ($own as $c) {
            $row = $rows->get($c->id.'|'.$c->phone_number_id);
            $miss = $failed->get($c->business_id);

            $found = match (true) {
                ! $c->isConnected() => ['code' => 'link_'.WhatsAppLink::state($c), 'params' => [], 'since' => self::stamp($c->last_error_at)],
                // ‏‏وجهةُ رسومٍ لم تثبت أو تبدّلت تُقال باسمها — لا «انقطع الربط»
                ($stop = WhatsAppBillingLock::stop($c)) !== null => ['code' => $stop, 'params' => [], 'since' => self::stamp(WhatsAppBillingLock::row($c)?->stopped_at)],
                ! $c->isUsable() => ['code' => 'link_'.WhatsAppLink::state($c), 'params' => [], 'since' => self::stamp($c->last_error_at)],
                $row !== null && self::told($row) => ['code' => (string) $row->health_code, 'params' => (array) $row->health_params, 'since' => self::stamp($row->health_since)],
                $miss !== null && (int) ($after[$c->business_id] ?? 0) < (int) $miss->id => ['code' => 'send_'.$miss->error_code, 'params' => [], 'since' => self::stamp($miss->failed_at ?? $miss->created_at)],
                default => null,
            };

            if ($found !== null) {
                $out[$c->id] = $found;
            }
        }

        return $out;
    }

    /**
     * ‏‏حالُ رقم الإرسال كما تعرضها شاشةُ التاجر وبطاقةُ المتجر عند الفريق — بلا رمزٍ ولا معرّف.
     *
     * @return array<string, mixed>
     */
    public static function view(Business $business): array
    {
        $c = WhatsAppConnections::forBusiness((int) $business->id);
        $mode = WhatsAppFeature::effectiveMode($business);
        $row = self::open((int) $business->id);
        $problem = self::problem($business, $c);
        $adopted = $row === null ? self::adopted($c) : null;

        return [
            'sends_from' => $mode === WhatsAppMode::BUSINESS_OWN ? 'own' : 'shared',
            // ‏‏وجهةُ رسومه بإحدى خمس — للمتجر المقفل وحده (`WhatsAppBillingLock`)
            'billing' => WhatsAppBillingLock::merchantView($business),
            'phone' => $c !== null && $c->status !== WhatsAppConnection::INACTIVE ? ($c->display_phone_number ?: null) : null,
            // ‏‏واتساب التاجر على تطبيقه لا يُفحص آليًّا ولا يُبدَّل
            'own_app' => WhatsAppOwnApp::holds($c),
            'state' => match (true) {
                $row !== null => 'checking',
                $problem !== null => 'problem',
                $adopted !== null && $mode === WhatsAppMode::BUSINESS_OWN => 'adopted',
                default => 'none',
            },
            'steps' => $row !== null ? self::steps($row) : [],
            'code' => $row?->code,
            'blocking' => match (true) {
                $row === null => null,
                ! self::enabled() => self::say('auto_off'),
                $row->code !== null => self::say($row->code, self::paramsOf($row)),
                default => null,
            },
            'checked_at' => self::stamp($row?->checked_at ?? $adopted?->health_checked_at),
            'adopted_at' => self::stamp($adopted?->adopted_at),
            'problem' => $problem !== null ? self::say($problem['code'], $problem['params']) + ['since' => $problem['since']] : null,
            // ‏‏والرجوعُ إلى رقم أبعاد لمن يتاح له — بضغطةٍ وتأكيد، لا وحده
            'shared_possible' => WhatsAppFeature::canUseShared($business) && WhatsAppFeature::sharedEnabled() && WhatsAppConnections::platform() !== null,
            'history' => self::history((int) $business->id),
        ];
    }

    /**
     * ‏‏سطرٌ واحد لجدول أرقام المتاجر عند مدير المنصّة.
     *
     * @return array{state: 'checking'|'adopted'|'problem'|'own'|'shared', text: string}
     */
    public static function brief(Business $business, WhatsAppConnection $c): array
    {
        $row = self::open((int) $business->id);
        $problem = $row === null ? self::problem($business, $c) : null;

        return match (true) {
            $row !== null => ['state' => 'checking', 'text' => __('قيد التحقّق قبل اعتماده').($row->code !== null ? ': '.self::waitingFor($row)['text'] : '')],
            $problem !== null => ['state' => 'problem', 'text' => self::say($problem['code'], $problem['params'])['text']],
            WhatsAppFeature::effectiveMode($business) !== WhatsAppMode::BUSINESS_OWN => ['state' => 'shared', 'text' => __('رسائله تخرج من رقم أبعاد')],
            self::adopted($c) !== null => ['state' => 'adopted', 'text' => __('رسائله تخرج من رقمه — اعتُمد تلقائيًّا')],
            default => ['state' => 'own', 'text' => __('رسائله تخرج من رقمه')],
        };
    }

    /** @return list<array{key: string, label: string, state: string, note: ?string}> */
    private static function steps(WhatsAppSenderAdoption $row): array
    {
        $checks = collect((array) $row->checks)->keyBy('key');

        return array_map(function (string $key) use ($checks) {
            $c = (array) ($checks->get($key) ?? ['state' => 'unknown', 'code' => null, 'params' => []]);

            return [
                'key' => $key,
                'label' => self::label($key),
                'state' => (string) ($c['state'] ?? 'unknown'),
                'note' => filled($c['code'] ?? null) ? self::say((string) $c['code'], (array) ($c['params'] ?? []))['text'] : null,
            ];
        }, WhatsAppSenderReadiness::STEPS);
    }

    /** @return array<string, string> */
    private static function paramsOf(WhatsAppSenderAdoption $row): array
    {
        return (array) (collect((array) $row->checks)->firstWhere('code', $row->code)['params'] ?? []);
    }

    /** @return list<array{at: ?string, from: ?string, to: string, phone: ?string, by: string}> */
    private static function history(int $businessId): array
    {
        return WhatsAppSenderAdoption::where('business_id', $businessId)->where('state', WhatsAppSenderAdoption::ADOPTED)
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (WhatsAppSenderAdoption $r) => [
                'at' => self::stamp($r->adopted_at),
                'from' => $r->from_mode !== null ? WhatsAppMode::label($r->from_mode) : null,
                'to' => WhatsAppMode::label(WhatsAppMode::BUSINESS_OWN),
                'phone' => $r->phone,
                'by' => __('تلقائيًّا'),
            ])->all();
    }

    /**
     * ‏‏ما يُقال في الجرس — لصاحب المتجر عن متجره، وللفريق عن كلّ متجر.
     *
     * ومن ينتظر يُقال لمن يملك الإصلاح (`$who`): اعتمادُ Meta لقالبٍ لا فعلَ لأحدٍ فيه.
     *
     * @param  list<string>  $who
     * @return array{adopted: Collection<int, WhatsAppSenderAdoption>, waiting: Collection<int, WhatsAppSenderAdoption>, broken: list<array{business: Business, connection: WhatsAppConnection, problem: array{code: string, params: array<string, string>, since: ?string}}>}
     */
    public static function bell(int $limit, ?int $businessId, array $who): array
    {
        // ‏‏والجرسُ يُبنى في كلّ صفحة: صفٌّ لا يُقرأ (رمزٌ تلف، جدولٌ لم يُنشأ بعد) لا يُسقطها
        try {
            return self::news($limit, $businessId, $who);
        } catch (\Throwable $e) {
            report($e);

            return ['adopted' => collect(), 'waiting' => collect(), 'broken' => []];
        }
    }

    /** @param  list<string>  $who */
    private static function news(int $limit, ?int $businessId, array $who): array
    {
        $numbers = WhatsAppConnections::ownNumbers($businessId);
        $problems = self::problems($numbers);

        // ‏‏وخبرُ الاعتماد والانتظار من سؤالٍ واحد
        $rows = WhatsAppSenderAdoption::with('business:id,name')->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('state', WhatsAppSenderAdoption::ADOPTED)->where('adopted_at', '>=', now()->subDays(self::BELL_DAYS)))
                ->orWhere(fn ($q) => $q->where('state', WhatsAppSenderAdoption::CHECKING)->whereNotNull('code')->where('created_at', '<=', now()->subMinutes(self::TELL_AFTER_MINUTES))))
            ->orderByDesc('id')->get();

        return [
            'adopted' => $rows->where('state', WhatsAppSenderAdoption::ADOPTED)->take($limit)->values(),
            'waiting' => $rows->where('state', WhatsAppSenderAdoption::CHECKING)
                ->filter(fn (WhatsAppSenderAdoption $r) => in_array(self::waitingFor($r)['who'], $who, true))->take($limit)->values(),
            'broken' => $numbers->filter(fn (WhatsAppConnection $c) => isset($problems[$c->id]))->take($limit)
                ->map(fn (WhatsAppConnection $c) => ['business' => $c->business, 'connection' => $c, 'problem' => $problems[$c->id]])->values()->all(),
        ];
    }

    /** ‏‏ما ينتظره صفٌّ لم يُعتمد — بكلماته */
    public static function waitingFor(WhatsAppSenderAdoption $row): array
    {
        return self::say((string) $row->code, self::paramsOf($row));
    }

    /* ═════════════ الكلمات ═════════════ */

    public static function label(string $step): string
    {
        return match ($step) {
            WhatsAppSenderReadiness::LINK => __('الرقم مربوط والتفويض سليم'),
            WhatsAppSenderReadiness::BILLING => __('رسوم واتساب على حساب المتجر — مثبتة'),
            WhatsAppSenderReadiness::SEND => __('الرقم متصل ويُرسل'),
            WhatsAppSenderReadiness::RECEIVE => __('رسائل العملاء تصل أبعاد'),
            WhatsAppSenderReadiness::TEMPLATES => __('قوالب الإشعارات معتمدة في حساب المتجر'),
            default => __('لا رسالة في الطريق من رقم أبعاد'),
        };
    }

    /**
     * ‏‏العلّةُ بكلماتها، وما يُفعل، ومن يفعله: `merchant` التاجر، و`abaad` الفريق، و`meta` يُنتظر.
     *
     * @param  array<string, string>  $params
     * @return array{text: string, who: 'merchant'|'abaad'|'meta'}
     */
    public static function say(string $code, array $params = []): array
    {
        [$text, $who] = match (true) {
            $code === 'auto_off' => [__('اعتماد الأرقام تلقائيًّا موقوف من إدارة المنصة الآن.'), 'abaad'],
            $code === 'shop_suspended' => [__('حساب المتجر موقوف أو بانتظار السداد.'), 'merchant'],
            $code === 'platform_off' => [__('واتساب موقوف في المنصة الآن — يفتحه فريق أبعاد.'), 'abaad'],
            $code === 'account_off' => [__('واتساب مطفأ لحساب هذا المتجر — يفتحه فريق أبعاد.'), 'abaad'],
            $code === 'plan' => [__('باقة المتجر لا تشمل واتساب.'), 'merchant'],
            $code === 'own_not_allowed' => [__('ربط رقم المتجر غير مفعّل لحسابه.'), 'abaad'],
            $code === 'link_'.WhatsAppLink::CONNECTING => [__('لم يظهر رقم في الحساب بعد — أعد الربط بعد دقائق.'), 'merchant'],
            $code === 'link_'.WhatsAppLink::NEEDS_REGISTRATION => [__('الرقم ينتظر التفعيل برمز التحقق بخطوتين.'), 'merchant'],
            $code === 'link_'.WhatsAppLink::BILLING_UNPROVEN => [__('الربط بتطبيق المتجر ينتظر إثبات الفوترة.'), 'merchant'],
            $code === 'link_'.WhatsAppLink::EXPIRED => [__('انتهى تفويض واتساب — أعد الربط.'), 'merchant'],
            $code === 'link_'.WhatsAppLink::REAUTH, $code === 'link_'.WhatsAppLink::ERROR => [__('انقطع ربط الرقم — أعد الربط.'), 'merchant'],
            str_starts_with($code, 'link_') => [__('الرقم غير مربوط الآن — أعد الربط.'), 'merchant'],
            WhatsAppBillingLock::owns($code) => array_values(WhatsAppBillingLock::says($code)),
            $code === 'billed_to_abaad' => [__('حساب واتساب المربوط تملكه أبعاد لا المتجر — يربط التاجر حسابه هو.'), 'abaad'],
            $code === 'no_payment_method' => [__('لا وسيلة دفع على حساب واتساب — أضفها من مدير واتساب (WhatsApp Manager) ‹ Overview.'), 'merchant'],
            $code === 'owner_unknown' => [__('لم نعرف بعد من يملك حساب واتساب — نعيد السؤال تلقائيًّا.'), 'meta'],
            $code === 'abaad_account_unknown' => [__('حساب أعمال أبعاد عند Meta غير معروف للنظام — يُضبط من فريق أبعاد.'), 'abaad'],
            $code === 'number_off' => [__('الرقم غير متصل عند واتساب (:status) — راجع حاله في مدير واتساب (WhatsApp Manager).', ['status' => $params['status'] ?? '—']), 'merchant'],
            $code === 'number_blocked' => [filled($params['why'] ?? null)
                ? __('واتساب يمنع الإرسال من هذا الرقم الآن: :why', ['why' => $params['why']])
                : __('واتساب يمنع الإرسال من هذا الرقم الآن — راجع حاله ووسيلة الدفع في مدير واتساب (WhatsApp Manager).'), 'merchant'],
            $code === 'number_payment' => [__('واتساب يمنع الإرسال لمشكلة في وسيلة الدفع — راجعها في مدير واتساب (WhatsApp Manager).'), 'merchant'],
            $code === 'number_unknown' => [__('لم يقل واتساب بعد إنّ الرقم يُرسل — نعيد السؤال تلقائيًّا.'), 'meta'],
            $code === 'webhook_missing' => [__('تطبيق أبعاد غير مشترك في إشعارات حساب المتجر — أعد الربط.'), 'merchant'],
            $code === 'webhook_elsewhere' => [__('إشعارات حساب المتجر موجّهة إلى نظام آخر — لا تصل أبعاد رسائل عملائه.'), 'merchant'],
            $code === 'routed_away' => [__('توجيه المحادثات عند Meta يُسلّم رسائل العملاء لمستجيب آخر — اجعل أبعاد المستجيب الأساسي للخدمة.'), 'merchant'],
            $code === 'app_deaf' => [__('إعداد إشعارات تطبيق أبعاد عند Meta ناقص — يُصلحه فريق أبعاد.'), 'abaad'],
            $code === 'templates_pending' => [__('ننتظر اعتماد واتساب لقوالب الإشعارات — بقي :n من :total.', ['n' => $params['n'] ?? '—', 'total' => $params['total'] ?? '—']), 'meta'],
            $code === 'template_rejected' => [__('رفض واتساب قالب «:template» أو أوقفه في حساب المتجر.', ['template' => $params['template'] ?? '—']), 'abaad'],
            $code === 'template_refused' => [__('لم يقبل واتساب إنشاء قالب «:template»: :why', ['template' => $params['template'] ?? '—', 'why' => $params['why'] ?? '—']), 'abaad'],
            $code === 'template_mismatch' => [__('قالب «:template» في حساب المتجر لا يطابق ما يُرسله أبعاد — احذفه ليُنشأ من جديد.', ['template' => $params['template'] ?? '—']), 'abaad'],
            $code === 'queue_busy' => [__('رسائل في الطريق من رقم أبعاد — يُبدَّل بعد خروجها.'), 'meta'],
            $code === 'send_131042' => [__('واتساب يردّ رسائل المتجر لمشكلة في الدفع — راجع وسيلة الدفع في مدير واتساب (WhatsApp Manager).'), 'merchant'],
            $code === 'send_190' => [__('تفويض واتساب لم يعد صالحًا — أعد الربط.'), 'merchant'],
            $code === 'send_133010' => [__('الرقم غير مفعّل عند واتساب — أعد الربط وفعّله برمزه.'), 'merchant'],
            $code === 'send_131031', $code === 'send_368' => [__('حساب واتساب للمتجر موقوف أو مقيّد عند Meta.'), 'merchant'],
            str_starts_with($code, 'send_') => [__('واتساب يردّ رسائل المتجر لنقص في الأذونات — أعد الربط واقبل كلّ الأذونات.'), 'merchant'],
            $code === 'unreachable' => [__('تعذّر الوصول إلى Meta — نعيد المحاولة تلقائيًّا.'), 'meta'],
            default => [WhatsAppLinkCheck::say($code), 'merchant'],
        };

        return ['text' => $text, 'who' => $who];
    }

    private static function inArabic(callable $say): string
    {
        $was = app()->getLocale();
        app()->setLocale('ar');

        try {
            return (string) $say();
        } finally {
            app()->setLocale($was);
        }
    }

    private static function stamp(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->timezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }
}
