<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تفعيلُ جهازٍ صندوقًا — أيُّ صفٍّ يُكتب: صندوقٌ جديد، أم صندوقٌ قائمٌ يُحيا.
 *
 * ═══ العطبُ الذي يُسدّ هنا ═══
 *
 * الجهازُ يُعرف بكوكي متصفّحه، والطابعةُ والدرجُ والبنكُ معلَّقةٌ على صفّه.
 * فكان كلُّ متصفّحٍ جديد — Chrome بعد Safari، تطبيقُ أبعاد بعد المتصفّح،
 * أو المتصفّحُ نفسُه بعد مسح بياناته — يولد له صفٌّ جديدٌ بلا طابعة. وفي متجر
 * الفرع الواحد يقع ذلك **وحدَه** بلا سؤال (`PageController::index`). فتبقى
 * الطابعةُ على صفٍّ قديمٍ لا يُحذف لأنّه باع، والإيصالُ لا يخرج، والموظفُ
 * يضيف الأجهزة ويحذفها ولا يعرف لماذا.
 *
 * ═══ فالاختيارُ صريح ═══
 *
 * - **`new`** — صندوقٌ جديد. لا يُتبنّى شيء.
 * - **`replace`** — «هذا الجهازُ يحلّ محلّ صندوقٍ قائم»: يُحيا صفُّه برمزٍ جديد،
 *   فتبقى هويّتُه ومبيعاتُه ووردياتُه وطابعتُه وبنكُه، ويتوقّف الجهازُ السابق.
 *   وله أربعةُ حرّاس:
 *     1. **صلاحية الإعدادات** — من يستبدل يوقف صندوقًا قائمًا.
 *     2. **الفرعُ نفسُه** — الاستبدالُ لا ينقل: الطابعةُ على شبكة محلّها، والنقلُ
 *        بابٌ آخر يُبطل ويُسأل عنه (`DeviceController::update`).
 *     3. **الإصدار** — ما رآه المديرُ حين فتح الشاشة (`version`)، يُقارن تحت قفل
 *        الصفّ. صندوقٌ فُعّل أو أُلغي من مكانٍ آخر بين الفتح والضغط يُردّ.
 *     4. **تأكيدُ النشط** — صندوقٌ يعمل الآن على جهازٍ آخر لا يُوقَف بلا «نعم».
 * - **بلا اختيار** (صفحةٌ قديمة، أو باب الفرع الواحد): يُحيا صفُّ **هذا المتصفّح
 *   نفسِه** إن كان ملغًى في الفرع نفسه — الجهازُ نفسُه عاد. وإن كان صفُّه نشطًا
 *   على جهازٍ آخر، أو في فرعٍ آخر، لا يُسلب ولا يُنقل: يُطلب الاختيار. وإلّا
 *   صندوقٌ جديد كما كان.
 *
 * ولا يمسّ الطلبات ولا الورديات ولا القيود: يُكتب صفُّ الجهاز وحده.
 */
final class TillActivation
{
    public const NEW = 'new';

    public const REPLACE = 'replace';

    public const MODES = [self::NEW, self::REPLACE];

    /**
     * بصمةُ حال الصندوق كما عُرض — تتبدّل مع كلّ تفعيلٍ وإلغاءٍ ونقل.
     *
     * من الرمز المجزَّأ (يُدوَّر في كلّ تفعيلٍ وإلغاء) والحالة والفرع، مجزَّأةً
     * مرّةً ثانية ومقصوصة: لا يُستدلّ منها على الرمز ولا على مجزَّئه.
     */
    public static function version(PosDevice $device): string
    {
        return substr(hash('sha256', 'till-version|'.$device->token_hash.'|'.$device->status.'|'.$device->branch_id), 0, 16);
    }

    /** متجرٌ بفرعٍ واحد — لا فرعَ يُخلط به، فيفتح موظفُ نقطة البيع صندوقًا جديدًا بنفسه */
    public static function singleBranch(int $businessId): bool
    {
        return Branch::where('business_id', $businessId)->count() === 1;
    }

    /** أيفعّل هذا المستخدمُ صندوقًا جديدًا أو يُحيي صندوقَ متصفّحه؟ */
    public static function mayActivate(User $user, int $businessId): bool
    {
        return $user->allows('settings') || ($user->allows('pos') && self::singleBranch($businessId));
    }

