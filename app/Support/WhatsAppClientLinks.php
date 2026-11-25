<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppClientLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ‏‏«أرسل رابطًا لعميلك» — فريقُ أبعاد يرسل رابطًا، وصاحبُ المتجر يفتحه فيربط رقم واتساب بنفسه.
 *
 * ═══ ولمَ رابطٌ ═══
 *
 * من يملك حساب فيسبوك النشاط ليس دائمًا من يدخل أبعاد، وفريقُنا لا يطلب كلمةَ مرور أحد. فالرابطُ
 * يفتح صفحةً واحدة: اسمُ المتجر، وطريقتا الربط، ونافذةُ Meta. لا تُدخل لوحةَ المتجر ولا تعرض منه شيئًا.
 *
 * ═══ وما يحرسه ═══
 *
 *   · الرمزُ أربعون حرفًا لا تُخمَّن، ولا يُحفظ منه إلّا بصمتُه. ويُعرض للفريق مرّةً عند إنشائه.
 *   · لمرّةٍ واحدة، و٧٢ ساعة، ورابطٌ واحدٌ مفتوح لكلّ متجر: الجديدُ يُلغي ما قبله.
 *   · لا يمسّ رقمًا يعمل ولا وصلةَ تطبيق التاجر (`refusal`) — تُفحص عند الإنشاء، وعند فتح الصفحة،
 *     وعند الربط نفسه: متجرٌ رُبط من حسابه بعد إرسال الرابط لا يبدّله الرابط.
 *   · والربطُ يمرّ بالباب نفسه (`WhatsAppOnboarding::connect`): الرمزُ من ميتا، والرقمُ من حسابه، ورقمُ
 *     متجرٍ آخر لا يُنتزع.
 *   · ويُقيَّد: من أنشأ ومتى فُتح واستُعمل وأُلغي، ويُقال لصاحب المتجر وللفريق في الجرس.
 *
 * ‏‏وروابطُ Meta الجاهزة (Hosted Embedded Signup) لا تحمل لأيّ متجرٍ هي، ولا تقبل رقمًا باقيًا في تطبيق
 * واتساب للأعمال — فالصفحةُ صفحتُنا.
 */
final class WhatsAppClientLinks
{
    public const HOURS = 72;

    public const TOKEN_LENGTH = 40;

    /** ‏‏كم مرّةً يصل الخادمَ إذنٌ من ميتا برابطٍ واحد — وبعدها يُنشأ رابطٌ جديد */
    public const MAX_ATTEMPTS = 10;

    /** ‏‏كم ساعةً يبقى تفعيلُ الرقم برمزه من الصفحة بعد الربط — وبعدها من حساب المتجر */
    public const PIN_HOURS = 24;

    /** ‏‏أيّامُ الخبر في الجرس — أقلُّ من عمر الإخفاء (`Demo::DISMISSAL_DAYS`) */
    public const BELL_DAYS = 7;

    public const OPEN = 'open';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    /** ‏‏استُنفدت محاولاتُه */
    public const EXHAUSTED = 'exhausted';

    /** ‏‏الرابطُ سليم، والمتجرُ لا يُربط به الآن (`refusal`) */
    public const CLOSED = 'closed';

    /** ‏‏لا رابطَ في هذا المتصفّح */
    public const MISSING = 'missing';

    private const LABELS = [
        self::OPEN => 'ينتظر العميل',
        self::USED => 'رُبط الرقم',
        self::EXPIRED => 'انتهت مدّته',
        self::REVOKED => 'أُلغي',
        self::EXHAUSTED => 'استُنفدت محاولاته',
    ];

    /** ‏‏ما يُرسل معاينةَ الرابط قبل أن يفتحه أحد — لا يُكتب «فُتح» بسببه */
    private const PREVIEW_AGENTS = '/whatsapp|facebookexternalhit|facebot|telegrambot|twitterbot|slackbot|discordbot|linkedinbot|skypeuripreview/i';

    /* ═════════════ من أيّ متجرٍ يُقبل ═════════════ */

