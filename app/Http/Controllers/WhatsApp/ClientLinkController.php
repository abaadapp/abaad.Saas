<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppClientLink;
use App\Support\WhatsAppClientLinks;
use App\Support\WhatsAppEmbeddedSignup;
use App\Support\WhatsAppOnboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * ‏‏ما يفتحه صاحبُ المتجر من رابط ربط واتساب — صفحةٌ بلا حسابٍ ولا دخول (`WhatsAppClientLinks`).
 *
 * ═══ والرمزُ لا يبقى في العنوان ═══
 *
 * عدّةُ Meta في المتصفّح ترسل عنوانَ الصفحة إليها (`fallback_redirect_uri`)، والعنوانُ يبقى في سجلّ
 * المتصفّح. فالرابطُ يُقرأ مرّةً ويُكتب رقمُ صفّه في الجلسة، ويُحوَّل إلى صفحةٍ عنوانُها بلا رمز.
 *
 * ‏‏ولا تعرض الصفحةُ من المتجر إلّا اسمَه. والرقمُ الذي رُبط لا يراه إلّا المتصفّحُ الذي ربطه.
 */
class ClientLinkController extends Controller
{
    private const SESSION = 'whatsapp_client_link';

    /** ‏‏الرابطُ الذي ربطه هذا المتصفّح — له وحده الرقمُ ورمزُ تفعيله */
    private const DONE = 'whatsapp_client_link_done';

    public function open(Request $request, string $token): RedirectResponse
    {
        $link = WhatsAppClientLinks::find($token);
        abort_if($link === null, 404);

        $request->session()->put(self::SESSION, $link->id);

        return redirect()->route('clientLink.show');
    }

    public function show(Request $request): Response
    {
        $link = $this->current($request);
        $state = $link ? WhatsAppClientLinks::pageState($link) : WhatsAppClientLinks::MISSING;
        $mine = $link !== null && $this->mine($request, $link);

        if ($link && $state === WhatsAppClientLinks::OPEN) {
            WhatsAppClientLinks::opened($link, $request->userAgent());
        }

        $response = Inertia::render('Auth/WhatsappLink', [
            'link' => [
                'state' => $state,
                // ‏‏واسمُ المتجر لرابطٍ يعمل أو لمن ربط به — لا لرابطٍ ميّتٍ في يد غيره
                'shop' => $state === WhatsAppClientLinks::OPEN || $mine ? $link?->business?->name : null,
                'expires' => $state === WhatsAppClientLinks::OPEN
                    ? $link->expires_at->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i') : null,
                'phone' => $mine ? $link->connected_phone : null,
                'needs_pin' => $mine && WhatsAppClientLinks::awaitsPin($link),
            ],
            // ‏‏وإعدادُ نافذة Meta لرابطٍ يعمل وحده — ولا سرَّ فيه
            'config' => $state === WhatsAppClientLinks::OPEN ? WhatsAppEmbeddedSignup::publicConfig() : null,
            'year' => now()->year,
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function connect(Request $request): RedirectResponse
    {
        $link = $this->current($request);

        if ($link === null) {
            return $this->back()->withErrors(['code' => __('افتح الرابط الذي وصلك من جديد.')]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'min:10', 'max:2000'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'phone_number_id' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:'.implode(',', WhatsAppOnboarding::TYPES)],
            // ‏‏رابطٌ وصل إلى غير صاحبه يربط رقمَه بمتجرٍ ليس له — فيُقرّ باسم المتجر قبل أن يربط
            'shop_confirmed' => ['accepted'],
        ], ['shop_confirmed.accepted' => __('أكّد أنّ المتجر متجرك قبل الربط.')]);

        $result = WhatsAppClientLinks::connect(
            $link,
            $data['code'],
            $data['waba_id'] ?? null,
            $data['phone_number_id'] ?? null,
            $data['type'] ?? WhatsAppOnboarding::CLOUD_API,
            $request->ip(),
        );

        if (! $result['ok']) {
            return $this->back()->withErrors([$result['field'] => $result['message']]);
        }

        if (WhatsAppClientLink::whereKey($link->id)->whereNotNull('connected_at')->exists()) {
            $request->session()->put(self::DONE, $link->id);
        }

        return $this->back()->with('status', $result['message']);
    }

    /** ‏‏تفعيلُ رقمٍ جديد برمزه — لمن ربطه من هذا المتصفّح، ولا يُحفظ الرمز */
    public function register(Request $request): RedirectResponse
    {
        $link = $this->current($request);

        if ($link === null || ! $this->mine($request, $link)) {
            return $this->back()->withErrors(['pin' => __('لا رقم ينتظر التفعيل من هذه الصفحة — فعّله من حساب متجرك في أبعاد.')]);
        }

        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $result = WhatsAppClientLinks::register($link, $data['pin']);

        return $result['ok']
            ? $this->back()->with('status', $result['message'])
            : $this->back()->withErrors(['pin' => $result['message']]);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('clientLink.show');
    }

    private function current(Request $request): ?WhatsAppClientLink
    {
        $id = (int) $request->session()->get(self::SESSION);

        return $id > 0 ? WhatsAppClientLink::with('business')->find($id) : null;
    }

    private function mine(Request $request, WhatsAppClientLink $link): bool
    {
        return (int) $request->session()->get(self::DONE) === (int) $link->id;
    }
}
