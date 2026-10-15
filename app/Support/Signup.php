<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * التسجيلُ الذاتيّ — تاجرٌ يفتح متجرَه بنفسه، بلا مشغّلٍ يُنشئه له.
 *
 * ═══ ولمَ صنفٌ لا متحكّم ═══
 *
 * إنشاءُ المستأجر ثلاثةُ أفعالٍ لا تقبل الانفصال: متجرٌ، ومالكٌ له، وتصنيفاتٌ
 * تُبذَر فلا يفتح لوحتَه على صفحةٍ بيضاء. ومتجرٌ بلا مالك بابٌ لا يُفتح أبدًا
 * ولا يعرف أحدٌ به، ومالكٌ بلا متجرٍ حسابٌ يدخل إلى لا شيء.
 *
 * فيقع الثلاثةُ في معاملةٍ واحدة، وتُقرأ من موضعٍ واحدٍ يختبره اختبار — لا
 * موزَّعةً في متحكّمٍ يصعب تشغيلُه بلا طلبِ HTTP.
 *
 * ═══ وهو الطريقُ نفسُه الذي تسلكه المنصّة — إلّا في الباقة ═══
 *
 * `SuperAdmin\BusinessController::store` يُنشئ بالأفعال نفسِها: `MerchantAccount`
 * ثمّ `BusinessTypes::provision`. فلا مسارَ ثانٍ لإنشاء المستأجرين يفترق عن
 * الأوّل عند أوّل حقلٍ يُضاف.
 *
 * ويفترقان في الباقة وحدها، عمدًا: المشغّلُ يختار لمن يُضيفه باقتَه ومدّتَه
 * (وافتراضيُّهما من إعدادات المنصّة)، والمسجِّلُ بنفسه عقدُه ثابت — الأساسيّةُ
 * وأربعة عشر يومًا — لا يتبدّل بتغيير `default_plan` أو `trial_days`. انظر
 * `subscription()`.
 */
class Signup
{
    /** مفتاحُ فتح الباب في إعدادات المنصّة */
    public const SWITCH = 'self_signup';

    /**
     * أيّامُ تجربة المسجِّل بنفسه — عقدٌ لا إعداد.
     *
     * وهي تُقرأ كما يقرأ `Tenancy` كلَّ تجربة: `ends_at` بعد أربعة عشر يومًا
     * من يوم التسجيل، ويبقى يومُ الانتهاء نفسُه مفتوحًا (`Tenancy::expired`).
     */
    public const TRIAL_DAYS = 14;

    /** مفتاحُ حجم الفريق في إعدادات المتجر */
    public const TEAM_SIZE = 'team_size';

    /**
     * أحجامُ الفريق — قائمةٌ مغلقة لا نصٌّ حرّ.
     *
     * نصٌّ حرٌّ يخرج «٣» و«ثلاثة» و«3-5» في عمودٍ واحد فلا يُجمع عليه شيء.
     * وهي لا تُقرأ في المنتج بعد: تُحفظ إعدادًا يُسأل عنه يومًا — انظر
     * `store()` أدناه.
     */
    public const TEAM_SIZES = ['أنا فقط', '2–5 موظفين', '6–10 موظفين', 'أكثر من 10 موظفين'];

