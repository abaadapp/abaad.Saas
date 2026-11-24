<?php

namespace App\Support;

use App\Models\ChannelAutomationRun;
use App\Models\InstagramConnection;
use App\Models\InstagramDataDeletion;
use App\Models\InstagramEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ‏‏ما يصل من إنستقرام — إشعاراتُ الحساب، وإلغاءُ الإذن، وطلبُ حذف البيانات.
 *
 * ═══ الإشعارات: تُحفظ فورًا وتُعالج بعدها ═══
 *
 * Meta تعيد الإشعار ستًّا وثلاثين ساعةً ما لم يُجب بـ200، ومنشورٌ ينتشر يجلب آلاف التعليقات دفعةً.
 * فكلُّ تعليقٍ أو رسالةٍ يُكتب صفًّا بمفتاحٍ فريد (معرّفُ التعليق أو الرسالة) ويُجاب فورًا — والمكرَّرُ
 * لا يُكتب ثانية. ولا يُردّ هنا على أحد: الردُّ في الطابور (`InstagramAutomations::queue`).
 *
 * ولا يُحفظ ما ليس لحسابٍ مربوطٍ يعمل في متجرٍ فُتح له إنستقرام: إشعارُ حسابٍ فصله صاحبُه يُجاب
 * بـ200 ويُترك.
 */
class InstagramWebhooks
{
    /** ‏‏تُحذف الإشعاراتُ الخام بعد هذه المدّة — فيها تعليقاتُ الناس ورسائلُهم */
    public const KEEP_DAYS = 30;

