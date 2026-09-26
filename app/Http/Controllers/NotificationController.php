<?php

namespace App\Http\Controllers;

use App\Models\DismissedNotification;
use App\Models\NotificationState;
use App\Models\Order;
use App\Models\User;
use App\Support\Contention;
use App\Support\Demo;
use App\Support\Notifications;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * تغذية الإشعارات (JSON) لاستطلاعها من المتصفح وإظهار إشعارات فورية للطلبات الجديدة.
 *
 * ═══ ولا يُلمس مفتاحٌ لا يراه صاحبُه ═══
 *
 * كلُّ بابٍ يُغيّر حالةَ تنبيهٍ يبدأ من `mine()`: يُعاد بناءُ قائمة **هذا
 * المستخدم** ويُبحث فيها عن المفتاح. والبناءُ يطبّق القيدَ نفسَه الذي يطبّقه
 * الجرس — قسمُ الوجهة (`allows`) وفعلُها (`may`) ونشاطُ صاحبِها (`Demo::bid`).
 *
 * فلا يُفتَّش عن النشاط ولا عن الفرع في هذا الملفّ: موضعان يحرسان الشيءَ
 * نفسَه يفترقان يومًا، ويبقى أحدُهما مفتوحًا. والحارسُ واحد، وهو الذي يبني.
 */
class NotificationController extends Controller
{
    public function feed()
    {
        $bid = auth()->user()->business_id ?? Demo::bid();

        $latest = Order::where('business_id', $bid)->sold()
            ->when(Demo::currentBranchId(), fn ($q) => $q->where('branch_id', Demo::currentBranchId()))
            ->orderByDesc('id')->first();

        // بناءٌ واحدٌ للصفوف والعدّاد — انظر `Demo::notificationFeed`
        $feed = Demo::notificationFeed();

        return response()->json([
            'count' => $feed['count'],
            'items' => $feed['items'],
            'snoozed' => $feed['snoozed'],
            'done' => $feed['done'],
            'latest_order' => $latest ? [
                'id' => $latest->id,
                'number' => $latest->number,
                'customer' => \App\Support\Demo::customerLabel($latest->customer_name),
                'total' => (float) $latest->total,
                'url' => route('admin.orders.show', $latest->number),
            ] : null,
        ]);
    }

