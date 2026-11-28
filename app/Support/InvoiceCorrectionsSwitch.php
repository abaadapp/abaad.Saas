<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * ═══ مفتاحُ إيقاف تعديل فواتير العملاء وتصحيحها — بيد مدير المنصّة، ويسري في الطلب التالي ═══
 *
 * هو خطّةُ الرجوع، لا طلبُ Revert.
 *
 * بعد أوّل إشعارٍ مدين يصدر من تصحيح، لا يجوز الرجوعُ بالكود وحده: كودُ ما قبل
 * التعديل لا يعرف الإشعارات المدينة، فيقرأ ما على العميل أقلَّ ممّا عليه، ويُسقطها
 * من الإقرار الضريبيّ — يتجاهل مستندًا ماليًّا صدر. فإن ظهر خللٌ بعد النشر أُوقف
 * **إصدارُ** الجديد من هنا، وبقي **قارئُ** ما صدر كلُّه يعمل: الذمم والكشوف والإقرار
 * والسجلّ وأوراقُ المستندات. ثمّ يُصلَح الخلل بنشرٍ تالٍ ويُعاد التشغيل.
 *
 *   - **لا صفّ** = مشغَّل: يعمل التعديلُ كما نُشر، لكلّ التجّار.
 *   - **مطفأ:** لا يُعدَّل شيءٌ ولا يُصحَّح ولا يُستبدل — مسودّةً كان أو صادرة — ولا يُنفَّذ
 *     اعتمادٌ معلّق (#196)، فالاعتمادُ يمرّ بالباب نفسه. وما صدر يبقى كما صدر ويُقرأ.
 *   - **صفٌّ لا يُقرأ** (خطأٌ في القاعدة): مطفأ. مفتاحُ إيقافٍ يُخطئ نحو الإيقاف.
 *
 * صفٌّ في إعدادات المنصّة (`settings` بلا متجر) يُقرأ من القاعدة في كلّ طلب — فلا
 * يحتاج نشرًا ولا `.env` ولا إعادةَ تشغيل (انظر `TillCatalogSwitch` ولمَ لا متغيّرَ بيئة).
 * وكلُّ تبديلٍ يُقيَّد في سجلّ النشاط: من، ومتى، وكان ماذا وصار ماذا.
 */
final class InvoiceCorrectionsSwitch
{
    public const KEY = 'customer_invoice_corrections';

    private const TURNED_ON = 'شغّل تعديل فواتير العملاء وتصحيحها';

    private const TURNED_OFF = 'أوقف تعديل فواتير العملاء وتصحيحها';

    /** ‏أيُعدَّل ويُصحَّح الآن؟ — قراءةٌ واحدة، وخطؤها «لا» */
    public static function on(): bool
    {
        try {
            return self::row()?->value !== '0';
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** ‏ما يُقال لمن أراد التعديل والمفتاحُ مطفأ */
    public static function pausedReason(): string
    {
        return __('تعديل فواتير العملاء وتصحيحها موقوف مؤقتًا من إدارة المنصّة. ما صدر من فواتير ومستندات تصحيح باقٍ كما هو ويُقرأ، ولا يتغيّر شيء.');
    }

    /**
     * ‏‏حالُ المفتاح كما تعرضه شاشةُ مدير المنصّة — وآخرُ ما تبدّل فيه.
     *
     * @return array{on: bool, readable: bool, changed_at: ?string, history: list<array{at: string, by: string, from: bool, to: bool}>}
     */
    public static function state(): array
    {
        $readable = true;
        $row = null;
        try {
            $row = self::row();
        } catch (\Throwable $e) {
            report($e);
            $readable = false;
        }

        $history = [];
        if ($row) {
            foreach (ActivityLog::where('subject_type', Setting::class)->where('subject_id', $row->id)->where('action', 'settings')
                ->orderByDesc('id')->limit(5)->get(['user_name', 'description', 'created_at']) as $log) {
                $to = str_starts_with((string) $log->description, self::TURNED_ON);
                $history[] = ['at' => $log->created_at->toIso8601String(), 'by' => (string) $log->user_name, 'from' => ! $to, 'to' => $to];
            }
        }

        return [
            'on' => $readable && $row?->value !== '0',
            'readable' => $readable,
            'changed_at' => $row?->updated_at?->toIso8601String(),
            'history' => $history,
        ];
    }

    /**
     * ‏‏يُشغَّل أو يُوقف — بيد مدير المنصّة وحده (الحارسُ على المسار)، تحت قفل الصفّ.
     * وما هو عليه أصلًا لا يُكتب ولا يُقيَّد.
     *
     * @return array{changed: bool, was: bool, now: bool}
     */
    public static function set(bool $on): array
    {
        return DB::transaction(function () use ($on) {
            $row = Setting::whereNull('business_id')->where('key', self::KEY)->orderByDesc('id')->lockForUpdate()->first();
            $was = $row?->value !== '0';
            if ($was === $on) {
                return ['changed' => false, 'was' => $was, 'now' => $on];
            }

            $value = $on ? '1' : '0';
            if ($row) {
                $row->value = $value;
                $row->updated_at = now();
                $row->save();
            } else {
                $row = Setting::create(['business_id' => null, 'key' => self::KEY, 'value' => $value]);
            }

            Activity::log('settings', ($on ? self::TURNED_ON : self::TURNED_OFF).' — كان '.($was ? 'مشغّلًا' : 'موقوفًا').' وصار '.($on ? 'مشغّلًا' : 'موقوفًا'), [
                'subject_type' => Setting::class,
                'subject_id' => $row->id,
            ]);

            return ['changed' => true, 'was' => $was, 'now' => $on];
        });
    }

    private static function row(): ?Setting
    {
        return Setting::whereNull('business_id')->where('key', self::KEY)->orderByDesc('id')->first(['id', 'value', 'updated_at']);
    }
}
