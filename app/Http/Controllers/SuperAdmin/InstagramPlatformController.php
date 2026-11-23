<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\InstagramPlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ‏‏مديرُ المنصّة يكتب معرّف تطبيق إنستقرام وسرّه، ويفتح الربط لمتاجر تجربةٍ أو للجميع.
 */
class InstagramPlatformController extends Controller
{
    public function credentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'string', 'max:25'],
            'app_secret' => ['nullable', 'string', 'max:200'],
        ], [], ['app_id' => __('معرّف تطبيق إنستقرام'), 'app_secret' => __('سر تطبيق إنستقرام')]);

        $r = InstagramPlatform::setCredentials((string) ($data['app_id'] ?? ''), $data['app_secret'] ?? null);

        if (! $r['ok']) {
            return back()->withErrors(['app_id' => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $r['changed'] ? __('حُفظ تطبيق إنستقرام.') : __('تطبيق إنستقرام كما هو — لم يتغيّر شيء.'),
            'type' => $r['changed'] ? 'success' : 'info',
        ]);
    }

    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(InstagramPlatform::MODES)],
            'pilot' => ['nullable', 'string', 'max:600'],
        ], [], ['mode' => __('حال ربط إنستقرام'), 'pilot' => __('متاجر التجربة')]);

        $r = InstagramPlatform::setMode($data['mode'], (string) ($data['pilot'] ?? ''));

        if (! $r['ok']) {
            return back()->withErrors(['pilot' => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $r['changed']
                ? __('ربط إنستقرام: :state.', ['state' => __(InstagramPlatform::label($data['mode']))])
                : __('ربط إنستقرام كما هو — لم يتغيّر شيء.'),
            'type' => $r['changed'] ? 'success' : 'info',
        ]);
    }
}
