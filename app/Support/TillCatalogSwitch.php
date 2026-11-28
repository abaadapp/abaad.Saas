<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * ═══ مفتاحُ كتالوج الصندوق على الجهاز — بيد مدير المنصّة، ويسري في الطلب التالي ═══
 *
 * كان المفتاحُ متغيّرَ بيئة (`POS_CATALOG_ON_DEVICE`) يُقرأ من الإعداد المخبّأ. وopcache
 * على الخادم لا يفحص تواريخ الملفّات (`validate_timestamps=0`)، فلا يسري تغييرُه حتى
 * `config:cache` ثمّ إعادةِ تحميل php-fpm. وفي 2026-11-20 أُطفئ بالخطوتين الأوليين
 * وحدهما فلم يتغيّر ما يقدّمه الموقع. ومفتاحُ طوارئ لا يعمل من أوّل مرّةٍ ليس مفتاحًا.
 *
 * فصار صفًّا في إعدادات المنصّة (`settings`، بلا متجر)، يُقرأ من القاعدة في كلّ طلب:
 * صفحةِ الصندوق وسؤالِ الكتالوج. فلا يحتاج إطفاؤه ولا تشغيلُه نشرًا ولا `.env` ولا
 * إعادةَ تشغيل.
 *
 *   - **لا صفّ** = مشغَّل، كما هو منذ v6.600: لا يتغيّر شيءٌ عند التجّار حتى يُضغط المفتاح.
 *   - **`POS_CATALOG_ON_DEVICE=false`** يبقى إطفاءً أعلى منه (للنشر وللاختبارات): يُطفئ
 *     مهما قال الصفّ، ولا يُشعَل من الشاشة.
 *   - **صفٌّ لا يُقرأ** (خطأٌ في القاعدة): مطفأ، أي الطريقُ القديم. الصفحةُ تحمل أصنافَ
 *     الخادم وتسأل عن السعر كما كانت، ولا يُعرض كتالوجٌ لم يُتحقَّق منه الآن.
 *
 * وكلُّ تبديلٍ يُقيَّد في سجلّ النشاط: من، ومتى، وكان ماذا وصار ماذا.
 */
final class TillCatalogSwitch
{
    public const KEY = 'pos_catalog_on_device';

    /** ‏أيعمل الكتالوجُ على الجهاز الآن؟ — قراءةٌ واحدة، وخطأُها «لا» */
    public static function on(): bool
    {
        if (! config('pos.catalog_on_device')) {
            return false;
        }

        try {
            return self::row()?->value !== '0';
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * ‏‏علامةُ آخر تبديل — تدخل سياقَ الكتالوج (`TillCatalog::context`).
     *
     * فبعد كلّ تبديلٍ لا يُعفى رمزُ جهازٍ صدر قبله: يُبنى الكلُّ ويُقارَن الصفُّ المبنيّ
     * نفسُه. فما يعود إليه الجهازُ بعد التشغيل نسخةٌ مُتحقَّقٌ منها صفًّا صفًّا، لا ما
     * كان عنده يوم أُطفئ.
     */
    public static function epoch(): string
    {
        try {
            $row = self::row();
        } catch (\Throwable) {
            return '';
        }

        return $row ? $row->value.'@'.$row->updated_at?->getTimestamp() : '';
    }

    /**
     * ‏‏حالُ المفتاح كما تعرضه شاشةُ مدير المنصّة — وآخرُ ما تبدّل فيه.
     *
     * @return array{on: bool, platform: bool, env: bool, readable: bool, changed_at: ?string, history: list<array{at: string, by: string, from: bool, to: bool}>}
     */
    public static function state(): array
    {
        $env = (bool) config('pos.catalog_on_device');
        $readable = true;
        $row = null;
        try {
            $row = self::row();
        } catch (\Throwable $e) {
            report($e);
            $readable = false;
        }
        $platform = $row?->value !== '0';

        $history = [];
        if ($row) {
            foreach (ActivityLog::where('subject_type', Setting::class)->where('subject_id', $row->id)->where('action', 'settings')
                ->orderByDesc('id')->limit(5)->get(['user_name', 'description', 'created_at']) as $log) {
                $to = str_starts_with((string) $log->description, self::TURNED_ON);
                $history[] = ['at' => $log->created_at->toIso8601String(), 'by' => (string) $log->user_name, 'from' => ! $to, 'to' => $to];
            }
        }

        return [
            'on' => $env && $readable && $platform,
            'platform' => $platform,
            'env' => $env,
            'readable' => $readable,
            'changed_at' => $row?->updated_at?->toIso8601String(),
            'history' => $history,
        ];
    }

    private const TURNED_ON = 'شغّل كتالوج الصندوق على الجهاز';

    private const TURNED_OFF = 'أطفأ كتالوج الصندوق على الجهاز';

    /**
     * ‏‏يُشعَل أو يُطفأ — بيد مدير المنصّة وحده (الحارسُ على المسار)، تحت قفل الصفّ.
     * ومن فعلها يُقرأ من الجلسة (`Activity::log`)، لا ممّا يرسله الطلب.
     *
     * وما هو عليه أصلًا لا يُكتب ولا يُقيَّد: ضغطتان من تبويبين لا تصنعان سطرين يقولان
     * إنّه تبدّل مرّتين.
     *
     * @return array{changed: bool, was: bool, now: bool}
     */
    public static function set(bool $on): array
    {
        return DB::transaction(function () use ($on) {
            $row = Setting::whereNull('business_id')->where('key', self::KEY)->orderByDesc('id')->lockForUpdate()->first();
            $was = $row?->value !== '0';
            // ‏‏ولا صفّ = مشغَّل: «شغّل» عليه لا يصنع صفًّا ولا سطرًا
            if ($was === $on) {
                return ['changed' => false, 'was' => $was, 'now' => $on];
            }

            $value = $on ? '1' : '0';
            if ($row) {
                $row->value = $value;
                // ‏‏وطابعُه يتحرّك ولو عاد إلى ما كان قبل ثانية — هو علامةُ التبديل (`epoch`)
                $row->updated_at = now();
                $row->save();
            } else {
                $row = Setting::create(['business_id' => null, 'key' => self::KEY, 'value' => $value]);
            }

            Activity::log('settings', ($on ? self::TURNED_ON : self::TURNED_OFF).' — كان '.($was ? 'مشغّلًا' : 'مطفأً').' وصار '.($on ? 'مشغّلًا' : 'مطفأً'), [
                'subject_type' => Setting::class,
                'subject_id' => $row->id,
            ]);

            return ['changed' => true, 'was' => $was, 'now' => $on];
        });
    }

    /** ‏‏صفُّ المفتاح — والأحدثُ إن وُجد اثنان (كتابتان متسابقتان قبل أوّل صفّ) */
    private static function row(): ?Setting
    {
        return Setting::whereNull('business_id')->where('key', self::KEY)->orderByDesc('id')->first(['id', 'value', 'updated_at']);
    }
}
