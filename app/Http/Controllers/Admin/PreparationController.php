<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Support\Activity;
use App\Support\CustomArrangement;
use App\Support\DeliveryPaper;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use App\Support\OrderTransition;
use App\Support\PrepChecklist;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * لوحة التجهيز — شاشةُ من يصنع الباقة، لا من يحاسب عليها.
 *
 * العامل يقف أمام الطاولة ويسأل سؤالين: ما التالي؟ وما الذي فيه؟ فتُعرض
 * الطلبات مرتّبةً بموعدها، ويُعرض في كلٍّ منها ما يُصنَع به: الأصناف
 * وكمّياتها، والمناسبة، ونصّ البطاقة، وإلى أين تذهب ومتى.
 *
 * ولا يُعرض ثمنٌ ولا تكلفةٌ ولا ربح. ليست شاشة محاسبة، ومن يجهّز الورد لا
 * يحتاج أن يعرف هامش المحلّ ليضع ساقًا في مزهرية — وعرضُه يعني أنّ كلّ من
 * يقف عند الطاولة يقرأ أرباح صاحبه.
 *
 * وصلاحيّتها قسمٌ مستقلّ: «المبيعات» تفتح الفواتير والإجماليّات ومجموع
 * المرشَّح، وهي أوسع بكثير ممّا يحتاجه من يجهّز. فيُمنح التجهيز وحده.
 */
class PreparationController extends Controller
{
    /** أقصى ما يُرسم على اللوحة دفعةً واحدة — وما زاد يُقال عددُه لا يُبتلع */
    private const BOARD_LIMIT = 200;

    /**
     * أعمدةُ اللوحة — تجميعُ حالاتٍ قائمة، لا حالاتٌ جديدة.
     *
     * ═══ ولمَ ليست في `OrderStatus` ═══
     *
     * هذا تجميعُ عرضٍ تخصّ هذه الشاشة: «جديد» و«مؤكّد» عمودٌ واحد هنا لأنّ من
     * يقف عند الطاولة لا يفرّق بينهما — كلاهما لم يُبدأ بعد. وشاشةُ المبيعات
     * تفرّقهما وتحتاج ذلك. فمصدرُ الحالات واحدٌ، وطريقةُ قراءتها تخصّ قارئها.
     *
     * ═══ والتغطيةُ محروسة ═══
     *
     * كلُّ حالةٍ حيّة (ما ليس في `OrderStatus::CLOSED`) لها عمودٌ واحدٌ لا
     * أكثر. وإلّا اختفت بطاقتُها من العرض العموديّ بلا كلمة — «تعذّر التوصيل»
     * بالذات: طلبٌ رجع من الطريق هو أحوجُ ما على اللوحة إلى أن يُرى، وهو أوّل
     * ما يسقط من قائمةٍ تُكتب بالحدس. انظر `ThePrepBoardStandsInColumnsTest`.
     *
     * وما لا عمودَ له — حالٌ قديمةٌ في القاعدة لا يعرفها هذا الملفّ — يُجمع في
     * «أخرى» ولا يُبتلع: `canMove` نفسُها تحتمل مثلَ ذلك ولا تحبسه.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'waiting' => [OrderStatus::PENDING, OrderStatus::CONFIRMED],
        'preparing' => [OrderStatus::PREPARING],
        'ready' => [OrderStatus::READY],
        'out' => [OrderStatus::OUT_FOR_DELIVERY],
        'failed' => [OrderStatus::DELIVERY_FAILED],
    ];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request)
    {
        $filter = $request->query('when');

        /*
         * مرشّح التنفيذ — بُعدٌ ثانٍ لا صفٌّ ثانٍ من التبويبات.
         *
         * ضربُه في النوافذ الأربع يعني ثمانية تبويباتٍ على شاشةٍ واحدة، ولا
         * أحد يقرأ ثمانية. فيبقى الزمن تبويبات ويصير التنفيذ مبدّلًا بجانبها،
         * والاثنان يعملان معًا: «توصيل اليوم» اختيارٌ واحد من كلٍّ منهما.
         *
         * والمجهول يعني «الكلّ»: عنوانٌ يُكتب بيدٍ لا يُفرغ اللوحة بلا سبب.
         */
        $type = in_array($request->query('type'), FlowerOrder::FULFILLMENT, true)
            ? $request->query('type')
            : null;

