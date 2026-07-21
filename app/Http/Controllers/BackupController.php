<?php

namespace App\Http\Controllers;

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
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Activity;
use App\Support\BackupService;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * نسخ احتياطي/استعادة بيانات المتجر (محصور بالمستأجر الحالي) كملف JSON.
 */
class BackupController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function download()
    {
        $bid = $this->bid();

        Activity::log('backup', 'صدّر نسخة احتياطية للمتجر');

        return response(BackupService::json($bid), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . BackupService::filename($bid) . '"',
        ]);
    }

    public function restore(Request $request)
    {
        $request->validate(['backup' => ['required', 'file', 'max:20480']]);

        $data = json_decode(file_get_contents($request->file('backup')->getRealPath()), true);
        if (! is_array($data) || (($data['meta']['app'] ?? null) !== 'AbadPOS')) {
            return back()->with('toast', ['msg' => __('ملف النسخة الاحتياطية غير صالح'), 'type' => 'error']);
        }

        $bid = $this->bid();
        $currentUserId = auth()->id();

        DB::transaction(function () use ($data, $bid, $currentUserId) {
            // ===== الحذف: الأبناء أولًا ثم الآباء =====
            OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid))->delete();
            Order::where('business_id', $bid)->delete();
            PurchaseOrderItem::whereHas('purchaseOrder', fn ($q) => $q->where('business_id', $bid))->delete();
            PurchaseOrder::where('business_id', $bid)->delete();
            InventoryMovement::where('business_id', $bid)->delete();
            Expense::where('business_id', $bid)->delete();
            Transaction::where('business_id', $bid)->delete();
            Coupon::where('business_id', $bid)->delete();
            Shift::where('business_id', $bid)->delete();
            ActivityLog::where('business_id', $bid)->delete();
            Product::where('business_id', $bid)->delete();
            Category::where('business_id', $bid)->delete();
            Customer::where('business_id', $bid)->delete();
            ExpenseType::where('business_id', $bid)->delete();
            Supplier::where('business_id', $bid)->delete();
            Currency::where('business_id', $bid)->delete();
            Branch::where('business_id', $bid)->delete();
            Setting::where('business_id', $bid)->delete();
            // الموظفون لا يُحذفون — تُحدَّث بياناتهم فقط (انظر أدناه)

            // ملف تعريف المتجر (حقول آمنة فقط)
            if (! empty($data['business']) && is_array($data['business'])) {
                Business::where('id', $bid)->update(
                    collect($data['business'])->only(['name', 'type', 'owner_name', 'phone', 'email', 'country', 'city', 'address', 'logo'])->all()
                );
            }

            // ===== الإدراج: الآباء أولًا =====
            $insert = function (array $rows, string $model) use ($bid) {
                foreach ($rows as $row) {
                    unset($row['items']);
                    $row['business_id'] = $bid;
                    $model::create($row);
                }
            };

            $insert($data['branches'] ?? [], Branch::class);
            $insert($data['currencies'] ?? [], Currency::class);
            $insert($data['suppliers'] ?? [], Supplier::class);
            $insert($data['expense_types'] ?? [], ExpenseType::class);
            $insert($data['categories'] ?? [], Category::class);
            $insert($data['products'] ?? [], Product::class);
            $insert($data['customers'] ?? [], Customer::class);

            foreach ($data['orders'] ?? [] as $order) {
                $items = $order['items'] ?? [];
                unset($order['items']);
                $order['business_id'] = $bid;
                Order::create($order);
                foreach ($items as $item) {
                    OrderItem::create($item);
                }
            }

            foreach ($data['purchase_orders'] ?? [] as $po) {
                $items = $po['items'] ?? [];
                unset($po['items']);
                $po['business_id'] = $bid;
                PurchaseOrder::create($po);
                foreach ($items as $item) {
                    PurchaseOrderItem::create($item);
                }
            }

            $insert($data['coupons'] ?? [], Coupon::class);
            $insert($data['expenses'] ?? [], Expense::class);
            $insert($data['transactions'] ?? [], Transaction::class);
            $insert($data['inventory_movements'] ?? [], InventoryMovement::class);
            $insert($data['shifts'] ?? [], Shift::class);
            $insert($data['activity_logs'] ?? [], ActivityLog::class);
            $insert($data['settings'] ?? [], Setting::class);

            // ===== الموظفون: تحديث/إضافة بلا حذف =====
            // لا نحذف المستخدمين حتى لا يفقد صاحب النشاط حسابه أثناء الاستعادة،
            // ولا نستورد كلمات المرور (غير موجودة في الملف أصلًا): الحساب القائم يبقى بكلمته،
            // والحساب الجديد يُنشأ بكلمة عشوائية تتطلب إعادة تعيين.
            foreach ($data['users'] ?? [] as $row) {
                unset($row['password'], $row['remember_token'], $row['id']);
                if (empty($row['email'])) {
                    continue;
                }
                $existing = User::where('email', $row['email'])->first();
                if ($existing) {
                    // لا نغيّر بيانات الحساب الذي ينفّذ الاستعادة حاليًا
                    if ($existing->id === $currentUserId) {
                        continue;
                    }
                    $existing->update(collect($row)->except(['business_id'])->all());
                } else {
                    $row['business_id'] = $bid;
                    $row['password'] = bcrypt(Str::random(40));
                    User::create($row);
                }
            }
        });

        Activity::log('restore', 'استعاد بيانات المتجر من نسخة احتياطية');

        return back()->with('toast', ['msg' => __('تمت استعادة البيانات بنجاح'), 'type' => 'success']);
    }
}
