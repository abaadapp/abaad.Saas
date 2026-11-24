<?php

namespace App\Support;

use App\Models\Business;
use App\Models\ChannelAutomation;
use App\Models\ChannelAutomationRun;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Support\Website\Sitemap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ‏‏محرّكُ الأتمتة — ردٌّ يكتبه التاجر مرّةً فيخرج وحده حين يكتب زبونٌ كلمةً بعينها.
 *
 * ═══ أين يقع من رسالةٍ تصل ═══
 *
 * بابُ الإشعارات ← الصندوق يكتبها ← الطلبُ الآليّ أوّلًا (له الأولويّة كما كان) ← **هنا** ←
 * الترحيبُ لما لم يطابق كلمة (`WhatsAppAutoReply::receive`). فلا يتغيّر شيءٌ لمتجرٍ لم يكتب أتمتة،
 * ولا لمتجرٍ لم تُفتح له (`ChannelAutomationsSwitch`).
 *
 * ═══ وأتمتةٌ واحدة لكلّ رسالة ═══
 *
 * المطابقةُ التامّة قبل الجزئيّة، ثمّ الكلمةُ الأطول، ثمّ الأقدم. والكلماتُ المستثناة تُخرج
 * الأتمتة من الحساب. ومفتاحُ السجلّ الفريد (`wa:<wamid>`) يمنع ردّين على رسالةٍ واحدة.
 *
 * ═══ وما يمنع الردّ ═══
 *
 * حرّاسُ الترحيب نفسُها بترتيبها (`WhatsAppAutoReply::blocked`): نافذةُ ٢٤ ساعة، وإذنُ واتساب
 * والباقة، وفوترةُ تطبيق التاجر، وصحّةُ الربط. ثمّ ما يخصّ الأتمتة:
 *  - التاجرُ ردّ من هاتفه (التعايش)، أو موظّفٌ ردّ من الصندوق في الساعة الأخيرة — فلا تقطعهما.
 *  - الأتمتةُ نفسُها ردّت على الزبون نفسِه في مدّة انتظارها — فلا يقرأ الردَّ مرّتين.
 *
 * والسببُ يُكتب في السجلّ بحروفه، فيُعرف لماذا لم يُردّ.
 */
class ChannelAutomations
{
    public const WHATSAPP = 'whatsapp';

    public const INSTAGRAM = 'instagram';

    public const CHANNELS = [self::WHATSAPP, self::INSTAGRAM];

    public const TRIGGER_KEYWORD = 'keyword';

    /** ‏‏ما يشغّل أتمتةً في كلّ قناة — وإنستقرام تعليقٌ أو رسالةٌ خاصّة (`InstagramAutomations`) */
    public const TRIGGERS = [
        self::WHATSAPP => [self::TRIGGER_KEYWORD],
        self::INSTAGRAM => [InstagramAutomations::COMMENT, InstagramAutomations::MESSAGE],
    ];

    /** ‏‏منشوراتٌ يختارها التاجر لأتمتة تعليق — وردودٌ علنيّة تُكتب تحته بالدور */
    public const MAX_POSTS = 20;

    public const MAX_PUBLIC = 5;

    public const POST_ID = '/^[0-9]{5,40}$/';

    public const MAX_AUTOMATIONS = 50;

    public const MAX_KEYWORDS = 20;

    public const MAX_KEYWORD = 40;

    public const MAX_NAME = 80;

    public const MAX_TEXT = WhatsAppAutoReply::MAX_TEXT;

    public const MAX_BUTTONS = 3;

    public const BUTTON_TITLE_MAX = 20;

    /** منتجاتُ منتقي المعالج — أوّلُ ٣٠٠ منتجٍ نشطٍ بالاسم؛ وما بعدها لا يظهر فيه بعد */
    public const PICKER_PRODUCTS = 300;

    public const DEFAULT_COOLDOWN = 60;

    /** أطولُ انتظارٍ بين ردّين للزبون نفسِه — أسبوع */
    public const COOLDOWN_MAX = 10080;

    /** موظّفٌ ردّ من الصندوق في هذه المدّة — فالأتمتةُ تصمت ولا تقطعه */
    public const HANDOFF_MINUTES = 60;

    public const BUTTON_REPLY = 'reply';

    public const BUTTON_LINK = 'link';

    /** معرّفُ زرّ الردّ — وضغطُه يصل نصًّا بعنوانه فيطابق أتمتةً أخرى بكلمتها */
    public const REPLY_ID_PREFIX = 'auto:';

    /** ما بين قوسين مربّعين في قالبٍ ينتظر أن يكتبه التاجر — لا يُنشر قبله */
    public const PLACEHOLDER = '/\[[^\]\n]{1,80}\]/u';

    public const SENDING = 'sending';

    public const SKIP_COOLDOWN = 'cooldown';

    public const SKIP_STAFF = 'staff_replied';

