<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * ‏‏إنستقرام عند المنصّة — معرّفُ تطبيق إنستقرام وسرُّه، ولمن يُفتح الربط.
 *
 * ═══ صفوفٌ في القاعدة لا `.env` ═══
 *
 * يكتبها مديرُ المنصّة من الشاشة، وتُقرأ في كلّ طلب: opcache على الإنتاج لا يعيد قراءة الإعداد
 * بعد النشر، فمفتاحٌ في `.env` يحتاج إعادة تشغيل. والسرُّ مشفَّرٌ بمفتاح التطبيق، ولا يعود إلى
 * الشاشة منه إلّا «محفوظ». وما لم يُكتب هنا يُقرأ من الخادم احتياطًا (`services.instagram`).
 *
 * ═══ ولمن يُفتح ═══
 *
 * مقفلٌ افتراضًا، ثمّ لمتاجر تجربةٍ بأرقامها، ثمّ للجميع. وإقفالُه يُخفي الشاشة ويُسكت الإشعارات،
 * ولا يمحو وصلةً قائمة.
 */
class InstagramPlatform
{
    public const APP_ID_KEY = 'instagram_app_id';

    public const SECRET_KEY = 'instagram_app_secret';

    public const MODE_KEY = 'instagram_link';

    public const PILOT_KEY = 'instagram_link_pilot';

    public const OFF = 'off';

    public const PILOT = 'pilot';

    public const ALL = 'all';

    public const MODES = [self::OFF, self::PILOT, self::ALL];

    public const PILOT_MAX = 50;

    public const APP_ID_PATTERN = '/^[0-9]{5,25}$/';

    public static function appId(): string
    {
        $row = self::value(self::APP_ID_KEY);

        return filled($row) ? (string) $row : (string) config('services.instagram.app_id');
    }

