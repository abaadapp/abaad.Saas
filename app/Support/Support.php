<?php

namespace App\Support;

use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportRead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * مركزُ المحادثات — القواعدُ في موضعٍ واحد.
 *
 * الحالاتُ والأولويّاتُ والتصنيفاتُ والقنوات تُقرأ من هنا: الشاشةُ ترسمها،
 * والتحقّقُ يقيس عليها، والحارسُ يشهد بها. وثلاثُ نسخٍ منها تفترق يومًا
 * فتُقبل حالةٌ لا ترسمها الشاشة.
 */
final class Support
{
    /* ═══════════════════ القوائم ═══════════════════ */

    /**
     * الحالاتُ الستّ ومسارُها.
     *
     * `new` ما لم يفتحه أحدٌ بعد — وهي غيرُ `open`: بها يُعرف ما ينتظر
     * نظرةً أولى من ما هو في الطريق. ومن يخلط الاثنين يفقد الفرق بين
     * «لم يرَها أحد» و«يُعمل عليها».
     */
    public const STATUSES = ['new', 'open', 'waiting_customer', 'waiting_abaad', 'resolved', 'closed'];

    /** ما يُعدّ حيًّا يحتاج نظرَ الدعم */
    public const LIVE = ['new', 'open', 'waiting_abaad'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * القنواتُ الأربعُ — وواحدةٌ تعمل.
     *
     * `in_app` وحدَها موصولة. والبقيّةُ مواضعُ محجوزةٌ في العمود لا أبوابٌ
     * تُفتح: انظر `usableChannels`.
     */
    public const CHANNELS = ['in_app', 'whatsapp', 'email', 'instagram', 'facebook'];

    /** ما يعمل فعلًا اليوم — والشاشةُ لا ترسم غيره */
    public const WORKING_CHANNELS = ['in_app'];

    public const CATEGORIES = [
        'الحساب والاشتراك',
        'المبيعات والفواتير',
        'المشتريات والموردون',
        'المخزون',
        'المالية',
        'الموظفون والرواتب',
        'الموقع الإلكتروني',
        'مشكلة تقنية',
        'أخرى',
    ];

    /* ═══════════════════ المرفقات ═══════════════════ */

    /** ما يُقبل رفعُه — والقائمةُ مصدرٌ واحد يقرأ منه التحقّق والشاشة */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** بالكيلوبايت — عشرةُ ميجابايت كأخواتها في المالية */
    public const MAX_KB = 10240;

    /** وثلاثةٌ تكفي صورةَ شاشةٍ وسجلًّا وورقة */
    public const MAX_FILES = 3;

    /* ═══════════════════ الترجمة ═══════════════════ */

    /** اسمُ الحالة كما تُقرأ — والمفتاحُ عربيٌّ كعادة النظام */
    public static function statusLabel(string $status): string
    {
        return __(match ($status) {
            'new' => 'جديدة',
            'open' => 'مفتوحة',
            'waiting_customer' => 'بانتظار العميل',
            'waiting_abaad' => 'بانتظار فريق أبعاد',
            'resolved' => 'تم الحل',
            'closed' => 'مغلقة',
            default => $status,
        });
    }

    public static function priorityLabel(string $priority): string
    {
        return __(match ($priority) {
            'low' => 'منخفضة',
            'normal' => 'عادية',
            'high' => 'عالية',
            'urgent' => 'عاجلة',
            default => $priority,
        });
    }

    public static function channelLabel(string $channel): string
    {
        return __(match ($channel) {
            'in_app' => 'داخل أبعاد',
            'whatsapp' => 'واتساب',
            'email' => 'البريد الإلكتروني',
            'instagram' => 'إنستغرام',
            'facebook' => 'فيسبوك',
            default => $channel,
        });
    }

    /* ═══════════════════ الرقم ═══════════════════ */

    /**
     * رقمُ المحادثة — `SUP-2026-000001`.
     *
     * ═══ ولمَ القفلُ على الجدول لا على صفّ المتجر ═══
     *
     * أرقامُ الفواتير تُقفل على صفّ المتجر لأنّ لكلّ متجرٍ تسلسلَه. وهذا
     * تسلسلُ **المنصّة** كلِّها: متجران يفتحان محادثةً في اللحظة نفسها
     * يقرآن آخرَ رقمٍ فيكتبانه معًا — وقفلُ صفَّي متجرَين لا يمنع شيئًا،
     * فهما صفّان مختلفان.
     *
     * فالقفلُ على أعلى صفٍّ في `support_conversations` نفسِه. وفهرسُ
     * التفرّد على `reference` هو الحارسُ الأخير خلفه.
     */
    public static function nextReference(): string
    {
        return DB::transaction(function () {
            $year = now()->format('Y');
            $prefix = 'SUP-'.$year.'-';

            $last = SupportConversation::where('reference', 'like', $prefix.'%')
                ->orderByRaw('length(reference) desc')
                ->orderBy('reference', 'desc')
                ->lockForUpdate()
                ->value('reference');

            $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        });
    }

    /* ═══════════════════ الفتح والردّ ═══════════════════ */

    /**
     * محادثةٌ جديدة من متجر.
     *
     * والمتجرُ والفاتحُ يُقرآن من الجلسة لا من النموذج: `business_id` قادمًا
     * من المتصفّح يعني أنّ من يبدّل رقمًا في الطلب يفتح محادثةً باسم جارِه.
     */
    public static function open(User $user, string $subject, string $category, string $body): SupportConversation
    {
        $conversation = SupportConversation::create([
            'reference' => self::nextReference(),
            'business_id' => $user->business_id,
            'opened_by' => $user->id,
            'subject' => $subject,
            'category' => $category,
            'channel' => 'in_app',
            'status' => 'new',
            'priority' => 'normal',
            'last_message_at' => now(),
        ]);

        self::say($conversation, $user, 'business', $body);

        return $conversation;
    }

    /**
     * سطرٌ يُكتب في الخيط — رسالةً أو ملاحظةً أو حدثًا.
     *
     * و`last_message_at` تُحدَّث هنا وحدَها: تحديثُها في كلّ متحكّمٍ يكتب
     * رسالةً يعني نسيانَها في واحد، فتهبط المحادثةُ إلى قاع القائمة وقد
     * وصلها ردٌّ للتوّ.
     */
    public static function say(
        SupportConversation $conversation,
        ?User $sender,
        string $scope,
        ?string $body,
        bool $internal = false,
        ?string $event = null,
        ?array $meta = null,
    ): SupportMessage {
        $message = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender?->id,
            'sender_scope' => $scope,
            'body' => $body,
            'is_internal' => $internal,
            'event' => $event,
            'event_meta' => $meta,
        ]);

