<?php

namespace App\Http\Controllers\Instagram;

use App\Http\Controllers\Controller;
use App\Models\InstagramDataDeletion;
use App\Support\InstagramPlatform;
use App\Support\InstagramWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * ‏‏أبوابُ Meta لإنستقرام — الإشعارات، وإلغاءُ الإذن، وطلبُ حذف البيانات، وصفحةُ حال الحذف.
 *
 * كلُّها خارج الجلسة ولا رمزَ CSRF لها: Meta لا تحمل جلسة. وما يُكتب منها لا يُقبل إلّا بتوقيعٍ بأحد
 * سرّينا (`InstagramPlatform::signingSecrets`).
 */
class WebhookController extends Controller
{
    /** ‏‏تحقّقُ Meta من العنوان — يُردّ التحدّي حين يطابق الرمزُ رمزَنا */
    public function verify(Request $request): Response
    {
        $ok = $request->query('hub_mode') === 'subscribe'
            && hash_equals(InstagramPlatform::verifyToken(), (string) $request->query('hub_verify_token'));

        return $ok
            ? response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain')
            : response('', 403);
    }

    public function handle(Request $request): Response
    {
        $raw = $request->getContent();

        if (! InstagramWebhooks::signatureValid($request->header('X-Hub-Signature-256'), $raw)) {
            return response('', 403);
        }

        try {
            InstagramWebhooks::store((array) json_decode($raw, true));
        } catch (\Throwable $e) {
            // ‏‏ولا يُردّ بغير 200 لعطبٍ عندنا: Meta تعيد الإشعار ساعاتٍ ويتراكم — يُبلَّغ ويُجاب
            report($e);
        }

        return response('', 200);
    }

    public function deauthorize(Request $request): JsonResponse
    {
        $data = InstagramWebhooks::signedRequest($request->input('signed_request'));

        if ($data === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        InstagramWebhooks::deauthorize((string) ($data['user_id'] ?? ''));

        return response()->json(['ok' => true]);
    }

    public function deletion(Request $request): JsonResponse
    {
        $data = InstagramWebhooks::signedRequest($request->input('signed_request'));

        if ($data === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $row = InstagramWebhooks::delete((string) ($data['user_id'] ?? ''));

        Log::info('instagram.data_deletion', ['code' => $row->confirmation_code, 'found' => $row->business_id !== null]);

        return response()->json([
            'url' => route('instagram.deletion.status', $row->confirmation_code),
            'confirmation_code' => $row->confirmation_code,
        ]);
    }

    /** ‏‏صفحةُ الحال التي يفتحها صاحبُ الحساب برمز تأكيده — بلا اسمٍ ولا متجر */
    public function status(string $code): Response
    {
        $row = InstagramDataDeletion::where('confirmation_code', $code)->first();

        abort_if($row === null, 404);

        return response()->view('instagram.deletion', ['row' => $row]);
    }
}
