<?php

namespace App\Http\Controllers\Admin;

use App\Models\Business;
use App\Support\ChannelAutomations;
use App\Support\InstagramAutomations;
use App\Support\InstagramLink;
use App\Support\InstagramMessenger;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ‏‏أتمتةُ إنستقرام عند التاجر — تُفتح بمفتاحين: إنستقرام مفتوحٌ لمتجره، والأتمتةُ مفتوحةٌ له. وغيرُه 404.
 *
 * ومن لم يربط حسابه بعدُ يكتب أتمتته ويحفظها؛ ولا يخرج منها ردٌّ حتى يربط (`InstagramAutomations::answer`).
 */
class InstagramAutomationsController extends AutomationsController
{
    public function index(): Response
    {
        $business = $this->business();

        return Inertia::render($this->page(), [
            'view' => ChannelAutomations::view($business, $this->mayManage(), $this->channel()),
            'link' => InstagramLink::view(InstagramLink::forBusiness((int) $business->id)),
        ]);
    }

    /** ‏‏منشوراتُ الحساب لمنتقي المعالج — بطلب التاجر لا مع الصفحة */
    public function posts(): JsonResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return response()->json(['ok' => false, 'posts' => [], 'error' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $connection = InstagramLink::forBusiness((int) $business->id);

        return response()->json($connection === null
            ? ['ok' => false, 'posts' => [], 'error' => __('اربط حساب إنستقرام أولًا لتختار من منشوراته.')]
            : InstagramMessenger::posts($connection));
    }

    protected function channel(): string
    {
        return ChannelAutomations::INSTAGRAM;
    }

    protected function page(): string
    {
        return 'Admin/Integrations/InstagramAutomations';
    }

    protected function open(Business $business): bool
    {
        return InstagramAutomations::available($business);
    }
}
