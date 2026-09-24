<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StorePaymentIntent;
use App\Models\Review;
use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use App\Support\Money;
use App\Support\Seo;
use App\Support\Store\RibbonTexts;
use App\Support\Store\CheckoutFields;
use App\Support\Store\GiftCard;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreSeo;
use App\Support\Store\StorePage;
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
    private const PATHS = ['shop', 'about', 'contact', 'cart', 'checkout', 'p', 'done', 'paying'];

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
     * والصفحاتُ الثلاثُ التي تقبله تحتاجه: صنفٌ بمعرّفه، وتأكيدٌ برقم طلبه،
     * وعودةٌ من البوّابة بمرجعها.
     */
    private const TAKES_ID = ['p', 'done', 'paying'];

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
        if (in_array($first, StoreNav::OPTIONAL, true) && ! StoreNav::has((int) $business->id, $first)) {
            abort(404);
        }

        $ctx = $this->context($business, $base, $lang, $first ?? StoreNav::HOME);

        return match ($first) {
            null => $this->render('store.ribbon.home', $ctx + $this->home($business, $lang)),
            'shop' => $this->render('store.ribbon.shop', $ctx + $this->shop($business, $lang, request())),
            'about' => $this->render('store.ribbon.about', $ctx + $this->about($business)),
            'contact' => $this->render('store.ribbon.contact', $ctx + $this->contact($business)),
            'p' => $this->render('store.ribbon.product', $ctx + $this->product($business, (int) $second, $lang)),
            'cart' => $this->render('store.ribbon.cart', $ctx),
            'checkout' => $this->render('store.ribbon.checkout', $ctx + $this->checkoutData($business)),
            'done' => $this->render('store.ribbon.done', $ctx + $this->done($business, (int) $second, $lang)),
            'paying' => $this->paying($business, (string) $second, $ctx),
        };
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
         * الأكثرُ مبيعًا ممّا بيع فعلًا (بقاعدة `Order::sold`)، والواصلُ حديثًا
         * بتاريخ إضافته. ولا يُخترع ترتيب: متجرٌ لم يبع بعدُ يعرض أحدثَ أصنافه
         * في الموضعين.
         */
        $sold = OrderItem::query()
            ->whereIn('order_id', Order::where('business_id', $bid)->sold()->select('id'))
            ->whereIn('product_id', $shown->pluck('id'))
            ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as q')
            ->pluck('q', 'product_id');

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

        $new = $shown->sortByDesc('id')->take(4)->values();

        // عملةٌ واحدةٌ للرفّ كلِّه — انظر `card`
        $currency = Storefront::currency($business);

        return [
            'categories' => $this->categories($bid, $lang, $shown),
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
            'block' => StorePage::block($bid),
            // وعنوانُ «الأكثر مبيعًا» يتبع مصدرَه: محسوبًا يُسمّى، ومختارًا يُسمّى
            'bestPicked' => $chosen->isNotEmpty(),
            'reviews' => Review::where('business_id', $bid)->showable()->latest()->take(3)->get()
                ->map(fn ($r) => ['name' => $r->displayName(), 'text' => (string) $r->comment, 'rating' => (int) $r->rating])
                ->filter(fn ($r) => $r['text'] !== '')->values()->all(),
        ];
    }

    private function shop(Business $business, string $lang, Request $request): array
    {
        $bid = (int) $business->id;
        $cat = (int) $request->query('cat', 0);
        $q = trim((string) $request->query('q', ''));
        $shown = $this->shown($bid)->with(['category:id,name,name_en', 'variants'])->orderBy('name')->get();

        $currency = Storefront::currency($business);

        $list = $shown
            ->when($cat > 0, fn ($c) => $c->where('category_id', $cat))
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => mb_stripos($p->name.' '.$p->name_en, $q) !== false));

        return [
            'categories' => $this->categories($bid, $lang, $shown),
            'cat' => $cat,
            'q' => $q,
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
    private function about(Business $business): array
    {
        $bid = (int) $business->id;
        $image = trim((string) (MarketingSettings::group($bid, 'website')['store_about_image'] ?? ''));

        return [
            'aboutText' => MerchantData::identity($bid)['about'],
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
    private function contact(Business $business): array
    {
        $bid = (int) $business->id;
        $lines = StoreNav::contactLines($bid);
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
        $p = $this->shown($bid)->with(['category:id,name,name_en', 'variants' => fn ($q) => $q->where('active', true)->orderBy('sort_order')->orderBy('id')])->find($id);
        abort_if($p === null, 404);

        $available = Shelf::availability($bid, [$p->id])[$p->id] ?? false;
        $currency = Storefront::currency($business);

        return [
            /*
             * وصورةُ الصنف هي صورةُ مشاركته: من أرسل رابطَ باقةٍ إلى صديقه
             * يريده يرى الباقةَ لا واجهةَ المحلّ. وتُطلَق مطلقةً لأنّ واتساب
             * يقرؤها من خادمه لا من متصفّح القارئ.
             */
            'ogImage' => $p->image ? (str_starts_with((string) $p->image, 'http') ? $p->image : url($p->image)) : null,
            'product' => $this->card($p, $lang, $business, $currency) + [
                'description' => (string) $p->description,
                'available' => $available,
                'sizes' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $lang === 'en' && filled($v->name_en) ? $v->name_en : $v->name,
                    'price' => (float) $v->price,
                    'price_text' => Money::format((float) $v->price, $currency),
                ])->values()->all(),
            ],
        ];
    }

    private function checkoutData(Business $business): array
    {
        $bid = (int) $business->id;
        $s = WebCheckout::settings($bid);
        $currency = Storefront::currency($business);

        return [
            'delivery' => $s,
            'payments' => array_keys(WebCheckout::payments($bid)),
            'accepts' => WebCheckout::accepts($business),
            'minDate' => today()->toDateString(),
            'maxDate' => today()->addDays(CheckoutFields::maxDays($bid))->toDateString(),
            /*
             * وما انتقاه صاحبُ المحلّ من حقول — تقرؤه الشاشةُ كما يقرؤه
             * الخادم، من موضعٍ واحد (`Store\CheckoutFields`).
             */
            'fields' => CheckoutFields::all($bid),
            'fulfilments' => CheckoutFields::fulfilments($bid),
            /*
             * وكرتُ الهدية: أيُعرض، وبكم، وما يُقبل رفعه معه.
             *
             * والثمنُ يُبنى هنا لا في المتصفّح: عملةُ المحلّ وخاناتُها تُقرأ
             * من إعداده، وحسبةٌ في الشاشة تكتب «0.5 ر.ع» حيث يكتب النظامُ
             * كلُّه «٠٫٥٠٠ ر.ع».
             */
            'giftCard' => [
                'on' => GiftCard::enabled($bid),
                'price' => GiftCard::price($bid),
                'price_text' => Money::format(GiftCard::price($bid), $currency),
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
                'date' => optional($order->scheduled_for)->format('Y-m-d').($order->delivery_notes ? ' · '.$order->delivery_notes : ''),
                'pay' => $order->payment_method === 'تحويل بنكي' ? $t['payBank'] : $t['payCod'],
                'transfer' => $order->payment_method === 'تحويل بنكي',
                'total' => Money::format((float) $order->total, $currency),
                'lines' => $order->items->map(fn ($i) => [
                    'name' => $i->displayName(),
                    'qty' => (int) $i->quantity,
                    'line' => Money::format($i->lineTotal(), $currency),
                ])->all(),
            ],
            'bank' => $s['bank'],
        ];
    }

    /* ═══════════ أدوات ═══════════ */

    private function context(Business $business, string $base, string $lang, string $current = StoreNav::HOME): array
    {
        $bid = (int) $business->id;
        $identity = MerchantData::identity($bid);
        $s = WebCheckout::settings($bid);
        $t = RibbonTexts::for($lang);

        return [
            'business' => $business,
            'base' => $base,
            'lang' => $lang,
            'dir' => $lang === 'en' ? 'ltr' : 'rtl',
            't' => $t,
            'logo' => $business->logo ?: null,
            'currency' => Storefront::currency($business),
            'identity' => $identity,
            'hours' => $s['hours'],
            'deliveryNote' => $s['note'],
            'imageNote' => $s['image_note'],
            'accepts' => WebCheckout::accepts($business),

            'analytics' => Seo::tagFor($bid),
            'catsNav' => $this->categories($bid, $lang, null)->take(6)->all(),
            // وسطرُ التذييل في كلّ صفحة — فهو في القالب العامّ لا في الرئيسية
            'tagline' => StorePage::tagline($bid),
            /*
             * وقائمةُ الصفحات — في الترويسة والتذييل معًا، ومن مصدرٍ واحد.
             *
             * ولو بُنيت في القالبين لَبقي في أحدهما رابطٌ إلى صفحةٍ أُطفئت.
             */
            'nav' => StoreNav::links($bid, $base, $t, $current),
            // وما يقرؤه غوغل — عنوانُ الصفحة ووصفُها وإذنُ الفهرسة
            'seo' => StoreSeo::head($business, $current, $t, $identity, $base, request()->getPathInfo()),
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

    /** الأقسامُ التي فيها صنفٌ معروض — قسمٌ فارغ لا يُرسم */
    private function categories(int $bid, string $lang, $shown)
    {
        $shown ??= $this->shown($bid)->get(['id', 'category_id']);
        $counts = $shown->groupBy('category_id')->map->count();

        return Category::where('business_id', $bid)->orderBy('name')->get()
            ->filter(fn ($c) => (int) ($counts[$c->id] ?? 0) > 0)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $lang === 'en' && filled($c->name_en) ? $c->name_en : $c->name, 'count' => (int) $counts[$c->id]])
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
            'name' => $lang === 'en' && filled($p->name_en) ? $p->name_en : $p->name,
            'category' => $p->category ? ($lang === 'en' && filled($p->category->name_en) ? $p->category->name_en : $p->category->name) : null,
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
                'name' => $lang === 'en' && filled($l['name_en']) ? $l['name_en'] : $l['name'],
                'variant' => $lang === 'en' && filled($l['variant_en']) ? $l['variant_en'] : $l['variant'],
                'image' => $l['image'], 'qty' => $l['qty'], 'price' => $l['price'],
                'price_text' => $m($l['price']), 'line_text' => $m($l['line']),
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
