<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * متجرُ التاجر على الإنترنت — الصفحة التي يفتحها الزبون.
 *
 * وحدُّها يُقال أوّلًا لأنّه يحكم كلّ ما بعده: **هذه صفحةٌ عامّة بلا جلسة**.
 * لا مستخدمَ مسجَّلًا يُقرأ منه المتجر، فلا `Demo::bid()` ولا شيءٌ يعتمد على
 * `auth()` — المتجرُ يُعرف من عنوانه وحده، وكلُّ استعلامٍ هنا مقيَّدٌ بمعرّفه
 * صراحةً. وسهوٌ واحد عن ذلك يعرض منتجات متجرٍ على صفحة جاره.
 *
 * ولا تُعرض إلّا ثلاثة: ما نشره صاحبُه (`store_on`)، ومنتجاتٌ فعّالة، وسعرٌ
 * إن أذن بعرضه. وما عدا ذلك لا يخرج من هنا: التكلفة، والكميّات الحقيقية،
 * وأرقام الموظفين، وكلُّ ما يخصّ الإدارة.
 *
 * والطلبُ في هذه النسخة يقع في واتساب لا في سلّة: الزائر يضغط فتُفتح محادثةٌ
 * بالطلب مكتوبًا. وهو أقصرُ طريقٍ إلى بيعٍ حقيقيّ في محلٍّ صغير — ولا يَعِد
 * الزبونَ بسلّةٍ ودفعٍ لا وجود لهما بعد.
 */
class Storefront
{
    /** ما لا يُحجز اسمًا لمتجر — أسماءٌ للنظام نفسه أو تُلبس على الزائر */
    public const RESERVED = [
        'www', 'app', 'admin', 'api', 'mail', 'smtp', 'ftp', 'ns', 'ns1', 'ns2',
        'abaad', 'abaadapp', 'pos', 'super', 'super-admin', 'platform', 'dashboard',
        'login', 'register', 'help', 'support', 'status', 'blog', 'cdn', 'static',
        'assets', 'storage', 'webhooks', 'test', 'demo', 'staging', 'dev',
    ];

    /** أقصر اسمٍ وأطولُه — والقصيرُ جدًّا يُلبس، والطويل لا يُملى في هاتف */
    public const MIN = 3;

    public const MAX = 40;

    /**
     * اسمٌ صالحٌ للعنوان — أو null.
     *
     * حروفٌ لاتينية صغيرة وأرقامٌ وشرطة، لا تبدأ بشرطة ولا تنتهي بها. والعربيةُ
     * ممنوعة هنا وإن كان المتجر عربيًّا: النطاقات العربية تُكتب في العنوان
     * بترميز `xn--` فيراه الزبون طلاسمَ حين ينسخه، ولا تُملى في هاتف.
     */
    public static function slug(?string $input): ?string
    {
        $value = strtolower(trim((string) $input));
        $value = preg_replace('/[\s_]+/', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9-]/', '', $value) ?? '';
        $value = trim(preg_replace('/-{2,}/', '-', $value) ?? '', '-');

        if (mb_strlen($value) < self::MIN || mb_strlen($value) > self::MAX) {
            return null;
        }

        return in_array($value, self::RESERVED, true) ? null : $value;
    }

    /**
     * اقتراحُ اسمٍ من اسم المتجر — أو null.
     *
     * ولا يُخترع اسمٌ لمن اسمُ متجره عربيّ: `store-a1b2c3` عنوانٌ لا يقوله
     * أحدٌ في هاتف ولا يُكتب على بطاقة. فيُترك الحقل فارغًا ويكتبه صاحبُه
     * بنفسه — وهو أوّل قرارٍ في موقعه وأبقاه.
     */
    public static function suggest(string $name): ?string
    {
        return self::slug($name);
    }

