<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * ما يُعرض في متجر التاجر على الإنترنت — مجموعًا في موضعٍ واحد.
 *
 * الشاشةُ التي يضبط فيها التاجر موقعه والصفحةُ التي يراها زبونه شيئان يجب
 * أن يقولا الشيء نفسه. ولو قرأ كلٌّ منهما المفاتيح بنفسه لَافترقا بهدوء:
 * مفتاحٌ يُقرأ في الشاشة ولا يُقرأ في الصفحة يصير مقبضًا لا يفعل شيئًا،
 * وهو العطب الذي جعل هذه المفاتيح كلَّها تُرفع من قبل.
 *
 * فمن هنا تُقرأ، ومن هنا وحدها: المعاينة في اللوحة والصفحة العامّة تنادِيان
 * `view()` نفسها، فما يراه التاجر هو ما يراه زبونه بالبناء لا بالانتباه.
 *
 * ولا شيء هنا يمرّ عبر `Demo`: تلك تقرأ المتجر من الجلسة، وزائرُ المتجر لا
 * جلسة له ولا حساب. فالمتجر يُمرَّر صراحةً في كلّ نداء.
 */
class Storefront
{
    /** أشكال عرض الأصناف — الشبكة صورٌ، والقائمة سطورٌ لمن يبيع بالوصف */
    public const LAYOUTS = ['grid', 'list'];

    /**
     * هل هذا المتجر مفتوحٌ للزوّار الآن؟
     *
     * ثلاثة شروط لا واحد: أن ينشره صاحبه، وألّا يكون النشاط معطَّلًا، وألّا
     * يكون اشتراكه منتهيًا بعد المهلة. والأخيران ليسا تشدّدًا: متجرٌ يتوقّف
     * عن الدفع ويبقى موقعه يستقبل الطلبات يعني طلباتٍ لا يراها أحد، وزبونًا
     * ينتظر بضاعةً لن تأتي.
     */
    public static function isOpen(Business $business): bool
    {
        if (trim((string) (self::settings($business)['site_published'] ?? '0')) !== '1') {
            return false;
        }

        if (in_array((string) $business->status, ['معطل', 'معطّل'], true)) {
            return false;
        }

        return ! Tenancy::locked($business);
    }

    /**
     * إعدادات موقع هذا المتجر.
     *
     * وبلا ذاكرةٍ عمدًا رغم أنّها تُنادى مرّتين في الطلب الواحد: ذاكرةٌ
     * ساكنة تبقى بين طلبين في العملية الواحدة — تحت Octane، أو في عاملِ
     * طابورٍ يعالج متجرين بالتتابع — فيُخدَم متجرٌ بإعدادات متجرٍ سبقه.
     * واستعلامان لصفوفٍ متجاورة أرخص من ذلك بكثير.
     */
    public static function settings(Business $business): array
    {
        return MarketingSettings::group($business->id, 'website');
    }

    /**
     * كلّ ما تحتاجه صفحة المتجر — بلا استعلامٍ ثانٍ من القالب.
     *
     * @return array{business: Business, site: array<string,string>, theme: array<string,string>, products: LengthAwarePaginator, categories: Collection, currency: array}
     */
    public static function view(Business $business, ?int $categoryId = null, string $search = ''): array
    {
        $site = self::settings($business);
        $products = self::products($business, $categoryId, $search);

        return [
            'business' => $business,
            'site' => $site,
            'theme' => self::theme($site),
            'products' => $products,
            'categories' => self::categories($business),
            'currency' => self::currency($business),
            'showPrices' => ($site['site_show_prices'] ?? '1') === '1',
            'allowOrders' => ($site['site_allow_orders'] ?? '0') === '1',
            'whatsapp' => self::whatsapp($site),
            'logo' => self::asset($business->logo),
            'cover' => self::asset($site['site_cover'] ?? ''),
        ];
    }

    /**
     * رابط ملفٍّ مرفوع — أو null إن لم يُرفع.
     *
     * الشعار والغلاف يُحفظان مسارًا على قرص `public`، وقد يُكتب أحدهما
     * رابطًا كاملًا (بيانات المنصّة القديمة). والحالتان تُقرآن هنا معًا كما
     * تُقرآن في لوحة المنصّة — انظر SuperAdmin\PageController::logoUrl.
     */
    private static function asset(?string $path): ?string
    {
        return \App\Http\Controllers\SuperAdmin\PageController::logoUrl(
            trim((string) $path) !== '' ? $path : null
        );
    }

    /** كم صنفًا في الصفحة الواحدة — قبل «التالي» */
    public const PER_PAGE = 24;

