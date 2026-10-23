<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Business;
use App\Models\Product;
use App\Support\Activity;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Vat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الإضافات من مكانٍ واحد — لكلّ متجر، بوتيكاتٌ عنده أو لا.
 *
 * كانت تُدار من نموذج المنتج وحده: شرائحُ «إضافات مع كلّ المنتجات» تُفتح
 * منها نافذتُها. فلا قائمةَ تقول أيّ الإضافات موقوفة، ولا أيّها من رفّ
 * بوتيك، ولا أين تُباع — إلّا بفتح كلّ واحدةٍ على حدة من منتجٍ ما.
 *
 * ولا نظامَ ثانٍ هنا: الصفوفُ صفوفُ `addons`، والحفظُ بابُه القائم
 * (`CatalogQuickAddController::storeAddon`/`updateAddon`) بقواعده كلّها —
 * المدى، والقناتان، والمخزون، وردُّ صنف البوتيك ما لم يُفتح سجلُّه. وما
 * يُضاف هنا ثلاثةُ أبوابٍ صغيرة لا تمسّ غيرَ عمودها أو صفّها:
 *
 *   - `active`: الإيقافُ والتفعيل. وكان حفظُ الإضافة كاملةً بابَه الوحيد،
 *     وحفظٌ بلا مداها يقرؤه الخادم «مع الجميع» فيمحو منتجاتها.
 *   - `link`/`unlink`: منتجٌ واحدٌ يدخل مدى إضافةٍ مخصّصة أو يخرج منه — من
 *     شاشة المنتج، دون أن تُرسَل قائمةُ منتجاتها كلُّها.
 *
 * والمتجرُ متجرُ من سجّل الدخول دائمًا: إضافةٌ أو منتجٌ من متجرٍ آخر ٤٠٤.
 */
