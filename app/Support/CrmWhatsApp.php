<?php

namespace App\Support;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmStageEvent;
use App\Models\User;
use App\Models\WhatsAppConnection;

/**
 * قناةُ واتساب في دفتر المبيعات — الموضعُ الوحيد الذي يعرفها.
 *
 * ═══ الحدُّ الذي لا يُعبر ═══
 *
 * هذا الرقمُ **ليس** رقمَ الإشعارات. ذاك يُرسل نيابةً عن المحلّات فيردّ عليه
 * زبائنُهم، ولا يُقرأ من وارده إلّا ما طابق مستخدمًا له متجر. وهذا يستقبل من
 * يريد أن يشتري أبعاد — فهو يقرأ **المجهول** بالضرورة.
 *
 * والشرطان لا يجتمعان على رقم، فالغرضُ عمودٌ يفصلهما: لا واردَ يُقرأ هنا
 * إلّا على وصلةٍ غرضُها `crm_sales` صراحةً. ولو سقط هذا الشرط لَصارت كلُّ
 * زبونةِ محلِّ ورودٍ سألت عن هديّتها صفًّا في دفتر مبيعاتنا.
 *
 * ═══ ونافذةُ ميتا حقيقةٌ لا تُلتفّ ═══
 *
 * النصُّ الحرُّ لا يخرج إلّا خلال أربعٍ وعشرين ساعةً من آخر رسالةٍ وصلت من
 * العميل. وخارجَها يُردّ بالخطأ ١٣١٠٤٧. فالمنعُ يُقرأ هنا **قبل** النداء
 * وتُكتب الرسالة `blocked` — لا «فشل» فيُظنَّ عطلًا يزول بالتكرار، ولا صمتٌ
 * فيُظنَّ الردُّ قد وصل.
 *
 * ═══ وحدُّ هذه المرحلة يُقال ═══
 *
 * نصٌّ يخرج ونصٌّ يدخل. ولا مرفقاتٍ ولا قوالبَ مُعتمَدة في هذه النسخة —
 * فخارج النافذة لا يخرج شيء، ويُقال ذلك في الشاشة لا يُصمت عنه.
 */
final class CrmWhatsApp
{
    /** نافذةُ ميتا للنصّ الحرّ — أربعٌ وعشرون ساعةً بعد آخر واردٍ من العميل */
    public const WINDOW_HOURS = 24;

    /* ═══════════════════ الخطّ ═══════════════════ */

    /**
     * خطُّ المبيعات — وصلةُ منصّةٍ نشطةٌ صالحةٌ غرضُها المبيعات.
     *
     * ولا مقبضَ `supports_inbox` عليها: رقمٌ لا غرضَ له غيرُ هذا، ومقبضان
     * لبابٍ واحد يُنسى أحدُهما فيُظنّ الرقمُ موصولًا ولا يصل شيء.
     */
    public static function line(): ?WhatsAppConnection
    {
        $connection = WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_CRM_SALES)
            ->where('status', WhatsAppConnection::ACTIVE)
            ->orderByDesc('id')->first();

