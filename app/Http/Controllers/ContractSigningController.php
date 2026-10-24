<?php

namespace App\Http\Controllers;

use App\Models\PlatformDocument;
use App\Support\PlatformDocuments;
use App\Support\SigningCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ما يفتحه العميلُ من رابط المستند — يثبت رقمه ثمّ يقرأ ويكمل ويوقّع، بلا حساب.
 *
 * والرابطُ يدلّ على المستند ولا يفتحه: ٣٢ حرفًا لا تُخمَّن، ورمزٌ مجهولٌ ٤٠٤
 * لا يقول أكثر. ومن فتحه رأى عنوانَ المستند وآخرَ أربعة أرقامٍ من الهاتف
 * المعتمد، ولا يرى بيانَ العميل ولا يوقّع حتى يكتب الرمزَ الذي يصل ذلك
 * الهاتف (`SigningCodes`).
 *
 * وما يعرضه الرابطُ يضيق بحاله (`PlatformDocument::readable`): الملغى والمنتهي
 * وما مضت أيّامُ نسخته يقول حالَه وحدها. و`no-store` على الصفحة والورقة:
 * رقمٌ مدنيٌّ وتوقيعٌ لا يبقيان في ذاكرة متصفّحٍ مشترك.
 */
class ContractSigningController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $doc = $this->find($token);
        $readable = $doc->readable();
        $granted = $readable && SigningCodes::granted($request->session(), $doc);

        return response()->view('public.sign', [
            'doc' => $doc,
            'token' => $token,
            'readable' => $readable,
            'granted' => $granted,
            'available' => SigningCodes::available(),
            'pending' => $readable && ! $granted ? SigningCodes::pending($doc) : null,
            'masked' => SigningCodes::mask($doc->signing_phone),
            'provider' => PlatformDocuments::providerOf($doc),
            'fields' => PlatformDocuments::FIELDS,
            'signature' => $granted && $doc->status === PlatformDocument::SIGNED ? PlatformDocuments::signatureDataUri($doc) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** أرسل الرمز إلى الرقم المعتمد — لا رقمَ يُقبل من الطلب */
    public function code(Request $request, string $token): RedirectResponse
    {
        $doc = $this->find($token);

        try {
            SigningCodes::send($doc, $request->ip());
        } catch (RuntimeException $e) {
            return redirect()->route('documents.sign', $token)->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('documents.sign', $token)->with('code_sent', true);
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $doc = $this->find($token);

        try {
            $grant = SigningCodes::verify($doc, (string) $request->input('code', ''), $request->ip());
        } catch (RuntimeException $e) {
            // والرمزُ لا يُعاد إلى الجلسة مع الخطأ
            return redirect()->route('documents.sign', $token)->withErrors(['code' => $e->getMessage()]);
        }

        // جلسةٌ جديدة بعد الإثبات: معرّفٌ عرفه غيرُه قبلها لا يحمل الإذن
        $request->session()->regenerate();
        SigningCodes::remember($request->session(), $doc, $grant);

        return redirect()->route('documents.sign', $token);
    }

    public function sign(Request $request, string $token): RedirectResponse
    {
        $doc = $this->find($token);

        $validator = Validator::make($request->all(), [
            'client' => ['nullable', 'array'],
            'client.*' => ['nullable', 'string', 'max:190'],
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'max:'.(int) (PlatformDocuments::MAX_SIGNATURE_BYTES * 1.4)],
        ], [
            'agree.accepted' => __('وافق على الشروط والأحكام'),
            'signature.required' => __('أضف توقيعك.'),
        ], [
            'client' => __('بيانات العميل'),
            'agree' => __('الموافقة'),
            'signature' => __('التوقيع'),
        ]);
        // صورةُ التوقيع لا تُحفظ في الجلسة مع الخطأ — يرسمها من جديد
        $keep = $request->except(['signature', 'code']);
        if ($validator->fails()) {
            return back()->withInput($keep)->withErrors($validator);
        }
        $data = $validator->validated();

        try {
            PlatformDocuments::sign(
                $doc, $data['client'] ?? [], $data['signature'], $request->ip(), $request->userAgent(),
                SigningCodes::held($request->session(), $doc),
            );
        } catch (RuntimeException $e) {
            return back()->withInput($keep)->withErrors(['sign' => $e->getMessage()]);
        }

        return redirect()->route('documents.sign', $token)->with('done', true);
    }

    /** الورقةُ بعد التوقيع وفي أيّام نسخته، لمتصفّحٍ أثبت الرقم — وبعدها تُطلب من مدير المنصّة */
    public function pdf(Request $request, string $token): Response
    {
        $doc = $this->find($token);
        abort_unless($doc->copyOpen(), 404);
        if (! SigningCodes::granted($request->session(), $doc)) {
            return redirect()->route('documents.sign', $token);
        }

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