    /**
     * الأصناف المعروضة — المنشورة وحدها، ومن هذا المتجر وحده.
     *
     * `published` تُسأل دائمًا ولو كان المتجر فارغًا: شرطٌ يُنسى في استعلامٍ
     * واحد يكشف الجرد كلَّه، وذلك عطبٌ لا يظهر في شاشةٍ بل عند زبون.
     *
     * وتُصفَّح لا تُجلب كلّها: متجرٌ بألفي صنفٍ منشور يبني صفحةً بألفي بطاقة
     * وألفي صورة — تُفتح على هاتفٍ في شبكةٍ بطيئة فلا تُفتح.
     */
    public static function products(Business $business, ?int $categoryId = null, string $search = ''): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('business_id', $business->id)
            ->where('published', true)
            ->where('active', true)
            // المقاسات معه: سعرُ ذي المقاسات لا يُقرأ من عموده — انظر prices()
            ->with(['category', 'variants' => fn ($q) => $q->where('active', true)]);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $search = trim($search);
        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('name_en', 'like', '%'.$search.'%'));
        }

        return $query->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * الأقسام التي فيها صنفٌ معروض — لا كلُّ أقسام الجرد.
     *
     * قسمٌ فارغ في شريط التصفية يقود الزائر إلى صفحةٍ لا شيء فيها، فيظنّ
     * أنّ المتجر معطوب.
     */
    public static function categories(Business $business): Collection
    {
        $used = Product::where('business_id', $business->id)
            ->where('published', true)->where('active', true)
            ->whereNotNull('category_id')
            ->distinct()->pluck('category_id');

        return $used->isEmpty()
            ? collect()
            : Category::where('business_id', $business->id)->whereIn('id', $used)->orderBy('name')->get();
    }

    /**
     * ألوان الصفحة مشتقّةً من لونٍ واحد اختاره التاجر.
     *
     * والاشتقاق هنا لا في القالب: لونٌ فاتح يجعل النصَّ الأبيض فوقه غير
     * مقروء، فيُختار الأسود أو الأبيض بحسب إضاءة اللون لا بحسب الذوق.
     */
    public static function theme(array $site): array
    {
        $accent = trim((string) ($site['site_theme'] ?? ''));
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#111827';
        }

        return [
            'accent' => $accent,
            'on_accent' => self::isLight($accent) ? '#111827' : '#ffffff',
            'soft' => $accent.'14', // نفس اللون بشفافية ٨٪ — للخلفيات الهادئة
        ];
    }

    /** إضاءة اللون بمعادلة الإدراك — لا بمتوسّط القنوات الثلاث */
    private static function isLight(string $hex): bool
    {
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150;
    }

    /**
     * رقم واتساب صالحًا للرابط — أرقامًا فقط.
     *
     * التاجر يكتبه بمسافاتٍ وشرطاتٍ و«+»، و`wa.me` لا يقبل إلا الأرقام.
     * وتنظيفُه هنا لا في القالب: القالب يظهر في موضعين والتنظيف واحد.
     */
    public static function whatsapp(array $site): string
    {
        return preg_replace('/\D+/', '', (string) ($site['site_whatsapp'] ?? '')) ?? '';
    }

    /**
     * عملة المتجر — مقروءةً من المتجر لا من الجلسة.
     *
     * `Demo::baseCurrency` تقرأ متجر المستخدم الحالي، وزائرُ المتجر لا
     * مستخدمَ له. فلو استُعملت هنا لَعُرضت أسعارُ كلّ المتاجر بعملة آخر
     * تاجرٍ دخل — أو بالافتراضيّة، وهي عملة أحدهم لا عملة الجميع.
     */
    public static function currency(Business $business): array
    {
        $c = Currency::where('business_id', $business->id)->where('is_base', true)->first();

        if ($c) {
            return ['code' => $c->code, 'symbol' => $c->symbol ?: $c->code];
        }

        $code = strtoupper(trim((string) (
            \App\Models\Setting::where('business_id', $business->id)->where('key', 'currency')->value('value') ?? ''
        )));
        $code = preg_match('/^[A-Z]{3}$/', $code) ? $code : 'OMR';

        return ['code' => $code, 'symbol' => Demo::SYMBOLS[$code] ?? $code];
    }

    /**
     * صورة الصنف — أو null إن لم يرفع التاجر واحدة.
     *
     * والفرق بينها وبين `$product->image` أنّ تلك لا تردّ فارغًا أبدًا: صنفٌ
     * بلا صورةٍ مرفوعة يُكتب في عموده رابطُ `picsum.photos` عند الإنشاء —
     * صورةٌ عشوائية من الإنترنت. وهي داخل اللوحة حشوٌ لا يضرّ، أمّا على صفحةٍ
     * يفتحها زبون فهي صورةُ منتجٍ ليست منتجَه: يطلب ما رأى فيصله غيرُه.
     *
     * فتُردّ null هنا، ويرسم القالب مكانَها لوحًا هادئًا بحرف الاسم. ومربّعٌ
     * فارغ يقول «لا صورة» أصدق من صورةٍ تقول شيئًا آخر.
     */
    public static function image(Product $product): ?string
    {
        $raw = (string) $product->getRawOriginal('image');

        if (trim($raw) === '' || str_contains($raw, 'picsum.photos')) {
            return null;
        }

        return $product->image;
    }

    /**
     * سعرُ الصنف كما يُقرأ على الصفحة — ومداه إن كان له مقاسات.
     *
     * ذو المقاسات لا يُباع بنفسه: السعر يأتي من المقاس المختار، وعمودُ
     * `price` فيه رقمٌ لا يدفعه أحد. فعرضُه على الزبون سعرٌ مكذوب — يرى
     * «١٢٫٥٠٠» ويطلب فيُقال له «الوسط بثمانية عشر».
     *
     * فيُعرض المدى: «من ١٢٫٥٠٠» حين تختلف الأسعار، والسعر وحده حين تتّفق أو
     * حين لا مقاس. والقائمة كاملةً في صفحة الصنف.
     *
     * @return array{from: float, to: float, variable: bool}
     */
    public static function price(Product $product): array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->where('active', true)->get();

        $prices = $variants->pluck('price')->map(fn ($p) => (float) $p)->all();

        if ($prices === []) {
            $single = $product->sellingPrice();

            return ['from' => $single, 'to' => $single, 'variable' => false];
        }

        return [
            'from' => min($prices),
            'to' => max($prices),
            'variable' => min($prices) !== max($prices),
        ];
    }

    /** المبلغ مكتوبًا بعملة المتجر — ثلاث خاناتٍ للريال العماني وأخواته */
    public static function money(float $value, array $currency): string
    {
        $decimals = in_array($currency['code'], ['OMR', 'KWD', 'BHD'], true) ? 3 : 2;

        return number_format($value, $decimals).' '.$currency['symbol'];
    }
}
