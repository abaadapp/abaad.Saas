<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كلُّ تاجرٍ بتطبيقه هو عند ميتا — وأبعاد برنامجٌ يستقبل إشعاراته ويُرسل برمزه.
 *
 * ميتا توقّع إشعاراتِ كلِّ تطبيقٍ بسرّه هو، فلا يُتحقَّق من إشعار تطبيق التاجر
 * بسرّ أبعاد. فلكلّ وصلةٍ سرُّ تطبيقها، وعنوانُ إشعاراتٍ لا يشاركها فيه أحد،
 * ورمزُ تحقّقٍ تطابقه ميتا حين تُسجّل العنوان.
 *
 * - `link_type`: `own_app` لوصلةٍ بتطبيق التاجر؛ وفارغٌ لما رُبط قبل هذا.
 * - `app_secret`: سرُّ تطبيق التاجر — مشفَّرٌ ومخفيّ (`WhatsAppConnection::$casts`).
 * - `webhook_key`: الجزءُ الأخير من عنوان إشعاراتها (`/webhooks/whatsapp/m/{key}`).
 * - `webhook_verify_token`: ما يكتبه التاجرُ في لوحة تطبيقه — مشفَّر.
 * - `webhook_verified_at`: متى طابقت ميتا الرمزَ على عنواننا.
 * - `billing_state` و`billing_owner_*` و`billing_checked_at`: على من تُحتسب
 *   رسائلُ هذا الحساب عند ميتا — والربطُ لا يُرسل قبل أن يثبت أنّها على التاجر.
 * - `billing_evidence`: ما راجعه فريقُ أبعاد دليلًا على ذلك، ومن راجعه ومتى،
 *   وبصمةُ وسيلة الدفع إن قرأتها ميتا لنا.
 *
 * أعمدةٌ فارغةٌ على الوصلات القائمة: لا يُمسّ رمزٌ ولا رقمٌ ولا وضع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_connections', 'link_type')) {
                $table->string('link_type', 20)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'app_secret')) {
                $table->text('app_secret')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'webhook_key')) {
                $table->string('webhook_key', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'webhook_verify_token')) {
                $table->text('webhook_verify_token')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'webhook_verified_at')) {
                $table->timestamp('webhook_verified_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'billing_state')) {
                $table->string('billing_state', 20)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'billing_owner_id')) {
                $table->string('billing_owner_id', 64)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'billing_owner_name')) {
                $table->string('billing_owner_name', 191)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'billing_checked_at')) {
                $table->timestamp('billing_checked_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'billing_evidence')) {
                $table->json('billing_evidence')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_connections', 'webhook_key')) {
                $table->dropUnique(['webhook_key']);
            }
        });

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            foreach (['link_type', 'app_secret', 'webhook_key', 'webhook_verify_token', 'webhook_verified_at',
                'billing_state', 'billing_owner_id', 'billing_owner_name', 'billing_checked_at', 'billing_evidence'] as $column) {
                if (Schema::hasColumn('whatsapp_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
