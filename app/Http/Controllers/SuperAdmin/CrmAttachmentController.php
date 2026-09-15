<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CrmAttachment;
use App\Models\CrmLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مرفقاتُ دفتر المبيعات — بابٌ واحد يسأل قبل أن يفتح.
 *
 * ═══ لماذا ليست على القرص العامّ ═══
 *
 * ما يرسله عميلٌ محتمَل صورةُ فاتورةٍ عنده، أو عرضُ منافسٍ، أو بطاقتُه.
 * والقرصُ العامّ يُخدَم من `public/storage` مباشرةً: لا Laravel يُستدعى ولا
 * جلسةَ يُسأل عنها. فمن عرف رابطًا واحدًا عرف نمطَه.
 *
 * ═══ والسؤالان ═══
 *
 * **أوّلًا:** أهذا مرفقُ هذا العميل؟ — رقمُ مرفقٍ من خيطٍ آخر يُكتب في
 * العنوان فيُقرأ، وهو عطبُ IDOR بعينه.
 *
 * **ثانيًا:** أمن فريق أبعادٍ أنت؟ — والمسارُ كلُّه تحت `role:super_admin`،
 * وهذا حارسٌ ثانٍ خلفه لا بديلٌ عنه.
 */
class CrmAttachmentController extends Controller
{
    public function __invoke(Request $request, int $lead, int $attachment): StreamedResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 404);

        $thread = CrmLead::findOrFail($lead);

        /*
         * والمرفقُ يُقرأ من خيطه لا من الجدول كلِّه.
         *
         * `CrmAttachment::find($id)` وحدَه يفتح مرفقَ أيّ عميل — يكفيه أن
         * يبدّل الرقم الثاني في العنوان، فيصير الرقمُ الأوّل زينة.
         */
        $file = CrmAttachment::whereKey($attachment)
            ->whereHas('message', fn ($m) => $m->where('lead_id', $thread->id))
            ->firstOrFail();

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }
}
