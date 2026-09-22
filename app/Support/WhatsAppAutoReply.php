<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Support\Facades\Log;

/**
 * ردٌّ واحدٌ على أوّل رسالةٍ تصل رقمَ المحلّ — وما بعدها صمت.
 *
 * ═══ ولمَ هو مُطفأ حتى يُشعله صاحبُه ═══
 *
 * هذا أوّلُ شيءٍ في النظام يكتب إلى زبونٍ **بلا أن يفعل أحدٌ شيئًا**. وكلُّ
 * ما سواه يتبع فعلًا: بيعةً تمّت، أو حالةَ طلبٍ تبدّلت. فميزةٌ تردّ وحدَها
 * تُشعلها يدٌ تعرف ما تكتب — ولا تُورَث بترقية.
 *
 * ═══ ونافذةُ الأربعِ والعشرين ═══
 *
 * ميتا تمنع النصّ الحرّ إلّا داخل أربعٍ وعشرين ساعةً من آخر رسالةٍ وصلت من
 * الزبون. ونحن هنا **داخلها بالتعريف**: الردُّ لا يقع إلّا على رسالةٍ وصلت
 * الآن. فالنصُّ الحرّ مسموحٌ ولا حاجةَ لقالب — ولو خرجنا عن هذا الشرط يومًا
 * وجب قالبٌ معتمَد، ولا يُرسَل نصٌّ حرٌّ يُردّ بالخطأ ١٣١٠٤٧.
 *
 * ═══ وتهدئةٌ لكلّ رقم ═══
 *
 * الزبون يكتب «مرحبا» ثمّ «أبغى باقة» ثمّ «كم سعرها» — ثلاثُ رسائلَ في
 * دقيقة. وبلا تهدئةٍ يقرأ ثلاثةَ ردودٍ آليّةٍ متطابقة فيظنّ الرقمَ معطوبًا.
 * فيُردّ مرّةً في كلّ فترة، والفترةُ يكتبها التاجر.
 */
class WhatsAppAutoReply
{
    public const ENABLED = 'whatsapp_autoreply_enabled';

    public const TEXT_AR = 'whatsapp_autoreply_ar';

    public const TEXT_EN = 'whatsapp_autoreply_en';

    public const BRANCH = 'whatsapp_autoreply_branch_id';

    public const COOLDOWN = 'whatsapp_autoreply_cooldown_hours';

    public const KEYS = [self::ENABLED, self::TEXT_AR, self::TEXT_EN, self::BRANCH, self::COOLDOWN];

    /** أطولُ ما يُقبل — وحدُّ واتساب للنصّ ٤٠٩٦ */
    public const MAX_TEXT = 900;

    public const DEFAULT_COOLDOWN = 12;

    /** نافذةُ ميتا للنصّ الحرّ */
    public const WINDOW_HOURS = 24;

    /**
     * إعداداتُ هذا المتجر — وافتراضُ الإشعال «لا».
     *
     * @return array{enabled:bool, ar:string, en:string, branch_id:?int, cooldown_hours:int}
     */
    public static function settings(int $businessId): array
    {
        $rows = Setting::where('business_id', $businessId)
            ->whereIn('key', self::KEYS)->pluck('value', 'key');

        $cooldown = (int) ($rows[self::COOLDOWN] ?? self::DEFAULT_COOLDOWN);

        return [
            // مُطفأةٌ ما لم تُكتب `1` صراحةً — لا افتراضَ بالإشعال
            'enabled' => (string) ($rows[self::ENABLED] ?? '0') === '1',
            'ar' => (string) ($rows[self::TEXT_AR] ?? ''),
            'en' => (string) ($rows[self::TEXT_EN] ?? ''),
            'branch_id' => filled($rows[self::BRANCH] ?? null) ? (int) $rows[self::BRANCH] : null,
            'cooldown_hours' => $cooldown > 0 ? $cooldown : self::DEFAULT_COOLDOWN,
        ];
    }

