<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Demo;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    private static function shape($q, Request $request)
    {
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('user_name', 'like', "%{$s}%"));
        }
        if ($a = $request->query('action')) { $q->where('action', $a); }

        return $q->latest('id')->paginate(15)->withQueryString()->through(fn ($l) => [
            'user' => $l->user_name, 'action' => $l->action, 'description' => $l->description,
            /*
             * من فعلها حقًّا — يظهر شارةً في السطر.
             *
             * بلا هذا يقرأ التاجر اسمه على عمليةٍ فعلها الدعم، فيتّهم موظفيه
             * أو يتّهمك. وهو حقّه أن يعرف: لوحته وبياناته.
             */
            'via' => $l->impersonator_name,
            'icon' => $l->icon, 'color' => $l->color, 'ip' => $l->ip,
            'time' => optional($l->created_at)->format('Y-m-d H:i'),
            'ago' => optional($l->created_at)?->diffForHumans(),
        ]);
    }

    public function superIndex(Request $request)
    {
        $logs = self::shape(ActivityLog::query(), $request);

        return \Inertia\Inertia::render('Platform/Activity', [
            'logs' => $logs->items(),
            'pagination' => \App\Support\Pagination::meta($logs),
            'filters' => $request->only('q', 'action'),
        ]);
    }

    public function adminIndex(Request $request)
    {
        return \Inertia\Inertia::render('Admin/Activity', self::adminData($request));
    }

    /**
     * بيانات قسم سجلّ النشاط — تُقرأ من صفحته المستقلّة ومن لوحة الإعدادات
     * حيث يُفتح مكانها، فلا تفترق النسختان.
     *
     * @return array<string, mixed>
     */
    public static function adminData(Request $request): array
    {
        $bid = auth()->user()->business_id ?? Demo::bid();

        /*
         * سجلّ التاجر يتتبّع موظفيه وحدهم.
         *
         * كان يفتحه فيقرأ أفعاله هو — «سجّل الدخول»، «عدّل منتجًا» — تدفع
         * أفعالَ من يجب أن يُراقَبوا خارج الصفحة الأولى. والغرض من الشاشة أن
         * يعرف ما جرى على الصندوق والمخزون في غيابه، فيُستثنى صاحب النشاط
         * ويُستثنى معه مدير المنصة: كلاهما ليس ممّن جاءت الشاشة لمتابعتهم.
         *
         * وما يفعله الدعم داخل الحساب لا يختفي، بل ينتقل إلى موضعه: سجلّ
         * المنصة يحتفظ بكل شيء بلا استثناء، والدليل هناك.
         */
        $notStaff = \App\Models\User::whereIn('role', ['admin', 'super_admin'])->select('id');

        $logs = self::shape(
            ActivityLog::where('business_id', $bid)
                ->whereNotIn('user_id', $notStaff)
                // فعلٌ جرى عبر انتحال الدعم: صاحبه الدعم لا الموظّف الذي
                // يحمل السجلُّ اسمه، فلا يُحسب على الفريق
                ->whereNull('impersonator_id'),
            $request,
        );

        return [
            'logs' => $logs->items(),
            'pagination' => \App\Support\Pagination::meta($logs),
            'filters' => $request->only('q', 'action'),
        ];
    }
}
