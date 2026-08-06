<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * وردية مفتوحة لهذا النشاط.
     *
     * البيع صار يتطلّبها، وهي شرطٌ للسيناريو لا موضوعُه: اختبارات المخزون
     * والحساب والفواتير تحتاج صندوقًا مفتوحًا لتصل إلى ما تفحصه. تُكتب هنا
     * مباشرةً لأن التهيئة تسبق تسجيل الدخول، فلا مستخدم بعدُ لتُشتقّ منه.
     */
    protected function openShiftFor(int $businessId, ?int $branchId = null): Shift
    {
        return Shift::create([
            'business_id' => $businessId,
            'branch_id' => $branchId ?? Branch::where('business_id', $businessId)->orderBy('id')->value('id'),
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => Shift::OPEN,
        ]);
    }
}