        /*
         * الاستعلام لا يحمل تاريخًا لا يلزم.
         *
         * `awaitingPreparation` يستبعد المغلق والمعلَّق وما لا موعد له، فلا
         * تُحمَّل ستّمئة فاتورةٍ أُغلقت منذ شهور. والفهرس المركّب
         * [business_id, scheduled_for] يخدم الترشيح والترتيب معًا.
         *
         * و`with('items')` لا حلقةٌ على الطلبات: بدونها استعلامٌ لكل طلب —
         * عشرون طلبًا على اللوحة تعني واحدًا وعشرين استعلامًا.
         */
        $q = $this->base()
            ->when($type, fn ($w) => $w->where('fulfillment_type', $type))
            ->with([
                // `variant_name` في الانتقاء وإلّا عاد الاسم بلا مقاسه:
                // عمودٌ لم يُنتقَ يُقرأ فارغًا لا مفقودًا، فيصمت العطب
                'items:id,order_id,name,variant_name,quantity,note,product_id,custom_details',
                // موادُّ الطلب المخصَّص — تُحمَّل مع البنود لا باستعلامٍ لكلّ بطاقة
                'items.components',
                // الصورة وحدها من المنتج — لا سعرَه ولا تكلفتَه
                'items.product:id,image',
                'items.addons',
            ]);

        $this->applyWindow($q, $filter);

        /*
         * السقف يبقى، والصمتُ عنه لا يبقى.
         *
         * مئتا بطاقةٍ على شاشةٍ واحدة حدٌّ معقول — لكنّ اللوحة كانت تقطع عندها
         * بلا كلمة: العدّاد فوقها يقول «٢٥٠» والقائمة تحته تنتهي عند المئتين،
         * فيظنّ من يجهّز أنّه أنهى ما عليه وخمسون طلبًا لم تُعرض له أصلًا.
         *
         * والترتيب بالموعد يُخفي الأبعد لا الأقرب — وهو أرحم ممّا لو كان
         * عشوائيًّا، لكنّ الطلب المؤجَّل يصير متأخّرًا بعد يومين، فيظهر حينها
         * وقد فات موعدُه. وباقةٌ لا تصل صاحبها في يومها ليست سطرًا ناقصًا في
         * شاشة.
         *
         * فيُقال العدد صراحةً ويُدلّ على المرشّح الذي يُظهر الباقي — والعدّ
         * على الاستعلام نفسه بنافذته ومرشّحه، لا على «الكلّ» فوقه.
         */
        $total = (clone $q)->count();
        $orders = $q->orderBy('scheduled_for')->limit(self::BOARD_LIMIT)->get();

        /*
         * والعلاماتُ تُقرأ دفعةً واحدة.
         *
         * اللوحة تُعرض مئتين، وتستطلع نفسَها كلَّ عشرين ثانية. فقراءةُ علامات
         * كلّ بطاقةٍ على حدة تعني مئتي استعلامٍ ثلاثَ مرّاتٍ في الدقيقة — على
         * شاشةٍ مفتوحةٍ طولَ اليوم أمام الطاولة.
         */
        $checks = PrepChecklist::forOrders($orders->pluck('id')->all());

