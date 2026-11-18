<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use App\Support\Pos\TillCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * زبائنُ الصندوق خارج صفحته — تُزامَن بما تغيّر، ويُسأل عن المختار منهم.
 *
 * كانوا في خصائص الصفحة: تُعاد القائمةُ كاملةً في كلّ فتحٍ للصندوق وكلّ عودةٍ
 * إليه. فصار الجهازُ يحفظها، ويسأل هنا عمّا تغيّر منذ ما حفظ (`TillCustomers`).
 * والمسارُ تحت `pos.` فيحرسه ما يحرس الصندوق: الدخولُ والمتجرُ وفرعُ الجهاز
 * والباقةُ وصلاحيةُ «pos» — ومتجرُ الطلب هو متجرُ الصندوق (`Demo::bid`).
 */
class TillCustomerController extends Controller
{
    /** ما تغيّر عن بصمات الجهاز — والجهازُ بلا نسخةٍ يُرسل `{}` فيصله الكلّ */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'have' => ['present', 'array', 'max:'.TillCustomers::BUCKETS],
            'have.*' => ['string', 'max:32'],
        ]);

        $have = [];

        foreach ($data['have'] as $bucket => $hash) {
            if (is_numeric($bucket) && (int) $bucket >= 0 && (int) $bucket < TillCustomers::BUCKETS) {
                $have[(int) $bucket] = (string) $hash;
            }
        }

        return $this->json(['business' => Demo::bid()] + TillCustomers::sync(Demo::bid(), $have));
    }

    /**
     * صفُّ عميلٍ واحدٍ كما هو الآن — يُسأل عنه حين يُختار، والاتّصالُ قائم.
     *
     * نقاطُه وحظرُه ولغتُه قد تغيّرت منذ فُتح الصندوق. و404 لمن حُذف أو ليس من
     * هذا المتجر: لا يُقال إن كان في متجرٍ آخر.
     */
    public function show(int $customer): JsonResponse
    {
        $row = TillCustomers::rows(Demo::bid(), $customer)[0] ?? null;

        return $row === null
            ? $this->json(['message' => __('هذا العميل لم يعد موجودًا')], 404)
            : $this->json(['customer' => $row]);
    }

    /** بالعربيّة كما هي — `ع` لكلّ حرفٍ يضاعف ما يُرسَل */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, private'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
