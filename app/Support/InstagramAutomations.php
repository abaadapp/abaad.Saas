<?php

namespace App\Support;

use App\Jobs\AnswerInstagramEvent;
use App\Models\Business;
use App\Models\ChannelAutomation;
use App\Models\ChannelAutomationRun;
use App\Models\InstagramConnection;
use App\Models\InstagramEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ‏‏أتمتةُ إنستقرام — تعليقٌ فيه كلمةٌ يصل صاحبَه ردٌّ خاصّ، ورسالةٌ فيها كلمةٌ يصلها ردُّها.
 *
 * ═══ المحرّكُ واحد ═══
 *
 * المطابقةُ والنصُّ والمتغيّراتُ والحجزُ ومدّةُ الانتظار في `ChannelAutomations` كما لواتساب. وهنا ما
 * يخصّ إنستقرام: قراءةُ الحدث، وحدودُ Meta، ومن نردّ عليه ومن لا نردّ.
 *
 * ═══ أين تقع ═══
 *
 * `InstagramWebhooks::store` يحفظ الحدث ويُجيب Meta فورًا ← `queue` ← مهمّةٌ في الطابور ← `answer`. وما
 * لم تبلغه المهمّة (عاملٌ توقّف، أو حدُّ ساعةٍ امتلأ) تلتقطه `sweep` كلَّ خمس دقائق، في يومٍ واحد.
 *
 * ═══ ومن لا يُردّ عليه ═══
 *
 *  - الحسابُ نفسُه: ردُّه العلنيُّ تعليقٌ يصل كغيره، والردُّ عليه حلقةٌ لا تنتهي.
 *  - رسالةٌ مرتدّة أو محذوفة أو لا تدعمها Meta، أو رسالةُ تجربةٍ من التاجر إلى نفسه.
 *  - زبونٌ حادثه التاجرُ بيده من تطبيق إنستقرام في الساعة الأخيرة — لا تُقطع محادثتُه.
 *  - من ردّت عليه الأتمتةُ نفسُها في مدّة انتظارها.
 *  - حدثٌ وصل ولا أتمتةَ منشورة: لا يُردّ عليه يومَ تُنشر. تعليقاتُ الأسبوع الماضي ليست أسئلةَ اليوم.
 *
 * ولا يُعاد إرسالٌ انقطعت شبكتُه: قد يكون وصل. ويُعاد ما ردّته Meta لامتلاء حدٍّ قبل تنفيذه.
 */
class InstagramAutomations
{
    /** ‏‏ما يشغّل الأتمتة — عمودُ `trigger` */
    public const COMMENT = 'comment';

    public const MESSAGE = 'message';

    /** ‏‏حقولُ الإشعارات التي يُردّ عليها — وغيرُها يُحفظ ويُترك */
    public const ANSWERED = ['comments', 'messages', 'messaging_postbacks'];

    public const WINDOW_HOURS = 24;

    /** ‏‏حدُّ Meta ٧٥٠ ردًّا خاصًّا على التعليقات في الساعة لكلّ حساب — ونقف قبله بهامش */
    public const PRIVATE_REPLIES_PER_HOUR = 700;

    public const MAX_ATTEMPTS = 5;

    public const SWEEP_LIMIT = 200;

    /** ‏‏رسالتُنا ترتدّ إلينا في لحظات — وما ارتدّ قربَ ردٍّ منّا ولم يُعرف معرّفُه لا يُحسب يدَ التاجر */
    private const ECHO_GRACE_SECONDS = 120;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const LATER = 'later';

    public const NO_MATCH = 'no_match';

    public const IGNORED = 'ignored';

    public const OFF = 'off';

    public const TAKEN = 'taken';

    public const SKIP_WINDOW = 'outside_window';

    /** ‏‏تُفتح للمتجر بمفتاحين: إنستقرام مفتوحٌ له، والأتمتةُ مفتوحةٌ له */
    public static function available(?Business $business): bool
    {
        return InstagramLink::available($business) && ChannelAutomationsSwitch::allows((int) $business->id);
    }

    /** ‏‏متجرٌ يعمل الآن: غيرُ موقوف ولا منتهٍ، وباقتُه تشمل التكاملات — كما تُفتح له الشاشة */
    public static function open(?Business $business): bool
    {
        return self::available($business)
            && ! in_array((string) $business->status, ['معطل', 'معطّل', Business::AWAITING_PAYMENT], true)
            && ! Tenancy::locked($business)
            && PlanFeatures::allows($business, 'integrations');
    }

