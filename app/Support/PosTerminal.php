<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PosDevice;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * الطرفيّة: هذا المتصفّح، وأيّ جهازٍ مفعَّل هو.
 *
 * الجهاز يعرف المتجر والفرع، والموظف يعرف رمزه وحده. هذا هو المبدأ كلّه.
 *
 * كان الربط كوكي تحمل رقم المتجر لا غير: بلا فرع، بلا سجلّ، بلا إبطال. فجهاز
 * الخوير وجهاز السيب متطابقان، والفرع يأتي من جلسة المتصفّح — أي من مبدّل
 * الفروع في لوحة الإدارة. وجهازٌ يُسرق أو يُباع يبقى صالحًا إلى الأبد لأن لا
 * شيء يُلغى.
 *
 * الآن: سجلٌّ في pos_devices، ورمزٌ عشوائي ٢٥٦ بت في كوكي موقَّعة، ومجزَّأ
 * بـSHA-256 في القاعدة. والتجزئة هنا سريعة عمدًا — بحثٌ مفهرس في كل طلب، ورمز
 * بهذه العشوائية لا يُخمَّن أصلًا (وهو ما تفعله Sanctum). أما رمز الموظف فيبقى
 * على bcrypt: أربعة أرقام فضاؤها ١٠٠٠٠ احتمال، فيلزمها هاشٌ بطيء.
 */
class PosTerminal
{
    /** الكوكي الجديدة: «معرّف الجهاز|الرمز الخام» */
    public const COOKIE = 'pos_device';

    /**
     * الكوكي القديمة — رقم المتجر وحده.
     *
     * تبقى مقروءة ولا تُكتب: الأجهزة المركّبة عند التجّار تحملها اليوم، وحذفُ
     * قراءتها كان سيقف بكل صندوقٍ قائم على شاشة تفعيل صباح النشر.
     */
    public const LEGACY_COOKIE = 'pos_business';

    /** سنتان: عمر جهاز الصندوق أطول من أي جلسة */
    private const DAYS = 730;

    /* ------------------------------ التفعيل ------------------------------ */

