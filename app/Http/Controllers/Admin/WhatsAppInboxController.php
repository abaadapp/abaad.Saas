<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMedia;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «محادثات واتساب» — صندوقُ المتجر مع زبائنه (`App\Support\WhatsAppInbox`).
 *
 * ═══ والحارسُ في كلّ طلب ═══
 *
 * المسارُ تحت `may:whatsapp.inbox.view` (والإرسالُ تحت `…reply`)، ويُسأل هنا
 * ثانيةً أنّ الصندوق مفتوحٌ لهذا المتجر. فمن سُحبت صلاحيتُه والصفحةُ مفتوحةٌ
 * أمامه يُردّ في أوّل تحديثٍ أو إرسال — لا يُكمل بما حمّلته الصفحة.
 *
 * والمتجرُ من الجلسة وحدها: لا `business_id` يُقرأ من الطلب، والمحادثةُ
 * والمرفقُ يُطلبان بمعرّفهما داخل متجر الجلسة — وما سواهما 404.
 */
class WhatsAppInboxController extends Controller
{
    private function business(Request $request): Business
    {
        $business = $request->user()->business;

        abort_unless(WhatsAppInbox::available($business), 404);

        return $business;
    }

    public function index(Request $request): Response
    {
        $business = $this->business($request);
        $user = $request->user();
        $q = (string) $request->query('q', '');

        $page = WhatsAppInbox::page($business, $user, $q);

        $thread = null;
        $chatId = (string) $request->query('c', '');

        if (ctype_digit($chatId) && ($chat = WhatsAppInbox::find($business, (int) $chatId))) {
            WhatsAppInbox::markRead($chat, $user);
            $check = WhatsAppInbox::check($chat);

            $thread = WhatsAppInbox::contact($chat, $user) + WhatsAppInbox::thread($chat) + [
                'blocked' => $check['reason'],
                // وخارج النافذة: قالبُ المتابعة المعتمَد إن وُجد — اسمُه وحده، والنصُّ من ميتا عند الإرسال
                'template' => $check['template'] ? (string) $check['template']->template_name : null,
                // وما خرج إليه من إشعاراتٍ كلُّها في السجلّ — لمن يملك قسمه
                'log_url' => $user->allows('marketing') ? route('admin.marketing.whatsapp.log', ['q' => (string) $chat->customer_phone]) : null,
                // وطلبُه الآليّ إن كان: حالُه ومبلغُه وفاتورتُه ورابطُه (`WhatsAppOrders::forChat`)
                'order' => WhatsAppOrders::forChat($chat),
            ];
        }

        return Inertia::render('Admin/WhatsAppInbox/Index', [
            'chats' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'params' => ['q' => $q, 'c' => $thread ? (string) $thread['id'] : null],
            'thread' => $thread,
            'canReply' => WhatsAppInbox::canReply($user),
            'line' => self::line($business),
            /*
             * وحالُ الربط بخطواته — من الحلّال الذي يسأله المُرسِل نفسُه.
             *
             * ربطٌ ناقصٌ أو منقطع لا يُغلق الصندوق: المحادثاتُ تُقرأ، ويُقال فوقها
             * ما ينقص وأين يُصلَح. وزرُّ الربط لمن يملكه وحده.
             */
            'readiness' => WhatsAppFeature::readiness($business),
            'mayManage' => (bool) $user->isAdmin(),
            'unread' => WhatsAppInbox::unreadTotal($user),
            'stamp' => WhatsAppInbox::stamp($business),
            'files' => [
                'max' => WhatsAppMedia::MAX_FILES,
                'max_kb' => WhatsAppMedia::MAX_KB,
                'accept' => '.'.implode(',.', WhatsAppMedia::EXTENSIONS),
            ],
        ]);
    }

    /**
     * أوصل جديد؟ — يسأله الصندوقُ المفتوح كلَّ بضع ثوانٍ.
     *
     * ختمٌ وعددٌ لا رسائل: الجديدُ يُقرأ بتحديث الصفحة نفسِها، فيمرّ بالحارس
     * نفسِه. ومن سُحبت صلاحيتُه يلقى ٤٠٣ هنا فتُغلق الشاشةُ ما أمامه.
     */
    public function poll(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return response()->json([
            'stamp' => WhatsAppInbox::stamp($business),
            'unread' => WhatsAppInbox::unreadTotal($request->user()),
            'can_reply' => WhatsAppInbox::canReply($request->user()),
        ]);
    }

    /** رسائلُ أقدم — بمعرّف المحادثة داخل متجر الجلسة */
    public function older(Request $request, int $chat): JsonResponse
    {
        $found = WhatsAppInbox::find($this->business($request), $chat);

        abort_unless($found !== null, 404);

        $before = $request->query('before');

        return response()->json(WhatsAppInbox::thread($found, ctype_digit((string) $before) ? (int) $before : null));
    }

