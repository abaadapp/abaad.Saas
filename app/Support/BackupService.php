<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;

/**
 * بناء حمولة النسخة الاحتياطية لمتجر واحد (يُستخدم في التنزيل اليدوي والجدولة التلقائية).
 */
class BackupService
{
    public static function payload(int $bid): array
    {
        return [
            'meta' => [
                'app' => 'AbadPOS', 'version' => 1, 'business_id' => $bid,
                'exported_at' => now()->toIso8601String(),
            ],
            'business' => Business::find($bid)?->only(['name', 'type', 'owner_name', 'phone', 'email', 'country', 'city', 'address', 'logo']),
            'categories' => Category::where('business_id', $bid)->get()->toArray(),
            'products' => Product::where('business_id', $bid)->get()->toArray(),
            'customers' => Customer::where('business_id', $bid)->get()->toArray(),
            'orders' => Order::where('business_id', $bid)->with('items')->get()->toArray(),
            'expenses' => Expense::where('business_id', $bid)->get()->toArray(),
            'transactions' => Transaction::where('business_id', $bid)->get()->toArray(),
            'inventory_movements' => InventoryMovement::where('business_id', $bid)->get()->toArray(),
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
