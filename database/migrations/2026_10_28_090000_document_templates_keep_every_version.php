<?php

use App\Models\PlatformDocumentTemplate;
use App\Support\DocumentForms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * قوالبُ المستندات تحفظ كلَّ إصدارٍ منها — بنصّه وبصمته ومن حفظه ومن نشره.
 *
 * كان القالبُ يحمل إصدارَه الحاليّ وحده: يُعدَّل فيُنسى ما قبله إلّا في مستندٍ صدر
 * منه. والآن يُحرَّر القالبُ كلُّه من الشاشة، فيُحفظ كلُّ إصدارٍ صفًّا لا يتغيّر
 * (`platform_document_template_versions`)، ويُسجَّل عليه من نشره ومتى.
 *
 * و`seed_key` اسمُ ملفّ البذرة لقوالب المالك الخمسة: كانت البذرةُ تعرف قالبها
 * باسمه، فقالبٌ أعاد المديرُ تسميتَه يُزرع ثانيةً بجانبه. وبالمفتاح لا يُزرع
 * ولا يُستبدل ما عدّله المدير.
 *
 * ولا يمسّ مستندًا صدر ولا بصمةً ولا ملفًّا: يقرأ القوالبَ ويكتب سجلَّها وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('platform_document_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('content');
            $table->string('content_hash', 64);
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('saved_by_name', 120)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_by_name', 120)->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'version']);
        });

        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->string('seed_key', 64)->nullable()->unique();
        });

        // قوالبُ المالك الخمسة بأسمائها كما زُرعت — ما أُعيدت تسميتُه قبل اليوم لا يُخمَّن
        foreach (DocumentForms::SEEDED as $slug) {
            DB::table('platform_document_templates')->whereNull('seed_key')
                ->where('name', DocumentForms::seed($slug)['name'])->update(['seed_key' => $slug]);
        }

        // الإصدارُ الحاليّ لكلّ قالب أوّلُ صفٍّ في سجلّه — منشورًا إن كان نصُّه هو المعتمد
        foreach (PlatformDocumentTemplate::orderBy('id')->get() as $template) {
            $published = $template->approved();
            DB::table('platform_document_template_versions')->insert([
                'template_id' => $template->id,
                'version' => $template->version,
                'content' => json_encode($template->content(), JSON_UNESCAPED_UNICODE),
                'content_hash' => $template->contentHash(),
                'saved_by' => null,
                'saved_by_name' => null,
                'published_at' => $published ? ($template->approved_at ?? $template->created_at) : null,
                'published_by' => $published ? $template->approved_by : null,
                'published_by_name' => $published ? $template->approved_by_name : null,
                'created_at' => $template->updated_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->dropUnique(['seed_key']);
            $table->dropColumn('seed_key');
        });
        Schema::dropIfExists('platform_document_template_versions');
    }
};
