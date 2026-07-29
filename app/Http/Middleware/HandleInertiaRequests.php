<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * البيانات المشتركة مع كل صفحة — ما تحتاجه القشرة (القائمة الجانبية،
     * الشريط العلوي، مبدّل الفرع والعملة، الإشعارات) دون أن يطلبها كل controller.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => fn () => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roleLabel' => $user->roleLabel(),
                    'avatar' => $user->avatar,
                    'branch' => $user->branch,
                    'businessId' => $user->business_id,
                ],
                // الأقسام المسموح بها — الواجهة تخفي ما لا يُسمح به بدل تخمينه
                'abilities' => collect(Permissions::sections())
                    ->filter(fn ($section) => $user->allows($section))
                    ->values()
                    ->all(),
            ] : null,

            // سياق المتجر: الفرع الحالي والعملة المعروضة ومنازل الكسر
            'context' => fn () => $user?->business_id ? [
                'businessName' => Demo::businessName(),
                'branchId' => Demo::currentBranchId(),
                'branchName' => Demo::currentBranchName(),
                'branches' => Demo::branches(),
                'currency' => Demo::displayCurrency(),
                'currencies' => collect(Demo::currencies())->where('active', true)->values()->all(),
            ] : null,

            'notifications' => fn () => $user?->business_id ? [
                'items' => Demo::notifications(),
                'count' => Demo::notificationsCount(),
            ] : null,

            // رسائل الجلسة — الواجهة تعرضها كـtoast
            'flash' => fn () => [
                'toast' => $request->session()->get('toast'),
                'status' => $request->session()->get('status'),
            ],

            'locale' => fn () => app()->getLocale(),
            'dir' => fn () => app()->getLocale() === 'en' ? 'ltr' : 'rtl',
        ];
    }
}
