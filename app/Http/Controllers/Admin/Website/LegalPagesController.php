<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\StorefrontController;
use App\Models\Business;
use App\Support\Activity;
use App\Support\MarketingSettings;
use App\Support\Store\StoreLegal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «الإعدادات ‹ الموقع الإلكتروني ‹ الصفحات القانونيّة».
 *
 * لكلّ متجرٍ صفحاتُه بلا أن يُنشئها له أحد: سياسةُ الخصوصيّة وتعليماتُ حذف
 * البيانات والشروط، بقالبٍ من بياناته يراجعه ثمّ ينشره. وروابطُها ثابتةٌ
 * تُنسخ بضغطة — يلصقها في إعدادات تطبيق Meta ويوزّعها. انظر `Store\StoreLegal`.
 *
 * وكلُّ ما هنا بمتجر الجلسة (`bid`) لا برقمٍ من الطلب: لا تُقرأ صفحاتُ متجرٍ
 * آخر ولا تُكتب. والمجموعةُ خلف «ضبط الموقع» (`Permissions::WEBSITE_CONFIGURE`).
 */
class LegalPagesController extends Controller
{
    use Concerns;

    public function index(): Response
    {
        $bid = $this->bid();
        $facts = StoreLegal::facts($bid);
        $base = rtrim($facts['website'], '/');

        return Inertia::render('Admin/Website/Legal', [
            'themed' => $this->theme() !== null,
            'facts' => $facts,
            'missing' => StoreLegal::missing($bid, $facts),
            'pages' => array_map(function (string $kind) use ($bid, $base) {
                $published = StoreLegal::published($bid, $kind);
                $source = StoreLegal::source($bid, $kind);

                return [
                    'kind' => $kind,
                    'title' => StoreLegal::title($kind, 'ar'),
                    'title_en' => StoreLegal::title($kind, 'en'),
                    'url' => $base !== '' ? $base.'/'.StoreLegal::PATHS[$kind] : null,
                    'published' => $published === null ? null : [
                        'version' => $published['version'],
                        'at' => $published['at']?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    ],
                    'from' => $source['from'],
                    'body_ar' => $source['ar'],
                    'body_en' => $source['en'],
                    'can_restore' => StoreLegal::previous($bid, $kind) !== null,
                ];
            }, StoreLegal::KINDS),
        ]);
    }

    /** الاسمُ القانونيّ وبريدُ التواصل — يُملأ بهما القالب */
    public function saveFacts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            StoreLegal::LEGAL_NAME => ['nullable', 'string', 'max:160'],
            StoreLegal::LEGAL_EMAIL => ['nullable', 'email', 'max:160'],
        ]);

        MarketingSettings::save($this->bid(), 'website', [
            StoreLegal::LEGAL_NAME => trim((string) ($data[StoreLegal::LEGAL_NAME] ?? '')),
            StoreLegal::LEGAL_EMAIL => trim((string) ($data[StoreLegal::LEGAL_EMAIL] ?? '')),
        ]);
        MarketingSettings::forget($this->bid());

        return back()->with('success', __('حُفظت بيانات الصفحات القانونية'));
    }

    public function saveDraft(Request $request, string $kind): RedirectResponse
    {
        $this->known($kind);
        $data = $this->body($request);
        StoreLegal::saveDraft($this->bid(), $kind, $data['body_ar'], $data['body_en']);

        return back()->with('success', __('حُفظت المسودة — لم يتغير شيء في الصفحة العامة'));
    }

    public function publish(Request $request, string $kind): RedirectResponse
    {
        $this->known($kind);
        $data = $this->body($request);
        $version = StoreLegal::publish($this->bid(), $kind, $data['body_ar'], $data['body_en'], auth()->id());

        Activity::log('website.legal.publish', __('نشر :page — الإصدار :n', ['page' => StoreLegal::title($kind, 'ar'), 'n' => $version]));

        return back()->with('success', __('نُشرت الصفحة — الإصدار :n', ['n' => $version]));
    }

    public function restore(string $kind): RedirectResponse
    {
        $this->known($kind);
        $version = StoreLegal::restore($this->bid(), $kind, auth()->id());

        if ($version === null) {
            return back()->with('error', __('لا إصدار سابق لهذه الصفحة'));
        }

        Activity::log('website.legal.restore', __('أعاد :page إلى نصه السابق — الإصدار :n', ['page' => StoreLegal::title($kind, 'ar'), 'n' => $version]));

        return back()->with('success', __('أُعيد النص السابق — الإصدار :n', ['n' => $version]));
    }

    /**
     * معاينةُ النصّ كما سيُنشر — ببيانات المتجر، والناقصُ علامةٌ ظاهرة.
     *
     * تُرسل الشاشةُ ما في حقولها الآن، فيرى صاحبُ المتجر ما لم يحفظه بعد.
     * والصفحةُ لا تُفهرس، ولا تُكتب في القاعدة.
     */
    public function preview(Request $request, string $kind): \Illuminate\Http\Response
    {
        $this->known($kind);
        $bid = $this->bid();
        $lang = $request->input('lang') === 'en' ? 'en' : 'ar';
        $source = StoreLegal::source($bid, $kind);
        $text = $request->has('body') ? StoreLegal::clean((string) $request->input('body')) : ($lang === 'en' ? $source['en'] : $source['ar']);
        $resolved = StoreLegal::resolve(mb_substr($text, 0, StoreLegal::MAX), $bid, $lang);

        return StorefrontController::legalView(
            Business::findOrFail($bid), $kind, $lang,
            ['ar' => $resolved, 'en' => $resolved, 'version' => 0, 'at' => null],
            '', preview: true,
        );
    }

    /** @return array{body_ar: ?string, body_en: ?string} */
    private function body(Request $request): array
    {
        return $request->validate([
            'body_ar' => ['nullable', 'string', 'max:'.StoreLegal::MAX],
            'body_en' => ['nullable', 'string', 'max:'.StoreLegal::MAX],
        ]) + ['body_ar' => null, 'body_en' => null];
    }

    private function known(string $kind): void
    {
        abort_unless(in_array($kind, StoreLegal::KINDS, true), 404);
    }
}
