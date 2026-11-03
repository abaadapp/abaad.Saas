<?php

namespace App\Support;

use App\Models\User;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * هل تصل الرسائل فعلًا — لا هل يبدو كلُّ شيءٍ مربوطًا.
 *
 * ═══ العطب الذي جاءت منه ═══
 *
 * كانت مراحلُ الربط أربعًا: مفعَّلٌ في المنصّة، ومفعَّلٌ لحسابك، وباقتُك
 * تشمله، والرقمُ جاهز. وكلُّها تقرأ **إعدادًا**، ولا واحدةَ منها تقرأ
 * **نتيجة**.
 *
 * فحين حجبت Meta التطبيق بقيت الأربعُ خضراء و«جاهز» فوقها، وكلُّ رسالةٍ
 * تخرج تُردّ بـ«API access blocked» وتُكتب `failed` في جدولٍ لا تفتحه شاشة.
 * فيبيع التاجر ولا يصل زبونَه شيء، وينظر إلى لوحته فتطمئنه — **ولا يعرف
 * أنّ شيئًا انكسر حتّى يسأل**.
 *
 * وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب: الثاني يُزعج، والأوّل يُنيم.
 *
 * ═══ ومن يُصلح ماذا ═══
 *
 * أعطابُ الإرسال نوعان لا واحد، ولا يُخلطان:
 *
 *   · ما هو على **أبعاد**: تطبيقٌ محجوب، رمزٌ منتهٍ، رقمٌ موقوف. لا يملك
 *     التاجر منها شيئًا، وقولُ «راجع رقمك» له إهدارُ وقتٍ ولومٌ في غير محلّه.
 *   · ما هو على **التاجر**: رقمُ زبونٍ خاطئ، أو رسالةٌ خارج نافذة الساعات
 *     الأربع والعشرين.
 *
 * فيُقرأ رمزُ الخطأ من Meta ويُنسب العطبُ إلى صاحبه.
 */
final class WhatsAppHealth
{
    /** خلال كم ساعةٍ يُقرأ الفشل — وما قبلها تاريخٌ لا حال */
    public const WINDOW_HOURS = 24;

    /**
     * أخطاءٌ لا يملك التاجر إصلاحها — من Meta إلى أبعاد.
     *
     * `0` و`1` و`2` أعطابُ منصّةٍ أو مصادقة. و`3` و`10` و`200` و`299` أذونٌ
     * وحجب. و`131031` حسابٌ موقوف، و`133010` رقمٌ غير مسجَّل.
     */
    private const OURS = ['0', '1', '2', '3', '10', '200', '299', '131031', '133010', '131042'];

    /** خمسُ حالاتٍ للتسليم — لا «يعمل» و«لا يعمل» وحدهما */
    public const UNTESTED = 'untested';

    public const UNCONFIRMED = 'unconfirmed';

    public const OK = 'ok';

    public const PARTIAL = 'partial';

    public const FAILING = 'failing';

    /**
     * ما يُثبت الوصول: واتساب قالت «سُلّمت» أو «قُرئت».
     *
     * و«قُبلت» أو «أُرسلت» وعدٌ لا وصول: الرسالتان اللتان لم تصلا زبونَ
     * RIBBON قبلتهما Meta وأعطتهما معرّفًا، ثمّ ردّتهما بعد ثانية.
     */
    public const ARRIVED = [WhatsAppStatus::DELIVERED, WhatsAppStatus::READ];

    /** وكم عميلًا تُعرض تفاصيلُه — والباقي في سجلّ الرسائل */
    public const SHOWN_CUSTOMERS = 10;

