<?php

namespace App\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;

/**
 * قناةُ واتساب في مركز المحادثات — الموضعُ الوحيد الذي يعرفها.
 *
 * ═══ الحدُّ الذي لا يُعبر ═══
 *
 * الرقمُ المشترك يُرسل إشعاراتِ الطلبات **نيابةً عن المحلّات**، فيردّ عليه
 * زبائنُهم. وتلك رسائلُ زبونٍ لمحلِّه: من يقرؤها في لوحة المنصّة يقرأ ما
 * كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها.
 *
 * فالقاعدةُ واحدة: **لا يُخزَّن واردٌ إلّا من رقمٍ يُطابق مستخدمًا واحدًا
 * في `users` له متجر**. لا زبونًا، ولا رقمًا مجهولًا، ولا رقمًا يُطابق
 * اثنين. وما لا يُخزَّن لا يُسرَّب ولا يُعرض ولا يخرج في نسخةٍ احتياطية.
 *
 * ═══ ونافذةُ ميتا حقيقةٌ لا تُلتفّ ═══
 *
 * النصُّ الحرُّ لا يخرج إلّا خلال أربعٍ وعشرين ساعةً من آخر رسالةٍ وصلت من
 * التاجر. وخارجَها يُردّ بالخطأ ١٣١٠٤٧. فالمنعُ يُقرأ هنا قبل النداء
 * ويُكتب في الرسالة `blocked` — لا «فشل» فيُظنَّ عطلًا يزول بالتكرار، ولا
 * صمتٌ فيُظنَّ الردُّ قد وصل.
 *
 * ═══ وحدُّ هذه المرحلة يُقال ═══
 *
 * نصٌّ يخرج ونصٌّ يدخل. والمرفقاتُ لا ترفع ولا تنزل عبر واتساب في هذه
 * النسخة — ولا يُصمت عن ذلك: الوارد غيرُ النصّيّ يُكتب بما هو، والصادرُ
 * ذو المرفق يحمل سطرًا يقول أين يُفتح.
 */
final class SupportWhatsApp
{
    /** نافذةُ ميتا للنصّ الحرّ — ساعةً بعد آخر واردٍ من التاجر */
    public const WINDOW_HOURS = 24;

    /* ═══════════════════ الخطّ ═══════════════════ */

    /**
     * خطُّ الدعم — وصلةُ منصّةٍ نشطةٌ صالحةٌ أُذن لها بالوارد صراحةً.
     *
     * والإذنُ عمودٌ لا استنتاج: وصلةُ الإشعارات ليست خطَّ دعمٍ بطبعها، ولو
     * كانت لَصار كلُّ ردٍّ من زبونٍ على إشعارِ طلبٍ محادثةَ دعمٍ تُقرأ في
     * لوحة المنصّة.
     */
    public static function line(): ?WhatsAppConnection
    {
        $connection = WhatsAppConnection::query()->platform()
            ->where('supports_inbox', true)
            ->where('status', WhatsAppConnection::ACTIVE)
            ->orderByDesc('id')->first();

        return $connection && $connection->isUsable() ? $connection : null;
    }

    /** هل القناةُ موصولةٌ فعلًا؟ — وعليها وحدَها تُعرض في الشاشات */
    public static function connected(): bool
    {
        return self::line() !== null;
    }

    /* ═══════════════════ من يكلّمنا ═══════════════════ */

