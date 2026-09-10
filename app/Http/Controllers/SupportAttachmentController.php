<?php

namespace App\Http\Controllers;

use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مرفقاتُ الدعم — بابٌ واحد يسأل سؤالين قبل أن يفتح.
 *
 * ═══ لماذا ليست على القرص العامّ ═══
 *
 * صورةُ شاشةٍ يرفعها تاجرٌ ليُرِي عطبًا فيها — غالبًا — أسماءُ زبائنه
 * ومبالغُهم وأرقامُ هواتفهم. والقرصُ العامّ يُخدَم من `public/storage`
 * مباشرةً: لا Laravel يُستدعى ولا جلسةَ يُسأل عنها. فمن عرف رابطًا واحدًا
 * عرف نمطَه.
 *
 * ═══ والسؤالان ═══
 *
 * **أوّلًا:** أهذا مرفقُ هذه المحادثة؟ — رقمُ مرفقٍ من محادثةٍ أخرى يُكتب
 * في العنوان فيُقرأ، وهو عطبُ IDOR بعينه.
 *
 * **ثانيًا:** أيحقّ لك؟ — مديرُ المنصّة يقرأ كلَّ شيء، والتاجرُ لا يقرأ
 * إلّا مرفقاتِ محادثات متجره هو، **ولا يقرأ مرفقَ ملاحظةٍ داخليّة** ولو
 * كانت في محادثته: الملاحظةُ لفريق أبعاد، وما عُلّق عليها منها.
 *
 * ═══ والاسم ═══
 *
 * المخزَّنُ عشوائيٌّ يولّده Laravel فلا يُخمَّن ولا يُخرَج به من المجلّد،
 * والمعروضُ ما سمّاه صاحبه. واسمٌ يُبنى من الأصل يُخمَّن، ومسارٌ يقبل ما
 * يكتبه المستخدم يُخرِجه إلى `.env`.
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, int $conversation, int $attachment): StreamedResponse
    {
        $user = $request->user();

        $thread = SupportConversation::findOrFail($conversation);

        if (! $user->isSuperAdmin()) {
            abort_unless($thread->business_id === $user->business_id, 404);
        }

        /*
         * والمرفقُ يُقرأ من محادثته لا من الجدول كلِّه.
         *
         * `SupportAttachment::find($id)` وحدَه يفتح مرفقَ أيّ محادثةٍ لمن
         * يملك واحدةً — يكفيه أن يبدّل الرقم الثاني في العنوان.
         */
        $file = SupportAttachment::whereKey($attachment)
            ->whereHas('message', fn ($m) => $m->where('conversation_id', $thread->id)
                ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('is_internal', false)))
            ->firstOrFail();

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }
}