    /**
     * حالُ تسليم الإشعارات في النافذة — ومن لم تصله رسالتُه.
     *
     * ═══ لماذا لا يكفي «فشلٌ واحد = لا يصل» ═══
     *
     * كانت رسالتان لم تصلا رقمَ زبونٍ واحد (131026) تُطفئان مرحلة «رسائلك
     * تصل» عند RIBBON، وكلُّ رقمٍ آخر تصله رسائلُه. فقرأ التاجر 7/8 وعلامةً
     * حمراء على ربطٍ سليم، وراح يبحث عن عطبٍ في الربط وهو في رقم زبون.
     *
     * فيُفرَّق بين ما يقول عن **الربط** وما يقول عن **عميل**:
     *
     *   · لم يصل شيءٌ أصلًا، أو انقطع الوصول بعطبٍ عند أبعاد ولم يعد ← `FAILING`
     *     وهي وحدها تُطفئ المرحلة.
     *   · وصل كثيرٌ ولم يصل بعضُ العملاء ← `PARTIAL`: الربطُ يعمل، ويُقال
     *     مَن لم تصله رسالتُه ولماذا.
     *   · وصل كلُّ شيء، أو وصلت العميلَ رسالةٌ بعد التي فشلت ← `OK`.
     *   · خرجت ولم يؤكَّد تسليمُ شيء ← `UNCONFIRMED`: لا يُقال «يصل».
     *   · لم يخرج شيء ← `UNTESTED`: لا يُقال «يصل» ولا «لا يصل».
     *
     * والترتيبُ بالوقت ثمّ بالمعرّف، لا بترتيب القاعدة: إشعارُ فشلٍ قد يصل
     * من Meta بعد رسالةٍ أحدث منه.
     *
     * @return array<string, mixed>
     */
    public static function delivery(int $businessId): array
    {
        $rows = WhatsAppMessage::where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            // وما لم يُرسَل أصلًا (حدثٌ مطفأ، حصّةٌ نفدت) لم يُجرَّب تسليمُه
            ->whereNotIn('status', WhatsAppStatus::bucket('not_sent'))
            ->orderBy('id')
            ->get(['id', 'event_type', 'template_name', 'recipient_phone', 'status', 'error_code', 'error_message',
                'sent_at', 'delivered_at', 'read_at', 'failed_at', 'created_at']);

        $arrived = $rows->filter(fn ($r) => in_array($r->status, self::ARRIVED, true));
        $failed = $rows->where('status', WhatsAppStatus::FAILED)
            ->sortBy(fn ($r) => [self::at($r)->getTimestamp(), (int) $r->id])->values();
        $last = $failed->last();

        // ونجح شيءٌ لأيّ عميلٍ بعد آخر فشل؟ — فالعطبُ الذي يصيب الربط عبر
        $recovered = $last !== null
            && $arrived->contains(fn ($r) => self::at($r)->gt(self::at($last)));

        /*
         * ومن لم تصله رسالتُه: عميلٌ آخرُ ما جرى له فشل.
         *
         * ورسالتان لعميلٍ واحد عميلٌ واحد لا عطبان، وعميلٌ وصلته رسالةٌ بعد
         * التي فشلت لم يعد ينتظر شيئًا.
         */
        // والمفتاحُ نصٌّ صراحةً: `groupBy` يقلب الرقمَ مفتاحًا عدديًّا فلا يطابقه نصٌّ
        $groups = $failed->groupBy(fn ($r) => 'p'.self::digits($r->recipient_phone))
            ->filter(fn ($list, $key) => ! $arrived->contains(fn ($r) => 'p'.self::digits($r->recipient_phone) === $key
                && self::at($r)->gt(self::at($list->last()))))
            ->sortByDesc(fn ($list) => [self::at($list->last())->getTimestamp(), (int) $list->last()->id]);

        $state = match (true) {
            $rows->isEmpty() => self::UNTESTED,
            $failed->isEmpty() && $arrived->isEmpty() => self::UNCONFIRMED,
            $failed->isNotEmpty() && ($arrived->isEmpty() || ! $recovered && self::isOurs($last->error_code)) => self::FAILING,
            $groups->isNotEmpty() => self::PARTIAL,
            default => self::OK,
        };

        return [
            'state' => $state,
            'window_hours' => self::WINDOW_HOURS,
            'attempts' => $rows->count(),
            'delivered' => $arrived->count(),
            'failed' => $failed->count(),
            'pending' => $rows->whereIn('status', WhatsAppStatus::bucket('pending'))->count(),
            'customers' => $groups->count(),
            'last_error' => $last?->error_message,
            'last_error_code' => $last?->error_code,
            'last_failed_at' => $last ? self::at($last)->toDateTimeString() : null,
            'failures' => $groups->take(self::SHOWN_CUSTOMERS)->map(function ($list) {
                $m = $list->last();

                return [
                    'phone' => self::mask($m->recipient_phone),
                    'count' => $list->count(),
                    'event' => WhatsAppEvent::label((string) $m->event_type),
                    'template' => $m->template_name,
                    'sent_at' => $m->sent_at ? Carbon::parse($m->sent_at)->format('Y-m-d H:i') : null,
                    'failed_at' => self::at($m)->format('Y-m-d H:i'),
                    'code' => $m->error_code,
                    'error' => $m->error_message,
                    'explain' => self::explain($m->error_code),
                    'ours' => self::isOurs($m->error_code),
                    'status_label' => WhatsAppStatus::label(WhatsAppStatus::FAILED),
                ];
            })->values()->all(),
        ];
    }

    /**
     * ردودُ الموظّفين من المحادثات — عدٌّ مستقلٌّ عن الإشعارات.
     *
     * لا تدخل حالَ التسليم: ردٌّ وصل يقول إنّ الردّ يصل، لا إنّ إشعار الطلب
     * يصل. ولا يُعدّ «حُفظ» وصولًا: الوصولُ ما أكّدته واتساب.
     *
     * @return array{attempts:int, delivered:int, failed:int}
     */
    public static function replies(int $businessId): array
    {
        $rows = WhatsAppChatMessage::where('business_id', $businessId)
            ->where('direction', WhatsAppChatMessage::OUT)
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->pluck('status');

        return [
            'attempts' => $rows->count(),
            'delivered' => $rows->filter(fn ($s) => in_array($s, self::ARRIVED, true))->count(),
            'failed' => $rows->filter(fn ($s) => $s === WhatsAppStatus::FAILED)->count(),
        ];
    }

