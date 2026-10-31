<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CrmMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatAttachment;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppChatRead;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * صندوقُ محادثات واتساب — ما يكتبه زبونُ المحلّ إليه، وما يردّ به موظّفوه.
 *
 * ═══ رقمان، وقاعدتا إسناد ═══
 *
 * - **رقمُ المتجر المستقلّ** (`business_own`): الرقمُ رقمُه، والإسنادُ يقين —
 *   الوصلةُ تُعرف من `phone_number_id` في إشعار ميتا، والمتجرُ صاحبُ الوصلة.
 * - **رقمُ أبعاد المشترك** (`abaad_shared`): رقمٌ واحدٌ يُرسل نيابةً عن كلّ
 *   المتاجر، فالمتجرُ لا يُعرف من الرقم. ردٌّ يقتبس رسالةً بعينها يُنسب لمتجر
 *   تلك الرسالة وحده؛ وبلا اقتباسٍ يُنسب لمتجرٍ **واحدٍ** راسل هذا الزبونَ من
 *   الرقم المشترك في آخر `ATTRIBUTION_DAYS` يومًا ولم يراسله غيرُه في آخر
 *   `AMBIGUOUS_DAYS` — وما سوى ذلك لا يُكتب في صندوق أحد (`attribute`). ومن
 *   تحادثه مبيعاتُ أبعاد على الرقم نفسِه لا يُنسب لمتجرٍ بلا اقتباس.
 *
 * ═══ ولكلّ متجرٍ يملك واتساب ═══
 *
 * يُفتح الصندوقُ لكلّ متجرٍ تشمل باقتُه واتساب ولم يُطفأ عنه (`available`) —
 * لا لمتجرٍ بعينه. وخارج نافذة ميتا لا نصَّ حرًّا: قالبُ المتابعة المعتمَد
 * في حساب المتجر وحده (`followUp`).
 *
 * ═══ والردُّ من رقم المحادثة وحده ═══
 *
 * يخرج من الوصلة التي يُرسل بها المتجرُ في وضعه الحاليّ (`WhatsAppConnections::resolve`)
 * **وبشرط** أن يكون رقمُها رقمَ المحادثة. فمحادثةٌ على رقمٍ فُكّ أو تبدّل،
 * أو على رقم أبعاد ومتجرُها صار على رقمه، تُقرأ ولا يُردّ عليها — ولا احتياطَ
 * من رقمٍ آخر أبدًا: الزبونُ يقرأ الرقم فيظنّه رقمَ المحلّ ويردّ عليه.
 *
 * ═══ والمتجرُ من الجلسة ═══
 *
 * لا يُقرأ `business_id` من الطلب في شيءٍ هنا: كلُّ استعلامٍ يبدأ من متجر
 * الجلسة، والمحادثةُ والرسالةُ والمرفقُ تُطلب بمعرّفها **داخل** متجرها.
 */
final class WhatsAppInbox
{
    /** مشاهدةٌ فقط — يقرأ ولا يكتب */
    public const VIEW = 'view';

    /** مشاهدةٌ وردّ */
    public const REPLY = 'reply';

    public const LEVELS = [self::VIEW, self::REPLY];

    /** نافذةُ ميتا للنصّ الحرّ — من آخر رسالةٍ وصلت من الزبون */
    public const WINDOW_HOURS = 24;

    /** على الرقم المشترك: كم يومًا تُقرأ مراسلةُ المتجر للزبون دليلًا على أنّ الواردَ له */
    public const ATTRIBUTION_DAYS = 7;

    /**
     * وزبونٌ راسله متجرٌ آخرُ من الرقم نفسه في هذه المدّة لا يُعرف لمن كتب.
     *
     * فالزبونُ الذي يشتري من متجرين يردّ على إشعارٍ قديمٍ لأحدهما وقد وصله
     * أحدثُ من الآخر — ونافذةُ الأيّام السبعة وحدها تنسبه للأحدث خطأً.
     */
    public const AMBIGUOUS_DAYS = 90;

    public const PER_PAGE = 30;

    public const THREAD_PAGE = 50;

    /** إشعاراتُ الطلبات التي تُعرض في الخيط بين الرسائل — حدٌّ لكلّ صفحة */
    public const NOTICES_PER_PAGE = 200;

    /**
     * قالبُ المتابعة — يُنشئه صاحبُ الرقم في حساب أعماله عند ميتا بهذا الاسم.
     *
     * متغيّرُه الوحيد {{1}} اسمُ المتجر. ويُربط بالحدث وحده يومَ تعتمده ميتا.
     */
    public const FOLLOW_UP_TEMPLATE = 'abaad_inbox_followup';

    public const FOLLOW_UP_EVENT = 'inbox_followup';

    /** حدُّ واتساب للنصّ ٤٠٩٦ */
    public const MAX_BODY = 4000;

    /* ═══ لماذا لم تُحفظ رسالةٌ وصلت رقمَ المتجر — يُكتب على وصلتها بلا نصّها ═══ */

    /** صندوقُ المحادثات غير متاحٍ للمتجر — باقتُه لا تشمل واتساب أو أُطفئ لحسابه (`available`) */
    public const INBOX_CLOSED = 'inbox_closed';

    /** الرقمُ على صفٍّ قديمٍ ليس وصلةَ المتجر الحاليّة */
    public const NOT_CURRENT = 'not_current_connection';

    /** فصل صاحبُ المتجر هذه الوصلة */
    public const LINK_INACTIVE = 'connection_inactive';

    /** إشعارٌ بلا معرّف رسالةٍ أو بلا رقم مُرسِلٍ يُقرأ */
    public const UNREADABLE = 'unreadable_message';

    /** نسخةٌ من رسالةٍ حُفظت من قبل — ميتا تُعيد الإشعار. ليست علّةً ولا حفظًا جديدًا */
    private const DUPLICATE = 'duplicate';

    /* ═══════════════════ الإذن ═══════════════════ */

    /**
     * أمفتوحٌ صندوقُ المحادثات لهذا المتجر؟
     *
     * لكلّ متجرٍ يملك واتساب: باقتُه تشمله، ولم يُطفئه فريقُ أبعاد لحسابه
     * (`whatsapp_enabled`). فالقائمُ والجديد سواء، ولا اسمَ متجرٍ ولا معرّفَه
     * هنا — ولا عمودَ يُفتح لمتجرٍ بعينه.
     *
     * ═══ والفتحُ قراءةٌ لا إرسال ═══
     *
     * ربطٌ لم يكتمل أو انقطع لا يُغلق الصندوق: المحادثاتُ تُقرأ، والسببُ
     * وخطواتُ الإصلاح تُقال فوقها (`WhatsAppFeature::readiness`). والردُّ
     * يُسأل وحده في `check` — من باب الإرسال نفسِه (`WhatsAppFeature::blockReason`).
     */
    public static function available(?Business $business): bool
    {
        // والعمودُ مفتوحٌ افتراضًا في القاعدة — ومتجرٌ في الذاكرة لم يُقرأ عمودُه لم يُطفأ عنه شيء
        // وواتساب يُشترى مرّة: في الباقة، أو إضافة «ربط واتساب برقم المتجر» (`WhatsAppFeature::servicePurchased`)
        return $business !== null
            && (bool) ($business->whatsapp_enabled ?? true)
            && WhatsAppFeature::servicePurchased($business);
    }

