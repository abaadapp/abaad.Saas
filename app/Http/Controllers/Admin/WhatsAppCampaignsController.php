<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignTemplate;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\WhatsAppCampaignAudience;
use App\Support\WhatsAppCampaigns;
use App\Support\WhatsAppCampaignTemplates;
use App\Support\WhatsAppOptOuts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ‏‏حملاتُ واتساب عند التاجر — قوالبُها ومن تصلهم وما خرج منها.
 *
 * تُفتح للمتجر بمفتاح المنصّة وحده (`WhatsAppCampaignsSwitch`) — وغيرُه 404. والقراءةُ لمن مُنح قسمَ
 * التسويق، والكتابةُ لصاحب الحساب وحده: كلُّ رسالةٍ تُحتسب عليه عند Meta وتخرج باسمه.
 */
class WhatsAppCampaignsController extends Controller
{
    public function index(): Response
    {
        $business = $this->business();

        return Inertia::render('Admin/Marketing/WhatsappCampaigns', ['view' => WhatsAppCampaigns::view($business, $this->mayManage())]);
    }

    /** ‏‏كم تصلهم الآن، ومن خرج ولماذا — يُقرأ مع كلّ تغييرٍ في الاختيار */
    public function preview(Request $request): JsonResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return response()->json(['ok' => false, 'error' => __('هذا الإعداد لصاحب الحساب.')], 403);
        }

        $r = WhatsAppCampaignAudience::resolve($business, WhatsAppCampaignAudience::clean($business, $this->audience($request)));

        return response()->json(['ok' => true, 'count' => count($r['people']), 'skipped' => $r['skipped']]);
    }

    public function store(Request $request): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['name' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'template_id' => ['required', 'integer', Rule::exists('whatsapp_campaign_templates', 'id')->where('business_id', $business->id)],
            'confirm_count' => ['required', 'integer', 'min:1'],
            'consent' => ['accepted'],
            'scheduled_at' => ['nullable', 'date'],
        ], [], [
            'name' => __('اسم الحملة'),
            'template_id' => __('القالب'),
            'confirm_count' => __('عدد الرسائل'),
            'consent' => __('إقرار الموافقة'),
            'scheduled_at' => __('وقت الإرسال'),
        ]);

        $r = WhatsAppCampaigns::launch($business, $data + ['audience' => $this->audience($request)], $request->user());

        if (! $r['ok']) {
            return back()->withErrors([$r['field'] => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $r['campaign']->status === WhatsAppCampaign::SCHEDULED
                ? __('جُدولت الحملة — تبدأ في وقتها.')
                : (WhatsAppCampaigns::quietNow() ? __('حُفظت الحملة — تبدأ في الثامنة صباحًا.') : __('بدأت الحملة — تخرج دفعة بعد دفعة.')),
            'type' => 'success',
        ]);
    }

    public function pause(int $campaign): RedirectResponse
    {
        return $this->act($campaign, fn ($b, $c) => WhatsAppCampaigns::pause($c), __('أُوقفت الحملة.'));
    }

    public function resume(int $campaign): RedirectResponse
    {
        return $this->act($campaign, fn ($b, $c) => WhatsAppCampaigns::resume($b, $c), __('استُؤنفت الحملة.'));
    }

    public function cancel(int $campaign): RedirectResponse
    {
        return $this->act($campaign, fn ($b, $c) => WhatsAppCampaigns::cancel($c), __('أُلغيت الحملة — ما لم يخرج لا يخرج.'));
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['body' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.WhatsAppCampaignTemplates::MAX_TITLE],
            'language' => ['required', Rule::in(WhatsAppCampaignTemplates::LANGUAGES)],
            'body' => ['required', 'string', 'max:'.WhatsAppCampaignTemplates::MAX_BODY],
            'button' => ['nullable', 'array'],
            'button.title' => ['nullable', 'string', 'max:'.WhatsAppCampaignTemplates::MAX_BUTTON],
            'button.url' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'title' => __('اسم القالب'),
            'language' => __('لغة القالب'),
            'body' => __('نص الرسالة'),
            'button' => __('الزر'),
            'button.title' => __('عنوان الزر'),
            'button.url' => __('رابط الزر'),
        ]);

        $r = WhatsAppCampaignTemplates::create($business, $data, $request->user());

        if (! $r['ok']) {
            return back()->withErrors(['body' => $r['message']]);
        }

        return back()->with('toast', ['msg' => __('أُرسل القالب إلى واتساب — تظهر موافقته هنا.'), 'type' => 'success']);
    }

    public function syncTemplates(): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['body' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $r = WhatsAppCampaignTemplates::sync($business);

        return $r['ok']
            ? back()->with('toast', ['msg' => __('حُدّثت حال القوالب من واتساب.'), 'type' => 'success'])
            : back()->withErrors(['templates' => $r['message']]);
    }

    public function destroyTemplate(int $template): RedirectResponse
    {
        $business = $this->business();
        $row = WhatsAppCampaignTemplate::where('business_id', $business->id)->whereKey($template)->firstOrFail();

        if (! $this->mayManage()) {
            return back()->withErrors(['templates' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $r = WhatsAppCampaignTemplates::delete($business, $row);

        return $r['ok']
            ? back()->with('toast', ['msg' => __('حُذف القالب.'), 'type' => 'success'])
            : back()->withErrors(['templates' => $r['message']]);
    }

    public function optOut(Request $request): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['phone' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $data = $request->validate(['phone' => ['required', 'string', 'max:30']], [], ['phone' => __('رقم الهاتف')]);
        $r = WhatsAppOptOuts::byStaff($business, $data['phone']);

        return $r['ok']
            ? back()->with('toast', ['msg' => __('لن تصل هذا الرقم حملة بعد الآن.'), 'type' => 'success'])
            : back()->withErrors(['phone' => $r['message']]);
    }

    private function act(int $id, callable $do, string $said): RedirectResponse
    {
        $business = $this->business();
        $campaign = WhatsAppCampaign::where('business_id', $business->id)->whereKey($id)->firstOrFail();

        if (! $this->mayManage()) {
            return back()->withErrors(['campaign' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $r = $do($business, $campaign);

        return $r['ok'] ? back()->with('toast', ['msg' => $said, 'type' => 'success']) : back()->withErrors(['campaign' => $r['message']]);
    }

    /** @return array<string, mixed> */
    private function audience(Request $request): array
    {
        $a = $request->input('audience');

        return is_array($a) ? array_filter($a, fn ($v) => is_scalar($v) || $v === null) : [];
    }

    private function business(): Business
    {
        $business = Business::findOrFail(auth()->user()->business_id ?? Demo::bid());

        abort_unless(WhatsAppCampaigns::available($business), 404);

        return $business;
    }

    private function mayManage(): bool
    {
        return Permissions::isOwner(auth()->user());
    }
}