    /**
     * ما تعرضه شاشةُ واتساب لمن يفتحها.
     *
     * والأرقامُ محجوبةٌ دائمًا هنا، وعدُّ الردود لمن يرى المحادثات وحده،
     * ورابطُ السجلّ لمن يفتح قسمه.
     *
     * @return array<string, mixed>
     */
    public static function view(int $businessId, ?User $user): array
    {
        return self::delivery($businessId) + [
            'replies' => WhatsAppInbox::canView($user) ? self::replies($businessId) : null,
            'log_url' => $user?->allows('marketing') ? route('admin.marketing.whatsapp.log', ['filter' => 'failed']) : null,
        ];
    }

    /**
     * رمزُ Meta بكلامٍ يُفهم — أو لا شيء، فيُعرض نصُّها كما وصل.
     *
     * ولا يُجزم بسببٍ لا تقوله Meta: 131026 تعني أنّ الرقم لا يستقبل، ولا
     * تقول لماذا.
     */
    public static function explain(?string $code): ?string
    {
        return match (true) {
            $code === null => null,
            trim($code) === '131026' => __('تعذّر تسليم الرسالة إلى هذا الرقم. قد يكون الرقم لا يستخدم واتساب أو لا يستطيع استقبال الرسائل حاليًا. تحقّق من الرقم أو تواصل مع العميل بطريقة أخرى.'),
            self::isOurs($code) => __('عطبٌ في ربط واتساب لدى أبعاد لا في رقم العميل — ونحن نعالجه.'),
            default => null,
        };
    }

    /** «968•••••968» — يُعرف به العميل ولا يُقرأ رقمُه */
    public static function mask(?string $phone): string
    {
        $d = self::digits($phone);

        return strlen($d) < 7 ? '•••' : substr($d, 0, 3).str_repeat('•', strlen($d) - 6).substr($d, -3);
    }

    private static function digits(?string $phone): string
    {
        return (string) preg_replace('/\D/', '', (string) $phone);
    }

    /** متى انتهى أمرُ الرسالة — فشلُها، أو وصولُها، أو خروجُها */
    private static function at(WhatsAppMessage $m): Carbon
    {
        return Carbon::parse(match (true) {
            $m->status === WhatsAppStatus::FAILED => $m->failed_at ?? $m->created_at,
            in_array($m->status, self::ARRIVED, true) => $m->delivered_at ?? $m->read_at ?? $m->sent_at ?? $m->created_at,
            default => $m->sent_at ?? $m->created_at,
        });
    }

    /** أعلى أبعادَ إصلاحُه أم على التاجر؟ */
    public static function isOurs(?string $code): bool
    {
        return $code !== null && in_array(trim($code), self::OURS, true);
    }

    /**
     * إنذارُ الربط — أو لا شيء.
     *
     * لا يُنذر إلّا حين لا يصل شيء: عميلٌ لا يستقبل رقمُه يُقال في قائمة
     * التسليم، لا جرسَ عطبٍ في الربط.
     *
     * @return array{level:string, text:string, ours:bool, count:int}|null
     */
    public static function alert(int $businessId): ?array
    {
        return self::alertFor(self::delivery($businessId));
    }

    /** @param  array<string, mixed>  $d  من `delivery()` */
    public static function alertFor(array $d): ?array
    {
        if ($d['state'] !== self::FAILING) {
            return null;
        }

        $ours = self::isOurs($d['last_error_code']);
        $e = Str::limit((string) ($d['last_error'] ?? '—'), 90);

        return [
            'level' => 'danger',
            'ours' => $ours,
            'count' => $d['failed'],
            // و«لم تصل أيٌّ» لا تُقال إلّا حين لم يُؤكَّد وصولُ شيء
            'text' => match (true) {
                $ours => __('لم تصل :n رسالة إلى زبائنك — العطب عند أبعاد لا عندك، ونحن نعالجه. (:e)', ['n' => $d['failed'], 'e' => $e]),
                $d['delivered'] === 0 => __('لم تصل أيٌّ من :n رسالة إلى زبائنك — قد يكون الربط بواتساب قائمًا والتسليمُ لا ينجح. راجع أرقام الزبائن. (:e)', ['n' => $d['failed'], 'e' => $e]),
                default => __('لم تصل :n رسالة إلى زبائنك — راجع أرقام الزبائن. (:e)', ['n' => $d['failed'], 'e' => $e]),
            },
        ];
    }

    /** حالُ المنصّة كلِّها — لمدير المنصّة وللنشر */
    public static function platform(): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        $rows = WhatsAppMessage::where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->get(['business_id', 'status', 'error_code', 'error_message']);

        $failed = $rows->where('status', 'failed');

        return [
            'attempts' => $rows->count(),
            'failed' => $failed->count(),
            'shops' => $failed->pluck('business_id')->unique()->count(),
            'ours' => $failed->filter(fn ($r) => self::isOurs($r->error_code))->count(),
            'last_error' => $failed->first()?->error_message,
        ];
    }
}
