<?php

namespace App\Support;

use App\Jobs\IssueWhatsAppOrderPayment;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOrderSession;
use App\Support\Store\ShopClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * الطلبُ الآليّ عبر واتساب — يطلب الزبونُ في المحادثة، ويدفع برابط، فيصل طلبُه لوحةَ التجهيز.
 *
 * ═══ المسار ═══
 *
 *  1. زبونٌ يكتب «طلب» (أو يضغط «اطلب الآن» في الردّ الترحيبيّ) إلى رقم المتجر.
 *  2. أسئلةٌ موجَّهةٌ بأزرارٍ وقوائم من كتالوج المتجر نفسِه (`WhatsAppOrderFlow`).
 *  3. ملخّصٌ بمبلغه من حساب الفاتورة، وموافقةٌ صريحةٌ بزرّ.
 *  4. فاتورةُ عميلٍ بتجهيزها، صادرة، ثمّ رابطُ Paymob من حساب التاجر
 *     (`WhatsAppOrderCheckout` في `IssueWhatsAppOrderPayment`) — ويُرسل الرابطُ من رقم المتجر.
 *  5. إشعارُ Paymob الموثَّق يسجّل التحصيلَ ويُظهر المهمّةَ في لوحة الفرع
 *     (`PaymentLinks::callback` ← `CustomerInvoicePreparations::activate`) — كما يقع
 *     لأيّ فاتورةٍ دُفعت برابط. وبعد التفعيل يصل الزبونَ تأكيدٌ برقم طلبه (`prepared`).
 *
 * ═══ ومن يُفتح له ═══
 *
 * مفتاحان: المنصّةُ تأذن للمتجر (`whatsapp.guided_orders_businesses`)، وصاحبُ
 * المتجر يُشعله من شاشة واتساب (`SETTING`) — مستقلًّا عن الردّ الترحيبيّ.
 * ولا يعمل حتى تكتمل شروطُه كلُّها (`readiness`): رقمٌ يُرسل وفوترتُه مراجَعة،
 * وصندوقُ محادثات، وتجهيزُ الفواتير بعد السداد، وروابطُ Paymob.
 *
 * ═══ ولا ردّان ═══
 *
 * يُنادى من الردّ الترحيبيّ بعد أن يُكتب صفُّ الوارد بمفتاحه الفريد
 * (`WhatsAppAutoReply::receive`): نسخةٌ مكرّرةٌ من الإشعار لا تصل هنا أبدًا. وما
 * يأخذه الطلبُ لا يردّ عليه الترحيب — يُقيَّد سببُه `guided_order`.
 */
class WhatsAppOrders
{
    public const SETTING = 'whatsapp_orders_enabled';

    /** سببُ صمت الردّ الترحيبيّ عن رسالةٍ أخذها الطلب */
    public const SKIP_REASON = 'guided_order';

    /** زرّا الردّ الترحيبيّ حين يُفتح الطلب — بلا طلبٍ قائمٍ يُشار إليه */
    public const START_ID = 'wo:start';

    public const STAFF_ID = 'wo:staff';

    /** طلبٌ لم يُكمَل يُغلق بعد هذا الصمت — فلا يبقى زبونٌ عالقًا في سؤالٍ نسيه */
    public const EXPIRE_HOURS = 24;

    /* ═══ لماذا حُوِّل لموظّف ═══ */

    public const REASON_ASKED = 'asked';

    public const REASON_SPECIAL = 'special_request';

    public const REASON_STAFF = 'staff';

    public const REASON_NO_BRANCH = 'no_branch';

    public const REASON_NO_DATES = 'no_dates';

    public const REASON_EMPTY_CATALOG = 'empty_catalog';

    public const REASON_CANCEL_AFTER_LINK = 'cancel_after_link';

    public const REASON_LINK_CLOSED = 'link_closed';

    public const REASON_INVOICE_FAILED = 'invoice_failed';

    public const REASON_LINK_FAILED = 'link_failed';

    /** ما يُعاد إصدارُه من الصندوق — فاتورةٌ أو رابطٌ تعذّر بعد الموافقة */
    public const RETRYABLE = [self::REASON_INVOICE_FAILED, self::REASON_LINK_FAILED];

    /* ═══ الرسالتان اللتان يتتبّعهما الطلب — في `context.sends` بمفتاحَيهما ═══ */

    public const SEND_LINK = 'link';

    public const SEND_PAID = 'paid';

    /** إرسالٌ حُجز ولم يُكتب ما ردّت به ميتا بعد هذه المدّة — انقطع، فيُعاد */
    public const SENDING_STALE_MINUTES = 5;

    /** حالُ رسالةٍ حُجزت ولم يُعرف مصيرُها بعد */
    public const SENDING = 'sending';

    /** ما يبدأ طلبًا — بعد التطبيع (`normalize`)، كلمةً كاملةً لا جزءًا من جملة */
    public const START_WORDS = [
        'طلب', 'اطلب', 'طلب جديد', 'ابي اطلب', 'ابغي اطلب', 'اريد اطلب', 'اريد الطلب', 'ودي اطلب',
        'المنيو', 'منيو', 'القائمه', 'order', 'new order', 'i want to order', 'menu',
    ];

    /* ═══════════ من يُفتح له ═══════════ */

    /** أذنت المنصّةُ لهذا المتجر؟ — قائمةٌ في الإعداد، تبدأ بـRIBBON وحده */
    public static function allowed(int $businessId): bool
    {
        return in_array($businessId, array_map('intval', (array) config('whatsapp.guided_orders_businesses', [])), true);
    }

    /** أشعله صاحبُ المتجر؟ — مطفأٌ ما لم يُكتب «1» صراحةً */
    public static function enabled(int $businessId): bool
    {
        return Setting::where('business_id', $businessId)->where('key', self::SETTING)->value('value') === '1';
    }

