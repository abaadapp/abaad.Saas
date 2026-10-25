<?php

namespace App\Http\Controllers;

use App\Models\PlatformDocument;
use App\Support\AlreadySigned;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ما يفتحه العميلُ من رابط المستند — يقرأ ويكمل بياناته ويوقّع، بلا حساب ولا رمز.
 *
 * والرابطُ وحده مفتاحه: ٣٢ حرفًا لا تُخمَّن، ورمزٌ مجهولٌ ٤٠٤ لا يقول أكثر.
 * والموقَّعُ يُعرض كما وُقِّع، ولا يُعاد توقيعُه ولا يُعدَّل.
 *
 * وما يعرضه الرابطُ يضيق بحاله (`PlatformDocument::readable`): الملغى والمنتهي
 * وما مضت أيّامُ نسخته يقول حالَه وحدها. والموقَّعُ يُعرض وتُنزَّل ورقتُه للمتصفّح
 * الذي وقّع وحده — كوكي `HttpOnly` على مسار الرابط يحمل مفتاحَ نسخته، ومن مُرِّر
 * إليه الرابطُ بعدها يرى «وُقِّع» فقط. و`no-store` على الصفحة والورقة: رقمٌ مدنيٌّ
 * وتوقيعٌ لا يبقيان في ذاكرة متصفّحٍ مشترك.
 *
 * والتوقيعُ يصل من الصفحة طلبَ JSON: إن انقطعت الشبكة بقي الرسمُ في اللوحة
 * ويُعاد الإرسال، ولا تُقال «نجح» إلّا بعد أن يُحفظ.
 */
class ContractSigningController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $doc = $this->find($token);

        // أوّلُ فتحٍ يُكتب — يقول للمدير إنّ الرابط فُتح (وقد تفتحه معاينةُ واتساب)
        if ($doc->viewed_at === null && $doc->status === PlatformDocument::SENT) {
            PlatformDocument::whereKey($doc->id)->whereNull('viewed_at')->update(['viewed_at' => now()]);
        }

        $readable = $doc->readable($request->cookie(PlatformDocument::COPY_COOKIE));
        $form = $doc->form ?: null;

        return response()->view('public.sign', [
            'doc' => $doc,
            'token' => $token,
            'readable' => $readable,
            'provider' => PlatformDocuments::providerOf($doc),
            'fields' => PlatformDocuments::FIELDS,
            'consent' => PlatformDocuments::CONSENT,
            'signature' => $readable && $doc->status === PlatformDocument::SIGNED ? PlatformDocuments::signatureDataUri($doc) : null,
            // النموذج: ما كتبه الفريق، وما أجاب به العميل — أو ما يُقترح له قبل أن يجيب
            'form' => $readable ? $form : null,
            'team' => $doc->team ?? [],
            'answers' => $doc->status === PlatformDocument::SIGNED
                ? ($doc->answers ?? [])
                : array_replace(DocumentForms::prefill($form, $doc->client ?? []), (array) old('answers', [])),
            'asksClient' => DocumentForms::asksClient($form),
            'amountInForm' => DocumentForms::showsAmount($form),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sign(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $doc = $this->find($token);
        $json = $request->expectsJson();
        $page = route('documents.sign', $token);

        $validator = Validator::make($request->all(), [
            'client' => ['nullable', 'array'],
            'client.*' => ['nullable', 'string', 'max:190'],
            'answers' => ['nullable', 'array'],
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'max:'.(int) (PlatformDocuments::MAX_SIGNATURE_BYTES * 1.4)],
        ], [
            'agree.accepted' => __('وافق على الشروط والأحكام'),
            'signature.required' => __('أضف توقيعك.'),
        ], [
            'client' => __('بيانات العميل'),
            'answers' => __('إجابات النموذج'),
            'agree' => __('الموافقة'),
            'signature' => __('التوقيع'),
        ]);
        // صورةُ التوقيع لا تُحفظ في الجلسة مع الخطأ
        $keep = $request->except(['signature']);
        if ($validator->fails()) {
            return $json
                ? response()->json(['message' => $validator->errors()->first()], 422)
                : back()->withInput($keep)->withErrors($validator);
        }
        $data = $validator->validated();
        $copyKey = Str::random(40);

        try {
            PlatformDocuments::sign($doc, $data['client'] ?? [], $data['signature'], $request->ip(), $request->userAgent(), $copyKey, $data['answers'] ?? []);
        } catch (AlreadySigned $e) {
            // ربما نجح طلبٌ قبله وضاع ردُّه — الصفحةُ تُعاد فتُري النسخةَ الموقَّعة
            return $json
                ? response()->json(['message' => $e->getMessage(), 'redirect' => $page], 409)
                : back()->withInput($keep)->withErrors(['sign' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return $json
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withInput($keep)->withErrors(['sign' => $e->getMessage()]);
        }

        session()->flash('done', true);

        // مفتاحُ النسخة لهذا المتصفّح وحده: لا يقرؤه جافاسكربت، ولا يُرسَل إلّا إلى مسار هذا الرابط
        $copy = cookie(PlatformDocument::COPY_COOKIE, $copyKey, PlatformDocument::COPY_DAYS * 24 * 60,
            parse_url($page, PHP_URL_PATH) ?: '/', null, $request->isSecure(), true, false, 'lax');

        return ($json
            ? response()->json(['ok' => true, 'redirect' => $page])
            : redirect($page))->withCookie($copy);
    }

    /** الورقةُ لمن وقّع على جهازه وفي أيّام نسخته — وغيرُه يطلبها من مدير المنصّة */
    public function pdf(Request $request, string $token): Response
    {
        $doc = $this->find($token);
        abort_unless($doc->holdsCopy($request->cookie(PlatformDocument::COPY_COOKIE)), 404);

        $paper = PlatformDocuments::paper($doc);
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
