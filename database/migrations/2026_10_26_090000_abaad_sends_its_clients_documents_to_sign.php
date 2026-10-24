<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * مستنداتُ أبعاد مع عملائها — عقدٌ يُرسَل برابطٍ ويُوقَّع من الهاتف.
 *
 * ═══ قالبٌ لكلّ خدمة ═══
 *
 * القالبُ يكتبه مدير المنصّة: عنوانُه وخدمتُه وبنودُه، وهل فيه الباقةُ ودورتُها
 * أو مبلغٌ مقطوع. وأوّلُها «عقد اشتراك أبعاد» بنصّه كما صُمِّم.
 *
 * ═══ والمستندُ نسخةٌ لا مرجع ═══
 *
 * يُنسخ عنوانُ القالب وبنودُه والباقةُ وسعرُها في المستند ساعةَ يُصدَر. فتعديلُ
 * القالب غدًا لا يغيّر عقدًا أُرسل أمس، ولا عقدًا وُقِّع — العميلُ وقّع على
 * ما قرأ، لا على ما كُتب بعده.
 *
 * ═══ والعميلُ قد لا يكون في النظام بعد ═══
 *
 * العقدُ يُوقَّع عادةً قبل التفعيل. فالربطُ بالنشاط اختياريّ (`client_business_id`)
 * وبيانُ العميل في المستند نفسه. ولا يُسمّى العمود `business_id`: حذفُ نشاطٍ
 * أو مسحُ متجرٍ تجريبيّ يمسح كلَّ جدولٍ فيه `business_id` — والعقدُ الموقَّع
 * سجلُّ المنصّة، يبقى بعد النشاط. والربطُ يُفكّ وحده (`nullOnDelete`).
 */
return new class extends Migration
{
    public const SUBSCRIPTION_TERMS = [
        ['h' => 'موضوع العقد:', 'b' => 'يمنح الطرف الأول الطرف الثاني حق استخدام نظام أبعاد السحابي وفق الباقة المختارة أعلاه وحدودها.'],
        ['h' => 'مدة الاشتراك:', 'b' => 'تبدأ من تاريخ التفعيل وتمتد شهرًا أو سنة حسب الدورة المختارة، وتتجدد بنفس الدورة ما لم يُطلب الإلغاء قبل نهايتها.'],
        ['h' => 'الرسوم والدفع:', 'b' => 'يلتزم الطرف الثاني بسداد رسوم الاشتراك مقدمًا عند بداية كل دورة. يحق للطرف الأول إيقاف الخدمة مؤقتًا عند عدم السداد.'],
        ['h' => 'الإضافات:', 'b' => 'الأجهزة وتخصيص الموقع ورقم واتساب الخاص ورسائل واتساب الإضافية خدمات منفصلة تُحتسب بحسب الطلب.'],
        ['h' => 'البيانات:', 'b' => 'تبقى بيانات الطرف الثاني ملكًا له، ويلتزم الطرف الأول بحفظها وعدم مشاركتها مع أي طرف آخر إلا بموافقته أو بموجب القانون.'],
        ['h' => 'الترقية والتغيير:', 'b' => 'يمكن للطرف الثاني ترقية الباقة في أي وقت، وتُحتسب الرسوم الجديدة من تاريخ الترقية.'],
        ['h' => 'الإلغاء:', 'b' => 'يحق لأي من الطرفين إنهاء العقد بإشعار مكتوب قبل نهاية الدورة الحالية، ولا تُسترد رسوم الدورة الجارية.'],
    ];

    public function up(): void
    {
        Schema::create('platform_document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // الخدمةُ التي يخصّها — اشتراك، تخصيص موقع، تطبيق خاص، أجهزة…
            $table->string('service')->nullable();
            $table->string('title');
            $table->string('title_en')->nullable();
            $table->json('terms');
            // الباقةُ ودورتُها من باقات النظام الحيّة — أو مبلغٌ مقطوع للخدمة
            $table->boolean('includes_plan')->default(false);
            $table->boolean('includes_amount')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('platform_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('template_id')->nullable()->index()
                ->constrained('platform_document_templates')->nullOnDelete();
            $table->foreignId('client_business_id')->nullable()->index()
                ->constrained('businesses')->nullOnDelete();

            // نسخةُ القالب ساعةَ الإصدار — لا تتبعه بعدها
            $table->string('service')->nullable();
            $table->string('title');
            $table->string('title_en')->nullable();
            $table->json('terms');
            $table->json('plan')->nullable();
            $table->decimal('amount', 12, 3)->nullable();
            $table->string('amount_label')->nullable();

            // ما كتبه المدير مسبقًا، ثمّ ما أقرّه العميلُ عند التوقيع
            $table->json('client');
            // اسمُ العميل ونشاطُه وهاتفُه نصًّا — للبحث والقائمة (JSON لا يُبحث فيه بـLIKE)
            $table->string('client_label')->nullable()->index();

            $table->string('token', 32)->unique();
            $table->string('status', 16)->default('sent')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_agent', 512)->nullable();
            $table->string('signature_path')->nullable();
            // بصمةُ ما وُقِّع عليه — تُطبع في الورقة، ويُعاد حسابُها للتحقّق
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('platform_document_templates')->insert([
            'name' => 'عقد اشتراك أبعاد',
            'service' => 'اشتراك النظام',
            'title' => 'عقد اشتراك',
            'title_en' => 'Subscription Agreement',
            'terms' => json_encode(self::SUBSCRIPTION_TERMS, JSON_UNESCAPED_UNICODE),
            'includes_plan' => true,
            'includes_amount' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_documents');
        Schema::dropIfExists('platform_document_templates');
    }
};
