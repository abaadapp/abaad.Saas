<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ChannelAutomation;
use App\Support\ChannelAutomations;
use App\Support\Demo;
use App\Support\KeywordMatch;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ‏‏أتمتةُ واتساب عند التاجر — يكتب كلماتٍ وردًّا، فيخرج الردّ وحده حين يكتبها زبون.
 *
 * تُفتح للمتجر بمفتاح المنصّة وحده (`ChannelAutomationsSwitch`) — وغيرُه لا يرى الصفحة (404).
 * والقراءةُ لمن مُنح قسم التكاملات، والكتابةُ لصاحب الحساب كالردّ الترحيبيّ: الردُّ يخرج برقم
 * المتجر وباسمه.
 */
class WhatsAppAutomationsController extends Controller
{
    public function index(): Response
    {
        $business = $this->business();

        return Inertia::render('Admin/Integrations/WhatsappAutomations', [
            'view' => ChannelAutomations::view($business, $this->mayManage()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['name' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $r = ChannelAutomations::save($business, $this->validated($request, $business), null, $request->user());

        if (! $r['ok']) {
            return back()->withErrors(['status' => $r['message']]);
        }

        return back()->with('toast', ['msg' => $this->said($r['automation']), 'type' => 'success']);
    }

    public function update(Request $request, int $automation): RedirectResponse
    {
        $business = $this->business();
        $row = $this->find($business, $automation);

        if (! $this->mayManage()) {
            return back()->withErrors(['name' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $r = ChannelAutomations::save($business, $this->validated($request, $business), $row, $request->user());

        if (! $r['ok']) {
            return back()->withErrors(['status' => $r['message']]);
        }

        return back()->with('toast', ['msg' => $this->said($r['automation']), 'type' => 'success']);
    }

    public function status(Request $request, int $automation): RedirectResponse
    {
        $business = $this->business();
        $row = $this->find($business, $automation);

        if (! $this->mayManage()) {
            return back()->withErrors(['status' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        $data = $request->validate(['status' => ['required', Rule::in([ChannelAutomation::PUBLISHED, ChannelAutomation::PAUSED])]]);
        $r = ChannelAutomations::setStatus($row, $data['status']);

        if (! $r['ok']) {
            return back()->withErrors(['status' => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $data['status'] === ChannelAutomation::PUBLISHED ? __('شُغّلت الأتمتة.') : __('أُوقفت الأتمتة — لا يخرج منها رد حتى تشغّلها.'),
            'type' => 'success',
        ]);
    }

    public function destroy(int $automation): RedirectResponse
    {
        $business = $this->business();
        $row = $this->find($business, $automation);

        if (! $this->mayManage()) {
            return back()->withErrors(['status' => __('هذا الإعداد لصاحب الحساب.')]);
        }

        ChannelAutomations::delete($row);

        return back()->with('toast', ['msg' => __('حُذفت الأتمتة.'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Business $business): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:'.ChannelAutomations::MAX_NAME],
            'template' => ['nullable', 'string', 'max:40'],
            'match' => ['required', Rule::in(KeywordMatch::MODES)],
            'keywords' => ['array', 'max:'.ChannelAutomations::MAX_KEYWORDS],
            'keywords.*' => ['string', 'max:'.ChannelAutomations::MAX_KEYWORD],
            'excluded' => ['nullable', 'array', 'max:'.ChannelAutomations::MAX_KEYWORDS],
            'excluded.*' => ['string', 'max:'.ChannelAutomations::MAX_KEYWORD],
            'reply_ar' => ['nullable', 'string', 'max:'.ChannelAutomations::MAX_TEXT],
            'reply_en' => ['nullable', 'string', 'max:'.ChannelAutomations::MAX_TEXT],
            'buttons' => ['nullable', 'array', 'max:'.ChannelAutomations::MAX_BUTTONS],
            'buttons.*.type' => ['required', Rule::in([ChannelAutomations::BUTTON_REPLY, ChannelAutomations::BUTTON_LINK])],
            'buttons.*.title' => ['required', 'string', 'max:'.ChannelAutomations::BUTTON_TITLE_MAX],
            'buttons.*.url' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                $v = trim((string) $value);

                if ($v !== '' && ! in_array($v, [...ChannelAutomations::VARIABLES['store_link'], ...ChannelAutomations::VARIABLES['product_link']], true)
                    && ! preg_match('~^https://[^\s]+$~i', $v)) {
                    $fail(__('رابط الزر يبدأ بـ https:// أو يكون {رابط_المتجر} أو {رابط_المنتج}.'));
                }
            }],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $business->id)],
            'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:'.ChannelAutomations::COOLDOWN_MAX],
            'status' => ['required', Rule::in([ChannelAutomation::DRAFT, ChannelAutomation::PUBLISHED])],
        ], [], [
            'name' => __('اسم الأتمتة'),
            'match' => __('طريقة المطابقة'),
            'keywords' => __('الكلمات'),
            'keywords.*' => __('الكلمة'),
            'excluded' => __('الكلمات المستثناة'),
            'excluded.*' => __('الكلمة المستثناة'),
            'reply_ar' => __('نص الرد بالعربية'),
            'reply_en' => __('نص الرد بالإنجليزية'),
            'buttons' => __('الأزرار'),
            'buttons.*.type' => __('نوع الزر'),
            'buttons.*.title' => __('عنوان الزر'),
            'buttons.*.url' => __('رابط الزر'),
            'product_id' => __('المنتج'),
            'cooldown_minutes' => __('مدة الانتظار'),
        ]) + ['buttons' => []];
    }

    private function said(?ChannelAutomation $automation): string
    {
        return $automation?->status === ChannelAutomation::PUBLISHED
            ? __('نُشرت الأتمتة — يخرج الرد حين يكتب زبون إحدى كلماتها.')
            : __('حُفظت الأتمتة مسوّدة — لا يخرج منها رد حتى تنشرها.');
    }

    private function find(Business $business, int $id): ChannelAutomation
    {
        return ChannelAutomation::where('business_id', $business->id)->where('channel', ChannelAutomations::WHATSAPP)->whereKey($id)->firstOrFail();
    }

    private function business(): Business
    {
        $business = Business::findOrFail(auth()->user()->business_id ?? Demo::bid());

        abort_unless(ChannelAutomations::available($business), 404);

        return $business;
    }

    private function mayManage(): bool
    {
        return Permissions::isOwner(auth()->user());
    }
}
