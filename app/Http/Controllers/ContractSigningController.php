<?php

namespace App\Http\Controllers;

use App\Models\PlatformDocument;
use App\Support\PlatformDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * ما يفتحه العميلُ من رابط المستند — يقرأ ويكمل بياناته ويوقّع، بلا حساب.
 *
 * والرابطُ وحده مفتاحه: ٣٢ حرفًا لا تُخمَّن، ورمزٌ مجهولٌ ٤٠٤ لا يقول أكثر.
 * والموقَّعُ يُعرض كما وُقِّع، ولا يُعاد توقيعُه ولا يُعدَّل.
 */
class ContractSigningController extends Controller
{
    public function show(string $token): View
    {
        $doc = $this->find($token);

        // أوّلُ فتحٍ يُكتب — يقول للمدير إنّ العميل رأى الرابط
        if ($doc->viewed_at === null && $doc->status === PlatformDocument::SENT) {
            PlatformDocument::whereKey($doc->id)->whereNull('viewed_at')->update(['viewed_at' => now()]);
        }

        return view('public.sign', [
            'doc' => $doc,
            'token' => $token,
            'provider' => PlatformDocuments::provider(),
            'fields' => PlatformDocuments::FIELDS,
            'signature' => $doc->status === PlatformDocument::SIGNED ? PlatformDocuments::signatureDataUri($doc) : null,
        ]);
    }

    public function sign(Request $request, string $token): RedirectResponse
    {
        $doc = $this->find($token);

        $data = $request->validate([
            'client' => ['nullable', 'array'],
            'client.*' => ['nullable', 'string', 'max:190'],
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'max:'.(int) (PlatformDocuments::MAX_SIGNATURE_BYTES * 1.4)],
        ], [
            'agree.accepted' => __('وافق على الشروط والأحكام'),
            'signature.required' => __('أضف توقيعك.'),
        ]);

        try {
            PlatformDocuments::sign($doc, $data['client'] ?? [], $data['signature'], $request->ip(), $request->userAgent());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['sign' => $e->getMessage()]);
        }

        return redirect()->route('documents.sign', $token)->with('done', true);
    }

    /** الورقةُ بعد التوقيع وحده — قبله يُقرأ المستند على الشاشة */
    public function pdf(string $token)
    {
        $doc = $this->find($token);
        abort_unless($doc->status === PlatformDocument::SIGNED, 404);

        return PlatformDocuments::pdf($doc);
    }

    private function find(string $token): PlatformDocument
    {
        $doc = PlatformDocument::where('token', $token)->first();
        abort_if($doc === null, 404);

        return $doc;
    }
}
