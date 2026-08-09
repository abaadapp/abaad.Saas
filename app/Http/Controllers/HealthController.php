<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * نقطة صحّة يقرأها مراقبٌ خارجي.
 *
 * `GET /health` — بلا مصادقة، لأن من يراقب لا يملك حسابًا، ولأن الشيء الذي
 * نريد اكتشافه (النظام لا يستجيب) يمنع تسجيل الدخول أصلًا.
 *
 * وهي تفحص ولا تكتفي بالردّ: صفحةٌ تعيد 200 لأن nginx حيّ لا تقول شيئًا عن
 * قاعدةٍ سقطت — والمتجر بلا قاعدة متجرٌ ساقط. فيُسأل كل جزءٍ يقف عليه البيع،
 * ويُردّ 503 إن سقط واحد، لأن المراقب يقرأ الرقم لا النصّ.
 *
 * ولا تُفشي شيئًا: لا إصدارات ولا مسارات ولا أسماء قواعد. من يقرأها من
 * الإنترنت يعرف أن النظام حيّ أو ميت، وهذا كل ما يحتاجه — وكل ما نمنحه.
 */
class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [
            'db' => $this->safe(fn () => DB::connection()->getPdo() !== null),
            // القراءة والكتابة معًا: قرصٌ ممتلئ يقرأ ولا يكتب، والبيع يكتب
            'storage' => $this->safe(function () {
                $probe = storage_path('framework/.health');
                file_put_contents($probe, (string) time());
                $ok = is_readable($probe);
                @unlink($probe);

                return $ok;
            }),
            'cache' => $this->safe(function () {
                cache()->put('health', 1, 10);

                return cache()->get('health') === 1;
            }),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json(['ok' => $ok] + $checks, $ok ? 200 : 503);
    }

    /** فحصٌ يرمي استثناءً هو فحصٌ فاشل، لا صفحةُ خطأ ٥٠٠ عند المراقب */
    private function safe(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (\Throwable) {
            return false;
        }
    }
}
