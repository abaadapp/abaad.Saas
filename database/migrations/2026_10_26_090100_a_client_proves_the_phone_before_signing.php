<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رابطُ المستند وحده لا يوقّع — يُثبت فاتحُه أنّه يملك الهاتف المعتمد.
 *
 * `signing_phone` رقمُ العميل كما اعتمده مدير المنصّة ساعةَ الإصدار، بصيغةٍ
 * دوليّة. إليه وحده يُرسل الرمز، ولا تغيّره صفحةُ التوقيع.
 *
 * `platform_document_codes` رموزُ التحقّق: لا يُحفظ الرمزُ نفسه بل بصمتُه
 * بسرّ التطبيق، ولكلّ رمزٍ عشرُ دقائق وخمسُ محاولات واستعمالٌ واحد. وما
 * يُثبته الرمزُ ملكيّةُ الرقم لا هويّةُ صاحبه.
 *
 * جدولا المنصّة وحدهما: لا عمودَ business_id، ولا صفَّ متجرٍ يُمسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_documents', function (Blueprint $table) {
            $table->string('signing_phone', 20)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
        });

        Schema::create('platform_document_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->index()->constrained('platform_documents')->cascadeOnDelete();
            // الرقمُ الذي أُرسل إليه — يُطابَق برقم المستند عند التحقّق والتوقيع
            $table->string('phone', 20);
            // HMAC-SHA256 للرمز بسرّ التطبيق — الرمزُ نفسه لا يُكتب في أيّ مكان
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_ip', 45)->nullable();
            // بصمةُ ما حُفظ في جلسة المتصفّح الذي تحقّق — لا يوقّع غيرُه
            $table->string('grant_hash', 64)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_document_codes');

        Schema::table('platform_documents', function (Blueprint $table) {
            $table->dropColumn(['signing_phone', 'phone_verified_at']);
        });
    }
};