    /**
     * نمطُ العنوان في المسار — ويستثني المحجوز صراحةً.
     *
     * والاستثناء ليس زينة: مسارُ النطاق الفرعيّ يلتقط كلَّ اسمٍ تحت النطاق،
     * ومنه `app.abaadapp.om` نفسه — فيبتلع الصفحة الرئيسية للنظام ويردّها
     * «متجرٌ غير موجود». وقعَ ذلك فعلًا، ولم يظهر إلّا في فحص الإنتاج.
     *
     * ويُبنى من `RESERVED` لا يُكتب بجانبها: قائمتان تفترقان عند أوّل إضافة.
     *
     * و«ما بعده نقطةٌ أو نهاية» لا «نهاية» وحدها: النمط يُركَّب داخل تعبير
     * المضيف كلِّه (`^(?<slug>…)\.abaadapp\.om$`)، فـ`$` فيه تعني نهاية
     * المضيف لا نهاية الاسم — وبها لا يُستثنى `app` أبدًا لأنّ بعده نقطة.
     * وهو ما جعل الاستثناءَ الأوّل لا يعمل، والصفحةُ الرئيسية تبقى مبتلعة.
     */
    public static function pattern(): string
    {
        return '(?!(?:'.implode('|', array_map('preg_quote', self::RESERVED)).')(?:\.|$))[a-z0-9][a-z0-9-]*';
    }

    /** النطاق الذي تُبنى عليه عناوين المتاجر */
    public static function domain(): string
    {
        return (string) config('storefront.domain');
    }

    /** عنوان متجرٍ كاملًا — أو null إن لم يحجز اسمًا */
    public static function url(?string $slug): ?string
    {
        return $slug ? 'https://'.$slug.'.'.self::domain() : null;
    }

    /**
     * العنوان البديل — يعمل قبل أن يُضبط النطاق بالحرف البدل.
     *
     * ويُقال بديلًا لا أصلًا: الأصلُ هو النطاق الفرعيّ، وهو ما يُكتب في
     * `canonical` وما يُعطى للزبون. وهذا بابٌ يعمل في اليوم الأول.
     */
    public static function fallbackUrl(?string $slug): ?string
    {
        return $slug ? url('/s/'.$slug) : null;
    }

    /* --------------------------- طريق العنوان --------------------------- */

    /**
     * الطرق الثلاث إلى عنوانٍ على الإنترنت — ولا رابعَ لها.
     *
     * `sub` نطاقُ أبعاد الفرعيّ (يستضيفه النظام)، و`own` نطاقٌ يملكه التاجر
     * أصلًا (يستضيفه غيرُنا ونربط إليه)، و`new` من لا نطاقَ له ويريد واحدًا.
     *
     * والسؤال يُطرح مرّةً لأنّ البطاقتين كانتا تُعرضان معًا وفيهما كلمةُ
     * «نطاق» بمعنيين متضادّين: «موقعي عندكم» و«موقعي عند غيركم». فيكتب
     * التاجر عنوان متجره في حقل الموقع الخارجيّ، ويصير زرُّ «فتح الموقع»
     * يشير إلى عنوانٍ لا وجود له.
     */
    public const PATHS = ['sub', 'own', 'new'];

    /**
     * الطريقُ الذي اختاره هذا المتجر — أو '' إن لم يُسأل بعد.
     *
     * ولمن سبق الاختيار يُستنتج ولا يُسأل: من حجز عنوانًا فطريقُه `sub`، ومن
     * كتب نطاقه الخارجيّ فطريقه `own`. وسؤالُ من ضبط موقعه قبل وجود هذه
     * الشاشة «هل عندك نطاق؟» يجعله يظنّ أنّ ما ضبطه ضاع.
     */
    public static function path(Business $business): string
    {
        $site = MarketingSettings::group((int) $business->id, 'website');
        $saved = (string) ($site['site_path'] ?? '');

        if (in_array($saved, self::PATHS, true)) {
            return $saved;
        }

        if ($business->site_slug !== null && $business->site_slug !== '') {
            return 'sub';
        }

        return trim((string) ($site['site_domain'] ?? '')) !== '' ? 'own' : '';
    }

