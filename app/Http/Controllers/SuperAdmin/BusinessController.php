<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $q = Business::with('plan');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('owner_name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }
        if ($t = $request->query('type')) { $q->where('type', $t); }
        if ($p = $request->query('plan')) { $q->whereHas('plan', fn ($w) => $w->where('name', $p)); }
        if ($st = $request->query('status')) { $q->where('status', $st); }

        $businesses = $q->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'owner' => $b->owner_name,
            'phone' => $b->phone, 'email' => $b->email, 'plan' => $b->plan?->name ?? '—',
            'status' => $b->status, 'registered' => optional($b->starts_at)->format('Y-m-d') ?? '—',
            'branches' => $b->branches_count, 'city' => $b->city, 'country' => $b->country,
            // مسار مخزَّن أو رابط مطلق — المحوّل يميّز بينهما
            'logo' => PageController::logoUrl($b->logo),
        ]);

        return \Inertia\Inertia::render('Platform/Businesses/Index', [
            'businesses' => $businesses->items(),
            'pagination' => \App\Support\Pagination::meta($businesses),
            'filters' => $request->only('q', 'type', 'plan', 'status'),
            'options' => PageController::filterOptions($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['logo'] = $request->hasFile('logo') ? $request->file('logo')->store('logos', 'public') : null;
        $business = Business::create($data);
        \App\Support\Activity::log('created', 'أضاف شركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم إضافة الشركة بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        $data = $this->validateData($request);
        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }
        $business->update($data);
        \App\Support\Activity::log('updated', 'عدّل الشركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم تحديث الشركة بنجاح'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $business = Business::findOrFail($id);
        $business->update(['status' => 'معطل']);
        \App\Support\Activity::log('deleted', 'عطّل الشركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم تعطيل الشركة'), 'type' => 'warning']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // النوع والحالة عمودان NOT NULL لهما قيم افتراضية؛ إرسال null صراحةً
            // يتخطّى الافتراضي ويكسر القيد بخطأ 500 بدل رسالة حقل مفقود.
            'type' => ['required', 'string', 'max:100'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'plan_id' => ['nullable', 'integer'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'status' => ['required', 'string', 'max:50'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);
    }
}
