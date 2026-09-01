<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\DemoGuard;
use App\Support\DemoStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * قسم الديمو — بناء المتاجر التجريبيّة ومحوُها من اللوحة لا من سطر الأوامر.
 *
 * وكان هذا كلّه أمرًا في الطرفيّة (`abaad:test-store`): يعمل، لكنّه يفترض
 * وصولًا بـSSH لمن يريد أن يعرض النظام. والعرضُ يقع في اجتماعٍ من هاتف، لا
 * أمام طرفيّة.
 *
 * ولا يكتب هذا المتحكّم في متجرٍ إلا موسومًا — انظر `DemoGuard`.
 */
class DemoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Demo/Index', [
            'stores' => DemoStore::all()->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'status' => $b->status,
                'registered' => $b->starts_at?->format('Y-m-d'),
                'counts' => $this->counts($b),
                /*
                 * الدخول يُقرأ من صفوف المتجر لا يُشتقّ من قاعدةٍ ثابتة.
                 *
                 * المتاجر القديمة أُنشئت ببريدٍ آخر، ومَن يعرض النظام يحتاج
                 * البريد الذي يفتح هذا المتجر بعينه — لا الذي كان يفتح غيره.
                 */
                'login' => $this->login($b),
            ])->all(),
            'sizes' => array_keys(DemoStore::SIZES),
            /*
             * حجمُ كلٍّ بالأرقام لا بالاسم وحده: «كبير» لا تقول شيئًا،
             * و«٣٥٠٠ فاتورة» تقول كم ينتظر من يضغط الزرّ.
             */
            'sizeDetail' => collect(DemoStore::SIZES)->map(fn ($s) => [
                'products' => $s['products'], 'customers' => $s['customers'],
                'orders' => $s['orders'], 'months' => $s['months'],
            ])->all(),
            'credentials' => [
                'password' => DemoStore::PASSWORD,
            ],
            /* عددُ متاجر التجّار — ليُقرأ الفصل بالأرقام لا بالثقة وحدها */
            'realCount' => Business::real()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'size' => ['required', 'string', 'in:' . implode(',', array_keys(DemoStore::SIZES))],
        ]);

        try {
            $business = DemoStore::create($data['name'], $data['size']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', __('أُنشئ المتجر التجريبيّ «:name».', ['name' => $business->name]));
    }

    public function reseed(Request $request, int $id)
    {
        $data = $request->validate([
            'size' => ['required', 'string', 'in:' . implode(',', array_keys(DemoStore::SIZES))],
        ]);

        try {
            $business = DemoStore::reseed($this->demo($id), $data['size']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['size' => $e->getMessage()]);
        }

        return back()->with('success', __('أُعيد بناء بيانات «:name».', ['name' => $business->name]));
    }

    public function destroy(int $id)
    {
        try {
            $business = $this->demo($id);
            $name = $business->name;
            DemoStore::destroy($business);
        } catch (RuntimeException $e) {
            return back()->withErrors(['store' => $e->getMessage()]);
        }

        return back()->with('success', __('حُذف المتجر التجريبيّ «:name» وكلّ بياناته.', ['name' => $name]));
    }

    /**
     * المتجر — ولا يُقبل إلا موسومًا.
     *
     * الحارس هنا لا في `DemoStore` وحده: المعرّف يأتي من الرابط، ومسارٌ
     * يُحذف منه متجرٌ بضغطة يجب أن يرفض معرّف تاجرٍ قبل أن يصل إلى المحو.
     */
    private function demo(int $id): Business
    {
        return DemoGuard::assertDemo(Business::find($id));
    }

    /** بريدا الدخول كما هما في صفوف المتجر */
    private function login(Business $business): array
    {
        $users = $business->users()->orderBy('id')->get(['email', 'role']);

        return [
            'owner' => $users->firstWhere('role', 'admin')?->email,
            'cashier' => $users->firstWhere('role', 'cashier')?->email,
        ];
    }

    /** ما في المتجر من صفوف — يُقرأ منها امتلاؤه بنظرة */
    private function counts(Business $business): array
    {
        $tables = [
            'المنتجات' => 'products', 'العملاء' => 'customers', 'المبيعات' => 'orders',
            'المورّدون' => 'suppliers', 'المشتريات' => 'purchase_orders',
            'القيود' => 'journal_entries', 'المصروفات' => 'expenses', 'الموظفون' => 'users',
        ];

        $out = [];
        foreach ($tables as $label => $table) {
            $out[] = [
                'label' => $label,
                'value' => Schema::hasTable($table)
                    ? DB::table($table)->where('business_id', $business->id)->count()
                    : 0,
            ];
        }

        return $out;
    }
}