        return Inertia::render('Admin/Preparation/Index', [
            'orders' => $orders->map(fn ($o) => $this->card($o, $checks[$o->id] ?? []))->values()->all(),
            'filters' => ['when' => $filter, 'type' => $type],
            'counts' => $this->counts($type),
            'typeCounts' => $this->typeCounts($filter),
            'columnCounts' => $this->columnCounts($filter, $type),
            /*
             * وخريطةُ الأعمدة تصل من الخادم لا تُكتب في الشاشة.
             *
             * لو كُتبت هناك لَافترقت عن `COLUMNS` عند أوّل حالةٍ تُضاف: يُضاف
             * العمودُ في الخادم فتُعدّ بطاقاتُه ولا تُرسم، أو يُضاف في الشاشة
             * فيُرسم عمودٌ عدّادُه صفرٌ أبدًا.
             */
            'columns' => self::COLUMNS,
            'truncated' => $total > self::BOARD_LIMIT
                ? ['shown' => $orders->count(), 'total' => $total]
                : null,
            /*
             * وختمُ القراءة — به تقيس الشاشةُ عمرَ ما تعرض.
             *
             * رقمٌ يتحرّك بلا أن يُعرف عمرُه يُقرأ لحظيًّا: فيقف من يجهّز أمام
             * لوحةٍ توقّف استطلاعُها منذ عشر دقائق وهو لا يعلم.
             */
            'fetchedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * أعدادُ الأعمدة — تحت المرشّحين القائمين، في استعلامٍ واحد.
     *
     * ═══ ولمَ تُعدّ في الخادم لا تُحصى من البطاقات ═══
     *
     * البطاقاتُ مقصوصةٌ عند `BOARD_LIMIT`. فإحصاؤها في الشاشة يجعل رأسَ العمود
     * يقول «١٢» وتحته مئةٌ لم تُحمَّل — وهو الكذبُ الذي وُضع شريطُ الاقتطاع
     * أصلًا ليمنعه.
     *
     * فالرأسُ يحمل الرقمين حين تُقصّ اللوحة: المعروض من الكلّ.
     *
     * @return array<string, int>
     */
    private function columnCounts(?string $when, ?string $type): array
    {
        $q = $this->base()->when($type, fn ($w) => $w->where('fulfillment_type', $type));
        $this->applyWindow($q, $when);

        $select = ['count(*) as all_count'];
        $bind = [];

        foreach (self::COLUMNS as $key => $statuses) {
            $in = implode(', ', array_fill(0, count($statuses), '?'));
            $select[] = "sum(case when status in ({$in}) then 1 else 0 end) as {$key}_count";
            $bind = array_merge($bind, $statuses);
        }

        $row = $q->selectRaw(implode(', ', $select), $bind)->first();

        $out = [];
        $known = 0;
        foreach (array_keys(self::COLUMNS) as $key) {
            $out[$key] = (int) ($row->{$key.'_count'} ?? 0);
            $known += $out[$key];
        }

        // وما لا عمودَ له يُقال عددُه — حالٌ قديمةٌ في القاعدة لا تُبتلع
        $out['other'] = max(0, (int) ($row->all_count ?? 0) - $known);

        return $out;
    }

    /** ما تنتظره اللوحة: متجرُ المستخدم، وفرعُه، وما لم يُغلق بعد */
    private function base()
    {
        return Order::where('business_id', $this->bid())
            ->awaitingPreparation()
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()));
    }

    /** نافذة الوقت المطلوبة — والمجهول يعني «الكلّ» لا يعني خطأً */
    private function applyWindow($q, ?string $when): void
    {
        match ($when) {
            'overdue' => $q->where('scheduled_for', '<', now()),
            'today' => $q->whereBetween('scheduled_for', [now()->startOfDay(), now()->endOfDay()]),
            'tomorrow' => $q->whereBetween('scheduled_for', [
                now()->addDay()->startOfDay(), now()->addDay()->endOfDay(),
            ]),
            // «قادم» ما بعد الغد: النوافذ الأربع تقسم اللوحة ولا تتداخل،
            // فطلبُ الغد يُعدّ مرّةً في تبويبه لا مرّتين
            'upcoming' => $q->where('scheduled_for', '>', now()->addDay()->endOfDay()),
            default => null,
        };
    }

