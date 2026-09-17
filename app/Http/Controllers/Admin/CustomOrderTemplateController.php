<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomOrderField;
use App\Models\CustomOrderFieldOption;
use App\Models\CustomOrderTemplate;
use App\Support\Activity;
use App\Support\CustomArrangement;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * قوالبُ الطلب المخصَّص — صاحبُ النشاط يكتب شكلَ طلبه.
 *
 * ═══ ولمَ يُحفظ القالبُ وحقولُه وخياراتُه في طلبٍ واحد ═══
 *
 * حقلٌ بلا قالبٍ لا معنى له، وخيارٌ بلا حقلٍ كذلك. ولو كان لكلٍّ بابُه
 * لَصار حفظُ قالبٍ فيه ثلاثةُ حقولٍ وتسعةُ خيارات ثلاثةَ عشرَ طلبًا: يسقط
 * أحدُها فيبقى القالبُ نصفَ محفوظ، ولا شيءَ يقول أيُّها سقط.
 *
 * فبابٌ واحد ومعاملةٌ واحدة: إمّا أن يُحفظ الشكلُ كلُّه أو لا يُحفظ شيء.
 *
 * ═══ وما يُبقى وما يُحذف ═══
 *
 * الحقلُ الذي يصل بمعرّفه يُحدَّث في موضعه، والذي يصل بلا معرّفٍ يُنشأ،
 * والذي لم يصل يُحذف. ولا يُمحى الشكلُ ويُعاد بناؤه: إعادةُ البناء تُغيّر
 * معرّفات الحقول والخيارات، فسلّةٌ معلَّقةٌ تُستأنف على معرّفاتٍ لم تعد
 * موجودة — ويجدها الكاشيرُ فارغةً بلا سببٍ يُقال.
 *
 * ولا يُمسّ طلبٌ بيع: البندُ يحمل لقطةَ تسمياته، لا مرجعًا إلى هذه الصفوف.
 */