    /**
     * يفعّل هذا المتصفّح كجهازٍ على فرعٍ بعينه.
     *
     * الرمز الخام يُرجَع مرّةً واحدة ليوضع في الكوكي، ولا يُخزَّن ولا يُعرض:
     * ما لا يُخزَّن لا يُسرَّب من قاعدة بيانات.
     */
    public static function activate(Branch $branch, string $name, ?int $byUserId = null): PosDevice
    {
        $raw = Str::random(64);

        $device = PosDevice::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'name' => $name,
            'token_hash' => hash('sha256', $raw),
            'status' => PosDevice::ACTIVE,
            'activated_by' => $byUserId,
            'activated_at' => now(),
            'last_seen_at' => now(),
        ]);

        self::bind($device, $raw);

        return $device;
    }

    /** يكتب هوية الجهاز في كوكي موقَّعة طويلة العمر */
    public static function bind(PosDevice $device, string $rawToken): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $device->id.'|'.$rawToken, self::DAYS * 24 * 60));
    }

    /**
     * يُبطل التفعيل ويدوّر الرمز.
     *
     * تدوير الرمز لا رفع العلم وحده: جهازٌ أُلغي ثم أُعيد تفعيله على فرعٍ آخر
     * كان سيقبل رمزه القديم لو بقي مخزَّنًا — والغرض من الإلغاء أن يموت الرمز.
     */
    public static function revoke(PosDevice $device): void
    {
        $device->update([
            'status' => PosDevice::REVOKED,
            'token_hash' => hash('sha256', Str::random(64)),
        ]);
    }

    /** يفك ارتباط هذا المتصفّح بالجهاز (لا يمسّ السجلّ) */
    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
        Cookie::queue(Cookie::forget(self::LEGACY_COOKIE));
    }

    /* ------------------------------ القراءة ------------------------------ */

    /**
     * الجهاز المفعَّل على هذا المتصفّح، أو null.
     *
     * المقارنة hash_equals لا `==`: مقارنة النصوص تنتهي عند أول حرفٍ مختلف،
     * فزمنُها يفشي كم حرفًا صحّ من الرمز.
     */
    public static function current(): ?PosDevice
    {
        $raw = (string) Request::cookie(self::COOKIE);
        if (! str_contains($raw, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $raw, 2);

        $device = PosDevice::find((int) $id);
        if (! $device || ! $device->isActive()) {
            return null;
        }

        return hash_equals($device->token_hash, hash('sha256', $token)) ? $device : null;
    }

    /** هل هذا المتصفّح مفعَّل كجهاز؟ */
    public static function activated(): bool
    {
        return self::current() !== null;
    }

    /**
     * متجر هذا الجهاز — من الجهاز وحده، ولا يُخمَّن.
     *
     * مصدران بالترتيب: الجهاز المفعَّل، ثم الكوكي القديمة (للأجهزة المركَّبة
     * قبل هذا التغيير). وكلتاهما مشفَّرةٌ في المتصفّح فلا تُزوَّر.
     *
     * وكان ثالثٌ: «إن كان في المنصّة متجرٌ واحد فهو هو». وحجّتُه صحيحةٌ في
     * محورها — تركيبةٌ مفردة لا يُخلط فيها بأحد — لكنّها تُجيب عن الخلط بين
     * المتاجر لا عن الباب نفسه: يصير باب الرمز مفتوحًا لأيّ متصفّحٍ في العالم
     * بلا جهازٍ ولا تفعيل، ولا يبقى بين المتجر وبين من يجرّب إلا أربعةُ
     * أرقام — عشرةُ آلاف احتمال، وحدُّ المحاولات يُحسب لكلّ عنوانٍ على حدة
     * فيُقسَّم على العناوين.
     *
     * وثمنُ إغلاقه لا شيء: النافذة الوحيدة التي كان يخدمها هي ما قبل تفعيل
     * أوّل جهاز — وفيها يدخل صاحب المتجر ببريده وكلمة مرّه ليفعّله. فلا
     * كاشير يقف أمام شاشةٍ مقفلة.
     */
    public static function businessId(): ?int
    {
        if ($device = self::current()) {
            return $device->business_id;
        }

        $legacy = Request::cookie(self::LEGACY_COOKIE);
        if ($legacy && Business::whereKey($legacy)->exists()) {
            return (int) $legacy;
        }

        return null;
    }

    /**
     * هل سبق أن دخل أحدٌ من هذا المتصفّح؟
     *
     * غير `businessId()` عمدًا: تلك تسقط على المتجر الوحيد في القاعدة إن لم
     * تجد كوكي — تسهيلًا للتاجر المفرد. وهذا الحكم لا يصلح لشاشة الدخول:
     * لو بُني عليه لظهر تبويب «رمز الموظف» على أي متصفّح في العالم يفتح
     * الرابط، قبل أن يُعرَف الجهاز أصلًا.
     *
     * هنا نسأل عن أثرٍ حقيقي لهذا الجهاز وحده: تفعيلٌ قائم، أو كوكي المتجر
     * التي تُكتب عند أول دخولٍ ببريد وكلمة مرور.
     */
    public static function remembered(): bool
    {
        if (self::current()) {
            return true;
        }

        $legacy = Request::cookie(self::LEGACY_COOKIE);

        return $legacy !== null && Business::whereKey($legacy)->exists();
    }

    /** فرع هذا الجهاز — null إن لم يكن مفعَّلًا */
    public static function branchId(): ?int
    {
        return self::current()?->branch_id;
    }

    public static function businessName(): ?string
    {
        $id = self::businessId();

        return $id ? Business::whereKey($id)->value('name') : null;
    }

    /** يُسجّل أن الجهاز حيّ — يُستدعى عند دخول موظف */
    public static function touch(PosDevice $device): void
    {
        $device->forceFill(['last_seen_at' => now()])->save();
    }

    /**
     * الكوكي القديمة، للتوافق.
     *
     * الدخول ببريد وكلمة مرور على جهازٍ غير مفعَّل يربطه بمتجره كما كان، حتى
     * يفعّله مديرٌ بفرعٍ محدَّد. بلا هذا يفقد كل صندوقٍ قائم رمزَه فجأة.
     */
    public static function rememberBusiness(?int $businessId): void
    {
        if (! $businessId || self::activated()) {
            return;
        }

        Cookie::queue(Cookie::make(self::LEGACY_COOKIE, (string) $businessId, self::DAYS * 24 * 60));
    }
}