    public static function appSecret(): string
    {
        $stored = self::value(self::SECRET_KEY);

        if (filled($stored)) {
            try {
                return Crypt::decryptString((string) $stored);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return (string) config('services.instagram.app_secret');
    }

    /** ‏‏جاهزٌ للربط: معرّفٌ وسرّ */
    public static function configured(): bool
    {
        return self::appId() !== '' && self::appSecret() !== '';
    }

    /**
     * ‏‏رمزُ التحقّق الذي يُلصق في لوحة Meta لعنوان الإشعارات — ثابتٌ لهذا الخادم، ولا يُحفظ.
     *
     * يُشتقّ من مفتاح التطبيق، فلا يُكتب صفٌّ عند أوّل قراءة، ولا يتبدّل بنشر.
     */
    public static function verifyToken(): string
    {
        return substr(hash_hmac('sha256', 'instagram-webhook-verify', (string) config('app.key')), 0, 40);
    }

    /**
     * ‏‏الأسرارُ التي يُفحص بها توقيعُ Meta — سرُّ تطبيق إنستقرام، ثمّ سرُّ تطبيق Meta.
     *
     * وثيقةُ الإشعارات تقول «سرّ التطبيق» ولا تقول أيّهما في تسجيل الدخول عبر Instagram، فيُقبل
     * التوقيعُ بأيّهما. وكلاهما سرٌّ لنا: لا يصنع توقيعًا مقبولًا إلّا من يملك أحدَهما.
     *
     * @return list<string>
     */
    public static function signingSecrets(): array
    {
        return array_values(array_unique(array_filter([self::appSecret(), (string) config('whatsapp.app_secret')], fn ($s) => $s !== '')));
    }

    public static function mode(): string
    {
        $v = self::value(self::MODE_KEY);

        return in_array($v, self::MODES, true) ? $v : self::OFF;
    }

    /** @return list<int> */
    public static function pilot(): array
    {
        return self::ids((string) self::value(self::PILOT_KEY));
    }

    public static function allows(?int $businessId): bool
    {
        if ($businessId === null || $businessId <= 0) {
            return false;
        }

        return match (self::mode()) {
            self::ALL => true,
            self::PILOT => in_array($businessId, self::pilot(), true),
            default => false,
        };
    }

    /**
     * @return array{ok: bool, changed: bool, message: ?string}
     */
    public static function setCredentials(string $appId, ?string $secret): array
    {
        $appId = trim($appId);

        if ($appId !== '' && ! preg_match(self::APP_ID_PATTERN, $appId)) {
            return ['ok' => false, 'changed' => false, 'message' => __('معرّف تطبيق إنستقرام أرقام فقط.')];
        }

        return DB::transaction(function () use ($appId, $secret) {
            $idRow = self::lockedRow(self::APP_ID_KEY);
            $changed = (string) ($idRow?->value ?? '') !== $appId;
            $row = self::write($idRow, self::APP_ID_KEY, $appId);

            if ($secret !== null && trim($secret) !== '') {
                self::write(self::lockedRow(self::SECRET_KEY), self::SECRET_KEY, Crypt::encryptString(trim($secret)));
                $changed = true;
            }

            if ($changed) {
                Activity::log('settings', 'إنستقرام: حُفظ معرّف التطبيق'.($secret !== null && trim($secret) !== '' ? ' وسرّه' : ''), [
                    'subject_type' => Setting::class,
                    'subject_id' => $row->id,
                ]);
            }

            return ['ok' => true, 'changed' => $changed, 'message' => null];
        });
    }

    /**
     * @return array{ok: bool, changed: bool, message: ?string}
     */
    public static function setMode(string $mode, string $pilot = ''): array
    {
        if (! in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'changed' => false, 'message' => __('حال غير معروفة.')];
        }

        $ids = self::ids($pilot);

        if (count($ids) > self::PILOT_MAX) {
            return ['ok' => false, 'changed' => false, 'message' => __('متاجر التجربة :max على الأكثر.', ['max' => self::PILOT_MAX])];
        }

        if ($mode === self::PILOT && $ids === []) {
            return ['ok' => false, 'changed' => false, 'message' => __('اكتب رقم متجر واحد على الأقل للتجربة.')];
        }

        if ($mode !== self::OFF && ! self::configured()) {
            return ['ok' => false, 'changed' => false, 'message' => __('اكتب معرّف تطبيق إنستقرام وسرّه أولًا.')];
        }

        return DB::transaction(function () use ($mode, $ids) {
            $modeRow = self::lockedRow(self::MODE_KEY);
            $pilotRow = self::lockedRow(self::PILOT_KEY);
            $was = ['mode' => self::mode(), 'pilot' => self::pilot()];

            if ($was === ['mode' => $mode, 'pilot' => $ids]) {
                return ['ok' => true, 'changed' => false, 'message' => null];
            }

            $row = self::write($modeRow, self::MODE_KEY, $mode);
            self::write($pilotRow, self::PILOT_KEY, implode(',', $ids));

            Activity::log('settings', 'ربط إنستقرام: '.self::label($was['mode']).' ← '.self::label($mode)
                .($ids !== [] ? ' · متاجر التجربة: '.implode('، ', $ids) : ''), [
                    'subject_type' => Setting::class,
                    'subject_id' => $row->id,
                ]);

            return ['ok' => true, 'changed' => true, 'message' => null];
        });
    }

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::ALL => 'مفتوحة لكل التجار',
            self::PILOT => 'لمتاجر التجربة',
            default => 'مقفلة',
        };
    }

    /**
     * ‏‏ما تعرضه شاشةُ المنصّة — والعناوينُ التي تُلصق في لوحة Meta. ولا يخرج السرّ.
     *
     * @return array<string, mixed>
     */
    public static function state(): array
    {
        return [
            'mode' => self::mode(),
            'pilot' => self::pilot(),
            'modes' => self::MODES,
            'pilot_max' => self::PILOT_MAX,
            'app_id' => self::appId(),
            'secret_set' => self::appSecret() !== '',
            'configured' => self::configured(),
            'redirect_uri' => InstagramLink::redirectUri(),
            'webhook_url' => route('webhooks.instagram'),
            'verify_token' => self::verifyToken(),
            'deauthorize_url' => route('webhooks.instagram.deauthorize'),
            'deletion_url' => route('webhooks.instagram.deletion'),
            'scopes' => InstagramLink::SCOPES,
            'fields' => [...InstagramLink::FIELDS, InstagramLink::ECHOES],
        ];
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        $parts = preg_split('/[\s,،]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map('intval', $parts), fn ($i) => $i > 0)));
    }

    private static function value(string $key): ?string
    {
        try {
            return Setting::whereNull('business_id')->where('key', $key)->orderByDesc('id')->value('value');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function write(?Setting $row, string $key, string $value): Setting
    {
        if ($row) {
            $row->value = $value;
            $row->updated_at = now();
            $row->save();

            return $row;
        }

        return Setting::create(['business_id' => null, 'key' => $key, 'value' => $value]);
    }

    private static function lockedRow(string $key): ?Setting
    {
        return Setting::whereNull('business_id')->where('key', $key)->orderByDesc('id')->lockForUpdate()->first();
    }
}