    /** ‏‏لماذا لا يُربط هذا المتجرُ برابطٍ الآن — نصٌّ لفريق أبعاد، و`null`: يُربط */
    public static function refusal(Business $business): ?string
    {
        if (WhatsAppOwnApp::enabledFor((int) $business->id)) {
            return __('الرابط يعمل بعد نقل التجّار الجدد إلى تطبيق أبعاد، أو إضافة هذا المتجر إلى متاجر التجربة — من إعدادات المنصة، تبويب واتساب.');
        }

        if (! WhatsAppEmbeddedSignup::configured()) {
            return __('الربط بضغطة غير جاهز — أكمل إعداده من إعدادات المنصة، تبويب واتساب.');
        }

        if (in_array((string) $business->status, ['معطل', 'معطّل', Business::AWAITING_PAYMENT], true) || Tenancy::locked($business)) {
            return __('حساب المتجر موقوف أو بانتظار السداد — لا يُربط رقمه الآن.');
        }

        if (! WhatsAppFeature::canUseOwnNumber($business)) {
            return __('ربط رقم المتجر غير متاح له — فعّل صلاحية رقمه الخاص، أو أضف خدمته إن كانت تُباع له إضافةً.');
        }

        $existing = WhatsAppConnections::forBusiness((int) $business->id);

        // ‏‏وصلةُ تطبيق التاجر لا تُمسّ برابط — ولو مفصولة: صفُّها يحمل سرَّ تطبيقه وعنوانَ إشعاراته
        if (WhatsAppOwnApp::is($existing)) {
            return __('هذا المتجر ربط رقمه بتطبيقه على Meta — لا يُربط برابط.');
        }

        // ‏‏رُبط من حسابه وينتظر رمزَه — لا يحلّ رابطٌ محلَّ رقمٍ بقيت له خطوة
        if (WhatsAppOnboarding::awaitsRegistration($existing)) {
            return __('رقم المتجر رُبط وينتظر رمز التفعيل — يكتبه صاحبه من صفحة واتساب في حسابه.');
        }

        // ‏‏ورقمٌ سُحب إذنُه عند Meta «مربوطٌ» عندنا ولا يُرسل — يُعاد ربطُه بالرابط كما يُعاد من الحساب
        if ($existing?->isConnected() && ! WhatsAppSender::needsRelink($business, $existing)) {
            return WhatsAppBillingLock::stop($existing) === null
                ? __('رقم المتجر مربوط ويعمل — لا يبدّله رابط. إن أراد رقمًا آخر فليفصله من حسابه أوّلًا.')
                : __('رقم المتجر مربوط وينتظر إثبات جهة رسومه — لا يبدّله رابط. إن أراد رقمًا آخر فليفصله من حسابه أوّلًا.');
        }

        return null;
    }

    /* ═════════════ فريقُ أبعاد ═════════════ */

    /**
     * ‏‏يُنشئ رابطًا ويُلغي المفتوحَ قبله — والرمزُ يعود مرّةً واحدة في العنوان ولا يُحفظ.
     *
     * @return array{ok: bool, message: ?string, link: ?WhatsAppClientLink, url: ?string}
     */
    public static function create(Business $business, User $staff): array
    {
        return DB::transaction(function () use ($business, $staff) {
            // ‏‏ضغطتان معًا: الثانيةُ تنتظر فتُلغي رابطَ الأولى — ولا يبقى رابطان مفتوحان
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();

            if ($why = self::refusal($business)) {
                return ['ok' => false, 'message' => $why, 'link' => null, 'url' => null];
            }

            self::close((int) $business->id, (string) $staff->name);

            $token = Str::random(self::TOKEN_LENGTH);
            $link = WhatsAppClientLink::create([
                'business_id' => $business->id,
                'token_hash' => self::hash($token),
                'created_by' => $staff->id,
                'created_by_name' => mb_substr((string) $staff->name, 0, 120),
                'expires_at' => now()->addHours(self::HOURS),
            ]);

            Activity::log('settings', 'واتساب — أنشأ رابط ربطٍ لصاحب «'.$business->name.'» صالحًا حتى '.self::stamp($link->expires_at),
                ['business_id' => $business->id]);

            return ['ok' => true, 'message' => null, 'link' => $link, 'url' => route('clientLink.open', $token)];
        });
    }

    /** ‏‏يُلغي الرابطَ المفتوح لهذا المتجر — ولا يفصل رقمًا رُبط به */
    public static function revoke(Business $business, User $staff): bool
    {
        $closed = DB::transaction(function () use ($business, $staff) {
            Business::whereKey($business->id)->lockForUpdate()->first();

            return self::close((int) $business->id, (string) $staff->name);
        });

        if ($closed > 0) {
            Activity::log('settings', 'واتساب — ألغى رابط الربط المرسل لصاحب «'.$business->name.'»', ['business_id' => $business->id]);
        }

        return $closed > 0;
    }

