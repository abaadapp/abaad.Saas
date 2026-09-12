<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * هويّةُ ورقة العميل — الشعارُ والاسمُ واللغةُ والذيل.
 *
 * ═══ ولمَ صنفٌ مستقلّ ═══
 *
 * صاحبُ المحلّ يريد ورقتَه باسمه وشعاره. والاسمُ القانونيُّ في `businesses`
 * ليس دائمًا ما يريده على فاتورته: «أبعاد للورود ش.م.م» في السجلّ، و«أبعاد
 * للورود» على الورقة. وتغييرُ الاسم القانونيّ ليُجمَّل الطبع يُغيّره في
 * العقود والاشتراك والفاتورة الضريبية معًا — فيُفصَل الاسمُ المعروض عن
 * الاسم المسجَّل، ولا يُمسّ الثاني.
 *
 * وواحدٌ يقرأ للاثنين: المعاينةُ في الشاشة والـPDF المطبوع. حقلان يقولان
 * اسمَ المتجر يفترقان يومًا، فتقول المعاينةُ اسمًا وتخرج الطابعةُ بغيره.
 *
 * ═══ ولا عنوانَ مبنًى هنا بحال ═══
 *
 * `paper()` تبني ما تقرؤه الترويسة، ولا مفتاحَ `address` فيها — لا مطفأً
 * ولا فارغًا: مفتاحٌ موجودٌ بقيمةٍ فارغة يُملأ يومًا بسطرٍ في مكانٍ آخر.
 * وهو شرطُ صاحب النظام: العنوانُ لا يُطبع على ورقة العميل. ويحرسه
 * `TheInvoiceCarriesTheShopsIdentityTest`.
 *
 * وهي ورقةُ العميل وحدَها: سندُ التسليم يحمل عنوانًا لأنّه يُوصِّل إليه،
 * فلا تُنزع منه القاعدةُ التي تخصّ الفاتورة.
 */
final class InvoiceBranding
{
    /** اسمُ المتجر كما يُطبع — وإن فرغ فالاسمُ المسجَّل */
    public const NAME = 'invoice_display_name';

    /** لغةُ الورقة المطبوعة — لا لغةُ اللوحة */
    public const LANGUAGE = 'invoice_language';

    /*
     * ولا تذييلَ هنا ولا سطرَ ترويسة — هما في «قوالب الأوراق».
     *
     * كانا مفتاحين من عند هذا الصنف، ولأخوات الورقة الأربع مثلُهما في
     * `DocumentTemplates`. وشيءٌ واحد بمفتاحين في شاشتين يجعل صاحبَه يكتب
     * في أحدهما ويبحث عن أثره في الآخر. فبقي مالكٌ واحد، ولم يبقَ هنا
     * إلّا ما لا نظيرَ له هناك: الاسمُ المعروض، ولغةُ الطبع، وملفُّ الشعار.
     */

    /** العميلُ الذي تُفتح عليه شاشةُ الإنشاء */
    public const DEFAULT_CUSTOMER = 'default_customer_id';

    /** @var list<string> */
    public const LANGUAGES = ['ar', 'en'];

    /**
     * ما يُضمَّن في الورقة نفسها من الشعار — وما فوقه يُوصَل برابط.
     *
     * والتضمينُ لأنّ الورقةَ تُقرأ في موضعين لا يشتركان في أصل: إطارٌ
     * معزول (`sandbox=""`) في الشاشة، ومحرّكُ mpdf الذي لا جلسةَ له ولا
     * كعكة. ورابطٌ نسبيٌّ يعمل في أحدهما ويسقط في الآخر بلا صوت — فيرى
     * التاجرُ شعارَه في المعاينة ويخرج الورقُ بلا شعار.
     *
     * وهو **بقدر ما يُقبل رفعُه** (٢ ميغابايت) لا أقلّ: حدٌّ أضيقُ من حدّ
     * الرفع يعني شعارًا مقبولًا في الرفع يسقط في الطبع — يُرى في الشاشة
     * ويغيب عن الورق، وهو أسوأ من رفضه عند الرفع.
     */
    private const INLINE_MAX = 2097152;

