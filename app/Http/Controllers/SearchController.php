<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * البحث العام الموحّد للشريط العلوي (JSON، مجمّع حسب النوع).
 */
class SearchController extends Controller
{
    public function admin(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $user = auth()->user();
        $bid = $user->business_id ?? Demo::bid();
        $like = "%{$q}%";

        /*
         * البحث لا يتجاوز صلاحيات صاحبه.
         *
         * المسار نفسه مفتوح لكل من دخل اللوحة — هو أداةٌ في الشريط العلوي لا
         * قسم. لكن نتائجه تقود إلى ثلاثة أقسام، فمن لا يملك «العملاء» كان
         * يقرأ أسماءهم وأرقامهم من مربّع البحث ثم يصطدم بـ403 عند الضغط:
         * البيانات وصلته قبل الباب المغلق.
         */
        $products = $user->allows('products') ? Product::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like))
            ->limit(5)->get()->map(fn ($p) => [
                'label' => $p->name, 'meta' => $p->sku ?: '—',
                'url' => route('admin.products.show', $p->id),
            ]) : collect();

        $orders = $user->allows('orders') ? Order::where('business_id', $bid)->sold()
            ->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('customer_name', 'like', $like))
            ->orderByDesc('id')->limit(5)->get()->map(fn ($o) => [
                'label' => $o->number, 'meta' => $o->customer_name ?? __('عميل نقدي'),
                'url' => route('admin.orders.show', $o->number),
            ]) : collect();

        $customers = $user->allows('customers') ? Customer::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))
            ->limit(5)->get()->map(fn ($c) => [
                'label' => $c->name, 'meta' => $c->phone ?: '—',
                'url' => route('admin.customers.show', $c->id),
            ]) : collect();

        return response()->json(['groups' => array_values(array_filter([
            $this->group(__('المنتجات'), 'package', $products),
            $this->group(__('الطلبات'), 'shopping-cart', $orders),
            $this->group(__('العملاء'), 'users', $customers),
        ]))]);
    }

    public function super(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = "%{$q}%";

        $businesses = Business::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('owner_name', 'like', $like))
            ->limit(6)->get()->map(fn ($b) => [
                'label' => $b->name, 'meta' => $b->owner_name ?: '—',
                'url' => route('super-admin.businesses.show', $b->id),
            ]);

        $users = User::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->limit(6)->get()->map(fn ($u) => [
                'label' => $u->name, 'meta' => $u->email ?: '—',
                'url' => route('super-admin.users.show', $u->id),
            ]);

        return response()->json(['groups' => array_values(array_filter([
            $this->group(__('الشركات'), 'building-2', $businesses),
            $this->group(__('المستخدمون'), 'users', $users),
        ]))]);
    }

    private function group(string $title, string $icon, $items): ?array
    {
        return $items->isEmpty() ? null : ['title' => $title, 'icon' => $icon, 'items' => $items->all()];
    }
}
