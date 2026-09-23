<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Demo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مرفقُ كرت الهدية — يرفعه زبونٌ في الموقع، ويقرؤه من يصنع الكرت.
 *
 * ═══ ولمَ ليس على القرص العامّ ═══
 *
 * العلّةُ نفسُها المشروحة في `FinancialAttachmentController`: القرصُ العامّ
 * يُخدَم بلا أن يُستدعى Laravel، فلا جلسةَ تُسأل ولا متجرَ يُتحقَّق منه —
 * ومن عرف رابطًا واحدًا عرف نمطَه.
 *
 * وهنا الأمرُ أمسّ: ما يرفعه الزبون رسالةٌ خاصّة إلى شخصٍ بعينه، وربّما
 * صورةٌ بخطّ يده. ورابطٌ يُخمَّن يعني قراءةَ رسائل الناس.
 *
 * فالبابُ يسأل سؤالين: أهذا طلبُ متجرك؟ وهل تُؤذن لك شاشةُ الطلبات أو
 * شاشةُ التجهيز أصلًا؟ ومن لا يرى الطلبَ لا يفتح مرفقَه.
 */
class OrderAttachmentController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /**
     * ملفُّ كرت الهدية — يُعرض لا يُنزَّل قسرًا.
     *
     * أكثرُه صورةٌ أو PDF، ومن فتحه يريد أن يراه ليكتبه لا أن يجده في
     * مجلّد التنزيلات.
     */
    public function giftCard(int|string $id): StreamedResponse
    {
        $user = auth()->user();

        /*
         * والشاشتان كلتاهما تكفيان.
         *
         * المنسّقُ الذي يصنع الكرت يعيش في «التجهيز» ولا يفتح «المبيعات»؛
         * والمحاسبُ الذي يراجع الطلب يفتح «المبيعات» ولا يدخل «التجهيز».
         * وحصرُه في إحداهما يمنع من يكتب الكرتَ من أن يقرأ ما يكتب.
         */
        abort_unless($user === null || $user->allows('orders') || $user->allows('preparation'), 403);

        $order = Order::where('business_id', $this->bid())->findOrFail($id);

        abort_if(
            ! filled($order->card_file) || ! Storage::disk('local')->exists($order->card_file),
            404,
        );

        return Storage::disk('local')->response(
            $order->card_file,
            $order->card_file_name ?: 'gift-card-'.$order->number.'.'.pathinfo($order->card_file, PATHINFO_EXTENSION),
            ['Content-Disposition' => 'inline'],
        );
    }
}