    /**
     * حفظُ الإعدادات — والفرعُ يُفحص أنّه فرعُ هذا المتجر.
     *
     * فرعٌ من متجرٍ آخر لا يُحفظ ولو أُرسل معرّفُه: اسمُه وعنوانُه يدخلان
     * نصَّ الردّ، فيقرأ زبونُ محلٍّ عنوانَ محلٍّ آخر.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(int $businessId, array $data): void
    {
        $branch = filled($data['branch_id'] ?? null)
            && Branch::where('business_id', $businessId)->whereKey($data['branch_id'])->exists()
                ? (string) $data['branch_id']
                : null;

        $values = [
            self::ENABLED => ! empty($data['enabled']) ? '1' : '0',
            self::TEXT_AR => mb_substr((string) ($data['ar'] ?? ''), 0, self::MAX_TEXT),
            self::TEXT_EN => mb_substr((string) ($data['en'] ?? ''), 0, self::MAX_TEXT),
            self::BRANCH => $branch,
            self::COOLDOWN => (string) max(1, (int) ($data['cooldown_hours'] ?? self::DEFAULT_COOLDOWN)),
        ];

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(
                ['business_id' => $businessId, 'key' => $key],
                ['value' => $value],
            );
        }
    }

    /**
     * رسالةٌ وصلت رقمَ محلّ — تُقيَّد مرّةً، ويُردَّ عليها إن وجب.
     *
     * وتُنادى من باب الإشعارات وحدَه (`WebhookController`). وأوّلُ ما تفعله
     * الكتابةُ لا القراءة: الكتابةُ بمفتاحٍ فريد هي التي تحسم أيُّ نسخةٍ من
     * الإشعار تُعالج حين يصل مرّتين في اللحظة نفسها. والقراءةُ قبلها تترك
     * نافذةً يمرّ منها الثاني فيُرسَل ردّان.
     */
    public static function receive(WhatsAppConnection $connection, array $message): void
    {
        // رقمُ المنصّة له بابُه (الدعم والمبيعات) — هذا لأرقام المحلّات وحدها
        if ($connection->owner_type !== WhatsAppMode::OWNER_BUSINESS || blank($connection->business_id)) {
            return;
        }

        $wamid = (string) ($message['id'] ?? '');
        $from = WhatsAppPhone::normalize($message['from'] ?? null);

        if ($wamid === '' || blank($from)) {
            return;
        }

        /*
         * ═══ الكتابةُ أوّلًا، والاصطدامُ في نقطة حفظه ═══
         *
         * أوّلُ ما يقع الكتابةُ لا القراءة: الكتابةُ بمفتاحٍ فريد هي التي
         * تحسم أيُّ نسخةٍ من الإشعار تُعالج حين يصل مرّتين في اللحظة نفسها.
         * والقراءةُ قبلها تترك نافذةً يمرّ منها الثاني فيخرج ردّان.
         *
         * و`Contention::attempt` لا `try/catch` هنا: على PostgreSQL يُجهض
         * التقاطُ الاصطدام **المعاملةَ المحيطة كلَّها** إن لم يكن في نقطة
         * حفظ — فإشعارٌ مكرّرٌ واحد كان يُسقط معالجةَ ما بقي في الحمولة.
         * و`null` منها تعني «سبقنا إليه» — وذلك حالٌ طبيعيّة لا عطب.
         */
        $row = Contention::attempt(fn () => WhatsAppInboundMessage::create([
            'business_id' => $connection->business_id,
            'whatsapp_connection_id' => $connection->id,
            'wamid' => $wamid,
            'from_phone' => $from,
            'message_type' => mb_substr((string) ($message['type'] ?? 'unknown'), 0, 20),
            'received_at' => isset($message['timestamp']) ? now()->setTimestamp((int) $message['timestamp']) : now(),
        ]));

        if (! $row instanceof WhatsAppInboundMessage) {
            return;
        }

        $decision = self::decide($connection, $from);

        if ($decision !== null) {
            $row->forceFill(['reply_status' => WhatsAppInboundMessage::SKIPPED, 'reply_reason' => $decision])->save();

            return;
        }

        self::send($connection, $row);
    }

    /**
     * ما يمنع الردّ — و`null` تعني «لا مانع».
     *
     * وتُردّ **علّةً** لا `false`: «لم يُردّ» بلا سببٍ سؤالٌ بلا جواب يوم
     * يسأل التاجر لماذا لم يردّ رقمُه.
     */
    private static function decide(WhatsAppConnection $connection, string $from): ?string
    {
        $settings = self::settings((int) $connection->business_id);

        if (! $settings['enabled']) {
            return 'disabled';
        }

        if (! $connection->isUsable()) {
            return 'connection_unusable';
        }

        if (self::text($connection, $settings, $from) === '') {
            return 'empty_text';
        }

        $recent = WhatsAppInboundMessage::where('business_id', $connection->business_id)
            ->where('from_phone', $from)
            ->whereNotNull('replied_at')
            ->where('replied_at', '>', now()->subHours($settings['cooldown_hours']))
            ->exists();

        return $recent ? 'cooldown' : null;
    }

