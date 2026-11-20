<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use App\Support\TillCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ‏‏كتالوجُ الصندوق للجهاز — ما تغيّر منذ ما حفظ (`TillCatalog`).
 *
 * المسارُ تحت `pos.` فيحرسه ما يحرس الصندوق في كلّ سؤال: الدخولُ والمتجرُ وفرعُ
 * الجهاز والباقةُ وصلاحيةُ «pos». والمتجرُ والفرعُ واللغةُ من الجلسة وحدها؛ وما
 * يرسله الجهازُ (مفتاحُه وبصماتُه ورمزُه) يُقارَن به ولا يُقرأ به شيء.
 */
class TillCatalogController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $scope = TillCatalog::scope((int) Demo::bid(), Demo::activeBranchId(), app()->getLocale());

        /*
         * ‏‏جهازٌ فُتح صندوقُه لمتجرٍ أو فرعٍ أو لغةٍ غيرِ ما في الجلسة الآن (بُدّل في
         * تبويبٍ آخر) يُقال له ذلك ولا يُعطى كتالوجًا ليس لما على شاشته.
         */
        $theirs = $request->input('scope');
        if (! is_string($theirs) || $theirs !== $scope) {
            return $this->json(['scope_changed' => true, 'scope' => $scope], 409);
        }

        return $this->json(TillCatalog::answer((int) Demo::bid(), Demo::activeBranchId(), $scope, $request->only(['t', 'b', 'ih', 'oh', 'deep'])));
    }

    /** ‏بالعربيّة كما هي — `\u` لكلّ حرفٍ يضاعف ما يُرسَل — ولا يُخبَّأ في الطريق */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, private'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
