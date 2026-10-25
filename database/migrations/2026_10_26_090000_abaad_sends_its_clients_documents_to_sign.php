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
    /*
     * نصُّ العقد كما يطابق النظامَ الفعليّ — مسوّدةٌ لمراجعة المالك القانونيّة:
     * لا تجديدَ ماليّ بلا سداد (`Billing::markPaid` وحده يمدّ)، ومهلةُ السماح
     * والإيقافُ من إعدادات المنصّة (`Tenancy::graceDays`، `auto_suspend`)،
     * والترقيةُ يدويّةٌ بلا احتسابٍ نسبيّ. ولا رقمَ سجلٍّ ولا عنوانَ هنا: لا
     * يُكتبان إلّا من بياناتٍ رسميّة يقدّمها المالك.
     */
    public const SUBSCRIPTION_TERMS = [
        ['h' => 'الطرفان:', 'b' => 'الطرف الأول هو «مشاريع الذهلي الجديدة»، ويقدّم الخدمة تحت الاسم التجاري للمنصة «أبعاد»، ويُشار إليه في هذا العقد بالطرف الأول أو أبعاد. والطرف الثاني هو العميل المذكورة بياناته أعلاه.'],
        ['h' => 'موضوع العقد:', 'b' => 'يمنح الطرف الأول الطرف الثاني حق استخدام نظام أبعاد السحابي وفق الباقة المختارة أعلاه وحدودها، طوال مدة الاشتراك المسددة رسومها.'],
        ['h' => 'مدة الاشتراك:', 'b' => 'تبدأ في تاريخ بداية الاشتراك المذكور أعلاه وتمتد حسب الدورة المختارة: شهرًا واحدًا للدورة الشهرية، أو ستة أشهر للدورة النصف سنوية، أو اثني عشر شهرًا للدورة السنوية، وتنتهي في تاريخ النهاية المذكور أعلاه.'],
        ['h' => 'التجديد:', 'b' => 'يخضع تجديد الاشتراك لدورة جديدة وتمديد الخدمة لتأكيد سداد رسوم تلك الدورة وفق شروط هذا الاتفاق، ولا تُمدَّد مدة الاشتراك إلا بعد سداد رسوم الدورة الجديدة. ولا يُعدّ توقيع هذا العقد بذاته سدادًا ولا تجديدًا، ولا تُخصم أي رسوم تلقائيًا من الطرف الثاني.'],
        ['h' => 'الرسوم والدفع:', 'b' => 'تُسدَّد رسوم كل دورة مقدمًا بالسعر المذكور أعلاه للباقة والدورة المختارتين، ويصدر الطرف الأول فاتورة بها. ولا تشمل هذه الرسوم الخدمات المذكورة في بند الإضافات.'],
        ['h' => 'عدم السداد:', 'b' => 'إذا انتهت مدة الاشتراك دون سداد رسوم الدورة الجديدة، تستمر الخدمة خلال مهلة السماح التي يعلنها الطرف الأول إن وُجدت، ثم يجوز له إيقاف الخدمة مؤقتًا أو تقييدها إلى حين السداد. ولا يترتب على الإيقاف وحده حذف بيانات الطرف الثاني.'],
        ['h' => 'الإضافات:', 'b' => 'الأجهزة وتخصيص الموقع ورقم واتساب الخاص ورسائل واتساب الإضافية خدمات منفصلة تُحتسب بحسب الطلب وبعد الاتفاق عليها.'],
        ['h' => 'الترقية والتغيير:', 'b' => 'يجوز للطرف الثاني طلب ترقية الباقة أو تغييرها في أي وقت، وتُفعَّل الترقية وتُحتسب رسومها وفق ما يتفق عليه الطرفان عند الطلب.'],
        ['h' => 'الإلغاء واسترداد الرسوم:', 'b' => 'يحق لأي من الطرفين عدم تجديد الاشتراك أو إنهاؤه بإشعار مكتوب، ويشمل ذلك الرسائل الإلكترونية، قبل نهاية الدورة الحالية، ويستمر الاشتراك حتى نهاية الدورة المسددة. ولا تُسترد رسوم الدورة الجارية بعد بدايتها ما لم يتفق الطرفان كتابةً على غير ذلك أو يقضِ القانون بخلافه.'],
        ['h' => 'البيانات وحمايتها:', 'b' => 'تبقى بيانات الطرف الثاني ملكًا له، ويلتزم الطرف الأول بحفظها وحمايتها وعدم مشاركتها مع أي طرف آخر إلا بموافقته أو بالقدر اللازم لتقديم الخدمة أو بموجب القانون.'],
        ['h' => 'حدود المسؤولية:', 'b' => 'يبذل الطرف الأول عناية معقولة لاستمرار الخدمة وأمانها، ولا يُسأل عن الأضرار غير المباشرة أو فوات الربح، ولا عن الانقطاع الناتج عن الإنترنت أو خدمات أطراف ثالثة كبوابات الدفع وواتساب. وفي جميع الأحوال لا تتجاوز مسؤوليته رسوم الدورة الجارية المسددة.'],
        ['h' => 'القانون والاختصاص:', 'b' => 'يخضع هذا العقد لقوانين سلطنة عُمان، وتختص محاكمها بالنظر في أي نزاع ينشأ عنه.'],
        ['h' => 'التوقيع الإلكتروني:', 'b' => 'يُعدّ توقيع الطرف الثاني على هذا العقد إلكترونيًا عبر الرابط المرسل إليه قبولًا منه لبنوده، ويحتفظ الطرف الأول بنسخة موقّعة منه.'],
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
            // عقدُ التجديد يشير إلى العقد الذي يجدّده
            $table->foreignId('renews_document_id')->nullable()->index()
                ->constrained('platform_documents')->nullOnDelete();

            // نسخةُ القالب ساعةَ الإصدار — لا تتبعه بعدها
            $table->string('service')->nullable();
            $table->string('title');
            $table->string('title_en')->nullable();
            $table->json('terms');
            $table->json('plan')->nullable();
            // مدّةُ الاشتراك كما اتُّفق عليها — بدايتُها ونهايتُها
            $table->date('term_starts_on')->nullable();
            $table->date('term_ends_on')->nullable();
            $table->decimal('amount', 12, 3)->nullable();
            $table->string('amount_label')->nullable();
            // الطرفُ الأوّل كما كان يومَ الإصدار — تغييرُ اسم أبعاد غدًا لا يغيّر عقدًا وُقِّع
            $table->json('provider')->nullable();

            // ما كتبه المدير مسبقًا، ثمّ ما أقرّه العميلُ عند التوقيع
            $table->json('client');
            // اسمُ العميل ونشاطُه وهاتفُه نصًّا — للبحث والقائمة (JSON لا يُبحث فيه بـLIKE)
            $table->string('client_label')->nullable()->index();
            // هاتفُ العميل دوليًّا بلا + — إليه يُفتح واتساب من جهاز المدير
            $table->string('client_phone', 20)->nullable();

            // آخرُ طريقةٍ شارك بها المديرُ الرابط — فعلُه هو، لا دليلُ وصولٍ ولا استلام
            $table->string('shared_via', 16)->nullable();
            $table->timestamp('shared_at')->nullable();

            $table->string('token', 32)->unique();
            $table->string('status', 16)->default('sent')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_agent', 512)->nullable();
            $table->string('signature_path')->nullable();
            // الإقرارُ الصريح كما قرأه العميل ووقتُه
            $table->text('consent_text')->nullable();
            $table->timestamp('agreed_at')->nullable();
            // بصمةُ ما وُقِّع عليه — تُطبع في الورقة، ويُعاد حسابُها للتحقّق
            $table->string('content_hash', 64)->nullable();
            // الورقةُ الموقَّعة محفوظةً كما نُزّلت أوّلَ مرّة، وبصمةُ ملفّها
            $table->string('pdf_path')->nullable();
            $table->string('pdf_hash', 64)->nullable();
            // مفتاحُ نسخة الموقِّع على جهازه — بصمتُه وحدها، والمفتاحُ في كوكي متصفّحه
            $table->string('copy_key_hash', 64)->nullable();
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
