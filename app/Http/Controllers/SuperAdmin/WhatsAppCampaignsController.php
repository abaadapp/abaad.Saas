<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\WhatsAppCampaignsSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ‏‏مديرُ المنصّة يفتح حملاتِ واتساب — لمتاجر تجربةٍ بأرقامها، أو للجميع، أو يقفلها.
 */
class WhatsAppCampaignsController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(WhatsAppCampaignsSwitch::MODES)],
            'pilot' => ['nullable', 'string', 'max:600'],
        ], [], ['mode' => __('حال الحملات'), 'pilot' => __('متاجر التجربة')]);

        $r = WhatsAppCampaignsSwitch::set($data['mode'], (string) ($data['pilot'] ?? ''));

        if (! $r['ok']) {
            return back()->withErrors(['campaigns_pilot' => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $r['changed']
                ? __('حملات واتساب: :state.', ['state' => __(WhatsAppCampaignsSwitch::label($data['mode']))])
                : __('حملات واتساب كما هي — لم يتغيّر شيء.'),
            'type' => $r['changed'] ? 'success' : 'info',
        ]);
    }
}
