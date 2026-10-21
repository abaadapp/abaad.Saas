<?php

namespace App\Support\Store;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ساعةُ المتجر — وقتُه ويومُه كما يُكتبان لزبونه، بلغته.
 *
 * ═══ والساعةُ ساعةُ الخادم لا المتصفّح ═══
 *
 * زبونٌ في دبي أو جهازٌ ساعتُه متأخّرة لا يفتح متجرًا أُغلق ولا يحجز فترةً
 * مضت. فكلُّ حكمٍ بالوقت — استقبالُ الطلبات وفتراتُ التسليم — يُقرأ من هنا،
 * بمنطقة التطبيق (`config('app.timezone')`، وهي `Asia/Muscat`). والمتصفّحُ
 * يعرض ما قاله الخادم ولا يحسب.
 *
 * ═══ وأرقامٌ لاتينيّة في اللغتين ═══
 *
 * كما يُكتب كلُّ رقمٍ في النظام (`EveryFigureOnAPaperIsWesternTest`). وأسماءُ
 * الأيام والشهور مكتوبةٌ هنا لا من `translatedFormat`: تلك تتبدّل بنسخة
 * Carbon ولغتِها، وهذه تُقرأ في اختبار.
 */
final class ShopClock
{
    private const DAYS = [
        'ar' => ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
        'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    ];

    private const MONTHS = [
        'ar' => [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    /** الآن بمنطقة المتجر */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    public static function zone(): string
    {
        return (string) config('app.timezone', 'Asia/Muscat');
    }

    /** يومٌ بتاريخه في منطقة المتجر — `Y-m-d` إلى أوّل لحظةٍ فيه */
    public static function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd, self::zone());
    }

    /** اسمُ يوم الأسبوع — 0 الأحد كما يعدّه `dayOfWeek` */
    public static function weekday(int $dow, string $lang): string
    {
        return self::DAYS[self::lang($lang)][$dow % 7];
    }

    /**
     * «الاثنين 2 نوفمبر» / «Monday 2 November» — والسنةُ إن لم تكن سنةَ اليوم.
     */
    public static function day(CarbonInterface $at, string $lang): string
    {
        $lang = self::lang($lang);
        $at = CarbonImmutable::instance($at)->setTimezone(self::zone());
        $text = self::DAYS[$lang][$at->dayOfWeek].' '.$at->day.' '.self::MONTHS[$lang][$at->month];

        return $at->year !== self::now()->year ? $text.' '.$at->year : $text;
    }

    /** «4:00 م» / «4:00 PM» — والظهرُ «12:00 م» ومنتصفُ الليل «12:00 ص» */
    public static function time(CarbonInterface $at, string $lang): string
    {
        $at = CarbonImmutable::instance($at)->setTimezone(self::zone());

        return self::clock($at->hour, $at->minute, $lang);
    }

    /** ساعةٌ مكتوبةٌ `HH:MM` بصيغة الزبون */
    public static function hm(string $hhmm, string $lang): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);

        return self::clock($h, $m, $lang);
    }

    /** «9:00 ص – 12:00 م» */
    public static function range(string $from, string $to, string $lang): string
    {
        return self::hm($from, $lang).' – '.self::hm($to, $lang);
    }

    private static function clock(int $h, int $m, string $lang): string
    {
        $pm = $h >= 12;
        $twelve = $h % 12 === 0 ? 12 : $h % 12;
        $suffix = self::lang($lang) === 'en' ? ($pm ? 'PM' : 'AM') : ($pm ? 'م' : 'ص');

        return $twelve.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).' '.$suffix;
    }

    private static function lang(string $lang): string
    {
        return $lang === 'en' ? 'en' : 'ar';
    }
}
