<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربطُ واتساب يُثبت أنّه لتطبيق أبعاد — قبل أن يُقال «يستقبل».
 *
 * أعمدةُ تحقّقٍ لا غير، كلُّها فارغةٌ على الوصلات القائمة: لا يُنادى ميتا
 * هنا، ولا يُمسّ رمزٌ ولا رقمٌ ولا حالة. والوصلةُ القائمة تُقرأ «متّصلٌ لم
 * يُتحقَّق منه» حتى يضغط صاحبُها أو فريقُ أبعاد «فحص الربط»
 * (`WhatsAppLinkCheck::recheck`).
 *
 * - `token_app_id`: التطبيقُ الذي أصدر الرمز كما قالته ميتا (`debug_token`).
 * - `token_verified_at`: متى ثبت أنّ الرمز من تطبيق أبعاد بصلاحياته وحسابه.
 * - `link_problem`: آخرُ علّةٍ وُجدت في الفحص (`WhatsAppLinkCheck::*`).
 * - `webhook_state`: هل تطبيقُ أبعاد في `subscribed_apps` لهذا الحساب.
 * - `webhook_checked_at`: متى قُرئ ذلك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_connections', 'token_app_id')) {
                $table->string('token_app_id', 32)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'token_verified_at')) {
                $table->timestamp('token_verified_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'link_problem')) {
                $table->string('link_problem', 40)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'webhook_state')) {
                $table->string('webhook_state', 20)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'webhook_checked_at')) {
                $table->timestamp('webhook_checked_at')->nullable();
            }
        });
    }

    /** أعمدةُ فحصٍ تُعاد بالضغط على «فحص الربط» — حذفُها لا يُضيّع رمزًا ولا رسالة */
    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            foreach (['token_app_id', 'token_verified_at', 'link_problem', 'webhook_state', 'webhook_checked_at'] as $column) {
                if (Schema::hasColumn('whatsapp_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
