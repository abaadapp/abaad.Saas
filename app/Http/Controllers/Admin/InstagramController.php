<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ChannelAutomation;
use App\Support\ChannelAutomations;
use App\Support\Demo;
use App\Support\InstagramAutomations;
use App\Support\InstagramLink;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * ‏‏إنستقرام عند التاجر — «تسجيل الدخول عبر Instagram» ثمّ الرجوعُ مربوطًا، والفصلُ.
 *
 * تُفتح للمتجر بمفتاح المنصّة وحده (`InstagramPlatform`) — وغيرُه لا يرى الصفحة (404). والربطُ والفصلُ
 * لصاحب الحساب وحده كواتساب: تفويضٌ باسم النشاط عند Meta يردّ على تعليقات زبائنه ويقرأ رسائلهم.
 */
class InstagramController extends Controller
{
    public function show(): Response
    {
        $business = $this->business();

        return Inertia::render('Admin/Integrations/Instagram', [
            'instagram' => [
                'link' => InstagramLink::view(InstagramLink::forBusiness((int) $business->id)),
                'may_manage' => $this->mayManage(),
                'scopes' => InstagramLink::SCOPES,
                // ‏‏والأتمتةُ لمتجرٍ فُتحت له — وكم منها يعمل
                'automations' => InstagramAutomations::available($business) ? [
                    'published' => ChannelAutomation::where('business_id', $business->id)->where('channel', ChannelAutomations::INSTAGRAM)->where('status', ChannelAutomation::PUBLISHED)->count(),
                    'total' => ChannelAutomation::where('business_id', $business->id)->where('channel', ChannelAutomations::INSTAGRAM)->count(),
                ] : null,
            ],
        ]);
    }

    /** ‏‏إلى صفحة إنستقرام — بختمٍ لمرّةٍ واحدة. وهو POST: يكتب الختم */
    public function connect(Request $request): HttpResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['instagram' => __('ربط إنستقرام لصاحب الحساب.')]);
        }

        return Inertia::location(InstagramLink::authorizeUrl($business, $request->user()));
    }

    /** ‏‏الرجوعُ من إنستقرام — والجوابُ جملةٌ على الصفحة، نجح أو لم ينجح */
    public function callback(Request $request): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return redirect()->route('admin.integrations.instagram')->withErrors(['instagram' => __('ربط إنستقرام لصاحب الحساب.')]);
        }

        $r = InstagramLink::connect($business, $request->user(), $request->query('state'), $request->query('code'), $request->query('error'));

        return $r['ok']
            ? redirect()->route('admin.integrations.instagram')->with('toast', ['msg' => $r['message'], 'type' => 'success'])
            : redirect()->route('admin.integrations.instagram')->withErrors(['instagram' => $r['message']]);
    }

    public function disconnect(): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['instagram' => __('فصل إنستقرام لصاحب الحساب.')]);
        }

        $connection = InstagramLink::forBusiness((int) $business->id);

        if ($connection !== null) {
            InstagramLink::disconnect($connection, auth()->user());
        }

        return back()->with('toast', ['msg' => __('فُصل حساب إنستقرام — لا يصل منه شيء بعد الآن.'), 'type' => 'success']);
    }

    private function business(): Business
    {
        $business = Business::findOrFail(auth()->user()->business_id ?? Demo::bid());

        abort_unless(InstagramLink::available($business), 404);

        return $business;
    }

    private function mayManage(): bool
    {
        return Permissions::isOwner(auth()->user());
    }
}
