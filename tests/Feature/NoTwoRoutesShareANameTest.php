<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * اسمُ المسار مفتاحٌ — ولا يُعطى لبابين.
 *
 * أضفتُ `finance.statement` مرّةً ثانيةً لكشف حساب العميل والاسمُ مأخوذٌ
 * لكشف الحساب البنكيّ. والاختباراتُ مرّت كلُّها لأنّها تطرق **عناوين** لا
 * أسماء — ولم يظهر العطب إلّا عند `route:cache` على خادم الإنتاج.
 *
 * وأثرُه أسوأ من خطأ التخزين: `route('admin.finance.statement')` تُخرج
 * عنوانَ آخرِ من سُمّي به، فيضغط التاجر زرًّا فيجد نفسه في شاشةٍ لم يقصدها
 * — بلا خطأ ولا رسالة.
 */
class NoTwoRoutesShareANameTest extends TestCase
{
    public function test_no_two_routes_share_a_name(): void
    {
        $seen = [];
        $clashes = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            $uri = implode('|', $route->methods()).' '.$route->uri();

            if (isset($seen[$name])) {
                $clashes[$name] = [$seen[$name], $uri];
            }

            $seen[$name] = $uri;
        }

        $this->assertSame([], $clashes, 'اسمُ مسارٍ أُعطي لبابين');
    }
}
