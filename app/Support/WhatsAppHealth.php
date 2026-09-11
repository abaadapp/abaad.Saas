<?php

namespace App\Support;

use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * هل تصل الرسائل فعلًا — لا هل يبدو كلُّ شيءٍ مربوطًا.
 *
 * ═══ العطب الذي جاءت منه ═══
 *
 * كانت مراحلُ الربط أربعًا: مفعَّلٌ في المنصّة، ومفعَّلٌ لحسابك، وباقتُك
 * تشمله، والرقمُ جاهز. وكلُّها تقرأ **إعدادًا**، ولا واحدةَ منها تقرأ
 * **نتيجة**.
 *
 * فحين حجبت Meta التطبيق بقيت الأربعُ خضراء و«جاهز» فوقها، وكلُّ رسالةٍ
 * تخرج تُردّ بـ«API access blocked» وتُكتب `failed` في جدولٍ لا تفتحه شاشة.
 * فيبيع التاجر ولا يصل زبونَه شيء، وينظر إلى لوحته فتطمئنه — **ولا يعرف
 * أنّ شيئًا انكسر حتّى يسأل**.
 *
 * وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب: الثاني يُزعج، والأوّل يُنيم.
 *
 * ═══ ومن يُصلح ماذا ═══
 *
 * أعطابُ الإرسال نوعان لا واحد، ولا يُخلطان:
 *
 *   · ما هو على **أبعاد**: تطبيقٌ محجوب، رمزٌ منتهٍ، رقمٌ موقوف. لا يملك
 *     التاجر منها شيئًا، وقولُ «راجع رقمك» له إهدارُ وقتٍ ولومٌ في غير محلّه.
 *   · ما هو على **التاجر**: رقمُ زبونٍ خاطئ، أو رسالةٌ خارج نافذة الساعات
 *     الأربع والعشرين.
 *
 * فيُقرأ رمزُ الخطأ من Meta ويُنسب العطبُ إلى صاحبه.
 */
final class WhatsAppHealth
{
    /** خلال كم ساعةٍ يُقرأ الفشل — وما قبلها تاريخٌ لا حال */
    public const WINDOW_HOURS = 24;

    /**
     * أخطاءٌ لا يملك التاجر إصلاحها — من Meta إلى أبعاد.
     *
     * `0` و`1` و`2` أعطابُ منصّةٍ أو مصادقة. و`3` و`10` و`200` و`299` أذونٌ
     * وحجب. و`131031` حسابٌ موقوف، و`133010` رقمٌ غير مسجَّل.
     */
    private const OURS = ['0', '1', '2', '3', '10', '200', '299', '131031', '133010', '131042'];

    /** آخرُ ما جرى للإرسال في هذه النافذة */
    public static function recent(int $businessId): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        $rows = WhatsAppMessage::where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->get(['status', 'error_code', 'error_message', 'failed_at', 'sent_at', 'created_at']);

        $failed = $rows->where('status', 'failed');
        $last = $failed->first();

        return [
            'attempts' => $rows->count(),
            'failed' => $failed->count(),
            'sent' => $rows->whereIn('status', ['sent', 'delivered', 'read'])->count(),
            'last_error' => $last?->error_message,
            'last_error_code' => $last?->error_code,
            'last_failed_at' => $last?->failed_at ? Carbon::parse($last->failed_at)->toDateTimeString() : null,
            /*
             * وهل نجح شيءٌ **بعد** آخر فشل.
             *
             * فشلٌ قديمٌ تلاه نجاحٌ عطبٌ عبر، وعرضُه إنذارًا قائمًا يجعل
             * التاجر يلاحق ما أُصلح — ثمّ يتوقّف عن قراءة الإنذارات.
             */
            'recovered' => $last !== null && $rows
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->filter(fn ($r) => $last->failed_at !== null
                    && Carbon::parse($r->sent_at ?? $r->created_at)->gt(Carbon::parse($last->failed_at)))
                ->isNotEmpty(),
        ];
    }

    /** أعلى أبعادَ إصلاحُه أم على التاجر؟ */
    public static function isOurs(?string $code): bool
    {
        return $code !== null && in_array(trim($code), self::OURS, true);
    }

    /**
     * إنذارُ الإرسال — أو لا شيء.
     *
     * @return array{level:string, text:string, ours:bool, count:int}|null
     */
    public static function alert(int $businessId): ?array
    {
        $r = self::recent($businessId);

        if ($r['failed'] === 0 || $r['recovered']) {
            return null;
        }

        $ours = self::isOurs($r['last_error_code']);

        return [
            'level' => 'danger',
            'ours' => $ours,
            'count' => $r['failed'],
            'text' => $ours
                ? __('لم تصل :n رسالة إلى زبائنك — العطب عند أبعاد لا عندك، ونحن نعالجه. (:e)', [
                    'n' => $r['failed'],
                    'e' => Str::limit((string) ($r['last_error'] ?? '—'), 90),
                ])
                : __('لم تصل :n رسالة إلى زبائنك — راجع أرقام الزبائن. (:e)', [
                    'n' => $r['failed'],
                    'e' => Str::limit((string) ($r['last_error'] ?? '—'), 90),
                ]),
        ];
    }

    /** حالُ المنصّة كلِّها — لمدير المنصّة وللنشر */
    public static function platform(): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        $rows = WhatsAppMessage::where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->get(['business_id', 'status', 'error_code', 'error_message']);

        $failed = $rows->where('status', 'failed');

        return [
            'attempts' => $rows->count(),
            'failed' => $failed->count(),
            'shops' => $failed->pluck('business_id')->unique()->count(),
            'ours' => $failed->filter(fn ($r) => self::isOurs($r->error_code))->count(),
            'last_error' => $failed->first()?->error_message,
        ];
    }
}