    /**
     * هل البابُ مفتوح؟
     *
     * ومفتوحٌ افتراضًا: نظامٌ يُسوَّق للتسجيل الذاتيّ ولا يُسجَّل فيه أحدٌ
     * بلا مشغّل. ومن أراد إقفالَه كتب `self_signup = 0` في إعدادات المنصّة،
     * فتختفي الصفحةُ ورابطُها معًا.
     */
    public static function open(): bool
    {
        /*
         * ويُقرأ داخل حارس — وهذا أسقطه اختبارٌ قائم.
         *
         * شاشةُ الدخول تسأل عن حال هذا الباب لتعرض رابطَه أو تخفيه، وهي أوّلُ
         * صفحةٍ تُفتح على تنصيبٍ جديد **قبل أن تُهاجَر القاعدة** — ولا جدولَ
         * `settings` بعد. واستعلامٌ يسقط هناك يجعل أوّلَ ما يراه المنصِّب
         * صفحةَ خمسمئة لا صفحةَ دخول.
         *
         * وهو الحارسُ نفسُه الذي يلفّ قراءةَ اللغة في `SetLocale` وللسبب
         * نفسِه. ولا يُبتلع به خطأٌ حقيقيّ: قاعدةٌ بلا جدول إعداداتٍ يفضحها
         * كلُّ ما سواها.
         *
         * والافتراضُ عند السقوط **مفتوح**: بابٌ يُقفل بإعدادٍ لا يُقرأ يصير
         * مقفلًا بالصدفة، فيُردّ من يسجّل بـ٤٠٤ لا يفهمها أحد.
         */
        try {
            return (string) Tenancy::platform(self::SWITCH, '1') !== '0';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * إنشاءُ المتجر ومالكِه وتصنيفاتِه — كلُّه أو لا شيء.
     *
     * @param  array{name: string, phone: string, type: string, shop: string, team_size?: ?string, address?: ?string, email: string, password: string}  $data
     */
    public static function register(array $data): User
    {
        // الباقةُ تُطلب قبل المعاملة: لا باقةَ أساسيّةً مؤكَّدة ← لا شيءَ يُكتب أصلًا
        $plan = self::plan();

        return DB::transaction(function () use ($data, $plan) {
            $business = Business::create([
                'name' => trim($data['shop']),
                'type' => $data['type'],
                'owner_name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'email' => mb_strtolower(trim($data['email'])),
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
                'status' => 'نشط',
                // الخادمُ يختم البابَ — لا حقلَ في الطلب يكتبه
                'registration_source' => Business::SOURCE_SELF_SIGNUP,
            ] + self::subscription($plan));

            $owner = MerchantAccount::provision(
                $business,
                $data['email'],
                $data['password'],
                trim($data['name']),
            );

            // تصنيفاتُ البداية بحسب النوع — لئلا يفتح لوحتَه على صفحةٍ بيضاء
            BusinessTypes::provision($business);

            /*
             * وحجمُ الفريق إعدادٌ لا عمود.
             *
             * ═══ ولمَ لا هجرة ═══
             *
             * لا يقرؤه شيءٌ في المنتج اليوم: لا يُغيّر باقةً ولا صلاحيةً ولا
             * شاشة. وعمودٌ في `businesses` لحقلٍ لا يقرؤه شيءٌ دَينٌ على
             * الجدول — يُهاجَر ويُفهرَس ويُقرأ في كلّ استعلامٍ بلا مقابل.
             *
             * و`settings` جدولُ مفاتيحَ لكلّ متجر، يقبله بلا تغييرِ بنية.
             * فإن صار له استعمالٌ يومًا — اقتراحُ باقةٍ أو تهيئةُ فروع —
             * كان محفوظًا من أوّل يوم، ونقلُه إلى عمودٍ حينها هجرةٌ واحدة.
             */
            if (filled($data['team_size'] ?? null)) {
                Setting::updateOrCreate(
                    ['business_id' => $business->id, 'key' => self::TEAM_SIZE],
                    ['value' => (string) $data['team_size']],
                );
            }

            Activity::log('created', 'سجّل متجرًا جديدًا: '.$business->name, [
                'business_id' => $business->id,
                'subject_id' => $business->id,
            ]);

            return $owner;
        });
    }

    /**
     * باقةُ المسجِّل بنفسه — الأساسيّةُ برمزها، أو لا تسجيل.
     *
     * ═══ ولمَ لا `default_plan` ═══
     *
     * كان يُقرأ إعدادُ المنصّة بالاسم — وهو فارغٌ افتراضًا، فيُفتح المتجرُ بلا
     * باقة؛ وإن ضُبط على «الاحترافيّة» لمن يُضيفه المشغّل صار كلُّ مسجِّلٍ
     * بنفسه احترافيًّا. والتسجيلُ الذاتيّ لا يبيع إلّا الأساسيّة.
     *
     * ═══ ولا بديل ═══
     *
     * لا باقةَ برمز `basic` (انظر هجرة `a_plan_has_a_code…`) ← يُرفض التسجيلُ
     * برسالةٍ صريحة، ولا يُختار أرخصُ سعرٍ ولا أوّلُ رقمٍ ولا اسمٌ يشبهها:
     * متجرٌ على باقةٍ خاطئة يفتح لصاحبه ما لم يُبَع له، ولا يلاحظه أحد.
     */
    public static function plan(): Plan
    {
        return Plan::where('code', Plan::CODE_BASIC)->first()
            ?? throw new SelfSignupPlanMissing;
    }

    /**
     * الباقةُ ومدّةُ التجربة — عقدُ التسجيل الذاتيّ لا إعداداتُ المنصّة.
     *
     * ومتجرٌ بلا تاريخ انتهاءٍ يعمل إلى الأبد: لا تجربةَ تنتهي ولا مطالبةَ
     * تحلّ. ومتجرٌ بلا باقةٍ يبقى بلا سعرٍ ولا فاتورة.
     *
     * @return array<string, mixed>
     */
    private static function subscription(Plan $plan): array
    {
        $starts = Carbon::now();

        return [
            'plan_id' => $plan->id,
            'starts_at' => $starts->toDateString(),
            'ends_at' => $starts->copy()->addDays(self::TRIAL_DAYS)->toDateString(),
        ];
    }
}
