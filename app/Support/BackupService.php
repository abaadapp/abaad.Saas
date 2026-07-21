<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;

/**
 * بناء حمولة النسخة الاحتياطية لمتجر واحد (يُستخدم في التنزيل اليدوي والجدولة التلقائية).
 *
 * ما لا يُنسخ عمدًا:
 * - plans: جدول على مستوى المنصة لا يخصّ متجرًا بعينه.
 * - subscriptions / invoices: سجلات فوترة تخصّ المنصة — لا يجوز للمتجر استعادتها.
 * - كلمات المرور ورموز التذكّر: لا تُكتب في ملف يُنزَّل على جهاز المستخدم.
 */
class BackupService
{
    public const VERSION = 2;

    public static function payload(int $bid): array
    {
        return [
            'meta' => [
                'app' => 'AbadPOS', 'version' => self::VERSION, 'business_id' => $bid,
                'exported_at' => now()->toIso8601String(),
            ],
            'business' => Business::find($bid)?->only(['name', 'type', 'owner_name', 'phone', 'email', 'country', 'city', 'address', 'logo']),

            // الأصول المرجعية (تُدرَج أولًا عند الاستعادة)
            'branches' => Branch::where('business_id', $bid)->get()->toArray(),
            'currencies' => Currency::where('business_id', $bid)->get()->toArray(),
            'suppliers' => Supplier::where('business_id', $bid)->get()->toArray(),
            'expense_types' => ExpenseType::where('business_id', $bid)->get()->toArray(),

            // الموظفون بدون بيانات الاعتماد
            'users' => User::where('business_id', $bid)->get()
                ->map(fn ($u) => collect($u->toArray())->except(['password', 'remember_token'])->all())->all(),

            'categories' => Category::where('business_id', $bid)->get()->toArray(),
            'products' => Product::where('business_id', $bid)->get()->toArray(),
            'customers' => Customer::where('business_id', $bid)->get()->toArray(),
            'orders' => Order::where('business_id', $bid)->with('items')->get()->toArray(),
            'purchase_orders' => PurchaseOrder::where('business_id', $bid)->with('items')->get()->toArray(),
            'coupons' => Coupon::where('business_id', $bid)->get()->toArray(),
            'expenses' => Expense::where('business_id', $bid)->get()->toArray(),
            'transactions' => Transaction::where('business_id', $bid)->get()->toArray(),
            'inventory_movements' => InventoryMovement::where('business_id', $bid)->get()->toArray(),
            'shifts' => Shift::where('business_id', $bid)->get()->toArray(),
            'activity_logs' => ActivityLog::where('business_id', $bid)->get()->toArray(),
            'settings' => Setting::where('business_id', $bid)->get()->toArray(),
        ];
    }

    public static function json(int $bid): string
    {
        return json_encode(self::payload($bid), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function filename(int $bid): string
    {
        return 'abadpos-backup-' . $bid . '-' . now()->format('Y-m-d-His') . '.json';
    }
}
