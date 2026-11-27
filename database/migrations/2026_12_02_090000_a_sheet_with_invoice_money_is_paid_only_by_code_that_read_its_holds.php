<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ورقةٌ فيها بنودُ فواتير عملاء لا تُوسم مسدَّدةً إلّا بكودٍ قرأ ما عُلّق منها — تقوله قاعدةُ البيانات.
 *
 * ═══ ما كان ═══
 *
 * الموقوفُ سدادُه في `boutique_sheet_holds` يقرؤه كودُ هذا الإصدار وحده. فإن رُجع إلى كودٍ
 * قبله (`scripts/rollback-code.sh`) بقيت الجداول ولم يقرأها أحد: زرُّ السداد القديم يُخرج
 * صافيَ الورقة كلَّه، وفيه ما أُلغي تحصيلُه. والرجوعُ بالكود وحده لا يمنع شيئًا.
 *
 * ═══ ما يُضاف ═══
 *
 * `boutique_sheet_payments`: صفٌّ يكتبه من يسدّد ورقةً بعد أن يقرأ ما عُلّق منها — ما خرج
 * وما أُوقف — في معاملة السداد نفسِها. وقادحٌ على `expenses`: مصروفُ ورقةٍ فيها بندُ فاتورةٍ
 * لا تصير حالتُه «مدفوع» وليس له هذا الصفّ. فالكودُ القديم لا يكتبه، فتُردّ معاملتُه كلُّها:
 * لا حالةَ ولا حركةَ ولا قيد. وما لا بندَ فاتورةٍ فيه — كلُّ مصروفٍ عاديّ، وورقةُ طلباتٍ وحدها
 * — لا يمسّه القادح، ويُسدَّد بالقديم كما كان.
 */
return new class extends Migration
{
    private const REFUSAL = 'boutique_sheet_holds_unread: ورقة بوتيك فيها فواتير عملاء لا تُسدَّد بكود لا يقرأ ما عُلّق منها';

    public function up(): void
    {
        Schema::create('boutique_sheet_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('boutique_settlement_id');
            $table->unsignedBigInteger('expense_id')->unique();
            // ما خرج للبوتيك مع الورقة، وما أُوقف منها
            $table->decimal('amount', 14, 3);
            $table->decimal('held', 14, 3)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('boutique_settlement_id');
        });

        $carries = 'EXISTS (SELECT 1 FROM boutique_settlements s JOIN boutique_invoice_lines l ON l.boutique_settlement_id = s.id WHERE s.expense_id = NEW.id)'
            .' AND NOT EXISTS (SELECT 1 FROM boutique_sheet_payments p WHERE p.expense_id = NEW.id)';

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE OR REPLACE FUNCTION boutique_sheet_is_paid_by_code_that_read_its_holds() RETURNS trigger LANGUAGE plpgsql AS $body$'
                ." BEGIN IF {$carries} THEN RAISE EXCEPTION '".self::REFUSAL."' USING ERRCODE = 'check_violation'; END IF; RETURN NEW; END \$body\$");
            DB::unprepared('CREATE TRIGGER boutique_sheet_holds_are_read BEFORE UPDATE OF status ON expenses FOR EACH ROW'
                ." WHEN (NEW.status = 'مدفوع' AND OLD.status IS DISTINCT FROM NEW.status)"
                .' EXECUTE FUNCTION boutique_sheet_is_paid_by_code_that_read_its_holds()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER boutique_sheet_holds_are_read BEFORE UPDATE OF status ON expenses FOR EACH ROW'
                ." WHEN NEW.status = 'مدفوع' AND OLD.status IS NOT NEW.status AND {$carries}"
                ." BEGIN SELECT RAISE(ABORT, '".self::REFUSAL."'); END");
        }
    }

    /** وفيها ورقةٌ سُدّدت أو تحمل بندَ فاتورة: رفعُ القادح يُطلق سدادَها بكودٍ لا يقرأ — فيُردّ */
    public function down(): void
    {
        $held = (Schema::hasTable('boutique_sheet_payments') ? DB::table('boutique_sheet_payments')->count() : 0)
            + (Schema::hasTable('boutique_invoice_lines') ? DB::table('boutique_invoice_lines')->whereNotNull('boutique_settlement_id')->count() : 0);

        if ($held > 0) {
            throw new RuntimeException('لا تُرفع حماية سداد أوراق البوتيكات وفيها أوراقٌ تحمل فواتير عملاء ('.$held.'). ارجع بالكود وحده فتبقى الحماية.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS boutique_sheet_holds_are_read ON expenses');
            DB::unprepared('DROP FUNCTION IF EXISTS boutique_sheet_is_paid_by_code_that_read_its_holds()');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS boutique_sheet_holds_are_read');
        }

        Schema::dropIfExists('boutique_sheet_payments');
    }
};