    /**
     * أسعارُ العنوان كما تُعرض — من `config/storefront.php` وحده.
     *
     * و`free` تُحسب هنا لا في الشاشة: «صفر» و«مشمول في باقتك» حكمٌ واحد،
     * وحسابُه في طرفين يجعل شاشةً تقول «٠٫٠٠٠ ر.ع» في يومٍ يقول فيه الخادم
     * «مجّانًا».
     *
     * @return array<string, mixed>
     */
    public static function pricing(): array
    {
        $monthly = self::money(config('storefront.pricing.subdomain.monthly'));
        $yearly = self::money(config('storefront.pricing.subdomain.yearly'));

        return [
            'currency' => (string) config('storefront.currency'),
            'subdomain' => [
                'monthly' => $monthly,
                'yearly' => $yearly,
                'free' => $monthly <= 0.0 && $yearly <= 0.0,
            ],
        ];
    }

    /** رقمُ سعرٍ نظيف — والسالبُ يُقرأ صفرًا لا خصمًا */
    private static function money(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return $number > 0 ? round($number, 3) : 0.0;
    }

    /** المتجر صاحبُ هذا الاسم — إن كان منشورًا */
    public static function find(string $slug): ?Business
    {
        $business = Business::where('site_slug', $slug)->first();

        if (! $business || $business->status !== 'نشط') {
            return null;
        }

        return self::published($business) ? $business : null;
    }

    /** هل نشر صاحبُه متجره؟ */
    public static function published(Business $business): bool
    {
        return $business->site_slug !== null
            && (MarketingSettings::group((int) $business->id, 'website')['store_on'] ?? '0') === '1';
    }

    /* ------------------------------ الثيمات ------------------------------ */

    /**
     * ثيماتٌ معدودة لا لوحةُ ألوان.
     *
     * صاحبُ محلٍّ ليس مصمّمًا، ومنحُه منتقي ألوانٍ حرًّا يُخرج صفحةً بلون
     * فاقعٍ على أبيض لا تُقرأ. فالخيارات معدودةٌ كلُّها مضبوطةُ التباين،
     * ويقع اختيارُها في نقرة — وهو معنى «أسهل طريقة ممكنة».
     */
    public const THEMES = [
        'rose' => ['label' => 'وردي', 'accent' => '#be185d', 'soft' => '#fdf2f8', 'ink' => '#500724'],
        'sand' => ['label' => 'رملي', 'accent' => '#a16207', 'soft' => '#fefce8', 'ink' => '#422006'],
        'olive' => ['label' => 'زيتوني', 'accent' => '#4d7c0f', 'soft' => '#f7fee7', 'ink' => '#1a2e05'],
        'night' => ['label' => 'ليلي', 'accent' => '#111827', 'soft' => '#f3f4f6', 'ink' => '#030712'],
        'sea' => ['label' => 'بحري', 'accent' => '#0e7490', 'soft' => '#ecfeff', 'ink' => '#083344'],
    ];

    public static function theme(?string $key): array
    {
        return self::THEMES[$key] ?? self::THEMES['rose'];
    }

    public static function themeOptions(): array
    {
        return collect(self::THEMES)
            ->map(fn ($t, $key) => ['value' => $key, 'label' => __($t['label']), 'accent' => $t['accent']])
            ->values()->all();
    }

    /* ------------------------------ الصفحة ------------------------------ */

    /**
     * كلُّ ما تحتاجه الصفحة العامّة — ولا حرفَ زائد.
     *
     * @return array<string, mixed>
     */
    public static function page(Business $business): array
    {
        $bid = (int) $business->id;
        $site = MarketingSettings::group($bid, 'website');
        $theme = self::theme($site['store_theme'] ?? null);
        $showPrices = ($site['store_show_prices'] ?? '1') === '1';
        $whatsapp = self::orderNumber($business, $site);
        $currency = self::currency($business);

        return [
            'business' => $business,
            'slug' => $business->site_slug,
            'url' => self::url($business->site_slug),
            'theme' => $theme,
            'themeKey' => array_search($theme, self::THEMES, true) ?: 'rose',
            'headline' => trim((string) ($site['store_headline'] ?? '')) ?: $business->name,
            'about' => trim((string) ($site['store_about'] ?? '')),
            'showPrices' => $showPrices,
            'whatsapp' => $whatsapp,
            'phone' => $business->phone,
            'address' => $business->address,
            'cod' => ($site['store_pay_cod'] ?? '1') === '1',
            'transfer' => ($site['store_pay_transfer'] ?? '0') === '1',
            'bank' => trim((string) ($site['store_bank'] ?? '')),
            'categories' => self::categories($bid),
            'currency' => $currency,
            'products' => self::products($bid, $showPrices, $whatsapp, $currency),
            'logo' => $business->logo ? Storage::url($business->logo) : null,
        ];
    }

