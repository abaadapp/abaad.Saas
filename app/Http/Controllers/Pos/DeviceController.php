<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
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

        $this->authorizeActivation();

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
        $current = PosTerminal::current();

        return Inertia::render('Admin/Devices/Index', [
            'devices' => PosDevice::where('business_id', Demo::bid())
                ->with('branch:id,name', 'activatedBy:id,name')
                ->orderByDesc('id')->get()->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'branch' => $d->branch?->name ?? '—',
                    'branchId' => $d->branch_id,
                    'status' => $d->status,
                    'lastSeen' => $d->last_seen_at?->diffForHumans() ?? '—',
                    'activatedAt' => $d->activated_at?->format('Y-m-d') ?? '—',
                    'activatedBy' => $d->activatedBy?->name ?? '—',
                    // الجهاز الذي تقف عليه الآن — لئلا يُلغي المدير جهازه بيده
                    'isThis' => $current?->id === $d->id,
                ])->values()->all(),
            'branches' => Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $device = $this->find($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'branch_id' => ['required', 'integer'],
        ]);

        $branch = Branch::where('business_id', Demo::bid())->find($data['branch_id']);
        if (! $branch) {
            return back()->withErrors(['branch_id' => __('هذا الفرع غير متاح.')]);
        }

        $moved = $device->branch_id !== $branch->id;
        $device->update(['name' => $data['name'], 'branch_id' => $branch->id]);

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
