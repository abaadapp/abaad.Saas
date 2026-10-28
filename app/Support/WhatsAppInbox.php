<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatAttachment;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppChatRead;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
 *   المتاجر، فالمتجرُ لا يُعرف من الرقم. يُنسب الواردُ لمتجرٍ **واحدٍ** راسل
 *   هذا الزبونَ من الرقم المشترك في آخر `ATTRIBUTION_DAYS` يومًا — ومتجران
 *   أو لا أحد = لا يُكتب الواردُ في صندوق أحد (`attribute`).
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

    public const PER_PAGE = 30;

    public const THREAD_PAGE = 50;

    /** إشعاراتُ الطلبات التي تُعرض في الخيط بين الرسائل — حدٌّ لكلّ صفحة */
    public const NOTICES_PER_PAGE = 200;

    /** حدُّ واتساب للنصّ ٤٠٩٦ */
    public const MAX_BODY = 4000;

    /* ═══════════════════ الإذن ═══════════════════ */

    /**
     * أمفتوحٌ صندوقُ المحادثات لهذا المتجر؟
     *
     * يفتحه فريقُ أبعاد بالعمود — ولا يفتحه التاجرُ بنفسه — والباقةُ تشمل
     * واتساب. ولا اسمَ متجرٍ ولا معرّفَه هنا.
     */
    public static function available(?Business $business): bool
    {
        return $business !== null
            && (bool) $business->whatsapp_inbox_enabled
            && PlanFeatures::allows($business, 'whatsapp');
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
     */
    public static function receiveOwn(WhatsAppConnection $connection, array $message, ?string $profileName = null): void
    {
        if ($connection->owner_type !== WhatsAppMode::OWNER_BUSINESS
            || blank($connection->business_id) || blank($connection->phone_number_id)
            || $connection->status === WhatsAppConnection::INACTIVE) {
            return;
        }

        $business = Business::find($connection->business_id);

        if (! self::available($business)
            || WhatsAppConnections::forBusiness($business->id)?->id !== $connection->id) {
            return;
        }

        self::store($business, $connection, WhatsAppMode::BUSINESS_OWN, $message, $profileName);
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

        $business = self::attribute($connection, $from);

        if (! self::available($business) || ! WhatsAppFeature::canUseShared($business)) {
            return;
        }

        self::store($business, $connection, WhatsAppMode::ABAAD_SHARED, $message, $profileName);
    }

    /**
     * لمن هذا الزبونُ على الرقم المشترك؟ — متجرٌ واحدٌ أو لا أحد.
     *
     * والدليلُ ممّا كتبناه نحن لا ممّا يكتبه الزبون: إشعارٌ خرج إليه فعلًا من
     * هذا الرقم باسم متجر، أو محادثةٌ جاريةٌ معه في صندوق متجر — في آخر
     * `ATTRIBUTION_DAYS` يومًا. ونصُّ الرسالة لا يُقرأ في الإسناد: «طلبي من
     * محلّ الورد» يكتبها من شاء.
     */
    public static function attribute(WhatsAppConnection $line, string $phone): ?Business
    {
        $since = now()->subDays(self::ATTRIBUTION_DAYS);

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

        $ids = $notified->merge($talking)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->count() === 1 ? Business::find($ids->first()) : null;
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
     */
    private static function store(Business $business, WhatsAppConnection $connection, string $mode, array $message, ?string $profileName): void
    {
        $wamid = (string) ($message['id'] ?? '');
        $from = WhatsAppPhone::normalize($message['from'] ?? null);

        if ($wamid === '' || $from === null || WhatsAppChatMessage::where('wamid', $wamid)->exists()) {
            return;
        }

        [$body, $mediaType, $file] = CrmWhatsApp::inbound($connection, $message);

        // ختمُ ميتا — ولا يُقبل من المستقبل: نافذةُ الردّ تُحسب منه
        $at = isset($message['timestamp']) ? now()->setTimestamp((int) $message['timestamp']) : now();
        $at = $at->isFuture() ? now() : $at;

        $chat = Contention::retry(fn () => WhatsAppChat::firstOrCreate(
            ['business_id' => $business->id, 'phone_number_id' => (string) $connection->phone_number_id, 'customer_phone' => $from],
            ['whatsapp_connection_id' => $connection->id, 'source_mode' => $mode],
        ));

        Contention::attempt(function () use ($business, $connection, $chat, $wamid, $body, $mediaType, $file, $at, $profileName) {
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
            return self::no(__('صندوق محادثات واتساب غير مفعّل لهذا المتجر.'));
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

        if ($chat->last_inbound_at === null || $chat->last_inbound_at->lte(now()->subHours(self::WINDOW_HOURS))) {
            return self::no(__('نافذة الردّ مغلقة — مرّت أكثر من :h ساعة على آخر رسالةٍ من العميل. لا يخرج بعدها إلّا قالبٌ معتمَد، وإرسالُ القوالب من الصندوق غير متاحٍ بعد.', [
                'h' => self::WINDOW_HOURS,
            ]));
        }

        return ['line' => $line, 'reason' => null];
    }

    /** @return array{line: null, reason: string} */
    private static function no(string $reason): array
    {
        return ['line' => null, 'reason' => $reason];
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
     * والبحثُ باسم العميل (ملفُّه في هذا المتجر أو اسمُه على واتساب) أو برقمه.
     */
    public static function page(Business $business, User $user, ?string $q): LengthAwarePaginator
    {
        $q = trim(str_replace('%', '', (string) $q));
        $like = Search::like();

        $query = WhatsAppChat::query()->where('business_id', $business->id);

        if ($q !== '') {
            $digits = (string) preg_replace('/\D+/', '', Digits::western($q));
            $customers = Customer::where('business_id', $business->id)
                ->where('name', $like, '%'.$q.'%')->pluck('id');

            $query->where(function ($w) use ($like, $q, $digits, $customers) {
                $w->where('profile_name', $like, '%'.$q.'%');

                if ($digits !== '') {
                    $w->orWhere('customer_phone', $like, '%'.$digits.'%');
                }

                if ($customers->isNotEmpty()) {
                    $w->orWhereIn('customer_id', $customers);
                }
            });
        }

        $chats = $query->select('whatsapp_chats.*')
            ->selectSub(self::unreadSql($user), 'unread')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $names = Customer::where('business_id', $business->id)
            ->whereIn('id', collect($chats->items())->pluck('customer_id')->filter())
            ->pluck('name', 'id');

        $chats->through(fn (WhatsAppChat $c) => [
            'id' => $c->id,
            'name' => $names[$c->customer_id] ?? $c->profile_name,
            'phone' => (string) $c->customer_phone,
            'at' => optional($c->last_message_at)->format('Y-m-d H:i'),
            'preview' => (string) $c->last_preview,
            'direction' => $c->last_direction,
            'unread' => (int) $c->unread,
            'source' => $c->source_mode,
        ]);

        return $chats;
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

        $items = $rows->map(fn (WhatsAppChatMessage $m) => self::bubble($m))
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
    public static function bubble(WhatsAppChatMessage $m): array
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

    private static function preview(?string $text): string
    {
        return mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), 0, 190, '…');
    }
}