    /** ما يُخزَّن ويُعرض في نافذة «تخصيص التصميم» — القيمُ الخام لا المشتقّة */
    public static function settings(int $businessId): array
    {
        $rows = Setting::where('business_id', $businessId)
            ->whereIn('key', [self::NAME, self::LANGUAGE, self::DEFAULT_CUSTOMER])
            ->pluck('value', 'key');

        return [
            'display_name' => (string) ($rows[self::NAME] ?? ''),
            'language' => self::language($businessId),
            /*
             * وشعارُ **الشاشة** رابطٌ لا صورةٌ مضمَّنة.
             *
             * `logo()` تُضمّن الصورةَ لأنّ الورقةَ تُقرأ في إطارٍ معزولٍ
             * وفي mpdf. ونافذةُ التخصيص عنصرُ DOM عاديّ يقرأ الروابط —
             * وحملُ ميغابايتين مرمَّزين في حمولة كلّ فتحةٍ للشاشة ثمنٌ
             * يُدفع في كلّ مرّة مقابل صورةٍ بحجم إبهام.
             */
            'logo' => self::logoUrl($businessId),
            'default_customer_id' => self::defaultCustomerId($businessId),
            /* والاسمُ المسجَّل يُعرض ليُعرف ما يحلّ محلّه المعروضُ حين يُترك فارغًا */
            'legal_name' => (string) (Business::whereKey($businessId)->value('name') ?? ''),
        ];
    }

    /** الاسمُ المطبوع — المعروضُ إن ضُبط، وإلّا المسجَّل */
    public static function name(int $businessId): string
    {
        $display = trim((string) Setting::where('business_id', $businessId)
            ->where('key', self::NAME)->value('value'));

        if ($display !== '') {
            return $display;
        }

        return trim((string) (Business::whereKey($businessId)->value('name') ?? ''));
    }

