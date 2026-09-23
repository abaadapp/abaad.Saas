<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Support\FlowerOrder;
use App\Support\Money;
use App\Support\Seo;
use App\Support\Store\RibbonTexts;
use App\Support\Store\GiftCard;
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
    private const PATHS = ['shop', 'cart', 'checkout', 'p', 'done'];

    /* ═══════════ الصفحات ═══════════ */

    public function page(Business $business, ?string $path, string $base): Response
    {
        $lang = $this->lang();
        [$first, $second] = array_pad(explode('/', trim((string) $path, '/'), 2), 2, null);
        $first = $first === '' ? null : $first;

        if ($first !== null && ! in_array($first, self::PATHS, true)) {
            abort(404);
        }

        $ctx = $this->context($business, $base, $lang);

        return match ($first) {
            null => $this->render('store.ribbon.home', $ctx + $this->home($business, $lang)),
            'shop' => $this->render('store.ribbon.shop', $ctx + $this->shop($business, $lang, request())),
            'p' => $this->render('store.ribbon.product', $ctx + $this->product($business, (int) $second, $lang)),
            'cart' => $this->render('store.ribbon.cart', $ctx),
            'checkout' => $this->render('store.ribbon.checkout', $ctx + $this->checkoutData($business)),
            'done' => $this->render('store.ribbon.done', $ctx + $this->done($business, (int) $second, $lang)),
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
            $order = WebCheckout::place($business, $request->all(), $this->lang());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
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

        $best = $shown->sortByDesc(fn ($p) => [(int) ($sold[$p->id] ?? 0), $p->id])->take(4)->values();
        $new = $shown->sortByDesc('id')->take(4)->values();

        return [
            'categories' => $this->categories($bid, $lang, $shown),
            'best' => $best->map(fn ($p) => $this->card($p, $lang, $business))->all(),
            'new' => $new->map(fn ($p) => $this->card($p, $lang, $business))->all(),
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

        $list = $shown
            ->when($cat > 0, fn ($c) => $c->where('category_id', $cat))
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => mb_stripos($p->name.' '.$p->name_en, $q) !== false));

        return [
            'categories' => $this->categories($bid, $lang, $shown),
            'cat' => $cat,
            'q' => $q,
            'products' => $list->map(fn ($p) => $this->card($p, $lang, $business))->values()->all(),
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
            'product' => $this->card($p, $lang, $business) + [
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
            'maxDate' => today()->addDays(WebCheckout::MAX_DAYS_AHEAD)->toDateString(),
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

    private function done(Business $business, int $id, string $lang): array
    {
        $order = Order::where('business_id', $business->id)->with('items')->find($id);
        abort_if($order === null || ! hash_equals(WebCheckout::token($order), (string) request()->query('t', '')), 404);

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

    private function context(Business $business, string $base, string $lang): array
    {
        $bid = (int) $business->id;
        $identity = MerchantData::identity($bid);
        $s = WebCheckout::settings($bid);

        return [
            'business' => $business,
            'base' => $base,
            'lang' => $lang,
            'dir' => $lang === 'en' ? 'ltr' : 'rtl',
            't' => RibbonTexts::for($lang),
            'logo' => $business->logo ?: null,
            'currency' => Storefront::currency($business),
            'identity' => $identity,
            'hours' => $s['hours'],
            'deliveryNote' => $s['note'],
            'imageNote' => $s['image_note'],
            'accepts' => WebCheckout::accepts($business),
            'canonical' => Storefront::canonical($business->site_slug, $bid),
            'analytics' => Seo::tagFor($bid),
            'catsNav' => $this->categories($bid, $lang, null)->take(6)->all(),
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

    private function card(Product $p, string $lang, Business $business): array
    {
        $currency = Storefront::currency($business);
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
