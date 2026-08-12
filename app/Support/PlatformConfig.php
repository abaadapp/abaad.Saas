<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات المنصة تُطبَّق على النظام فعلًا.
 *
 * كان تبويب البريد كلّه زينة: يملأ المشغّل المضيف والمنفذ وكلمة السرّ ويحفظ،
 * ولا سطر في النظام يمرّرها إلى config — فيبقى البريد يمشي على ما في .env
 * وحده. ثم يضغط «إرسال بريد تجريبي» فيقول له النظام «تمّ» لأن المرسِل هو
 * `log`: كُتبت الرسالة في ملفّ ولم تخرج. إعدادٌ لا يُطبَّق أهونُ من إعدادٍ
 * يكذب عند اختباره.
 */
class PlatformConfig
{
    /** المفاتيح التي تُطبَّق عند الإقلاع، وما تقابله في config */
    private static ?array $cache = null;

    /**
     * يقرأ إعدادات المنصة مرّةً واحدة في العمليّة.
     *
     * والحارس ليس تزيّدًا: هذا يجري عند كل إقلاع — بما فيه `migrate` على
     * قاعدةٍ فارغة لا جدول settings فيها بعد، وحينها استعلامٌ واحد يمنع
     * الهجرة من أن تبدأ أصلًا.
     */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return self::$cache = [];
            }

            return self::$cache = Setting::whereNull('business_id')->pluck('value', 'key')->all();
        } catch (\Throwable) {
            return self::$cache = [];
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * حال البريد كما هو فعلًا — للعرض في شاشة المنصة.
     *
     * `delivers` هو السؤال الوحيد الذي يهمّ: هل تخرج الرسالة من الخادم؟
     * `log` يكتبها في ملفّ، و`array` يحتفظ بها في الذاكرة — وكلاهما «ينجح».
     */
    public static function mailStatus(): array
    {
        $mailer = (string) config('mail.default');

        return [
            'mailer' => $mailer,
            'host' => (string) (config("mail.mailers.{$mailer}.host") ?? ''),
            'port' => config("mail.mailers.{$mailer}.port"),
            'fromAddress' => (string) config('mail.from.address'),
            'fromName' => (string) config('mail.from.name'),
            'delivers' => ! in_array($mailer, ['log', 'array', 'null'], true),
        ];
    }

    public static function apply(): void
    {
        $s = self::all();
        if (! $s) {
            return;
        }

        $name = trim((string) ($s['app_name'] ?? ''));
        if ($name !== '') {
            Config::set('app.name', $name);
        }

        /*
         * المضيف والمنفذ لا يُطبَّقان من هنا عن قصد.
         *
         * SMTP بلا اسم مستخدم وكلمة سرّ لا يسلّم شيئًا، وتخزين كلمة سرّ
         * الخادم نصًّا في جدول settings يجعل كلّ نسخةٍ احتياطية تحملها. فبقيت
         * الاعتمادات في .env حيث موضعها، وصارت الشاشة تعرض ما يعمل فعلًا بدل
         * أن تقبل ما لا يُقرأ. أما اسم المُرسِل وعنوانه فلا سرّ فيهما — وهما
         * ما يراه المستقبِل — فيُطبَّقان.
         */
        $from = trim((string) ($s['from_address'] ?? ''));
        if ($from !== '') {
            Config::set('mail.from.address', $from);
        }
        $fromName = trim((string) ($s['from_name'] ?? ''));
        if ($fromName !== '') {
            Config::set('mail.from.name', $fromName);
        }
    }
}
