<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كلُّ عمود ربطٍ (مفتاحٍ أجنبيّ) له فهرس — على PostgreSQL كما على غيره.
 *
 * ═══ العطب ═══
 *
 * `foreignId()->constrained()` يُنشئ القيدَ ولا يُنشئ فهرسًا في PostgreSQL
 * (MySQL يُنشئه وحده). فبقي ١٤٢ عمودَ ربطٍ بلا فهرس، منها
 * `order_items.order_id` و`orders.customer_id` و`transactions.journal_entry_id`.
 * وثمنُه في موضعين:
 *
 * - **القراءة:** أصنافُ طلبٍ واحد تُقرأ بمسح `order_items` كلِّه.
 * - **الحذف:** حذفُ طلبٍ يسأل كلَّ جدولٍ يشير إليه «أبقي صفٌّ يشير إليه؟»،
 *   وبلا فهرسٍ يمسح الجدولَ كلَّه — لكلّ صفٍّ محذوف. فحذفُ متجرٍ تجريبيّ
 *   بآلاف طلباته أخذ دقائق، وقسمُ PostgreSQL 0/3 في الفحص ٣٣ دقيقة.
 *
 * ═══ ما يفعله ═══
 *
 * فهرسٌ لكلّ عمودٍ في القائمة — القائمةُ مكتوبةٌ لا مستنتَجةٌ وقتَ التشغيل،
 * فيُعرف ما يُنشأ قبل أن يُنشأ. ولا يُنشأ فهرسٌ لعمودٍ له فهرسٌ يبدأ به،
 * ولا لجدولٍ أو عمودٍ غير موجود — فالتشغيلُ مرّتين آمن.
 *
 * وفي PostgreSQL يُبنى `CONCURRENTLY`: لا يُقفل الجدولَ عن الكتابة وهو يُبنى،
 * فلا يتوقّف البيعُ في الإنتاج لحظةَ النشر. ولذلك لا تُغلَّف الهجرةُ بمعاملة
 * (`withinTransaction = false`) — البناءُ المتزامن لا يجري داخل معاملة.
 *
 * ولا يمسّ بيانًا: فهارسُ وحدها، لا عمودَ ولا صفّ.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** الجدولُ ← أعمدةُ الربط فيه التي لم يكن لها فهرس */
    private const COLUMNS = [
        'accounts' => ['parent_id'],
        'addons' => ['inventory_product_id', 'product_id'],
        'bank_accounts' => ['account_id', 'business_id'],
        'bank_statement_lines' => ['bank_account_id', 'business_id'],
        'boutique_settlements' => ['boutique_id', 'business_id', 'expense_id'],
        'branch_google_places' => ['linked_by'],
        'branches' => ['business_id'],
        'business_archives' => ['generated_by'],
        'business_purges' => ['requested_by'],
        'businesses' => ['plan_id'],
        'categories' => ['business_id'],
        'coupon_redemptions' => ['coupon_id'],
        'crm_ai_feedback' => ['user_id'],
        'crm_leads' => ['assigned_by', 'converted_business_id', 'interested_plan_id'],
        'crm_messages' => ['sender_id'],
        'crm_notes' => ['user_id'],
        'crm_reads' => ['user_id'],
        'crm_stage_events' => ['user_id'],
        'crm_tasks' => ['completed_by', 'created_by'],
        'currencies' => ['business_id'],
        'custom_alerts' => ['created_by'],
        'customer_credit_notes' => ['created_by', 'order_id'],
        'customer_invoice_attachments' => ['customer_invoice_id', 'uploaded_by'],
        'customer_invoice_items' => ['order_item_id', 'product_id'],
        'customer_invoices' => ['branch_id', 'cancelled_by', 'created_by', 'customer_id'],
        'customer_payments' => ['bank_account_id', 'cancelled_by', 'created_by', 'customer_id'],
        'customers' => ['branch_id', 'business_id'],
        'delivery_note_items' => ['delivery_note_id', 'product_id'],
        'delivery_notes' => ['branch_id', 'customer_id', 'order_id'],
        'document_links' => ['business_id'],
        'expenses' => ['business_id', 'transaction_id'],
        'fixed_assets' => ['branch_id'],
        'goods_receipt_note_items' => ['goods_receipt_note_id', 'product_id', 'purchase_order_item_id'],
        'goods_receipt_notes' => ['approved_by', 'branch_id', 'purchase_order_id', 'rejected_by', 'submitted_by', 'supplier_id'],
        'google_business_accounts' => ['linked_by'],
        'inventory_movements' => ['branch_id', 'business_id'],
        'invoices' => ['business_id', 'plan_id'],
        'job_titles' => ['business_id'],
        'journal_entries' => ['branch_id', 'created_by', 'reverses_id'],
        'order_edits' => ['order_id'],
        'order_item_addons' => ['inventory_product_id', 'order_item_id'],
        'order_item_components' => ['order_item_id'],
        'order_items' => ['order_id', 'standalone_addon_id', 'variant_id'],
        'orders' => ['bank_account_id', 'customer_id', 'pos_device_id'],
        'password_recovery_challenges' => ['business_id'],
        'payroll_lines' => ['user_id'],
        'payroll_runs' => ['created_by'],
        'pos_devices' => ['activated_by', 'bank_account_id', 'branch_id'],
        'pos_peripherals' => ['pos_device_id'],
        'product_addons' => ['addon_id'],
        'product_images' => ['business_id'],
        'product_variants' => ['product_id'],
        'products' => ['boutique_id', 'business_id', 'category_id'],
        'purchase_order_items' => ['purchase_order_id'],
        'purchase_orders' => ['branch_id', 'supplier_id'],
        'recipe_items' => ['product_id', 'variant_id'],
        'reviews' => ['customer_id', 'product_id'],
        'season_reminders' => ['season_id'],
        'seasons' => ['created_by'],
        'shift_movements' => ['business_id'],
        'shifts' => ['pos_device_id'],
        'stock_adjustments' => ['branch_id', 'created_by'],
        'stock_transfers' => ['created_by', 'from_branch_id', 'to_branch_id'],
        'store_payment_intents' => ['order_id'],
        'store_sites' => ['draft_saved_by', 'published_version_id'],
        'subscriptions' => ['business_id', 'plan_id'],
        'supplier_invoices' => ['approved_by', 'override_by', 'purchase_order_id', 'rejected_by', 'submitted_by', 'supplier_id'],
        'suppliers' => ['business_id'],
        'support_conversations' => ['assigned_by', 'opened_by'],
        'support_messages' => ['sender_id'],
        'support_reads' => ['user_id'],
        'transactions' => ['bank_account_id', 'branch_id', 'journal_entry_id'],
        'website_sections' => ['business_id', 'page_id'],
        'website_versions' => ['created_by'],
        'websites' => ['created_by', 'published_version_id'],
        'whatsapp_connections' => ['connected_by_user_id'],
        'whatsapp_inbound_messages' => ['whatsapp_connection_id'],
        'whatsapp_message_packs' => ['invoice_id'],
        'whatsapp_messages' => ['customer_id', 'customer_invoice_id', 'whatsapp_connection_id'],
        'whatsapp_template_mappings' => ['business_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column) || $this->leads($table, $column)) {
                    continue;
                }

                $name = $table.'_'.$column.'_index';

                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS "'.$name.'" ON "'.$table.'" ("'.$column.'")');
                } else {
                    Schema::table($table, fn ($t) => $t->index($column, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');
            foreach ($columns as $column) {
                $name = $table.'_'.$column.'_index';
                if (in_array($name, $existing, true)) {
                    Schema::table($table, fn ($t) => $t->dropIndex($name));
                }
            }
        }
    }

    /** أفي الجدول فهرسٌ يبدأ بهذا العمود؟ — فيُغني عن آخر */
    private function leads(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