    /**
     * عملةُ المتجر — مقروءةً من المتجر لا من الجلسة.
     *
     * كان السعرُ يُكتب في الصفحة `number_format($p, 3).' ر.ع'` — ثلاثُ خاناتٍ
     * ورمزٌ عمانيّ مثبَّتان في القالب وفي رابط الطلب. فمتجرٌ في الإمارات أو
     * السعودية يعرض أسعاره بالريال العماني على صفحةٍ يفتحها زبونُه، ويصل
     * التاجرَ طلبٌ بمبلغٍ بعملةٍ أخرى.
     *
     * و`Demo::baseCurrency` لا تصلح هنا: تلك تقرأ متجر المستخدم الحاليّ،
     * وزائرُ المتجر لا مستخدمَ له — فتُقرأ أسعارُ كلّ المتاجر بعملة آخر
     * تاجرٍ دخل، أو بالافتراضية وهي عملة أحدهم لا عملة الجميع.
     *
     * @return array{code: string, symbol: string, decimals: int}
     */
    public static function currency(Business $business): array
    {
        $bid = (int) $business->id;
        $row = \App\Models\Currency::where('business_id', $bid)->where('is_base', true)->first();

        $code = $row?->code ?: strtoupper(trim((string) (
            \App\Models\Setting::where('business_id', $bid)->where('key', 'currency')->value('value') ?? ''
        )));
        $code = preg_match('/^[A-Z]{3}$/', $code) ? $code : 'OMR';

        return [
            'code' => $code,
            'symbol' => $row?->symbol ?: (Demo::SYMBOLS[$code] ?? $code),
            // ثلاثُ خاناتٍ للريال العماني وأخواته، واثنتان لما سواها
            'decimals' => in_array($code, ['OMR', 'KWD', 'BHD'], true) ? 3 : 2,
        ];
    }

    /**
     * المبلغ مكتوبًا بعملة هذا المتجر — تقرؤه الصفحة ورابطُ الطلب معًا.
     *
     * واسمُها `amount` لا `money`: تلك قائمةٌ هنا لتنظيف رقمِ سعرٍ من إعدادات
     * المنصّة، وشيءٌ آخر تمامًا.
     */
    public static function amount(float $value, array $currency): string
    {
        return number_format($value, $currency['decimals']).' '.$currency['symbol'];
    }

    /**
     * ما يُعرض في المتجر — استعلامٌ واحدٌ تقرؤه القائمةُ والأقسام معًا.
     *
     * وشرطان لا واحد: `active` تعني «يُباع في نقطة البيع»، و`published` تعني
     * «يراه الزبون». وكانت الأولى وحدها تحكم الصفحة، فيظهر للزبائن ورقُ
     * التغليف ومكوّناتُ الباقات وأسعارُ الجملة، ولا يملك التاجر منعَ صنفٍ
     * إلّا بإيقاف بيعه عند الطاولة أيضًا.
     *
     * وموضعُ الشرطين واحدٌ عمدًا: قائمةٌ تُصفّى وأقسامٌ لا تُصفّى معها تعني
     * تبويبَ قسمٍ يفتح على صفحةٍ فارغة.
     */
    private static function shown(int $bid): \Illuminate\Database\Eloquent\Builder
    {
        return Product::where('business_id', $bid)
            ->where('active', true)
            ->where('published', true);
    }

