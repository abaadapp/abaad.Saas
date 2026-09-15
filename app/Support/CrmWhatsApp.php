<?php

namespace App\Support;

use App\Models\CrmAttachment;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmRead;
use App\Models\CrmStageEvent;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
 * ═══ وما يعبر وما لا يعبر ═══
 *
 * نصٌّ وصورةٌ وملفُّ PDF — في الاتّجاهين. ولا قوالبَ معتمَدةً في هذه
 * النسخة، فخارج النافذة لا يخرج شيء، ويُقال ذلك في الشاشة لا يُصمت عنه.
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

        if ($connection && $connection->isUsable()) {
            return $connection;
        }

        /*
         * ولا خطَّ مبيعاتٍ مستقلًّا: يُشارَك رقمُ الإشعارات إن أُذن بذلك.
         *
         * والإذنُ مقبضٌ يُدار في الإعدادات، لا حالةٌ تُستنتج: رقمٌ واحدٌ يخدم
         * الغرضين قرارُ مالكِ المنصّة وحدَه، وثمنُه مكتوبٌ في `SHARED_COST`.
         */
        if (! self::shared()) {
            return null;
        }

        $notices = WhatsAppConnections::platform();

        return $notices && $notices->isUsable() ? $notices : null;
    }

    /**
     * أمأذونٌ لرقم الإشعارات أن يحمل دفترَ المبيعات معه؟
     *
     * ═══ وما يُشترى بهذا الإذن وما يُدفع ثمنًا ═══
     *
     * يُشترى: رقمٌ واحدٌ يكفي، ولا رقمَ ثانيًا يُشترى ويُوثَّق عند ميتا.
     *
     * ويُدفع: رقمُ الإشعارات يُرسل نيابةً عن المحلّات، فيردّ عليه زبائنُهم.
     * ومن ردَّ عليه ولم نكن أرسلنا إليه شيئًا يصير — في هذا الوضع — عميلًا
     * محتمَلًا في دفترنا، ويُقرأ نصُّه في لوحة المنصّة.
     *
     * ولذلك لا يُفتح البابُ للجميع: `strangerOnSharedLine` تردُّ كلَّ رقمٍ
     * أرسلنا إليه إشعارَ طلبٍ يومًا، وكلَّ رقمٍ يخصّ مستخدمًا له متجر. ويبقى
     * الباقي — وهو من راسلنا ولم نراسله قطّ.
     */
    public static function shared(): bool
    {
        return (string) Setting::whereNull('business_id')
            ->where('key', 'crm_whatsapp_shared')->value('value') === '1';
    }

    /** أعلى رقمِ الإشعارات يجري هذا — أم على خطٍّ مستقلّ؟ */
    public static function sharingNoticeLine(): bool
    {
        $line = self::line();

        return $line !== null && $line->purpose === WhatsAppMode::PURPOSE_NOTIFICATIONS;
    }

    public static function connected(): bool
    {
        return self::line() !== null;
    }

    /* ═══════════════════ ما لم يُقرأ ═══════════════════ */

    /**
     * كم رسالةَ عميلٍ لم يقرأها هذا الموظّف في هذه المحادثة.
     *
     * والوارد وحدَه يُعدّ: ردُّنا نحن ليس شيئًا يُقرأ، وعدُّه يُبقي الشارةَ
     * مضيئةً بعد أن يردّ الموظّف بنفسه.
     */
    public static function unreadFor(CrmLead $lead, User $user): int
    {
        $since = (int) CrmRead::where('lead_id', $lead->id)
            ->where('user_id', $user->id)->value('last_read_message_id');

        return CrmMessage::where('lead_id', $lead->id)
            ->where('id', '>', $since)
            ->where('direction', CrmMessage::IN)
            ->count();
    }

    /**
     * ما لم يُقرأ لكلّ محادثةٍ في الصفحة — باستعلامٍ واحد.
     *
     * ولا `unreadFor` في حلقة: عشرون صفًّا تعني أربعين استعلامًا في كلّ
     * فتحةِ شاشة.
     *
     * @param  list<int>  $leadIds
     * @return array<int, int>
     */
    public static function unreadMap(array $leadIds, User $user): array
    {
        if ($leadIds === []) {
            return [];
        }

        $read = CrmRead::whereIn('lead_id', $leadIds)
            ->where('user_id', $user->id)
            ->pluck('last_read_message_id', 'lead_id');

        $rows = CrmMessage::whereIn('lead_id', $leadIds)
            ->where('direction', CrmMessage::IN)
            ->selectRaw('lead_id, id')
            ->get();

        $out = array_fill_keys($leadIds, 0);

        foreach ($rows as $row) {
            if ($row->id > (int) ($read[$row->lead_id] ?? 0)) {
                $out[$row->lead_id]++;
            }
        }

        return $out;
    }

    /** يُعلَّم المقروءُ عند فتح المحادثة — لهذا القارئ وحدَه */
    public static function markRead(CrmLead $lead, User $user): void
    {
        $last = (int) CrmMessage::where('lead_id', $lead->id)->max('id');

        CrmRead::updateOrCreate(
            ['lead_id' => $lead->id, 'user_id' => $user->id],
            ['last_read_message_id' => $last],
        );
    }

    /**
     * شارةُ الشريط الجانبيّ — كم محادثةً تنتظر ردَّ هذا الموظّف.
     *
     * ═══ ولمَ عددُ المحادثات لا عددُ الرسائل ═══
     *
     * عميلٌ كتب ثلاثةَ أسطرٍ في دقيقة ليس ثلاثةَ أشياء تنتظر — هو واحد.
     * ورقمٌ يقفز إلى «٢٣» لأنّ ثلاثةً أسهبوا يُقرأ ضجيجًا فيُهمَل.
     *
     * والمنتهون لا يُعدّون: من رُبح أو خُسر أُغلق بابُه، ورسالةٌ متأخّرةٌ
     * منه لا تُعيد فتحَ الشارة على من لا يُنتظر منه قرار.
     */
    public static function badge(User $user): int
    {
        if (! $user->isSuperAdmin()) {
            return 0;
        }

        return CrmLead::where('status', Crm::ACTIVE)
            ->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('crm_messages')
                ->whereColumn('crm_messages.lead_id', 'crm_leads.id')
                ->where('crm_messages.direction', CrmMessage::IN)
                ->whereRaw(
                    'crm_messages.id > coalesce((select last_read_message_id from crm_reads
                        where crm_reads.lead_id = crm_leads.id and crm_reads.user_id = ?), 0)',
                    [$user->id],
                ))
            ->count();
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
        if (! $connection->isUsable()) {
            return;
        }

        $sales = $connection->purpose === WhatsAppMode::PURPOSE_CRM_SALES;

        /*
         * ورقمُ الإشعارات لا يُقرأ هنا إلّا إن كان **هو خطَّ المبيعات فعلًا**.
         *
         * ═══ ولمَ يُسأل `line()` لا تُعاد شروطُه ═══
         *
         * أوّلُ كتابةٍ لهذا الشرط أعادت الشروطَ هنا: «غرضُه إشعارات، والإذنُ
         * مُدار». فقُبلت **وصلةُ متجرٍ** ربط رقمَه الخاصّ — غرضُها إشعاراتٌ
         * أيضًا — فصار وارد رقمِ محلٍّ يُكتب في دفتر مبيعات أبعاد.
         *
         * و`line()` تحمل الشروطَ كلَّها في موضعٍ واحد: وصلةُ منصّةٍ، نشطةٌ،
         * صالحة، وبإذن. فتُسأل ولا تُنسخ — ونسختان تفترقان يومًا.
         */
        if (! $sales && self::line()?->id !== $connection->id) {
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

        /*
         * وعلى الرقم المشترَك: لا يُقرأ إلّا **غريبٌ لم نراسله قطّ**.
         *
         * والشرطُ يُقاس ولا يُحدس: دفترُ إرسالنا نفسُه يقول من أرسلنا إليه
         * إشعارَ طلب. فمن كان فيه فهو زبونُ محلٍّ يردّ على إشعاره، لا تاجرٌ
         * يريد أن يشتري أبعاد.
         */
        if (! $sales && ! self::strangerOnSharedLine($from)) {
            return;
        }

        /*
         * والتنزيلُ **قبل** الكتابة لا داخلها: نداءُ شبكةٍ داخل نقطةِ حفظٍ
         * يُبقي القفلَ على الصفوف حتّى يردّ خادمٌ في بلدٍ آخر.
         */
        [$body, $mediaType, $file] = self::inbound($connection, $message);

        /*
         * واسمُ الملفّ الشخصيّ يُؤخذ إن أرسلته ميتا — ولا يُخترع.
         *
         * وهو ما يكتبه صاحبُ الرقم عن نفسه في واتساب، لا اسمُ نشاطه: يُكتب
         * اسمًا ويبقى «اسم النشاط» فارغًا حتّى يقوله هو.
         */
        $profileName = self::profileName($message);

        Contention::attempt(fn () => self::store($from, $profileName, $body, $mediaType, $file, $wamid));
    }

    /**
     * أغريبٌ هو — على الرقم الذي يخدم الغرضين؟
     *
     * ═══ ولمَ دفترُ الإرسال لا الحدس ═══
     *
     * رقمُ الإشعارات يُرسل نيابةً عن المحلّات، فأكثرُ وارده ردودُ زبائنَ على
     * إشعاراتِ طلباتهم. ولو وُزّع الواردُ بنصّ الرسالة أو بطولها لَصار سؤالُ
     * زبونةٍ عن هديّتها «عميلًا محتمَلًا» يومَ يُشبه سؤالَ تاجر.
     *
     * فالفاصلُ حقيقةٌ عندنا مكتوبةٌ في صفوفنا: `whatsapp_messages` تحفظ رقمَ
     * كلّ من أرسلنا إليه. فمن كان فيه ليس غريبًا، ومن لم يكن فيه لم نراسله
     * قطّ — فهو من بدأ هو بالكتابة إلينا.
     *
     * ويُردّ كذلك كلُّ رقمٍ يخصّ مستخدمًا له متجر: ذاك تاجرٌ يطلب دعمًا،
     * وبابُه `SupportWhatsApp` لا دفترُ المبيعات.
     *
     * ═══ وما يبقى من خطرٍ يُقال ═══
     *
     * زبونُ محلٍّ لم نُرسل إليه شيئًا قطّ — أعطاه صاحبُ المحلّ الرقمَ يدًا
     * بيد — يُكتب عميلًا محتمَلًا. وهذا حدُّ هذا الوضع، ولذلك الرقمُ الثاني
     * أسلم. انظر `shared()`.
     */
    public static function strangerOnSharedLine(string $from): bool
    {
        $phone = WhatsAppPhone::normalize($from);

        if ($phone === null) {
            return false;
        }

        /* أرسلنا إليه إشعارًا يومًا: زبونُ محلٍّ يردّ، لا عميلٌ محتمَل */
        if (WhatsAppMessage::where('recipient_phone', $phone)->exists()) {
            return false;
        }

        /*
         * ورقمُ تاجرٍ عندنا: بابُه الدعم لا المبيعات.
         *
         * ويُطبَّع كلُّ رقمٍ قبل المقارنة — الأرقامُ تُكتب في الحسابات بصيغٍ
         * شتّى: بمفتاحٍ وبلا مفتاح، بمسافاتٍ وبشَرطات. ومقارنةُ نصٍّ بنصٍّ
         * تُخطئ فتفتح دفترَ المبيعات على تاجر.
         *
         * وأيُّ مطابقةٍ تكفي للردّ — ولو تعدّدت. `SupportWhatsApp::sender`
         * تردُّ المكرَّر `null` لأنّها تحتاج صاحبًا واحدًا بعينه؛ وهنا يكفي
         * أن يكون الرقمُ لتاجرٍ ما.
         */
        foreach (User::query()->whereNotNull('business_id')->whereNotNull('phone')->cursor() as $user) {
            if (WhatsAppPhone::normalize($user->phone) === $phone) {
                return false;
            }
        }

        return true;
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
        ?array $file,
        string $wamid,
    ): void {
        $result = CrmLeads::findOrCreateByPhone($from, Crm::SOURCE_WHATSAPP, $profileName);
        $lead = $result['lead'];

        $message = CrmMessage::create([
            'lead_id' => $lead->id,
            'direction' => CrmMessage::IN,
            'body' => $body,
            'media_type' => $mediaType,
            'external_message_id' => $wamid,
        ]);

        /* وما نزل يُعلَّق على رسالته — لا على العميل ولا على رسالةٍ أخرى */
        if ($file !== null) {
            self::keep($message, $file['contents'], $file['mime'], $file['name']);
        }

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
     * قراءةُ الوارد — نصُّه، ونوعُه، وملفُّه إن كان ممّا نحمله.
     *
     * ═══ ولمَ `media_type` يبقى مكتوبًا ولو نزل الملفّ ═══
     *
     * العمودُ يقول «بمَ جاءت هذه الرسالة»، والمرفقُ يقول «وأين هي». وصفٌّ
     * فيه مرفقٌ ولا نوعَ عليه يُقرأ نصًّا في كلّ تقريرٍ يُكتب بعد سنة.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: ?string, 2: ?array{contents:string, mime:string, name:string}}
     */
    private static function inbound(WhatsAppConnection $connection, array $message): array
    {
        $type = (string) ($message['type'] ?? '');

        if ($type === 'text') {
            $body = trim((string) ($message['text']['body'] ?? ''));

            if ($body !== '') {
                return [mb_substr($body, 0, 5000), null, null];
            }
        }

        $pressed = trim((string) match ($type) {
            'button' => $message['button']['text'] ?? '',
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? '',
            default => '',
        });

        if ($pressed !== '') {
            return [mb_substr($pressed, 0, 5000), null, null];
        }

        $part = (array) ($message[$type] ?? []);
        $mediaId = (string) ($part['id'] ?? '');
        $mime = (string) ($part['mime_type'] ?? '');
        $caption = trim((string) ($part['caption'] ?? ''));

        /* والفاصلُ سؤالٌ واحد — انظر `SupportWhatsApp::inbound` لعلّته */
        if ($mediaId !== '' && WhatsAppMedia::kind($mime) !== null) {
            return self::inboundFile($connection, $type, $mediaId, $mime, $caption, $part);
        }

        return [
            $caption !== '' ? mb_substr($caption, 0, 5000) : self::describe($type),
            $type !== '' ? mb_substr($type, 0, 20) : 'unknown',
            null,
        ];
    }

    /**
     * ملفٌّ ثبت أنّه ممّا نحمله — يُسحب من ميتا ويُسمّى.
     *
     * @param  array<string, mixed>  $part
     * @return array{0: string, 1: ?string, 2: ?array{contents:string, mime:string, name:string}}
     */
    private static function inboundFile(
        WhatsAppConnection $connection,
        string $type,
        string $mediaId,
        string $mime,
        string $caption,
        array $part,
    ): array {
        $download = WhatsAppMedia::download($connection, $mediaId);

        if (! $download['ok']) {
            /*
             * وإخفاقُ التنزيل يُقال ولا يُسكت عنه.
             *
             * عميلٌ محتمَلٌ أرسل صورةَ ما يريد شراءَه، فإن لم يقرأ الموظّفُ
             * شيئًا ظنَّ أنّ الرسالةَ فارغةٌ فلم يسأل — وذهب العميل.
             */
            return [
                __('[وصل :type ولم يُنزَّل — :why. اطلب إعادةَ إرساله.]', [
                    'type' => $type === 'image' ? __('صورة') : __('ملفّ'),
                    'why' => mb_substr((string) $download['message'], 0, 120),
                ]),
                mb_substr($type, 0, 20),
                null,
            ];
        }

        $name = trim((string) ($part['filename'] ?? ''));

        if ($name === '') {
            $name = 'whatsapp-'.now()->format('Ymd-His').'.'.WhatsAppMedia::suffix($mime);
        }

        return [
            $caption !== '' ? mb_substr($caption, 0, 5000) : ($type === 'image' ? __('[صورة]') : __('[ملفّ]')),
            mb_substr($type, 0, 20),
            ['contents' => (string) $download['contents'], 'mime' => $mime, 'name' => $name],
        ];
    }

    /** وصفُ ما لا يُحمل — سطرٌ يُقرأ بدل صفٍّ يُسقَط أو سطرٍ أبيض */
    private static function describe(string $type): string
    {
        return __('[أرسل :type — لا يُعرض هنا في هذه النسخة.]', [
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
        ]);
    }

    /**
     * حفظُ ملفٍّ على رسالة — بايتاتٍ من واتساب أو نموذجَ رفعٍ من الشاشة.
     *
     * والاسمُ المخزَّن عشوائيٌّ: اسمُ ملفٍّ يختاره من في الطرف الآخر يُكتب
     * في مسارٍ فيخرج به من المجلّد. والمعروضُ ما سمّاه صاحبُه — عمودًا
     * يُقرأ لا جزءًا من طريق.
     */
    public static function keep(
        CrmMessage $message,
        string $contents,
        string $mime,
        string $name,
    ): CrmAttachment {
        $path = 'crm/'.$message->lead_id.'/'
            .Str::random(40).'.'.WhatsAppMedia::suffix($mime);

        Storage::disk('local')->put($path, $contents);

        return CrmAttachment::create([
            'message_id' => $message->id,
            'disk' => 'local',
            'path' => $path,
            'name' => mb_substr($name, 0, 240),
            'mime' => $mime,
            'size' => strlen($contents),
        ]);
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
    /**
     * @param  list<UploadedFile>  $files  مرفقاتُ الردّ — بترتيب اختيارها
     */
    public static function send(CrmLead $lead, User $sender, string $body, array $files = []): CrmMessage
    {
        $message = CrmMessage::create([
            'lead_id' => $lead->id,
            'direction' => CrmMessage::OUT,
            'body' => mb_substr(trim($body), 0, 4000),
            'sender_id' => $sender->id,
            'sender_name' => $sender->name,
        ]);

        /*
         * والمرفقُ يُحفظ **قبل** أن يُحكم على النافذة.
         *
         * ردٌّ مُنع لأنّ النافذةَ أُغلقت يبقى في الخيط نصًّا ومرفقًا: من فتحه
         * غدًا رآه كما كُتب. وحفظُه بعد النجاح وحدَه يعني أنّ ما مُنع يضيع
         * ملفُّه، فيُعاد رفعُه من جديد.
         */
        foreach ($files as $file) {
            self::keep(
                $message,
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getClientMimeType(),
                (string) $file->getClientOriginalName(),
            );
        }

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
        $out = WhatsAppMedia::deliver($line, (string) $lead->phone, (string) $message->body, self::outboundFiles($message));

        $message->forceFill([
            'delivery' => $out['state'],
            'delivery_error' => $out['message'] === null ? null : mb_substr((string) $out['message'], 0, 200),
        ])->save();

        if (filled($out['id'])) {
            $message->forceFill(['external_message_id' => $out['id']])->save();
        }

        /* وآخرُ اتّصالٍ يتحرّك بما خرج فعلًا — لا بما كُتب ومُنع */
        if ($out['sent'] > 0) {
            $lead->forceFill(['last_contact_at' => now()])->save();
        }

        return $message;
    }

    /**
     * مرفقاتُ الردّ كما تُسلَّم للقناة — بترتيب حفظها.
     *
     * @return list<array{disk:string, path:string, mime:string, name:string}>
     */
    private static function outboundFiles(CrmMessage $message): array
    {
        return $message->attachments()->orderBy('id')->get()
            ->map(fn ($a) => [
                'disk' => (string) $a->disk,
                'path' => (string) $a->path,
                'mime' => (string) $a->mime,
                'name' => (string) $a->name,
            ])
            ->values()->all();
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
            /* وهذه لا تُدّعى نجاحًا ولا فشلًا — انظر `WhatsAppMedia::deliver` */
            'partial' => __('خرج النصّ ولم يخرج المرفق'),
            /* وهذه من ميتا لا منّا — انظر `WebhookController::applyCrmStatus` */
            'delivered' => __('سُلّمت'),
            'read' => __('قُرئت'),
            'failed' => __('لم تُرسل'),
            'blocked' => __('لم تخرج — النافذة مغلقة'),
            default => null,
        };
    }
}