    public static function save(int $businessId, bool $on): void
    {
        Setting::updateOrCreate(['business_id' => $businessId, 'key' => self::SETTING], ['value' => $on ? '1' : '0']);
    }

    /**
     * شروطُ العمل — كلٌّ بحاله، لتقرأها الشاشةُ ويسألها الاستقبال.
     *
     * @return array<string, bool>
     */
    public static function readiness(Business $business): array
    {
        $bid = (int) $business->id;
        $line = WhatsAppConnections::resolve($business);

        return [
            'number' => WhatsAppFeature::blockReason($business) === null
                && WhatsAppFeature::effectiveMode($business) === WhatsAppMode::BUSINESS_OWN
                && $line !== null && $line->owner_type === WhatsAppMode::OWNER_BUSINESS,
            'inbox' => WhatsAppInbox::available($business),
            'preparation' => CustomerInvoicePreparations::on($bid),
            'payment_links' => PaymentLinks::ready($bid),
        ];
    }

    public static function active(?Business $business): bool
    {
        return $business !== null
            && self::allowed((int) $business->id)
            && self::enabled((int) $business->id)
            && ! in_array(false, self::readiness($business), true);
    }

    /* ═══════════ الوارد ═══════════ */

    /**
     * رسالةُ زبونٍ إلى رقم المتجر — أيأخذها الطلب؟ وإن أخذها ردّ عليها.
     *
     * لا يأخذ ما لا يخصّه: متجرٌ لم يُفتح له، أو محادثةٌ لم تُحفظ، أو رقمٌ لا يُرسل
     * الآن (فوترةٌ لم تُراجَع، ربطٌ منقطع)، أو رسالةٌ لا تبدأ طلبًا ولا طلبَ مفتوحًا.
     * فيبقى للردّ الترحيبيّ ما كان له.
     *
     * والحالُ تُقرأ وتُكتب تحت قفل المحادثة: رسالتان وصلتا معًا تُعالجان واحدةً
     * بعد الأخرى، والثانيةُ ترى ما كتبته الأولى. والإرسالُ بعد القفل.
     */
    public static function receive(WhatsAppConnection $connection, array $message, WhatsAppInboundMessage $row): bool
    {
        $business = Business::find($connection->business_id);

        if (! self::active($business)) {
            return false;
        }

        /*
         * ولا يُسقط عطبٌ هنا بابَ الإشعارات.
         *
         * إشعارٌ ردّ بخطأ تعيده ميتا، فيجد صفَّ الوارد قائمًا فلا يُعالج ثانيةً —
         * فتضيع الرسالةُ بصمت. فيُبلَّغ العطب، وتصمت المحادثةُ إن كان فيها طلب:
         * لا ترحيبَ يقطع طلبًا لم يُفهم سببُ تعثّره.
         */
        try {
            return self::take($business, $connection, $message, $row);
        } catch (Throwable $e) {
            report($e);

            return WhatsAppOrderSession::where('business_id', $business->id)->where('customer_phone', (string) $row->from_phone)->whereNotNull('open_key')->exists();
        }
    }

    private static function take(Business $business, WhatsAppConnection $connection, array $message, WhatsAppInboundMessage $row): bool
    {

        $chat = WhatsAppChat::where('business_id', $business->id)
            ->where('phone_number_id', (string) $connection->phone_number_id)
            ->where('customer_phone', (string) $row->from_phone)
            ->first();

        if ($chat === null) {
            return false;
        }

        $input = self::input($message);
        $open = WhatsAppOrderSession::where('open_key', $chat->id)->exists();

        if (! $open && ! self::starts($input)) {
            return false;
        }

        ['line' => $line, 'reason' => $reason] = WhatsAppInbox::check($chat);

        if ($reason !== null || $line === null) {
            return false;
        }

        // تفاعلٌ أو رسالةُ نظام: لا جوابَ لها — ولا ترحيبَ وسط طلب
        if (in_array($input['type'], WhatsAppAutoReply::NOT_A_MESSAGE, true)) {
            return $open;
        }

        [$session, $result] = DB::transaction(function () use ($business, $chat, $input) {
            WhatsAppChat::whereKey($chat->id)->lockForUpdate()->first();

            // والطلبُ مقفولٌ أيضًا: مهمّةُ الرابط تكتب حالَ رسالتها فيه (`recordSend`) ولا يمحوها حفظٌ هنا
            $session = WhatsAppOrderSession::where('open_key', $chat->id)->lockForUpdate()->first();

            if ($session !== null && self::expired($session)) {
                $session->forceFill([
                    'state' => WhatsAppOrderSession::CANCELLED,
                    'error' => 'expired',
                    'open_key' => null,
                    'closed_at' => now(),
                ])->save();
                $session = null;
            }

            if ($session === null) {
                if (! self::starts($input)) {
                    return [null, null];
                }

                $session = WhatsAppOrderSession::create([
                    'business_id' => $business->id,
                    'chat_id' => $chat->id,
                    'customer_phone' => (string) $chat->customer_phone,
                    'customer_id' => $chat->customer_id,
                    'language' => self::languageFor($business, $chat, $input['text']),
                    'state' => WhatsAppOrderSession::COLLECTING,
                    'open_key' => $chat->id,
                    'cart' => [],
                    'details' => [],
                    'context' => [],
                    'last_activity_at' => now(),
                ]);

                if ($input['id'] === self::STAFF_ID) {
                    $session->forceFill(['state' => WhatsAppOrderSession::NEEDS_HUMAN, 'handoff_reason' => self::REASON_ASKED])->save();

                    return [$session, ['messages' => [['kind' => 'text', 'body' => WhatsAppOrderTexts::say($session->language, 'handoff')]], 'issue' => false]];
                }

                $result = (new WhatsAppOrderFlow($business, $session))->start();
            } elseif ($session->state === WhatsAppOrderSession::NEEDS_HUMAN) {
                // محادثةٌ مع موظّف: الآليُّ يصمت فيها — ولا ترحيبَ يقطعها
                $session->forceFill(['last_activity_at' => now()])->save();

                return [$session, ['messages' => [], 'issue' => false]];
            } else {
                $result = (new WhatsAppOrderFlow($business, $session))->handle($input);
            }

            $session->last_activity_at = now();
            $session->save();

            return [$session, $result];
        });

        if ($session === null) {
            return false;
        }

        self::deliver($chat, $line, $result['messages'], (string) $session->language, (int) $session->id);

        if ($result['issue']) {
            IssueWhatsAppOrderPayment::dispatch((int) $session->id)->onQueue((string) config('whatsapp.queue', 'whatsapp'));
        }

        return true;
    }

