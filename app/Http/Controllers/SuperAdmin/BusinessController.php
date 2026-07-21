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
            'branches' => $b->branches_count, 'logo' => $b->logo, 'city' => $b->city, 'country' => $b->country,
        ]);

        return view('super-admin.businesses.index', ['businesses' => $businesses, 'filters' => $request->only('q', 'type', 'plan', 'status')]);
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
            'type' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'plan_id' => ['nullable', 'integer'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'status' => ['nullable', 'string', 'max:50'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);
    }
}
