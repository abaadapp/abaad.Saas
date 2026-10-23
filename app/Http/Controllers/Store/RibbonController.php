<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PdfController;
use App\Models\Addon;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorePaymentIntent;
use App\Models\Review;
use App\Support\CategoryName;
use App\Support\NotesAndEdits;
use App\Support\ProductAddons;
use App\Support\ProductDescription;
use App\Support\ProductName;
use App\Support\SaleLines;
use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use App\Support\Money;
use App\Support\PaymentMethods;
use App\Support\Seo;
use App\Support\Store\BestSellers;
use App\Support\Store\RibbonPicks;
use App\Support\Store\RibbonTexts;
use App\Support\Store\RibbonUpsells;
use App\Support\Store\CheckoutFields;
use App\Support\Store\EnglishCheckout;
use App\Support\Store\GiftCard;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\GiftOrders;
use App\Support\Store\NewArrivals;
use App\Support\Store\ProductReviews;
use App\Support\Store\PaidReceipt;
use App\Support\Store\ShelfOrder;
use App\Support\Store\ThankYouPage;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreMedia;
use App\Support\Store\StoreFooter;
use App\Support\Store\StoreHeader;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreSeo;
use App\Support\Store\StoreVisualBar;
use App\Support\Store\StorePage;
use App\Support\Store\CardIdentity;
use App\Support\Store\CardIdentityUnconfirmed;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\OrderWindow;
use App\Support\Store\ShopClock;
use App\Support\Store\StoreTerms;
use App\Support\Store\TermsChanged;
use App\Support\Store\WebCheckout;
use App\Support\Storefront;
use App\Support\Website\MerchantData;
use App\Support\Website\Shelf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * واجهةُ RIBBON — متجرٌ كاملٌ على عنوان التاجر: رئيسيةٌ ومنتجاتٌ وصنفٌ وسلّةٌ
 * وإتمامُ طلبٍ في صفحةٍ واحدة وتأكيد.
 *
 * وهي **خارج كلّ حرّاس النظام** كصفحة المتجر البسيطة: زبونٌ لا حساب له.
 * والمتجرُ يصل من `StorefrontController` بعد أن عرفه من عنوانه وتحقّق أنّه
 * يُخدم — فلا يُقرأ هنا معرّفٌ من الطلب.
 *
 * والتصميمُ تصميمُ صاحبه (ملفّ «متجر RIBBON الإلكتروني») نُقل إلى Blade
 * بألوانه وخطّه وترتيب أقسامه، والبياناتُ بياناتُ أبعاد: الأصنافُ والأقسامُ
 * والأسعارُ والمقاساتُ والكوبوناتُ والرفّ. ولا يُخترع ما ليس في المتجر —
 * قسمٌ بلا بياناتٍ لا يُرسم.
 */
class RibbonController extends Controller
{
    /*
     * و`paying` صفحةُ العودة من بوّابة الدفع — لا تكتب شيئًا.
     *
     * وهي من مسارات المتجر لا بابًا على حدة: فتُبنى لها الترويسةُ والتذييل
     * وتُقرأ بلغتها كأيّ صفحة، ويصحّ رابطُها على الطرق الثلاث إلى العنوان.
     */
    private const PATHS = ['shop', 'about', 'contact', 'cart', 'checkout', 'p', 'done', 'paying', 'receipt', StoreCustomPages::PATH];

    /**
     * ما يتبعه مقطعٌ ثانٍ — وما سواه لا يقبله.
     *
     * ═══ والعطبُ الذي وُضع لأجله ═══
     *
     * كان المقطعُ الثاني يُقرأ ويُهمَل: `‎/shop/أيّ-شيء‎` يردّ صفحةَ المتجر
     * بـ٢٠٠، وكذلك `‎/cart/x‎` و`‎/checkout/x‎` و`‎/about/x‎` و`‎/contact/x‎`.
     * فلكلّ صفحةٍ نسخٌ لا تُحصى على عناوين لا نهاية لها.
     *
     * وضررُه في موضعين: غوغل يفهرس النسخَ فيقسم ثقلَ الصفحة الواحدة على
     * عشرٍ منها ويعرض أيَّها شاء؛ ورابطٌ كُتب خطأً يُفتح فيبدو سليمًا، فلا
     * يكتشف أحدٌ أنّه خطأ حتّى يُوزَّع.
     *
     * والصفحاتُ التي تقبله تحتاجه: صنفٌ بمعرّفه، وتأكيدٌ برقم طلبه، وعودةٌ
     * من البوّابة بمرجعها، وإيصالُ الدفع برقم طلبه، وصفحةٌ مخصّصةٌ باسمها.
     */
    private const TAKES_ID = ['p', 'done', 'paying', 'receipt', StoreCustomPages::PATH];

    /* ═══════════ الصفحات ═══════════ */

    public function page(Business $business, ?string $path, string $base): Response
    {
        $lang = $this->lang();
        [$first, $second] = array_pad(explode('/', trim((string) $path, '/'), 2), 2, null);
        $first = $first === '' ? null : $first;

        if ($first !== null && ! in_array($first, self::PATHS, true)) {
            abort(404);
        }

        // ومقطعٌ ثانٍ على صفحةٍ لا تأخذه ليس عنوانَها — انظر `TAKES_ID`
        if ($second !== null && ! in_array($first, self::TAKES_ID, true)) {
            abort(404);
        }

        /*
         * وصفحةٌ أطفأها صاحبُها أو فرغت تُردّ «غير موجود» — لا صفحةً بيضاء.
         *
         * القائمةُ لا ترسم رابطَها أصلًا (انظر `StoreNav::links`)، والرابطُ
         * يبقى محفوظًا في متصفّحٍ ومفهرَسًا عند غوغل بعد أن تُطفأ. فيُسأل
         * السؤالُ هنا أيضًا ولا يُكتفى بإخفاء الرابط: إخفاءُ رابطٍ ليس حراسة.
         */
        if (in_array($first, StoreNav::OPTIONAL, true) && ! StoreNav::has((int) $business->id, $first, $lang)) {
            abort(404);
        }

        // والإيصالُ ملفٌّ لا صفحة: لا ترويسةَ له ولا تذييل
        if ($first === 'receipt') {
            return $this->receipt($business, (string) $second);
        }

        /*
         * والصفحةُ المخصّصة تُقرأ قبل الترويسة — ليُضاء رابطُها في القائمة،
         * ويُردّ «غير موجود» لما لا يُعرف أو أُخفي قبل أن يُبنى شيء.
         */
        $custom = $first === StoreCustomPages::PATH ? $this->customPage($business, (string) $second) : null;

        $ctx = $this->context($business, $base, $lang, $first ?? StoreNav::HOME, $this->here($first, $second, $custom));

        return match ($first) {
            null => $this->render('store.ribbon.home', $ctx + $this->home($business, $lang)),
            'shop' => $this->render('store.ribbon.shop', $ctx + $this->shop($business, $lang, request(), $base)),
            'about' => $this->render('store.ribbon.about', $ctx + $this->about($business, $lang)),
            'contact' => $this->render('store.ribbon.contact', $ctx + $this->contact($business, $lang)),
            'p' => $this->render('store.ribbon.product', $ctx + $this->product($business, (int) $second, $lang)),
            'cart' => $this->render('store.ribbon.cart', $ctx),
            'checkout' => $this->render('store.ribbon.checkout', $ctx + $this->checkoutData($business, $lang)),
            'done' => $this->render('store.ribbon.done', $ctx + $this->done($business, (int) $second, $lang)),
            'paying' => $this->paying($business, (string) $second, $ctx),
            StoreCustomPages::PATH => $this->render('store.ribbon.page', $this->customPageData($business, $custom, $ctx, $lang, $base)),
        };
    }

