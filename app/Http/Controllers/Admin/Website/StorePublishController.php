<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WebsiteVersion;
use App\Support\Activity;
use App\Support\Store\StaleDraft;
use App\Support\Store\StoreContent;
use App\Support\Store\ThemePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * نشرُ متجر الواجهة الخاصّة واسترجاعُ نشرةٍ منه.
 *
 * وهو في معناه `BuilderController::publish` و`restore` — والفرقُ أنّ
 * محرّكَ العرض آخر. والدورةُ مشتركةٌ في `Store\ThemePublisher`، فما يُصلَح
 * في القفل أو في الرقم يُصلَح للاثنين.
 */
class StorePublishController extends Controller
{
    use Concerns;

    /**
     * «نشر التغييرات».
     *
     * ولا يُقال «نُشر بنجاح» إلّا بعد أن تُكتب النشرةُ فعلًا: من ضغط مرّتين
     * أو نشر بلا تغييرٍ يُقال له «الموقع محدّث» — لا يُكذَب عليه بنجاحٍ لم
     * يقع، ولا يُصفع بخطأٍ لم يُخطئه.
     */
    public function publish(Request $request): RedirectResponse
    {
        $business = Business::findOrFail($this->bid());

        if ($business->storefrontTheme() === null || ! StoreContent::usesDrafts((int) $business->id)) {
            abort(404);
        }

        try {
            $version = ThemePublisher::publish(
                $business,
                auth()->id(),
                (string) $request->input('note', ''),
                // ومَن نشر ما لم يره يُردّ — انظر `ThemePublisher::publish`
                $request->filled('revision') ? $request->integer('revision') : null,
            );
        } catch (StaleDraft) {
            return back()->withErrors([
                'publish' => __('تبدّلت المسودة بعد أن فتحت الشاشة — راجعها ثم انشر.'),
            ]);
        }

        if ($version === null) {
            return back()->with('toast', ['msg' => __('الموقع محدّث'), 'type' => 'info']);
        }

        Activity::log('updated', 'نشر متجره الإلكتروني — نشرة '.$version->number);

        return back()->with('toast', [
            'msg' => __('نُشر متجرك — نشرة :n', ['n' => $version->number]), 'type' => 'success',
        ]);
    }

    /**
     * «استرجاع نسخة» — إلى المسوّدة لا إلى الموقع.
     *
     * فيراها صاحبُها ويراجعها ثمّ ينشرها بيده. ولو كُتبت على الحيّ رأسًا
     * لَتبدّل موقعٌ يعمل بضغطةٍ في شاشة تاريخ.
     */
    public function restore(int $version): RedirectResponse
    {
        $business = Business::findOrFail($this->bid());

        // والنشرةُ تُقرأ بنشاطها لا برقمها وحدَه — فلا تُسترجَع نشرةُ جار
        $row = WebsiteVersion::where('business_id', $business->id)
            ->where('kind', WebsiteVersion::THEME)
            ->findOrFail($version);

        abort_unless(ThemePublisher::restore($business, $row, auth()->id()), 404);

        Activity::log('updated', 'استرجع نشرة '.$row->number.' إلى مسودة متجره');

        return back()->with('toast', [
            'msg' => __('رجعت نشرة :n إلى مسودتك — راجعها ثم انشر', ['n' => $row->number]),
            'type' => 'success',
        ]);
    }
}
