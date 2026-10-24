<?php

namespace App\Http\Controllers;

use App\Models\PlatformDocument;
use App\Support\PlatformDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ما يفتحه العميلُ من رابط المستند — يقرأ ويكمل بياناته ويوقّع، بلا حساب.
 *
 * والرابطُ وحده مفتاحه: ٣٢ حرفًا لا تُخمَّن، ورمزٌ مجهولٌ ٤٠٤ لا يقول أكثر.
 * والموقَّعُ يُعرض كما وُقِّع، ولا يُعاد توقيعُه ولا يُعدَّل.
 *
 * وما يعرضه الرابطُ يضيق بحاله (`PlatformDocument::readable`): الملغى والمنتهي
 * وما مضت أيّامُ نسخته يقول حالَه بلا بيان العميل. و`no-store` على الصفحة
 * والورقة: رقمٌ مدنيٌّ وتوقيعٌ لا يبقيان في ذاكرة متصفّحٍ مشترك.
 */
class ContractSigningController extends Controller
{
    public function show(string $token): Response
    {
        $doc = $this->find($token);

        // أوّلُ فتحٍ يُكتب — يقول للمدير إنّ العميل رأى الرابط
        if ($doc->viewed_at === null && $doc->status === PlatformDocument::SENT) {
            PlatformDocument::whereKey($doc->id)->whereNull('viewed_at')->update(['viewed_at' => now()]);
        }

        $readable = $doc->readable();

        return response()->view('public.sign', [
            'doc' => $doc,
            'token' => $token,
            'readable' => $readable,
            'provider' => PlatformDocuments::providerOf($doc),
            'fields' => PlatformDocuments::FIELDS,
            'signature' => $readable && $doc->status === PlatformDocument::SIGNED ? PlatformDocuments::signatureDataUri($doc) : null,
        ])->header('Cache-Control', 'no-store, private');
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

    /** الورقةُ بعد التوقيع وفي أيّام نسخته — قبله يُقرأ المستند على الشاشة، وبعدها تُطلب من مدير المنصّة */
    public function pdf(string $token): Response
    {
        $doc = $this->find($token);
        abort_unless($doc->copyOpen(), 404);

        $paper = PlatformDocuments::pdf($doc);
        $paper->headers->set('Cache-Control', 'no-store, private');

        return $paper;
    }

    private function find(string $token): PlatformDocument
    {
        $doc = PlatformDocument::where('token', $token)->first();
        abort_if($doc === null, 404);

        return $doc;
    }
}