    /**
     * جوابُ الرسالة: معرّفُ الزرّ إن ضُغط زرّ، والنصّ، ونوعُ الرسالة.
     *
     * ومعرّفُ الزرّ يُقرأ من الإشعار نفسه: الصندوقُ يحفظ عنوانَه للقراءة
     * (`CrmWhatsApp::inbound`)، والقرارُ بالمعرّف لا بالعنوان.
     *
     * @return array{id: ?string, text: string, type: string}
     */
    public static function input(array $message): array
    {
        $interactive = (array) ($message['interactive'] ?? []);
        $reply = (array) ($interactive['button_reply'] ?? $interactive['list_reply'] ?? []);

        $id = $reply['id'] ?? ($message['button']['payload'] ?? null);
        $text = $message['text']['body'] ?? $reply['title'] ?? $message['button']['text'] ?? '';

        return [
            'id' => is_string($id) && $id !== '' ? mb_substr($id, 0, 256) : null,
            'text' => mb_substr(trim((string) $text), 0, 2000),
            'type' => (string) ($message['type'] ?? 'unknown'),
        ];
    }

    /** أيبدأ هذا طلبًا؟ — زرُّ الترحيب، أو كلمةٌ من `START_WORDS` وحدَها */
    public static function starts(array $input): bool
    {
        if (in_array($input['id'], [self::START_ID, self::STAFF_ID], true)) {
            return true;
        }

        return $input['id'] === null && in_array(self::normalize((string) $input['text']), self::START_WORDS, true);
    }

    /**
     * النصُّ مطبَّعًا للمقارنة — بلا تشكيلٍ ولا همزاتٍ متفرّقة ولا علامات.
     *
     * «إلغاء» و«الغاء» و«الْغاء» كلمةٌ واحدة، و«Order!» و«order» كذلك.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** الأرقامُ كما تُكتب بلوحة عربيّة أو لاتينيّة — ٩١٢٣ و9123 سواء */
    public static function digits(string $text): string
    {
        $text = strtr(trim($text), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return trim(preg_replace('/[^0-9+]/', '', $text) ?? '');
    }

    /** الرقمُ كما يُكتب في عُمان — ثمانيةُ أرقامٍ بلا مفتاح الدولة، وغيرُه بمفتاحه */
    public static function localPhone(string $normalized): string
    {
        $digits = preg_replace('/\D/', '', $normalized) ?? '';

        return str_starts_with($digits, '968') && strlen($digits) === 11 ? substr($digits, 3) : '+'.$digits;
    }

    private static function expired(WhatsAppOrderSession $session): bool
    {
        return in_array($session->state, [WhatsAppOrderSession::COLLECTING, WhatsAppOrderSession::CONFIRMING, WhatsAppOrderSession::NEEDS_HUMAN], true)
            && $session->last_activity_at !== null
            && $session->last_activity_at->lt(now()->subHours(self::EXPIRE_HOURS));
    }

    /** لغةُ الحوار: لغةُ الزبون في ملفّه إن عُرف، وإلّا حروفُ رسالته الأولى */
    private static function languageFor(Business $business, WhatsAppChat $chat, string $text): string
    {
        $known = $chat->customer_id ? Customer::where('business_id', $business->id)->whereKey($chat->customer_id)->value('language') : null;

        if (in_array($known, ['ar', 'en'], true)) {
            return $known;
        }

        return ! preg_match('/\p{Arabic}/u', $text) && preg_match('/[A-Za-z]/', $text) ? 'en' : 'ar';
    }

    /**
     * ورسالةٌ تحمل `track` (الرابطُ يُعاد لزبونٍ كتب وهو ينتظر الدفع) يُكتب ما ردّت به ميتا
     * في حالها — فيرى الموظّفُ أنّ الرابط وصل بعد أن تعذّر أوّلَ مرّة.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private static function deliver(WhatsAppChat $chat, WhatsAppConnection $line, array $messages, string $lang, ?int $sessionId = null): void
    {
        foreach ($messages as $message) {
            $record = WhatsAppOrderReplies::send($chat, $line, $message, $lang);

            if ($sessionId !== null && isset($message['track'])) {
                self::recordSend($sessionId, (string) $message['track'], 'chat', (int) $record->id, $record->status === WhatsAppStatus::FAILED);
            }
        }
    }

    /* ═══════════ الفاتورةُ والرابط ═══════════ */

    /**
     * يكتب الفاتورةَ ويطلب الرابطَ ويرسله — مرّةً واحدةً مهما أُعيد.
     *
     * ويُنادى من المهمّة (`IssueWhatsAppOrderPayment`) لا من باب الإشعارات:
     * طلبُ Paymob يدوم ثوانيَ، وميتا لا تنتظرها. ومن الصندوق (`resendLink`) حين
     * يعيد الموظّفُ ما تعذّر — فلا يُقال للزبون «تعذّر» ثانيةً (`$byStaff`). ومحاولةُ رابطٍ
     * لم تُعرف نتيجتُها عند Paymob لا تُعاد إلّا بقراره الصريح (`$replaceUncertain`)،
     * كما في صفحة الفاتورة: الجديدُ وحده يصل الزبون.
     *
     * والإرسالُ يُحجز تحت قفل الطلب (`claimSend`)، ولا يُعدّ «وصل» حتى تقبله ميتا:
     * ما ردّته يبقى «لم يصل»، فيُعاد الرابطُ نفسُه — والفاتورةُ برمزها والرابطُ
     * المفتوحُ لا يُكتبان ثانيةً.
     */
    public static function issue(int $sessionId, bool $byStaff = false, ?int $userId = null, bool $replaceUncertain = false): void
    {
        $session = WhatsAppOrderSession::find($sessionId);

        if ($session === null || $session->state !== WhatsAppOrderSession::AWAITING_PAYMENT) {
            return;
        }

        $business = Business::find($session->business_id);

        if ($business === null) {
            return;
        }

        try {
            $invoice = WhatsAppOrderCheckout::invoice($business, $session);
        } catch (WhatsAppOrderPriceChanged $e) {
            self::reconfirm($business, $session, $e->preview);

            return;
        } catch (Throwable $e) {
            report($e);
            self::fail($session, self::REASON_INVOICE_FAILED, 'failed_issue', $e, quiet: $byStaff);

            return;
        }

        try {
            $link = $session->payment_link_id !== null
                ? CustomerPaymentLink::where('business_id', $business->id)->findOrFail($session->payment_link_id)
                : PaymentLinks::forInvoice($invoice, $userId, replaceUncertain: $replaceUncertain)[0];
        } catch (Throwable $e) {
            report($e);
            // والفاتورةُ تبقى صادرةً آجلة — ورابطُها يُعاد من الصندوق أو صفحتها، لا فاتورةٌ ثانية
            self::fail($session, self::REASON_LINK_FAILED, 'failed_link', $e, quiet: $byStaff);

            return;
        }

        $attempt = DB::transaction(function () use ($session, $link) {
            $locked = WhatsAppOrderSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->payment_link_id === null) {
                $locked->payment_link_id = $link->id;
            }

            // دُفع أو أُغلق في الأثناء، أو انتهى الرابط: لا رسالةَ تطلب مالًا لا يُطلب
            $attempt = $locked->state === WhatsAppOrderSession::AWAITING_PAYMENT && $link->open()
                ? self::claimSend($locked, self::SEND_LINK)
                : null;

            $locked->save();

            return $attempt;
        });

        if ($attempt !== null) {
            self::sendLink($session->fresh(), $invoice, $link, $attempt);
        }
    }

