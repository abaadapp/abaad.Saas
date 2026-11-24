<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بندُ فاتورة العميل يحفظ ما لصاحبه من البوتيكات — لقطةً ساعةَ الإصدار.
 *
 * ═══ ما كان ═══
 *
 * فاتورةُ العميل بلا طلب (اليدويّةُ وطلبُ واتساب الآليّ) لا مكانَ في بندها
 * لصاحب الصنف ولا لنسبته ولا لختم ورقته، وحسابُ البوتيك يُقرأ من بنود الطلبات
 * وحدها. فيُباع صنفُه فيها ولا يُستحقّ له شيء.
 *
 * ═══ ما يُضاف — وكلُّه إضافة ═══
 *
 * - `boutique_invoice_lines`: صفٌّ لكلّ بندٍ لبوتيك في فاتورةٍ صدرت **بعد** هذه
 *   الهجرة. لقطتُه لا تُعدَّل: البوتيكُ واسمُه، والصنف، والكمّيّة والسعر، والنسبة،
 *   والضريبة وطريقتُها، وأساسُ العمولة وقاعدتُه، والعمولة (حصّةُ المتجر) والصافي
 *   (حصّةُ البوتيك). وبجانبها ما رُدّ منه، ومتى اكتمل تحصيلُ فاتورته، وما حملته ورقتُه.
 * - `boutique_invoice_returns`: ما ردّه كلُّ إشعار دائنٍ من كلّ بند — صفٌّ لكلّ ردّ.
 * - `customer_credit_notes.items` و`basis`: بنودُ الإشعار وأساسُه. وهما العمودان اللذان
 *   يضيفهما طلبُ إشعارات الدائن والمدين (#195) بالشكل نفسِه — أيُّهما سبق أضافهما،
 *   ويتخطّاهما الآخر (`hasColumn` في الاثنين).
 * - `boutique_returns`: مرتجعُ ما بعد الورقة يقبل بندَ فاتورةٍ بلا طلب، وكمّيّةً بكسر.
 *
 * ═══ ولا يُمسّ قديم ═══
 *
 * لا صفَّ يُكتب لفاتورةٍ صدرت قبلها، ولا تُستنتج نسبةٌ ولا صاحب: ما لم يُحفظ يومَه
 * لا يُخترع. ولا قيدَ ولا مبلغَ ولا مخزونَ يتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boutique_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // لقطةٌ لا صلة — بوتيكٌ يُحذف لا يُفقد ما استُحقّ له معناه (كما في `order_items`)
            $table->unsignedBigInteger('boutique_id');
            $table->string('boutique_name');
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_invoice_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');

            // ── اللقطة: تُكتب مرّةً عند الإصدار ولا تُعدَّل
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 3);
            // سعرُ بطاقة الصنف ساعتَها — ليُرى إن كُتب البندُ بأقلَّ منه، لا ليُحسب به
            $table->decimal('list_price', 14, 3)->nullable();
            // الكمّيّةُ × السعر قبل أيّ خصم: منها عمولةُ البوتيك وصافيه
            $table->decimal('gross', 14, 3);
            // خصمُ البند ونقاطُه — على المتجر وحده، لا يُنقص ما للبوتيك
            $table->decimal('discount', 14, 3)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_amount', 14, 3)->default(0);
            // قاعدةُ الأساس وما أخرجته — `as_recorded`: المبلغُ كما سُجّل
            $table->string('rule', 24);
            $table->decimal('base', 14, 3);
            $table->decimal('rate', 5, 2);
            $table->decimal('commission', 14, 3);
            $table->decimal('net', 14, 3);
            $table->timestamp('issued_at');

            // ── ما رُدّ منه بإشعار، ومجموعُه بالفرق لا بالضرب
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->decimal('returned_gross', 14, 3)->default(0);
            $table->decimal('returned_commission', 14, 3)->default(0);
            $table->decimal('returned_net', 14, 3)->default(0);

            // ── ومتى صار قابلًا للتسوية: اكتمل تحصيلُ فاتورته — وفراغٌ حتّى ذلك
            $table->timestamp('collected_at')->nullable();
            // أُلغيت فاتورتُه قبل أن تحمله ورقة
            $table->timestamp('voided_at')->nullable();

            // ── وما حملته ورقتُه يومَ صدورها
            $table->unsignedBigInteger('boutique_settlement_id')->nullable();
            $table->decimal('settled_quantity', 12, 3)->nullable();
            $table->decimal('settled_gross', 14, 3)->nullable();
            $table->decimal('settled_commission', 14, 3)->nullable();
            $table->decimal('settled_net', 14, 3)->nullable();
            $table->timestamps();

            // بندٌ واحد لقطةٌ واحدة — نداءان للإصدار لا يكتبان مستحقّين
            $table->unique('customer_invoice_item_id');
            $table->index('customer_invoice_id');
            $table->index(['business_id', 'boutique_id', 'collected_at']);
            $table->index('boutique_settlement_id');
        });

        Schema::create('boutique_invoice_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boutique_invoice_line_id')->constrained()->cascadeOnDelete();
            // الإشعارُ الذي ردّه — وفراغٌ لما أُلغيت فاتورتُه بعد ورقته
            $table->foreignId('customer_credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('gross', 14, 3);
            $table->decimal('commission', 14, 3);
            $table->decimal('net', 14, 3);
            // أوقع قبل الورقة (نقص به المستحقّ) أم بعدها (`boutique_returns`)
            $table->boolean('after_sheet')->default(false);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            // وإشعارٌ واحد لا يردّ البندَ نفسَه مرّتين
            $table->unique(['customer_credit_note_id', 'boutique_invoice_line_id'], 'bir_note_line_unique');
            $table->index('boutique_invoice_line_id');
            $table->index('business_id');
        });

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_credit_notes', 'items')) {
                $table->json('items')->nullable();
            }
            if (! Schema::hasColumn('customer_credit_notes', 'basis')) {
                $table->string('basis', 20)->nullable();
            }
        });

        Schema::table('boutique_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->decimal('quantity', 12, 3)->change();
            $table->unsignedBigInteger('boutique_invoice_line_id')->nullable()->after('order_item_id');
            $table->index('boutique_invoice_line_id');
        });
    }

    /**
     * ═══ ولا يُتراجع عنها وفيها ما يُفقد ═══
     *
     * اللقطةُ مستحقٌّ لبوتيكٍ على المتجر: حذفُها يُسقط دَينًا. فيُردّ بأرقامه،
     * والرجوعُ حينها رجوعُ كودٍ وحده تبقى معه الجداول: الكودُ القديم لا يقرؤها.
     */
    public function down(): void
    {
        $held = array_filter([
            'boutique_invoice_lines' => Schema::hasTable('boutique_invoice_lines') ? DB::table('boutique_invoice_lines')->count() : 0,
            'boutique_invoice_returns' => Schema::hasTable('boutique_invoice_returns') ? DB::table('boutique_invoice_returns')->count() : 0,
            'boutique_returns' => Schema::hasColumn('boutique_returns', 'boutique_invoice_line_id')
                ? DB::table('boutique_returns')->whereNotNull('boutique_invoice_line_id')->count() : 0,
            'customer_credit_notes.items' => Schema::hasColumn('customer_credit_notes', 'items')
                ? DB::table('customer_credit_notes')->whereNotNull('items')->count() : 0,
        ]);

        if ($held !== []) {
            throw new RuntimeException(
                'لا يُتراجع عن هجرة مستحقّ فواتير العملاء وفيها ما يُفقد — '
                .implode('، ', array_map(fn ($t, $n) => $t.': '.$n, array_keys($held), $held))
                .'. ارجع بالكود وحده (scripts/rollback-code.sh مع ALLOW_MIGRATIONS) فتبقى الجداول وبياناتها.'
            );
        }

        Schema::dropIfExists('boutique_invoice_returns');
        Schema::dropIfExists('boutique_invoice_lines');

        Schema::table('boutique_returns', function (Blueprint $table) {
            $table->dropIndex(['boutique_invoice_line_id']);
            $table->dropColumn('boutique_invoice_line_id');
        });

        Schema::table('boutique_returns', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->change();
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });

        // وعمودا الإشعار يبقيان إن كان طلبُ التصحيح (#195) قد أضاف ما فوقهما
        if (! Schema::hasColumn('customer_credit_notes', 'revision_id')) {
            Schema::table('customer_credit_notes', function (Blueprint $table) {
                $table->dropColumn(['items', 'basis']);
            });
        }
    }
};