    /** وأيستبدل صندوقًا قائمًا؟ — يوقف جهازًا آخر، فللإعدادات وحدها */
    public static function mayReplace(User $user): bool
    {
        return $user->allows('settings');
    }

    /**
     * @param  array{mode?: string|null, replaces?: int|string|null, version?: string|null, confirm_active?: bool}  $choice
     * @return array{device: PosDevice, replaced: string|null}
     *
     * @throws ValidationException
     */
    public static function run(Branch $branch, string $name, User $by, array $choice): array
    {
        $mode = $choice['mode'] ?? null;

        return DB::transaction(function () use ($branch, $name, $by, $choice, $mode) {
            if ($mode === self::REPLACE) {
                return self::replace($branch, $name, $by, $choice);
            }

            if ($mode === self::NEW) {
                return ['device' => PosTerminal::activate($branch, $name, $by->id), 'replaced' => null];
            }

            return self::sameBrowser($branch, $name, $by);
        });
    }

    /** «يحلّ محلّ» صندوقٍ اختاره المدير — بحرّاسه الأربعة */
    private static function replace(Branch $branch, string $name, User $by, array $choice): array
    {
        if (! self::mayReplace($by)) {
            throw ValidationException::withMessages(['replaces' => __('استبدال صندوق قائم يحتاج صلاحية الإعدادات — اطلبه من المدير.')]);
        }

        $target = PosDevice::where('business_id', $branch->business_id)
            ->lockForUpdate()
            ->find((int) ($choice['replaces'] ?? 0));

        if (! $target) {
            throw ValidationException::withMessages(['replaces' => __('هذا الصندوق غير متاح.')]);
        }

        if ((int) $target->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['branch_id' => __('الاستبدال يُبقي الصندوق في فرعه («:branch»). لنقله إلى فرع آخر عدّله من الإعدادات أوّلًا ثم فعّله.', [
                'branch' => $target->branch?->name ?? '—',
            ])]);
        }

        if (! hash_equals(self::version($target), (string) ($choice['version'] ?? ''))) {
            throw ValidationException::withMessages(['replaces' => __('تغيّر هذا الصندوق منذ فتحت الشاشة — فُعّل أو أُلغي من مكان آخر. حدّث الصفحة وتحقّق قبل الاستبدال.')]);
        }

        if ($target->isActive() && ! ($choice['confirm_active'] ?? false)) {
            throw ValidationException::withMessages(['confirm_active' => __('هذا الصندوق مفعَّل الآن على جهاز آخر — أكّد أنك تريد إيقافه هناك وتشغيله هنا.')]);
        }

        $was = $target->name;

        return ['device' => PosTerminal::activate($branch, $name, $by->id, $target), 'replaced' => $was];
    }

    /**
     * بلا اختيارٍ صريح — صفُّ هذا المتصفّح نفسِه، إن كان يُحيا بلا أذًى.
     *
     * ملغًى في الفرع نفسه: الجهازُ نفسُه عاد بعد إلغاءٍ أو نقل — يُحيا بعتاده.
     * نشطٌ: رمزُه لم يعد رمزَ هذا المتصفّح — صار صندوقَ جهازٍ آخر، فلا يُسلب
     * بصمت. وفي فرعٍ آخر: الإحياءُ لا ينقل.
     */
    private static function sameBrowser(Branch $branch, string $name, User $by): array
    {
        $previous = PosTerminal::previous((int) $branch->business_id);
        $previous = $previous ? PosDevice::whereKey($previous->id)->lockForUpdate()->first() : null;

        if ($previous && $previous->isActive()) {
            throw ValidationException::withMessages(['replaces' => __('هذا المتصفّح كان صندوق «:name»، وهو مفعَّل الآن على جهاز آخر. اختر «صندوق جديد» أو «يحلّ محلّه».', [
                'name' => $previous->name,
            ])]);
        }

        if ($previous && (int) $previous->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['branch_id' => __('هذا المتصفّح كان صندوق «:name» في فرع «:branch». اختر «صندوق جديد» لهذا الفرع، أو انقل ذلك الصندوق من الإعدادات أوّلًا.', [
                'name' => $previous->name,
                'branch' => $previous->branch?->name ?? '—',
            ])]);
        }

        return ['device' => PosTerminal::activate($branch, $name, $by->id, $previous), 'replaced' => null];
    }
}