    /**
     * ‏‏حدثٌ حُفظ الآن — إلى الطابور إن كانت له أتمتةٌ تعمل، وإلّا يُطوى.
     *
     * والطابورُ `default` لا `whatsapp`: منشورٌ ينتشر يجلب آلافَ التعليقات، ولا تقف خلفها رسائلُ الطلبات.
     */
    public static function queue(InstagramEvent $event, bool $listening): void
    {
        if (! $listening || ! in_array($event->field, self::ANSWERED, true)) {
            $event->forceFill(['processed_at' => now()])->save();

            return;
        }

        AnswerInstagramEvent::dispatch((int) $event->id)->onQueue('default')->afterCommit();
    }

    /** ‏‏ألهذا المتجر أتمتةٌ منشورة تسمع الآن؟ */
    public static function listening(int $businessId): bool
    {
        return self::open(Business::find($businessId))
            && ChannelAutomation::where('business_id', $businessId)
                ->where('channel', ChannelAutomations::INSTAGRAM)
                ->where('status', ChannelAutomation::PUBLISHED)
                ->exists();
    }

    /**
     * ‏‏حدثٌ واحد — يُردّ عليه أو يُقال لماذا لم يُردّ. ولا يأخذه عاملان: من ختم `processed_at` أخذه.
     */
    public static function answer(InstagramEvent $event): string
    {
        $claimed = InstagramEvent::whereKey($event->id)->whereNull('processed_at')->update(['processed_at' => now()]);

        if ($claimed !== 1) {
            return self::TAKEN;
        }

        try {
            return self::take($event);
        } catch (Throwable $e) {
            report($e);

            return self::FAILED;
        }
    }

    private static function take(InstagramEvent $event): string
    {
        $connection = InstagramConnection::find($event->instagram_connection_id);
        $business = $connection === null ? null : Business::find($connection->business_id);

        if ($connection === null || ! $connection->usable() || ! self::open($business)) {
            return self::OFF;
        }

        $in = self::read($event, $connection);

        if ($in === null) {
            return self::IGNORED;
        }

        $automation = ChannelAutomations::pick((int) $business->id, ChannelAutomations::INSTAGRAM, $in['text'], $in['trigger'], $in['posts']);

        if ($automation === null) {
            return self::NO_MATCH;
        }

        // ‏‏حدُّ الساعة امتلأ: يُؤجَّل الحدثُ ولا يُفقد — ولا تُحسب محاولة
        if ($in['trigger'] === self::COMMENT && self::hourIsFull((int) $business->id)) {
            return self::defer($event, counted: false);
        }

        $lang = self::languageOf($in['text'], $automation);
        $body = ChannelAutomations::render($automation, $business, $lang, known: ['name' => (string) $in['name'], 'last_order' => '']);

        $reason = self::blocked($business, $event, $in['from'], $body);
        $run = $reason === null
            ? ChannelAutomations::reserve($business, $automation, ChannelAutomations::INSTAGRAM, $in['key'], $in['from'])
            : null;

        if ($reason === null && $run === null) {
            $reason = ChannelAutomations::SKIP_COOLDOWN;
        }

        if ($reason !== null) {
            ChannelAutomations::skipped($business, $automation, ChannelAutomations::INSTAGRAM, $in['key'], $in['from'], $reason);

            return self::SKIPPED;
        }

        return self::deliver($business, $connection, $automation, $event, $run, $in, $body);
    }

    /**
     * ‏‏الإرسال — ثمّ الردُّ العلنيّ تحت التعليق بعد أن تصل الخاصّة لا قبلها: لا يُقال «أرسلنا لك» ولم يُرسل.
     *
     * @param  array<string, mixed>  $in
     */
    private static function deliver(Business $business, InstagramConnection $connection, ChannelAutomation $automation, InstagramEvent $event, ChannelAutomationRun $run, array $in, string $body): string
    {
        $sent = $in['trigger'] === self::COMMENT
            ? InstagramMessenger::privateReply($connection, $in['comment'], $body)
            : InstagramMessenger::send($connection, $in['from'], ChannelAutomations::message($automation, $business, $body));

        // ‏‏ردّته Meta لامتلاء حدّ قبل تنفيذه: يُرفع الحجزُ ويُعاد — خمسَ مرّاتٍ على الأكثر
        if (! $sent['ok'] && $sent['later'] && (int) $event->attempts < self::MAX_ATTEMPTS - 1) {
            $run->delete();

            return self::defer($event, counted: true);
        }

        $reason = $sent['code'];

        if ($sent['ok'] && $in['trigger'] === self::COMMENT && ($text = self::publicText($automation, $in)) !== null) {
            $public = InstagramMessenger::publicReply($connection, $in['comment'], $text);
            $reason = $public['ok'] ? null : 'public_'.$public['code'];
        }

        $run->forceFill([
            'status' => $sent['ok'] ? ChannelAutomationRun::SENT : ChannelAutomationRun::FAILED,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 60),
            'message_id' => $sent['message_id'],
        ])->save();

