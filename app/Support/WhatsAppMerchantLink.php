<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * ═══ كيف يربط التجّارُ واتسابهم — بيد مدير المنصّة، ويسري في الطلب التالي ═══
 *
 * طريقان (`config/whatsapp.php` › `merchant_link`):
 *   · `own_app` — التاجرُ يربط تطبيقَه هو على Meta (`WhatsAppOwnApp`).
 *   · `abaad_app` — بضغطةٍ عبر تطبيق أبعاد، مزوّدًا تقنيًّا (التسجيل المدمج). رقمُ التاجر
 *     وحسابُه ووسيلةُ دفعه له، وMeta تفوتره مباشرة؛ وهو الطريقُ الوحيد الذي تُتيح فيه
 *     Meta بقاءَ الرقم في تطبيق واتساب للأعمال (التعايش).
 *
 * كان الاختيارُ متغيّرَ بيئة. ومتغيّرُ البيئة على هذا الخادم لا يسري حتى `config:cache`
 * ثمّ إعادةِ تحميل php-fpm (opcache بلا فحص طوابع — حادثة 2026-11-20). فصار صفًّا في
 * إعدادات المنصّة يُقرأ من القاعدة، ومعه رقمُ إعداد التسجيل المدمج (`config_id`) — وهو ليس
 * سرًّا: تحمله صفحةُ التاجر إلى نافذة Meta.
 *
 * ═══ ولا صفّ = ما في الإعداد ═══
 *
 * فيوم النشر لا يتغيّر شيء: يبقى ما كتبه الخادم (`own_app` افتراضًا). والصفُّ الذي لا
 * يُقرأ يُرجع إلى الإعداد كذلك — وهو الطريقُ القائم، لا طريقٌ جديد.
 *
 * ═══ ووصلةٌ قائمة لا تتبع المفتاح ═══
 *
 * نوعُ الوصلة مكتوبٌ فيها (`link_type`)، وإشعاراتُ تطبيق التاجر تصل على عنوانها الخاصّ.
 * فالمفتاحُ يقرّر كيف يُربط **الجديد** فقط — ووصلةُ RIBBON على تطبيقه تبقى كما هي.
 */
final class WhatsAppMerchantLink
{
    public const KEY = 'whatsapp_merchant_link';

    public const CONFIG_KEY = 'whatsapp_es_config_id';

    public const OWN_APP = 'own_app';

    public const ABAAD_APP = 'abaad_app';

    public const MODES = [self::OWN_APP, self::ABAAD_APP];

    /** ‏‏رقمُ إعداد Meta: أرقامٌ وحدها — كمعرّف التطبيق */
    public const CONFIG_ID_PATTERN = '/^[0-9]{5,25}$/';

    private const LABELS = [
        self::OWN_APP => 'تطبيق التاجر على Meta',
        self::ABAAD_APP => 'تطبيق أبعاد (بضغطة)',
    ];

    private const MODE_CHANGED = 'غيّر طريقة ربط واتساب للتجّار';

    private const CONFIG_CHANGED = 'غيّر رقم إعداد الربط المدمج';

    /** ‏‏الطريقُ الذي يسري الآن */
    public static function mode(): string
    {
        try {
            $value = self::row(self::KEY)?->value;
        } catch (\Throwable $e) {
            report($e);

            return self::fallbackMode();
        }

        return in_array($value, self::MODES, true) ? $value : self::fallbackMode();
    }

    public static function ownApp(): bool
    {
        return self::mode() === self::OWN_APP;
    }

    /** ‏‏رقمُ إعداد التسجيل المدمج — من الشاشة إن كُتب، وإلّا من الخادم */
    public static function configId(): string
    {
        try {
            $value = (string) self::row(self::CONFIG_KEY)?->value;
        } catch (\Throwable $e) {
            report($e);
            $value = '';
        }

        return preg_match(self::CONFIG_ID_PATTERN, $value) ? $value : (string) config('whatsapp.config_id');
    }

    /**
     * ‏‏ما تعرضه شاشةُ مدير المنصّة: الطريقُ الساري، وجاهزيّةُ الربط بضغطة، وآخرُ ما تبدّل.
     * ولا سرَّ فيه: سرُّ التطبيق يُقال «موجود» أو «ناقص» ولا يُعرض.
     *
     * @return array{mode: string, default: string, platform: ?string, readable: bool, config_id: string, config_source: string, app_id_ready: bool, app_secret_ready: bool, ready: bool, history: list<array{at: string, by: string, what: string}>}
     */
    public static function state(): array
    {
        $readable = true;
        $modeRow = $configRow = null;
        try {
            $modeRow = self::row(self::KEY);
            $configRow = self::row(self::CONFIG_KEY);
        } catch (\Throwable $e) {
            report($e);
            $readable = false;
        }

        $fromScreen = preg_match(self::CONFIG_ID_PATTERN, (string) $configRow?->value) === 1;
        $ids = array_values(array_filter([$modeRow?->id, $configRow?->id]));

        $history = [];
        if ($ids !== []) {
            foreach (ActivityLog::where('subject_type', Setting::class)->whereIn('subject_id', $ids)->where('action', 'settings')
                ->orderByDesc('id')->limit(6)->get(['user_name', 'description', 'created_at']) as $log) {
                $history[] = ['at' => $log->created_at->toIso8601String(), 'by' => (string) $log->user_name, 'what' => (string) $log->description];
            }
        }

        $configId = self::configId();

        return [
            'mode' => self::mode(),
            'default' => self::fallbackMode(),
            'platform' => in_array($modeRow?->value, self::MODES, true) ? $modeRow->value : null,
            'readable' => $readable,
            'config_id' => $configId,
            'config_source' => $fromScreen ? 'platform' : ($configId !== '' ? 'server' : 'none'),
            'app_id_ready' => filled(config('whatsapp.app_id')),
            'app_secret_ready' => filled(config('whatsapp.app_secret')),
            'ready' => WhatsAppEmbeddedSignup::configured(),
            'history' => $history,
        ];
    }

