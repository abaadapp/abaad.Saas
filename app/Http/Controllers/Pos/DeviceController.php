<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\PosTerminal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * تفعيل جهاز نقطة البيع وإدارته.
 *
 * التفعيل فعلٌ إداريّ يقع مرّةً واحدة يوم التركيب: يقف المدير على الجهاز،
 * يختار فرعه، ويسمّيه. بعدها لا يرى الكاشير شيئًا من هذا — يفتح الشاشة فيجد
 * لوحة الأرقام.
 *
 * ولا يبدّل الكاشير فرع الجهاز: الفرع يقرّر أيّ مخزونٍ يُخصم وأيّ درجٍ يُعدّ،
 * وجعلُه زرًّا على الصندوق يعني أن خطأً في نقرةٍ ينقل مبيعات فرعٍ إلى آخر.
 */
class DeviceController extends Controller
{
    /**
     * شاشة إعداد نقطة البيع — تظهر حين لا يكون المتصفّح مفعَّلًا.
     *
     * الفروع من متجر المستخدم وحده: قائمةٌ تُبنى من `Demo::bid()` لا مما يصل
     * من الواجهة.
     */
    public function setup()
    {
        if (PosTerminal::activated()) {
            return redirect()->route('pos.index');
        }

        /*
         * من لا يملك التفعيل لا يُصفع بـ403: كتب بريدَه وكلمتَه صحيحَين
         * ووصل. يُقال له ما ينقص ومن يفعله — والتفعيلُ نفسُه يبقى محروسًا
         * في `activate`.
         */
        if (! auth()->user()->allows('settings')) {
            return Inertia::render('Pos/DeviceNotActivated', [
                'businessName' => Demo::businessName(),
            ]);
        }

        return Inertia::render('Pos/DeviceSetup', [
            'branches' => Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            'businessName' => Demo::businessName(),
        ]);
    }

