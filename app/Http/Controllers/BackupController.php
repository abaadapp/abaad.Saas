<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\BackupService;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            return back()->with('toast', ['msg' => 'ملف النسخة الاحتياطية غير صالح', 'type' => 'error']);
        }

        $bid = $this->bid();

        DB::transaction(function () use ($data, $bid) {
            // حذف بيانات المستأجر الحالية (الأبناء أولًا)
            OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid))->delete();
            Order::where('business_id', $bid)->delete();
            Product::where('business_id', $bid)->delete();
            Category::where('business_id', $bid)->delete();
            Customer::where('business_id', $bid)->delete();
            Expense::where('business_id', $bid)->delete();
            Transaction::where('business_id', $bid)->delete();
            InventoryMovement::where('business_id', $bid)->delete();
            Setting::where('business_id', $bid)->delete();

            // ملف تعريف المتجر (حقول آمنة فقط)
            if (! empty($data['business']) && is_array($data['business'])) {
                Business::where('id', $bid)->update(
                    collect($data['business'])->only(['name', 'type', 'owner_name', 'phone', 'email', 'country', 'city', 'address', 'logo'])->all()
                );
            }

            // إعادة الإدراج (الآباء أولًا) مع فرض business_id الحالي والحفاظ على المعرّفات
            $insert = function (array $rows, string $model) use ($bid) {
                foreach ($rows as $row) {
                    unset($row['items']);
                    $row['business_id'] = $bid;
                    $model::create($row);
                }
            };

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

            $insert($data['expenses'] ?? [], Expense::class);
            $insert($data['transactions'] ?? [], Transaction::class);
            $insert($data['inventory_movements'] ?? [], InventoryMovement::class);
            $insert($data['settings'] ?? [], Setting::class);
        });

        Activity::log('restore', 'استعاد بيانات المتجر من نسخة احتياطية');

        return back()->with('toast', ['msg' => 'تمت استعادة البيانات بنجاح', 'type' => 'success']);
    }
}