        if ($reason !== null) {
            Log::warning('instagram.automation_failed', ['business_id' => $business->id, 'automation_id' => $automation->id, 'code' => $reason]);
        }

        return $sent['ok'] ? self::SENT : self::FAILED;
    }

    /**
     * ‏‏ما في الحدث — أو `null` لما لا يُردّ عليه أصلًا.
     *
     * @return array{trigger: string, text: string, from: string, name: ?string, key: string, comment: ?string, posts: list<string>}|null
     */
    public static function read(InstagramEvent $event, InstagramConnection $connection): ?array
    {
        $p = (array) $event->payload;

        if ($event->field === 'comments') {
            $v = (array) ($p['value'] ?? []);
            $media = (array) ($v['media'] ?? []);
            $id = (string) ($v['id'] ?? $v['comment_id'] ?? '');
            $from = (string) ($v['from']['id'] ?? '');
            $name = trim((string) ($v['from']['username'] ?? ''));

            if ($id === '' || $from === '' || self::isSelf($connection, $from, $name)) {
                return null;
            }

            return [
                'trigger' => self::COMMENT, 'text' => trim((string) ($v['text'] ?? '')), 'from' => mb_substr($from, 0, 64),
                'name' => $name === '' ? null : $name, 'key' => mb_substr('ig:c:'.$id, 0, 191), 'comment' => $id,
                // ‏‏والإعلانُ بمعرّفه وبمعرّف المنشور الذي صُنع منه — فأتمتةُ المنشور تشمل إعلانَه
                'posts' => array_values(array_filter(array_map('strval', [$media['id'] ?? '', $media['original_media_id'] ?? '']))),
            ];
        }

        $from = (string) ($p['sender']['id'] ?? '');
        $message = (array) ($p['message'] ?? []);
        $postback = (array) ($p['postback'] ?? []);

        if ($from === '' || self::isSelf($connection, $from, null)
            || ($message['is_echo'] ?? false) || ($message['is_self'] ?? false) || ($message['is_deleted'] ?? false) || ($message['is_unsupported'] ?? false)) {
            return null;
        }

        // ‏‏وضغطةُ زرٍّ تصل بعنوانه نصًّا — فتطابق أتمتةً أخرى بكلمتها
        $text = trim((string) ($message['text'] ?? $postback['title'] ?? ''));
        $mid = (string) ($message['mid'] ?? $postback['mid'] ?? '');

        if ($text === '') {
            return null;
        }

        return [
            'trigger' => self::MESSAGE, 'text' => $text, 'from' => mb_substr($from, 0, 64), 'name' => null,
            'key' => mb_substr('ig:m:'.($mid !== '' ? $mid : $event->event_key), 0, 191), 'comment' => null, 'posts' => [],
        ];
    }

    /** ‏‏من كتب أو من كُتب إليه — يُحفظ مع الحدث ليُسأل به */
    public static function counterpart(string $field, array $body): ?string
    {
        $id = match (true) {
            $field === 'message_echoes' => $body['recipient']['id'] ?? null,
            isset($body['sender']) => $body['sender']['id'] ?? null,
            default => $body['value']['from']['id'] ?? null,
        };

        return blank($id) ? null : mb_substr((string) $id, 0, 64);
    }

    private static function isSelf(InstagramConnection $c, string $from, ?string $username): bool
    {
        return in_array($from, array_filter([(string) $c->ig_user_id, (string) $c->ig_scoped_id]), true)
            || (filled($username) && mb_strtolower((string) $username) === mb_strtolower((string) $c->username));
    }

    /** ‏‏ما يُسكت الردَّ عن هذا الزبون الآن — غيرُ مدّة الانتظار */
    private static function blocked(Business $business, InstagramEvent $event, string $from, string $body): ?string
    {
        if ($event->received_at !== null && $event->received_at->lt(now()->subHours(self::WINDOW_HOURS))) {
            return self::SKIP_WINDOW;
        }

        if (trim($body) === '') {
            return ChannelAutomations::SKIP_EMPTY;
        }

        return self::merchantIsTalking((int) $business->id, $from) ? ChannelAutomations::SKIP_STAFF : null;
    }

    /**
     * ‏‏أردّ التاجرُ بيده على هذا الزبون من تطبيق إنستقرام في الساعة الأخيرة؟
     *
     * الرسالةُ المرتدّة تصل لما كتبه هو ولما أرسلناه نحن. فما عُرف معرّفُه في سجلّنا لنا، وما جاء على أثر
     * ردٍّ منّا قبل أن يُكتب معرّفُه لا يُحسب عليه. وما بقي يدُه.
     */
    public static function merchantIsTalking(int $businessId, string $from): bool
    {
        $since = now()->subMinutes(ChannelAutomations::HANDOFF_MINUTES);

        $echoes = InstagramEvent::where('business_id', $businessId)->where('counterpart', $from)->where('field', 'message_echoes')
            ->where('received_at', '>', $since)->orderByDesc('id')->limit(50)->get(['id', 'payload', 'received_at']);

        if ($echoes->isEmpty()) {
            return false;
        }

        $runs = ChannelAutomationRun::where('business_id', $businessId)->where('channel', ChannelAutomations::INSTAGRAM)->where('recipient', $from)
            ->whereIn('status', [ChannelAutomations::SENDING, ChannelAutomationRun::SENT])
            ->where('created_at', '>', $since->copy()->subSeconds(self::ECHO_GRACE_SECONDS))->get(['message_id', 'created_at']);

        $ours = $runs->pluck('message_id')->filter()->all();

        return $echoes->contains(function (InstagramEvent $e) use ($ours, $runs) {
            if (in_array((string) ($e->payload['message']['mid'] ?? ''), $ours, true)) {
                return false;
            }

            return ! $runs->contains(fn ($r) => $r->created_at !== null
                && $e->received_at->gte($r->created_at->copy()->subSeconds(5))
                && $e->received_at->lte($r->created_at->copy()->addSeconds(self::ECHO_GRACE_SECONDS)));
        });
    }

    public static function hourIsFull(int $businessId): bool
    {
        return ChannelAutomationRun::where('business_id', $businessId)->where('channel', ChannelAutomations::INSTAGRAM)
            ->where('source_key', 'like', 'ig:c:%')
            ->whereIn('status', [ChannelAutomations::SENDING, ChannelAutomationRun::SENT, ChannelAutomationRun::FAILED])
            ->where('created_at', '>', now()->subHour())
            ->count() >= self::PRIVATE_REPLIES_PER_HOUR;
    }

    /** ‏‏بلغةِ من كتب: حرفٌ عربيٌّ فعربيّ، وإلّا فالإنجليزيُّ إن كُتب له نصّ */
    private static function languageOf(string $text, ChannelAutomation $automation): string
    {
        $latin = ! preg_match('/\p{Arabic}/u', $text) && preg_match('/\p{Latin}/u', $text);

        return $latin && filled($automation->reply_en) ? 'en' : 'ar';
    }

    /**
     * ‏‏الردُّ العلنيّ — واحدٌ ممّا كتبه التاجر بالدور (بمعرّف التعليق)، فلا يتكرّر السطرُ نفسُه تحت كلّ تعليق.
     *
     * @param  array<string, mixed>  $in
     */
    private static function publicText(ChannelAutomation $automation, array $in): ?string
    {
        $replies = array_values(array_filter(array_map('strval', (array) ($automation->public_replies ?? [])), fn ($r) => trim($r) !== ''));

        if ($replies === []) {
            return null;
        }

        $text = ChannelAutomations::renderPublic($replies[crc32((string) $in['comment']) % count($replies)], $in['name']);

        return $text === '' ? null : $text;
    }

    private static function defer(InstagramEvent $event, bool $counted): string
    {
        // ‏‏بالاستعلام لا بالنموذج: ختمُ الأخذ كُتب باستعلامٍ أيضًا (`answer`)، فالنموذجُ يظنّه فارغًا ولا يكتب
        InstagramEvent::whereKey($event->id)->update(['processed_at' => null, 'attempts' => (int) $event->attempts + ($counted ? 1 : 0)]);

        return self::LATER;
    }

    /**
     * ‏‏ما بقي بلا ردّ — يُعاد، ولحسابٍ امتلأ حدُّه يُترك إلى الجولة التالية. وما مضى عليه يومٌ يُطوى.
     *
     * @return array{answered: int, left: int}
     */
    public static function sweep(): array
    {
        InstagramEvent::whereNull('processed_at')->where('received_at', '<=', now()->subHours(self::WINDOW_HOURS))->update(['processed_at' => now()]);

        $events = InstagramEvent::whereNull('processed_at')
            ->whereIn('field', self::ANSWERED)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            // ‏‏وما وصل الآن لمهمّته في الطابور — لا يُزاحَم
            ->where('received_at', '<', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(self::SWEEP_LIMIT)
            ->get();

        $answered = 0;
        $left = 0;
        $full = [];

        foreach ($events as $event) {
            if (isset($full[$event->instagram_connection_id])) {
                $left++;

                continue;
            }

            if (self::answer($event) === self::LATER) {
                $full[$event->instagram_connection_id] = true;
                $left++;
            } else {
                $answered++;
            }
        }

        return ['answered' => $answered, 'left' => $left];
    }
}