    /**
     * ‏‏يبدّل طريقَ الربط للتجّار الجدد — بيد مدير المنصّة وحده (الحارسُ على المسار)،
     * تحت قفل الصفّ. وما هو عليه أصلًا لا يُكتب ولا يُقيَّد.
     *
     * ولا يُفتح طريقُ تطبيق أبعاد والربطُ بضغطةٍ غيرُ مهيّأ: لَرأى كلُّ تاجرٍ زرًّا يقول
     * «غير مهيّأ على الخادم» مكان الطريق الذي كان يعمل.
     *
     * @return array{ok: bool, changed: bool, was: string, now: string, message: ?string}
     */
    public static function set(string $mode): array
    {
        return DB::transaction(function () use ($mode) {
            $row = self::lockedRow(self::KEY);
            $was = in_array($row?->value, self::MODES, true) ? $row->value : self::fallbackMode();

            if ($was === $mode) {
                return ['ok' => true, 'changed' => false, 'was' => $was, 'now' => $mode, 'message' => null];
            }

            if ($mode === self::ABAAD_APP && ! WhatsAppEmbeddedSignup::configured()) {
                return ['ok' => false, 'changed' => false, 'was' => $was, 'now' => $was,
                    'message' => __('الربط بضغطة غير جاهز — اكتب رقم إعداد الربط المدمج، وتأكّد أنّ معرّف تطبيق أبعاد وسرّه على الخادم.')];
            }

            $row = self::write($row, self::KEY, $mode);

            Activity::log('settings', self::MODE_CHANGED.' — كانت «'.self::LABELS[$was].'» وصارت «'.self::LABELS[$mode].'»', [
                'subject_type' => Setting::class,
                'subject_id' => $row->id,
            ]);

            return ['ok' => true, 'changed' => true, 'was' => $was, 'now' => $mode, 'message' => null];
        });
    }

    /**
     * ‏‏يكتب رقمَ إعداد التسجيل المدمج، أو يمحوه (`null`) فيُقرأ ما على الخادم.
     *
     * ولا يُمحى وتطبيقُ أبعاد هو طريقُ التجّار والخادمُ بلا رقم: يقف الربطُ لكلّ تاجر.
     *
     * @return array{ok: bool, changed: bool, message: ?string}
     */
    public static function setConfigId(?string $id): array
    {
        $id = trim((string) $id);

        if ($id !== '' && ! preg_match(self::CONFIG_ID_PATTERN, $id)) {
            return ['ok' => false, 'changed' => false, 'message' => __('رقم إعداد الربط المدمج أرقامٌ فقط، كما يظهر في لوحة Meta.')];
        }

        return DB::transaction(function () use ($id) {
            $row = self::lockedRow(self::CONFIG_KEY);
            $was = (string) $row?->value;

            if ($was === $id || ($row === null && $id === '')) {
                return ['ok' => true, 'changed' => false, 'message' => null];
            }

            if ($id === '' && self::mode() === self::ABAAD_APP && blank(config('whatsapp.config_id'))) {
                return ['ok' => false, 'changed' => false,
                    'message' => __('لا يُمحى رقم الإعداد والتجّار يربطون عبر تطبيق أبعاد — بدّل طريق الربط أوّلًا.')];
            }

            $row = self::write($row, self::CONFIG_KEY, $id);

            Activity::log('settings', self::CONFIG_CHANGED.' — كان «'.($was !== '' ? $was : '—').'» وصار «'.($id !== '' ? $id : '—').'»', [
                'subject_type' => Setting::class,
                'subject_id' => $row->id,
            ]);

            return ['ok' => true, 'changed' => true, 'message' => null];
        });
    }

    public static function label(string $mode): string
    {
        return self::LABELS[$mode] ?? $mode;
    }

    private static function fallbackMode(): string
    {
        return config('whatsapp.merchant_link', self::OWN_APP) === self::ABAAD_APP ? self::ABAAD_APP : self::OWN_APP;
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

    /** ‏‏الأحدثُ إن وُجد اثنان (كتابتان متسابقتان قبل أوّل صفّ) */
    private static function row(string $key): ?Setting
    {
        return Setting::whereNull('business_id')->where('key', $key)->orderByDesc('id')->first(['id', 'value', 'updated_at']);
    }
}
