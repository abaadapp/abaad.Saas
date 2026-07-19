<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Demo;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    private function shape($q, Request $request)
    {
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('user_name', 'like', "%{$s}%"));
        }
        if ($a = $request->query('action')) { $q->where('action', $a); }

        return $q->latest('id')->paginate(15)->withQueryString()->through(fn ($l) => [
            'user' => $l->user_name, 'action' => $l->action, 'description' => $l->description,
            'icon' => $l->icon, 'color' => $l->color, 'ip' => $l->ip,
            'time' => optional($l->created_at)->format('Y-m-d H:i'),
            'ago' => optional($l->created_at)?->locale('ar')->diffForHumans(),
        ]);
    }

    public function superIndex(Request $request)
    {
        $logs = $this->shape(ActivityLog::query(), $request);

        return view('super-admin.activity', ['logs' => $logs, 'filters' => $request->only('q', 'action')]);
    }

    public function adminIndex(Request $request)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $logs = $this->shape(ActivityLog::where('business_id', $bid), $request);

        return view('admin.activity', ['logs' => $logs, 'filters' => $request->only('q', 'action')]);
    }
}