class AddonController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(): Response
    {
        $bid = $this->bid();
        $business = Business::find($bid);

        $owners = Product::where('business_id', $bid)
            ->whereIn('id', Addon::where('business_id', $bid)->whereNotNull('product_id')->pluck('product_id'))
            ->pluck('name', 'id');

        $addons = Addon::where('business_id', $bid)
            ->with('inventoryProduct.boutique')
            ->orderBy('id')->get()
            ->map(fn (Addon $a) => CatalogQuickAddController::addonPayload($a) + [
                // المملوكةُ لمنتجٍ واحد — اسمُه، فتقول القائمةُ «هذا المنتج: باقة الربيع»
                'owner_name' => $a->product_id ? ($owners[$a->product_id] ?? null) : null,
            ])->all();

        return Inertia::render('Admin/Addons/Index', [
            'addons' => $addons,
            'stockItems' => ProductCompositionController::stockItemOptions($bid),
            'products' => ProductCompositionController::productOptions($bid),
            'boutiques' => Boutiques::options($business),
            'boutiqueLinks' => self::boutiqueLinks($business),
        ]);
    }

    /**
     * أيُربط صنفُ بوتيكٍ بإضافةٍ في هذا المتجر؟ — بقاعدة الخادم نفسِها.
     *
     * `hosts`: يُعرض خيارُ «منتج من بوتيك» أصلًا. `allowed`: يُقبل حفظُه —
     * سجلُّ مستحقّات المكوّنات مفتوحٌ والمتجرُ بلا ضريبة
     * (`Boutiques::refuseNewLink`). وما لا يُقبل يُعرض معطّلًا بسببه، لا
     * مخفيًّا: من يُؤوي بوتيكاتٍ يسأل أين الخيار.
     *
     * @return array{hosts: bool, allowed: bool, reason: ?string}
     */
    public static function boutiqueLinks(?Business $business): array
    {
        $hosts = Boutiques::holds($business);
        if (! $hosts) {
            return ['hosts' => false, 'allowed' => false, 'reason' => null];
        }

        $bid = (int) $business->id;
        $reason = match (true) {
            ! BoutiqueDues::active($bid) => __('ربطُ قطعة بوتيك بإضافة يحتاج تفعيل مستحقّات مكوّنات البوتيك لمتجرك من إدارة المنصّة.'),
            Vat::enabled($bid) => __('مستحقُّ البوتيك لا يُحسب بعد في متجرٍ يحتسب الضريبة.'),
            default => null,
        };

        return ['hosts' => true, 'allowed' => $reason === null, 'reason' => $reason];
    }

    /**
     * إيقافُ إضافةٍ أو تفعيلُها — عمودٌ واحد، ولا يُمسّ مداها ولا قناتاها.
     *
     * الموقوفةُ لا تُعرض في الصندوق ولا في الموقع، ويردّها الخادمُ عند البيع
     * (`ProductAddons::sells`). ولا يُحذف صفٌّ ولا تُمسّ فاتورةٌ مضت: لقطتُها
     * في `order_item_addons`.
     */
    public function active(Request $request, int $addon): JsonResponse
    {
        $model = Addon::where('business_id', $this->bid())->findOrFail($addon);
        $data = $request->validate(['active' => ['required', 'boolean']], [], ['active' => __('حالة الإضافة')]);

        $model->update(['active' => (bool) $data['active']]);

        Activity::log('updated', ($model->active ? 'فعّل' : 'أوقف').' إضافة «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json(['ok' => true, 'addon' => CatalogQuickAddController::addonPayload($model->fresh())]);
    }

    /**
     * يُدخل هذا المنتجَ مدى إضافةٍ مخصّصة — من شاشة المنتج.
     *
     * للمخصّصة وحدها: العامّةُ تُعرض معه أصلًا، والمملوكةُ لمنتجٍ آخر لا
     * تخرج عنه (`ProductAddons::owned`). وصفٌّ واحدٌ لكلّ زوج مهما تكرّر
     * الطلب: الزوجُ فريدٌ في الجدول، و`insertOrIgnore` تجعل الثانيَ صمتًا لا
     * خطأ — فحصُ «أموجود؟» قبل الكتابة يقرأ «لا» في طلبين معًا، فيسقط
     * الثاني بالقيد ٥٠٠ لربطٍ وقع (`AnAddonLinkedTwiceAtOnceIsLinkedOnceTest`).
     */
    public function link(int $id, int $addon): JsonResponse
    {
        [$product, $model] = $this->pair($id, $addon);

        if ($model->scopeName() !== Addon::SCOPE_SELECTED) {
            return $this->refuse($model->scopeName() === Addon::SCOPE_ALL
                ? __('«:name» إضافةٌ عامّة — تظهر مع هذا المنتج أصلًا.', ['name' => $model->name])
                : __('«:name» خاصّةٌ بمنتجٍ آخر ولا تُربط بغيره.', ['name' => $model->name]));
        }

        DB::table('product_addons')->insertOrIgnore([
            'business_id' => $this->bid(),
            'product_id' => $product->id,
            'addon_id' => $model->id,
            'sort_order' => (int) DB::table('product_addons')->where('addon_id', $model->id)->max('sort_order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Activity::log('updated', 'ربط إضافة «'.$model->name.'» بـ'.$product->name, ['subject_id' => $product->id]);

        return response()->json(['ok' => true, 'addon' => CatalogQuickAddController::addonPayload($model->fresh())]);
    }

    /**
     * يُخرج هذا المنتجَ من مدى إضافةٍ مخصّصة — والإضافةُ باقيةٌ لمنتجاتها الأخرى.
     *
     * والعامّةُ لا تُفكّ من منتجٍ واحد: مداها «الجميع»، وتضييقُه قرارٌ في
     * صفحة الإضافات لا ضغطةٌ في منتج. والمملوكةُ تُوقَف ولا تُفكّ: هذا المنتجُ
     * هو مداها كلُّه.
     */
    public function unlink(int $id, int $addon): JsonResponse
    {
        [$product, $model] = $this->pair($id, $addon);

        if ($model->scopeName() !== Addon::SCOPE_SELECTED) {
            return $this->refuse($model->scopeName() === Addon::SCOPE_ALL
                ? __('«:name» إضافةٌ عامّة — غيّر نطاقها إلى «منتجات محددة» من صفحة الإضافات لتستثني منتجًا.', ['name' => $model->name])
                : __('«:name» خاصّةٌ بهذا المنتج — أوقفها بدل فكّها.', ['name' => $model->name]));
        }

        DB::table('product_addons')->where('product_id', $product->id)->where('addon_id', $model->id)->delete();

        Activity::log('updated', 'فكّ إضافة «'.$model->name.'» عن '.$product->name, ['subject_id' => $product->id]);

        return response()->json(['ok' => true, 'addon' => CatalogQuickAddController::addonPayload($model->fresh())]);
    }

    /** @return array{0: Product, 1: Addon} */
    private function pair(int $productId, int $addonId): array
    {
        $bid = $this->bid();

        return [
            Product::where('business_id', $bid)->findOrFail($productId),
            Addon::where('business_id', $bid)->findOrFail($addonId),
        ];
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['addon' => [$message]]], 422);
    }
}