    public static function signatureValid(?string $header, string $raw): bool
    {
        if (! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $given = substr($header, 7);

        foreach (InstagramPlatform::signingSecrets() as $secret) {
            if (hash_equals(hash_hmac('sha256', $raw, $secret), $given)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ‏‏حمولةٌ صحّ توقيعُها — كم حدثًا جديدًا حُفظ.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function store(array $payload): int
    {
        if (($payload['object'] ?? null) !== 'instagram') {
            return 0;
        }

        $saved = 0;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $entry = (array) $entry;
            $connection = self::connectionOf($entry);
            $account = (string) ($connection?->ig_user_id ?? '');

            if ($connection === null || ! $connection->usable() || ! InstagramPlatform::allows((int) $connection->business_id)) {
                continue;
            }

            $events = [];
            $listening = InstagramAutomations::listening((int) $connection->business_id);
            $changes = (array) ($entry['changes'] ?? []);

            // ‏‏والوثيقةُ تعرض الشكلين: الحقلُ في `changes[]`، والحقلُ في المدخل نفسِه
            if (isset($entry['field'])) {
                $changes[] = ['field' => $entry['field'], 'value' => $entry['value'] ?? []];
            }

            foreach ($changes as $change) {
                $value = (array) ($change['value'] ?? []);
                $field = (string) ($change['field'] ?? 'unknown');
                $id = (string) ($value['id'] ?? $value['comment_id'] ?? '');
                $events[] = [$field, 'c:'.$field.':'.($id !== '' ? $id : sha1(json_encode($value))), $change];
            }

            foreach ((array) ($entry['messaging'] ?? []) as $item) {
                $item = (array) $item;
                $field = match (true) {
                    isset($item['postback']) => 'messaging_postbacks',
                    (bool) ($item['message']['is_echo'] ?? false) => 'message_echoes',
                    isset($item['message']) => 'messages',
                    default => 'messaging',
                };
                $mid = (string) ($item['message']['mid'] ?? $item['postback']['mid'] ?? '');
                $events[] = [$field, 'm:'.$field.':'.($mid !== '' ? $mid : sha1(json_encode($item))), $item];
            }

            foreach ($events as [$field, $key, $body]) {
                $row = Contention::attempt(fn () => InstagramEvent::create([
                    'business_id' => $connection->business_id,
                    'instagram_connection_id' => $connection->id,
                    'event_key' => mb_substr($key, 0, 191),
                    'field' => mb_substr($field, 0, 40),
                    'counterpart' => InstagramAutomations::counterpart($field, (array) $body),
                    'payload' => ['account' => $account, 'time' => $entry['time'] ?? null] + (array) $body,
                    'received_at' => now(),
                ]));

                if (! $row instanceof InstagramEvent) {
                    continue;
                }

                $saved++;
                InstagramAutomations::queue($row, $listening);
            }

            $connection->forceFill(['last_webhook_at' => now()])->save();
        }

        return $saved;
    }

    /**
     * ‏‏الحسابُ الذي يعنيه المدخل — بمعرّفه.
     *
     * ومثالُ ضغطة الزرّ في الوثيقة يضع في `entry.id` معرّفَ الزبون لا الحساب: فهذا الشكلُ وحده (المدخلُ
     * باسم من ضغط، ولا تعليقَ فيه) يُسأل فيه عن المستلِم.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function connectionOf(array $entry): ?InstagramConnection
    {
        $id = (string) ($entry['id'] ?? '');
        $found = $id === '' ? null : InstagramConnection::where('ig_user_id', $id)->first();

        if ($found !== null || isset($entry['changes']) || isset($entry['field'])) {
            return $found;
        }

        $first = (array) (((array) ($entry['messaging'] ?? []))[0] ?? []);
        $to = (string) ($first['recipient']['id'] ?? '');

        return isset($first['postback']) && $to !== '' && $id === (string) ($first['sender']['id'] ?? '')
            ? InstagramConnection::where('ig_user_id', $to)->first()
            : null;
    }

    /**
     * ‏‏`signed_request` من Meta — حمولتُه إن صحّ توقيعُه بأحد سرّينا، وإلّا `null`.
     *
     * جزءان بنقطة، كلاهما base64url؛ والتوقيعُ HMAC-SHA256 على الجزء الثاني مرمَّزًا لا مفكوكًا.
     *
     * @return array<string, mixed>|null
     */
    public static function signedRequest(?string $signed): ?array
    {
        if (! is_string($signed) || substr_count($signed, '.') !== 1) {
            return null;
        }

        [$sig, $payload] = explode('.', $signed, 2);
        $decoded = self::base64url($sig);

        foreach (InstagramPlatform::signingSecrets() as $secret) {
            if ($decoded !== '' && hash_equals(hash_hmac('sha256', $payload, $secret, true), $decoded)) {
                $data = json_decode(self::base64url($payload), true);

                return is_array($data) && strtoupper((string) ($data['algorithm'] ?? '')) === 'HMAC-SHA256' ? $data : null;
            }
        }

        return null;
    }

    /** ‏‏الوصلةُ التي يعنيها معرّفُ Meta — بمعرّف التطبيق أو بمعرّف الحساب الاحترافيّ */
    public static function connectionFor(string $metaUserId): ?InstagramConnection
    {
        return $metaUserId === '' ? null : InstagramConnection::where('ig_scoped_id', $metaUserId)->orWhere('ig_user_id', $metaUserId)->first();
    }

    /** ‏‏ألغى صاحبُ الحساب إذنَ أبعاد من إنستقرام — يُمحى الرمز، وتبقى الوصلة «ملغاة» ليعرف التاجر */
    public static function deauthorize(string $metaUserId): bool
    {
        $connection = self::connectionFor($metaUserId);

        if ($connection === null) {
            return false;
        }

        InstagramLink::revoke($connection, 'أُلغي إذن أبعاد من إنستقرام');

        return true;
    }

    /**
     * ‏‏طلبُ حذف البيانات — تُحذف الوصلةُ وإشعاراتُها، ويُردّ برمز تأكيدٍ وعنوانِ حالٍ كما تشترط Meta.
     *
     * والطلبُ يُقيَّد ولو لم نجد له حسابًا: صاحبُه يفتح العنوان فيقرأ أنّ لا شيء عندنا باسمه.
     */
    public static function delete(string $metaUserId): InstagramDataDeletion
    {
        return DB::transaction(function () use ($metaUserId) {
            $connection = self::connectionFor($metaUserId);
            $businessId = $connection?->business_id;

            if ($connection !== null) {
                InstagramEvent::where('instagram_connection_id', $connection->id)->delete();
                // ‏‏وسجلُّ الردود فيه معرّفاتُ من كتبوا إلى الحساب — يُحذف معها، ويبقى ما كتبه التاجرُ من أتمتة
                ChannelAutomationRun::where('business_id', $businessId)->where('channel', ChannelAutomations::INSTAGRAM)->delete();
                $connection->delete();

                Activity::log('settings', 'إنستقرام: حُذفت بيانات الحساب @'.$connection->username.' بطلب صاحبه من Meta', [
                    'business_id' => $businessId,
                ]);
            }

            return InstagramDataDeletion::create([
                'confirmation_code' => Str::upper(Str::random(12)),
                'meta_user_id' => mb_substr($metaUserId, 0, 64),
                'business_id' => $businessId,
                'status' => InstagramDataDeletion::COMPLETED,
                'completed_at' => now(),
            ]);
        });
    }

    /** ‏‏يُحذف ما مضت عليه `KEEP_DAYS` — كم صفًّا */
    public static function prune(): int
    {
        return InstagramEvent::where('received_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    private static function base64url(string $v): string
    {
        $v = strtr($v, '-_', '+/');

        return (string) base64_decode(str_pad($v, (int) (ceil(strlen($v) / 4) * 4), '='), true);
    }
}