    /**
     * السعر الذي يدفعه الزبون — لا الرقم الخام في العمود.
     *
     * وعمودُ `price` ليس السعر في حالتين: ذو المقاسات لا يُباع بنفسه («السعر
     * يأتي من المقاس المختار» — نصُّ `ProductVariant`)، وذو الخصم يُباع بعد
     * خصمه (`Product::sellingPrice`). فعرضُ العمود خامًا يُري الزبون رقمًا
     * يطلب عليه ثمّ يُقال له غيرُه — وهو أسوأ من ألّا يُعرض سعر.
     *
     * @return array{0: float, 1: bool} السعر، وهل هو «من» أدنى مقاس
     */
    private static function price(Product $product): array
    {
        $prices = $product->variants->pluck('price')->map(fn ($v) => (float) $v)->all();

        if ($prices === []) {
            return [$product->sellingPrice(), false];
        }

        return [min($prices), min($prices) !== max($prices)];
    }

    /**
     * الرقم الذي يصله الطلب.
     *
     * ويقع على رقم المتجر إن لم يُكتب رقمٌ للطلبات: أكثرُ المحلّات لها رقمٌ
     * واحد. وبلا رقمٍ أصلًا لا يُعرض زرُّ الطلب — زرٌّ يفتح محادثةً بلا
     * مستقبِل أسوأ من غيابه.
     */
    public static function orderNumber(Business $business, array $site): ?string
    {
        $raw = trim((string) ($site['store_whatsapp'] ?? '')) ?: (string) $business->phone;

        return WhatsAppPhone::normalize($raw);
    }

    /** أقسامٌ فيها منتجٌ معروض — وقسمٌ فارغ لا يُعرض تبويبًا يفتح على فراغ */
    private static function categories(int $bid): array
    {
        $used = self::shown($bid)
            ->whereNotNull('category_id')->distinct()->pluck('category_id');

        return Category::where('business_id', $bid)->whereIn('id', $used)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all();
    }

    /**
     * المنتجات المعروضة — الفعّالة وحدها.
     *
     * ولا كميّةَ تخرج: عرضُ «باقٍ ٢» يدفع الزبون إلى الاستعجال، وعرضُ «باقٍ
     * ٤٠٠» يقول لمنافسك كم عندك. والمعروضُ حالةٌ لا رقم: متوفّرٌ أو نفد.
     */
    private static function products(int $bid, bool $showPrices, ?string $whatsapp, array $currency): array
    {
        return self::shown($bid)
            ->orderByDesc('quantity')->orderBy('name')
            // المقاسات معه: سعرُ ذي المقاسات لا يُقرأ من عموده — انظر price()
            ->with(['variants' => fn ($q) => $q->where('active', true)])
            ->get()
            ->map(function ($p) use ($showPrices, $whatsapp, $currency) {
                [$price, $from] = self::price($p);
                $price = $showPrices ? $price : null;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'category_id' => $p->category_id,
                    // الخام لا المقروء: `getImageAttribute` يردّ صورةً بديلة من
                    // الإنترنت لمنتجٍ بلا صورة — ولا تُعرض صورةُ غريبٍ بضاعةً للتاجر
                    'image' => ProductImages::hasRealMain($p) ? $p->image : null,
                    'price' => $price,
                    // «من ١٨٫٠٠٠» حين تختلف أسعار المقاسات — انظر price()
                    'from' => $from,
                    'available' => (int) $p->quantity > 0,
                    'order_url' => self::orderLink($whatsapp, $p->name, $price, $currency),
                ];
            })->values()->all();
    }

    /**
     * رابطُ الطلب — محادثةُ واتساب بالطلب مكتوبًا.
     *
     * ويُبنى هنا لا في القالب: القالب يرسم ولا يحسب. وكان بناؤه فيه يُدخل
     * كتلةَ `@php` في صفحةٍ عامّة — وهي أوّل ما يُنسى فحصُه.
     *
     * وبلا رقمٍ يعود null فلا يُرسم الزرّ: زرٌّ يفتح محادثةً بلا مستقبِل
     * أسوأ من غيابه.
     */
    public static function orderLink(?string $whatsapp, string $product, ?float $price, array $currency): ?string
    {
        if ($whatsapp === null) {
            return null;
        }

        $line = $product.($price !== null ? ' — '.self::amount($price, $currency) : '');

        return 'https://wa.me/'.$whatsapp.'?text='.rawurlencode(__('السلام عليكم، أريد طلب:').' '.$line);
    }
}
