<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * ‏‏لمن تُفتح حملاتُ واتساب — مفتاحُ منصّةٍ في القاعدة لا في `.env`، كأتمتة القنوات.
 *
 * مقفلةٌ افتراضًا، ثمّ لمتاجر تجربةٍ بأرقامها، ثمّ للجميع. ويُقرأ في كلّ طلبٍ وكلّ دفعة: إقفالُها يوقف
 * حملةً تُرسل الآن قبل رسالتها التالية، ولا يمحوها ولا يمحو من طلب الإيقاف.
 */
class WhatsAppCampaignsSwitch
{
    public const KEY = 'whatsapp_campaigns';

    public const PILOT_KEY = 'whatsapp_campaigns_pilot';

    public const OFF = 'off';

    public const PILOT = 'pilot';

    public const ALL = 'all';

    public const MODES = [self::OFF, self::PILOT, self::ALL];

    /** أكثرُ متاجر التجربة */
    public const PILOT_MAX = 50;

    public static function mode(): string
    {
        try {
            $value = self::row(self::KEY)?->value;
        } catch (\Throwable $e) {
            report($e);
            $value = null;
        }

        return in_array($value, self::MODES, true) ? $value : self::OFF;
    }

    /** @return list<int> */
    public static function pilot(): array
    {
        try {
            $value = (string) self::row(self::PILOT_KEY)?->value;
        } catch (\Throwable $e) {
            report($e);
            $value = '';
        }

        return self::ids($value);
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
     * @param  list<int>|string  $pilot
     * @return array{ok: bool, changed: bool, message: ?string}
     */
    public static function set(string $mode, array|string $pilot = []): array
    {
        if (! in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'changed' => false, 'message' => __('حال غير معروفة.')];
        }

        $ids = is_string($pilot) ? self::ids($pilot) : array_values(array_unique(array_filter(array_map('intval', $pilot), fn ($i) => $i > 0)));

        if (count($ids) > self::PILOT_MAX) {
            return ['ok' => false, 'changed' => false, 'message' => __('متاجر التجربة :max على الأكثر.', ['max' => self::PILOT_MAX])];
        }

        if ($mode === self::PILOT && $ids === []) {
            return ['ok' => false, 'changed' => false, 'message' => __('اكتب رقم متجر واحد على الأقل للتجربة.')];
        }

        return DB::transaction(function () use ($mode, $ids) {
            $modeRow = self::lockedRow(self::KEY);
            $pilotRow = self::lockedRow(self::PILOT_KEY);

            $was = ['mode' => self::mode(), 'pilot' => self::pilot()];
            $now = ['mode' => $mode, 'pilot' => $ids];

            if ($was === $now) {
                return ['ok' => true, 'changed' => false, 'message' => null];
            }

            $row = self::write($modeRow, self::KEY, $mode);
            self::write($pilotRow, self::PILOT_KEY, implode(',', $ids));

            Activity::log('settings', 'حملات واتساب: '.self::label($was['mode']).' ← '.self::label($mode)
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

    /** @return array{mode: string, pilot: list<int>, modes: list<string>, pilot_max: int} */
    public static function state(): array
    {
        return ['mode' => self::mode(), 'pilot' => self::pilot(), 'modes' => self::MODES, 'pilot_max' => self::PILOT_MAX];
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        $parts = preg_split('/[\s,،]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map('intval', $parts), fn ($i) => $i > 0)));
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

    private static function row(string $key): ?Setting
    {
        return Setting::whereNull('business_id')->where('key', $key)->orderByDesc('id')->first(['id', 'value']);
    }
}