    public const SKIP_PHONE = 'replied_from_phone';

    public const SKIP_EMPTY = 'empty_text';

    /** المتغيّراتُ — بالعربيّة والإنجليزيّة، وكلاهما يعمل في النصّين */
    public const VARIABLES = [
        'name' => ['{الاسم}', '{name}'],
        'store' => ['{المتجر}', '{store}'],
        'store_link' => ['{رابط_المتجر}', '{store_link}'],
        'product' => ['{المنتج}', '{product}'],
        'price' => ['{السعر}', '{price}'],
        'product_link' => ['{رابط_المنتج}', '{product_link}'],
        'last_order' => ['{آخر_طلب}', '{last_order}'],
    ];

    public static function available(?Business $business): bool
    {
        return $business !== null && ChannelAutomationsSwitch::allows((int) $business->id);
    }

    /**
     * ‏‏رسالةٌ وصلت رقمَ المتجر — أتأخذها أتمتة؟ وإن أخذتها ردّت أو قالت لماذا لم تردّ.
     *
     * `false`: لم تطابق شيئًا، فيبقى للترحيب ما كان له. ولا يُسقط عطبٌ هنا بابَ الإشعارات.
     */
    public static function receive(WhatsAppConnection $connection, array $message, WhatsAppInboundMessage $row): bool
    {
        try {
            return self::take($connection, $message, $row);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function take(WhatsAppConnection $connection, array $message, WhatsAppInboundMessage $row): bool
    {
        $business = Business::find($connection->business_id);

        if (! self::available($business)) {
            return false;
        }

        $input = WhatsAppOrders::input($message);

        if (in_array(strtolower($input['type']), WhatsAppAutoReply::NOT_A_MESSAGE, true) || $input['text'] === '') {
            return false;
        }

        $automation = self::pick((int) $business->id, self::WHATSAPP, $input['text']);

        if ($automation === null) {
            return false;
        }

        $from = (string) $row->from_phone;
        $chat = WhatsAppChat::where('business_id', $business->id)
            ->where('phone_number_id', (string) $connection->phone_number_id)
            ->where('customer_phone', $from)
            ->first();

        $lang = WhatsAppAutoReply::languageOf((int) $business->id, $from) === 'en' ? 'en' : 'ar';
        $body = self::render($automation, $business, $lang, $from, $chat);

        $reason = WhatsAppAutoReply::blocked($connection, $row)
            ?? (trim($body) === '' ? self::SKIP_EMPTY : null)
            ?? self::quiet((int) $business->id, $from, $automation);

        $run = $reason === null ? self::claim($business, $automation, $row) : null;

        if ($reason === null && $run === null) {
            $reason = self::SKIP_COOLDOWN;
        }

        if ($reason !== null) {
            self::skip($business, $automation, $row, $reason);

            return true;
        }

        self::deliver($business, $connection, $automation, $row, $run, $chat, $body, $lang);

        return true;
    }

    /**
     * ‏‏الأتمتةُ التي تأخذ هذا النصّ — أو `null`.
     *
     * التامّةُ قبل الجزئيّة، ثمّ الكلمةُ الأطول، ثمّ الأقدم. والمستثناةُ تُخرجها من الحساب.
     * وفي تعليقات إنستقرام: أتمتةُ منشورٍ بعينه قبل أتمتة كلّ المنشورات.
     *
     * @param  list<string>  $posts  المنشورُ الذي كُتب تحته التعليق (وأصلُه إن كان إعلانًا)
     */
    public static function pick(int $businessId, string $channel, string $text, ?string $trigger = null, array $posts = []): ?ChannelAutomation
    {
        $best = null;
        $score = null;

        $rows = ChannelAutomation::where('business_id', $businessId)
            ->where('channel', $channel)
            ->where('status', ChannelAutomation::PUBLISHED)
            ->orderBy('id')
            ->limit(self::MAX_AUTOMATIONS)
            ->get();

        foreach ($rows as $automation) {
            if ($trigger !== null && $automation->trigger !== $trigger) {
                continue;
            }

            $chosen = self::postIds($automation);

            if ($chosen !== [] && array_intersect($chosen, $posts) === []) {
                continue;
            }

            if (filled($automation->excluded) && KeywordMatch::first($text, (array) $automation->excluded) !== null) {
                continue;
            }

            $hit = $automation->match === KeywordMatch::ANY && $chosen !== []
                ? ''
                : KeywordMatch::first($text, (array) $automation->keywords, (string) $automation->match);

            if ($hit === null) {
                continue;
            }

            $mine = [$chosen !== [] ? 1 : 0, $automation->match === KeywordMatch::EXACT ? 1 : 0, mb_strlen(KeywordMatch::normalize($hit))];

            if ($score === null || $mine > $score) {
                [$best, $score] = [$automation, $mine];
            }
        }

        return $best;
    }

    /** @return list<string> */
    public static function postIds(ChannelAutomation $automation): array
    {
        return array_values(array_filter(array_map(fn ($p) => (string) (is_array($p) ? ($p['id'] ?? '') : $p), (array) ($automation->posts ?? []))));
    }

    /** ما يُسكت الأتمتةَ عن هذا الزبون الآن — غيرُ حرّاس الإرسال */
    private static function quiet(int $businessId, string $from, ChannelAutomation $automation): ?string
    {
        $hours = max(1, (int) ceil(max(1, (int) $automation->cooldown_minutes) / 60));

        if (WhatsAppCoexistence::repliedFromPhone($businessId, $from, $hours)) {
            return self::SKIP_PHONE;
        }

        $staff = WhatsAppChatMessage::where('business_id', $businessId)
            ->whereIn('chat_id', WhatsAppChat::where('business_id', $businessId)->where('customer_phone', $from)->select('id'))
            ->where('direction', WhatsAppChatMessage::OUT)
            ->whereNotNull('sender_user_id')
            ->where('created_at', '>', now()->subMinutes(self::HANDOFF_MINUTES))
            ->exists();

        return $staff ? self::SKIP_STAFF : null;
    }

    /**
     * ‏‏يحجز الردَّ قبل أن يخرج — ورسالتان من الزبون نفسِه في اللحظة نفسها لا تُردّان مرّتين.
     *
     * صفُّ المتجر مقفلٌ ريثما يُسأل عن ردٍّ سبقه من هذه الأتمتة في مدّة انتظارها، كما يفعل الترحيب
     * (`WhatsAppAutoReply::claim`). ويُختم `replied_at` على الوارد: الترحيبُ يقرؤه فلا يردّ فوقه.
     * و`null`: سبقه ردّ.
     */
    private static function claim(Business $business, ChannelAutomation $automation, WhatsAppInboundMessage $row): ?ChannelAutomationRun
    {
        return DB::transaction(function () use ($business, $automation, $row) {
            $run = self::reserve($business, $automation, self::WHATSAPP, self::sourceKey($row), (string) $row->from_phone);

            if ($run !== null) {
                $row->forceFill(['replied_at' => now()])->save();
            }

            return $run;
        });
    }

    /** ‏‏الحجزُ نفسُه لكلّ قناة — تحت قفل المتجر: سبق ردٌّ من هذه الأتمتة في مدّتها أو سبقت الرسالةُ نفسُها، فـ`null` */
    public static function reserve(Business $business, ChannelAutomation $automation, string $channel, string $sourceKey, string $recipient): ?ChannelAutomationRun
    {
        return DB::transaction(function () use ($business, $automation, $channel, $sourceKey, $recipient) {
            Business::whereKey($business->id)->lockForUpdate()->first();

            $taken = ChannelAutomationRun::where('business_id', $business->id)
                ->where('recipient', $recipient)
                ->where('automation_id', $automation->id)
                ->whereIn('status', [self::SENDING, ChannelAutomationRun::SENT])
                ->where('created_at', '>', now()->subMinutes(max(1, (int) $automation->cooldown_minutes)))
                ->exists();

            if ($taken) {
                return null;
            }

            $run = Contention::attempt(fn () => ChannelAutomationRun::create([
                'business_id' => $business->id,
                'automation_id' => $automation->id,
                'channel' => $channel,
                'source_key' => $sourceKey,
                'recipient' => $recipient,
                'status' => self::SENDING,
                'created_at' => now(),
            ]));

            return $run instanceof ChannelAutomationRun ? $run : null;
        });
    }

    private static function skip(Business $business, ChannelAutomation $automation, WhatsAppInboundMessage $row, string $reason): void
    {
        self::skipped($business, $automation, self::WHATSAPP, self::sourceKey($row), (string) $row->from_phone, $reason);

        $row->forceFill(['reply_status' => WhatsAppInboundMessage::SKIPPED, 'reply_reason' => mb_substr('automation_'.$reason, 0, 60)])->save();
    }

    /** ‏‏طابقت ولم يُردّ — وبأيّ سبب، في السجلّ */
    public static function skipped(Business $business, ChannelAutomation $automation, string $channel, string $sourceKey, string $recipient, string $reason): void
    {
        Contention::attempt(fn () => ChannelAutomationRun::create([
            'business_id' => $business->id,
            'automation_id' => $automation->id,
            'channel' => $channel,
            'source_key' => $sourceKey,
            'recipient' => $recipient,
            'status' => ChannelAutomationRun::SKIPPED,
            'reason' => mb_substr($reason, 0, 60),
            'created_at' => now(),
        ]));
    }

    private static function sourceKey(WhatsAppInboundMessage $row): string
    {
        return mb_substr('wa:'.$row->wamid, 0, 191);
    }

    /**
     * ‏‏الإخراج — في المحادثة إن كانت في الصندوق (فيرى الموظّف ما قيل باسم الأتمتة)، وإلّا مباشرة.
     *
     * والفشلُ يرفع الحجز عن الوارد: الترحيبُ والأتمتةُ لا يقرآن ردًّا لم يصل.
     */
    private static function deliver(Business $business, WhatsAppConnection $connection, ChannelAutomation $automation, WhatsAppInboundMessage $row, ChannelAutomationRun $run, ?WhatsAppChat $chat, string $body, string $lang): void
    {
        $message = self::message($automation, $business, $body);

        if ($chat !== null) {
            $sent = WhatsAppOrderReplies::send($chat, $connection, $message, $lang, __('رد آلي').' · '.$automation->name);
            $ok = $sent->status === WhatsAppStatus::SENT;
            $code = $ok ? null : (string) ($sent->error_code ?: 'provider_failed');
        } else {
            $result = $message['kind'] === 'text'
                ? MetaWhatsAppClient::sendText($connection, (string) $row->from_phone, mb_substr((string) $message['body'], 0, WhatsAppOrderReplies::TEXT_MAX))
                : MetaWhatsAppClient::sendInteractive($connection, (string) $row->from_phone, WhatsAppOrderReplies::interactive($message));
            $ok = (bool) $result['ok'];
            $code = $ok ? null : (string) ($result['code'] ?: 'provider_failed');
        }

        $run->forceFill(['status' => $ok ? ChannelAutomationRun::SENT : ChannelAutomationRun::FAILED, 'reason' => $code === null ? null : mb_substr($code, 0, 60)])->save();

        $row->forceFill($ok
            ? ['reply_status' => WhatsAppInboundMessage::SENT, 'reply_reason' => mb_substr('automation:'.$automation->id, 0, 60)]
            : ['reply_status' => WhatsAppInboundMessage::FAILED, 'reply_reason' => mb_substr((string) $code, 0, 60), 'replied_at' => null])->save();

        if (! $ok) {
            Log::warning('whatsapp.automation_failed', ['business_id' => $business->id, 'automation_id' => $automation->id, 'meta_code' => $code]);
        }
    }

    /**
     * ‏‏الرسالةُ كما تخرج: نصٌّ، أو نصٌّ بأزرار ردٍّ (ثلاثةٌ على الأكثر)، أو نصٌّ بزرّ رابطٍ واحد.
     *
     * @return array<string, mixed>
     */
    public static function message(ChannelAutomation $automation, Business $business, string $body): array
    {
        $buttons = array_values(array_filter((array) $automation->buttons, fn ($b) => is_array($b) && filled($b['title'] ?? null)));
        $link = collect($buttons)->firstWhere('type', self::BUTTON_LINK);

        if ($link !== null) {
            $url = self::url((string) ($link['url'] ?? ''), $automation, $business);

            return $url !== null
                ? ['kind' => 'cta', 'body' => $body, 'label' => (string) $link['title'], 'url' => $url]
                : ['kind' => 'text', 'body' => $body];
        }

        $replies = array_slice(array_values(array_filter($buttons, fn ($b) => ($b['type'] ?? null) === self::BUTTON_REPLY)), 0, self::MAX_BUTTONS);

        if ($replies === []) {
            return ['kind' => 'text', 'body' => $body];
        }

        return ['kind' => 'buttons', 'body' => $body, 'buttons' => array_map(
            fn ($b, $i) => [self::REPLY_ID_PREFIX.$automation->id.':'.$i, (string) $b['title']],
            $replies, array_keys($replies),
        )];
    }

    /** عنوانُ زرّ الرابط — رابطٌ مكتوب، أو رابطُ المتجر أو المنتج بمتغيّره */
    private static function url(string $url, ChannelAutomation $automation, Business $business): ?string
    {
        $url = trim($url);

        if (in_array($url, self::VARIABLES['store_link'], true)) {
            return self::storeLink($business);
        }

        if (in_array($url, self::VARIABLES['product_link'], true)) {
            return self::productLink($business, self::product($automation));
        }

        return preg_match('~^https://[^\s]+$~i', $url) ? $url : null;
    }

    /**
     * ‏‏نصُّ الردّ بلغة الزبون، والمتغيّراتُ بقيمها لهذا المتجر وهذا الزبون — تُقرأ لحظةَ الردّ.
     *
     * ومتغيّرٌ بلا قيمة يُحذف ولا يخرج بقوسيه. و`$known`: ما تعرفه القناةُ عن الزبون بلا رقم هاتف
     * (اسمُه في إنستقرام) — فلا يُسأل عنه.
     *
     * @param  array<string, string>  $known
     */
    public static function render(ChannelAutomation $automation, Business $business, string $lang, ?string $phone = null, ?WhatsAppChat $chat = null, array $known = []): string
    {
        $text = trim($lang === 'en'
            ? ((string) $automation->reply_en ?: (string) $automation->reply_ar)
            : ((string) $automation->reply_ar ?: (string) $automation->reply_en));

        if ($text === '') {
            return '';
        }

        $product = self::product($automation);
        $values = [];

        foreach (self::VARIABLES as $key => $tokens) {
            if (! self::uses($text, $tokens)) {
                continue;
            }

            $values[$key] = $known[$key] ?? match ($key) {
                'name' => self::name($business, $phone, $chat),
                'store' => trim((string) ($lang === 'en' ? ($business->name_en ?: $business->name) : $business->name)),
                'store_link' => (string) self::storeLink($business),
                'product' => $product === null ? '' : trim((string) ($lang === 'en' && filled($product->name_en ?? null) ? $product->name_en : $product->name)),
                'price' => $product === null ? '' : Storefront::amount((float) $product->price, Storefront::currency($business)),
                'product_link' => (string) self::productLink($business, $product),
                'last_order' => self::lastOrder($business, $phone, $chat, $lang),
            };

            $text = str_replace($tokens, $values[$key], $text);
        }

        $text = preg_replace('/[ \t]+([،,.!؟?])/u', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** ‏‏الاسمُ وحده في الردّ العلنيّ — وما سواه من متغيّراتٍ يُحذف: لا سعرَ ولا طلبَ يُكتب تحت منشور */
    public static function renderPublic(string $text, ?string $name): string
    {
        foreach (self::VARIABLES as $key => $tokens) {
            $text = str_replace($tokens, $key === 'name' ? (string) $name : '', $text);
        }

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
    }

    /** @param list<string> $tokens */
    private static function uses(string $text, array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (str_contains($text, $t)) {
                return true;
            }
        }

        return false;
    }

    private static function product(ChannelAutomation $automation): ?Product
    {
        if (blank($automation->product_id)) {
            return null;
        }

        return Product::where('business_id', $automation->business_id)->whereKey($automation->product_id)->first();
    }

    private static function name(Business $business, ?string $phone, ?WhatsAppChat $chat): string
    {
        $customerId = $chat?->customer_id ?: ($phone ? WhatsAppInbox::customerFor((int) $business->id, $phone) : null);
        $name = $customerId ? Customer::where('business_id', $business->id)->whereKey($customerId)->value('name') : null;

        return mb_substr(trim((string) ($name ?: $chat?->profile_name)), 0, 60);
    }

    /** ‏‏رابطُ المتجر كما يُعطى للزبون — أو `null` لمتجرٍ بلا موقع */
    public static function storeLink(Business $business): ?string
    {
        return Sitemap::root($business);
    }

    /** ‏‏رابطُ صفحة المنتج في الواجهة الخاصّة إن كان معروضًا فيها — وإلّا رابطُ المتجر */
    public static function productLink(Business $business, ?Product $product): ?string
    {
        $root = self::storeLink($business);

        if ($root === null || $product === null) {
            return $root;
        }

        $shown = (bool) $product->active && (bool) $product->published;

        return Storefront::serves($business) === Storefront::SERVES_THEME && $shown ? $root.'/p/'.$product->id : $root;
    }

    /** ‏‏آخرُ طلبٍ لهذا الرقم في هذا المتجر — رقمُه وحالُه — أو جملةٌ تقول إنّه لم يُعرف */
    public static function lastOrder(Business $business, ?string $phone, ?WhatsAppChat $chat, string $lang): string
    {
        $customerId = $chat?->customer_id ?: ($phone ? WhatsAppInbox::customerFor((int) $business->id, $phone) : null);
        $order = $customerId
            ? Order::where('business_id', $business->id)->where('customer_id', $customerId)->orderByDesc('id')->first(['id', 'number', 'status'])
            : null;

        if ($order === null) {
            return $lang === 'en'
                ? "We couldn't find an order under your number — our team will reply shortly."
                : 'لم نجد طلبًا مسجّلًا برقمك — سيردّ عليك فريقنا قريبًا.';
        }

        return $lang === 'en'
            ? 'Your order '.$order->number.': '.__((string) $order->status, [], 'en')
            : 'طلبك رقم '.$order->number.': '.$order->status;
    }

    /* ═══════════ ما يكتبه التاجر ═══════════ */

    /**
     * ‏‏حفظُ أتمتة — جديدةٍ أو قائمة — بعد أن صحّت الحقول في المتحكّم.
     *
     * والنشرُ يُرفض ما لم تكن له كلمةٌ ونصّ، وما بقي في نصّه ما بين قوسين ينتظر أن يُكتب.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, automation: ?ChannelAutomation, message: ?string}
     */
    public static function save(Business $business, array $data, ?ChannelAutomation $automation, ?User $user, string $channel = self::WHATSAPP): array
    {
        $channel = $automation?->channel ?? $channel;
        $triggers = self::TRIGGERS[$channel];
        $trigger = in_array($data['trigger'] ?? null, $triggers, true) ? $data['trigger'] : ($automation?->trigger ?? $triggers[0]);
        $onComment = $trigger === InstagramAutomations::COMMENT;
        $posts = $onComment ? self::posts((array) ($data['posts'] ?? [])) : [];
        $public = $onComment ? self::publicReplies((array) ($data['public_replies'] ?? [])) : [];
        $match = in_array($data['match'] ?? null, $onComment ? [...KeywordMatch::MODES, KeywordMatch::ANY] : KeywordMatch::MODES, true)
            ? $data['match'] : KeywordMatch::CONTAINS;
        $keywords = self::words((array) ($data['keywords'] ?? []));
        $excluded = self::words((array) ($data['excluded'] ?? []));
        // ‏‏والردُّ الخاصّ على التعليق نصٌّ لا غير — لا زرَّ فيه عند Meta
        $buttons = $onComment ? [] : self::buttons((array) ($data['buttons'] ?? []));
        $status = ($data['status'] ?? null) === ChannelAutomation::PUBLISHED ? ChannelAutomation::PUBLISHED : ChannelAutomation::DRAFT;
        $ar = trim((string) ($data['reply_ar'] ?? ''));
        $en = trim((string) ($data['reply_en'] ?? ''));

        $draft = new ChannelAutomation([
            'channel' => $channel, 'trigger' => $trigger, 'match' => $match, 'keywords' => $keywords,
            'reply_ar' => $ar, 'reply_en' => $en, 'buttons' => $buttons, 'posts' => $posts, 'public_replies' => $public,
        ]);

        if ($status === ChannelAutomation::PUBLISHED && ($problem = self::unpublishable($draft)) !== null) {
            return ['ok' => false, 'automation' => null, 'message' => $problem];
        }

        if ($automation === null && ChannelAutomation::where('business_id', $business->id)->where('channel', $channel)->count() >= self::MAX_AUTOMATIONS) {
            return ['ok' => false, 'automation' => null, 'message' => __('وصلت إلى :max أتمتة — احذف واحدة قبل أن تضيف غيرها.', ['max' => self::MAX_AUTOMATIONS])];
        }

        $productId = filled($data['product_id'] ?? null)
            && Product::where('business_id', $business->id)->whereKey($data['product_id'])->exists()
                ? (int) $data['product_id'] : null;

        $values = [
            'name' => mb_substr(trim((string) $data['name']), 0, self::MAX_NAME),
            'template' => filled($data['template'] ?? null) ? mb_substr((string) $data['template'], 0, 40) : ($automation?->template),
            'trigger' => $trigger,
            'match' => $match,
            'keywords' => $keywords,
            'excluded' => $excluded === [] ? null : $excluded,
            'reply_ar' => mb_substr($ar, 0, self::MAX_TEXT),
            'reply_en' => mb_substr($en, 0, self::MAX_TEXT),
            'buttons' => $buttons === [] ? null : $buttons,
            'posts' => $posts === [] ? null : $posts,
            'public_replies' => $public === [] ? null : $public,
            'product_id' => $productId,
            'status' => $status,
            'cooldown_minutes' => min(self::COOLDOWN_MAX, max(1, (int) ($data['cooldown_minutes'] ?? self::DEFAULT_COOLDOWN))),
            'published_at' => $status === ChannelAutomation::PUBLISHED ? ($automation?->published_at ?? now()) : $automation?->published_at,
        ];

        if ($automation === null) {
            $automation = ChannelAutomation::create($values + [
                'business_id' => $business->id,
                'channel' => $channel,
                'created_by' => $user?->id,
            ]);
        } else {
            $automation->forceFill($values)->save();
        }

        Activity::log('settings', self::label($channel).' — أتمتة «'.$automation->name.'»: '.($status === ChannelAutomation::PUBLISHED ? 'نُشرت' : 'حُفظت مسوّدة'), [
            'subject_type' => ChannelAutomation::class,
            'subject_id' => $automation->id,
        ]);

        return ['ok' => true, 'automation' => $automation, 'message' => null];
    }

    /** @return array{ok: bool, message: ?string} */
    public static function setStatus(ChannelAutomation $automation, string $status): array
    {
        if (! in_array($status, [ChannelAutomation::PUBLISHED, ChannelAutomation::PAUSED], true)) {
            return ['ok' => false, 'message' => __('حال غير معروفة.')];
        }

        if ($status === ChannelAutomation::PUBLISHED && ($problem = self::unpublishable($automation)) !== null) {
            return ['ok' => false, 'message' => $problem];
        }

        $automation->forceFill([
            'status' => $status,
            'published_at' => $status === ChannelAutomation::PUBLISHED ? ($automation->published_at ?? now()) : $automation->published_at,
        ])->save();

        Activity::log('settings', self::label((string) $automation->channel).' — أتمتة «'.$automation->name.'»: '.($status === ChannelAutomation::PUBLISHED ? 'شُغّلت' : 'أُوقفت'), [
            'subject_type' => ChannelAutomation::class,
            'subject_id' => $automation->id,
        ]);

        return ['ok' => true, 'message' => null];
    }

    public static function delete(ChannelAutomation $automation): void
    {
        $name = $automation->name;
        $id = $automation->id;
        $channel = self::label((string) $automation->channel);
        $automation->delete();

        Activity::log('settings', $channel.' — حُذفت أتمتة «'.$name.'»', ['subject_type' => ChannelAutomation::class, 'subject_id' => $id]);
    }

    private static function label(string $channel): string
    {
        return $channel === self::INSTAGRAM ? 'إنستقرام' : 'واتساب';
    }

    /** ‏‏ما يمنع نشرَها — بكلماتٍ يفهمها التاجر، أو `null` */
    private static function unpublishable(ChannelAutomation $a): ?string
    {
        $instagram = $a->channel === self::INSTAGRAM;
        $ar = trim((string) $a->reply_ar);
        $en = trim((string) $a->reply_en);
        $texts = [$ar, $en, ...array_map('strval', (array) ($a->public_replies ?? []))];

        if ($a->match === KeywordMatch::ANY) {
            if (self::postIds($a) === []) {
                return __('«أي تعليق» يعمل على منشورات تختارها — اختر منشورًا واحدًا على الأقل.');
            }
        } elseif ((array) $a->keywords === []) {
            return __('اكتب كلمة واحدة على الأقل يكتبها الزبون.');
        }

        if ($ar === '' && $en === '') {
            return __('اكتب نص الرد قبل النشر.');
        }

        if (preg_match(self::PLACEHOLDER, implode(' ', $texts))) {
            return __('أكمل ما بين القوسين [ ] في نص الرد قبل النشر.');
        }

        // ‏‏ولا رقمَ للزبون في إنستقرام يُعرف به طلبُه
        if ($instagram && self::uses(implode(' ', $texts), self::VARIABLES['last_order'])) {
            return __('«حالة آخر طلب» لا تعمل على إنستقرام — لا رقم للزبون هناك.');
        }

        if ($instagram) {
            $link = collect((array) ($a->buttons ?? []))->contains(fn ($b) => is_array($b) && ($b['type'] ?? null) === self::BUTTON_LINK);

            foreach ([$ar, $en] as $text) {
                if (strlen($text) > InstagramMessenger::TEXT_BYTES || ($link && mb_strlen($text) > InstagramMessenger::BUTTON_TEXT)) {
                    return __('النص أطول مما يقبله إنستقرام (نحو 500 حرف عربي) — قصّره.');
                }
            }
        }

        return null;
    }

    /**
     * ‏‏منشوراتٌ اختارها التاجر — بمعرّفها عند Meta وأوّلِ سطرٍ منها، بلا تكرار.
     *
     * @param  array<int, mixed>  $posts
     * @return list<array{id: string, caption: string}>
     */
    public static function posts(array $posts): array
    {
        $out = [];

        foreach ($posts as $p) {
            $id = trim((string) (is_array($p) ? ($p['id'] ?? '') : $p));

            if (preg_match(self::POST_ID, $id) && ! array_key_exists($id, $out)) {
                $out[$id] = ['id' => $id, 'caption' => mb_substr(trim((string) (is_array($p) ? ($p['caption'] ?? '') : '')), 0, 120)];
            }
        }

        return array_slice(array_values($out), 0, self::MAX_POSTS);
    }

    /**
     * @param  array<int, mixed>  $replies
     * @return list<string>
     */
    public static function publicReplies(array $replies): array
    {
        $clean = array_map(fn ($r) => mb_substr(trim((string) $r), 0, InstagramMessenger::COMMENT_TEXT), $replies);

        return array_slice(array_values(array_unique(array_filter($clean, fn ($r) => $r !== ''))), 0, self::MAX_PUBLIC);
    }

    /**
     * ‏‏الكلماتُ مقصوصةً وبلا تكرار — و«السعر» و«السّعر» كلمةٌ واحدة.
     *
     * @param  array<int, mixed>  $words
     * @return list<string>
     */
    public static function words(array $words): array
    {
        $out = [];

        foreach ($words as $w) {
            $w = mb_substr(trim((string) $w), 0, self::MAX_KEYWORD);
            $key = KeywordMatch::normalize($w);

            if ($key !== '' && ! array_key_exists($key, $out)) {
                $out[$key] = $w;
            }
        }

        return array_slice(array_values($out), 0, self::MAX_KEYWORDS);
    }

    /**
     * ‏‏الأزرار: ثلاثةُ أزرار ردٍّ، أو زرُّ رابطٍ واحد — واتساب لا يجمع بينهما في رسالة.
     *
     * @param  array<int, mixed>  $buttons
     * @return list<array{type: string, title: string, url?: string}>
     */
    public static function buttons(array $buttons): array
    {
        $clean = [];

        foreach ($buttons as $b) {
            $title = mb_substr(trim((string) ($b['title'] ?? '')), 0, self::BUTTON_TITLE_MAX);

            if ($title === '') {
                continue;
            }

            if (($b['type'] ?? null) === self::BUTTON_LINK) {
                return [['type' => self::BUTTON_LINK, 'title' => $title, 'url' => mb_substr(trim((string) ($b['url'] ?? '')), 0, 500)]];
            }

            $clean[] = ['type' => self::BUTTON_REPLY, 'title' => $title];
        }

        return array_slice($clean, 0, self::MAX_BUTTONS);
    }

    /* ═══════════ ما تعرضه الشاشة ═══════════ */

    /** @return array<string, mixed> */
    public static function view(Business $business, bool $mayManage, string $channel = self::WHATSAPP): array
    {
        $instagram = $channel === self::INSTAGRAM;
        $rows = ChannelAutomation::where('business_id', $business->id)->where('channel', $channel)->orderBy('id')->get();
        $currency = Storefront::currency($business);
        $root = self::storeLink($business);
        $theme = Storefront::serves($business) === Storefront::SERVES_THEME;

        $counts = ChannelAutomationRun::where('business_id', $business->id)
            ->whereIn('automation_id', $rows->pluck('id'))
            ->selectRaw('automation_id, status, count(*) as n, max(created_at) as last_at')
            ->groupBy('automation_id', 'status')
            ->get()
            ->groupBy('automation_id');

        return [
            'available' => $instagram ? InstagramAutomations::available($business) : self::available($business),
            'may_manage' => $mayManage,
            'automations' => $rows->map(function (ChannelAutomation $a) use ($counts) {
                $mine = $counts->get($a->id, collect());
                $last = $mine->max('last_at');

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'template' => $a->template,
                    'trigger' => $a->trigger,
                    'posts' => array_values((array) ($a->posts ?? [])),
                    'public_replies' => array_values((array) ($a->public_replies ?? [])),
                    'match' => $a->match,
                    'keywords' => array_values((array) $a->keywords),
                    'excluded' => array_values((array) ($a->excluded ?? [])),
                    'reply_ar' => (string) $a->reply_ar,
                    'reply_en' => (string) $a->reply_en,
                    'buttons' => array_values((array) ($a->buttons ?? [])),
                    'product_id' => $a->product_id,
                    'status' => $a->status,
                    'cooldown_minutes' => (int) $a->cooldown_minutes,
                    'counts' => [
                        'matched' => (int) $mine->sum('n'),
                        'sent' => (int) ($mine->firstWhere('status', ChannelAutomationRun::SENT)?->n ?? 0),
                        'failed' => (int) ($mine->firstWhere('status', ChannelAutomationRun::FAILED)?->n ?? 0),
                    ],
                    'last_run_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
                ];
            })->values()->all(),
            // ‏‏والترحيبُ أوّلُ أتمتةٍ في القائمة — بإعداداته القائمة كما هي، يُحرَّر في صفحة واتساب
            'welcome' => ! $instagram && WhatsAppFeature::canUseOwnNumber($business) ? [
                'enabled' => WhatsAppAutoReply::settings((int) $business->id)['enabled'],
                'state' => WhatsAppAutoReply::state($business),
            ] : null,
            'templates' => $instagram ? ChannelAutomationTemplates::instagram() : ChannelAutomationTemplates::all(),
            // ‏‏والعملةُ وجذرُ المتجر يُقرآن مرّةً لا لكلّ منتج
            'products' => Product::where('business_id', $business->id)->where('active', true)
                ->orderBy('name')->limit(self::PICKER_PRODUCTS)->get(['id', 'name', 'price', 'active', 'published'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => (string) $p->name,
                    'price' => Storefront::amount((float) $p->price, $currency),
                    'link' => $root !== null && $theme && (bool) $p->published ? $root.'/p/'.$p->id : $root,
                ])->values()->all(),
            'context' => [
                'store' => (string) $business->name,
                'store_link' => $root,
            ],
            'variables' => array_map(fn ($t) => $t[0], $instagram ? array_diff_key(self::VARIABLES, ['last_order' => true]) : self::VARIABLES),
            'limits' => [
                'automations' => self::MAX_AUTOMATIONS,
                'keywords' => self::MAX_KEYWORDS,
                'keyword' => self::MAX_KEYWORD,
                'name' => self::MAX_NAME,
                'text' => self::MAX_TEXT,
                'buttons' => self::MAX_BUTTONS,
                'button_title' => self::BUTTON_TITLE_MAX,
                'cooldown_max' => self::COOLDOWN_MAX,
                'posts' => self::MAX_POSTS,
                'public_replies' => self::MAX_PUBLIC,
                'public_reply' => InstagramMessenger::COMMENT_TEXT,
                'bytes' => $instagram ? InstagramMessenger::TEXT_BYTES : null,
            ],
        ];
    }
}
