<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سياسةُ البوتيك المقترحة — مكتوبةٌ ومطفأة.
 *
 * ═══ ما تقوله (لما يُباع بعد يومها وحده) ═══
 *
 *   ١. حصّةُ البوتيك من السعر **المتّفق عليه** ساعةَ البيع: الخصمُ والكوبونُ وتخفيضُ السعر على
 *      المتجر، وما زاد عليه للمتجر. ويُحفظ السعرُ المتّفق عليه والنسبةُ والحصّةُ على البند.
 *   ٢. والحصّةُ لا تُسوّى قبل اكتمال تحصيل بيعها — في كلّ باب: الصندوق آجلًا ونقدًا، والموقع،
 *      وفواتير العملاء وواتساب. وفاتورةُ الشهر المدفوعةُ جزئيًّا تنتظر اكتمالها.
 *   ٣. وتحصيلٌ أُلغي بعد الورقة يوقف سدادَ ما تأثّر وحده، كما في #271.
 *
 * ═══ ولا تسري على أحد ═══
 *
 * `businesses.boutique_policy_from` فارغٌ للجميع، ولا بابَ في النظام يكتبه. وما بيع قبل يومه
 * بقاعدته كما كان.
 *
 * ═══ والكودُ الأقدم ═══
 *
 * لا يقرأ هذه الأعمدة: فلو رُجع إليه لحمل في ورقته بندًا لم يُقبض ولسدّد قطعًا معلّقة. فقوادحُ
 * ثلاثة تمنعه: بندُ سياسةٍ لا يحمله إلّا ورقةٌ تقرأ التحصيل (`reads_collection`)، وكذلك القطعة،
 * وقطعُ ورقةٍ فيها قطعةُ سياسةٍ لا تُسدَّد بلا قراءة المعلّق (`components_read_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', fn (Blueprint $table) => $table->date('boutique_policy_from')->nullable());

        Schema::create('boutique_price_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('boutique_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('price', 14, 3);
            $table->date('starts_on');
            // عرضٌ مؤقّتٌ باتّفاق: له نهاية
            $table->date('ends_on')->nullable();
            $table->string('reference', 255);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'product_id', 'starts_on']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // السعرُ المتّفق عليه ساعةَ البيع — وفراغٌ لما بيع بالقاعدة القائمة
            $table->decimal('boutique_agreed_price', 14, 3)->nullable();
            // بيع بالسياسة: حصّتُه تنتظر تحصيلَه — وفراغٌ لما بيع قبلها
            $table->string('boutique_policy', 16)->nullable();
        });

        Schema::table('boutique_invoice_lines', fn (Blueprint $table) => $table->decimal('agreed_price', 14, 3)->nullable());
        Schema::table('boutique_component_dues', fn (Blueprint $table) => $table->boolean('waits')->default(false));

        Schema::table('boutique_settlements', function (Blueprint $table) {
            // ما بين إجماليّ البنود وما حُسبت منه الحصّة: خصمٌ تحمّله المتجر (سالب) أو زيادةٌ له
            $table->decimal('adjustment', 14, 3)->default(0);
            // كتبها كودٌ يقرأ التحصيل — بها وحدها يُحمل بندُ سياسة
            $table->boolean('reads_collection')->default(false);
            // وسدادُ قطعها قرأ ما عُلّق منها
            $table->timestamp('components_read_at')->nullable();
        });

        Schema::table('boutique_sheet_holds', function (Blueprint $table) {
            // والمعلّقُ بندُ طلبٍ أو قطعةٌ فيه، لا بندُ فاتورةٍ وحده
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedBigInteger('boutique_component_due_id')->nullable();
            $table->index('order_item_id');
        });
        Schema::table('boutique_sheet_holds', function (Blueprint $table) {
            $table->dropForeign(['boutique_invoice_line_id']);
        });
        Schema::table('boutique_sheet_holds', function (Blueprint $table) {
            $table->unsignedBigInteger('boutique_invoice_line_id')->nullable()->change();
            $table->unsignedBigInteger('customer_invoice_id')->nullable()->change();
        });

        $this->triggers();
    }

    private const POLICY = 'boutique_policy_unread: بند بوتيك بيع بالسياسة لا تحمله ورقة بكود لا يقرأ التحصيل';

    private const PIECES = 'boutique_pieces_unread: قطع ورقة بوتيك فيها ما ينتظر التحصيل لا تُسدَّد بكود لا يقرأ ما عُلّق منها';

    private const SHEET = 'boutique_sheet_holds_unread: ورقة بوتيك فيها فواتير عملاء لا تُسدَّد بكود لا يقرأ ما عُلّق منها';

    /** وقادحُ سداد الورقة (#271) يشمل ورقةً فيها بندُ سياسة — فيه ما ينتظر التحصيل كبند الفاتورة */
    private function sheetGuard(bool $policy): void
    {
        $carries = 'EXISTS (SELECT 1 FROM boutique_settlements s JOIN boutique_invoice_lines l ON l.boutique_settlement_id = s.id WHERE s.expense_id = NEW.id)'
            .($policy ? ' OR EXISTS (SELECT 1 FROM boutique_settlements s JOIN order_items oi ON oi.boutique_settlement_id = s.id WHERE s.expense_id = NEW.id AND oi.boutique_policy IS NOT NULL)' : '');
        $carries = "({$carries}) AND NOT EXISTS (SELECT 1 FROM boutique_sheet_payments p WHERE p.expense_id = NEW.id)";

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE OR REPLACE FUNCTION boutique_sheet_is_paid_by_code_that_read_its_holds() RETURNS trigger LANGUAGE plpgsql AS $body$'
                ." BEGIN IF {$carries} THEN RAISE EXCEPTION '".self::SHEET."' USING ERRCODE = 'check_violation'; END IF; RETURN NEW; END \$body\$");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS boutique_sheet_holds_are_read');
            DB::unprepared('CREATE TRIGGER boutique_sheet_holds_are_read BEFORE UPDATE OF status ON expenses FOR EACH ROW'
                ." WHEN NEW.status = 'مدفوع' AND OLD.status IS NOT NEW.status AND {$carries}"
                ." BEGIN SELECT RAISE(ABORT, '".self::SHEET."'); END");
        }
    }

    private function triggers(): void
    {
        $this->sheetGuard(true);

        $reads = 'EXISTS (SELECT 1 FROM boutique_settlements s WHERE s.id = NEW.boutique_settlement_id AND s.reads_collection)';
        $waits = 'EXISTS (SELECT 1 FROM boutique_component_dues d WHERE d.boutique_settlement_id = NEW.id AND d.waits)';

        if (DB::getDriverName() === 'pgsql') {
            // بندٌ يُختم بورقةٍ لا تقرأ التحصيل يُتخطّى، فتعدّه ورقةُ الكود الأقدم ناقصًا وتُردّ بسببها
            DB::unprepared('CREATE OR REPLACE FUNCTION boutique_policy_line_is_carried_by_code_that_reads_collection() RETURNS trigger LANGUAGE plpgsql AS $body$'
                ." BEGIN IF NEW.boutique_settlement_id IS NOT NULL AND NOT {$reads} THEN RETURN NULL; END IF; RETURN NEW; END \$body\$");
            DB::unprepared('CREATE TRIGGER boutique_policy_lines_read_collection BEFORE UPDATE OF boutique_settlement_id ON order_items FOR EACH ROW'
                .' WHEN (NEW.boutique_policy IS NOT NULL AND NEW.boutique_settlement_id IS DISTINCT FROM OLD.boutique_settlement_id)'
                .' EXECUTE FUNCTION boutique_policy_line_is_carried_by_code_that_reads_collection()');
            DB::unprepared('CREATE TRIGGER boutique_policy_pieces_read_collection BEFORE UPDATE OF boutique_settlement_id ON boutique_component_dues FOR EACH ROW'
                .' WHEN (NEW.waits AND NEW.boutique_settlement_id IS DISTINCT FROM OLD.boutique_settlement_id)'
                .' EXECUTE FUNCTION boutique_policy_line_is_carried_by_code_that_reads_collection()');
            DB::unprepared('CREATE OR REPLACE FUNCTION boutique_sheet_pieces_are_paid_by_code_that_read_their_holds() RETURNS trigger LANGUAGE plpgsql AS $body$'
                ." BEGIN IF NEW.components_read_at IS NULL AND {$waits} THEN RAISE EXCEPTION '".self::PIECES."' USING ERRCODE = 'check_violation'; END IF; RETURN NEW; END \$body\$");
            DB::unprepared('CREATE TRIGGER boutique_sheet_pieces_holds_are_read BEFORE UPDATE OF components_paid_at ON boutique_settlements FOR EACH ROW'
                .' WHEN (NEW.components_paid_at IS NOT NULL AND OLD.components_paid_at IS NULL)'
                .' EXECUTE FUNCTION boutique_sheet_pieces_are_paid_by_code_that_read_their_holds()');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['order_items' => ['boutique_policy_lines_read_collection', 'NEW.boutique_policy IS NOT NULL'], 'boutique_component_dues' => ['boutique_policy_pieces_read_collection', 'NEW.waits']] as $table => [$name, $when]) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE OF boutique_settlement_id ON {$table} FOR EACH ROW"
                    ." WHEN {$when} AND NEW.boutique_settlement_id IS NOT NULL AND NEW.boutique_settlement_id IS NOT OLD.boutique_settlement_id AND NOT {$reads}"
                    .' BEGIN SELECT RAISE(IGNORE); END');
            }
            DB::unprepared('CREATE TRIGGER boutique_sheet_pieces_holds_are_read BEFORE UPDATE OF components_paid_at ON boutique_settlements FOR EACH ROW'
                ." WHEN NEW.components_paid_at IS NOT NULL AND OLD.components_paid_at IS NULL AND NEW.components_read_at IS NULL AND {$waits}"
                ." BEGIN SELECT RAISE(ABORT, '".self::PIECES."'); END");
        }
    }

    /** ولا يُتراجع عنها وقد بيع بها شيء — والقوادحُ تبقى ما بقي ما تحرسه */
    public function down(): void
    {
        $held = array_filter([
            'businesses.boutique_policy_from' => DB::table('businesses')->whereNotNull('boutique_policy_from')->count(),
            'boutique_price_agreements' => Schema::hasTable('boutique_price_agreements') ? DB::table('boutique_price_agreements')->count() : 0,
            'order_items.boutique_policy' => DB::table('order_items')->whereNotNull('boutique_policy')->count(),
            'boutique_component_dues.waits' => DB::table('boutique_component_dues')->where('waits', true)->count(),
            'boutique_sheet_holds (orders)' => DB::table('boutique_sheet_holds')->whereNull('boutique_invoice_line_id')->count(),
        ]);

        if ($held !== []) {
            throw new RuntimeException(
                'لا يُتراجع عن هجرة سياسة البوتيك وقد بيع بها — '
                .implode('، ', array_map(fn ($t, $n) => $t.': '.$n, array_keys($held), $held))
                .'. ارجع بالكود وحده (scripts/rollback-code.sh مع ALLOW_MIGRATIONS) فتبقى الأعمدة وقوادحُها.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS boutique_policy_lines_read_collection ON order_items');
            DB::unprepared('DROP TRIGGER IF EXISTS boutique_policy_pieces_read_collection ON boutique_component_dues');
            DB::unprepared('DROP TRIGGER IF EXISTS boutique_sheet_pieces_holds_are_read ON boutique_settlements');
            DB::unprepared('DROP FUNCTION IF EXISTS boutique_policy_line_is_carried_by_code_that_reads_collection()');
            DB::unprepared('DROP FUNCTION IF EXISTS boutique_sheet_pieces_are_paid_by_code_that_read_their_holds()');
        } else {
            foreach (['boutique_policy_lines_read_collection', 'boutique_policy_pieces_read_collection', 'boutique_sheet_pieces_holds_are_read'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            }
        }

        $this->sheetGuard(false);

        Schema::table('boutique_sheet_holds', function (Blueprint $table) {
            $table->dropIndex(['order_item_id']);
            $table->dropColumn(['order_item_id', 'boutique_component_due_id']);
        });
        Schema::table('boutique_settlements', fn (Blueprint $table) => $table->dropColumn(['adjustment', 'reads_collection', 'components_read_at']));
        Schema::table('boutique_component_dues', fn (Blueprint $table) => $table->dropColumn('waits'));
        Schema::table('boutique_invoice_lines', fn (Blueprint $table) => $table->dropColumn('agreed_price'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['boutique_agreed_price', 'boutique_policy']));
        Schema::dropIfExists('boutique_price_agreements');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('boutique_policy_from'));
    }
};
