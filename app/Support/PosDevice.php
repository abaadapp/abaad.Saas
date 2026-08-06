<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;

/**
 * الجهاز الذي تقف عليه نقطة البيع، ومتجره.
 *
 * صار رمز الدخول فريدًا داخل المتجر لا عبر المنصة (انظر
 * EmployeeController::pinTaken)، فلم يعد الرمز وحده يكفي لمعرفة صاحبه:
 * «1234» قد تكون لكاشيرٍ عندك ولآخر عند جارك.
 *
 * فالجهاز يتذكّر متجره. يُربط مرّةً واحدة حين يدخل أحدُ العاملين ببريده
 * وكلمة مروره على هذا الجهاز — وهو ما يحدث فعلًا يوم التركيب — ثم يعمل
 * الكاشير بالرمز وحده بعدها.
 *
 * وكوكي موقَّعة لا جلسة: الجلسة تنتهي بالخروج، والجهاز يبقى هو الجهاز.
 */
class PosDevice
{
    public const COOKIE = 'pos_business';

    /** سنتان: عمر جهاز الصندوق أطول من أي جلسة */
    private const DAYS = 730;

    /** يربط هذا الجهاز بمتجر — يُستدعى بعد كل دخولٍ ببريد وكلمة مرور */
    public static function remember(?int $businessId): void
    {
        if (! $businessId) {
            return;
        }

        Cookie::queue(Cookie::make(self::COOKIE, (string) $businessId, self::DAYS * 24 * 60));
    }

    /**
     * متجر هذا الجهاز.
     *
     * وحين لا يكون مربوطًا: إن كان في المنصة متجرٌ واحد فهو هو. هذا يُبقي
     * التركيبات المفردة تعمل بلا خطوةٍ إضافية، ولا يفتح شيئًا — إذ لا جار
     * يُخلط به.
     */
    public static function businessId(): ?int
    {
        $id = Request::cookie(self::COOKIE);
        if ($id && Business::whereKey($id)->exists()) {
            return (int) $id;
        }

        return Business::count() === 1 ? (int) Business::value('id') : null;
    }

    public static function name(): ?string
    {
        $id = self::businessId();

        return $id ? Business::whereKey($id)->value('name') : null;
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