    /**
     * صاحبُ الرقم — مستخدمٌ **واحدٌ** له متجر، أو لا أحد.
     *
     * والغموضُ رفض: رقمٌ يُطابق مستخدمَين في متجرَين لا يُعرف لأيّهما تُنسب
     * الرسالة، ونسبتُها لأحدهما بالحدس تعني محادثةً تُفتح باسم متجرٍ لم
     * يكتبها أحدٌ فيه.
     *
     * ═══ ولمَ يُمسح الجدول ═══
     *
     * الأرقامُ تُكتب كما اعتاد أصحابُها: «+968 7114 1624»، «96871141624»،
     * «71141624». فلا `where phone = ?` يُطابق شيئًا، ولا `like` على ذيلِ
     * الرقم ينجو من مسافةٍ في المنتصف. والتطبيعُ في PHP هو ما يُقارَن.
     *
     * وجدولُ المستخدمين موظّفو المتاجر لا زبائنُها: مئاتٌ لا مئاتُ آلاف،
     * ويُمسح مرّةً في كلّ رسالةٍ واردة — وهي أندرُ ما يجري في هذا النظام.
     */
    public static function sender(?string $phone): ?User
    {
        $digits = WhatsAppPhone::normalize($phone);

        if ($digits === null) {
            return null;
        }

        $matches = [];

        foreach (User::query()->whereNotNull('business_id')->whereNotNull('phone')->cursor() as $user) {
            if (WhatsAppPhone::normalize($user->phone) === $digits) {
                $matches[] = $user;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * من يستطيع أن يصل إلينا على واتساب فعلًا — ومن لا يستطيع.
     *
     * ═══ ولمَ يُقال هذا في الشاشة ═══
     *
     * القناةُ تُشعَل ثمّ لا يصل شيء، فيُظنّ العطبُ في الربط. والسببُ غالبًا
     * أنّ أصحابَ المتاجر لم يكتبوا أرقامهم في حساباتهم: رقمٌ غيرُ مسجَّلٍ
     * لا يُطابق أحدًا، ورسالتُه تُسقَط بحقّ.
     *
     * والمكرَّرُ يُقال كذلك: رقمٌ كتبه اثنان لا يُنسب لأحدهما، فرسالتُهما
     * كلتاهما تُسقَط — وذلك عطبُ بياناتٍ يُصلحه صاحبُه لا الكود.
     *
     * @return array{reachable:int, total:int, ambiguous:int}
     */
    public static function reach(): array
    {
        $seen = [];
        $total = 0;

        foreach (User::query()->whereNotNull('business_id')->get(['id', 'phone']) as $user) {
            $total++;
            $digits = WhatsAppPhone::normalize($user->phone);

            if ($digits !== null) {
                $seen[$digits] = ($seen[$digits] ?? 0) + 1;
            }
        }

        return [
            'reachable' => count(array_filter($seen, fn (int $n) => $n === 1)),
            'total' => $total,
            'ambiguous' => count(array_filter($seen, fn (int $n) => $n > 1)),
        ];
    }

    /* ═══════════════════ الوارد ═══════════════════ */

    /**
     * رسالةٌ وصلت من ميتا — تُقرأ أو تُسقَط، ولا شيء بينهما.
     *
     * @param  array<string, mixed>  $message  عنصرٌ من `value.messages`
     */
    public static function receive(WhatsAppConnection $connection, array $message): void
    {
        // بابٌ لم يُؤذن له: الواردُ يُسقَط كما كان قبل هذه المرحلة
        if (! $connection->supports_inbox || ! $connection->isUsable()) {
            return;
        }

        $wamid = (string) ($message['id'] ?? '');

        if ($wamid === '') {
            return;
        }

        /*
         * وإشعارٌ وصل مرّتين يُكتب مرّة.
         *
         * ميتا تُعيد الإشعارَ حين لا تصلها ٢٠٠ في الوقت، وتُعيده أحيانًا وقد
         * وصلتها. والفحصُ هنا يُوفّر الطريق، والفهرسُ الفريدُ على العمود هو
         * الحارسُ الأخير خلفه: بين القراءة والكتابة نافذةٌ يمرّ منها الثاني.
         */
        if (SupportMessage::where('external_message_id', $wamid)->exists()) {
            return;
        }

        $user = self::sender($message['from'] ?? null);

        // رقمٌ لا نعرفه — زبونُ محلٍّ يردّ على إشعارِ طلبه، أو رقمٌ مجهول
        if (! $user || $user->business_id === null) {
            return;
        }

        $phone = WhatsAppPhone::normalize($message['from'] ?? null);
        $body = self::inboundBody($message);

        /*
         * وإشعارٌ توأمٌ يصل في اللحظة نفسِها يموت وحدَه.
         *
         * ميتا قد تُرسل النسختين معًا فتمرّان الفحصَ أعلاه معًا، والفهرسُ
         * الفريدُ يردّ الثانية. و`Contention` هي التي تلتقطها — لا `catch`
         * هنا: على PostgreSQL يُجهض أوّلُ أمرٍ فاشلٍ المعاملةَ كلَّها، فالتقاطُ
         * الاستثناء بلا نقطة حفظٍ يترك معاملةً ميّتةً في اليد.
         *
         * ولا يُرفع الاستثناءُ إلى ميتا: خطأٌ يُردّ إليها يعني إعادةَ إرسالٍ
         * ثالثة، ثمّ إيقافَ الإشعارات عن عنوانٍ يُكثر الخطأ.
         *
         * وردُّ `attempt` لا يُقرأ — وهو ما تُحذّر منه في غير هذا الموضع:
         * النجاحُ والاصطدامُ سواءٌ هنا، كلاهما يعني أنّ الرسالة مكتوبةٌ
         * مرّةً واحدة.
         */
        Contention::attempt(fn () => self::store($user, $phone, $body, $wamid));
    }

    /**
     * كتابةُ الوارد في خيطه — داخلَ نقطةِ حفظِ `Contention`.
     *
     * @param  non-empty-string  $wamid
     */
    private static function store(User $user, ?string $phone, string $body, string $wamid): void
    {
        /*
         * خيطُ واتساب لهذا المتجر — حيًّا، أو حديثًا لم تمضِ عليه النافذة.
         *
         * ومحادثةٌ حُلّت قبل شهرٍ لا تُبعث برسالةٍ عن شيءٍ آخر: من كتب اليوم
         * يكتب في خيطٍ جديد، ويبقى القديمُ محلولًا كما أُغلق.
         */
        $conversation = SupportConversation::where('business_id', $user->business_id)
            ->where('channel', 'whatsapp')
            ->where(fn ($q) => $q->whereNotIn('status', ['resolved', 'closed'])
                ->orWhere('last_message_at', '>=', now()->subHours(self::WINDOW_HOURS)))
            ->orderByDesc('last_message_at')
            ->lockForUpdate()
            ->first();

        if ($conversation) {
            Support::businessReplied($conversation, $user, $body, $wamid);
        } else {
            $conversation = Support::open(
                $user,
                self::subjectFrom($body),
                'أخرى',
                $body,
                'whatsapp',
                $phone,
                $wamid,
            );
        }

        /*
         * وختمُ النافذة يُكتب هنا وحدَه.
         *
         * `last_message_at` يتحرّك بردّ أبعادٍ أيضًا، فلو حُسبت النافذةُ منه
         * لَظنّت الشاشةُ البابَ مفتوحًا لأنّ الدعمَ كتب — وميتا تردّ الرسالة.
         */
        $conversation->forceFill([
            'contact_phone' => $phone,
            'whatsapp_window_at' => now(),
        ])->save();
    }

    /**
     * نصُّ الوارد — أو وصفٌ صادقٌ لما لا يُقرأ.
     *
     * صورةٌ أو صوتٌ أو موقعٌ لا يُنزَّل في هذه النسخة. وإسقاطُ الرسالة يعني
     * تاجرًا أرسل ولم يردّ عليه أحد؛ وكتابةُ نصٍّ فارغٍ تعني سطرًا أبيضَ لا
     * يُفهم. فيُكتب نوعُها.
     *
     * @param  array<string, mixed>  $message
     */
    private static function inboundBody(array $message): string
    {
        $type = (string) ($message['type'] ?? '');

        if ($type === 'text') {
            $body = trim((string) ($message['text']['body'] ?? ''));

            if ($body !== '') {
                return mb_substr($body, 0, 5000);
            }
        }

        // ردُّ زرٍّ أو قائمة — نصُّه هو ما ضغطه صاحبه
        $pressed = trim((string) match ($type) {
            'button' => $message['button']['text'] ?? '',
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? '',
            default => '',
        });

        if ($pressed !== '') {
            return mb_substr($pressed, 0, 5000);
        }

        return __('[أرسل التاجر :type عبر واتساب — لا يُعرض هنا. اطلب منه رفعه من داخل أبعاد.]', [
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

    /** عنوانُ الخيط — أوّلُ سطرٍ ممّا كُتب، فهو ما يبحث به الدعم */
    private static function subjectFrom(string $body): string
    {
        $line = trim(explode("\n", $body)[0]);

        return $line === '' ? __('رسالة واتساب') : mb_substr($line, 0, 120);
    }

    /* ═══════════════════ الصادر ═══════════════════ */

    /** هل نافذةُ النصّ الحرّ مفتوحةٌ الآن؟ */
    public static function windowOpen(SupportConversation $conversation): bool
    {
        return $conversation->whatsapp_window_at !== null
            && $conversation->whatsapp_window_at->gt(now()->subHours(self::WINDOW_HOURS));
    }

    /** متى تُغلق — تُعرض للدعم قبل أن يكتب لا بعد أن يُمنع */
    public static function windowEndsAt(SupportConversation $conversation): ?string
    {
        return $conversation->whatsapp_window_at
            ? $conversation->whatsapp_window_at->addHours(self::WINDOW_HOURS)->toIso8601String()
            : null;
    }

    /**
     * إخراجُ ردٍّ إلى واتساب — وكتابةُ ما جرى عليه.
     *
     * وتُنادى **بعد** انتهاء المعاملة: نداءُ شبكةٍ داخل معاملةٍ مفتوحة يُبقي
     * القفلَ على الصفوف حتى يردّ خادمٌ في بلدٍ آخر أو تنتهي المهلة.
     */
    public static function deliver(SupportConversation $conversation, SupportMessage $message): void
    {
        /*
         * الملاحظةُ الداخليّة لا تخرج — أوّلُ سطرٍ وقبل كلّ فحص.
         *
         * ولو تأخّر هذا الفحصُ خلف فحص القناة لَبقي ميتًا ما دامت المحادثةُ
         * `in_app`، ثمّ خرج كلامُ الفريق عن تاجرٍ إلى هاتف التاجر نفسِه أوّلَ
         * يومٍ تُفتح فيه محادثةُ واتساب.
         */
        if ($message->is_internal) {
            return;
        }

        // قناةٌ أخرى: الردُّ في الشاشة وحدها، ولا تسليمَ يُقاس
        if ($conversation->channel !== 'whatsapp') {
            return;
        }

        $line = self::line();

        if (! $line) {
            self::stamp($message, 'failed', __('خطُّ دعم واتساب غير موصول.'));

            return;
        }

        $to = (string) $conversation->contact_phone;

        if ($to === '') {
            self::stamp($message, 'failed', __('لا رقمَ محفوظًا لهذه المحادثة.'));

            return;
        }

        if (! self::windowOpen($conversation)) {
            self::stamp($message, 'blocked', __('نافذةُ واتساب مغلقة — لم تصل رسالةٌ من التاجر منذ أكثر من :h ساعة.', [
                'h' => self::WINDOW_HOURS,
            ]));

            return;
        }

        $result = MetaWhatsAppClient::sendText($line, $to, self::outboundBody($message));

        if ($result['ok']) {
            $message->forceFill([
                'delivery' => 'sent',
                'delivery_error' => null,
                'external_message_id' => $result['id'],
            ])->save();

            return;
        }

        self::stamp($message, 'failed', mb_substr((string) $result['message'], 0, 200));
    }

    /**
     * نصُّ الصادر — كما كُتب، ومعه ما لا يستطيع واتساب حملَه.
     *
     * مرفقٌ لا يخرج في هذه النسخة. وإخراجُ النصّ وحده صمتًا يعني تاجرًا
     * يقرأ «أرفقتُ لك الصورة» ولا صورةَ عنده.
     */
    private static function outboundBody(SupportMessage $message): string
    {
        $body = (string) $message->body;
        $files = $message->attachments()->count();

        if ($files > 0) {
            $body .= "\n\n".__('(أُرفق :n ملفًّا — يُفتح من صفحة الدعم داخل أبعاد.)', ['n' => $files]);
        }

        return $body;
    }

    private static function stamp(SupportMessage $message, string $state, string $reason): void
    {
        $message->forceFill(['delivery' => $state, 'delivery_error' => $reason])->save();
    }

    /** كيف تُقرأ حالُ التسليم في الشاشة */
    public static function deliveryLabel(?string $state): ?string
    {
        return match ($state) {
            'sent' => __('أُرسلت عبر واتساب'),
            'failed' => __('لم تُرسل'),
            'blocked' => __('لم تخرج — النافذة مغلقة'),
            default => null,
        };
    }
}