    /**
     * الرابطُ إلى الزبون — رسالةٌ بزرّه داخل النافذة، وقالبٌ معتمَدٌ خارجها.
     *
     * وما ردّت به ميتا يُكتب في حال الرسالة (`recordSend`) — ولو رمى شيءٌ هنا
     * يُكتب «لم يصل»، فلا يبقى حجزٌ معلّقٌ يمنع إعادتها.
     */
    private static function sendLink(WhatsAppOrderSession $session, CustomerInvoice $invoice, CustomerPaymentLink $link, int $attempt): void
    {
        try {
            $lang = (string) $session->language;
            $chat = $session->chat;
            $business = Business::find($session->business_id);
            $amount = Money::format((float) $link->amount, Storefront::currency($business));
            $expires = $link->expires_at ? ShopClock::day($link->expires_at->toImmutable()->setTimezone(ShopClock::zone()), $lang) : '';

            ['line' => $line, 'reason' => $reason] = WhatsAppInbox::check($chat);

            if ($reason === null && $line !== null) {
                $record = WhatsAppOrderReplies::send($chat, $line, [
                    'kind' => 'cta',
                    'body' => WhatsAppOrderTexts::say($lang, 'link', ['number' => (string) $invoice->number, 'total' => $amount, 'expires' => $expires]),
                    'label' => WhatsAppOrderTexts::say($lang, 'pay_now'),
                    'url' => (string) $link->short_url,
                ], $lang);

                self::recordSend((int) $session->id, self::SEND_LINK, 'chat', (int) $record->id, $record->status === WhatsAppStatus::FAILED);

                return;
            }

            $name = (string) (((array) $session->details)['name'] ?? '');
            $row = self::template($business, $session, WhatsAppEvent::ORDER_PAYMENT_LINK, [$name, (string) $invoice->number, $amount, (string) $link->short_url], 'link:'.$link->id.':'.$attempt);

            self::recordSend((int) $session->id, self::SEND_LINK, 'log', $row?->id, $row === null || $row->status !== WhatsAppStatus::QUEUED);
        } catch (Throwable $e) {
            report($e);
            self::recordSend((int) $session->id, self::SEND_LINK, null, null, true);
        }
    }

