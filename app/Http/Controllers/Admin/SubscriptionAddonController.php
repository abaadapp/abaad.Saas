<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\SubscriptionAddon;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\AddonViews;
use App\Support\Addons\Catalog;
use App\Support\Demo;
use App\Support\Permissions;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «إضافات متجرك» — ما يشمله اشتراكُ التاجر وما يطلبه.
 *
 * يقرؤها من يفتح «الإعدادات»، ويطلب ويوقف التجديدَ صاحبُ النشاط وحده: الطلبُ
 * يُصدر فاتورةً على المتجر. والمتجرُ من الجلسة لا من الطلب، والاستحقاقُ يُفعَّل
 * بسداد فاتورته في لوحة المنصّة — لا بشيءٍ يصل من هنا.
 */
class SubscriptionAddonController extends Controller
{
    private function business(): Business
    {
        return Business::findOrFail(auth()->user()->business_id ?? Demo::bid());
    }

    private function mayBuy(): void
    {
        abort_unless(Permissions::isOwner(auth()->user()), 403, __('طلب الإضافات لصاحب النشاط وحده.'));
    }

    public function index(): Response
    {
        $business = $this->business();

        return Inertia::render('Admin/Subscription/Addons', AddonViews::forMerchant($business) + [
            'currency' => Demo::displayCurrency(),
            'mayBuy' => Permissions::isOwner(auth()->user()),
        ]);
    }

    public function request(Request $request): RedirectResponse
    {
        $this->mayBuy();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'cycle' => ['required', Rule::in(Catalog::CYCLES)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.Catalog::MAX_QUANTITY],
        ]);

        $addon = SubscriptionAddon::where('code', $data['code'])->first();
        if (! $addon) {
            return back()->withErrors(['code' => __('هذه الإضافة غير موجودة.')]);
        }

        try {
            $result = AddonOrders::request($this->business(), $addon, $data['cycle'], (int) ($data['quantity'] ?? 1), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        $order = $result['order'];

        return back()->with('toast', [
            'msg' => match (true) {
                ! $result['created'] => __('لك طلبٌ قائمٌ لهذه الإضافة لم يُحسم بعد — لا يُصدر له طلبٌ ثانٍ.'),
                $order->status === AddonOrders::PAID => __('فُعّلت الإضافة'),
                $order->unit_price === null => __('وصلنا طلبُك — يصلك عرضُ سعرٍ من أبعاد، ولا تُفعَّل قبل اعتماده وسداده.'),
                default => __('وصلنا طلبُك — تصلك فاتورتُها، وتُفعَّل فور تسجيل السداد.'),
            },
            'type' => $result['created'] ? 'success' : 'warning',
        ]);
    }

    /** يوقف التاجرُ تجديدَ إضافته أو يعيده — وتبقى ساريةً حتى نهاية دورتها */
    public function renews(Request $request, int $id): RedirectResponse
    {
        $this->mayBuy();
        $data = $request->validate(['renews' => ['required', 'boolean']]);

        $entitlement = BusinessAddon::where('business_id', $this->business()->id)->findOrFail($id);

        try {
            AddonOrders::setRenews($entitlement, (bool) $data['renews']);
        } catch (DomainException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', [
            'msg' => $data['renews'] ? __('تتجدّد الإضافة بفاتورةٍ عند نهاية دورتها') : __('أُوقف التجديد — الإضافة سارية حتى نهاية دورتها'),
            'type' => 'success',
        ]);
    }
}
