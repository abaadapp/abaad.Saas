<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رقم الفاتورة فريد داخل كل نشاط.
 *
 * كان يُولَّد بـ random_int(78900, 99999) بلا أي قيد: 21,100 قيمة فقط، أي احتمال
 * تصادم ≈61% خلال 200 فاتورة. التصادم يعني فاتورتين مختلفتين بالرقم نفسه —
 * فيلتبس البحث والطباعة والمرتجعات وتقارير الضريبة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renumberDuplicates();

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['business_id', 'number'], 'orders_business_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_business_number_unique');
        });
    }

    /** يمنح المكرَّرات القديمة أرقامًا جديدة حتى يقبل القيدُ البياناتِ الموجودة */
    private function renumberDuplicates(): void
    {
        $dupes = DB::table('orders')
            ->select('business_id', 'number', DB::raw('COUNT(*) as c'))
            ->groupBy('business_id', 'number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            // نُبقي الأقدم على رقمه ونُعيد ترقيم من بعده
            $ids = DB::table('orders')
                ->where('business_id', $d->business_id)
                ->where('number', $d->number)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            foreach ($ids as $id) {
                DB::table('orders')->where('id', $id)->update([
                    'number' => $this->freeNumber($d->business_id, (string) $d->number, $id),
                ]);
            }
        }
    }

    private function freeNumber(?int $businessId, string $original, int $id): string
    {
        // نشتقّ الرقم الجديد من معرّف الصف: فريد بطبيعته وثابت عند إعادة التشغيل
        $prefix = preg_match('/^([A-Z]+-)/', $original, $m) ? $m[1] : 'INV-';

        do {
            $candidate = $prefix . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $taken = DB::table('orders')
                ->where('business_id', $businessId)
                ->where('number', $candidate)
                ->where('id', '!=', $id)
                ->exists();
            $id += 1000000; // لو كان محجوزًا، ابتعد إلى مدى لا يتقاطع مع المعرّفات
        } while ($taken);

        return $candidate;
    }
};