    /**
     * إخفاءُ خبرٍ قُرئ — ولا يبتلع هذا البابُ إجراءً لم يُنجَز.
     *
     * ═══ العطبُ الذي يُسدّ هنا ═══
     *
     * هذا البابُ أقدمُ من حالات الإنجاز، وكان يقبل **أيَّ مفتاح**. فمن
     * ناداه على «نفد المخزون» أسكته ثلاثين يومًا والرفُّ فارغ — يتجاوز
     * حارسَ «تم» الذي يسأل المصدرَ، ويتجاوز التأجيلَ الذي يعود.
     *
     * وبابٌ يُسكت مشكلةً قائمةً يُفرغ الميزةَ من معناها: لا يُقاس التحقّقُ
     * إن بقي طريقٌ حوله.
     *
     * ═══ ولا يُردّ إلّا ما يملك النظامُ أن يكذّبه ═══
     *
     * والردُّ مقصورٌ على `AUTO` — ما يُسأل مصدرُه فيُجيب. أمّا ما لا دليلَ
     * عليه (`MANUAL`: تذكيرُ موسمٍ، وتذكيرٌ كتبه صاحبُه، وزبونٌ راكد) فـ«تم»
     * فيه تصديقٌ لكلامه لا قياس — وهي و«أخفِه» سواءٌ في الحجّة. فمنعُ
     * إحداهما تضييقٌ بلا فائدةٍ يكسر ما كان يعمل، ويحرم التاجرَ من إخفاء
     * تذكيرٍ وضعه بيده.
     *
     * فيُقرأ الصفُّ من قائمة صاحبه لا من بادئة المفتاح: `custom-` تحتمل
     * الوجهين — قاعدةٌ تُقاس أو تذكيرٌ بموعد — والفرقُ في الصفّ لا في اسمه.
     */
    public function dismiss(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:191']]);

        [$user, $bid, $item] = $this->mine($data['key']);

        if ($item !== null && Notifications::resolveOf($item) === Notifications::AUTO
            && Notifications::kindOfItem($item) === Notifications::TASK) {
            return response()->json([
                'ok' => false,
                'outcome' => 'task',
                'reason' => __('هذا إجراءٌ يُتابَع لا خبرٌ يُخفى — أنجزه أو أجّله.'),
            ], 422);
        }

        DismissedNotification::firstOrCreate([
            'user_id' => auth()->id(),
            'notif_key' => $data['key'],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * إخفاءُ الأخبار المعروضة — وتبقى الإجراءاتُ كما هي.
     *
     * «حذف الكل» كان يكتب صفَّ إخفاءٍ لكلّ ما في القائمة، فيُسكت بضغطةٍ
     * واحدةٍ كلَّ صنفٍ ناقصٍ وكلَّ ورقةٍ تنتظر الاعتماد. وهو البابُ نفسُه
     * فوقه، مضروبًا في عدد الصفوف.
     */
    public function clear()
    {
        $hidden = $kept = 0;

        foreach (Demo::allNotifications() as $n) {
            /*
             * ═══ والجماعيُّ أضيقُ من الفرديّ عمدًا ═══
             *
             * ضغطةٌ واحدةٌ تمسّ كلَّ صفّ، فلا تمسّ إلّا ما لا خلافَ فيه:
             * الأخبار. والفرديُّ فعلٌ مقصودٌ على صفٍّ بعينه، فيُسمح فيه بما
             * لا يملك النظامُ أن يكذّبه — تذكيرٌ كتبه صاحبُه يُخفيه بيده،
             * ولا يُخفيه له زرٌّ يقول «إخفاء الأخبار».
             */
            if (Notifications::kindOfItem($n) === Notifications::TASK) {
                $kept++;

                continue;
            }

            DismissedNotification::firstOrCreate([
                'user_id' => auth()->id(),
                'notif_key' => $n['key'],
            ]);

            $hidden++;
        }

        return response()->json(['ok' => true, 'hidden' => $hidden, 'kept' => $kept]);
    }

    /**
     * فُتح التنبيهُ فقُرئ — ولا يعني ذلك أنّ المهمّة انتهت.
     *
     * والصفُّ يُكتب هنا لا عند العرض: كتابةٌ لكلّ ما يمرّ في الجرس تُنشئ
     * جدولًا بحجم ما رآه الناسُ لا بحجم ما فعلوه. ومن فتح تنبيهًا ليعالجه
     * صار له صفٌّ يحمل نصَّه — فإن عالجه ثمّ عاد يضغط «تم» وجد النظامُ ما
     * يُغلقه ويُسجّل فيه اسمَه.
     */
    public function open(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:191']]);

        [$user, $bid, $item] = $this->mine($data['key']);

        /*
         * والخبرُ لا يُتتبَّع.
         *
         * «ملخّصُ اليوم» يمرّ كلَّ يومٍ ويزول كلَّ ليلة. فلو كُتب صفٌّ لكلّ
         * خبرٍ فُتح لَنما الجدولُ بعدد الأيّام، وامتلأ سجلُّ «المكتملة»
         * بملخّصاتٍ «حُلّت تلقائيًّا» — وهي لم تكن مشكلةً تُحلّ. والخبرُ
         * يبقى على القراءة والإخفاء كما كان.
         */
        if ($item === null || Notifications::kindOfItem($item) !== Notifications::TASK) {
            return response()->json(['ok' => true, 'outcome' => 'gone']);
        }

        $row = $this->row($user, $bid, $item);
        $row->seen_at ??= now();
        $row->save();

        return response()->json(['ok' => true, 'outcome' => 'seen']);
    }

    /**
     * ═══ «تم» تسأل المصدرَ ولا تُصدّق الضغطة ═══
     *
     * تنبيهٌ يُتحقَّق منه (`Notifications::AUTO`) سببُه **هو** وجودُه: ما دام
     * المفتاحُ يُبنى فالصنفُ ناقصٌ على الرفّ والورقةُ معلَّقةٌ والطلبُ ينتظر.
     * فوجدانُه الآن جوابٌ قاطع: لم تُحلّ.
     *
     * ولا تُنسخ هنا قاعدةُ مخزونٍ ولا حالةُ طلب — يُعاد سؤالُ من يملك
     * الجواب. ورسالةُ الرفض هي نصُّ التنبيه نفسُه، فهو أدقُّ ما يمكن أن
     * يُقال: «مخزون منخفض: وردٌ أحمر (٣ متبقٍ)» تقول ما بقي وكم يلزم.
     *
     * وما لا يملك النظامُ دليلًا عليه (`MANUAL`) يُصدَّق صاحبُه ويُسجَّل وقتُه
     * — ولا يُخترع تحقّقٌ غيرُ موثوق.
     *
     * ولا يفعل هذا الزرُّ شيئًا في قسمٍ آخر: لا يزيد كمّيةً، ولا يسدّد
     * فاتورة، ولا يعتمد ورقةً، ولا يغيّر حالةَ طلب.
     */
    public function done(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:191']]);

        [$user, $bid, $item] = $this->mine($data['key']);

        if ($item === null) {
            /* زال سببُه — فإن كان له صفٌّ مفتوحٌ أُغلق باسم من أغلقه */
            $row = $this->openRow($user, $bid, $data['key']);

            if ($row !== null) {
                $row->forceFill(['done_at' => now(), 'resolved_at' => now()])->save();

                return response()->json(['ok' => true, 'outcome' => 'done']);
            }

            return response()->json(['ok' => true, 'outcome' => 'gone']);
        }

        if (Notifications::kindOfItem($item) !== Notifications::TASK) {
            return response()->json([
                'ok' => false,
                'outcome' => 'info',
                'reason' => __('هذا إشعارٌ للعلم لا إجراءٌ يُنجَز — يمكنك إخفاؤه.'),
            ], 422);
        }

        if (Notifications::resolveOf($item) === Notifications::AUTO) {
            return response()->json([
                'ok' => false,
                'outcome' => 'unresolved',
                'reason' => __('ما زالت المشكلة قائمة: :what', ['what' => $item['text']]),
                'url' => $item['url'] ?? null,
            ], 409);
        }

        $row = $this->row($user, $bid, $item);
        $row->forceFill(['done_at' => now(), 'snooze_until' => null])->save();

        return response()->json(['ok' => true, 'outcome' => 'done']);
    }

    /** يؤجّل تنبيهًا قائمًا مدّةً معلومة — ثمّ يعود إن بقي سببُه */
    public function snooze(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'minutes' => ['required', 'integer', 'in:'.implode(',', Notifications::SNOOZE)],
        ]);

        [$user, $bid, $item] = $this->mine($data['key']);

        if ($item === null) {
            return response()->json(['ok' => true, 'outcome' => 'gone']);
        }

        if (Notifications::kindOfItem($item) !== Notifications::TASK) {
            return response()->json([
                'ok' => false,
                'outcome' => 'info',
                'reason' => __('هذا إشعارٌ للعلم لا إجراءٌ يُؤجَّل — يمكنك إخفاؤه.'),
            ], 422);
        }

        $row = $this->row($user, $bid, $item);
        $row->forceFill([
            'snooze_until' => now()->addMinutes((int) $data['minutes']),
            'done_at' => null,
        ])->save();

        return response()->json(['ok' => true, 'outcome' => 'snoozed', 'until' => $row->snooze_until->toIso8601String()]);
    }

    /** يُعيد ما أُنجز أو أُجّل إلى «تحتاج إجراء» — ضغطةٌ في غير محلّها تُراجَع */
    public function reopen(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:191']]);

        $row = $this->openRow(auth()->user(), (int) auth()->user()->business_id, $data['key']);

        if ($row === null) {
            return response()->json(['ok' => true, 'outcome' => 'gone']);
        }

        $row->forceFill(['done_at' => null, 'snooze_until' => null])->save();

        return response()->json(['ok' => true, 'outcome' => 'active']);
    }

    /**
     * سجلُّ ما انتهى — منجَزًا بيد صاحبه أو محلولًا من نفسه.
     *
     * ولا يُحمَّل مع كلّ استطلاع: الجرسُ يُسأل كلَّ ثلاثين ثانية، وهذا
     * يُقرأ حين يُفتح تبويبُه وحدَه.
     */
    public function history()
    {
        $user = auth()->user();

        $rows = NotificationState::where('user_id', $user->id)
            ->where('business_id', $user->business_id)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(Notifications::HISTORY_DAYS))
            ->orderByDesc('resolved_at')
            ->limit(50)->get();

        return response()->json([
            'items' => $rows->map(fn (NotificationState $r) => [
                'key' => $r->notif_key,
                'text' => $r->label,
                'url' => $r->url,
                /* والفرقُ يُقال: أنجزه صاحبُه، أو زال سببُه فأُغلق وحدَه */
                'state' => $r->done_at !== null ? 'done' : 'auto',
                'at' => $r->resolved_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * المستخدمُ ونشاطُه وصفُّ التنبيه في قائمته هو — أو `null` إن لم يعد فيها.
     *
     * والبناءُ هنا كاملٌ (`allNotifications`) لا مقصوصٌ كبناء الجرس: من ضغط
     * «تم» على الصنف الخامسَ عشرَ لا يُقال له «لم يعد قائمًا» لأنّ الجرسَ
     * يعرض عشرة.
     *
     * @return array{0: User, 1: int, 2: array<string, mixed>|null}
     */
    private function mine(string $key): array
    {
        $user = auth()->user();
        $bid = (int) $user->business_id;

        /*
         * ومديرُ المنصّة لا متجرَ له.
         *
         * صفوفُه — محادثاتُ الدعم والاشتراكاتُ المنتهية — أخبارٌ لا إجراءاتٌ
         * تُتابَع، ولا نشاطَ يُنسب إليه الصفّ. فلو مرّ لَكُتبت صفوفٌ
         * بـ`business_id = 0` لا تقرؤها شاشةٌ ولا يمحوها كنس.
         */
        if ($bid === 0) {
            return [$user, 0, null];
        }

        foreach (Demo::allNotifications() as $item) {
            if ($item['key'] === $key) {
                return [$user, $bid, $item];
            }
        }

        return [$user, $bid, null];
    }

    /** الدورةُ المفتوحةُ لهذا المفتاح عند هذا المستخدم — إن كانت */
    private function openRow(User $user, int $bid, string $key): ?NotificationState
    {
        return NotificationState::where('user_id', $user->id)
            ->where('business_id', $bid)
            ->where('notif_key', $key)
            ->whereNull('resolved_at')
            ->first();
    }

    /**
     * الدورةُ المفتوحة، وتُفتح واحدةٌ برقمٍ تالٍ إن لم تكن.
     *
     * والرقمُ التالي لا الأوّل: مشكلةٌ زالت ثمّ عادت دورةٌ ثانيةٌ بالمفتاح
     * نفسِه، والصفُّ الأوّلُ مغلقٌ يبقى في السجلّ.
     *
     * وضغطتان متسارعتان تقرآن «لا صفَّ» كلتاهما فتصطدمان على قيد التفرّد —
     * فيُلتقط الاصطدامُ ويُقرأ ما كتبه السابق. انظر `Contention`.
     */
    private function row(User $user, int $bid, array $item): NotificationState
    {
        $key = $item['key'];

        if ($row = $this->openRow($user, $bid, $key)) {
            return $row;
        }

        $cycle = (int) NotificationState::where('user_id', $user->id)
            ->where('notif_key', $key)->max('cycle') + 1;

        $made = Contention::attempt(fn () => NotificationState::create([
            'business_id' => $bid,
            'user_id' => $user->id,
            'notif_key' => $key,
            'cycle' => $cycle,
            'label' => Str::limit((string) $item['text'], 180),
            'url' => $item['url'] ?? null,
        ]));

        return $made ?? $this->openRow($user, $bid, $key);
    }
}