    private static function close(int $businessId, string $by): int
    {
        return WhatsAppClientLink::where('business_id', $businessId)
            ->whereNull('connected_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->update(['revoked_at' => now(), 'revoked_by_name' => mb_substr($by, 0, 120), 'updated_at' => now()]);
    }

    /**
     * ‏‏ما تعرضه صفحةُ المتجر عند فريق أبعاد — الحالُ وآخرُ رابط، ولا رمزَ ولا عنوان.
     *
     * @return array<string, mixed>
     */
    public static function view(Business $business): array
    {
        $link = WhatsAppClientLink::where('business_id', $business->id)->orderByDesc('id')->first();
        $why = self::refusal($business);
        $state = $link ? self::state($link) : null;

        return [
            'can' => $why === null,
            'why' => $why,
            'hours' => self::HOURS,
            'link' => $link ? [
                'state' => $state,
                'label' => __(self::LABELS[$state] ?? $state),
                'created_by' => $link->created_by_name,
                'created_at' => self::stamp($link->created_at),
                'expires_at' => self::stamp($link->expires_at),
                'opened_at' => self::stamp($link->opened_at),
                'attempts' => $link->attempts,
                'problem' => $link->last_problem,
                'connected_at' => self::stamp($link->connected_at),
                'phone' => $link->connected_phone,
            ] : null,
        ];
    }

    /** ‏‏الرسالةُ الجاهزة — يراجعها الموظّف ويرسلها بنفسه */
    public static function message(WhatsAppClientLink $link, string $url): string
    {
        return implode("\n", [
            __('مرحبًا،'),
            __('هذا رابط ربط واتساب متجر «:shop» بنظام أبعاد:', ['shop' => (string) $link->business?->name]),
            $url,
            __('افتحه وسجّل الدخول بحساب فيسبوك نشاطك. يعمل مرّة واحدة، وينتهي في :date.', ['date' => (string) self::stamp($link->expires_at)]),
            __('لا نطلب منك كلمة مرور، فلا ترسلها لأحد.'),
        ]);
    }

    /**
     * ‏‏ما رُبط من الروابط في آخر أيّام — للجرس: لفريق أبعاد عن كلّ متجر، ولصاحب المتجر عن متجره.
     *
     * @return Collection<int, WhatsAppClientLink>
     */
    public static function recent(int $limit, ?int $businessId = null): Collection
    {
        return WhatsAppClientLink::with('business:id,name')
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->where('connected_at', '>=', now()->subDays(self::BELL_DAYS))
            ->orderByDesc('connected_at')->limit($limit)->get();
    }

    /* ═════════════ صاحبُ المتجر ═════════════ */

    public static function find(string $token): ?WhatsAppClientLink
    {
        return WhatsAppClientLink::where('token_hash', self::hash($token))->first();
    }

    public static function state(WhatsAppClientLink $link): string
    {
        return match (true) {
            $link->connected_at !== null => self::USED,
            $link->revoked_at !== null => self::REVOKED,
            $link->expires_at->isPast() => self::EXPIRED,
            $link->attempts >= self::MAX_ATTEMPTS => self::EXHAUSTED,
            default => self::OPEN,
        };
    }

    /** ‏‏حالُ الرابط كما تراه صفحةُ العميل — والمفتوحُ لمتجرٍ لا يُربط الآن «مغلق» */
    public static function pageState(WhatsAppClientLink $link): string
    {
        $state = self::state($link);

        return $state === self::OPEN && ($link->business === null || self::refusal($link->business) !== null)
            ? self::CLOSED
            : $state;
    }

    /** ‏‏أوّلُ فتحٍ بمتصفّحٍ حقيقيّ — معاينةُ الرابط في المحادثة ليست فتحًا */
    public static function opened(WhatsAppClientLink $link, ?string $agent): void
    {
        if ($link->opened_at !== null || preg_match(self::PREVIEW_AGENTS, (string) $agent)) {
            return;
        }

        WhatsAppClientLink::whereKey($link->id)->whereNull('opened_at')->update(['opened_at' => now()]);
    }

    /**
     * ‏‏يربط رقمَ المتجر بإذنٍ وصل من صفحة الرابط.
     *
     * ويُعاد كلُّ فحصٍ تحت قفل المتجر: رابطٌ أُلغي والنافذةُ مفتوحة، أو رقمٌ رُبط من الحساب في الدقيقة
     * نفسها، أو ضغطتان من جهازين — لا يربط منها إلّا ما بقي مفتوحًا. ولا يُستهلك الرابطُ إلّا برقمٍ
     * رُبط: إذنٌ لم يُظهر رقمًا بعدُ يُعاد به.
     *
     * @return array{ok: bool, field: string, message: string, pending: bool}
     */
    public static function connect(WhatsAppClientLink $link, string $code, ?string $wabaId, ?string $phoneId, string $type, ?string $ip): array
    {
        $lock = Cache::lock('whatsapp-client-link:'.$link->business_id, 120);

        if (! $lock->get()) {
            return self::no('code', __('يجري ربطٌ آخر لهذا المتجر الآن — انتظر دقيقة ثمّ أعد المحاولة.'));
        }

        try {
            $link = WhatsAppClientLink::with('business')->find($link->id);

            if ($link === null || self::pageState($link) !== self::OPEN) {
                return self::no('code', __('لم يعد هذا الرابط يعمل — اطلب من فريق أبعاد رابطًا جديدًا.'));
            }

            if ($type === WhatsAppOnboarding::COEXISTENCE && ! WhatsAppOnboarding::coexistenceOpen()) {
                return self::no('code', __('ربط رقمٍ على تطبيق واتساب للأعمال (التعايش) غير متاحٍ بعد — اختر «رقم Cloud API مستقل».'));
            }

            $link->increment('attempts');

            $result = WhatsAppOnboarding::connect($link->business, null, $code, $wabaId, $phoneId, $type);

            if (! $result['ok']) {
                $link->update(['last_problem' => mb_substr((string) $result['message'], 0, 255)]);

                return self::no((string) $result['field'], (string) $result['message']);
            }

            $connection = $result['connection'];

            if (blank($connection?->phone_number_id)) {
                $link->update(['last_problem' => mb_substr((string) $result['message'], 0, 255)]);

                return ['ok' => true, 'field' => '', 'message' => __('تمّ التفويض، وحسابك لم يُظهر رقمًا بعد. انتظر دقائق ثمّ اضغط زرّ الربط مرّةً أخرى.'), 'pending' => true];
            }

            $link->update([
                'connected_at' => now(),
                'whatsapp_connection_id' => $connection->id,
                'connected_phone' => mb_substr((string) ($connection->display_phone_number ?: $connection->phone_number_id), 0, 40),
                'connected_ip' => $ip,
                'last_problem' => null,
            ]);

            Activity::log('settings', 'واتساب — رُبط رقم المتجر ('.$link->connected_phone.') من الرابط الذي أرسله '.($link->created_by_name ?: 'فريق أبعاد'),
                ['business_id' => $link->business_id]);

            return ['ok' => true, 'field' => '', 'message' => (string) $result['message'], 'pending' => (bool) $result['pending']];
        } finally {
            $lock->release();
        }
    }

    /** ‏‏أينتظر الرقمُ الذي رُبط بهذا الرابط رمزَ تفعيله؟ — يومًا بعد الربط، ثمّ من حساب المتجر */
    public static function awaitsPin(WhatsAppClientLink $link): bool
    {
        if ($link->connected_at === null || $link->connected_at->copy()->addHours(self::PIN_HOURS)->isPast()) {
            return false;
        }

        $connection = WhatsAppConnections::forBusiness((int) $link->business_id);

        return $connection !== null
            && (int) $connection->id === (int) $link->whatsapp_connection_id
            && WhatsAppOnboarding::awaitsRegistration($connection);
    }

    /** @return array{ok: bool, message: string} */
    public static function register(WhatsAppClientLink $link, string $pin): array
    {
        if ($link->business === null || ! self::awaitsPin($link)) {
            return ['ok' => false, 'message' => __('لا رقم ينتظر التفعيل من هذه الصفحة — فعّله من حساب متجرك في أبعاد.')];
        }

        return WhatsAppOnboarding::register($link->business, $pin);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function stamp(?CarbonInterface $at): ?string
    {
        return $at?->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i');
    }

    /** @return array{ok: bool, field: string, message: string, pending: bool} */
    private static function no(string $field, string $message): array
    {
        return ['ok' => false, 'field' => $field, 'message' => $message, 'pending' => false];
    }
}