    /**
     * نصُّ الردّ بلغة الزبون إن عُرفت — وبالعربيّة إن لم تُعرف.
     *
     * ═══ ولا تُخمَّن اللغةُ من نصّ الرسالة ═══
     *
     * «hi» يكتبها من يقرأ العربيّة، و«مرحبا» يكتبها بالحروف اللاتينيّة من
     * لا يقرؤها. فاللغةُ من صفّ الزبون — وهو الحقل الذي يسأله الصندوق قبل
     * البيع (`customers.language`) — ومن لم يُسجَّل عندنا يُخاطب بالعربيّة.
     *
     * والعربيُّ احتياطٌ لمن ترك الإنجليزيّة فارغةً والعكس: نصٌّ فارغ يعني
     * رسالةً فارغةً تُردّ من ميتا، أو أسوأ: رسالةً بيضاءَ تصل الزبون.
     *
     * @param  array{ar:string, en:string, branch_id:?int}  $settings
     */
    public static function text(WhatsAppConnection $connection, array $settings, ?string $phone = null): string
    {
        $language = self::languageOf((int) $connection->business_id, $phone);

        $body = trim($language === 'en' ? ($settings['en'] ?: $settings['ar']) : ($settings['ar'] ?: $settings['en']));

        if ($body === '' || blank($settings['branch_id'] ?? null)) {
            return $body;
        }

        /*
         * واسمُ الفرع يُلحق حين يُختار — لا يُزرع في وسط نصٍّ كتبه التاجر.
         *
         * البدائلُ داخل النصّ (`{{branch}}`) تعني أن يتعلّم التاجرُ صيغةً،
         * وأن يخرج الردُّ بقوسين حين يخطئ فيها. والإلحاقُ سطرٌ لا يُخطئ.
         */
        $branch = Branch::where('business_id', $connection->business_id)
            ->whereKey($settings['branch_id'])->value('name');

        return filled($branch) ? $body."\n".$branch : $body;
    }

    /**
     * لغةُ صاحب هذا الرقم إن كان زبونًا مسجَّلًا.
     *
     * ═══ ولمَ لا تُطابَق الأعمدةُ نصًّا ═══
     *
     * الهاتفُ يُكتب في صفّ الزبون كما كتبه الموظّف: «+968 9198 3270» أو
     * «99123456» أو «0096891983270». وميتا تُعيده أرقامًا متّصلةً بلا زائد.
     * فمقارنةُ العمود بالنصّ تفشل على أكثر الصفوف — ويُخاطَب زبونٌ معروفٌ
     * بغير لغته.
     *
     * فتُضيَّق القائمةُ بآخر ثماني خاناتٍ (هي رقمُ المشترك في عُمان)، ثمّ
     * يُقارَن المطبَّعُ بالمطبَّع — وهي القاعدةُ نفسُها التي يستعملها
     * `Store\WebCheckout::customer`.
     */
    private static function languageOf(int $businessId, ?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        /*
         * وآخرُ أربعِ خاناتٍ لا ثماني.
         *
         * الهاتفُ يُكتب في الصفّ بفراغاتٍ: «+968 9123 4567». فتضييقٌ بآخر
         * ثمانٍ (`91234567`) لا يُطابق شيئًا لأنّ بينها فراغًا — وأوّلُ
         * كتابةٍ لهذا السطر سقطت به. والأربعُ الأخيرةُ متّصلةٌ في كلّ
         * الصيغ التي تُكتب بها الأرقام العُمانيّة.
         */
        $tail = mb_substr($phone, -4);

        return Customer::where('business_id', $businessId)
            /* و`ilike` على PostgreSQL و`like` على SQLite — انظر `Search::like` */
            ->whereNotNull('phone')->where('phone', Search::like(), '%'.$tail)
            ->get(['id', 'phone', 'language'])
            ->first(fn ($c) => WhatsAppPhone::normalize($c->phone) === $phone)
            ?->language;
    }

    /**
     * الإخراجُ إلى واتساب — نصٌّ حرٌّ داخل النافذة، ولا قالبَ يُنتحل.
     *
     * وما جرى يُقيَّد على الصفّ: نجحت أم فشلت وبأيّ رمز. ولا يُكتب نصُّ
     * الرسالة ولا رمزُ الوصول في سجلٍّ ولا في عمود.
     */
    private static function send(WhatsAppConnection $connection, WhatsAppInboundMessage $row): void
    {
        $settings = self::settings((int) $connection->business_id);
        $result = MetaWhatsAppClient::sendText(
            $connection,
            $row->from_phone,
            self::text($connection, $settings, $row->from_phone),
        );

        if ($result['ok']) {
            $row->forceFill([
                'reply_status' => WhatsAppInboundMessage::SENT,
                'reply_reason' => null,
                'replied_at' => now(),
            ])->save();

            return;
        }

        $row->forceFill([
            'reply_status' => WhatsAppInboundMessage::FAILED,
            'reply_reason' => mb_substr((string) $result['code'], 0, 60),
            /*
             * ولا يُختم `replied_at` على فاشلة.
             *
             * الختمُ هو ما تقرؤه التهدئة. ولو خُتم على الفشل لَصمت الرقمُ
             * ساعاتٍ بعد ردٍّ **لم يصل** — يسكت النظامُ عن الزبون ويظنّ
             * التاجر أنّه رُدّ عليه.
             */
        ])->save();

        Log::warning('whatsapp.autoreply_failed', [
            'business_id' => $connection->business_id,
            'meta_code' => $result['code'],
            'meta_message' => mb_substr((string) $result['message'], 0, 200),
        ]);
    }
}
