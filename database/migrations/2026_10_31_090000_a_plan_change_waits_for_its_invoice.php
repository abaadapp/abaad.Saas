<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ترقيةُ الباقة تنتظر فاتورتها — `subscriptions.from_plan_id`.
 *
 * تجديدٌ بباقةٍ غير باقة المتجر كان يُرفض عند السداد دائمًا (`Billing::start`):
 * لا يُعرف أنُقل المتجرُ بعد الإصدار، أم صدرت الفاتورةُ لنقله. فالترقيةُ تكتب
 * هنا الباقةَ التي كان عليها يومَ صدرت فاتورتُها: يُسدَّد فيُنقل إلى باقتها، وإن
 * تغيّرت باقتُه بينهما رُفض السداد كما كان.
 *
 * عمودٌ فارغٌ لكلّ صفٍّ قائم — فلا يتغيّر تجديدٌ ولا فاتورةٌ صدرا قبله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('from_plan_id')->nullable()->after('plan_id')->index()->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_plan_id');
        });
    }
};
