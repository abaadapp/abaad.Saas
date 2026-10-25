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
 *
 * والرجوعُ عنه بالكود وحده (Revert PR): الجدولُ والعمودُ يبقيان، والكودُ القديم
 * لا يقرؤهما ولا يتعثّر بهما. و`down()` يرفض ما دام في السجلّ ما لا يُعاد بناؤه
 * من القوالب نفسها — إصدارٌ سابق، أو حفظٌ من الشاشة، أو قالبُ بذرةٍ أُعيدت
 * تسميتُه فلا يعرفه بعد المفتاح إلّا اسمُه القديم.
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
            $table->foreignId('saved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('saved_by_name', 120)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('published_by_name', 120)->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'version']);
        });

        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->string('seed_key', 64)->nullable()->unique();
        });

        // قوالبُ المالك الخمسة بأسمائها كما زُرعت، وما أُعيدت تسميتُه بعنوانه وخدمته
        DocumentForms::keySeeds();

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
        $versions = DB::table('platform_document_template_versions')->get()->groupBy('template_id');
        $history = PlatformDocumentTemplate::orderBy('id')->get()->filter(function (PlatformDocumentTemplate $template) use ($versions) {
            $own = $versions->get($template->id, collect());
            $row = $own->first();

            return $own->count() > 1 || ($row && ((int) $row->version !== (int) $template->version
                || $row->content_hash !== $template->contentHash() || $row->saved_by !== null || $row->saved_by_name !== null));
        })->pluck('id');
        $renamed = DB::table('platform_document_templates')->whereIn('seed_key', DocumentForms::SEEDED)->get(['id', 'name', 'seed_key'])
            ->filter(fn ($t) => $t->name !== DocumentForms::seed($t->seed_key)['name'])->pluck('id');

        if ($history->isNotEmpty() || $renamed->isNotEmpty()) {
            throw new RuntimeException('لا يُتراجع: في سجلّ إصدارات القوالب تاريخٌ لا نسخة له في القوالب نفسها'
                .($history->isNotEmpty() ? ' (إصداراتُ القوالب: '.$history->implode(', ').')' : '')
                .($renamed->isNotEmpty() ? ' (قوالبُ بذرةٍ أُعيدت تسميتُها: '.$renamed->implode(', ').')' : '')
                .'. ارجع بالكود وحده (Revert PR) واترك الجدولَ والعمود — الكودُ القديم يعمل عليهما.');
        }

        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->dropUnique(['seed_key']);
            $table->dropColumn('seed_key');
        });
        Schema::dropIfExists('platform_document_template_versions');
    }
};