    /**
     * الشعارُ صالحًا للرسم في الموضعين.
     *
     * والعمودُ يُقرأ خامًا لا عبر النموذج: `Business::logo` مُلحَقٌ به قارئٌ
     * يردّ `Storage::url` — رابطًا لا مسارًا — فلا يُفتح به ملفٌّ ليُضمَّن.
     */
    public static function logo(int $businessId): ?string
    {
        $raw = DB::table('businesses')->where('id', $businessId)->value('logo');
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'http') || str_starts_with($raw, 'data:')) {
            return $raw;
        }

        $disk = Storage::disk('public');

        try {
            if ($disk->exists($raw) && $disk->size($raw) <= self::INLINE_MAX) {
                return 'data:'.($disk->mimeType($raw) ?: 'image/png')
                    .';base64,'.base64_encode($disk->get($raw));
            }
        } catch (\Throwable) {
            /* قرصٌ لا يُقرأ: يُوصَل برابطٍ ولا تسقط الورقة كلُّها لأجل شعار */
        }

        return url($disk->url($raw));
    }

    /**
     * الشعارُ رابطًا — للشاشة وحدها.
     *
     * ولا يُقرأ منه الطبعُ ولا المعاينة: انظر `logo()`.
     */
    public static function logoUrl(int $businessId): ?string
    {
        $raw = DB::table('businesses')->where('id', $businessId)->value('logo');
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            return null;
        }

        return str_starts_with($raw, 'http') || str_starts_with($raw, 'data:')
            ? $raw
            : Storage::disk('public')->url($raw);
    }

    /** لغةُ الطبع — والافتراضُ لغةُ اللوحة لا لغةٌ مكتوبةٌ هنا */
    public static function language(int $businessId): string
    {
        $saved = (string) Setting::where('business_id', $businessId)
            ->where('key', self::LANGUAGE)->value('value');

        return in_array($saved, self::LANGUAGES, true) ? $saved : app()->getLocale();
    }

    /**
     * العميلُ الافتراضيّ — ويُتحقّق منه عند كلّ قراءة.
     *
     * عميلٌ حُذف أو نُقل يترك في الإعدادات رقمًا لا صفَّ له. وشاشةٌ تفتح
     * على عميلٍ لا وجود له تعرض حقلًا مختارًا بلا اسم، ويُردّ الحفظُ بـ«ليس
     * من عملاء متجرك» عن اختيارٍ لم يختره أحد. فيُقرأ الصفُّ أو يُهمَل الرقم.
     */
    public static function defaultCustomerId(int $businessId): ?int
    {
        $id = Setting::where('business_id', $businessId)
            ->where('key', self::DEFAULT_CUSTOMER)->value('value');

        if (blank($id)) {
            return null;
        }

        $exists = Customer::where('business_id', $businessId)->whereKey($id)->exists();

        return $exists ? (int) $id : null;
    }

    /**
     * ما تقرؤه ترويسةُ الورقة — و`Paper::brand` تبنيه.
     *
     * ولا `address` فيها: انظر رأسَ الملفّ.
     *
     * @return array<string, mixed>
     */
    public static function paper(int $businessId): array
    {
        $row = DB::table('businesses')->where('id', $businessId)
            ->first(['type', 'city', 'phone', 'email']);

        return [
            'name' => self::name($businessId),
            'logo' => self::logo($businessId),
            'type' => $row->type ?? '',
            'city' => $row->city ?? '',
            'phone' => $row->phone ?? '',
            'email' => $row->email ?? '',
        ];
    }

    /**
     * الرسمُ بلغة الورقة — لا بلغة من ضغط الزرّ.
     *
     * ═══ ولمَ هنا لا في كلّ متحكّم ═══
     *
     * الورقةُ تُرسم من بابين: معاينةُ الشاشة، وزرُّ الطباعة. ولو ضبط كلٌّ
     * منهما اللغةَ بنفسه لافترقا عند أوّل سهو — فيعاين التاجرُ ورقةً عربيّة
     * ويخرج الورقُ إنجليزيًّا، أو العكس. وهو الصنفُ الذي لا يُكتشف إلّا بعد
     * أن تصل الورقةُ إلى العميل.
     *
     * واللغةُ تُعاد إلى ما كانت مهما وقع: `finally` لا سطرٌ بعد النداء.
     * ورسمٌ يرمي كان يترك الجلسةَ كلَّها بلغةٍ لم يخترها صاحبُها — فيرى
     * اللوحةَ إنجليزيّةً بعد أن ضغط «معاينة».
     *
     * ═══ وبخاناتٍ غربيّة، بلغةٍ كانت أو بأخرى ═══
     *
     * ورقةُ العميل تُبنى في `CustomerInvoiceController::paper` وتُرسم من
     * أربعة أبواب، كلُّها تمرّ من هنا. فتحويلُ الخانات هنا يبلغها جميعًا،
     * ولا يُنسى في بابٍ رابعٍ يُضاف غدًا. وأخواتُها الأربع لها مخرجُها
     * الواحد — `DocumentRenderer::html`.
     *
     * ويقع على النصّ المرسوم لا على البيان: لا خانةَ عربيّةً في وسمٍ ولا في
     * `data:`، فما يُحوَّل نصُّ البشر وحدَه. وما ليس نصًّا يمرّ كما هو —
     * فهذا البابُ يردّ ما يُعطاه أيًّا كان نوعُه. انظر `Support\Digits`.
     *
     * @template T
     *
     * @param  callable():T  $draw
     * @return T
     */
    public static function render(int $businessId, ?string $language, callable $draw): mixed
    {
        $lang = in_array($language, self::LANGUAGES, true)
            ? $language
            : self::language($businessId);

        $was = app()->getLocale();
        app()->setLocale($lang);

        try {
            $drawn = $draw();

            return is_string($drawn) ? Digits::western($drawn) : $drawn;
        } finally {
            app()->setLocale($was);
        }
    }

    /**
     * حفظُ الشعار — مالكٌ واحد لكتابة العمود.
     *
     * ويُنادى من بابين: «تخصيص التصميم» في شاشة الفاتورة، و«شعار المتجر»
     * في الإعدادات. وقاعدةٌ تُكتب في البابين تفترق — فيقبل أحدُهما ما يردّه
     * الآخر، أو يُكتب في أحدهما مسارٌ بصيغةٍ لا يقرؤها الثاني.
     */
    public static function storeLogo(Business $business, ?UploadedFile $file, bool $remove = false): bool
    {
        if ($file !== null) {
            $business->logo = $file->store('logos', 'public');
        } elseif ($remove) {
            $business->logo = null;
        } else {
            return false;
        }

        $business->save();

        return true;
    }

    /**
     * حفظُ ما عدا الشعار.
     *
     * والفراغُ يُحفظ فراغًا لا يُحذف الصفّ: «امحُ الاسمَ المعروض» و«لم
     * يُضبط» يقعان على القراءة نفسها — وحذفُ الصفّ يجعل تصديرَ الإعدادات
     * يقول «لا مفتاح» عن مفتاحٍ ضبطه صاحبُه ثمّ أفرغه.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(int $businessId, array $data): void
    {
        foreach ([self::NAME => 'display_name', self::LANGUAGE => 'language'] as $key => $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            Setting::updateOrCreate(
                ['business_id' => $businessId, 'key' => $key],
                ['value' => (string) ($data[$field] ?? '')],
            );
        }
    }
}
