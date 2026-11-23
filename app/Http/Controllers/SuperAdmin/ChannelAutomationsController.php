<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\ChannelAutomationsSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ‏‏مديرُ المنصّة يفتح أتمتةَ الردود بالكلمات — لمتاجر تجربةٍ بأرقامها، أو للجميع، أو يقفلها.
 */
class ChannelAutomationsController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(ChannelAutomationsSwitch::MODES)],
            'pilot' => ['nullable', 'string', 'max:600'],
        ], [], ['mode' => __('حال الأتمتة'), 'pilot' => __('متاجر التجربة')]);

        $r = ChannelAutomationsSwitch::set($data['mode'], (string) ($data['pilot'] ?? ''));

        if (! $r['ok']) {
            return back()->withErrors(['pilot' => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $r['changed']
                ? __('أتمتة الردود بالكلمات: :state.', ['state' => __(ChannelAutomationsSwitch::label($data['mode']))])
                : __('أتمتة الردود بالكلمات كما هي — لم يتغيّر شيء.'),
            'type' => $r['changed'] ? 'success' : 'info',
        ]);
    }
}
