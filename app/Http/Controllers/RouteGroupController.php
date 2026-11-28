<?php

namespace App\Http\Controllers;

use App\Support\RouteGroups;
use Illuminate\Http\JsonResponse;

/**
 * مساراتُ مجموعةٍ تُضمّ في المتصفّح — حين ينتقل إلى صفحةٍ من واجهةٍ أخرى بلا تحميل.
 *
 * الصندوقُ يحمل جدولَه وحده؛ فإن انتقل إلى اللوحة بلا تحميلٍ جُلبت مجموعتُها هنا
 * وضُمّت قبل أن تُرسم (`lib/route-groups.ts`). ومجموعةُ الزوّار لكلّ أحد، ومجموعتا
 * التاجر لمن دخل، والكلُّ لمدير المنصّة ومن دخل منه باسم تاجر — كما تأخذها صفحاتُهم.
 * ولا يُكتب شيء، ولا يُحفظ الجوابُ في ذاكرةٍ مشتركة.
 */
class RouteGroupController extends Controller
{
    public function __invoke(string $group): JsonResponse
    {
        abort_unless(array_key_exists($group, RouteGroups::COVERS), 404);

        if ($group !== RouteGroups::GUEST) {
            abort_unless(auth()->check(), 401);
        }

        if ($group === RouteGroups::ALL) {
            abort_unless(RouteGroups::seesEverything(), 403);
        }

        return response()->json(
            ['group' => $group, 'routes' => RouteGroups::routes($group)],
            200,
            ['Cache-Control' => 'no-store, private'],
            JSON_UNESCAPED_SLASHES,
        );
    }
}
