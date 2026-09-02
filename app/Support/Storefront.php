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
            'products' => self::products($bid, $showPrices, $whatsapp),
            'logo' => $business->logo ? Storage::url($business->logo) : null,
        ];
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
        $used = Product::where('business_id', $bid)->where('active', true)
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
    private static function products(int $bid, bool $showPrices, ?string $whatsapp): array
    {
        return Product::where('business_id', $bid)->where('active', true)
            ->orderByDesc('quantity')->orderBy('name')
            ->get()
            ->map(function ($p) use ($showPrices, $whatsapp) {
                $price = $showPrices ? (float) $p->price : null;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'category_id' => $p->category_id,
                    // الخام لا المقروء: `getImageAttribute` يردّ صورةً بديلة من
                    // الإنترنت لمنتجٍ بلا صورة — ولا تُعرض صورةُ غريبٍ بضاعةً للتاجر
                    'image' => ProductImages::hasRealMain($p) ? $p->image : null,
                    'price' => $price,
                    'available' => (int) $p->quantity > 0,
                    'order_url' => self::orderLink($whatsapp, $p->name, $price),
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
    public static function orderLink(?string $whatsapp, string $product, ?float $price): ?string
    {
        if ($whatsapp === null) {
            return null;
        }

        $line = $product.($price !== null ? ' — '.number_format($price, 3).' ر.ع' : '');

        return 'https://wa.me/'.$whatsapp.'?text='.rawurlencode(__('السلام عليكم، أريد طلب:').' '.$line);
    }
}
