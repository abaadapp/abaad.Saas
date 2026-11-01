<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ردٌّ واحدٌ على أوّل رسالةٍ تصل رقمَ المحلّ — وما بعدها صمت.
 *
 * ═══ مُشعَلٌ لمن لم يقل رأيه، وصامتٌ لمن أطفأه ═══
 *
 * كان مُطفأً حتى يُشعله صاحبُه، فلم يردّ رقمٌ على زبونٍ قطّ إلّا بيدٍ تذكّرت.
 * وقرارُ صاحب المنتج (2026-11-01): الافتراضُ إشعالٌ لكلّ متجرٍ برقمه، حاليٍّ
 * وجديد، بلا اسمٍ ولا رقمِ متجرٍ ولا قائمة. ومن أطفأه صراحةً (`0` محفوظ) يبقى
 * صامتًا، ومن كتب نصّه يبقى نصُّه. وما لم يُكتب نصٌّ يُردّ بنصٍّ عامٍّ باسم
 * المتجر نفسِه (`defaultText`).
 *
 * والإشعالُ ليس إرسالًا: يخرج الردّ إن عمل الرقمُ وأذنت الباقةُ وصحّ الربطُ
 * ورُوجع دليلُ فوترة تطبيق التاجر — كلُّ حارسٍ في `decide` كما كان، ولا يُتجاوز
 * `billing_unproven` أبدًا. والشاشةُ تقول أيّها الحال (`state`).
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
     * ما ليس رسالةً كتبها زبون — فلا يُردّ عليه.
     *
     * تفاعلٌ بوجهٍ على رسالتنا، وإشعارُ نظامٍ بتبدّل رقمه، وما لا تدعمه ميتا،
     * و«ترحيبٌ» تطلبه حين يفتح المحادثةَ ولم يكتب حرفًا.
     */
    public const NOT_A_MESSAGE = ['reaction', 'system', 'unsupported', 'request_welcome', 'ephemeral', 'unknown'];

    /** أحوالُ الميزة كما تقولها الشاشة — `state` */
    public const WORKING = 'working';

    public const WAITING = 'waiting';

    public const OFF = 'off';

    /**
     * إعداداتُ هذا المتجر — وافتراضُ الإشعال «نعم» لمن لم يقل رأيه.
     *
     * و`explicit` يقول أقال: صفٌّ محفوظٌ لمفتاح الإشعال — `1` أو `0`. فمن أطفأه
     * يبقى مُطفأً، ولا يقرأ أحدٌ «لم يُضبط» على أنّه «أُطفئ».
     *
     * @return array{enabled:bool, explicit:bool, ar:string, en:string, branch_id:?int, cooldown_hours:int}
     */
    public static function settings(int $businessId): array
    {
        $rows = Setting::where('business_id', $businessId)
            ->whereIn('key', self::KEYS)->pluck('value', 'key');

        $cooldown = (int) ($rows[self::COOLDOWN] ?? self::DEFAULT_COOLDOWN);
        $explicit = $rows->has(self::ENABLED) && $rows[self::ENABLED] !== null && (string) $rows[self::ENABLED] !== '';

        return [
            // مُشعَلةٌ ما لم تُكتب `0` صراحةً — ومن أطفأها يبقى على قوله
            'enabled' => $explicit ? (string) $rows[self::ENABLED] === '1' : true,
            'explicit' => $explicit,
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

        $decision = self::decide($connection, $from, $row, (string) ($message['type'] ?? ''));

        if ($decision === null && ! self::claim($connection, $row)) {
            $decision = 'cooldown';
        }

        if ($decision !== null) {
            $row->forceFill(['reply_status' => WhatsAppInboundMessage::SKIPPED, 'reply_reason' => $decision])->save();

            return;
        }

        self::send($connection, $row);
    }

    /**
     * يحجز الردَّ لهذا الزبون قبل أن يخرج — فرسالتان في اللحظة نفسها لا تُردّان مرّتين.
     *
     * ═══ والعطبُ الذي وُضع لأجله ═══
     *
     * التهدئةُ تقرأ `replied_at`، وكان يُختم بعد أن يعود نداءُ ميتا. فرسالتان
     * مختلفتان من الزبون نفسِه تصلان معًا (إشعاران متوازيان) تقرآن كلتاهما «لا
     * ردَّ قبلي» وتخرجان ردَّين. فيُختم الحجزُ أوّلًا، وصفُّ المتجر مقفلٌ ريثما
     * يُسأل عن ردٍّ سبقه — والثانيةُ تجد الحجزَ فتصمت. وإن فشل الإرسالُ رُفع الحجز
     * (`send`)، فلا يصمت الرقمُ عن زبونٍ لم يصله شيء.
     */
    private static function claim(WhatsAppConnection $connection, WhatsAppInboundMessage $row): bool
    {
        $hours = self::settings((int) $connection->business_id)['cooldown_hours'];

        return DB::transaction(function () use ($connection, $row, $hours) {
            Business::whereKey($connection->business_id)->lockForUpdate()->first();

            $taken = WhatsAppInboundMessage::where('business_id', $connection->business_id)
                ->where('from_phone', $row->from_phone)
                ->whereKeyNot($row->id)
                ->whereNotNull('replied_at')
                ->where('replied_at', '>', now()->subHours($hours))
                ->exists();

            if ($taken) {
                return false;
            }

            $row->forceFill(['replied_at' => now()])->save();

            return true;
        });
    }

    /**
     * ما يمنع الردّ — و`null` تعني «لا مانع».
     *
     * وتُردّ **علّةً** لا `false`: «لم يُردّ» بلا سببٍ سؤالٌ بلا جواب يوم
     * يسأل التاجر لماذا لم يردّ رقمُه.
     */
    private static function decide(WhatsAppConnection $connection, string $from, ?WhatsAppInboundMessage $row = null, string $type = ''): ?string
    {
        $settings = self::settings((int) $connection->business_id);

        if (! $settings['enabled']) {
            return 'disabled';
        }

        // ولا ردَّ على ما لم يكتبه زبون — تفاعلٌ أو إشعارُ نظامٍ أو «ترحيبٌ» بلا حرف
        if (in_array(strtolower($type), self::NOT_A_MESSAGE, true)) {
            return 'not_a_message';
        }

        /*
         * ولا ردَّ خارج نافذة ميتا — رسالةٌ وصلت متأخّرة.
         *
         * ميتا تعيد الإشعارَ أيّامًا حين لا يُجاب بـ٢٠٠، فقد تصل «مرحبا» كُتبت
         * قبل يومين. والنصُّ الحرُّ بعد الأربعِ والعشرين يُردّ (١٣١٠٤٧) — وردٌّ
         * على تحيّةٍ قديمةٍ يُربك الزبونَ قبل أن يُردّ.
         */
        if ($row !== null && $row->received_at !== null && $row->received_at->lt(now()->subHours(self::WINDOW_HOURS))) {
            return 'outside_window';
        }

        /*
         * ═══ والردُّ الآليّ رسالةٌ تخرج — فيسأل بابَ الإرسال نفسَه ═══
         *
         * كان يردّ ما دام صاحبُ المحلّ أشعله، ولو أطفأ فريقُ أبعاد واتساب
         * لحسابه أو خرجت باقتُه منه أو سُحب ربطُ رقمه: إرسالٌ لم يُؤذن به.
         * فيُسأل ما يسأله كلُّ إرسال (`WhatsAppFeature::blockReason`)، والردُّ
         * يخرج من رقم المتجر فيُسأل إذنُه (`canUseOwnNumber`). والإشعالُ
         * يبقى في إعداده: يعود الإذنُ فيعود الردّ.
         */
        $business = Business::find($connection->business_id);

        // والسببُ بحروف سجلّ الإرسال نفسِها (`WhatsAppStatus::SKIP_*`) — لا قاموسٌ ثانٍ
        if ($business === null) {
            return WhatsAppStatus::SKIP_AUTOMATION_OFF;
        }

        if (($blocked = WhatsAppFeature::blockReason($business)) !== null) {
            return $blocked;
        }

        // وفي وضع رقم أبعاد يبقى ربطُ رقمه — ولا يردّ منه ما لم يملك ربطه
        if (! WhatsAppFeature::canUseOwnNumber($business)) {
            return WhatsAppStatus::SKIP_OWN_NOT_ALLOWED;
        }

        /*
         * وربطٌ بتطبيق التاجر لم يُراجَع دليلُ فوترته لا يردّ — باسمه لا بحالٍ عامّة.
         *
         * بابُ الإرسال يمنعه أيضًا (`MetaWhatsAppClient::account`)، لكنّ «لم يُردّ»
         * يُقال بسببه: بانتظار إثبات الفوترة — لا «فشل».
         */
        if (WhatsAppOwnApp::awaitsBilling($connection)) {
            return WhatsAppOwnApp::AWAITS_BILLING;
        }

        if (! $connection->isUsable()) {
            return 'connection_unusable';
        }

        // ولا يُعرف بأمان على أيّ حساب رسائل تُحتسب — لا ردّ (`WhatsAppOwnApp::sendAccount`)
        if (($account = WhatsAppOwnApp::sendAccount($connection)['problem']) !== null) {
            return $account;
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

        // وما لم يكتب صاحبُ المتجر نصًّا يُردّ بالنصّ العامّ — باسم متجره هو، بلغة زبونه
        if ($body === '') {
            $body = self::defaultText((int) $connection->business_id, $language === 'en' ? 'en' : 'ar');
        }

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
     * النصُّ العامّ — باسم هذا المتجر وحده، يُقرأ لحظةَ الردّ.
     *
     * لا يُحفظ في الإعدادات: من بدّل اسمَ متجره يُردّ باسمه الجديد، ولا يقع اسمُ
     * متجرٍ في ردّ غيره — يُقرأ من صفّ المتجر الذي وصلته الرسالة. والإنجليزيُّ
     * بالاسم الإنجليزيّ إن كُتب، وإلّا بالاسم كما هو.
     */
    public static function defaultText(int $businessId, string $lang): string
    {
        $business = Business::find($businessId);
        $name = trim((string) ($lang === 'en' ? ($business?->name_en ?: $business?->name) : $business?->name));

        if ($lang === 'en') {
            return $name !== ''
                ? 'Welcome to '.$name.'! Thank you for reaching out — we have received your message and will reply shortly.'
                : 'Welcome! Thank you for reaching out — we have received your message and will reply shortly.';
        }

        return $name !== ''
            ? 'أهلًا بك في '.$name.'! شكرًا لتواصلك معنا — وصلتنا رسالتك وسنردّ عليك قريبًا.'
            : 'أهلًا بك! شكرًا لتواصلك معنا — وصلتنا رسالتك وسنردّ عليك قريبًا.';
    }

    /**
     * أيعمل الردُّ الآن؟ — بالحرّاس نفسِها التي يمرّ بها كلُّ ردّ، لا بمقبض الإشعال وحده.
     *
     * «يعمل» حين يُرسل فعلًا لو كتب زبونٌ الآن. و«بانتظار» حين يُشعَل ويمنعه
     * ربطٌ لم يكتمل أو فوترةٌ لم تُراجَع أو باقة — والسببُ بكلماته. و«متوقف»
     * لمن أطفأه.
     *
     * @return array{state:string, reason:?string}
     */
    public static function state(Business $business): array
    {
        if (! self::settings((int) $business->id)['enabled']) {
            return ['state' => self::OFF, 'reason' => null];
        }

        $connection = WhatsAppConnections::forBusiness((int) $business->id);

        $reason = match (true) {
            WhatsAppFeature::blockReason($business) !== null => __('واتساب غير متاح لحسابك الآن — يعمل الرد حين يُفتح.'),
            ! WhatsAppFeature::canUseOwnNumber($business) => __('يعمل الرد من رقم متجرك الخاص وحده — باقتك لا تتيح الرقم الخاص بعد.'),
            $connection === null || $connection->owner_type !== WhatsAppMode::OWNER_BUSINESS => __('بانتظار ربط رقم متجرك — يعمل الرد فور اكتماله.'),
            WhatsAppOwnApp::awaitsBilling($connection) => __('بانتظار إثبات فوترة ميتا — يعمل الرد فور مراجعة الدليل.'),
            ! $connection->isUsable() => __('بانتظار اكتمال ربط رقمك — يعمل الرد فور عودته.'),
            WhatsAppOwnApp::sendAccount($connection)['problem'] !== null => WhatsAppOwnApp::say((string) WhatsAppOwnApp::sendAccount($connection)['problem']),
            default => null,
        };

        return ['state' => $reason === null ? self::WORKING : self::WAITING, 'reason' => $reason];
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
            ])->save();

            return;
        }

        $row->forceFill([
            'reply_status' => WhatsAppInboundMessage::FAILED,
            'reply_reason' => mb_substr((string) $result['code'], 0, 60),
            // ويُرفع الحجزُ (`claim`) — فالتهدئةُ لا تقرأ ردًّا لم يصل
            'replied_at' => null,
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