    public function activate(Request $request)
    {
        $this->authorizeActivation();

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:60'],
        ], [], [
            'branch_id' => __('الفرع'),
            'name' => __('اسم الجهاز'),
        ]);

        // الفرع يُقيَّد بمتجر المستخدم: معرّفٌ من الواجهة لا يُوثق به
        $branch = Branch::where('business_id', Demo::bid())->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('هذا الفرع غير متاح.')]);
        }

        $device = PosTerminal::activate($branch, $data['name'], auth()->id());

        Activity::log('created', 'فعّل جهاز نقطة بيع: '.$device->name.' — فرع '.$branch->name, [
            'subject_id' => $device->id,
        ]);

        return redirect()->route('pos.index')->with('toast', [
            'msg' => __('تم تفعيل الجهاز على فرع :branch', ['branch' => $branch->name]),
            'type' => 'success',
        ]);
    }

    /* ------------------------- الإدارة من الإعدادات ------------------------- */

    public function index()
    {
        return Inertia::render('Admin/Devices/Index', self::panelData());
    }

    /**
     * بيانات قسم الأجهزة.
     *
     * تُقرأ من موضعين: صفحتها المستقلّة، ولوحة الإعدادات حيث يُفتح القسم
     * مكانها. وهي هنا مرّةً واحدة فلا تفترق النسختان مع أوّل تعديل.
     *
     * @return array<string, mixed>
     */
    public static function panelData(): array
    {
        $current = PosTerminal::current();

        return [
            'devices' => PosDevice::where('business_id', Demo::bid())
                ->with('branch:id,name', 'activatedBy:id,name', 'peripherals', 'bankAccount')
                ->withCount('orders', 'shifts')
                ->orderByDesc('id')->get()->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'branch' => $d->branch?->name ?? '—',
                    'branchId' => $d->branch_id,
                    /*
                     * البنكُ الذي يودع فيه جهازُ الشبكة الموصول بهذا الصندوق.
                     *
                     * يُعرض فارغًا بوصفه «الرئيسيّ» لا بوصفه «لا شيء»: الفارغ
                     * وجهةٌ فعليّة (انظر `Bank::depositFor`)، وعرضُه شاغرًا
                     * يوهم التاجر أنّ بيعَه بالبطاقة لا يصل حسابًا.
                     */
                    'bankAccountId' => $d->bank_account_id,
                    'bankAccount' => $d->bankAccount?->displayName(),
                    'status' => $d->status,
                    'lastSeen' => $d->last_seen_at?->diffForHumans() ?? '—',
                    'activatedAt' => $d->activated_at?->format('Y-m-d') ?? '—',
                    'activatedBy' => $d->activatedBy?->name ?? '—',
                    // الجهاز الذي تقف عليه الآن — لئلا يُلغي المدير جهازه بيده
                    'isThis' => $current?->id === $d->id,
                    /*
                     * أيُحذف صفُّه؟ — يُقاس هنا لا في الشاشة.
                     *
                     * الشاشةُ ترسم بهذا، والخادمُ يردّ بالشرط نفسِه في
                     * `destroy`. ومقياسان لسؤالٍ واحد يفترقان يوم يُبدَّل
                     * أحدهما، فيُعرض زرٌّ يردّه الخادم — وهو أسوأ من غيابه.
                     */
                    'erasable' => ! $d->isActive() && $d->orders_count === 0 && $d->shifts_count === 0,
                    'orders' => (int) $d->orders_count,
                    'shifts' => (int) $d->shifts_count,
                    'peripherals' => $d->peripherals->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'type' => $p->type,
                        'connection' => $p->connection,
                        'model' => $p->model,
                        'address' => $p->address,
                        'port' => $p->port,
                        'paperWidth' => $p->paper_width,
                        'autoPrint' => $p->auto_print,
                        'notes' => $p->notes,
                        'active' => $p->active,
                        // تقودها نقطة البيع فعلًا، أم تُسجَّل للجرد وحده
                        'drivable' => $p->isDrivable(),
                    ])->values()->all(),
                ])->values()->all(),
            'branches' => Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            /*
             * حسابات المتجر البنكيّة — ومنها يختار المديرُ بنكَ كلّ جهاز.
             *
             * والموقوفةُ تُعرض: جهازٌ أُسنِد إلى حسابٍ ثمّ أُوقف الحساب يبقى
             * مسندًا إليه، وحجبُ الصفّ من القائمة يجعل الشاشة تعرض فراغًا
             * وتقول «الرئيسيّ» — وهي تكذب.
             */
            'bankAccounts' => BankAccount::where('business_id', Demo::bid())
                ->orderByDesc('is_primary')->orderBy('id')->get()
                ->map(fn ($a) => ['value' => $a->id, 'label' => $a->displayName()])->values()->all(),
            'peripheralTypes' => \App\Models\PosPeripheral::TYPES,
            'drivableTypes' => \App\Models\PosPeripheral::DRIVABLE,
            'paperWidths' => \App\Models\PosPeripheral::PAPER_WIDTHS,
        ];
    }

    public function update(Request $request, int $id)
    {
        $device = $this->find($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'branch_id' => ['required', 'integer'],
            'bank_account_id' => ['nullable', 'integer'],
        ]);

        $branch = Branch::where('business_id', Demo::bid())->find($data['branch_id']);
        if (! $branch) {
            return back()->withErrors(['branch_id' => __('هذا الفرع غير متاح.')]);
        }

        /*
         * وحسابُ متجرٍ آخر يُردّ — لا يُقبل صامتًا.
         *
         * `Bank::leaf` تُسقط غيرَ المملوك إلى الورقة النظاميّة، فلا يُرحَّل
         * إلى دفتر جارٍ أبدًا. لكنّ القبولَ الصامت يكتب في الصفّ رقمًا تعرضه
         * الشاشةُ باسمٍ فارغ، ويبقى الجهاز مسندًا إلى ما لا يملكه.
         */
        $bankAccountId = null;

        if (filled($data['bank_account_id'] ?? null)) {
            $owned = BankAccount::where('business_id', Demo::bid())
                ->whereKey($data['bank_account_id'])->exists();

            if (! $owned) {
                return back()->withErrors(['bank_account_id' => __('هذا الحساب البنكي ليس من حسابات متجرك.')]);
            }

            $bankAccountId = (int) $data['bank_account_id'];
        }

        $moved = $device->branch_id !== $branch->id;
        $device->update([
            'name' => $data['name'],
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccountId,
        ]);

        /*
         * نقل الجهاز إلى فرعٍ آخر يُبطل تفعيله.
         *
         * الجهاز يُنقل حين يُنقل فعلًا — إلى محلٍّ آخر، أو إلى يد موظفٍ آخر.
         * وإبقاء رمزه صالحًا يعني أن من كان يملكه يواصل البيع على الفرع
         * الجديد من مكانه. فيُدوَّر الرمز، ويُعاد التفعيل على الجهاز نفسه.
         */
        if ($moved) {
            PosTerminal::revoke($device);
            Activity::log('updated', 'نقل جهاز '.$device->name.' إلى فرع '.$branch->name.' — يلزم إعادة تفعيله', [
                'subject_id' => $device->id,
            ]);

            return back()->with('toast', [
                'msg' => __('نُقل الجهاز إلى :branch وأُبطل تفعيله — أعد تفعيله من الجهاز نفسه.', ['branch' => $branch->name]),
                'type' => 'warning',
            ]);
        }

        Activity::log('updated', 'عدّل جهاز نقطة بيع: '.$device->name, ['subject_id' => $device->id]);

        return back()->with('toast', ['msg' => __('تم حفظ الجهاز'), 'type' => 'success']);
    }

    public function revoke(int $id)
    {
        $device = $this->find($id);
        PosTerminal::revoke($device);
        Activity::log('deleted', 'ألغى تفعيل جهاز: '.$device->name, ['subject_id' => $device->id]);

        return back()->with('toast', ['msg' => __('أُلغي تفعيل الجهاز'), 'type' => 'warning']);
    }

    /**
     * حذفُ صفّ الجهاز — وهو غيرُ إبطال تفعيله.
     *
     * ═══ ولمَ لزم ═══
     *
     * القائمةُ كانت تنمو ولا تنقص. جهازٌ فُعِّل بالخطأ على الفرع الخطأ، أو
     * حاسوبٌ بيع، أو تجربةٌ يوم التركيب — يبقى صفُّه في الجدول أبدًا يقول
     * «ملغى». وبعد سنةٍ تُقرأ شاشةُ الأجهزة فلا يُعرف الصندوقُ القائم من
     * أثرِ تجربةٍ قديمة.
     *
     * ═══ وما لا يُحذف ═══
     *
     * **صندوقٌ باع لا يُحذف.** `orders.pos_device_id` و`shifts.pos_device_id`
     * يسقطان إلى `NULL` عند حذف الصفّ (`nullOnDelete`) — فحذفُ الجهاز يمحو
     * الجوابَ عن سؤالٍ لا يُسأل إلّا حين يقع خطب: «الدرج ناقصٌ عشرين ريالًا،
     * من أيّ صندوقٍ خرجت؟». والعمودُ كُتب لهذا وحده.
     *
     * فيُمنع الحذف ويُقال كم عليه، كما يُمنع حذفُ فرعٍ فيه بضاعة.
     *
     * **وجهازٌ نشطٌ لا يُحذف قبل إبطاله.** خطوتان لا واحدة: الإبطالُ يقتل
     * الرمز، والحذفُ يمحو الصفّ. وحذفٌ يفعلهما معًا بنقرةٍ واحدة يُخرج
     * صندوقًا من الخدمة وهو يظنّ أنّه يُرتّب قائمة.
     *
     * والملحقاتُ تذهب معه — طابعتُه ودرجُه صفوفٌ لا معنى لها بلا جهازها
     * (`cascadeOnDelete`)، وهي بعضُ ما يُراد حذفُه أصلًا.
     */
    public function destroy(int $id)
    {
        $device = $this->find($id);

        if ($device->isActive()) {
            return self::refuse(__('هذا الصندوق ما زال مفعَّلًا — ألغِ تفعيله أوّلًا، ثم احذف سجلّه.'));
        }

        $orders = $device->orders()->count();
        $shifts = $device->shifts()->count();

        if ($orders > 0 || $shifts > 0) {
            /*
             * والرقمان بصيغة «تسمية: رقم» لا داخل جملة: «:n فاتورة» تنكسر
             * مع كلّ عدد — «فاتورة» للواحدة و«فواتير» للثلاث.
             */
            return self::refuse(__(
                'على «:name» — الفواتير: :orders · الورديات: :shifts. حذف سجلّه يمحو نسبتَها إليه، فلا يُعرف من أيّ صندوق خرجت. يبقى ملغًى في القائمة.',
                ['name' => $device->name, 'orders' => $orders, 'shifts' => $shifts]
            ));
        }

        Activity::log('deleted', 'حذف سجلّ جهاز: '.$device->name, ['subject_id' => $device->id]);
        $device->delete();

        return back()->with('toast', ['msg' => __('حُذف سجلّ الجهاز'), 'type' => 'warning']);
    }

    /**
     * رفضُ حذفٍ يُقال — على القناة التي تعرضها الشاشة.
     *
     * `withErrors` لا قارئَ لها هنا: لا حقلَ في جدول الأجهزة يُعلَّق عليه
     * خطأ، ونافذةُ التأكيد تُغلق بعد الإرسال. فرسالةٌ تُكتب هناك تُكتب
     * لنفسها — انظر `BranchController::refuse`، العطبُ واحد.
     */
    private static function refuse(string $message): \Illuminate\Http\RedirectResponse
    {
        return back()->with('toast', ['msg' => $message, 'type' => 'danger']);
    }

    /**
     * التفعيل فعلٌ إداريّ لا يملكه الكاشير.
     *
     * صلاحية «نقطة البيع» تفتح الشاشة، ولا تكفي لربط الصندوق بفرع: من يستطيع
     * ذلك يستطيع تحويل مبيعات فرعٍ إلى فرعٍ آخر بنقرتين. فيُشترط قسم الإعدادات
     * — وهو ما يملكه صاحب النشاط والمدير.
     */
    private function authorizeActivation(): void
    {
        abort_unless(auth()->user()->allows('settings'), 403, __('تفعيل الجهاز يحتاج صلاحية الإعدادات.'));
    }

    /** الجهاز داخل متجر المستخدم وحده — منعًا لتخطّي المستأجرين بالمعرّف */
    private function find(int $id): PosDevice
    {
        return PosDevice::where('business_id', Demo::bid())->findOrFail($id);
    }
}
