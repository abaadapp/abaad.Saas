<?php

namespace App\Http\Controllers;

use App\Support\Website\Published;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * ما يقرؤه العارض الخارجيّ — النسخة المنشورة وحدها.
 *
 * ولم يعد هذا البابَ الوحيد إلى الموقع: أبعاد صارت ترسمه بنفسها أيضًا
 * (انظر `Store\StorefrontController`). فبقي هذا مستندًا يُقرأ من خارج
 * النظام — عارضُ `abaadapp/Storefront` حين يُنشر، وأيُّ عارضٍ بعده.
 *
 * والقراءةُ والحراسةُ انتقلتا إلى `Support\Website\Published`: كانتا هنا،
 * فلمّا صار للموقع بابٌ ثانٍ داخل أبعاد كان الخياران نداءَ HTTP من الخادم
 * إلى نفسه أو نسخَ المنطق — وكلاهما يفترق يومًا في حارسٍ أو في حال.
 *
 * وما يخرج علنيٌّ بطبعه: هو ما سيُعرض على موقع التاجر لكلّ زائر. فلا سرَّ
 * فيه — ولذلك لا مفتاح عليه، والحدُّ على الطلبات يكفي لمنع الاستنزاف.
 */
class PublishedSiteController extends Controller
{
    public function __invoke(string $host): JsonResponse
    {
        $found = Published::forHost($host);

        return match ($found['state']) {
            Published::NOT_FOUND => response()->json(['error' => 'not_found'], 404),
            Published::NOT_PUBLISHED => response()->json(['error' => 'not_published'], 404),

            // وحالُ الصيانة تخرج كما هي — بلا مفاتيح هذا الملفّ الداخلية
            Published::MAINTENANCE => response()->json(Arr::except($found, ['state', 'business_id']), 503),

            default => response()->json([
                'published_at' => $found['published_at'],
                'version' => $found['version'],
                'site' => $found['site'],
            ])->header('Cache-Control', 'public, max-age=60'),
        };
    }
}