        /*
         * وحدثُ النظام لا يرفع المحادثة.
         *
         * «غُيّرت الأولويّة» ليست تواصلًا: لو رفعت المحادثةَ إلى الرأس
         * لصار ترتيبُ القائمة يقول «وصل جديد» عن فعلٍ فعله الدعمُ بنفسه.
         */
        if ($event === null) {
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
        }

        /*
         * ومن كتب فقد قرأ ما قبله.
         *
         * وبلا هذا يرى الدعمُ رسالتَه هو غيرَ مقروءة، ويرى التاجر ردَّه
         * على نفسه شارةً حمراء.
         */
        if ($sender) {
            self::markRead($conversation, $sender);
        }

        return $message;
    }

    /**
     * ردُّ التاجر — ويُعيد المحادثةَ إلى الطريق.
     *
     * محادثةٌ «بانتظار العميل» ردّ عليها صاحبُها تصير `waiting_abaad`: هي
     * الآن في ملعب أبعاد. ومحادثةٌ «تم حلّها» أو «مغلقة» تُفتح من جديد —
     * ومن يجد بابَه مغلقًا يفتح محادثةً ثانيةً بالسؤال نفسه.
     */
    public static function businessReplied(SupportConversation $conversation, User $user, string $body): SupportMessage
    {
        $was = $conversation->status;
        $message = self::say($conversation, $user, 'business', $body);

        $conversation->forceFill([
            'status' => 'waiting_abaad',
            'resolved_at' => null,
            'closed_at' => null,
        ])->save();

        if (in_array($was, ['resolved', 'closed'], true)) {
            self::say($conversation, null, 'system', null, false, 'reopened', ['from' => $was]);
        }

        return $message;
    }

    /* ═══════════════════ القراءة ═══════════════════ */

    /** قرأ فلانٌ حتّى آخر رسالة */
    public static function markRead(SupportConversation $conversation, User $user): void
    {
        $last = (int) SupportMessage::where('conversation_id', $conversation->id)->max('id');

        SupportRead::updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->id],
            ['last_read_message_id' => $last],
        );
    }

    /**
     * كم رسالةً لم يقرأها هذا القارئ في هذه المحادثة.
     *
     * والتاجرُ لا تُعدّ له الملاحظاتُ الداخليّة ولا رسائلُه هو.
     */
    public static function unreadFor(SupportConversation $conversation, User $user): int
    {
        $since = (int) SupportRead::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)->value('last_read_message_id');

        return SupportMessage::where('conversation_id', $conversation->id)
            ->where('id', '>', $since)
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('is_internal', false)
                ->where('sender_scope', '!=', 'business'))
            ->when($user->isSuperAdmin(), fn ($q) => $q->where('sender_scope', 'business'))
            ->count();
    }

    /**
     * شارةُ الشريط الجانبيّ لمدير المنصّة — ما يحتاج نظرًا.
     *
     * والعددُ محسوبٌ لا مكتوب: رقمٌ ثابتٌ في الشاشة يقول «٨» أبدًا ولو
     * لم يبقَ شيء — وشارةٌ لا تُطفأ تُصبح جزءًا من الأثاث فلا تُقرأ.
     */
    public static function platformBadge(User $user): int
    {
        if (! $user->isSuperAdmin()) {
            return 0;
        }

        return SupportConversation::whereIn('status', self::LIVE)
            ->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('support_messages')
                ->whereColumn('support_messages.conversation_id', 'support_conversations.id')
                ->where('support_messages.sender_scope', 'business')
                ->whereRaw(
                    'support_messages.id > coalesce((select last_read_message_id from support_reads
                     where support_reads.conversation_id = support_conversations.id
                       and support_reads.user_id = ?), 0)',
                    [$user->id],
                ))
            ->count();
    }

    /** شارةُ التاجر — ردودٌ من أبعاد لم يقرأها */
    public static function businessBadge(User $user): int
    {
        if ($user->business_id === null) {
            return 0;
        }

        return SupportConversation::where('business_id', $user->business_id)
            ->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('support_messages')
                ->whereColumn('support_messages.conversation_id', 'support_conversations.id')
                ->where('support_messages.is_internal', false)
                ->where('support_messages.sender_scope', 'platform')
                ->whereRaw(
                    'support_messages.id > coalesce((select last_read_message_id from support_reads
                     where support_reads.conversation_id = support_conversations.id
                       and support_reads.user_id = ?), 0)',
                    [$user->id],
                ))
            ->count();
    }

    /**
     * خلاصةُ صفحةٍ من المحادثات — بثلاثة استعلاماتٍ لا بثلاثةٍ لكلّ صفّ.
     *
     * كانت الشاشةُ تسأل عن آخر رسالةٍ وعن غير المقروء **لكلّ محادثة**، فصفحةٌ
     * بخمسةَ عشرَ صفًّا تُطلق أربعةً وخمسين استعلامًا. وهي تُفتح في كلّ
     * ضغطةِ تبويبٍ وكلّ حرفٍ في البحث.
     *
     * فالثلاثةُ تُجمع: علاماتُ القراءة صفوفًا، وأعدادُ غير المقروء تجميعًا،
     * وآخرُ رسالةٍ باستعلامٍ واحدٍ يُرتّب تنازليًّا ويُلتقط أوّلُ كلٍّ في PHP.
     *
     * @param  list<int>  $ids
     * @return array<int, array{preview: string, unread: int}>
     */
    public static function digest(array $ids, User $user, bool $asPlatform): array
    {
        if ($ids === []) {
            return [];
        }

        $read = SupportRead::whereIn('conversation_id', $ids)
            ->where('user_id', $user->id)
            ->pluck('last_read_message_id', 'conversation_id');

        /*
         * وما يُعدّ غيرَ مقروءٍ يفترق بالجهتين.
         *
         * الدعمُ يُعدّ له كلامُ المتجر، والتاجرُ يُعدّ له ردُّ أبعادٍ الظاهر
         * — لا ملاحظتُهم الداخليّة ولا رسالتُه هو.
         */
        $unread = SupportMessage::whereIn('conversation_id', $ids)
            ->when($asPlatform, fn ($q) => $q->where('sender_scope', 'business'))
            ->when(! $asPlatform, fn ($q) => $q->where('is_internal', false)
                ->where('sender_scope', '!=', 'business'))
            ->get(['id', 'conversation_id'])
            ->groupBy('conversation_id')
            ->map(fn ($rows, $cid) => $rows->where('id', '>', (int) ($read[$cid] ?? 0))->count());

        /* وآخرُ ما قيل — رسالةً لا حدثًا، وظاهرةً لا ملاحظةً داخليّة */
        $previews = SupportMessage::whereIn('conversation_id', $ids)
            ->whereNull('event')
            ->where('is_internal', false)
            ->orderByDesc('id')
            ->get(['conversation_id', 'body'])
            ->groupBy('conversation_id')
            ->map(fn ($rows) => (string) ($rows->first()->body ?? ''));

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'preview' => mb_substr($previews[$id] ?? '', 0, 80),
                'unread' => (int) ($unread[$id] ?? 0),
            ];
        }

        return $out;
    }

    /* ═══════════════════ المرفقات ═══════════════════ */

    /** @return array<string, mixed> قواعدُ التحقّق لحقلٍ اسمه $field */
    public static function fileRules(string $field): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_FILES],
            $field.'.*' => ['file', 'max:'.self::MAX_KB, 'extensions:'.implode(',', self::EXTENSIONS)],
        ];
    }

    /**
     * حفظُ ملفٍّ على رسالة.
     *
     * والاسمُ المخزَّن عشوائيٌّ يولّده Laravel، والمعروضُ ما سمّاه صاحبه.
     * فاسمٌ يُبنى من الأصل يُخمَّن، ومسارٌ يقبل ما يكتبه المستخدم يُخرَج به
     * من المجلّد كلِّه.
     */
    public static function attach(SupportMessage $message, UploadedFile $file): SupportAttachment
    {
        $path = $file->store('support/'.$message->conversation_id, 'local');

        return SupportAttachment::create([
            'message_id' => $message->id,
            'disk' => 'local',
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
