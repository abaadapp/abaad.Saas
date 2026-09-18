<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\PosDevice;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\PlanLimits;
use App\Support\PosTerminal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** تبديل الفرع الحالي (يُحفظ في الجلسة) */
    public function switch(Request $request, $branch)
    {
        if ($branch === 'all') {
            $request->session()->forget('current_branch');

            return back();
        }

        // المعرّف يصل من شريط العنوان، وكان يُخزَّن كما هو. فرعُ متجرٍ آخر
        // كان يمرّ: الاستعلامات تُرجع فراغًا (لأنها مقيّدة بـbusiness_id)
        // لكن اسم الفرع يُعرض في الترويسة — تسريب اسم من متجر الجار.
        $belongs = Branch::where('id', (int) $branch)
            ->where('business_id', $this->bid())
            ->exists();

        abort_unless($belongs, 404);

        $request->session()->put('current_branch', (int) $branch);

        return back();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            /*
             * الاسم يُميّز فرعًا من فرع — فلا يتكرّر.
             *
             * الفرع يُعرَف باسمه في كلّ موضعٍ يراه التاجر: مبدّل الفروع في
             * الشريط، وترويسة الملفّات، وعمود «الفرع» في الطلبات، ورسائل
             * الجرد. ففرعان باسم «مسقط» يعنيان قائمةً فيها سطران متطابقان
             * لا يُعرف أيّهما أيّ، وتقريرًا يُنسب إلى أحدهما ولا يُدرى أيّهما.
             *
             * والمحذوف لا يحجز اسمه: من حذف «صلالة» يفتحها من جديد.
             *
             * و`whereNull('deleted_at')` هي التي تجعل السطرَ أعلاه صحيحًا.
             * بدونها كانت `unique` تقرأ الصفَّ المحذوف: يحذف التاجر «صلالة»
             * ثمّ يفتحها فيُقال له «لديك فرعٌ بهذا الاسم» — وهو ينظر إلى
             * قائمةٍ ليس فيها صلالة. فلا يفهم، ولا شيءَ في الشاشة يدلّه على
             * سلّة المحذوفات.
             *
             * وهو القيدُ نفسُه في `ProductController`: صنفٌ حُذف لا يحجز رمزه.
             */
            'name' => $this->nameRule(),
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('لديك فرعٌ بهذا الاسم — الفرع يُعرَف باسمه في كل شاشة.'),
        ]);
        $data['business_id'] = $this->bid();
        PlanLimits::enforce(auth()->user()->business, 'branches');
        Branch::create($data);
        Activity::log('created', 'أضاف فرعًا: '.$data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة الفرع'), 'type' => 'success']);
    }

    /**
     * قاعدةُ الاسم — تُكتب مرّةً ويقرؤها الإنشاءُ والتعديل.
     *
     * حارسان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدهما: لو نُسخت القاعدة في
     * `update` لَجاز يومًا في التعديل ما يُمنع في الإنشاء — فيدخل الاسمُ
     * المكرَّر من البابِ الثاني.
     *
     * و`$except` هو الصفُّ الذي يُعدَّل: فرعٌ يُحفظ باسمه الحاليّ لا يصطدم
     * بنفسه. ومن غيرها لا يُحفظ تعديلُ الهاتف وحدَه إلّا بتغيير الاسم.
     *
     * @return array<int, mixed>
     */
    private function nameRule(?int $except = null): array
    {
        $unique = Rule::unique('branches', 'name')
            ->where('business_id', $this->bid())
            ->whereNull('deleted_at');

        return ['required', 'string', 'max:255', $except ? $unique->ignore($except) : $unique];
    }

    /**
     * تعديل الفرع — الاسم والهاتف والعنوان.
     *
     * ═══ ولمَ لزم ═══
     *
     * كان الفرع يُنشأ ويُحذف ولا يُعدَّل: قائمةُ الصفّ فيها «حذف» وحدها. فمن
     * كتب «فرغ صحار» بدل «فرع صحار» لا سبيل له إلى تصحيحه — إلّا أن يحذفه
     * ويفتحه من جديد، وذاك لا يمرّ إن كان فيه بضاعة، ولا يمرّ إن كان آخرَ
     * فرعٍ له، ولا يمرّ إن كانت باقتُه تسمح بفرعٍ واحد.
     *
     * والهاتفُ والعنوانُ يتبدّلان في الواقع: المحلّ ينتقل، والخطّ يتغيّر.
     * وبيانٌ لا يُصحَّح يُتلف نفسَه بمرور الوقت.
     *
     * ═══ والفواتيرُ الصادرة لا تتبدّل ═══
     *
     * اسمُ الفرع يُصوَّر في `orders.branch` يوم البيع، لا يُقرأ من الجدول
     * وقت الطباعة. فإعادةُ التسمية لا تُعيد كتابة ورقةٍ خرجت — والفاتورةُ
     * تقول ما كان صحيحًا يوم صدرت، وهو ما ينبغي أن تقوله.
     *
     * ولا يُبطَّل جهازٌ ولا تُمسّ وردية: الفرعُ هو هو، وإنّما تبدّل اسمُه.
     * (وهذا يفترق عن نقل **الجهاز** بين الفروع — ذاك ينقل المبيعات فيُبطَّل.)
     */
    public function update(Request $request, $id)
    {
        $branch = Branch::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'name' => $this->nameRule($branch->id),
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('لديك فرعٌ بهذا الاسم — الفرع يُعرَف باسمه في كل شاشة.'),
        ]);

        $was = (string) $branch->name;
        $branch->update($data);

        /*
         * والسجلُّ يحفظ الاسمَ القديم.
         *
         * من يقرأ تقريرًا قديمًا يجد فيه «فرع الخوير» ولا يجده في القائمة،
         * فيظنّه محذوفًا. والسطرُ هنا هو ما يصله بالاسم الجديد.
         */
        Activity::log('updated', $was === $data['name']
            ? 'عدّل بيانات الفرع: '.$data['name']
            : 'غيّر اسم الفرع: '.$was.' ← '.$data['name'],
            ['subject_id' => $branch->id, 'subject_type' => 'branch']);

        return back()->with('toast', ['msg' => __('تم حفظ الفرع'), 'type' => 'success']);
    }

    /**
     * حذف الفرع — بعد إفراغه، لا قبله.
     *
     * كان الحذف يقع بلا سؤال، فيبقى مخزون الفرع في مكانه ويختفي من كلّ شاشة:
     * الصنف يقول «الكمية ١٠» ومجموع الفروع الظاهرة ٤. ستّ قطعٍ لا يجدها
     * التاجر في فرعٍ ولا يستطيع صرفها ولا بيعها، ولا يعرف أين ذهبت — ولا
     * يكتشف الفرق إلا في جردٍ آخر السنة، حين يكون سببُه قد نُسي.
     *
     * فيُمنع الحذف ويُقال له كم بقي وكيف يُفرغه — بالحركة اليدويّة في شاشة
     * المخزون، إذ لا سندَ نقلٍ بين الفروع في النظام. والمنعُ مع إرشادٍ يمكن
     * العمل به أصدق من حذفٍ يُخفي بضاعة، ومن إرشادٍ يُحيل إلى بابٍ لا وجود له.
     *
     * وأجهزةُ الفرع تُبطَل معه: جهازٌ يبقى «نشطًا» على فرعٍ لا وجود له يردّ
     * كاشيرَه برسالة «رمز غير صحيح أو غير مسموح في هذا الفرع» — فيظنّ أنّه
     * أخطأ رمزه، والسبب حذفٌ وقع في اللوحة. وهو ما يقع عند نقل الجهاز بين
     * الفروع أصلًا، وحذفُ الفرع أشدّ من نقله.
     */
    public function destroy($id)
    {
        $branch = Branch::where('business_id', $this->bid())->findOrFail($id);

        /*
         * ═══ ولا يُحذف آخرُ فرع ═══
         *
         * متجرٌ بلا فرعٍ واحد يعمل — ويعمل بغير ما يظنّ صاحبُه. قِسته: الصندوق
         * يفتح ويبيع، والفاتورة تُكتب بـ`branch_id = NULL`، ورصيدُ الشركة
         * ينقص. فليس فسادًا في الدفتر — إنّما كلُّ بيعةٍ تُنسب إلى لا أحد،
         * وتقريرُ الفروع يسقط منه ما بيع، ولا شاشةَ تقول لماذا.
         *
         * وهو بابٌ لا يُخرَج منه إلّا بإنشاء فرعٍ جديد — ومن كانت باقتُه
         * بفرعٍ واحدٍ يخرج، ثمّ لا يستطيع الدخول ثانيةً لو أراد.
         *
         * ═══ والمنعُ لا يصحّ إلّا بوجود مخرج ═══
         *
         * كان الحذفُ هو الطريقَ الوحيد إلى تصحيح اسم فرع: يُحذف ويُفتح من
         * جديد. فلو سُدّ هذا البابُ وحدَه لَحُبس من أخطأ اسمَ فرعه الوحيد
         * بلا سبيل. و`update` أعلاه هو المخرج — وهذان السطران يعتمد أحدُهما
         * على الآخر، فلا يُحذف أحدُهما وحدَه.
         */
        if (Branch::where('business_id', $this->bid())->count() <= 1) {
            return self::refuse(__(
                'لا يمكن حذف آخر فرع — المتجر بلا فرعٍ يبيع ولا يُنسب بيعُه إلى مكان. أضِف فرعًا آخر أوّلًا، أو عدّل بيانات هذا الفرع.'
            ));
        }

        $left = (float) BranchStock::where('branch_id', $branch->id)
            ->where('quantity', '>', 0)->sum('quantity');

        if ($left > 0) {
            /*
             * والنصيحة تُسمّي بابًا موجودًا.
             *
             * كانت تقول «انقلها إلى فرعٍ آخر» ولا نقلَ في النظام أصلًا، فيبحث
             * التاجر عن زرٍّ ليس موجودًا ثمّ يظنّ العطب في بصره. ثمّ دلّته على
             * شاشة النقل بين الفروع — وقد حُذفت بطلب صاحب النظام.
             *
             * فتعود إلى الباب القائم: سجلّ المخزون، تُصرَف الكميّة من هذا
             * الفرع وتُضاف في الآخر. ونصيحةٌ تُحيل إلى مسارٍ محذوف أسوأ من
             * نصيحةٍ لا تُحيل إلى شيء، لأنّها تبدو صحيحةً حتى تُجرَّب.
             */
            return self::refuse(__(
                'في «:branch» ما زال :qty قطعة. اصرفها من المخزون ← سجل المخزون وأضِفها في فرعٍ آخر، ثم احذفه.',
                ['branch' => $branch->name, 'qty' => rtrim(rtrim(number_format($left, 3, '.', ''), '0'), '.')]
            ));
        }

        $devices = PosDevice::where('business_id', $this->bid())
            ->where('branch_id', $branch->id)
            ->where('status', PosDevice::ACTIVE)->get();

        foreach ($devices as $device) {
            PosTerminal::revoke($device);
        }

        Activity::log('deleted', 'حذف الفرع: '.$branch->name, ['subject_id' => $branch->id, 'subject_type' => 'branch']);
        $branch->delete();

        $msg = $devices->isEmpty()
            ? __('تم حذف الفرع')
            : __('تم حذف الفرع، وأُبطل تفعيل :count من أجهزته.', ['count' => $devices->count()]);

        return back()->with('toast', [
            'msg' => $msg,
            'type' => 'warning',
            'undo' => ['url' => route('admin.branches.restore', $branch->id), 'label' => $branch->name],
        ]);
    }

    /**
     * رفضُ حذفٍ يُقال للتاجر — لا يُكتب في الجلسة ويُنسى.
     *
     * ═══ العطب ═══
     *
     * كان الرفضُ `withErrors(['branch' => …])` — ولا مكوّنَ واحدٌ في الواجهة
     * يقرأ `errors.branch`. فيضغط التاجر «حذف» على فرعٍ فيه ستّ قطع، فتُغلق
     * نافذةُ التأكيد ولا يقع شيء ولا يُقال شيء: الفرعُ في مكانه والشاشةُ
     * صامتة. فيظنّ العطبَ في الزرّ ويضغط ثانيةً وثالثة.
     *
     * والرسالةُ كانت مكتوبةً ومحسوبةً — تسمّي الفرعَ وتقول كم بقي وأين
     * يُصرَف — وتذهب كلُّها إلى مفتاحٍ لا قارئَ له. وتقريرُ حالٍ لا يصل
     * كأن لم يُكتب.
     *
     * فيمرّ الرفضُ على القناة التي تعمل: `flash.toast` — يقرؤها
     * `AdminLayout` ويعرضها. و`withErrors` تبقى لما هي له: خطأُ حقلٍ
     * يُعرض تحت حقله.
     *
     * (وثمنٌ ثالث كان: `BranchesPanel` يفتح نموذجَ الإضافة حين تكون في
     * الجلسة أخطاء — فرفضُ حذفٍ كان يفتح نموذج «إضافة فرع» بلا سبب.)
     */
    private static function refuse(string $message): RedirectResponse
    {
        return back()->with('toast', ['msg' => $message, 'type' => 'danger']);
    }
}
