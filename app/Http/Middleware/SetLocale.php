<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;

/**
 * يضبط لغة الواجهة لكل طلب: من الجلسة أولًا ثم من إعدادات النشاط.
 * المدعوم: ar (افتراضي) و en فقط.
 */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale');

        if (! in_array($locale, self::SUPPORTED, true) && auth()->check() && auth()->user()->business_id) {
            $locale = Setting::where('business_id', auth()->user()->business_id)
                ->where('key', 'locale')->value('value');
        }

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'ar';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