    /**
     * أعداد التبويبات — استعلامٌ واحد لا أربعة.
     *
     * أربع عدّاتٍ منفصلة تمسح الفهرس نفسه أربع مرّات لتُجيب عن سؤالٍ واحد.
     * والجمع الشرطيّ يفعلها في مسحةٍ واحدة، ويعمل على PostgreSQL وSQLite معًا
     * (`case when` قياسيّ، خلافًا لـ`FILTER` التي لا يعرفها الثاني).
     *
     * وتُعدّ تحت مرشّح التنفيذ المختار: رقمٌ على تبويبٍ يجب أن يكون عدد ما
     * يظهر عند الضغط عليه، لا عدد ما كان يظهر قبل مرشّحٍ آخر.
     *
     * @return array<string, int>
     */
    private function counts(?string $type): array
    {
        $when = fn (string $expr) => "sum(case when {$expr} then 1 else 0 end)";

        $row = $this->base()
            ->when($type, fn ($w) => $w->where('fulfillment_type', $type))
            ->selectRaw(
                'count(*) as all_count, '
                .$when('scheduled_for < ?').' as overdue_count, '
                .$when('scheduled_for between ? and ?').' as today_count, '
                .$when('scheduled_for between ? and ?').' as tomorrow_count',
                [
                    now(),
                    now()->startOfDay(), now()->endOfDay(),
                    now()->addDay()->startOfDay(), now()->addDay()->endOfDay(),
                ]
            )->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
            'today' => (int) ($row->today_count ?? 0),
            'tomorrow' => (int) ($row->tomorrow_count ?? 0),
        ];
    }

    /**
     * أعداد مبدّل التنفيذ — تحت النافذة الزمنية المختارة، للسبب نفسه.
     *
     * و«الكلّ» ليس مجموع الاثنين: طلبٌ له موعدٌ ولم يُحدَّد تنفيذه ليس
     * توصيلًا ولا استلامًا، فيسقط من كليهما ويبقى في «الكلّ» وحده — وهو
     * الموضع الذي يُرى فيه أنّ في اللوحة ما ينقصه شيء.
     *
     * @return array<string, int>
     */
    private function typeCounts(?string $when): array
    {
        $q = $this->base();
        $this->applyWindow($q, $when);

        // قيمتان ثابتتان، ومع ذلك تُربَط لا تُدمَج: راويةُ SQL لا تُفتح لعادة
        $case = 'sum(case when fulfillment_type = ? then 1 else 0 end)';

        $row = $q->selectRaw(
            'count(*) as all_count, '
            .$case.' as delivery_count, '
            .$case.' as pickup_count',
            [FlowerOrder::DELIVERY, FlowerOrder::PICKUP]
        )->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'delivery' => (int) ($row->delivery_count ?? 0),
            'pickup' => (int) ($row->pickup_count ?? 0),
        ];
    }

    /**
     * بطاقة الطلب على اللوحة — ما يُصنَع به لا ما يُحاسَب عليه.
     *
     * لا `price` ولا `cost` ولا `total`: العمود موجودٌ في البند، وإرسالُه
     * إلى الشاشة يجعله مقروءًا لكلّ من يفتح أدوات المتصفّح — سواءٌ رُسم أم
     * لم يُرسم.
     */
    private function card(Order $o, array $checks = []): array
    {
        return [
            'number' => $o->number,
            'status' => $o->status,
            // اسم العميل — صاحبُ الطلب لا مستلِمُه. وكانا يُخلطان: بطاقةٌ
            // تعرض المستلِم وحدها لا تقول لمن تُسلَّم عند الاستلام من المحل
            'customer' => $o->customer_name,
            'fulfillment' => $o->fulfillment_type,
            'scheduled_for' => optional($o->scheduled_for)->format('Y-m-d H:i'),
            /*
             * والموعدُ مفكوكًا — تاريخٌ ووقتٌ ويومٌ ودقائقُ باقية.
             *
             * ═══ ولمَ لا يُرسَل نصًّا واحدًا ويُفكّ في المتصفّح ═══
             *
             * `new Date('2026-09-23 14:00')` ليست تاريخًا قياسيًّا: المحرّكات
             * تقرؤها بالتوقيت المحليّ للجهاز — وجهازُ الطاولة قد يكون على
             * توقيتٍ آخر، أو على توقيتٍ لم يُضبط أصلًا (لوحاتٌ رخيصةٌ تُشترى
             * وتُشغَّل ولا أحد يفتح إعداداتِها). فيقرأ من يجهّز «بعد ساعتين»
             * لطلبٍ فات موعدُه.
             *
             * فالحسابُ كلُّه هنا: الخادمُ على `Asia/Muscat` — توقيتِ التاجر —
             * والمتصفّحُ يعرض ما يصله ولا يفسّر تاريخًا أبدًا. والدقائقُ عددٌ
             * صحيحٌ موقَّع: سالبُه تأخيرٌ وموجبُه بقيّة.
             *
             * واليومُ مفتاحٌ لا كلمة: `today` تُترجَم في الشاشة كسائر نصوصها،
             * ولو أُرسلت «اليوم» عربيّةً لَبقيت عربيّةً في واجهةٍ إنجليزية.
             */
            'scheduled' => $o->scheduled_for ? [
                'date' => $o->scheduled_for->format('Y-m-d'),
                'time' => $o->scheduled_for->format('H:i'),
                'day' => match (true) {
                    $o->scheduled_for->isToday() => 'today',
                    $o->scheduled_for->isTomorrow() => 'tomorrow',
                    $o->scheduled_for->isYesterday() => 'yesterday',
                    default => null,
                },
                'minutes_left' => (int) round(now()->diffInMinutes($o->scheduled_for)),
            ] : null,
            'overdue' => $o->scheduled_for && $o->scheduled_for->isPast(),
            'recipient' => $o->recipient_name,
            'recipient_phone' => $o->recipient_phone,
            'address' => $o->delivery_address,
            'occasion' => FlowerOrder::occasionLabel($o->occasion_type),
            'card_message' => $o->card_message,
            /*
             * وترتيبُ النصّ يُرسَل معه — فمن يكتب الكرت يكتبه كما طُلب.
             *
             * وبلا هذا يصل النصُّ بلا ترتيبه، فيُكتب كما اعتاد الكاتبُ لا
             * كما رتّبه من دفع ثمنه — وهو أوّلُ ما يُرى على الكرت.
             */
            'card_align' => $o->card_align,
            'card_file' => filled($o->card_file) ? route('admin.orders.giftcard', $o->id) : null,
            'card_file_name' => $o->card_file_name,
            // اسم المُهدي يبقى للموظّف: هو يكتب البطاقة، والإخفاء عن المستلِم
            // لا عن من يصنعها — انظر FlowerOrder::cardForRecipient
            'sender' => $o->sender_name,
            'hide_sender' => (bool) $o->hide_sender,
            'delivery_notes' => $o->delivery_notes,
            'internal_notes' => $o->internal_notes,
            'branch' => $o->branch,
            // المقاس والإضافات على بطاقة التجهيز: من يجهّز «بوكيه» لا يعرف
            // أيّ مقاسٍ يجهّز، ولا أنّ معه دبًّا — فيخرج الطلب ناقصًا
            'items' => $o->items->map(fn ($i) => [
                /*
                 * ومعرّفُ البند يُرسَل — منه يُبنى مفتاحُ علامة التحقّق.
                 *
                 * وليس فيه ما يُخفى: رقمُ صفٍّ في جدولٍ محصورٍ بمتجره وفرعه،
                 * ولا يُقبل من المتصفّح إلّا إن كان من هذا الطلب نفسِه
                 * (`PrepChecklist::allows`). والبديلُ — مفتاحٌ يُبنى من اسم
                 * البند — يجمع بندين اسمُهما واحد في علامةٍ واحدة: «وردة حمراء»
                 * مرّتين في طلبٍ واحد تُؤشَّر إحداهما فتُؤشَّر الأخرى معها.
                 */
                'id' => $i->id,
                'name' => $i->displayName(),
                'qty' => (int) $i->quantity,
                'note' => $i->note,
                'image' => $i->product?->image,
                'addons' => $i->addons->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'qty' => (int) $a->quantity,
                ])->all(),
                /*
                 * وموادُّ الطلب المخصَّص — وهي كلُّ ما يعرفه المنسّق عنه.
                 *
                 * الباقةُ الجاهزة اسمُها يقول ما فيها، والمخصَّصةُ اسمُها
                 * «تنسيق ورد مخصص» ولا شيءَ تحته. فبلا هذه يقف من يجهّز أمام
                 * بطاقةٍ تقول «تنسيق مخصص ×١» ولا تقول ممّ يُصنع.
                 *
                 * وبلا تكلفةٍ ولا سعر: اللوحةُ شاشةُ من يصنع لا من يحاسب.
                 */
                'components' => $i->components->map(fn ($c) => [
                    'name' => $c->name,
                    // كسرٌ يُعرض كما هو: «١.٥ لفّة» لا «٢»
                    'qty' => (float) $c->quantity,
                ])->all(),
                /*
                 * وخياراتُ الطلب كما كُتبت يوم البيع — لا كما يقول القالبُ اليوم.
                 *
                 * ═══ ولمَ لا تُقرأ الحقولُ بأسمائها ═══
                 *
                 * كانت ثلاثةَ مفاتيح تُقرأ بأسمائها: `colors` و`packaging_label`
                 * و`florist_notes`. فبطاقةُ التجهيز كانت تعرف أنّ في الدنيا
                 * ألوانًا وتغليفًا ومنسّقًا — وهي شاشةُ نظامٍ لا يعرف ما يبيع
                 * التاجر. ومن أضاف حقلًا رابعًا لم يظهر على البطاقة أصلًا.
                 *
                 * فصارت قائمةً: تسميةٌ وقيمة، أيًّا كانت. والقارئُ واحدٌ يفهم
                 * الشكلين — ما بيع قبل القوالب وما بعدها.
                 *
                 * ولا سعرَ ولا تكلفة: اللوحةُ شاشةُ من يصنع لا من يحاسب.
                 */
                'custom' => $i->isCustom() ? (function () use ($i) {
                    $v = CustomArrangement::view($i->custom_details);

                    return ['template' => $v['template'], 'fields' => $v['fields']];
                })() : null,
            ])->values()->all(),
            // ما يجوز الانتقال إليه من هنا — تُبنى منه أزرار البطاقة
            'next' => OrderStatus::nextFrom($o->status),
            /*
             * وعلاماتُ التجهيز — ما أُشّر منها ومن أشّره ومتى.
             *
             * تصل مع البطاقة لا باستدعاءٍ ثانٍ: فتتجدّد مع الاستطلاع نفسِه،
             * فيرى من على الطاولة الثانية ما جمعه زميلُه قبل عشرين ثانية.
             * ولو جُلبت وحدها لَبقيت مربّعاتُ شاشةٍ على حالها بينما الطلب
             * يُجهَّز كلُّه على شاشةٍ أخرى.
             */
            'checks' => $checks,
        ];
    }

    /**
     * «ابدأ التجهيز» و«جاهز» — والحارس هو نفسه حارس شاشة المبيعات.
     *
     * مصدرٌ واحد للانتقالات المسموحة (`OrderStatus`) لا حارسان: لو كُتب هنا
     * حارسٌ ثانٍ لَافترق عن أخيه عند أول تعديل، فأجاز أحدهما ما يمنعه الآخر
     * — والعامل يستطيع من لوحته ما لا يستطيعه صاحبُ المحلّ من شاشته.
     */
    /**
     * سندُ التسليم من اللوحة — الورقةُ التي تمشي مع الشحنة.
     *
     * ═══ العطب ═══
     *
     * اللوحةُ تنقل الطلبَ إلى «خرج للتوصيل» ولا تطبع شيئًا، ولا رابطَ واحدٌ
     * يخرج من بطاقتها. والورقةُ موجودةٌ وجاهزة — لكنّ بابَها الوحيد كان في
     * صفحة الطلب داخل «المبيعات».
     *
     * ومسارُ تلك الصفحة يُشتقّ منه قسمُ `orders`، فمن يجهّز يُردّ عنه بـ٤٠٣.
     * قِسناه: يفتح لوحتَه ٢٠٠، ويطبع السندَ ٤٠٣. فيطبعها صاحبُ المحلّ بيده
     * لكلّ طلب، أو يمنح المجهِّزَ «المبيعات» — فيفتح له الفواتيرَ
     * والإجماليّاتِ وأرباحَ المحلّ، وهي عينُ ما فُصل قسمُ التجهيز ليمنعه.
     *
     * ═══ ولمَ يتبع `preparation` لا `orders` ═══
     *
     * القسمُ يُشتقّ من اسم المسار، واسمُه هنا `admin.preparation.*` — فالبابُ
     * بابُ من يجهّز. وليس في الورقة ما يُخفى عنه: قالبُ `delivery` يُطفئ
     * الأسعار افتراضًا، وما فيها هو ما يراه على بطاقته أصلًا — الأصنافُ
     * والمستلِمُ وعنوانُه وموعدُه. وهو الذي يضعها في الصندوق.
     *
     * ═══ والحصرُ حصرُ اللوحة نفسِها ═══
     *
     * `base()` لا `Order::where(...)`: متجرُه، وفرعُه المختار، وما لم يُغلق
     * بعدُ. فلا يطبع سندَ طلبٍ ليس على لوحته — ولا سندَ طلبٍ سُلّم وأُغلق.
     * و«خرج للتوصيل» ليست من `CLOSED`، فالورقةُ تبقى في متناوله بعد الضغطة.
     */
    public function deliveryNote(string $number)
    {
        $order = $this->base()->where('number', $number)->with('items')->firstOrFail();

        return DeliveryPaper::pdf($this->bid(), $order);
    }

    /**
     * وضعُ علامةِ تجهيزٍ أو رفعُها — ولا شيء سواها.
     *
     * ═══ ما لا تفعله هذه الدالّة ═══
     *
     * لا تخصم من رفّ، ولا تكتب قيدًا، ولا تنقل حالَ الطلب. المربّعُ يقول
     * «جمعتُه» ولا يقول «جاهز» — ومن يؤشّر الأخير يضغط الزرّ بنفسه. وخلطُ
     * الاثنين يعني طلبًا يقفز إلى «جاهز» لأنّ موظّفًا أشّر آخرَ بندٍ وهو لم
     * يغلّفه بعد.
     *
     * ═══ وحارسان لا واحد ═══
     *
     * الطلبُ من `base()`: متجرُه وفرعُه وما لم يُغلق. والمفتاحُ من الطلب نفسِه
     * (`PrepChecklist::allows`) — فلا يُؤشَّر بندُ طلبٍ آخر برقمٍ يُبدَّل في
     * الطلب، ولا مفتاحٌ مخترَعٌ يُكتب في الجدول فيتراكم ما لا يُعرض.
     */
    public function check(Request $request, string $number)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'checked' => ['required', 'boolean'],
        ]);

        $order = $this->base()->where('number', $number)->with('items.addons')->firstOrFail();

        if (! PrepChecklist::allows($order, $data['key'])) {
            return back()->with('toast', [
                'msg' => __('هذا البند ليس من هذا الطلب.'),
                'type' => 'danger',
            ])->withErrors(['key' => __('هذا البند ليس من هذا الطلب.')]);
        }

        PrepChecklist::set($order, $data['key'], (bool) $data['checked']);

        /*
         * ولا `toast` للنجاح ولا سطرٌ في سجلّ النشاط.
         *
         * المربّعُ يُؤشَّر عشرين مرّةً في الطلب الواحد. فتنبيهٌ لكلّ ضغطة يُغرق
         * الشاشة، وسطرٌ لكلّ ضغطة يدفن في السجلّ ما يُراقَب حقًّا — حذفُ فاتورةٍ
         * وتغييرُ حال. ومن وضع العلامة ومتى محفوظان في الصفّ نفسِه ويُعرضان
         * بجانبها، وهو الموضع الذي يُسأل فيه عنهما.
         */
        return back();
    }

    /**
     * خطُّ الطلب الزمنيّ — من سجلّ النشاط القائم لا من سجلٍّ ثانٍ.
     *
     * ═══ ولمَ لا جدولَ جديد ═══
     *
     * الشاشتان اللتان تنقلان الحال — `PreparationController::move` وصفحةُ
     * الطلب — تكتبان في `activity_logs` بالفعل: الفعلُ `status`، والموضوعُ
     * `order` بمعرّفه، ومن فعلها ومتى. فجدولٌ ثانٍ يعني حقيقتين لحدثٍ واحد،
     * إحداهما تُكتب والأخرى تُنسى.
     *
     * ═══ ولا يُخترع ما لم يُسجَّل ═══
     *
     * طلباتٌ نُقلت قبل أن يُكتب هذا السجلّ لا خطَّ لها — فيُقال ذلك ولا يُملأ
     * الفراغ بتخمينٍ من `updated_at`. وختمٌ واحدٌ على الصفّ لا يقول متى بُدئ
     * التجهيز ولا من بدأه.
     *
     * ═══ وطلبُ الجار لا يُقرأ ═══
     *
     * الطلبُ من `base()` أوّلًا، ثمّ السجلّ بمتجره ومعرّفه. ولا يكفي أحدهما:
     * رقمُ طلبٍ من متجرٍ آخر يُردّ بـ٤٠٤ قبل أن يُقرأ سطرٌ واحد.
     */
    public function timeline(string $number)
    {
        $order = $this->base()->where('number', $number)->firstOrFail();

        $rows = ActivityLog::where('business_id', $this->bid())
            ->where('subject_type', 'order')
            ->where('subject_id', $order->id)
            ->where('action', 'status')
            ->orderBy('created_at')
            ->limit(50)
            ->get(['description', 'user_name', 'created_at']);

        return response()->json([
            'events' => $rows->map(fn ($r) => [
                'text' => $r->description,
                'by' => $r->user_name,
                'at' => optional($r->created_at)->format('Y-m-d H:i'),
            ])->values()->all(),
        ]);
    }

    public function move(Request $request, string $number)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(OrderStatus::ALL)],
        ]);

        $order = Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()))
            ->where('number', $number)
            ->firstOrFail();

        $from = $order->status;

        if ($error = OrderTransition::apply($order, $data['status'])) {
            /*
             * والرفض يُرى — واللوحة لا تعرض إلّا `flash.toast`.
             *
             * `withErrors` وحدها كانت تجعل الضغطة لا تفعل شيئًا ولا تقول
             * شيئًا: طلبٌ ألغاه صاحب المحلّ قبل لحظة يبقى على شاشة العامل،
             * فيضغط «جاهز» فلا يتحرّك ولا يُخبَر لماذا.
             */
            return back()
                ->with('toast', ['msg' => $error, 'type' => 'danger'])
                ->withErrors(['status' => $error]);
        }

        Activity::log('status', 'التجهيز: نقل الطلب '.$order->number.' من «'.$from.'» إلى «'.$data['status'].'»', [
            'subject_id' => $order->id,
            'subject_type' => 'order',
        ]);

        return back()->with('toast', [
            'msg' => __('حالة الطلب: :status', ['status' => $data['status']]),
            'type' => 'success',
        ]);
    }
}