        return $connection && $connection->isUsable() ? $connection : null;
    }

    public static function connected(): bool
    {
        return self::line() !== null;
    }

    /* ═══════════════════ الوارد ═══════════════════ */

    /**
     * رسالةٌ وصلت من ميتا على خطّ المبيعات.
     *
     * @param  array<string, mixed>  $message  عنصرٌ من `value.messages`
     */
    public static function receive(WhatsAppConnection $connection, array $message): void
    {
        /*
         * والغرضُ يُفحص هنا أيضًا لا في المُنادي وحده.
         *
         * `WebhookController` يوزّع بالغرض، وهذا حارسٌ ثانٍ خلفه: من ينادي
         * هذه الدالّة يومًا من موضعٍ آخر لا يفتح بابَ المجهول على رقم
         * الإشعارات بسهو.
         */
        if ($connection->purpose !== WhatsAppMode::PURPOSE_CRM_SALES || ! $connection->isUsable()) {
            return;
        }

        $wamid = (string) ($message['id'] ?? '');

        if ($wamid === '') {
            return;
        }

        // وإشعارٌ وصل مرّتين يُكتب مرّة — والفهرسُ الفريد هو الحارسُ خلفه
        if (CrmMessage::where('external_message_id', $wamid)->exists()) {
            return;
        }

        $from = (string) ($message['from'] ?? '');

        if (WhatsAppPhone::normalize($from) === null) {
            return;
        }

        [$body, $mediaType] = self::inboundBody($message);

        /*
         * واسمُ الملفّ الشخصيّ يُؤخذ إن أرسلته ميتا — ولا يُخترع.
         *
         * وهو ما يكتبه صاحبُ الرقم عن نفسه في واتساب، لا اسمُ نشاطه: يُكتب
         * اسمًا ويبقى «اسم النشاط» فارغًا حتّى يقوله هو.
         */
        $profileName = self::profileName($message);

        Contention::attempt(fn () => self::store($from, $profileName, $body, $mediaType, $wamid));
    }

    /**
     * كتابةُ الوارد في خيطه — وإنشاءُ العميل المحتمَل إن كان أوّلَ مرّة.
     *
     * @param  non-empty-string  $wamid
     */
    private static function store(
        string $from,
        ?string $profileName,
        string $body,
        ?string $mediaType,
        string $wamid,
    ): void {
        $result = CrmLeads::findOrCreateByPhone($from, Crm::SOURCE_WHATSAPP, $profileName);
        $lead = $result['lead'];

        CrmMessage::create([
            'lead_id' => $lead->id,
            'direction' => CrmMessage::IN,
            'body' => $body,
            'media_type' => $mediaType,
            'external_message_id' => $wamid,
        ]);

        /*
         * وختمُ النافذة يُكتب هنا وحدَه.
         *
         * `last_contact_at` يتحرّك بردِّنا نحن أيضًا، فلو حُسبت النافذةُ منه
         * لَظنّت الشاشةُ البابَ مفتوحًا لأنّ موظّفَ المبيعات كتب — وميتا تردّ
         * الرسالة.
         */
        $lead->forceFill([
            'whatsapp_window_at' => now(),
            'last_contact_at' => now(),
        ])->save();

        /*
         * ورسالةٌ من عميلٍ سُجّل «مفقودًا» تُعيده إلى الطريق.
         *
         * ومن يكتب إلينا بعد شهرٍ لا يُترك في خانة «مفقود» لأنّنا وضعناه
         * فيها — فلا يظهر في أيّ قائمةِ متابعة، ولا يردّ عليه أحد.
         *
         * و«مشترك» لا تُمسّ: تاجرٌ يكتب إلى رقم المبيعات ليس عميلًا محتملًا
         * عاد، بل صاحبُ دعمٍ طرقَ البابَ الخطأ — ويُقال ذلك في شاشته.
         */
        if ($lead->stage === Crm::LOST) {
            $lead->forceFill([
                'stage' => Crm::CONTACTED,
                'status' => Crm::ACTIVE,
                'lost_reason' => null,
                'lost_note' => null,
            ])->save();

            CrmStageEvent::create([
                'lead_id' => $lead->id,
                'from_stage' => Crm::LOST,
                'to_stage' => Crm::CONTACTED,
                'user_id' => null,
                'user_name' => __('واتساب'),
                'reason' => __('كتب إلينا من جديد'),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * نصُّ الوارد — أو وصفٌ صادقٌ لما لا يُقرأ.
     *
     * صورةٌ أو صوتٌ لا يُنزَّل في هذه النسخة. وإسقاطُ الرسالة يعني عميلًا
     * أرسل ولم يردّ عليه أحد؛ وكتابةُ نصٍّ فارغٍ تعني سطرًا أبيضَ لا يُفهم.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: ?string}
     */
    private static function inboundBody(array $message): array
    {
        $type = (string) ($message['type'] ?? '');

        if ($type === 'text') {
            $body = trim((string) ($message['text']['body'] ?? ''));

            if ($body !== '') {
                return [mb_substr($body, 0, 5000), null];
            }
        }

        $pressed = trim((string) match ($type) {
            'button' => $message['button']['text'] ?? '',
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? '',
            default => '',
        });

        if ($pressed !== '') {
            return [mb_substr($pressed, 0, 5000), null];
        }

        return [
            __('[أرسل :type — لا يُعرض هنا في هذه النسخة.]', [
                'type' => match ($type) {
                    'image' => __('صورة'),
                    'document' => __('ملفًّا'),
                    'audio', 'voice' => __('رسالة صوتية'),
                    'video' => __('مقطعًا'),
                    'sticker' => __('ملصقًا'),
                    'location' => __('موقعًا'),
                    'contacts' => __('جهة اتصال'),
                    default => __('رسالة'),
                },
            ]),
            $type !== '' ? mb_substr($type, 0, 20) : 'unknown',
        ];
    }

    /** @param array<string, mixed> $message */
    private static function profileName(array $message): ?string
    {
        $name = trim((string) ($message['profile']['name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 150) : null;
    }

    /* ═══════════════════ النافذة ═══════════════════ */

    public static function windowOpen(CrmLead $lead): bool
    {
        return $lead->whatsapp_window_at !== null
            && $lead->whatsapp_window_at->gt(now()->subHours(self::WINDOW_HOURS));
    }

    /** متى تُغلق — تُعرض قبل أن يكتب لا بعد أن يُمنع */
    public static function windowEndsAt(CrmLead $lead): ?string
    {
        return $lead->whatsapp_window_at
            ? $lead->whatsapp_window_at->addHours(self::WINDOW_HOURS)->toIso8601String()
            : null;
    }

    /**
     * لمَ لا يخرج ردٌّ الآن — أو `null` إن كان يخرج.
     *
     * ═══ ولمَ تُقرأ في الشاشة قبل الضغط ═══
     *
     * زرٌّ يُعرض ثمّ يردّ «لم تُرسل» يجعل صاحبَه يظنّ العطبَ عندنا فيعيد
     * المحاولة. والسببُ يُقال قبل الكتابة: «لم تصل رسالةٌ منه منذ يوم».
     */
    public static function blockedReason(CrmLead $lead): ?string
    {
        if (! self::connected()) {
            return __('رقم مبيعات أبعاد غير موصول — يُربط من إعدادات واتساب.');
        }

        if ($lead->whatsapp_window_at === null) {
            return __('لم تصلنا رسالةٌ منه على واتساب — ولا يبدأ واتساب محادثةً بنصٍّ حرّ.');
        }

        if (! self::windowOpen($lead)) {
            return __('نافذةُ واتساب مغلقة — لم تصل رسالةٌ منه منذ أكثر من :h ساعة.', [
                'h' => self::WINDOW_HOURS,
            ]);
        }

        return null;
    }

    /* ═══════════════════ الصادر ═══════════════════ */

    /**
     * ردٌّ يخرج إلى واتساب — وما جرى عليه يُكتب في الصفّ.
     *
     * والحالُ تُقاس ولا تُفترض: `sent` تعني أنّ ميتا قبلتها وأعادت معرّفًا،
     * و`blocked` تعني أنّنا منعناها قبل النداء، و`failed` تعني أنّ ميتا
     * ردّتها. وثلاثتُها تُقرأ في الشاشة.
     */
    public static function send(CrmLead $lead, User $sender, string $body): CrmMessage
    {
        $message = CrmMessage::create([
            'lead_id' => $lead->id,
            'direction' => CrmMessage::OUT,
            'body' => mb_substr(trim($body), 0, 4000),
            'sender_id' => $sender->id,
            'sender_name' => $sender->name,
        ]);

        $reason = self::blockedReason($lead);

        if ($reason !== null) {
            return self::stamp($message, 'blocked', $reason);
        }

        $line = self::line();

        /*
         * والنداءُ خارجَ أيّ معاملة.
         *
         * نداءُ شبكةٍ داخل معاملةٍ مفتوحة يُبقي القفلَ على الصفوف حتّى يردّ
         * خادمٌ في بلدٍ آخر أو تنتهي المهلة.
         */
        $result = MetaWhatsAppClient::sendText($line, $lead->phone, (string) $message->body);

        if ($result['ok']) {
            $message->forceFill([
                'delivery' => 'sent',
                'delivery_error' => null,
                'external_message_id' => $result['id'],
            ])->save();

            $lead->forceFill(['last_contact_at' => now()])->save();

            return $message;
        }

        return self::stamp($message, 'failed', mb_substr((string) $result['message'], 0, 200));
    }

    private static function stamp(CrmMessage $message, string $state, string $reason): CrmMessage
    {
        $message->forceFill(['delivery' => $state, 'delivery_error' => $reason])->save();

        return $message;
    }

    /** كيف تُقرأ حالُ التسليم في الشاشة */
    public static function deliveryLabel(?string $state): ?string
    {
        return match ($state) {
            'sent' => __('أُرسلت'),
            /* وهذه من ميتا لا منّا — انظر `WebhookController::applyCrmStatus` */
            'delivered' => __('سُلّمت'),
            'read' => __('قُرئت'),
            'failed' => __('لم تُرسل'),
            'blocked' => __('لم تخرج — النافذة مغلقة'),
            default => null,
        };
    }
}