    /**
     * ما يقف عليه الزائر — وجهةً تُقاس عليها روابطُ القائمة (`StoreTarget::same`).
     *
     * @return array{type: string, value: string|int}|null
     */
    private function here(?string $first, ?string $second, ?array $custom): ?array
    {
        $q = request()->query();

        return match ($first) {
            null => ['type' => 'home', 'value' => ''],
            'shop' => match (true) {
                ($q['view'] ?? null) === StoreHeader::VIEW_BEST => ['type' => 'best', 'value' => ''],
                is_string($q[StoreCollections::QUERY] ?? null) && $q[StoreCollections::QUERY] !== '' => ['type' => 'collection', 'value' => $q[StoreCollections::QUERY]],
                (int) ($q['cat'] ?? 0) > 0 => ['type' => 'category', 'value' => (int) $q['cat']],
                default => ['type' => 'shop', 'value' => ''],
            },
            'about', 'contact' => ['type' => $first, 'value' => ''],
            'p' => ['type' => 'product', 'value' => (int) $second],
            StoreCustomPages::PATH => $custom === null ? null : ['type' => 'page', 'value' => $custom['slug']],
            default => null,
        };
    }

    /**
     * أهذه معاينةُ صاحب المتجر؟ — يضعها `StorefrontController::preview` على الطلب.
     *
     * فيها وحدها تُفتح المجموعةُ والصفحةُ المخفيّتان: يجهّزهما ويراهما قبل أن
     * يُظهرهما. وزبونُه لا يبلغهما بأيّ رابط.
     */
    private function previewing(): bool
    {
        return request()->attributes->get(StorefrontController::PREVIEW) === true;
    }

    /** صفحةٌ مخصّصةٌ بالاسم المختصر — ظاهرةٌ، أو مخفيّةٌ في المعاينة — وإلّا «غير موجود» */
    private function customPage(Business $business, string $slug): array
    {
        $bid = (int) $business->id;
        $page = preg_match(StoreCollections::SLUG, $slug) === 1
            ? ($this->previewing() ? StoreCustomPages::findAny($bid, $slug) : StoreCustomPages::find($bid, $slug))
            : null;

        abort_if($page === null, 404);

        return $page;
    }

    /**
     * الصفحةُ المخصّصة كما تُرسم — ووصفُها في البحث نبذتُها إن كتبها.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function customPageData(Business $business, array $page, array $ctx, string $lang, string $base): array
    {
        $bid = (int) $business->id;
        $view = StoreCustomPages::view($bid, $page, $lang, $base);

        /*
         * والمنتجاتُ بطاقاتُ الرفّ نفسُها — من المعروض وحده، في سؤالٍ واحدٍ
         * للصفحة كلّها. ومنتجٌ أُخفي بعد اختياره لا يُرسم، ووحدةٌ لم يبقَ فيها
         * منتجٌ معروضٌ لا تُرسم: لا عنوانَ فوق رفٍّ فارغ.
         */
        $wanted = [];

        foreach ($view['blocks'] as $b) {
            if (isset($b['ids'])) {
                array_push($wanted, ...$b['ids']);
            }
        }

        $shown = $wanted === [] ? collect() : $this->shown($bid)->whereIn('id', array_values(array_unique($wanted)))
            ->with(['category:id,name,name_en', 'variants'])->get()->keyBy('id');
        $currency = Storefront::currency($business);

        $view['blocks'] = array_values(array_filter(array_map(function (array $b) use ($shown, $lang, $business, $currency) {
            if (! isset($b['ids'])) {
                return $b;
            }

            $cards = [];

            foreach ($b['ids'] as $id) {
                if ($shown->has($id)) {
                    $cards[] = $this->card($shown[$id], $lang, $business, $currency);
                }
            }

            // والمجموعةُ تُعرض أوّلُها ورابطٌ إلى رفّها — لا الرفُّ كلُّه في صفحةٍ أخرى
            if ($b['type'] === 'collection') {
                $cards = array_slice($cards, 0, StoreCustomPages::COLLECTION_SHOWN);
            }

            unset($b['ids']);

            return $cards === [] ? null : $b + ['cards' => $cards];
        }, $view['blocks'])));

