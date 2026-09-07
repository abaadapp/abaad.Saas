<?php

namespace Tests;

use App\Models\Branch;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * الذاكرة الساكنة لا تعيش بين اختبارين.
     *
     * قاعدة البيانات تُلفّ وتُعاد، لكن `Demo::$baseCur` ساكنةٌ تعيش ما عاشت
     * العمليّة — فاختبارٌ يضبط عملته دولارًا يجعل الاختبار الذي يليه يقرأ
     * الدولار وقد مُحيت إعداداته. عطلٌ في الاختبار لا في النظام، وأسوأ منه
     * أنه يظهر ويختفي بترتيب التشغيل.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\Demo::flushCurrency();
    }

    /**
     * وردية مفتوحة لهذا النشاط.
     *
     * البيع صار يتطلّبها، وهي شرطٌ للسيناريو لا موضوعُه: اختبارات المخزون
     * والحساب والفواتير تحتاج صندوقًا مفتوحًا لتصل إلى ما تفحصه. تُكتب هنا
     * مباشرةً لأن التهيئة تسبق تسجيل الدخول، فلا مستخدم بعدُ لتُشتقّ منه.
     */
    /**
     * اعتمادُ كلّ استلامٍ معلَّق — وبه تدخل البضاعةُ الرفّ.
     *
     * صار الاستلامُ خطوتين: تُكتب الورقة، ثمّ تُعتمد. وأكثرُ الاختبارات
     * تفحص أثرَ الشحنة على المخزون لا مسارَ الاعتماد نفسه، فالخطوةُ الثانية
     * فيها شرطُ السيناريو لا موضوعُه.
     *
     * وتمرّ بالخدمة لا بالمسار: المسارُ يُفحص في اختباره وحده.
     *
     * @return int عددُ ما اعتُمد
     */
    protected function approvePendingReceipts(int $businessId, ?\App\Models\User $by = null): int
    {
        $notes = \App\Models\GoodsReceiptNote::where('business_id', $businessId)
            ->where('status', \App\Support\GoodsReceipts::PENDING)->orderBy('id')->get();

        foreach ($notes as $note) {
            \App\Support\GoodsReceipts::approve($note, $by ?? auth()->user());
        }

        return $notes->count();
    }

    /**
     * اعتمادُ كلّ سند مورّدٍ معلَّق — وبه تنشأ الذمّة في الدفتر.
     *
     * وصار السندُ يُكتب أوّلًا ويُعتمد ثانيًا، كالاستلام. وأكثرُ الاختبارات
     * تفحص أثرَ الذمّة أو السداد لا مسارَ الاعتماد نفسه.
     *
     * ولا تُطابَق: التجاوزُ مُمرَّرٌ بسببٍ صريح كي تمرّ سنداتُ الاختبارات
     * التي لا أوامرَ مستلَمةً لها — والمطابقةُ تُفحص في اختبارها وحده.
     */
    protected function approvePendingSupplierInvoices(int $businessId, ?\App\Models\User $by = null): int
    {
        $invoices = \App\Models\SupplierInvoice::where('business_id', $businessId)
            ->where('approval_status', \App\Support\SupplierInvoices::PENDING)->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            \App\Support\SupplierInvoices::approve($invoice, $by ?? auth()->user(), 'اعتمادُ اختبار');
        }

        return $invoices->count();
    }

    /**
     * جهاز نقطة بيع مفعَّل على هذا المتصفّح.
     *
     * صار البيع يتطلّبه: الجهاز هو من يعرف الفرع، وبلا تفعيل يعود الفرع إلى
     * جلسة المتصفّح — وهو العطب الذي جاء التفعيل ليغلقه. وهو في أكثر
     * الاختبارات شرطُ السيناريو لا موضوعُه، فيُهيَّأ هنا في سطر.
     */
    protected function activatePosDevice(int $businessId, ?int $branchId = null): \App\Models\PosDevice
    {
        $raw = \Illuminate\Support\Str::random(64);

        $device = \App\Models\PosDevice::create([
            'business_id' => $businessId,
            'branch_id' => $branchId ?? Branch::where('business_id', $businessId)->orderBy('id')->value('id'),
            'name' => 'كاشير الاختبار',
            'token_hash' => hash('sha256', $raw),
            'status' => \App\Models\PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);

        $this->withCookie(\App\Support\PosTerminal::COOKIE, $device->id.'|'.$raw);

        return $device;
    }

}
