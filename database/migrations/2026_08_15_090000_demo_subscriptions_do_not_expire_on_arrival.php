<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * يُقدِّم اشتراك المتاجر التجريبيّة المنتهية إلى ذكرى تجديدٍ قادمة.
 *
 * `DemoStore` كان يحسب `ends_at` سنةً بعد التسجيل، والتسجيلُ يُحسب بعُمق
 * التاريخ المطلوب — فمتجرٌ بتاريخ اثني عشر شهرًا ينتهي اشتراكه يوم يُنشأ.
 * أُصلح المُنشئ، لكنّ التاريخ الخاطئ صفٌّ في القاعدة لا سطرٌ في شيفرة: من
 * أنشأ متجره قبل الإصلاح يبقى شريطُه الأحمر حتّى يعيد بناء البيانات كلَّها.
 *
 * ولا يمسّ هذا الترحيلُ متجر تاجرٍ: شرطه `is_demo` وحده، وهي علامةٌ يحرسها
 * `DemoGuard` فلا تُكتب إلا على ما أنشأته المنصّة للعرض. وتمديدُ اشتراك
 * تاجرٍ بترحيلٍ صامت مالٌ لم يُقبض.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('businesses', 'is_demo')) {
            return;
        }

        $floor = now()->addMonths(2);

        foreach (DB::table('businesses')->where('is_demo', true)->get(['id', 'ends_at']) as $demo) {
            if (! $demo->ends_at) {
                continue;
            }

            $ends = Carbon::parse($demo->ends_at);
            if ($ends->greaterThanOrEqualTo($floor)) {
                continue;
            }

            while ($ends->lessThan($floor)) {
                $ends->addYear();
            }

            DB::table('businesses')->where('id', $demo->id)->update(['ends_at' => $ends]);

            /*
             * وصفّ الاشتراك معه — الشريط يقرأ `businesses.ends_at`، لكنّ شاشة
             * «الاشتراك» داخل المتجر تقرأ هذا. وتركُه يجعل العرض يقول شيئين.
             */
            if (DB::getSchemaBuilder()->hasTable('subscriptions')) {
                DB::table('subscriptions')
                    ->where('business_id', $demo->id)
                    ->where('ends_at', '<', $floor)
                    ->update(['ends_at' => $ends]);
            }
        }
    }

    /**
     * لا رجوع: القيمة السابقة تاريخٌ منتهٍ لا حالةَ نظامٍ تُستعاد، وإعادةُ
     * كتابته تُعيد العطل نفسه.
     */
    public function down(): void {}
};
