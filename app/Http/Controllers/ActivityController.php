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
         * سجلّ التاجر يتتبّع موظفيه لا نفسه.
         *
         * كان يفتحه فيقرأ أفعاله هو — «سجّل الدخول»، «عدّل منتجًا» — تدفع
         * أفعالَ من يجب أن يُراقَبوا خارج الصفحة الأولى. والغرض من الشاشة أن
         * يعرف ما جرى على الصندوق والمخزون في غيابه.
         *
         * ويبقى ما فعله الدعم داخل حسابه ظاهرًا: يُقيَّد باسمه، وإخفاؤه يعني أن
         * يجري في متجره ما لا يراه. وسجلّ المنصة يحتفظ بكل شيء بلا استثناء —
         * الدليل هناك.
         */
        $owners = \App\Models\User::where('business_id', $bid)->where('role', 'admin')->select('id');

        $logs = self::shape(
            ActivityLog::where('business_id', $bid)
                ->where(fn ($w) => $w->whereNotIn('user_id', $owners)->orWhereNotNull('impersonator_id')),
            $request,
        );

        return [
            'logs' => $logs->items(),
            'pagination' => \App\Support\Pagination::meta($logs),
            'filters' => $request->only('q', 'action'),
        ];
    }
}
