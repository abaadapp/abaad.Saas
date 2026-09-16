<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobTitle extends Model
{
    protected $guarded = [];

    /** أدوار النظام المسموح ربط الوظيفة بها (تحدّد صلاحيات الدخول) */
    /** أدوار الموظّفين — مصدرها الوحيد `App\Support\Roles` */
    public static function roles(): array
    {
        return \App\Support\Roles::staffLabels();
    }

    /**
     * وظائفُ البداية لنشاطٍ جديد — الستُّ التي يعرفها النظام.
     *
     * ═══ العطب ═══
     *
     * كان `MerchantAccount::provision` يبذر **وظيفةً واحدة**: «مدير» دورُها
     * `admin`. فيفتح التاجرُ الجديد «الرواتب والموظفين» فلا يجد مسمًّى واحدًا
     * لكاشيرٍ ولا لبائعٍ ولا لمحاسب — والوظيفةُ الوحيدة الجاهزة **تصنع صاحبَ
     * نشاطٍ ثانيًا**: من أُسندت إليه صار دورُه `admin`، يملك كلَّ قسمٍ ويمسّ
     * كلَّ حساب. قِسناه على متجرٍ حقيقيّ: «تيست 1» له وظيفةٌ واحدة، `admin`.
     *
     * والستُّ موجودةٌ في المتجرين الأقدمين وحدَهما — بذرتهما هجرةٌ نُفّذت
     * مرّةً يومَ كانا قائمين، ولم يرثها من جاء بعدهما.
     *
     * ═══ والاسمُ يُقرأ من `Roles` لا يُكتب هنا ═══
     *
     * قائمتان تُكتبان باليد تفترقان يومًا — وقد افترقت ثلاثٌ: الهجرةُ كتبت
     * «مسؤول مخزون»، و`DemoStore` كتبت «أمين مخزن»، فخرج من المتجر التجريبيّ
     * موظّفٌ على وظيفةٍ لا وجودَ لها.
     *
     * و`admin` ليست فيها بحكم `Roles::STAFF`: «وظيفةٌ تمنحه لأيّ موظّف تمنحه
     * كلَّ شيء».
     *
     * والاسمُ خامٌ لا مترجَم — `staffNames` لا `staffLabels`: هو مفتاحُ ربطٍ
     * يُخزَّن، لا نصٌّ يُعرض. انظر تعليلَها في `Roles`.
     */
    public static function seedDefaults(int $businessId): void
    {
        $covered = self::where('business_id', $businessId)->pluck('role')->all();

        foreach (\App\Support\Roles::staffNames() as $role => $label) {
            /*
             * والتغطيةُ بالدور لا بالاسم.
             *
             * متجرٌ قائمٌ يحمل «مدير» لدور `manager` — بذرتها هجرةٌ قديمة
             * بتسميةٍ غير تسمية اليوم. فالبذرُ بالاسم كان يضيف «مدير فرع»
             * بجانبها: مسمّيان لدورٍ واحد في شاشة تاجرٍ لم يطلب ذلك.
             *
             * فما غُطِّي دورُه يُترك على اسمه، وما لم يُغطَّ يُبذَر.
             */
            if (in_array($role, $covered, true)) {
                continue;
            }

            self::firstOrCreate(
                ['business_id' => $businessId, 'name' => $label],
                ['role' => $role],
            );
        }
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
