<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أساس المحاسبة المزدوجة: شجرة الحسابات، والقيود، وسطورها.
 *
 * القيد لا يُحفظ إلا متوازنًا — والحارس في النموذج لا هنا، لأن القاعدة لا
 * تعرف مجموع سطورٍ لم تُكتب بعد. لكنّ البنية تجعل الاختلال مرئيًّا: كل سطر
 * مدينٌ أو دائن، لا كلاهما ولا واحدَ منهما.
 *
 * والحسابات شجرة: `parent_id` يبني المستويات، و`code` يرتّبها كما تُقرأ في
 * ميزان المراجعة (1 أصول، 2 خصوم، 3 حقوق ملكية، 4 إيرادات، 5 مصروفات).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            // أصل | خصم | حقوق ملكية | إيراد | مصروف
            $table->string('type', 20);
            /*
             * الطبيعة تُحسم هنا لا تُشتقّ عند كل قراءة: حسابٌ طبيعته مدينة
             * يزيد بالمدين وينقص بالدائن، والعكس. والاشتقاق من النوع يخطئ في
             * الحسابات المقابلة (مجمّع الإهلاك أصلٌ طبيعته دائنة).
             */
            $table->string('normal_side', 6)->default('debit'); // debit | credit
            // حسابٌ عليه حركة لا يُحذف ولا يُعطَّل؛ يُغلق فلا يقبل قيدًا جديدًا
            $table->boolean('active')->default(true);
            // النظام يرحّل إليه تلقائيًّا (مبيعات، مشتريات، صندوق…) فلا يُحذف
            $table->string('system_key', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'type']);
            $table->index(['business_id', 'system_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('number', 30);
            $table->date('entry_date');
            $table->string('description');
            /*
             * مصدر القيد: يدويّ أو مُرحَّل من مستند.
             *
             * القيد المُرحَّل لا يُعدَّل من شاشة القيود — يُعدَّل مستنده
             * فيُعاد ترحيله. وبلا هذا يصير الدفتر يقول غير ما تقوله الفاتورة.
             */
            $table->string('source', 30)->default('يدوي');
            $table->nullableMorphs('sourceable');
            $table->boolean('posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 14, 3)->default(0);
            $table->decimal('credit', 14, 3)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