        if ($view['intro'] !== '') {
            $ctx['seo']['description'] = \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $view['intro']), 155);
        }

        // وصورتُها صورةُ مشاركتها — من أرسل رابطَ «خدماتنا» يرى صورتَها لا شعارَ المتجر
        $og = $view['image']['url'] ?? null;

        return $ctx + [
            'customPage' => $view,
            'ogImage' => $og === null ? null : (str_starts_with($og, 'http') ? $og : url($og)),
        ];
    }

    /** السلّةُ مسعَّرةً من الخادم — تُقرأ في صفحتَي السلّة والإتمام */
    public function quote(Business $business, Request $request): JsonResponse
    {
        $this->lang();

        try {
            $q = WebCheckout::quote($business, $request->all());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }

        return response()->json(['ok' => true] + $this->publicQuote($business, $q));
    }

    /** إتمامُ الطلب — طلبٌ حقيقيّ في أبعاد */
    public function place(Business $business, Request $request, string $base): JsonResponse
    {
        try {
            /*
             * والبطاقةُ لا تُنشئ طلبًا هنا — تفتح صفحةَ البوّابة.
             *
             * الطلبُ يُكتب حين يصل المال، من الإشعار الموقَّع وحدَه (انظر
             * `Store\PaymobController`). ولو كُتب قبله لَخصم كلُّ زائرٍ فتح
             * صفحةَ الدفع ثمّ أغلقها باقةً من الرفّ.
             */
            if ($request->input('pay') === WebCheckout::PAY_CARD) {
                return response()->json([
                    'ok' => true,
                    'redirect' => WebCheckout::toCard($business, $request->all(), $this->lang()),
                ]);
            }

            $order = WebCheckout::place($business, $request->all(), $this->lang());
        } catch (CardIdentityUnconfirmed $e) {
            /*
             * ورسالةُ بطاقةٍ تقول اسمَ من أخفى اسمَه — تسأله الصفحة: «عدّلها
             * أو أرسلها كما هي». والرمزُ يُرسَل إن اختار الثانية، و`source`
             * يقول أيَّ محرّرٍ يُفتح إن اختار الأولى. انظر `CardIdentity`.
             */
            return response()->json([
                'ok' => false,
                'errors' => $e->errors(),
                'card_identity' => ['token' => $e->token, 'source' => $e->source],
            ], 422);
        } catch (TermsChanged $e) {
            /*
             * وشروطٌ حُدّثت والصفحةُ مفتوحة — تُعطى الصفحةُ نصَّها الجديد بلغتها
             * فتعرضه وتُفرغ الموافقة، ولا يُكتب طلبٌ على نصٍّ لم يره. انظر `StoreTerms`.
             */
            return response()->json(['ok' => false, 'errors' => $e->errors(), 'terms' => $e->terms], 422);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (\RuntimeException $e) {
            // وبوّابةٌ لم تُجب لا تُترك صفحةً بيضاء: يُقال له ويُعرض عليه غيرُها
            return response()->json([
                'ok' => false,
                'errors' => ['pay' => [__('تعذّر فتحُ صفحة الدفع — جرّب طريقةً أخرى أو أعد المحاولة.')]],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'number' => $order->number,
            'redirect' => $base.'/done/'.$order->id.'?t='.WebCheckout::token($order),
        ]);
    }

    /**
     * يرفع الزائرُ ملفَّ كرت الهدية — صورةً بخطّ يده أو تصميمًا جاهزًا.
     *
     * ═══ ولمَ بابٌ على حدة ═══
     *
     * إتمامُ الطلب يُرسَل JSON ويُعاد تسعيرُه في الخادم مرّاتٍ قبل أن يُضغط
     * «تأكيد». ورفعُ ملفٍّ في كلّ مرّةٍ يعني رفعَه مرارًا — أو تحويلَ البابِ
     * كلِّه إلى `multipart` وهو يُستعمل في السلّة والتسعير أيضًا.
     *
     * فالملفُّ يُرفع مرّةً ويُعاد عنه رمز، ويُرسَل الرمزُ مع الطلب. وهو
     * معلَّقٌ حتى يُتمّ صاحبُه، ويُكنس بعد يومٍ إن لم يُتمّ — انظر
     * `Store\GiftCard`.
     *
     * والبابُ مفتوحٌ لزائرٍ مجهول، فحُدَّ بالنوع والحجم وبعدد المحاولات في
     * المسار نفسِه.
     */
    public function giftCard(Business $business, Request $request): JsonResponse
    {
        $bid = (int) $business->id;

        if (! GiftCard::enabled($bid)) {
            return response()->json(['ok' => false, 'errors' => ['file' => [__('كرت الهدية غير متاح في هذا المتجر.')]]], 422);
        }

        try {
            $request->validate([
                'file' => ['required', 'file', 'mimes:'.implode(',', GiftCard::MIMES), 'max:'.GiftCard::MAX_KB],
            ], [
                'file.mimes' => __('نوع الملف غير مدعوم — صورة أو PDF.'),
                'file.max' => __('الملف أكبر من المسموح.'),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }

        return response()->json(['ok' => true] + GiftCard::hold($request->file('file')));
    }

    /* ═══════════ بيانات الصفحات ═══════════ */

    private function home(Business $business, string $lang): array
    {
        $bid = (int) $business->id;
        $shown = $this->shown($bid)->with(['category:id,name,name_en', 'variants'])->get();

        /*
         * الأكثرُ مبيعًا ممّا بيع فعلًا (`BestSellers` بقاعدة `Order::sold`)، والواصلُ حديثًا
         * بتاريخ إضافته. ولا يُخترع ترتيب: متجرٌ لم يبع بعدُ يعرض أحدثَ أصنافه
         * في الموضعين.
         */
        $sold = BestSellers::quantities($bid, $shown->pluck('id'));

        /*
         * والمختاراتُ تتقدّم الحسبة — إن اختار.
         *
         * الحسبةُ تتأخّر عن المواسم دائمًا: باقاتُ العيد تُبرَز بعد أن تبيع
         * وقد مضى العيد. ومن لم يختر يبقى على المحسوب كما كان.
         *
         * والمعروضُ وحدَه: صنفٌ أُبرز ثمّ أُخفي أو نفد لا يُقحَم في الصفحة
         * بأمرِ إعدادٍ قديم — `$shown` هي قاعدةُ «ما يُعرض» ولا تُتجاوَز.
         */
        $picked = StorePage::featured($bid);

        $chosen = $shown->whereIn('id', $picked)
            ->sortBy(fn ($p) => array_search($p->id, $picked, true))
            ->values();

        /*
         * ومختاراتٌ لم يبقَ منها شيءٌ معروض تعود إلى الحسبة — لا إلى فراغ.
         *
         * صاحبُ المحلّ يختار أربعةً في العيد، ثمّ تنفد أو يُخفيها بعده.
         * ولولا هذا لَاختفى القسمُ كلُّه من صفحته بلا أن يفعل شيئًا، ولا
         * شيءَ في الشاشة يقول له لماذا — إعدادٌ قديمٌ يمحو قسمًا قائمًا.
         */
        $best = $chosen->isNotEmpty()
            ? $chosen
            : $shown->sortByDesc(fn ($p) => [(int) ($sold[$p->id] ?? 0), $p->id])->take(4)->values();

        /*
         * و«وصل حديثًا» أحدثُ أربعةٍ بالمعرّف — إلّا لمن في قائمة الاختيار
         * اليدويّ واختار: فما اختاره بترتيبه، من `$shown` وحدها، وإلى
         * الأحدث إن لم يبقَ ممّا اختاره شيءٌ معروض. انظر `NewArrivals`.
         */
        $new = NewArrivals::pick($bid, $shown);

        // عملةٌ واحدةٌ للرفّ كلِّه — انظر `card`
        $currency = Storefront::currency($business);

        return [
            /*
             * وبطاقاتُ «تسوّق حسب الفئة» بلا ما أخفاه صاحبُه — هنا وحدها.
             *
             * فالرفُّ ومرشِّحُه وروابطُ التذييل تقرأ `categories` كما هي:
             * القسمُ المخفيُّ بطاقتُه يبقى يُختار وتُباع أصنافُه.
             */
            'categories' => $this->categories($bid, $lang, $shown)->reject(fn ($c) => $c['hidden'])->values(),
            'best' => $best->map(fn ($p) => $this->card($p, $lang, $business, $currency))->all(),
            'new' => $new->map(fn ($p) => $this->card($p, $lang, $business, $currency))->all(),
            /*
             * أفي المتجر بضاعةٌ أصلًا؟ — يسألها ما يَعِد الزبونَ بشيء.
             *
             * والسؤالُ عن الرفّ لا عن قسمٍ بعينه: صاحبُ المحلّ قد يُطفئ
             * «وصل حديثًا» ويبقى رفُّه عامرًا.
             */
            'shelf' => $shown->isNotEmpty(),
            // ترتيبُ الأقسام وظهورُها، وصورةُ الواجهة، والقسمُ الذي كتبه بنفسه
            'sections' => StorePage::order($bid),
            'hero' => StorePage::heroImage($bid),
            'bannerImage' => StorePage::bannerImage($bid),
            'block' => StorePage::block($bid, $lang),
            /*
             * وطريقةُ عرض كلّ صورةٍ من الثلاث — ما اختاره لها في المحرّر، والفراغُ
             * «ملء الإطار» كما كانت. انظر `StoreMedia::fit`.
             */
            'heroFit' => StoreMedia::fit(MarketingSettings::group($bid, 'website')['store_hero_image_fit'] ?? null),
            'bannerFit' => StoreMedia::fit(MarketingSettings::group($bid, 'website')['store_banner_image_fit'] ?? null),
            'blockFit' => StoreMedia::fit(MarketingSettings::group($bid, 'website')['store_block_image_fit'] ?? null),
            /*
             * وعنوانُ الواجهة ووصفُها بلغة الصفحة — ما كتبه لها، وإلّا نصُّ
             * القالب للّغة نفسِها (`StorePage::heroTitle`).
             */
            'heroTitle' => StorePage::heroTitle($bid, $lang, RibbonTexts::for($lang)),
            'heroSub' => StorePage::heroSub($bid, $lang, RibbonTexts::for($lang)),
            /*
             * و«اختيارات RIBBON» — أصنافُه بيده من `$shown` وحدها، بترتيبه.
             * ولا يُرسم القسمُ إلّا إن رفعه في الترتيب (`StorePage::order`).
             */
            'picks' => RibbonPicks::pick($bid, $shown)->map(fn ($p) => $this->card($p, $lang, $business, $currency))->all(),
            'picksTitle' => RibbonPicks::title($bid, $lang, RibbonTexts::for($lang)),
            // وعنوانُ «الأكثر مبيعًا» يتبع مصدرَه: محسوبًا يُسمّى، ومختارًا يُسمّى
            'bestPicked' => $chosen->isNotEmpty(),
            'reviews' => Review::where('business_id', $bid)->testimonial()->latest()->take(3)->get()
                ->map(fn ($r) => ['name' => $r->displayName(), 'text' => (string) $r->comment, 'rating' => (int) $r->rating])
                ->filter(fn ($r) => $r['text'] !== '')->values()->all(),
        ];
    }

    /**
     * رفُّ المتجر — وعقدُ رابطه ثلاثةُ مدخلاتٍ لا أكثر.
     *
     *  • `view=best`  ← ما بيع فعلًا، من الأكثر (`BestSellers`)، وله الغلبة:
     *                   مع `cat` يُهمَل `cat` — الخياراتُ في الصفّ واحدٌ منها.
     *  • `cat={id}`   ← فئةٌ بمعرّفها، لا باسمها.
     *  • `collection={slug}` ← مجموعةٌ كتبها صاحبُ المتجر (`StoreCollections`)،
     *                   بأصنافها وبترتيبه. بعد «الأكثر مبيعًا» وقبل `cat`: مع
     *                   الثاني يُهمَل الثاني. ومجموعةٌ لا تُعرف أو أُخفيت
     *                   تُردّ «غير موجود» — من إعداد هذا المتجر وحده.
     *  • `q`          ← يبحث **داخل** ما سبق: في الكلّ، أو الفئة، أو الأكثر
     *                   مبيعًا بترتيبه، أو المجموعة بترتيبها.
     *
     * وقيمةٌ أخرى لـ`view` تُقرأ «الكلّ» — لا خطأ، ولا رفَّين متناقضين.
     */
    private function shop(Business $business, string $lang, Request $request, string $base): array
    {
        $bid = (int) $business->id;
        $best = $request->query('view') === StoreHeader::VIEW_BEST;
        $asked = $best ? null : $request->query(StoreCollections::QUERY);
        // ومُدخَلٌ ليس نصًّا (`collection[]=x`) لا يُقرأ مجموعةً ولا يُسقط الصفحة
        abort_if($asked !== null && ! is_string($asked), 404);
        // والمخفيّةُ تُفتح في معاينة صاحبها وحدها — `previewing`
        $collection = is_string($asked) && $asked !== ''
            ? ($this->previewing() ? StoreCollections::findAny($bid, $asked) : StoreCollections::find($bid, $asked))
            : null;
        abort_if(is_string($asked) && $asked !== '' && $collection === null, 404);
        $cat = $best || $collection !== null ? 0 : (int) $request->query('cat', 0);
        $q = trim((string) $request->query('q', ''));
        $shown = $this->shown($bid)->with(['category:id,name,name_en', 'variants'])->orderBy('name')->get();

        $currency = Storefront::currency($business);
        $categories = $this->categories($bid, $lang, $shown);

        $list = ($best ? BestSellers::ranked($bid, $shown) : ($collection !== null ? StoreCollections::members($collection, $shown) : $shown))
            ->when($cat > 0, fn ($c) => $c->where('category_id', $cat))
            /*
             * والبحثُ بالاسمين معًا أيًّا كانت لغةُ الصفحة: زبونٌ على الصفحة
             * الإنجليزيّة يكتب «ورد» فيجد الباقة، وعلى العربيّة يكتب «Flower»
             * فيجدها — الصنفُ نفسُه بمعرّفه. ولا ترجمةَ ولا نقحرة: ما كُتب وحده.
             */
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => mb_stripos(ProductName::searchable($p->name, $p->name_en), $q) !== false));

        /*
         * و«كل المنتجات» والفئةُ بترتيب صاحبها إن رتّبهما — كلٌّ بقائمته
         * (`ShelfOrder`)، والبحثُ يحفظ ترتيبَ ما يُبحث فيه. و«الأكثر مبيعًا»
         * والمجموعةُ بترتيبهما هما لا يُمسّان.
         */
        if (! $best && $collection === null) {
            $list = ShelfOrder::apply($bid, $list, $cat);
        }

        return [
            'categories' => $categories,
            'cat' => $cat,
            'best' => $best,
            /*
             * واسمُ الفئة عنوانًا للرفّ — فمن وصل من بطاقةٍ في الرئيسية أو من
             * التذييل يعرف أين هو، وصفُّ الترويسة قد لا يحمل فئتَه.
             */
            'catName' => $cat > 0 ? ($categories->firstWhere('id', $cat)['name'] ?? null) : null,
            /*
             * والمجموعةُ عنوانًا للرفّ ووصفًا تحته — اسمُها بلغة الصفحة،
             * ووصفُها بلغتها وحدها (`StoreCollections`).
             */
            'collection' => $collection === null ? null : [
                'slug' => $collection['slug'],
                'title' => StoreCollections::title($collection, $lang),
                'description' => StoreCollections::description($collection, $lang),
                /*
                 * وصورتُها رأسًا لرفّها — بطريقة عرضها: «كاملة» بنسبتها، أو تملأ
                 * إطارًا عريضًا. ومجموعةٌ بلا صورةٍ رفُّها كما كان.
                 */
                'image' => $collection['image'] !== '' ? $collection['image'] : null,
                'fit' => StoreMedia::fit($collection['fit'] ?? null),
                'size' => $collection['image'] !== '' ? StoreMedia::size($bid, $collection['image']) : null,
                'hidden' => ! $collection['visible'],
            ],
            /*
             * صفُّ خيارات المتجر — يُرسم داخل الترويسة اللاصقة (`layout`)
             * لا في جسم الصفحة: موضعٌ واحدٌ للترشيح لا اثنان.
             */
            'shopOptions' => StoreHeader::shopOptions($bid, $categories, RibbonTexts::for($lang), $base, $cat, $best, StoreCollections::visible($bid), $collection['slug'] ?? null, $lang),
            'q' => $q,
            /*
             * أفي الرفّ شيءٌ أصلًا؟ — ليُفرَّق «لا شيء يطابق بحثك» عن «لا
             * منتجات بعد». وكانت الصفحةُ تقول الثانيةَ لكلّ بحثٍ لم يُصب،
             * فيظنّ الزبونُ أنّ المحلّ فارغ وهو مليء.
             */
            'hasAny' => $shown->isNotEmpty(),
            'products' => $list->map(fn ($p) => $this->card($p, $lang, $business, $currency))->values()->all(),
        ];
    }

    /**
     * «من نحن» — نبذتُه بحروفها، وصورةٌ إن رفعها.
     *
     * والنصُّ هو الصفحة: ما لا نبذةَ فيه لا يصل هذه الدالّة أصلًا
     * (انظر `StoreNav::has`). والصورةُ زينةٌ تُترك فراغًا إن لم تكن.
     *
     * والرفُّ يُقرأ ليُعرف أيُرسم زرُّ «تسوّق الآن» تحتها: قاعدةُ الواجهة
     * نفسُها — لا وعدَ ببضاعةٍ على رفٍّ خالٍ.
     */
    private function about(Business $business, string $lang): array
    {
        $bid = (int) $business->id;
        $image = trim((string) (MarketingSettings::group($bid, 'website')['store_about_image'] ?? ''));

        return [
            // نبذةُ لغة الصفحة وحدها — `StorePage::about`
            'aboutText' => StorePage::about($bid, $lang),
            'aboutImage' => $image !== '' ? $image : null,
            'shelf' => $this->shown($bid)->exists(),
        ];
    }

    /**
     * «تواصل معنا» — ما كتبه في بيانات نشاطه ومتجره، لا حقولَ تُملأ مرّتين.
     *
     * ولا خريطةَ مُضمَّنة: تلك تحتاج مفتاحَ خرائط غوغل وثمنًا شهريًّا، وهي
     * إطارٌ ثقيل يُحمَّل في كلّ فتحة. والرابطُ يفتح خريطةَ الزائر التي يعرفها
     * على عنوان المحلّ — بلا مفتاحٍ ولا ثمن.
     *
     * ولا يُبنى إلّا على عنوانٍ مكتوب: رابطُ خرائطَ باسم المحلّ وحدَه يفتح
     * على نتيجةٍ في بلدٍ آخر.
     */
    private function contact(Business $business, string $lang): array
    {
        $bid = (int) $business->id;
        $lines = StoreNav::contactLines($bid, $lang);
        $address = $lines['address'] ?? '';

        return [
            'lines' => $lines,
            'mapUrl' => $address !== ''
                ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($business->name.'، '.$address)
                : null,
        ];
    }

    private function product(Business $business, int $id, string $lang): array
    {
        $bid = (int) $business->id;
        /*
         * والصورُ الإضافيّة تُحمَّل هنا وحدَها — الرئيسيّةُ والرفُّ والبحثُ
         * يعرضون صورةً واحدة ولا يسألون عنها. وبمعرّف المتجر مع معرّف
         * الصنف: صفٌّ في الجدول لمتجرٍ آخر لا يدخل معرضَ هذا.
         */
        $p = $this->shown($bid)->with([
            'category:id,name,name_en',
            'variants' => fn ($q) => $q->where('active', true)->orderBy('sort_order')->orderBy('id'),
            'images' => fn ($q) => $q->where('business_id', $bid),
        ])->find($id);
        abort_if($p === null, 404);

        $available = Shelf::availability($bid, [$p->id])[$p->id] ?? false;
        $currency = Storefront::currency($business);
        $giftCard = GiftCardProduct::is($bid, $p);

        return [
            /*
             * وصورةُ الصنف هي صورةُ مشاركته: من أرسل رابطَ باقةٍ إلى صديقه
             * يريده يرى الباقةَ لا واجهةَ المحلّ. وتُطلَق مطلقةً لأنّ واتساب
             * يقرؤها من خادمه لا من متصفّح القارئ.
             */
            'ogImage' => $p->image ? (str_starts_with((string) $p->image, 'http') ? $p->image : url($p->image)) : null,
            'product' => $this->card($p, $lang, $business, $currency) + [
                /*
                 * بلغة الصفحة، وحيث اختار صاحبُه — والمطفأُ فراغٌ فلا يُرسم سطرُه
                 * (`ProductDescription::online`). والقالبُ يهرّبه كأيّ نصّ.
                 */
                'description' => ProductDescription::online($p, $lang),
                'available' => $available,
                'sizes' => $this->sizes($p, $lang, $currency),
                'gallery' => $this->gallery($p),
                /*
                 * كرتُ الهدية صنفًا (`GiftCardProduct`) — تُعرض على صفحته
                 * خانةُ نصّه، ويُردّ في الخادم بلا نصّ.
                 */
                'gift_card' => $giftCard,
                'card_max' => GiftCardProduct::MAX,
                /*
                 * وملاحظةُ المنتج — لصنفٍ عاديّ حين تُفتح الميزة (`NotesAndEdits`).
                 * وصفحةُ الكرت خانتُها رسالتُه، فلا ملاحظةَ ثانية تحتها.
                 */
                'note_on' => ! $giftCard && NotesAndEdits::on($bid),
                'note_max' => NotesAndEdits::PRODUCT_NOTE_MAX,
            ],
            /*
             * وآراءُ الصنف — ما كتبه من اشتراه ونشره صاحبُ المحلّ (`ProductReviews`).
             * وتُسأل هنا وحدَها: الرفُّ والرئيسيّةُ لا يحملانها.
             */
            'reviews' => ProductReviews::for($bid, (int) $p->id),
            /*
             * وكتابةُ المبلغ كما يكتبه `Money::format` — لمجموع «أضف مع طلبك»
             * وسطور عدّاداته في الصفحة (`RBUpsells.money`). عرضٌ وحده:
             * الإتمامُ يسعّر من القاعدة.
             */
            'money' => [
                'd' => Money::decimals($currency),
                'symbol' => __((string) ($currency['symbol'] ?? '')),
                'before' => (bool) ($currency['before'] ?? false),
            ],
            /*
             * وملاحظةُ التوصيل تحت زرّ السلّة — إلّا لمن طلب تنبيهَ الصورة
             * وحده هناك (`storefront.ribbon_product_page_without_delivery_note_businesses`).
             */
            'showDeliveryNote' => ! in_array($bid, array_map('intval', (array) config('storefront.ribbon_product_page_without_delivery_note_businesses', [])), true),
            /*
             * «أضف مع طلبك» — لمن في قائمة المالك وحده (`RibbonUpsells`).
             *
             * وصنفٌ نفد لا زرَّ سلّةٍ له، فلا يُقترح معه شيء. والأسعارُ
             * للعرض: الإتمامُ يسعّر البنودَ من القاعدة كأيّ صنف.
             */
            /*
             * و«إضافات اختيارية» — إضافاتُ الصنف نفسِه التي فُتحت للموقع.
             *
             * غيرُ «أضف مع طلبك» أعلاه: تلك أصنافٌ مستقلّة تدخل السلّةَ بنودًا،
             * وهذه تُحمل على بند الصنف نفسِه (`order_item_addons`). فلا يُباع
             * شيءٌ مرّتين ولا يُحسب ثمنُه مرّتين. وما نفد صنفُه لا تُعرض معه.
             */
            'addons' => $available && ! $giftCard ? $this->addons($bid, $p, $lang, $currency) : [],
            // وصفحةُ الكرت نصُّه وحده — لا إضافاتٌ تُقترح معه
            'upsells' => $available && ! $giftCard
                ? RibbonUpsells::for($bid, $p, $this->shown($bid))
                    ->map(fn (Product $u) => $this->card($u, $lang, $business, $currency) + [
                        'sizes' => $this->sizes($u, $lang, $currency),
                        // وكرتُ الهدية إضافةً تُفتح له خانةُ نصّه — الحكمُ نفسُه الذي يسعّره
                        'gift_card' => GiftCardProduct::is($bid, $u),
                    ])
                    ->all()
                : [],
        ];
    }

    /**
     * إضافاتُ الصنف المفتوحةُ للموقع — بالقاعدة التي يأذن بها الخادمُ عند التسعير.
     *
     * `ProductAddons::for` ببابِ الموقع: ما أُغلق عن الموقع أو أُوقف أو لا
     * يخصّ هذا الصنف لا يُعرض. والسعرُ للعرض وحدَه — الإتمامُ يسعّر من القاعدة.
     * وما نفد رفُّه يُعرض معطَّلًا لا يُخفى: الزبونُ يعرف أنّها تُباع هنا.
     *
     * @param  array<string, mixed>  $currency
     * @return list<array{id: int, name: string, price: float, price_text: string, available: bool}>
     */
    private function addons(int $bid, Product $p, string $lang, array $currency): array
    {
        $addons = ProductAddons::for($p, null, null, SaleLines::WEBSITE);
        $stock = Shelf::availability($bid, $addons->pluck('inventory_product_id')->filter()->all());

        return $addons->map(fn (Addon $a) => [
            'id' => (int) $a->id,
            'name' => ProductName::display($a->name, $a->name_en, $lang),
            'price' => (float) $a->price,
            'price_text' => Money::format((float) $a->price, $currency),
            'available' => $a->inventory_product_id === null || ($stock[(int) $a->inventory_product_id] ?? false),
        ])->values()->all();
    }

    /**
     * صورُ صفحة الصنف — الرئيسيّةُ أوّلًا ثمّ الإضافيّةُ بترتيبها.
     *
     * والرئيسيّةُ في `products.image` لا في الجدول (`ProductImage`)، فتُضاف
     * أوّلًا بيدها. وصورةٌ مكرّرةٌ لا تُعرض مرّتين.
     *
     * @return list<string>
     */
    private function gallery(Product $p): array
    {
        $urls = array_merge([(string) $p->image], $p->images->map(fn ($i) => (string) $i->url)->all());

        return array_values(array_unique(array_filter($urls, fn ($u) => $u !== '')));
    }

    /** مقاساتُ الصنف المفعّلة — بأسمائها بلغة الصفحة وأسعارها من القاعدة */
    private function sizes(Product $p, string $lang, array $currency): array
    {
        return $p->variants->map(fn ($v) => [
            'id' => $v->id,
            'name' => $lang === 'en' && filled($v->name_en) ? $v->name_en : $v->name,
            'price' => (float) $v->price,
            'price_text' => Money::format((float) $v->price, $currency),
        ])->values()->all();
    }

    /**
     * موعدُ الطلب في صفحة الشكر — بلغة الزبون.
     *
     * طلبٌ اختار فترتَه من جدول المتجر يُكتب يومُه وفترتُه بلغة صفحته: اسمُ
     * الفترة بلغته إن بقيت في الجدول، وساعتاها من الطلب نفسِه — فتعديلُ الجدول
     * بعد الطلب لا يُبدّل ما يقرؤه. وطلبٌ بلا جدولٍ يُكتب كما كان.
     */
    private function when(Business $business, Order $order, string $lang): string
    {
        if ($order->delivery_slot === null || $order->scheduled_for === null || $order->scheduled_until === null) {
            return optional($order->scheduled_for)->format('Y-m-d').($order->delivery_notes ? ' · '.$order->delivery_notes : '');
        }

        $named = DeliverySchedule::lookup(
            (int) $business->id, (string) $order->fulfillment_type, $order->scheduled_for->toDateString(), $order->delivery_slot,
        );

        return ShopClock::day($order->scheduled_for, $lang).' · '.DeliverySchedule::label([
            'from' => $order->scheduled_for->format('H:i'),
            'to' => $order->scheduled_until->format('H:i'),
            'ar' => $named['ar'] ?? '',
            'en' => $named['en'] ?? '',
        ], $lang);
    }

    private function checkoutData(Business $business, string $lang): array
    {
        $bid = (int) $business->id;
        $s = WebCheckout::settings($bid);
        $currency = Storefront::currency($business);
        // ومن رسالتُه مجّانيّة لا يصل الشاشةَ ثمنٌ يُكتب — ولو سُعّر كرتُه
        $messageOnly = GiftCard::messageOnly($bid);
        $asProduct = GiftCardProduct::on($bid);
        $cardPrice = $messageOnly ? null : GiftCard::price($bid);

        return [
            'delivery' => $s,
            'payments' => array_keys(WebCheckout::payments($bid)),
            'accepts' => WebCheckout::accepts($business),
            'minDate' => today()->toDateString(),
            'maxDate' => today()->addDays(CheckoutFields::maxDays($bid))->toDateString(),
            /*
             * وأيّامُ التسليم وفتراتُها من جدول المتجر — المتاحُ وحدَه، بلغة الصفحة،
             * لكلّ طريقة استلام. و`null` لمتجرٍ بلا جدول: يُرسم حقلُ التاريخ وسطورُ
             * الفترات كما كانت. انظر `DeliverySchedule`.
             */
            'schedule' => DeliverySchedule::on($bid)
                ? DeliverySchedule::forPage($bid, CheckoutFields::fulfilments($bid), $lang)
                : null,
            // وما يقوله صاحبُ المتجر حين لا موعدَ للتوصيل — بلغة الصفحة، أو `null` لنصّ النظام
            'noDeliverySays' => DeliverySchedule::on($bid) ? DeliverySchedule::unavailableSays($bid, $lang) : null,
            /*
             * والشروطُ بلغة الصفحة وحدها، ورقمُ نسختها — أو `null` حيث لا تُشترط.
             * انظر `StoreTerms`.
             */
            'terms' => StoreTerms::forPage($bid, $lang),
            /*
             * وما انتقاه صاحبُ المحلّ من حقول — تقرؤه الشاشةُ كما يقرؤه
             * الخادم، من موضعٍ واحد (`Store\CheckoutFields`).
             */
            'fields' => CheckoutFields::all($bid),
            'fulfilments' => CheckoutFields::fulfilments($bid),
            // الاسمان والعنوانُ بالإنجليزيّة — والخادمُ يحرسها (`EnglishCheckout`)
            'englishOnly' => EnglishCheckout::on($bid),
            // وملاحظاتُ الطلب — خانةٌ حرّةٌ بالإنجليزيّة لمن فُتحت له الميزة (`NotesAndEdits`)
            'orderNotes' => ['on' => NotesAndEdits::on($bid), 'max' => NotesAndEdits::ORDER_NOTES_MAX],
            /*
             * وميزةُ الإهداء — لمن فتحها له مديرُ المنصّة (`GiftOrders::on`).
             * والمناسباتُ الثابتةُ بلغة الصفحة؛ والخادمُ يحرس ما يُرسَل.
             */
            'giftOrder' => [
                'on' => GiftOrders::on($bid),
                'occasions' => GiftOrders::occasionOptions(),
                'occasion_max' => FlowerOrder::CUSTOM_LABEL_MAX,
            ],
            /*
             * و«لا تذكر اسمي» ورسالةُ البطاقة — تسأل الصفحةُ إن قال الخادمُ إنّ
             * الرسالةَ تحمل اسمَه أو رقمَه، وتذكّره إن أرفق ملفًّا. لمتجرٍ فُتح
             * له الطرحُ وحده (`CardIdentity::on`).
             */
            'cardConsent' => [
                'on' => CardIdentity::on($bid),
                'max' => GiftCardProduct::MAX,
            ],
            /*
             * وكرتُ الهدية: أيُعرض، وبكم، وما يُقبل رفعه معه.
             *
             * والثمنُ يُبنى هنا لا في المتصفّح: عملةُ المحلّ وخاناتُها تُقرأ
             * من إعداده، وحسبةٌ في الشاشة تكتب «0.5 ر.ع» حيث يكتب النظامُ
             * كلُّه «٠٫٥٠٠ ر.ع».
             *
             * ومتجرٌ لم يُسعّر كرتَه لا `on` له — و`enabled` تسأل عن الثمن مع
             * المفتاح، فالصفرُ هنا ثمنٌ لا يُقرأ لا ثمنٌ يُعرض.
             */
            'giftCard' => [
                'on' => GiftCard::enabled($bid),
                /*
                 * رسالةٌ بلا ثمن بدل الكرت المدفوع (`GiftCard::messageOnly`):
                 * خانةٌ ونصٌّ فقط — لا ثمنَ ولا ملفَّ ولا إعادةَ تسعير.
                 */
                'message_only' => $messageOnly,
                // وكرتُه صنفٌ من الرفّ: لا شيءَ منه في الإتمام (`GiftCardProduct`)
                'as_product' => $asProduct,
                'price' => $cardPrice ?? 0.0,
                'price_text' => $cardPrice === null ? '' : Money::format($cardPrice, $currency),
                'accept' => '.'.implode(',.', GiftCard::MIMES),
                'max_kb' => GiftCard::MAX_KB,
                'aligns' => GiftCard::ALIGNS,
            ],
        ];
    }

    /**
     * صفحةُ التأكيد — ومفتاحُها رمزٌ في الرابط لا رقمُ الطلب.
     *
     * و`$trusted` لمن وصل من صفحة العودة بعد الدفع: المرجعُ هناك أُثبت
     * بالإشعار الموقَّع، فقد فُتح البابُ بمفتاحٍ أقوى من هذا. ولا تُقرأ
     * إلّا من الكود — لا من الطلب، وإلّا صارت بابًا يُفتح بالرقم.
     */
    private function done(Business $business, int $id, string $lang, bool $trusted = false): array
    {
        $order = Order::where('business_id', $business->id)->with('items')->find($id);
        abort_if($order === null, 404);
        abort_unless($trusted || hash_equals(WebCheckout::token($order), (string) request()->query('t', '')), 404);

        $currency = Storefront::currency($business);
        $s = WebCheckout::settings((int) $business->id);
        $t = RibbonTexts::for($lang);

        return [
            'order' => [
                'number' => $order->number,
                'fulfil' => $order->fulfillment_type === FlowerOrder::PICKUP ? $t['pickup'] : $t['delivery'],
                'date' => $this->when($business, $order, $lang),
                /*
                 * وطريقةُ الدفع كما كُتبت على الطلب — ثلاثٌ لا اثنتان.
                 *
                 * كُتب هذا قبل البطاقة: التحويلُ تحويلٌ وما سواه «عند
                 * الاستلام». فلمّا جاءت Paymob صار زبونٌ دفع ببطاقته يُقال
                 * له في صفحة الشكر «الدفع عند الاستلام» — فيظنّ أنّ ماله لم
                 * يُقبض، أو يُطالَب به مرّتين عند التسليم.
                 */
                'pay' => match ($order->payment_method) {
                    PaymentMethods::TRANSFER => $t['payBank'],
                    PaymentMethods::CARD => $t['payCard'],
                    default => $t['payCod'],
                },
                'transfer' => $order->payment_method === PaymentMethods::TRANSFER,
                /*
                 * وإيصالُ الدفع — لمن دفع دفعًا ثبت ومتجرُه فتحه (`PaidReceipt`).
                 *
                 * والرابطُ يحمل رمزَ الطلب نفسَه: يُبنى هنا لمن فتح هذه الصفحة
                 * برمزها أو عاد من البوّابة بمرجعٍ ثبت. ولا يُقرأ للدفع شيءٌ من
                 * رابط هذه الصفحة.
                 */
                'receipt' => PaidReceipt::offered($order)
                    ? '/receipt/'.$order->id.'?t='.WebCheckout::token($order)
                    : null,
                'total' => Money::format((float) $order->total, $currency),
                'lines' => $order->items->map(fn ($i) => [
                    'name' => $i->displayName(),
                    'qty' => (int) $i->quantity,
                    'line' => Money::format($i->lineTotal(), $currency),
                ])->all(),
            ],
            'bank' => $s['bank'],
            /*
             * ونصوصُ الصفحة بلغتها — ما كتبه التاجر أو نصُّ النظام (`ThankYouPage`).
             * والطلبُ وأرقامُه أعلاه من الطلب نفسِه، لا من نصٍّ يُكتب.
             */
            'thanks' => ThankYouPage::texts((int) $business->id, $lang),
        ];
    }

    /**
     * إيصالُ الدفع الحراريّ — ملفُّ PDF يفتحه الزبونُ من صفحة الشكر.
     *
     * ═══ ثلاثةُ أقفالٍ قبل أن يُرسم شيء ═══
     *
     *   ١) الطلبُ من **هذا المتجر** — المتجرُ عُرف من العنوان لا من الرابط،
     *      فرقمُ طلبٍ لمتجرٍ آخر لا يوجد هنا أصلًا.
     *   ٢) **رمزُ الطلب** (`WebCheckout::token`) يطابق، بمقارنةٍ لا تُقاس
     *      بالوقت. فرقمُ الطلب وحده لا يفتح شيئًا.
     *   ٣) **دفعٌ ثبت** ومتجرٌ فتح الإيصال (`PaidReceipt::offered`) — والدفعُ
     *      من نيّةٍ ثبّتها الإشعارُ الموقَّع، لا من شيءٍ في الرابط.
     *
     * وكلُّ رفضٍ ٤٠٤ لا ٤٠٣: لا يُقال لمن يجرّب الأرقامَ أيُّها موجود.
     *
     * والورقةُ ورقةُ الصندوق نفسُها (`PdfController::thermal`) لا تُكتب هنا.
     */
    private function receipt(Business $business, string $id): Response
    {
        abort_unless(ctype_digit($id), 404);

        $order = Order::where('business_id', $business->id)->with('items.addons')->find((int) $id);
        abort_if($order === null, 404);
        abort_unless(hash_equals(WebCheckout::token($order), (string) request()->query('t', '')), 404);
        abort_unless(PaidReceipt::offered($order), 404);

        return PdfController::thermal((int) $business->id, $order)
            ->header('Cache-Control', 'no-store, private')
            // ورقةُ زبونٍ برمزه — لا تُفهرس ولو وصل رابطُها إلى محرّك بحث
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /* ═══════════ أدوات ═══════════ */

    private function context(Business $business, string $base, string $lang, string $current = StoreNav::HOME, ?array $here = null): array
    {
        $bid = (int) $business->id;
        $identity = MerchantData::identity($bid);
        /*
         * والنبذةُ بلغة الصفحة — قسمُ «عنّا» والتذييلُ ووصفُ البحث المحسوب
         * يقرؤونها من هنا. وفارغُ اللغة لا تقع عليه نبذةُ الأخرى.
         */
        $identity['about'] = StorePage::about($bid, $lang);
        $s = WebCheckout::settings($bid);
        $t = RibbonTexts::for($lang);
        $nav = StoreNav::links($bid, $base, $t, $current, $lang, $here);
        $catsNav = $this->categories($bid, $lang, null)->take(6)->all();

        return [
            'business' => $business,
            'base' => $base,
            'lang' => $lang,
            'dir' => $lang === 'en' ? 'ltr' : 'rtl',
            't' => $t,
            'logo' => $business->logo ?: null,
            'currency' => Storefront::currency($business),
            'identity' => $identity,
            // ساعاتُ العمل بلغة الصفحة — التذييلُ وسطرُ الاستلام في الإتمام
            'hours' => StorePage::hours($bid, $lang),
            // وملاحظةُ التوصيل بلغة الصفحة — وفارغُ اللغة لا يُرسم ولا يقع على الأخرى
            'deliveryNote' => $lang === 'en' ? $s['note_en'] : $s['note'],
            /*
             * تنبيهُ الصورة بلغة الصفحة — `store_image_note` للعربيّة و
             * `store_image_note_en` للإنجليزيّة. وفارغُ اللغة لا يُرسم، ولا
             * يقع على الأخرى فتختلط اللغتان.
             */
            'imageNote' => $lang === 'en' ? $s['image_note_en'] : $s['image_note'],
            // وتنبيهُ نطاق التوصيل بلغة الصفحة — بلا وقوعٍ على الأخرى
            'deliveryAreaNote' => $lang === 'en' ? $s['area_note_en'] : $s['area_note'],
            'accepts' => $accepts = WebCheckout::accepts($business),
            /*
             * وما يُقال حين لا يُستقبل طلب — رسالةُ صاحب المتجر بلغة الصفحة إن
             * كان السببُ إغلاقَه (`OrderWindow::message`)، وإلّا نصُّ النظام.
             * تقرؤه السلّةُ وصفحةُ الإتمام معًا.
             */
            'closedSays' => $accepts ? '' : (OrderWindow::open($bid) ? $t['closed'] : OrderWindow::message($bid, $lang)),

            'analytics' => Seo::tagFor($bid),
            'catsNav' => $catsNav,
            // وسطرُ التذييل في كلّ صفحة — فهو في القالب العامّ لا في الرئيسية
            'tagline' => StorePage::tagline($bid, $lang),
            /*
             * وقائمةُ الصفحات — في الترويسة والتذييل معًا، ومن مصدرٍ واحد.
             *
             * ولو بُنيت في القالبين لَبقي في أحدهما رابطٌ إلى صفحةٍ أُطفئت.
             */
            'nav' => $nav,
            /*
             * والتذييلُ كما رتّبه صاحبُه — أعمدتُه وعناوينُها وما يظهر فيها،
             * من القائمة والفئات والمجموعات نفسِها. والفراغُ التذييلُ القائم.
             * انظر `StoreFooter`.
             */
            'footer' => StoreFooter::view(
                StoreFooter::settings((string) (MarketingSettings::group($bid, 'website')[StoreFooter::KEY] ?? '')),
                $lang, $t, $base, $nav, $catsNav, StoreCollections::visible($bid), (string) $business->name,
            ),
            // والقائمةُ التي كتبها صاحبُها سطرٌ يُمرَّر — والقديمةُ كما كانت (`layout`)
            'navScroll' => StoreNav::customized($bid),
            /*
             * وصورُ المنتجات والفئات «كاملة» أو «ملء الإطار» — صنفٌ على جذر الصفحة
             * تقرؤه البطاقاتُ وصفحةُ المنتج والسلّة معًا. والفراغُ «ملء الإطار».
             */
            'catalogFit' => StoreMedia::fit(MarketingSettings::group($bid, 'website')['store_catalog_fit'] ?? null),
            /*
             * وشريطُ الإعلان أعلى كلّ صفحة — بلغة الزائر وحدها، أو لا شريط.
             * انظر `StoreHeader::announcement`.
             */
            'announcement' => StoreHeader::announcement($bid, $lang),
            /*
             * وشريطُ المجموعات المصوّر فوق الترويسة — لصفحات التصفّح، بلغة
             * الزائر، لمن شغّله ونشره. وفارغٌ لمن سواه فلا يُرسم شيء.
             * انظر `StoreVisualBar::cards`.
             */
            'visualBar' => StoreVisualBar::cards($bid, $lang, $base, $current),
            // وما يقرؤه غوغل — عنوانُ الصفحة ووصفُها وإذنُ الفهرسة
            'seo' => StoreSeo::head($business, $current, $t, $identity, $base, request()->getPathInfo(), $lang),
        ] + $this->floatingWhatsapp($bid, $identity, $t);
    }

    /**
     * زرُّ واتساب العائم — في القالب العامّ، فيظهر في كلّ صفحة.
     *
     * لمن في `storefront.ribbon_floating_whatsapp_businesses` وحده، وبرقم
     * «بيانات المتجر» نفسِه الذي يحمله رابطُ التذييل — أرقامًا وحدها كما
     * ينظّفه التذييل. ومعه رسالةٌ جاهزةٌ بلغة الصفحة. وبلا رقمٍ لا زرّ.
     *
     * @return array{showFloatingWhatsapp: bool, floatingWhatsappUrl: string|null}
     */
    private function floatingWhatsapp(int $bid, array $identity, array $t): array
    {
        $listed = in_array($bid, array_map('intval', (array) config('storefront.ribbon_floating_whatsapp_businesses', [])), true);
        $digits = preg_replace('/\D/', '', (string) ($identity['whatsapp'] ?? ''));

        if (! $listed || $digits === '') {
            return ['showFloatingWhatsapp' => false, 'floatingWhatsappUrl' => null];
        }

        return [
            'showFloatingWhatsapp' => true,
            'floatingWhatsappUrl' => 'https://wa.me/'.$digits.'?text='.rawurlencode($t['waFloatText']),
        ];
    }

    /**
     * لغةُ الزائر — من الرابط أوّلًا، ثمّ ممّا اختاره قبلُ.
     *
     * وتُضبط لغةُ التطبيق معها: رسائلُ الرفض في الخادم (`__()`) تخرج
     * بلغة الزائر لا بلغة النظام، فلا يقرأ زبونٌ إنجليزيٌّ خطأً عربيًّا.
     */
    private function lang(): string
    {
        $q = request()->query('lang');
        $lang = in_array($q, ['ar', 'en'], true) ? $q : (request()->cookie('rb_lang') === 'en' ? 'en' : 'ar');
        app()->setLocale($lang);

        return $lang;
    }

    /**
     * عاد الزائرُ من بوّابة الدفع — فيُقرأ ما كتبه الإشعار.
     *
     * ولا يُقرأ شيءٌ ممّا في الرابط: هو في يد الزائر، ومن كتب فيه «نجح»
     * بيده لا يُصدَّق. والمرجعُ وحده يُؤخذ منه — واسمٌ عشوائيٌّ لا يُخمَّن.
     *
     * وقد تسبق عودتُه الإشعارَ بثوانٍ، فيُقال له «نؤكّد دفعتك» ولا يُدَّعى
     * فشلٌ لم يقع: صفحةٌ تقول «فشل» على مالٍ خرج من حسابه أسوأُ من انتظار.
     */
    private function paying(Business $business, string $reference, array $ctx): Response
    {
        $intent = StorePaymentIntent::where('business_id', $business->id)
            ->where('reference', $reference)->firstOrFail();

        if ($intent->order_id !== null) {
            $order = Order::find($intent->order_id);

            if ($order !== null) {
                return $this->render(
                    'store.ribbon.done',
                    $ctx + $this->done($business, (int) $order->id, $ctx['lang'], trusted: true),
                );
            }
        }

        return $this->render('store.ribbon.paying', $ctx + ['paid' => $intent->status === StorePaymentIntent::PAID]);
    }

    private function render(string $view, array $data): Response
    {
        $res = response()->view($view, $data)->header('Cache-Control', 'no-store');

        if (in_array(request()->query('lang'), ['ar', 'en'], true)) {
            $res->cookie('rb_lang', request()->query('lang'), 60 * 24 * 365, null, null, null, false);
        }

        return $res;
    }

    private function shown(int $bid)
    {
        return Product::where('business_id', $bid)->where('active', true)->where('published', true);
    }

    /**
     * الأقسامُ التي فيها صنفٌ معروض — قسمٌ فارغ لا يُرسم.
     *
     * واسمُ القسم من `CategoryName` — قاعدةُ الصندوق والموقع المبنيّ نفسُها:
     * كانت هنا نسخةٌ تقع على العربيّ متى فرغ `name_en`، فتعرض الصفحةُ
     * الإنجليزيّة «منتجات» و«الإضافات» وسطَ واجهةٍ إنجليزيّة، والمعجمُ
     * يعرفهما يقينًا. والمعرّفُ كما هو: به يُرشَّح الرفّ.
     *
     * وصورةُ البطاقة وإخفاؤها يُحملان ولا يُطبَّقان هنا: الرئيسيّةُ وحدها
     * تُسقط المخفيّ (`home`)، والرفُّ والتذييلُ يُبقيانه.
     *
     * والترتيبُ ترتيبُ صاحب المحلّ (`Category::scopeInStoreOrder`) — وقبل أن
     * يرتّب، الأبجديّةُ كما كانت. فالبطاقاتُ وروابطُ التذييل بترتيبٍ واحد.
     */
    private function categories(int $bid, string $lang, $shown)
    {
        $shown ??= $this->shown($bid)->get(['id', 'category_id']);
        $counts = $shown->groupBy('category_id')->map->count();

        return Category::where('business_id', $bid)->inStoreOrder()->get()
            ->filter(fn ($c) => (int) ($counts[$c->id] ?? 0) > 0)
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => CategoryName::display($c->name, $c->name_en, $lang),
                'count' => (int) $counts[$c->id],
                'image' => filled($c->card_image) ? (string) $c->card_image : null,
                'hidden' => (bool) $c->card_hidden,
            ])
            ->values();
    }

    /**
     * بطاقةُ صنفٍ في الرفّ.
     *
     * ═══ والعملةُ تُمرَّر ولا تُقرأ هنا ═══
     *
     * كانت `Storefront::currency` تُنادى داخل كلّ بطاقة، وهي استعلامان:
     * جدولُ العملات وجدولُ الإعدادات. فرفٌّ بمئة صنفٍ يسأل القاعدةَ مئتي
     * سؤالٍ زائدٍ عن عملةٍ واحدةٍ لا تتبدّل بين صنفٍ وآخر — وقد قِيسَ:
     * ثلاثة عشر تكرارًا لكلٍّ منهما في صفحة رفٍّ باثني عشر صنفًا.
     *
     * والجوابُ محسوبٌ في السياق أصلًا (`context`) فيُمرَّر.
     */
    private function card(Product $p, string $lang, Business $business, ?array $currency = null): array
    {
        $currency ??= Storefront::currency($business);
        $prices = $p->variants->where('active', true)->pluck('price')->map(fn ($v) => (float) $v);
        $price = $prices->isEmpty() ? $p->sellingPrice() : (float) $prices->min();

        return [
            'id' => $p->id,
            'name' => ProductName::display($p->name, $p->name_en, $lang),
            // والقاعدةُ نفسُها التي يُسمّى بها القسمُ في المرشّح — فلا يفترق اسماه
            'category' => $p->category ? CategoryName::display($p->category->name, $p->category->name_en, $lang) : null,
            'category_id' => $p->category_id,
            'image' => $p->image,
            'price' => $price,
            'price_text' => Money::format($price, $currency),
            'from' => $prices->count() > 1 && $prices->min() !== $prices->max(),
            'tint' => self::TINTS[$p->id % count(self::TINTS)],
        ];
    }

    /** ألوانُ التصميم لبطاقةٍ بلا صورة — من الملفّ نفسِه */
    private const TINTS = ['#f2d6dc', '#e8c4c8', '#e6dcc8', '#d9e3e0', '#e3d5e4', '#f5e1d7', '#ece8dc', '#dcd3c6'];

    private function publicQuote(Business $business, array $q): array
    {
        $currency = Storefront::currency($business);
        $lang = $this->lang();
        $m = fn (float $v) => Money::format($v, $currency);

        return [
            'lines' => array_map(fn ($l) => [
                'id' => $l['id'], 'variant_id' => $l['variant_id'],
                'name' => ProductName::display($l['name'], $l['name_en'], $lang),
                'variant' => $lang === 'en' && filled($l['variant_en']) ? $l['variant_en'] : $l['variant'],
                'image' => $l['image'], 'qty' => $l['qty'], 'price' => $l['price'],
                'price_text' => $m($l['price']), 'line_text' => $m($l['line']),
                'gift_card' => $l['gift_card'], 'note' => $l['note'],
                // وإضافاتُه بأسمائها في لغة الصفحة — تُرسم تحته وتُعرف بها هويّتُه في السلّة
                'addons' => array_map(fn ($a) => [
                    'id' => $a['id'], 'qty' => $a['qty'],
                    'name' => ProductName::display($a['name'], $a['name_en'], $lang),
                    'total_text' => $m($a['total']),
                ], $l['addons'] ?? []),
            ], $q['lines']),
            'subtotal' => $q['subtotal'], 'subtotal_text' => $m($q['subtotal']),
            'discount' => $q['discount'], 'discount_text' => $m($q['discount']),
            'delivery' => $q['delivery'], 'delivery_text' => $m($q['delivery']),
            'tax' => $q['tax'], 'tax_text' => $m($q['tax']),
            'total' => $q['total'], 'total_text' => $m($q['total']),
            'coupon' => $q['coupon'], 'promo_error' => $q['promo_error'],
            'free_over' => $q['free_over'], 'fulfil' => $q['fulfil'],
        ];
    }
}
