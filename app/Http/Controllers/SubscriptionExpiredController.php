<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Tenancy;

/**
 * الشاشة الوحيدة التي يراها متجرٌ انتهى اشتراكه.
 *
 * كان يُردّ عند الباب برسالةٍ في حقل البريد، فيقرؤها ويحاول ثانيةً ظنًّا أنه
 * أخطأ كلمة المرور. ثم يتّصل ليسأل «لماذا لا أدخل؟» قبل أن يسأل «كيف
 * أجدّد؟» — وهو السؤال الوحيد الذي يهمّ الطرفين. فصار يدخل ويقف هنا: متى
 * انتهى، وبمن يتّصل، وأن بياناته كما تركها.
 */
class SubscriptionExpiredController extends Controller
{
    public function __invoke(): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        $user = auth()->user();
        $business = $user?->business;

        /*
         * من لا شأن له بهذه الصفحة يُعاد إلى عمله.
         *
         * وإلا لصارت بابًا خلفيًّا: رابطٌ يفتحه أيّ مستخدمٍ فيرى شاشة انتهاء
         * اشتراكٍ لم ينتهِ، ويظنّ متجره مقفلًا وهو يعمل.
         */
        if (Tenancy::blockReason($user) !== Tenancy::SUBSCRIPTION_EXPIRED) {
            return redirect()->route($user?->isSuperAdmin() ? 'super-admin.dashboard' : 'admin.dashboard');
        }

        $platform = Setting::whereNull('business_id')
            ->whereIn('key', ['company', 'official_email', 'phone', 'website'])
            ->pluck('value', 'key');

        return \Inertia\Inertia::render('Auth/SubscriptionExpired', [
            'business' => $business?->name,
            'endedAt' => $business?->ends_at?->format('Y-m-d'),
            // الأيّام منذ الانتهاء لا منذ الإقفال: هي ما يقيس تأخّره
            'daysSince' => $business?->ends_at
                ? (int) $business->ends_at->startOfDay()->diffInDays(now()->startOfDay())
                : null,
            'plan' => $business?->plan?->name,
            // السعر الشهري: الباقة تحمل شهريًّا وسنويًّا، ولا يُعرض رقمٌ ملزم
            'amount' => $business?->plan ? (float) $business->plan->monthly_price : null,
            'contact' => [
                'company' => $platform['company'] ?? null,
                'email' => $platform['official_email'] ?? null,
                'phone' => $platform['phone'] ?? null,
                'website' => $platform['website'] ?? null,
            ],
        ]);
    }
}