    /** مستوى هذا المستخدم: `reply` أو `view` أو لا شيء */
    public static function level(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user->may(Permissions::INBOX_REPLY)) {
            return self::REPLY;
        }

        return $user->may(Permissions::INBOX_VIEW) ? self::VIEW : null;
    }

    public static function canView(?User $user): bool
    {
        return $user !== null && $user->business_id !== null
            && $user->may(Permissions::INBOX_VIEW)
            && self::available($user->business);
    }

    public static function canReply(?User $user): bool
    {
        return self::canView($user) && $user->may(Permissions::INBOX_REPLY);
    }

    /* ═══════════════════ الوارد ═══════════════════ */

    /**
     * رسالةٌ وصلت رقمَ المتجر المستقلّ — يقينُ الإسناد من الوصلة.
     *
     * ويُطلب أن تكون **وصلتَه الحاليّة**: صفٌّ قديمٌ بقي يحمل الرقمَ نفسَه لا
     * يُدخل شيئًا، ووصلةٌ فُصلت (`inactive`) لا تُقرأ — فكَّ صاحبُها الربط.
     *
     * ═══ ولا شرطَ غيرُ ذلك ═══
     *
     * الرقمُ رقمُ المتجر، فكلُّ من كتب إليه زبونُه: لا يُطلب اقتباسٌ
     * (`context.id`)، ولا إشعارٌ سابقٌ خرج إليه، ولا تُقرأ نوافذُ الرقم
     * المشترك (`ATTRIBUTION_DAYS` · `AMBIGUOUS_DAYS`) — تلك لرقمٍ يتشاركه
     * متاجر، وهذا لا يتشاركه أحد.
     *
     * ═══ والوصولُ ليس الحفظ ═══
     *
     * الويب هوك يختم `last_inbound_at` حين تصل رسالةُ زبونٍ إلى الوصلة. وهنا
     * يُختم ما بعدها: رسالةٌ **حُفظت** في الصندوق تختم `last_inbound_saved_at`
     * وتمحو العلّة، ورسالةٌ رُدّت تكتب سببها (`inbound_problem`) بلا نصّها ولا
     * رقم مُرسِلها. ونسخةٌ مكرّرةٌ من رسالةٍ حُفظت لا تختم شيئًا. فلا تقول لوحةُ
     * الصحّة «استُقبلت» عن رسالةٍ وصلت ورُدّت (`WhatsAppLinkHealth::INBOUND`).
     *
     * @return ?string سببُ ألّا تُحفظ (`self::INBOX_CLOSED` …) — أو `null` حُفظت أو كانت مكرّرة
     */
    public static function receiveOwn(WhatsAppConnection $connection, array $message, ?string $profileName = null): ?string
    {
        if ($connection->owner_type !== WhatsAppMode::OWNER_BUSINESS
            || blank($connection->business_id) || blank($connection->phone_number_id)) {
            return null;
        }

        $business = Business::find($connection->business_id);

        $result = match (true) {
            $connection->status === WhatsAppConnection::INACTIVE => self::LINK_INACTIVE,
            ! self::available($business) => self::INBOX_CLOSED,
            WhatsAppConnections::forBusiness($business->id)?->id !== $connection->id => self::NOT_CURRENT,
            default => self::store($business, $connection, WhatsAppMode::BUSINESS_OWN, $message, $profileName),
        };

        if ($result === self::DUPLICATE) {
            return null;
        }

        $connection->forceFill($result === null
            ? ['last_inbound_saved_at' => now(), 'inbound_problem' => null, 'inbound_problem_at' => null]
            : ['inbound_problem' => $result, 'inbound_problem_at' => now()])->save();

        return $result;
    }

    /** ما يقرؤه صاحبُ المتجر ومديرُ المنصّة عن رسالةٍ لم تُحفظ */
    public static function refusal(string $problem): string
    {
        return match ($problem) {
            self::INBOX_CLOSED => __('صندوق محادثات واتساب غير متاحٍ لهذا المتجر — باقته لا تشمل واتساب أو واتساب مطفأ لحسابه'),
            self::NOT_CURRENT => __('وصلت على ربطٍ قديمٍ ليس ربطَ المتجر الحاليّ'),
            self::LINK_INACTIVE => __('الرقم مفصولٌ عن المتجر'),
            default => __('إشعارٌ لم يُقرأ منه معرّفُ الرسالة أو رقمُ المُرسِل'),
        };
    }

    /**
     * رسالةٌ وصلت رقمَ أبعاد المشترك — تُكتب لمتجرٍ واحدٍ ثبت أنّها له، أو لا تُكتب.
     *
     * ═══ وما لا يدخل هنا ═══
     *
     * - رقمُ تاجرٍ عندنا: ذاك يطلب دعمًا، وبابُه `SupportWhatsApp`.
     * - غريبٌ لم نراسله قطّ: ذاك عميلٌ محتمَلٌ لأبعاد، وبابُه `CrmWhatsApp`.
     * - زبونٌ راسله متجران أو أكثر: لا يُعرف لمن كتب — فلا يُعرض لأحد.
     * - ومتجرٌ أُغلق عنه رقمُ أبعاد أو لم يُفتح له الصندوق: لا يُكتب له.
     */
    public static function receiveShared(WhatsAppConnection $connection, array $message, ?string $profileName = null): void
    {
        if ($connection->owner_type !== WhatsAppMode::OWNER_PLATFORM
            || WhatsAppConnections::platform()?->id !== $connection->id) {
            return;
        }

        $from = WhatsAppPhone::normalize($message['from'] ?? null);

        if ($from === null || self::isMerchantPhone($from)) {
            return;
        }

        $business = self::attribute($connection, $from, $message['context']['id'] ?? null);

        if (! self::available($business) || ! WhatsAppFeature::canUseShared($business)) {
            return;
        }

        self::store($business, $connection, WhatsAppMode::ABAAD_SHARED, $message, $profileName);
    }

    /**
     * لمن هذا الزبونُ على الرقم المشترك؟ — متجرٌ واحدٌ أو لا أحد.
     *
     * والدليلُ ممّا كتبناه نحن لا ممّا يكتبه الزبون — ونصُّ الرسالة لا يُقرأ
     * في الإسناد: «طلبي من محلّ الورد» يكتبها من شاء.
     *
     * - **ردٌّ يقتبس رسالة** (`context.id` من ميتا، ومنه زرُّ الردّ السريع في
     *   القالب): لمتجر تلك الرسالة على هذا الرقم وحده. واقتباسٌ لا نعرفه —
     *   رسالةُ دعمٍ أو مبيعاتٍ لأبعاد — لا يُنسب لأحد، ولا يُخمَّن بعده.
     * - **وبلا اقتباس**: متجرٌ راسله فعلًا من هذا الرقم (إشعارٌ خرج إليه، أو
     *   محادثةٌ جاريةٌ في صندوقه) في آخر `ATTRIBUTION_DAYS` يومًا، **ولم**
     *   يراسله متجرٌ غيرُه في آخر `AMBIGUOUS_DAYS`.
     */
    public static function attribute(WhatsAppConnection $line, string $phone, ?string $quoted = null): ?Business
    {
        if (filled($quoted)) {
            $owner = WhatsAppMessage::query()
                ->where('whatsapp_connection_id', $line->id)
                ->where('provider_message_id', (string) $quoted)
                ->where('recipient_phone', $phone)
                ->where('direction', 'outbound')
                ->where('source_mode', WhatsAppMode::ABAAD_SHARED)
                ->value('business_id')
                ?? WhatsAppChatMessage::query()
                    ->where('whatsapp_connection_id', $line->id)
                    ->where('wamid', (string) $quoted)
                    ->whereHas('chat', fn ($q) => $q->where('customer_phone', $phone)
                        ->where('source_mode', WhatsAppMode::ABAAD_SHARED))
                    ->value('business_id');

            return $owner ? Business::find($owner) : null;
        }

        /*
         * ومن تحادثه أبعادُ نفسُها لا يُنسب لمتجرٍ بلا اقتباس.
         *
         * دفترُ المبيعات قد يجري على هذا الرقم (`CrmWhatsApp::shared`)، وزبونُ
         * محلٍّ قد يسأل أبعادَ عن اشتراكٍ وقد وصله إشعارُ طلبٍ من محلّه. فرسالتُه
         * التالية لا يُعرف أهي لأبعاد أم للمحلّ — وكشفُ محادثة مبيعاتٍ لتاجرٍ
         * أسوأ من ألّا يقرأ ردًّا. والمدّةُ مدّةُ الالتباس نفسُها.
         */
        if (self::talksToAbaad($phone, now()->subDays(self::AMBIGUOUS_DAYS))) {
            return null;
        }

        $recent = self::shopsThatWrote($line, $phone, now()->subDays(self::ATTRIBUTION_DAYS));
        $known = self::shopsThatWrote($line, $phone, now()->subDays(self::AMBIGUOUS_DAYS));

        return $recent->count() === 1 && $known->count() === 1
            ? Business::find($recent->first())
            : null;
    }

    /** أفي دفتر مبيعات أبعاد رسالةٌ مع هذا الرقم منذ `$since`؟ — في أيّ اتّجاه */
    private static function talksToAbaad(string $phone, $since): bool
    {
        return CrmMessage::query()
            ->whereHas('lead', fn ($q) => $q->where('phone', $phone))
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /**
     * المتاجرُ التي راسلت هذا الزبونَ من هذا الرقم منذ `$since`.
     *
     * @return Collection<int, int>
     */
    private static function shopsThatWrote(WhatsAppConnection $line, string $phone, $since): Collection
    {
        $notified = WhatsAppMessage::query()
            ->where('whatsapp_connection_id', $line->id)
            ->where('recipient_phone', $phone)
            ->where('direction', 'outbound')
            ->where('source_mode', WhatsAppMode::ABAAD_SHARED)
            ->whereIn('status', [WhatsAppStatus::SENT, WhatsAppStatus::DELIVERED, WhatsAppStatus::READ])
            ->where('created_at', '>=', $since)
            ->distinct()->pluck('business_id');

        $talking = WhatsAppChat::query()
            ->where('phone_number_id', (string) $line->phone_number_id)
            ->where('source_mode', WhatsAppMode::ABAAD_SHARED)
            ->where('customer_phone', $phone)
            ->where('last_message_at', '>=', $since)
            ->pluck('business_id');

        return $notified->merge($talking)->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** رقمُ مستخدمٍ له متجر — والتضييقُ بآخر أربع خانات (انظر `WhatsAppAutoReply::languageOf`) */
    private static function isMerchantPhone(string $phone): bool
    {
        return User::query()
            ->whereNotNull('business_id')->whereNotNull('phone')
            ->where('phone', Search::like(), '%'.mb_substr($phone, -4))
            ->get(['id', 'phone'])
            ->contains(fn ($u) => WhatsAppPhone::normalize($u->phone) === $phone);
    }

    /**
     * كتابةُ الوارد — مرّةً واحدة، في محادثته.
     *
     * والتنزيلُ **قبل** الكتابة: نداءُ شبكةٍ داخل معاملةٍ يُبقي القفلَ حتّى
     * يردّ خادمٌ في بلدٍ آخر. والمعرّفُ الفريد (`wamid`) يحسم الإشعارَ المكرّر.
     *
     * @return ?string `null` حُفظ — و`self::UNREADABLE` لإشعارٍ لا يُقرأ، و`self::DUPLICATE` لنسخةٍ حُفظ أصلُها
     */
    private static function store(Business $business, WhatsAppConnection $connection, string $mode, array $message, ?string $profileName): ?string
    {
        $wamid = (string) ($message['id'] ?? '');
        $from = WhatsAppPhone::normalize($message['from'] ?? null);

        if ($wamid === '' || $from === null) {
            return self::UNREADABLE;
        }

        if (WhatsAppChatMessage::where('wamid', $wamid)->exists()) {
            return self::DUPLICATE;
        }

        [$body, $mediaType, $file] = CrmWhatsApp::inbound($connection, $message);

        // ختمُ ميتا — ولا يُقبل من المستقبل: نافذةُ الردّ تُحسب منه
        $at = isset($message['timestamp']) ? now()->setTimestamp((int) $message['timestamp']) : now();
        $at = $at->isFuture() ? now() : $at;

        $chat = Contention::retry(fn () => WhatsAppChat::firstOrCreate(
            ['business_id' => $business->id, 'phone_number_id' => (string) $connection->phone_number_id, 'customer_phone' => $from],
            ['whatsapp_connection_id' => $connection->id, 'source_mode' => $mode],
        ));

        // ونسختان وصلتا معًا: الثانيةُ تصطدم بالمعرّف الفريد فلا تُكتب — وتُعدّ مكرّرة
        $row = Contention::attempt(function () use ($business, $connection, $chat, $wamid, $body, $mediaType, $file, $at, $profileName) {
            $row = WhatsAppChatMessage::create([
                'business_id' => $business->id,
                'chat_id' => $chat->id,
                'direction' => WhatsAppChatMessage::IN,
                'body' => $body,
                'media_type' => $mediaType,
                'wamid' => $wamid,
                'whatsapp_connection_id' => $connection->id,
                'phone_number_id' => (string) $connection->phone_number_id,
                'status' => WhatsAppChatMessage::RECEIVED,
                'received_at' => $at,
            ]);

            if ($file !== null) {
                self::keep($row, $file['contents'], $file['mime'], $file['name']);
            }

            $chat->forceFill(array_filter([
                'whatsapp_connection_id' => $connection->id,
                'profile_name' => filled($profileName) ? mb_substr((string) $profileName, 0, 150) : null,
                // والنافذةُ لا ترجع إلى الوراء: إشعارٌ قديمٌ وصل متأخّرًا لا يُغلقها
                'last_inbound_at' => $chat->last_inbound_at && $chat->last_inbound_at->gt($at) ? null : $at,
                'last_message_at' => now(),
                'last_preview' => self::preview($body),
                'last_direction' => WhatsAppChatMessage::IN,
                'customer_id' => $chat->customer_id ?? self::customerFor($business->id, (string) $chat->customer_phone),
            ], fn ($v) => $v !== null))->save();

            return $row;
        });

        return $row === null ? self::DUPLICATE : null;
    }

    /**
     * عميلُ هذا الرقم في هذا المتجر — إن كان واحدًا بعينه.
     *
     * عميلان بالرقم نفسِه لا يُختار أحدُهما: ربطُ المحادثة بملفٍّ خاطئ يعرض
     * طلباتِ زبونٍ لغيره.
     */
    public static function customerFor(int $businessId, string $phone): ?int
    {
        $matches = Customer::where('business_id', $businessId)
            ->whereNotNull('phone')->where('phone', Search::like(), '%'.mb_substr($phone, -4))
            ->get(['id', 'phone'])
            ->filter(fn ($c) => WhatsAppPhone::normalize($c->phone) === $phone);

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }

    /**
     * حفظُ ملفٍّ على رسالة — والاسمُ المخزَّن عشوائيّ.
     *
     * اسمُ ملفٍّ يختاره من في الطرف الآخر يُكتب في مسارٍ فيخرج به من المجلّد.
     * والمعروضُ ما سمّاه صاحبُه — عمودًا يُقرأ لا جزءًا من طريق.
     */
    public static function keep(WhatsAppChatMessage $message, string $contents, string $mime, string $name): WhatsAppChatAttachment
    {
        $path = 'whatsapp-inbox/'.$message->business_id.'/'.Str::random(40).'.'.WhatsAppMedia::suffix($mime);

        Storage::disk('local')->put($path, $contents);

        return WhatsAppChatAttachment::create([
            'business_id' => $message->business_id,
            'message_id' => $message->id,
            'disk' => 'local',
            'path' => $path,
            'name' => mb_substr($name, 0, 240),
            'mime' => mb_substr($mime, 0, 100),
            'size' => strlen($contents),
        ]);
    }

    /* ═══════════════════ حالُ ما خرج ═══════════════════ */

    /**
     * إشعارُ حالٍ من ميتا لردٍّ خرج من الصندوق — و`false` إن لم يكن ردًّا منه.
     *
     * يُطابَق بالمعرّف **وبالوصلة معًا**: حمولةٌ موقَّعةٌ على خطٍّ لا تُعدّل
     * صفًّا على خطٍّ آخر. والحالُ لا ترجع إلى الوراء، والفشلُ يُكتب دائمًا.
     */
    public static function applyStatus(WhatsAppConnection $connection, string $wamid, array $status): bool
    {
        $message = WhatsAppChatMessage::where('wamid', $wamid)
            ->where('whatsapp_connection_id', $connection->id)
            ->where('direction', WhatsAppChatMessage::OUT)
            ->first();

        if (! $message) {
            return false;
        }

        $next = match ($status['status'] ?? '') {
            'sent' => [WhatsAppStatus::SENT, 'sent_at'],
            'delivered' => [WhatsAppStatus::DELIVERED, 'delivered_at'],
            'read' => [WhatsAppStatus::READ, 'read_at'],
            'failed' => [WhatsAppStatus::FAILED, 'failed_at'],
            default => null,
        };

        if ($next === null) {
            return true;
        }

        [$state, $column] = $next;
        $stamp = isset($status['timestamp']) ? now()->setTimestamp((int) $status['timestamp']) : now();
        // والختمُ الأوّلُ يبقى — إشعارٌ مُعاد خبرٌ عن التسليم لا تسليمٌ جديد
        $attributes = [$column => $message->{$column} ?? $stamp];

        $rank = [WhatsAppStatus::QUEUED => 0, WhatsAppStatus::SENT => 1, WhatsAppStatus::DELIVERED => 2, WhatsAppStatus::READ => 3];

        if ($state === WhatsAppStatus::FAILED) {
            $error = $status['errors'][0] ?? [];
            $attributes['status'] = WhatsAppStatus::FAILED;
            $attributes['error_code'] = mb_substr((string) ($error['code'] ?? 'provider_failed'), 0, 60);
            $attributes['error_message'] = mb_substr((string) ($error['title'] ?? ($error['message'] ?? '')), 0, 500);
        } elseif (($rank[$state] ?? 0) > ($rank[$message->status] ?? -1)) {
            $attributes['status'] = $state;
        }

        $message->forceFill($attributes)->save();

        return true;
    }

    /* ═══════════════════ الصادر ═══════════════════ */

    /**
     * أيخرج ردٌّ في هذه المحادثة الآن؟ — والوصلةُ التي يخرج منها، أو سببُ المنع.
     *
     * ويُقرأ في الشاشة قبل الكتابة وفي الخادم قبل النداء — من موضعٍ واحد.
     *
     * @return array{line: ?WhatsAppConnection, reason: ?string}
     */
    public static function check(WhatsAppChat $chat): array
    {
        $business = Business::find($chat->business_id);
        $own = $chat->source_mode === WhatsAppMode::BUSINESS_OWN;

        if (! self::available($business)) {
            return self::no(__('صندوق محادثات واتساب غير متاحٍ لهذا المتجر — باقته لا تشمل واتساب أو واتساب مطفأ لحسابه.'));
        }

        if (WhatsAppFeature::blockReason($business) !== null) {
            return self::no(__('واتساب موقوفٌ لهذا المتجر الآن — لا يخرج منه ردّ.'));
        }

        $mode = WhatsAppFeature::effectiveMode($business);

        if ($own && $mode !== WhatsAppMode::BUSINESS_OWN) {
            return self::no(__('هذه المحادثة على رقم متجرك، والإرسالُ الآن من رقم أبعاد — ولا يُردّ عليها من رقمٍ آخر.'));
        }

        if (! $own && $mode !== WhatsAppMode::ABAAD_SHARED) {
            return self::no(__('هذه المحادثة على رقم أبعاد المشترك، والإرسالُ الآن من رقم متجرك — ولا يُردّ عليها من رقمٍ آخر.'));
        }

        $line = WhatsAppConnections::resolve($business);

        if ($line === null) {
            $connection = $own ? WhatsAppConnections::forBusiness($business->id) : WhatsAppConnections::platform();
            $expired = $connection?->token_expires_at !== null && $connection->token_expires_at->isPast();

            return self::no(match (true) {
                $own && $expired => __('انتهت صلاحية ربط رقم متجرك لدى ميتا — أعد الربط من «التطبيقات التكاملية ‹ واتساب». ولا يُرسَل الردّ من رقمٍ آخر.'),
                $own => __('رقم متجرك غير مربوطٍ الآن — ولا يُرسَل الردّ من رقمٍ آخر.'),
                default => __('رقم أبعاد المشترك متوقّفٌ الآن — ولا يُرسَل الردّ من رقمٍ آخر.'),
            });
        }

        if ((string) $line->phone_number_id !== (string) $chat->phone_number_id) {
            return self::no(__('هذه المحادثة على رقمٍ لم يعد مربوطًا — تُقرأ ولا يُردّ عليها من الرقم الجديد.'));
        }

        if (! self::windowOpen($chat)) {
            /*
             * وخارج النافذة لا نصَّ حرًّا — قالبُ المتابعة المعتمَد وحده إن وُجد.
             *
             * على رقم المتجر وحده: القالبُ في حساب أعماله ويُحاسَب هو عليه. ورقمُ
             * أبعاد لا يبدأ عنه محادثةً بقالب — ما يخرج منه إشعاراتُ الطلبات وحدها.
             */
            $template = self::followUpTemplate($chat);

            return self::no(match (true) {
                $template !== null => __('نافذة الردّ مغلقة — مرّت أكثر من :h ساعة على آخر رسالةٍ من العميل. لا يخرج نصٌّ حرّ؛ أرسل قالب المتابعة المعتمَد ليردّ العميل فتُفتح النافذة.', ['h' => self::WINDOW_HOURS]),
                $own => __('نافذة الردّ مغلقة — مرّت أكثر من :h ساعة على آخر رسالةٍ من العميل. لا يخرج بعدها إلّا قالبٌ معتمَد، ولا قالبَ متابعةٍ معتمَدٌ لرقم متجرك بعد (:name).', ['h' => self::WINDOW_HOURS, 'name' => self::FOLLOW_UP_TEMPLATE]),
                default => __('نافذة الردّ مغلقة — مرّت أكثر من :h ساعة على آخر رسالةٍ من العميل. ومن رقم أبعاد المشترك لا يخرج بعدها إلّا إشعاراتُ الطلبات.', ['h' => self::WINDOW_HOURS]),
            }, $template !== null ? $line : null, $template);
        }

        return ['line' => $line, 'reason' => null, 'template' => null];
    }

    /** أمفتوحةٌ نافذةُ ميتا — آخرُ رسالةٍ من الزبون قبل أقلّ من `WINDOW_HOURS`؟ */
    public static function windowOpen(WhatsAppChat $chat): bool
    {
        return $chat->last_inbound_at !== null && $chat->last_inbound_at->gt(now()->subHours(self::WINDOW_HOURS));
    }

    /**
     * سببُ المنع — ومعه الوصلةُ والقالبُ حين يبقى بابُ القالب وحده مفتوحًا.
     *
     * @return array{line: ?WhatsAppConnection, reason: string, template: ?WhatsAppTemplateMapping}
     */
    private static function no(string $reason, ?WhatsAppConnection $line = null, ?WhatsAppTemplateMapping $template = null): array
    {
        return ['line' => $line, 'reason' => $reason, 'template' => $template];
    }

    /**
     * قالبُ المتابعة لهذه المحادثة — معتمَدًا عند ميتا في حساب المتجر، أو لا شيء.
     *
     * يُربط وحده في المزامنة الساعيّة يومَ تعتمد ميتا قالبًا بهذا الاسم في
     * حساب أعمال المتجر (`WhatsAppTemplates::adopt`). وحالٌ مجهولةٌ ليست اعتمادًا:
     * لا يُنادى بقالبٍ لم تقل ميتا إنّه معتمَد.
     */
    public static function followUpTemplate(WhatsAppChat $chat): ?WhatsAppTemplateMapping
    {
        if ($chat->source_mode !== WhatsAppMode::BUSINESS_OWN) {
            return null;
        }

        return WhatsAppTemplateMapping::where('scope_type', WhatsAppMode::OWNER_BUSINESS)
            ->where('business_id', $chat->business_id)
            ->where('event_type', self::FOLLOW_UP_EVENT)
            ->where('enabled', true)
            ->where('meta_status', WhatsAppTemplates::APPROVED)
            ->first();
    }

    /**
     * قالبُ المتابعة يخرج إلى زبونٍ أُغلقت نافذتُه — مرّةً، من رقم محادثته.
     *
     * ومتغيّرُه اسمُ المتجر وحده، ونصُّه ما اعتمدته ميتا بعينه (`snapshot`):
     * قالبٌ لا يُعرف نصُّه أو يطلب ما لا نملؤه لا يُرسَل — فلا يصل الزبونَ
     * قالبٌ بفراغ، ولا يُكتب في الخيط نصٌّ لم يقرأه.
     */
    public static function followUp(WhatsAppChat $chat, User $sender): WhatsAppChatMessage
    {
        ['line' => $line, 'reason' => $reason, 'template' => $template] = self::check($chat);

        if ($line === null || $template === null) {
            throw ValidationException::withMessages(['body' => $reason ?? __('نافذة الردّ مفتوحة — اكتب ردّك نصًّا.')]);
        }

        $business = Business::findOrFail($chat->business_id);
        $variables = [(string) ($business->name ?: __('متجر'))];
        $language = (string) $template->language_code;
        $text = WhatsAppTemplates::snapshot(WhatsAppMode::OWNER_BUSINESS, $business->id, (string) $template->template_name, $language, $variables);

        if ($text === null) {
            throw ValidationException::withMessages(['body' => __('نصّ قالب المتابعة لم يُقرأ من ميتا بعد، أو يطلب ما لا نملؤه — لا يُرسَل.')]);
        }

        $message = WhatsAppChatMessage::create([
            'business_id' => $chat->business_id,
            'chat_id' => $chat->id,
            'direction' => WhatsAppChatMessage::OUT,
            'body' => $text,
            'whatsapp_connection_id' => $line->id,
            'phone_number_id' => (string) $line->phone_number_id,
            'sender_user_id' => $sender->id,
            'sender_name' => mb_substr((string) $sender->name, 0, 150),
            'status' => WhatsAppStatus::QUEUED,
        ]);

        // والنداءُ خارجَ أيّ معاملة — انظر `CrmWhatsApp::send`
        $out = MetaWhatsAppClient::sendTemplate($line, (string) $chat->customer_phone, (string) $template->template_name, $language, $variables);

        $message->forceFill($out['ok']
            ? ['status' => WhatsAppStatus::SENT, 'wamid' => $out['id'], 'sent_at' => now()]
            : [
                'status' => WhatsAppStatus::FAILED,
                'failed_at' => now(),
                'error_code' => mb_substr((string) ($out['code'] ?? 'provider_failed'), 0, 60),
                'error_message' => mb_substr((string) $out['message'], 0, 500),
            ])->save();

        $chat->forceFill([
            'last_message_at' => now(),
            'last_preview' => self::preview($text),
            'last_direction' => WhatsAppChatMessage::OUT,
        ])->save();

        return $message;
    }

    /**
     * ردُّ موظّفٍ يخرج إلى الزبون — النصُّ صفٌّ، وكلُّ ملفٍّ صفّ.
     *
     * ولكلّ جزءٍ صفُّه لأنّ ميتا تعيد لكلّ جزءٍ معرّفًا وحالًا: نصٌّ قُرئ
     * وصورةٌ فشلت يُقالان كلٌّ بحاله، لا «أُرسلت» عن الاثنين.
     *
     * والمرسِلُ من الجلسة، والرقمُ من `check` — لا شيء منهما من الطلب.
     *
     * @param  list<UploadedFile>  $files
     * @return list<WhatsAppChatMessage>
     */
    public static function reply(WhatsAppChat $chat, User $sender, string $body, array $files = []): array
    {
        ['line' => $line, 'reason' => $reason] = self::check($chat);

        if ($reason !== null) {
            throw ValidationException::withMessages(['body' => $reason]);
        }

        $body = mb_substr(trim($body), 0, self::MAX_BODY);
        $sent = [];

        if ($body !== '') {
            $sent[] = self::deliverPart($chat, $line, $sender, $body, null);
        }

        foreach ($files as $file) {
            $sent[] = self::deliverPart($chat, $line, $sender, null, $file);
        }

        return $sent;
    }

    private static function deliverPart(WhatsAppChat $chat, WhatsAppConnection $line, User $sender, ?string $body, ?UploadedFile $file): WhatsAppChatMessage
    {
        $mime = $file ? (string) ($file->getMimeType() ?: $file->getClientMimeType()) : null;

        $message = WhatsAppChatMessage::create([
            'business_id' => $chat->business_id,
            'chat_id' => $chat->id,
            'direction' => WhatsAppChatMessage::OUT,
            'body' => $body,
            'media_type' => $file ? WhatsAppMedia::kind($mime) : null,
            'whatsapp_connection_id' => $line->id,
            'phone_number_id' => (string) $line->phone_number_id,
            'sender_user_id' => $sender->id,
            'sender_name' => mb_substr((string) $sender->name, 0, 150),
            'status' => WhatsAppStatus::QUEUED,
        ]);

        $files = [];

        if ($file !== null) {
            $kept = self::keep($message, (string) file_get_contents($file->getRealPath()), (string) $mime, (string) $file->getClientOriginalName());
            $files[] = ['disk' => $kept->disk, 'path' => $kept->path, 'mime' => $kept->mime, 'name' => $kept->name];
        }

        // والنداءُ خارجَ أيّ معاملة — انظر `CrmWhatsApp::send`
        $out = WhatsAppMedia::deliver($line, (string) $chat->customer_phone, $body, $files);

        $message->forceFill($out['state'] === 'sent'
            ? ['status' => WhatsAppStatus::SENT, 'wamid' => $out['id'], 'sent_at' => now()]
            : [
                'status' => WhatsAppStatus::FAILED,
                'failed_at' => now(),
                'error_code' => 'provider_failed',
                'error_message' => mb_substr((string) $out['message'], 0, 500),
            ])->save();

        $chat->forceFill([
            'last_message_at' => now(),
            'last_preview' => self::preview($body ?? ($file ? '['.$file->getClientOriginalName().']' : '')),
            'last_direction' => WhatsAppChatMessage::OUT,
        ])->save();

        return $message;
    }

    /* ═══════════════════ القراءة ═══════════════════ */

    /** محادثةٌ من متجر الجلسة بمعرّفها — وما سواها 404 */
    public static function find(Business $business, int $chatId): ?WhatsAppChat
    {
        return WhatsAppChat::where('business_id', $business->id)->whereKey($chatId)->first();
    }

    /**
     * قائمةُ المحادثات — آخرُ نشاطٍ أوّلًا، وغيرُ المقروء لهذا الموظّف وحده.
     *
     * ═══ ومن لم يكتب بعدُ في القائمة أيضًا ═══
     *
     * كما في واتساب ويب: زبونٌ وصلته إشعاراتُ طلباته ولم يكتب حرفًا له
     * محادثةٌ في القائمة — صفوفُ دفتر الإرسال نفسُها (`whatsapp_messages`)
     * مجموعةً برقمه وبالرقم الذي خرجت منه، بلا صفٍّ يُكتب لها. وتُفتح من
     * `open` يومَ يُضغط عليها. وما لم يخرج إلى واتساب (`skipped`…) ليس منها.
     *
     * والبحثُ باسم العميل (ملفُّه في هذا المتجر أو اسمُه على واتساب) أو برقمه.
     */
    public static function page(Business $business, User $user, ?string $q): LengthAwarePaginator
    {
        $q = trim(str_replace('%', '', (string) $q));
        $like = Search::like();
        $digits = $q === '' ? '' : (string) preg_replace('/\D+/', '', Digits::western($q));
        $customers = $q === '' ? collect() : Customer::where('business_id', $business->id)
            ->where('name', $like, '%'.$q.'%')->pluck('id');

        $chats = WhatsAppChat::query()
            ->where('business_id', $business->id)
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($like, $q, $digits, $customers) {
                $w->where('profile_name', $like, '%'.$q.'%');

                if ($digits !== '') {
                    $w->orWhere('customer_phone', $like, '%'.$digits.'%');
                }

                if ($customers->isNotEmpty()) {
                    $w->orWhereIn('customer_id', $customers);
                }
            }))
            /*
             * ومحادثةٌ فُتحت من إشعارٍ ولم يُكتب فيها تُرتَّب بآخر ما خرج إلى زبونها —
             * لا تسقط إلى أسفل القائمة ولا تعلوها (`open` لا يختم `last_message_at`).
             */
            ->selectRaw('id as chat_id, null as message_id, coalesce(last_message_at, (select max(n.created_at) from whatsapp_messages n
                where n.business_id = whatsapp_chats.business_id and n.direction = ? and n.recipient_phone = whatsapp_chats.customer_phone
                and n.sender_phone_number_id = whatsapp_chats.phone_number_id and n.status in ('.implode(',', array_fill(0, count(WhatsAppConversations::SHOWN), '?')).'))) as last_at',
                ['outbound', ...WhatsAppConversations::SHOWN]);

        $quiet = self::quietScope($business)
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($like, $digits, $customers) {
                // لا اسمَ واتساب لمن لم يكتب — رقمُه أو ملفُّه وحده
                $w->whereRaw('1 = 0');

                if ($digits !== '') {
                    $w->orWhere('m.recipient_phone', $like, '%'.$digits.'%');
                }

                if ($customers->isNotEmpty()) {
                    $w->orWhereIn('m.customer_id', $customers);
                }
            }))
            ->groupBy('m.recipient_phone', 'm.sender_phone_number_id', 'm.source_mode')
            ->selectRaw('null as chat_id, max(m.id) as message_id, max(m.created_at) as last_at');

        $page = DB::query()->fromSub($chats->unionAll($quiet), 'inbox')
            ->orderByRaw('case when last_at is null then 1 else 0 end')
            ->orderByDesc('last_at')->orderByDesc('chat_id')->orderByDesc('message_id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $rows = collect($page->items());
        $chatIds = $rows->pluck('chat_id')->filter()->map(fn ($id) => (int) $id);
        $messageIds = $rows->pluck('message_id')->filter()->map(fn ($id) => (int) $id);

        $found = WhatsAppChat::where('business_id', $business->id)->whereIn('id', $chatIds)
            ->select('whatsapp_chats.*')->selectSub(self::unreadSql($user), 'unread')
            ->get()->keyBy('id');
        $notices = WhatsAppMessage::where('business_id', $business->id)->whereIn('id', $messageIds)
            ->with(['order:id,number', 'customerInvoice:id,number'])->get()->keyBy('id');

        $names = Customer::where('business_id', $business->id)
            ->whereIn('id', $found->pluck('customer_id')->merge($notices->pluck('customer_id'))->filter()->unique())
            ->pluck('name', 'id');

        $page->setCollection($rows->map(function ($row) use ($found, $notices, $names) {
            if ($row->chat_id !== null && ($c = $found[(int) $row->chat_id] ?? null)) {
                return [
                    'id' => $c->id,
                    'key' => 'c'.$c->id,
                    'url' => null,
                    'name' => $names[$c->customer_id] ?? $c->profile_name,
                    'phone' => (string) $c->customer_phone,
                    'at' => $row->last_at ? Carbon::parse($row->last_at)->format('Y-m-d H:i') : null,
                    'preview' => (string) $c->last_preview,
                    'direction' => $c->last_direction,
                    'unread' => (int) $c->unread,
                    'source' => $c->source_mode,
                ];
            }

            $m = $notices[(int) $row->message_id] ?? null;

            return $m === null ? null : [
                'id' => null,
                'key' => 'm'.$m->id,
                'url' => route('admin.whatsappInbox.open', $m->id),
                'name' => $names[$m->customer_id] ?? null,
                'phone' => (string) $m->recipient_phone,
                'at' => optional($m->created_at)->format('Y-m-d H:i'),
                'preview' => self::noticePreview($m),
                'direction' => WhatsAppChatMessage::OUT,
                'unread' => 0,
                'source' => $m->source_mode,
            ];
        })->filter()->values());

        return $page;
    }

    /**
     * ما خرج إلى زبونٍ ولا محادثةَ له على الرقم الذي خرج منه — صفوفُ الإرسال وحدها.
     *
     * والرقمُ من `sender_phone_number_id`: الوصلةُ صفٌّ يُحدَّث بالرقم الجديد،
     * فمعرّفُها وحده يخلط رقمين. وصفٌّ قديمٌ بلا رقمٍ يبقى في السجلّ لا هنا.
     */
    private static function quietScope(Business $business): \Illuminate\Database\Query\Builder
    {
        return DB::table('whatsapp_messages as m')
            ->where('m.business_id', $business->id)
            ->where('m.direction', 'outbound')
            ->whereNotNull('m.recipient_phone')
            ->whereNotNull('m.sender_phone_number_id')
            ->whereIn('m.status', WhatsAppConversations::SHOWN)
            ->whereNotExists(fn ($w) => $w->selectRaw('1')->from('whatsapp_chats as c')
                ->whereColumn('c.business_id', 'm.business_id')
                ->whereColumn('c.customer_phone', 'm.recipient_phone')
                ->whereColumn('c.phone_number_id', 'm.sender_phone_number_id'));
    }

    /**
     * محادثةُ زبونٍ من رسالةٍ خرجت إليه — تُفتح ولو لم يكتب بعد.
     *
     * والرسالةُ تُطلب **داخل** متجر الجلسة وبين ما خرج إلى واتساب: معرّفٌ من
     * متجرٍ آخر أو رسالةٌ لم تخرج لا تفتح شيئًا (`null` ← 404). والمحادثةُ
     * على رقمها الذي خرجت منه — رقمٌ سبق تُقرأ ولا يُردّ عليها (`check`).
     */
    public static function open(Business $business, int $messageId): ?WhatsAppChat
    {
        $m = WhatsAppMessage::where('business_id', $business->id)
            ->where('direction', 'outbound')
            ->whereNotNull('recipient_phone')->whereNotNull('sender_phone_number_id')
            ->whereIn('status', WhatsAppConversations::SHOWN)
            ->whereKey($messageId)->first();

        if ($m === null) {
            return null;
        }

        $phone = WhatsAppPhone::normalize($m->recipient_phone) ?? (string) $m->recipient_phone;

        return Contention::retry(fn () => WhatsAppChat::firstOrCreate(
            ['business_id' => $business->id, 'phone_number_id' => (string) $m->sender_phone_number_id, 'customer_phone' => $phone],
            [
                'whatsapp_connection_id' => $m->whatsapp_connection_id,
                'source_mode' => $m->source_mode,
                // ولا ختمَ نشاط: محادثةٌ فُتحت للقراءة ليست مراسلةً تُقرأ دليلَ إسناد (`shopsThatWrote`)
                'last_preview' => self::noticePreview($m),
                'last_direction' => WhatsAppChatMessage::OUT,
                'customer_id' => $m->customer_id ?? self::customerFor($business->id, $phone),
            ],
        ));
    }

    /** واردٌ لم يقرأه هذا الموظّف في محادثةٍ — استعلامٌ فرعيّ للقائمة */
    private static function unreadSql(User $user): Builder
    {
        return WhatsAppChatMessage::query()
            ->selectRaw('count(*)')
            ->whereColumn('whatsapp_chat_messages.chat_id', 'whatsapp_chats.id')
            ->where('whatsapp_chat_messages.direction', WhatsAppChatMessage::IN)
            ->whereRaw('whatsapp_chat_messages.id > coalesce((select r.last_read_message_id from whatsapp_chat_reads r
                where r.chat_id = whatsapp_chat_messages.chat_id and r.user_id = ?), 0)', [$user->id]);
    }

    /** كلُّ ما لم يقرأه هذا الموظّف — شارةُ الشريط */
    public static function unreadTotal(User $user): int
    {
        if ($user->business_id === null) {
            return 0;
        }

        return WhatsAppChatMessage::query()
            ->where('whatsapp_chat_messages.business_id', $user->business_id)
            ->where('whatsapp_chat_messages.direction', WhatsAppChatMessage::IN)
            ->whereRaw('whatsapp_chat_messages.id > coalesce((select r.last_read_message_id from whatsapp_chat_reads r
                where r.chat_id = whatsapp_chat_messages.chat_id and r.user_id = ?), 0)', [$user->id])
            ->count();
    }

    /**
     * ختمُ آخر تغييرٍ في صندوق هذا المتجر — تقرؤه الشاشةُ لتعرف أنّ جديدًا وصل.
     *
     * رسالةٌ جديدة تُغيّر أكبرَ معرّف، وحالٌ تبدّلت تُغيّر آخرَ تحديث.
     */
    public static function stamp(Business $business): string
    {
        $row = WhatsAppChatMessage::where('business_id', $business->id)
            ->selectRaw('max(id) as id, max(updated_at) as at')->first();

        return ((int) ($row?->id ?? 0)).'|'.((string) ($row?->at ?? ''));
    }

    public static function markRead(WhatsAppChat $chat, User $user): void
    {
        $last = (int) WhatsAppChatMessage::where('chat_id', $chat->id)->max('id');

        Contention::retry(fn () => WhatsAppChatRead::updateOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $user->id],
            ['business_id' => $chat->business_id, 'last_read_message_id' => $last],
        ));
    }

    /**
     * رأسُ المحادثة: من هو، وملفُّه وطلباتُه إن ثبت أنّه عميلٌ واحدٌ بعينه.
     *
     * والروابطُ لمن يملك قسمها: موظّفُ المحادثات لا يُمنح العملاءَ والمبيعات
     * بها — يرى اسمَ العميل، ولا يفتح ملفَّه إلّا إن كان له.
     *
     * @return array<string, mixed>
     */
    public static function contact(WhatsAppChat $chat, User $viewer): array
    {
        $customer = $chat->customer_id
            ? Customer::where('business_id', $chat->business_id)->whereKey($chat->customer_id)->first(['id', 'name'])
            : null;

        $orders = $customer && $viewer->allows('orders')
            ? Order::where('business_id', $chat->business_id)->where('customer_id', $customer->id)
                ->orderByDesc('id')->limit(3)->get(['id', 'number', 'total', 'status'])
                ->map(fn (Order $o) => [
                    'number' => (string) $o->number,
                    'total' => (float) $o->total,
                    'status' => (string) $o->status,
                    'url' => route('admin.orders.show', $o->number),
                ])->all()
            : [];

        $connection = WhatsAppConnection::find($chat->whatsapp_connection_id);

        return [
            'id' => $chat->id,
            'name' => $customer?->name ?? $chat->profile_name,
            'phone' => (string) $chat->customer_phone,
            'profile_name' => $chat->profile_name,
            'customer' => $customer ? [
                'name' => (string) $customer->name,
                'url' => $viewer->allows('customers') ? route('admin.customers.show', $customer->id) : null,
            ] : null,
            'orders' => $orders,
            'source' => $chat->source_mode,
            // الرقمُ المعروض وحده — لا معرّفُ الوصلة ولا رمزُها
            'line_number' => $connection && (string) $connection->phone_number_id === (string) $chat->phone_number_id
                ? $connection->display_phone_number : null,
            'window_ends_at' => $chat->last_inbound_at?->copy()->addHours(self::WINDOW_HOURS)->format('Y-m-d H:i'),
        ];
    }

    /**
     * رسائلُ المحادثة — الأحدثُ خمسون، ومعها إشعاراتُ الطلبات في الفترة نفسِها.
     *
     * والإشعاراتُ من دفتر الإرسال (`whatsapp_messages`) لهذا الزبون ومن رقم
     * المحادثة نفسِه — تُقرأ ولا تُنسخ. وحدودُ فترتها من أطراف الصفحة: الأقدمُ
     * ضمنًا والأحدثُ حدًّا، فلا يتكرّر إشعارٌ بين صفحتين ولا يسقط.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool, before: ?int}
     */
    public static function thread(WhatsAppChat $chat, ?int $before = null): array
    {
        $rows = WhatsAppChatMessage::where('business_id', $chat->business_id)
            ->where('chat_id', $chat->id)
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->with('attachments')
            ->orderByDesc('id')
            ->limit(self::THREAD_PAGE + 1)
            ->get();

        $more = $rows->count() > self::THREAD_PAGE;
        $rows = $rows->take(self::THREAD_PAGE);

        $upper = $before !== null
            ? WhatsAppChatMessage::where('chat_id', $chat->id)->whereKey($before)->value('created_at')
            : null;
        $lower = $more ? $rows->last()->created_at : null;

        /*
         * والردُّ الآليّ لا يُحفظ نصُّه (`WhatsAppAutoReply::send` يُرسل ويختم
         * الحال وحدها) — فلا يُعرض في الخيط وكأنّه منه. يُقال تحت رسالة الزبون
         * إنّه خرج إليه ردٌّ آليّ، ولا يُخترع له نصّ.
         */
        $autoReplied = WhatsAppInboundMessage::where('business_id', $chat->business_id)
            ->whereIn('wamid', $rows->where('direction', WhatsAppChatMessage::IN)->pluck('wamid')->filter()->values())
            ->where('reply_status', WhatsAppInboundMessage::SENT)
            ->pluck('wamid')->flip();

        $items = $rows->map(fn (WhatsAppChatMessage $m) => self::bubble($m, $autoReplied->has((string) $m->wamid)))
            ->concat(self::notices($chat, $lower, $upper))
            ->sortBy([['ts', 'asc'], ['order', 'asc']])
            ->values();

        return [
            'messages' => $items->all(),
            'has_more' => $more,
            'before' => $more ? (int) $rows->last()->id : null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function notices(WhatsAppChat $chat, $lower, $upper): Collection
    {
        $query = WhatsAppMessage::query()
            ->where('business_id', $chat->business_id)
            ->where('direction', 'outbound')
            ->where('recipient_phone', $chat->customer_phone)
            ->whereIn('status', WhatsAppConversations::SHOWN);

        if ($chat->source_mode === WhatsAppMode::BUSINESS_OWN) {
            $query->where('source_mode', WhatsAppMode::BUSINESS_OWN)
                ->where('sender_phone_number_id', $chat->phone_number_id);
        } else {
            $query->where('source_mode', WhatsAppMode::ABAAD_SHARED)
                ->where('whatsapp_connection_id', $chat->whatsapp_connection_id);
        }

        return $query
            ->when($lower !== null, fn ($q) => $q->where('created_at', '>=', $lower))
            ->when($upper !== null, fn ($q) => $q->where('created_at', '<', $upper))
            ->with(['order:id,number', 'customerInvoice:id,number'])
            ->orderByDesc('id')
            ->limit(self::NOTICES_PER_PAGE)
            ->get()
            ->map(fn (WhatsAppMessage $m) => [
                'kind' => 'notice',
                'key' => 'n'.$m->id,
                'ts' => (int) optional($m->created_at)->getTimestamp(),
                'order' => 0,
            ] + WhatsAppConversations::bubble($m));
    }

    /** @return array<string, mixed> */
    public static function bubble(WhatsAppChatMessage $m, bool $autoReplied = false): array
    {
        $at = $m->received_at ?? $m->created_at;

        return [
            'kind' => $m->direction === WhatsAppChatMessage::IN ? 'in' : 'out',
            'key' => 'm'.$m->id,
            'id' => $m->id,
            'ts' => (int) optional($m->created_at)->getTimestamp(),
            'order' => 1,
            'at' => optional($at)->format('Y-m-d H:i'),
            'time' => optional($at)->format('H:i'),
            'body' => $m->body,
            'media_type' => $m->media_type,
            // اسمُ من ردّ كما كُتب ساعتَها — لا يتبدّل إن غيّر اسمَه بعدها
            'sender' => $m->direction === WhatsAppChatMessage::OUT ? $m->sender_name : null,
            'status' => $m->status,
            'status_label' => $m->direction === WhatsAppChatMessage::OUT ? WhatsAppStatus::label((string) $m->status) : null,
            'error' => $m->status === WhatsAppStatus::FAILED ? $m->error_message : null,
            'auto_replied' => $autoReplied,
            'attachments' => $m->attachments->map(fn (WhatsAppChatAttachment $a) => [
                'id' => $a->id,
                'name' => (string) $a->name,
                'image' => str_starts_with((string) $a->mime, 'image/'),
                'url' => route('admin.whatsappInbox.attachment', $a->id),
            ])->values()->all(),
        ];
    }

    /** المرفقُ من متجر الجلسة بمعرّفه — وما سواه 404 */
    public static function attachment(Business $business, int $id): ?WhatsAppChatAttachment
    {
        return WhatsAppChatAttachment::where('business_id', $business->id)->whereKey($id)->first();
    }

    /** سطرُ إشعارٍ في القائمة — نصُّه المحفوظ، أو وصفُه */
    private static function noticePreview(WhatsAppMessage $m): string
    {
        $bubble = WhatsAppConversations::bubble($m);

        return self::preview($bubble['body'] ?? $bubble['summary']);
    }

    private static function preview(?string $text): string
    {
        return mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), 0, 190, '…');
    }
}