    /**
     * رسالةُ قالبٍ معتمَدٍ خارج النافذة — صفٌّ في دفتر الإرسال وطابورُه، كإشعارات الطلبات.
     *
     * وبلا قالبٍ معتمَد يُقيَّد الصفُّ «لم يُرسَل» بسببه ولا يخرج نصٌّ حرٌّ خارج
     * النافذة: ميتا تردّه، والزبونُ لا يصله شيء.
     *
     * والمعتمَدُ ما كتبت المزامنةُ أنّ ميتا اعتمدته — لكلّ متجر، لا لـRIBBON وحده:
     * `resolve` يقبل الحالَ الفارغة («لم نسأل بعد») لإشعارات الطلبات القديمة، وهنا لا.
     *
     * @param  list<string>  $variables
     * @return WhatsAppMessage|null صفُّ الإرسال (في الطابور، أو «لم يُرسَل» بسببه)، أو `null` إن سبقه مثلُه
     */
    private static function template(Business $business, WhatsAppOrderSession $session, string $event, array $variables, string $key): ?WhatsAppMessage
    {
        $mode = WhatsAppFeature::effectiveMode($business);
        $connection = WhatsAppConnections::resolve($business);
        $template = WhatsAppTemplates::resolve($business, $event, $mode, $session->language);

        if ($template !== null && $template->meta_status !== WhatsAppTemplates::APPROVED) {
            $template = null;
        }

        $base = [
            'business_id' => $business->id,
            'order_id' => null,
            'customer_invoice_id' => $session->customer_invoice_id,
            'customer_id' => $session->customer_id,
            'whatsapp_connection_id' => $connection?->id,
            'sender_phone_number_id' => $connection?->phone_number_id,
            'source_mode' => $mode,
            'event_type' => $event,
            'direction' => 'outbound',
            'recipient_phone' => (string) $session->customer_phone,
            'template_name' => $template?->template_name,
            'language_code' => $template?->languageFor($session->language),
            'dedupe_key' => 'wo:'.$business->id.':'.$session->id.':'.$key,
            'metadata' => ['variables' => array_values($variables), 'whatsapp_order_session_id' => (int) $session->id],
        ];

        $skip = match (true) {
            $connection === null => WhatsAppStatus::SKIP_NO_CONNECTION,
            $template === null => WhatsAppStatus::SKIP_NO_TEMPLATE,
            default => null,
        };

        $row = Contention::attempt(fn () => WhatsAppMessage::create($base + ($skip === null
            ? ['status' => WhatsAppStatus::QUEUED, 'quota_consumed' => false, 'queued_at' => now()]
            : ['status' => WhatsAppStatus::SKIPPED, 'error_code' => $skip, 'quota_consumed' => false])));

        if ($row !== null && $skip === null) {
            SendWhatsAppMessage::dispatch($row->id)->onQueue((string) config('whatsapp.queue', 'whatsapp'));
        }

        return $row;
    }

    /** المبلغُ تغيّر قبل الفاتورة — ملخّصٌ جديدٌ وسؤالٌ جديد */
    private static function reconfirm(Business $business, WhatsAppOrderSession $session, array $preview): void
    {
        $chat = $session->chat;

        [$fresh, $result] = DB::transaction(function () use ($business, $chat, $session, $preview) {
            WhatsAppChat::whereKey($chat->id)->lockForUpdate()->first();
            $locked = WhatsAppOrderSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->state !== WhatsAppOrderSession::AWAITING_PAYMENT || $locked->customer_invoice_id !== null) {
                return [$locked, null];
            }

            $result = (new WhatsAppOrderFlow($business, $locked))->reconfirm($preview);
            $locked->save();

            return [$locked, $result];
        });

        if ($result === null) {
            return;
        }

        ['line' => $line, 'reason' => $reason] = WhatsAppInbox::check($chat);