    /**
     * ردٌّ من موظّف — نصٌّ أو ملفّاتٌ أو كلاهما.
     *
     * والمرسِلُ من الجلسة، والرقمُ من حال المحادثة (`WhatsAppInbox::check`)؛
     * وما يُرسل في الطلب غيرَ النصّ والملفّات لا يُقرأ.
     */
    public function reply(Request $request, int $chat): RedirectResponse
    {
        $found = WhatsAppInbox::find($this->business($request), $chat);

        abort_unless($found !== null, 404);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.WhatsAppInbox::MAX_BODY],
            ...WhatsAppMedia::rules('files'),
        ]);

        $files = array_values($request->file('files', []));

        if (trim((string) ($data['body'] ?? '')) === '' && $files === []) {
            return back()->withErrors(['body' => __('اكتب ردًّا أو أرفق ملفًّا.')]);
        }

        $sent = WhatsAppInbox::reply($found, $request->user(), (string) ($data['body'] ?? ''), $files);

        $failed = collect($sent)->where('status', 'failed')->count();

        return back()->with('toast', $failed === 0
            ? ['msg' => __('أُرسل الردّ'), 'type' => 'success']
            : ['msg' => __('لم يخرج جزءٌ من الردّ — سببُه مكتوبٌ تحته.'), 'type' => 'danger']);
    }

    /**
     * محادثةُ زبونٍ من رسالةٍ خرجت إليه — لم يكتب بعد، فلا صفَّ لها حتّى تُفتح.
     *
     * والرسالةُ من متجر الجلسة وحده، وما سواها 404 (`WhatsAppInbox::open`).
     */
    public function open(Request $request, int $message): RedirectResponse
    {
        $chat = WhatsAppInbox::open($this->business($request), $message);

        abort_unless($chat !== null, 404);

        return redirect()->route('admin.whatsappInbox.index', ['c' => $chat->id]);
    }

    /**
     * قالبُ المتابعة — لزبونٍ أُغلقت نافذتُه، من رقم المتجر وحده.
     *
     * لا شيء يُقرأ من الطلب: القالبُ والرقمُ والمتغيّرُ من حال المحادثة
     * (`WhatsAppInbox::followUp`)، والمرسِلُ من الجلسة.
     */
    public function followUp(Request $request, int $chat): RedirectResponse
    {
        $found = WhatsAppInbox::find($this->business($request), $chat);

        abort_unless($found !== null, 404);

        /*
         * والقالبُ خارج النافذة رسالةٌ تحتسبها ميتا على حساب واتساب المتجر —
         * فلا يخرج إلّا بعد أن يقرأ مُرسِلُه ذلك ويؤكّده. الزرُّ يسأل أوّلًا
         * ثمّ يرسل هذا الحقل؛ وطلبٌ بلاه يُردّ ولا يُرسل شيء. ولا بابَ آخر في
         * النظام يُرسل هذا القالب: لا مجدولَ ولا ردَّ آليًّا.
         */
        $request->validate(['cost_acknowledged' => ['accepted']]);

        $sent = WhatsAppInbox::followUp($found, $request->user());

        return back()->with('toast', $sent->status === 'failed'
            ? ['msg' => __('لم يخرج قالب المتابعة — سببُه مكتوبٌ تحته.'), 'type' => 'danger']
            : ['msg' => __('أُرسل قالب المتابعة — يُفتح الردّ حين يكتب العميل.'), 'type' => 'success']);
    }

    /**
     * يتسلّم الموظّفُ المحادثةَ من الطلب الآليّ — فيصمت الآليُّ فيها.
     *
     * ويصمت حتى يُنهى الطلبُ من هنا (`closeOrder`)، أو يمضي يومٌ بلا رسالة.
     */
    public function takeOverOrder(Request $request, int $chat): RedirectResponse
    {
        $found = WhatsAppInbox::find($this->business($request), $chat);

        abort_unless($found !== null && WhatsAppOrders::allowed((int) $found->business_id), 404);

        WhatsAppOrders::handoff($found, $request->user());

        return back()->with('toast', ['msg' => __('تسلّمت المحادثة — لن يردّ الطلب الآلي فيها'), 'type' => 'success']);
    }

    /**
     * يُنهي الطلبَ الآليَّ في المحادثة — فيعود الآليُّ يستقبل من يكتب «طلب».
     *
     * ولا يمسّ فاتورةً ولا رابطًا صدرا: يُداران من صفحتهما كأيّ فاتورة.
     */
    public function closeOrder(Request $request, int $chat): RedirectResponse
    {
        $found = WhatsAppInbox::find($this->business($request), $chat);

        abort_unless($found !== null && WhatsAppOrders::allowed((int) $found->business_id), 404);

        WhatsAppOrders::close($found, $request->user());

        return back()->with('toast', ['msg' => __('أُنهي الطلب الآلي في هذه المحادثة'), 'type' => 'success']);
    }

    /** مرفقٌ في محادثة — من متجر الجلسة وحده، وما سواه 404 */
    public function attachment(Request $request, int $attachment): StreamedResponse
    {
        $file = WhatsAppInbox::attachment($this->business($request), $attachment);

        abort_unless($file !== null && Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }

    /**
     * الرقمُ الذي يعمل عليه الصندوقُ الآن — وحالُه، بلا رمزٍ ولا معرّف.
     *
     * @return array{mode: string, number: ?string, usable: bool, status: ?string, expires_at: ?string}
     */
    private static function line(Business $business): array
    {
        $mode = WhatsAppFeature::effectiveMode($business);
        $connection = $mode === WhatsAppMode::BUSINESS_OWN
            ? WhatsAppConnections::forBusiness($business->id)
            : WhatsAppConnections::platform();
        $view = WhatsAppConnections::publicView($connection);

        return [
            'mode' => $mode,
            'number' => $mode === WhatsAppMode::BUSINESS_OWN ? ($view['display_phone_number'] ?? null) : null,
            'usable' => WhatsAppConnections::resolve($business) !== null,
            'status' => $view['status'] ?? null,
            'expires_at' => $view['expires_at'] ?? null,
        ];
    }
}