class CustomOrderTemplateController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** أكثرُ ما يُقبل من حقولٍ في قالب — حدٌّ يمنع شاشةً لا تُقرأ */
    private const MAX_FIELDS = 30;

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $template = DB::transaction(function () use ($data) {
            $template = CustomOrderTemplate::create($this->columns($data) + ['business_id' => $this->bid()]);
            $this->syncFields($template, $data['fields'] ?? []);

            return $template;
        });

        Activity::log('settings', 'أنشأ قالب طلب مخصص: '.$template->name, ['subject_id' => $template->id]);

        return back()->with('toast', ['msg' => __('تم حفظ القالب'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $template = $this->mine($id);
        $data = $this->validated($request, $template);

        DB::transaction(function () use ($template, $data) {
            $template->update($this->columns($data));
            $this->syncFields($template, $data['fields'] ?? []);
        });

        Activity::log('settings', 'عدّل قالب طلب مخصص: '.$template->name, ['subject_id' => $template->id]);

        return back()->with('toast', ['msg' => __('تم حفظ القالب'), 'type' => 'success']);
    }

    /**
     * يحذف القالبَ حذفًا ليّنًا — ولا يمسّ طلبًا بيع به.
     *
     * والحذفُ لا يُحيي الافتراضيّ: `seedDefault` تنصرف متى وُجد صفٌّ ولو
     * محذوفًا. فمن حذف قوالبَه كلَّها أغلق الميزةَ بيده، ولا يُقحَم عليه
     * قالبٌ لم يطلبه في أوّل بيعةٍ بعده.
     */
    public function destroy($id)
    {
        $template = $this->mine($id);
        $template->delete();

        Activity::log('settings', 'حذف قالب طلب مخصص: '.$template->name, ['subject_id' => $template->id]);

        return back()->with('toast', ['msg' => __('تم حذف القالب'), 'type' => 'success']);
    }

    /**
     * ترتيبُ القوالب كما يريده صاحبُ النشاط — وهو ترتيبُ مُنتقي الصندوق.
     *
     * ويُحصر بالمتجر صفًّا صفًّا: معرّفُ قالبِ متجرٍ آخر في القائمة كان
     * سيُعيد ترتيبَ قوالبه.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $mine = CustomOrderTemplate::where('business_id', $this->bid())
            ->whereIn('id', $data['ids'])->pluck('id')->all();

        DB::transaction(function () use ($data, $mine) {
            foreach ($data['ids'] as $at => $id) {
                if (in_array((int) $id, $mine, true)) {
                    CustomOrderTemplate::whereKey($id)->update(['sort_order' => $at]);
                }
            }
        });

        return back()->with('toast', ['msg' => __('تم حفظ الترتيب'), 'type' => 'success']);
    }

    /** قالبٌ من متجر الطالب لا غير — ولا يُقال أيّ متجرٍ يملكه */
    private function mine($id): CustomOrderTemplate
    {
        return CustomOrderTemplate::where('business_id', $this->bid())->findOrFail($id);
    }

    /** أعمدةُ القالب وحدها — الحقولُ تُكتب بمعاملتها */
    private function columns(array $data): array
    {
        return [
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'modes' => $data['modes'],
            'default_mode' => in_array($data['default_mode'] ?? null, $data['modes'], true)
                ? $data['default_mode']
                : $data['modes'][0],
            'base_label' => $data['base_label'] ?? null,
            'base_label_en' => $data['base_label_en'] ?? null,
            'allow_components' => (bool) ($data['allow_components'] ?? false),
            'allow_addons' => (bool) ($data['allow_addons'] ?? false),
            'components_restockable_default' => (bool) ($data['components_restockable_default'] ?? false),
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    /**
     * يكتب حقولَ القالب وخياراتِها — تحديثًا في الموضع لا محوًا وإعادةَ بناء.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncFields(CustomOrderTemplate $template, array $rows): void
    {
        $keep = [];

        foreach ($rows as $at => $row) {
            /*
             * والمعرّفُ يُبحث عنه داخل هذا القالب لا في الجدول كلِّه.
             *
             * `find($id)` المجرّدة كانت تسمح بأن يُمرَّر معرّفُ حقلٍ من قالب
             * متجرٍ آخر فيُنقل إليه — نقلُ ملكيّةٍ بحقلٍ في نموذج.
             */
            $field = filled($row['id'] ?? null)
                ? $template->fields()->find($row['id'])
                : null;

            $columns = [
                'label' => $row['label'],
                'label_en' => $row['label_en'] ?? null,
                'type' => $row['type'],
                'required' => (bool) ($row['required'] ?? false),
                'internal' => (bool) ($row['internal'] ?? false),
                'active' => (bool) ($row['active'] ?? true),
                'sort_order' => $at,
            ];

            if ($field) {
                $field->update($columns);
            } else {
                $field = $template->fields()->create($columns);
            }

            $keep[] = $field->id;
            $this->syncOptions($field, $row['options'] ?? []);
        }

        // ما لم يصل حُذف — والطلباتُ الماضية تحمل تسمياتها لا مرجعًا إليه
        $template->fields()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function syncOptions(CustomOrderField $field, array $rows): void
    {
        if (! $field->takesOptions()) {
            // نوعٌ لا يقبل خيارات: ما كان له منها يُحذف، فلا تبقى قائمةٌ لا تُعرض
            $field->options()->delete();

            return;
        }

        $keep = [];

        foreach ($rows as $at => $row) {
            $option = filled($row['id'] ?? null) ? $field->options()->find($row['id']) : null;

            $columns = [
                'label' => $row['label'],
                'label_en' => $row['label_en'] ?? null,
                'active' => (bool) ($row['active'] ?? true),
                'sort_order' => $at,
            ];

            $option = $option ? tap($option)->update($columns) : $field->options()->create($columns);
            $keep[] = $option->id;
        }

        $field->options()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /**
     * ما يُقبل من الشاشة.
     *
     * والأنواعُ والأوضاعُ تُقرأ من ثوابت النظام لا من قائمةٍ تُكتب هنا:
     * قائمتان لشيءٍ واحد تفترقان يوم يُضاف نوعٌ إلى إحداهما.
     */
    private function validated(Request $request, ?CustomOrderTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],

            'modes' => ['required', 'array', 'min:1'],
            'modes.*' => ['string', 'in:'.implode(',', CustomArrangement::MODES)],
            'default_mode' => ['nullable', 'string', 'in:'.implode(',', CustomArrangement::MODES)],

            'base_label' => ['nullable', 'string', 'max:120'],
            'base_label_en' => ['nullable', 'string', 'max:120'],

            'allow_components' => ['sometimes', 'boolean'],
            'allow_addons' => ['sometimes', 'boolean'],
            'components_restockable_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],

            'fields' => ['nullable', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.label_en' => ['nullable', 'string', 'max:120'],
            'fields.*.type' => ['required', 'string', 'in:'.implode(',', CustomOrderField::TYPES)],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.internal' => ['sometimes', 'boolean'],
            'fields.*.active' => ['sometimes', 'boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:'.CustomOrderFieldOption::MAX_PER_FIELD],
            'fields.*.options.*.id' => ['nullable', 'integer'],
            'fields.*.options.*.label' => ['required', 'string', 'max:120'],
            'fields.*.options.*.label_en' => ['nullable', 'string', 'max:120'],
            'fields.*.options.*.active' => ['sometimes', 'boolean'],
        ], [], [
            'name' => __('اسم القالب'),
            'modes' => __('طرق التسعير'),
        ]);
    }
}