        if ($reason === null && $line !== null) {
            self::deliver($chat, $line, $result['messages'], (string) $fresh->language);
        }
    }

    /**
     * تعذّر ما بعد الموافقة — يُقال للزبون بصدق، ويُحوَّل الطلبُ لموظّف بسببه.
     *
     * وإن أعاده الموظّفُ فتعذّر ثانيةً (`$quiet`) لا يُقال للزبون مرّةً أخرى: الموظّفُ
     * يرى السببَ في الصندوق، والزبونُ قيل له أوّلَ مرّة.
     */
    private static function fail(WhatsAppOrderSession $session, string $reason, string $say, Throwable $e, bool $quiet = false): void
    {
        WhatsAppOrderSession::whereKey($session->id)->update([
            'state' => WhatsAppOrderSession::NEEDS_HUMAN,
            'handoff_reason' => $reason,
            'error' => mb_substr($reason.':'.class_basename($e), 0, 60),
        ]);

        if ($quiet) {
            return;
        }

        $chat = $session->chat;
        ['line' => $line, 'reason' => $why] = WhatsAppInbox::check($chat);

        if ($why === null && $line !== null) {
            WhatsAppOrderReplies::send($chat, $line, ['kind' => 'text', 'body' => WhatsAppOrderTexts::say((string) $session->language, $say)], (string) $session->language);
        }
    }

    /* ═══════════ بعد الدفع ═══════════ */

    /**
     * ظهرت مهمّةُ الفاتورة في لوحة التجهيز — إن كانت لطلب واتساب يُختم «مدفوعًا» ويُبلَّغ زبونُه.
     *
     * يُنادى بعد التفعيل وحده (`CustomerInvoicePreparations::activate`)، والتفعيلُ لا
     * يقع إلّا لفاتورةٍ سُدّدت كلُّها بإشعارٍ موثَّق أو بيد موظّف — فدفعةٌ جزئيّة،
     * أو رابطٌ فشل أو انتهى، لا تصل هنا. ومرّةً واحدة: الحالُ تُقلب تحت القفل
     * قبل الرسالة. ولا يرمي: لوحةُ التجهيز لا تسقط بسبب رسالة.
     *
     * والتأكيدُ رسالةٌ بعد التفعيل لا جزءٌ منه: إن لم يصل الزبونَ يراه الموظّفُ
     * «لم يصل» ويعيده من الصندوق (`resendPaid`) — رسالةً وحدها، فلا تحصيلَ ثانيًا
     * ولا مهمّةَ تجهيزٍ ثانية.
     */
    public static function prepared(int $invoiceId): void
    {
        try {
            $session = WhatsAppOrderSession::where('customer_invoice_id', $invoiceId)->first();

            if ($session === null) {
                return;
            }

            $attempt = DB::transaction(function () use ($session) {
                $locked = WhatsAppOrderSession::whereKey($session->id)->lockForUpdate()->first();

                if (! in_array($locked->state, [WhatsAppOrderSession::AWAITING_PAYMENT, WhatsAppOrderSession::NEEDS_HUMAN], true)) {
                    return null;
                }

                $locked->forceFill([
                    'state' => WhatsAppOrderSession::PAID,
                    'step' => null,
                    'paid_at' => now(),
                    'open_key' => null,
                    'closed_at' => now(),
                ]);
                $attempt = self::claimSend($locked, self::SEND_PAID);
                $locked->save();

                return $attempt;
            });

            if ($attempt !== null) {
                self::notifyPaid($session->fresh(), $attempt);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function notifyPaid(WhatsAppOrderSession $session, int $attempt): void
    {
        try {
            $lang = (string) $session->language;
            $business = Business::find($session->business_id);
            $invoice = CustomerInvoice::where('business_id', $session->business_id)->find($session->customer_invoice_id);

            if ($business === null || $invoice === null) {
                self::recordSend((int) $session->id, self::SEND_PAID, null, null, true);

                return;
            }

            $d = (array) $session->details;
            $when = WhatsAppOrderCheckout::whenText((int) $business->id, (string) ($d['fulfil'] ?? FlowerOrder::DELIVERY), $d, $lang);

            $chat = $session->chat;
            ['line' => $line, 'reason' => $reason] = WhatsAppInbox::check($chat);

            if ($reason === null && $line !== null) {
                $body = WhatsAppOrderTexts::say($lang, 'paid', ['number' => (string) $invoice->number]);
                if ($when !== null) {
                    $body .= "\n".WhatsAppOrderTexts::say($lang, 'paid_when', ['when' => $when]);
                }
                $body .= "\n".WhatsAppOrderTexts::say($lang, 'paid_thanks');

                $record = WhatsAppOrderReplies::send($chat, $line, ['kind' => 'text', 'body' => $body], $lang);
                self::recordSend((int) $session->id, self::SEND_PAID, 'chat', (int) $record->id, $record->status === WhatsAppStatus::FAILED);

                return;
            }

            $amount = Money::format((float) $invoice->total, Storefront::currency($business));
            $row = self::template($business, $session, WhatsAppEvent::ORDER_PAID, [(string) ($d['name'] ?? ''), (string) $invoice->number, $amount, (string) ($when ?? '—')], 'paid:'.$attempt);

            self::recordSend((int) $session->id, self::SEND_PAID, 'log', $row?->id, $row === null || $row->status !== WhatsAppStatus::QUEUED);
        } catch (Throwable $e) {
            report($e);
            self::recordSend((int) $session->id, self::SEND_PAID, null, null, true);
        }
    }

    /* ═══════════ حالُ الرسالتين ═══════════ */

    /**
     * أين وصلت رسالةُ الرابط أو التأكيد — من صفّها نفسِه، بما كتبته إشعاراتُ ميتا.
     *
     * رسالةٌ قبلتها ميتا ثمّ ردّتها بإشعار (رقمٌ لا يستقبل مثلًا) تُقرأ «فشلت» هنا
     * لا «أُرسلت». وحجزٌ لم يُكتب مصيرُه بعد `SENDING_STALE_MINUTES` انقطع: «فشلت».
     *
     * @return string|null `sending`، أو حالٌ من `WhatsAppStatus`، أو `null` لما لم يُرسل قطّ
     */
    public static function sendStatus(WhatsAppOrderSession $session, string $key): ?string
    {
        $entry = ((array) ($session->context ?? []))['sends'][$key] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        if (($entry['state'] ?? null) === self::SENDING) {
            $at = isset($entry['at']) ? Carbon::parse((string) $entry['at']) : null;

            return $at !== null && $at->gt(now()->subMinutes(self::SENDING_STALE_MINUTES)) ? self::SENDING : WhatsAppStatus::FAILED;
        }

        $id = (int) ($entry['id'] ?? 0);
        $status = match ($id > 0 ? ($entry['via'] ?? null) : null) {
            'chat' => WhatsAppChatMessage::where('business_id', $session->business_id)->whereKey($id)->value('status'),
            'log' => WhatsAppMessage::where('business_id', $session->business_id)->whereKey($id)->value('status'),
            default => null,
        };

        return $status ?? WhatsAppStatus::FAILED;
    }

    /** لم تصل الزبونَ ولن تصل — فتُعاد */
    private static function undelivered(?string $status): bool
    {
        return in_array($status, WhatsAppStatus::TERMINAL, true);
    }

    /**
     * يحجز الإرسالَ في طلبٍ مقفول — مرّةً، إلّا أن يكون ما قبله لم يصل.
     *
     * فنداءان معًا (مهمّةٌ أُعيدت، نقرتان على «إعادة الإرسال») رسالةٌ واحدة: الثاني
     * يجد الحجزَ قائمًا. والحفظُ على من نادى.
     *
     * @return int|null رقمُ المحاولة، أو `null` إن كانت رسالةٌ في طريقها أو وصلت
     */
    private static function claimSend(WhatsAppOrderSession $locked, string $key): ?int
    {
        $context = (array) ($locked->context ?? []);
        $entry = $context['sends'][$key] ?? null;

        if (is_array($entry) && ! self::undelivered(self::sendStatus($locked, $key))) {
            return null;
        }

        $attempt = (int) (is_array($entry) ? ($entry['n'] ?? 0) : 0) + 1;
        $context['sends'][$key] = ['state' => self::SENDING, 'via' => null, 'id' => null, 'n' => $attempt, 'at' => now()->toIso8601String()];
        $locked->context = $context;

        return $attempt;
    }

    /** ما ردّت به ميتا — صفُّ الرسالة الذي يحمل حالَها من بعد، أو أنّها لم تخرج */
    private static function recordSend(int $sessionId, string $key, ?string $via, ?int $id, bool $failed): void
    {
        DB::transaction(function () use ($sessionId, $key, $via, $id, $failed) {
            $locked = WhatsAppOrderSession::whereKey($sessionId)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $context = (array) ($locked->context ?? []);
            $previous = (array) ($context['sends'][$key] ?? []);
            $context['sends'][$key] = [
                'state' => $failed ? WhatsAppStatus::FAILED : 'done',
                'via' => $via,
                'id' => $id,
                'n' => max(1, (int) ($previous['n'] ?? 1)),
                'at' => now()->toIso8601String(),
            ];
            $locked->context = $context;

            $error = $key.'_not_sent';
            if ($failed) {
                $locked->error = $error;
            } elseif ($locked->error === $error) {
                $locked->error = null;
            }

            $locked->save();
        });
    }

    /* ═══════════ الموظّف ═══════════ */

    /** آخرُ طلبٍ في المحادثة — المفتوحُ إن كان، وإلّا أحدثُ ما أُغلق */
    public static function latest(WhatsAppChat $chat): ?WhatsAppOrderSession
    {
        return WhatsAppOrderSession::where('business_id', $chat->business_id)->where('chat_id', $chat->id)
            ->orderByRaw('CASE WHEN open_key IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->first();
    }

    /** يُسكت الآليَّ في المحادثة — ويبقى ساكتًا حتى يُنهى الطلبُ من هنا */
    public static function handoff(WhatsAppChat $chat, User $user): void
    {
        DB::transaction(function () use ($chat) {
            WhatsAppChat::whereKey($chat->id)->lockForUpdate()->first();
            $session = WhatsAppOrderSession::where('open_key', $chat->id)->first();

            if ($session !== null) {
                $session->forceFill(['state' => WhatsAppOrderSession::NEEDS_HUMAN, 'handoff_reason' => self::REASON_STAFF, 'step' => null])->save();

                return;
            }

            WhatsAppOrderSession::create([
                'business_id' => $chat->business_id,
                'chat_id' => $chat->id,
                'customer_phone' => (string) $chat->customer_phone,
                'customer_id' => $chat->customer_id,
                'language' => 'ar',
                'state' => WhatsAppOrderSession::NEEDS_HUMAN,
                'handoff_reason' => self::REASON_STAFF,
                'open_key' => $chat->id,
                'cart' => [], 'details' => [], 'context' => [],
                'last_activity_at' => now(),
            ]);
        });
    }

    /**
     * يُنهي طلبَ المحادثة — فيعود الآليُّ يستقبل من يكتب «طلب».
     *
     * ولا يمسّ فاتورةً ولا رابطًا: طلبٌ ينتظر الدفعَ يُغلق هنا، وفاتورتُه
     * ورابطُه يُداران من صفحتهما كأيّ فاتورة.
     */
    public static function close(WhatsAppChat $chat, User $user): void
    {
        DB::transaction(function () use ($chat) {
            WhatsAppChat::whereKey($chat->id)->lockForUpdate()->first();
            $session = WhatsAppOrderSession::where('open_key', $chat->id)->first();

            if ($session === null) {
                throw ValidationException::withMessages(['order' => __('لا طلب آليًّا مفتوحًا في هذه المحادثة.')]);
            }

            $session->forceFill([
                'state' => WhatsAppOrderSession::CANCELLED,
                'step' => null,
                'open_key' => null,
                'closed_at' => now(),
                'error' => $session->error ?? 'closed_by_staff',
            ])->save();
        });
    }

    /**
     * يعيد الموظّفُ رابطَ الدفع نفسَه لزبونٍ لم يصله — أو يعيد إصدارَ ما تعذّر بعد موافقته.
     *
     * بالمسار نفسه (`issue`): الفاتورةُ برمزها، والرابطُ المفتوحُ نفسُه، والإرسالُ
     * محجوزٌ تحت القفل — فلا ورقةٌ ثانية ولا رابطٌ ثانٍ، ونقرتان رسالةٌ واحدة.
     * ورابطٌ وصل أو في طريقه لا يُعاد من هنا: الزبونُ يكتب فيصله ثانيةً.
     *
     * ومحاولةُ رابطٍ سابقةٌ لم تُعرف نتيجتُها (`PaymentLinks::doubt`) لا يُطلب بعدها رابطٌ
     * جديدٌ إلّا بـ`$replaceUncertain` — قرارُ موظّفٍ قيل له إنّ الأوّل ربّما أُنشئ عند Paymob.
     *
     * @return string|null حالُ رسالة الرابط بعد المحاولة — و`confirming` إن تغيّر المبلغُ فأُعيد الملخّصُ للزبون
     */
    public static function resendLink(WhatsAppChat $chat, ?User $user = null, bool $replaceUncertain = false): ?string
    {
        $session = DB::transaction(function () use ($chat) {
            WhatsAppChat::whereKey($chat->id)->lockForUpdate()->first();
            $locked = WhatsAppOrderSession::where('open_key', $chat->id)->lockForUpdate()->first();
            $why = $locked === null ? __('لا طلب آليًّا مفتوحًا في هذه المحادثة.') : self::cannotResendLink($locked);

            if ($why !== null) {
                throw ValidationException::withMessages(['order' => $why]);
            }

            if ($locked->state === WhatsAppOrderSession::NEEDS_HUMAN) {
                $locked->forceFill(['state' => WhatsAppOrderSession::AWAITING_PAYMENT, 'handoff_reason' => null, 'error' => null])->save();
            }

            return $locked;
        });

        self::issue((int) $session->id, byStaff: true, userId: $user?->id, replaceUncertain: $replaceUncertain);
        $session->refresh();

        return $session->state === WhatsAppOrderSession::CONFIRMING
            ? WhatsAppOrderSession::CONFIRMING
            : self::sendStatus($session, self::SEND_LINK);
    }

    /**
     * لمَ لا يُعاد الرابطُ من الصندوق — أو `null` إن جاز.
     *
     * يجوز لطلبٍ ينتظر الدفعَ لم تصل رسالةُ رابطه (أو علقت فاتورتُه قبل الرابط)،
     * ولطلبٍ حُوِّل لموظّفٍ لأنّ فاتورتَه أو رابطَه تعذّر. ورابطٌ انتهى أو دُفع لا يُعاد.
     */
    private static function cannotResendLink(WhatsAppOrderSession $session): ?string
    {
        $retry = $session->state === WhatsAppOrderSession::NEEDS_HUMAN && in_array($session->handoff_reason, self::RETRYABLE, true);

        if ($session->state !== WhatsAppOrderSession::AWAITING_PAYMENT && ! $retry) {
            return __('لا رابط دفع يُعاد لهذا الطلب.');
        }

        $link = $session->payment_link_id !== null
            ? CustomerPaymentLink::where('business_id', $session->business_id)->find($session->payment_link_id)
            : null;

        if ($link !== null && ! $link->open()) {
            return __('رابط الدفع انتهى أو أُغلق — أنشئ رابطًا من صفحة الفاتورة.');
        }

        if ($retry) {
            return null;
        }

        $status = self::sendStatus($session, self::SEND_LINK);

        if ($status === null) {
            // لم يُحجز إرسالٌ قطّ: المهمّةُ تعمل الآن — أو علقت قبل الرابط
            $since = $session->confirmed_at ?? $session->updated_at;

            return $since !== null && $since->lt(now()->subMinutes(self::SENDING_STALE_MINUTES))
                ? null
                : __('الرابط يُجهَّز الآن — انتظر قليلًا.');
        }

        return self::undelivered($status) ? null : __('رسالة الرابط وصلت العميل أو في طريقها — لا حاجة لإعادتها.');
    }

    /**
     * يعيد الموظّفُ تأكيدَ الدفع لزبونٍ دفع ولم يصله التأكيد.
     *
     * رسالةٌ وحدها (`notifyPaid`): لا تحصيلٌ ولا تفعيلٌ ولا مهمّةُ تجهيزٍ ثانية.
     * وتأكيدٌ وصل أو في طريقه لا يُعاد.
     *
     * @return string|null حالُ رسالة التأكيد بعد المحاولة
     */
    public static function resendPaid(WhatsAppChat $chat): ?string
    {
        [$session, $attempt] = DB::transaction(function () use ($chat) {
            WhatsAppChat::whereKey($chat->id)->lockForUpdate()->first();
            $latest = self::latest($chat);
            $locked = $latest !== null ? WhatsAppOrderSession::whereKey($latest->id)->lockForUpdate()->first() : null;

            if ($locked === null || $locked->state !== WhatsAppOrderSession::PAID) {
                throw ValidationException::withMessages(['order' => __('لا طلب مدفوعًا في هذه المحادثة.')]);
            }

            if (! self::undelivered(self::sendStatus($locked, self::SEND_PAID))) {
                throw ValidationException::withMessages(['order' => __('رسالة التأكيد وصلت العميل أو في طريقها — لا حاجة لإعادتها.')]);
            }

            $attempt = self::claimSend($locked, self::SEND_PAID);
            $locked->save();

            return [$locked, $attempt];
        });

        self::notifyPaid($session->fresh(), (int) $attempt);

        return self::sendStatus($session->fresh(), self::SEND_PAID);
    }

    /**
     * طلبُ المحادثة كما يراه الموظّف — حالُه ومبلغُه وفاتورتُه ورابطُه.
     *
     * @return array<string, mixed>|null
     */
    public static function forChat(WhatsAppChat $chat): ?array
    {
        if (! self::allowed((int) $chat->business_id)) {
            return null;
        }

        $session = self::latest($chat);

        if ($session === null) {
            return null;
        }

        $invoice = $session->customer_invoice_id ? CustomerInvoice::where('business_id', $chat->business_id)->find($session->customer_invoice_id) : null;
        $link = $session->payment_link_id ? CustomerPaymentLink::where('business_id', $chat->business_id)->find($session->payment_link_id) : null;

        return [
            'id' => (int) $session->id,
            'state' => (string) $session->state,
            'open' => $session->isOpen(),
            'step' => $session->step,
            'total' => $session->total !== null ? (float) $session->total : null,
            'reason' => $session->handoff_reason,
            'error' => $session->error,
            'items' => count((array) $session->cart),
            'invoice' => $invoice ? ['id' => (int) $invoice->id, 'number' => (string) $invoice->number] : null,
            'link' => $link ? ['status' => (string) $link->status, 'open' => $link->open(), 'url' => (string) $link->short_url] : null,
            // محاولةُ رابطٍ لم تُعرف نتيجتُها عند Paymob — فالإعادةُ تطلب جديدًا بقرارٍ صريح
            'link_uncertain' => $invoice !== null && $link === null && PaymentLinks::doubt($invoice) !== null,
            // ورسالتا الرابط والتأكيد: أين وصلتا، وهل يُعادان من هنا
            'link_message' => self::sendStatus($session, self::SEND_LINK),
            'paid_message' => self::sendStatus($session, self::SEND_PAID),
            'can_resend_link' => $session->isOpen() && self::cannotResendLink($session) === null,
            'can_resend_paid' => $session->state === WhatsAppOrderSession::PAID && self::undelivered(self::sendStatus($session, self::SEND_PAID)),
            'updated_at' => optional($session->updated_at)->toIso8601String(),
        ];
    }

    /**
     * بطاقةُ الإعداد في شاشة واتساب — أو `null` لمتجرٍ لم تُفتح له الميزة.
     *
     * @return array{enabled: bool, active: bool, checks: array<string, bool>}|null
     */
    public static function view(Business $business): ?array
    {
        if (! self::allowed((int) $business->id)) {
            return null;
        }

        return [
            'enabled' => self::enabled((int) $business->id),
            'active' => self::active($business),
            'checks' => self::readiness($business),
        ];
    }

    /**
     * أزرارُ الردّ الترحيبيّ حين يعمل الطلب — «اطلب الآن» و«موظّف».
     *
     * @return array<string, mixed>|null كائنُ `interactive`، أو `null` لنصٍّ بلا أزرار
     */
    public static function welcome(?Business $business, string $text, string $lang): ?array
    {
        if (! self::active($business)) {
            return null;
        }

        return WhatsAppOrderReplies::interactive([
            'kind' => 'buttons',
            'body' => $text,
            'buttons' => [
                [self::START_ID, WhatsAppOrderTexts::say($lang, 'welcome_order')],
                [self::STAFF_ID, WhatsAppOrderTexts::say($lang, 'welcome_staff')],
            ],
        ]);
    }
}
