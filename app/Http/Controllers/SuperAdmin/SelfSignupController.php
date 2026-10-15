<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\Lists\SelfSignupsList;
use App\Support\Pagination;
use App\Support\Search;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التسجيلاتُ الذاتيّة كلُّها — «عرض الكل» من قسمها في صفحة الشركات.
 *
 * في مجموعة `super-admin` بحرسها (`role:super_admin`)، ولا بابَ لها في
 * الشريط الجانبيّ: تُبلغ من صفحة الشركات وحدها. واستعلامُها منصّةٌ مقصودة —
 * لا يُقرأ فيه رقمُ نشاطٍ من الطلب، ولا يصل تاجرًا.
 */
class SelfSignupController extends Controller
{
    public function index(Request $request): Response
    {
        $page = SelfSignupsList::query($request)->paginate(SelfSignupsList::PER_PAGE)->withQueryString();

        return Inertia::render('Platform/Businesses/SelfSignups', [
            'rows' => SelfSignupsList::rows($page->getCollection()),
            'pagination' => Pagination::meta($page),
            'filters' => [
                'q' => Search::term($request),
                'state' => SelfSignupsList::state($request),
            ],
            'stats' => SelfSignupsList::stats(),
        ]);
    }
}
