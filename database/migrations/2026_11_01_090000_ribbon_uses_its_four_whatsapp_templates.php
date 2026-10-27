<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RIBBON يرسل أربع رسائل طلب فقط، بالأسماء التي أنشأها صاحبه في Meta.
 *
 * الوصلة كانت قائمة قبل هذا التخصيص، لذلك صفوف abaad_* القديمة موجودة فعلًا.
 * تعديل الكود وحده لا يغيّرها؛ هذه الهجرة تجعل بيانات الإنتاج تطابق العقد الجديد
 * فور النشر، وتطفئ أحداث التسليم والفواتير لهذا المتجر تحديدًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_template_mappings') || ! Schema::hasTable('businesses')) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) config('whatsapp.ribbon_businesses', []))));

        $templates = [
            'order_received' => 'ribbon_order_received',
            'order_confirmed' => 'ribbon_order_confirmed',
            'order_ready' => 'ribbon_order_ready',
            'order_out_for_delivery' => 'ribbon_order_out_for_delivery',
        ];

        foreach ($ids as $businessId) {
            if (! DB::table('businesses')->where('id', $businessId)->exists()) {
                continue;
            }

            DB::table('whatsapp_template_mappings')
                ->where('scope_type', 'business')
                ->where('business_id', $businessId)
                ->whereNotIn('event_type', array_keys($templates))
                ->delete();

            foreach ($templates as $event => $name) {
                $where = [
                    'scope_type' => 'business',
                    'business_id' => $businessId,
                    'event_type' => $event,
                ];

                $values = [
                    'template_name' => $name,
                    'language_code' => 'ar',
                    'enabled' => true,
                    'variable_mapping' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ];

                foreach (['meta_status', 'meta_synced_at', 'approved_languages', 'body_by_language'] as $column) {
                    if (Schema::hasColumn('whatsapp_template_mappings', $column)) {
                        $values[$column] = null;
                    }
                }

                if (DB::table('whatsapp_template_mappings')->where($where)->exists()) {
                    DB::table('whatsapp_template_mappings')->where($where)->update($values);
                } else {
                    DB::table('whatsapp_template_mappings')->insert($where + $values + ['created_at' => now()]);
                }
            }

            if (Schema::hasTable('settings')) {
                foreach ([
                    'wa_on_received' => '1',
                    'wa_on_order' => '1',
                    'wa_on_ready' => '1',
                    'wa_on_out_for_delivery' => '1',
                    'wa_on_delivered' => '0',
                    'wa_on_invoice_due_soon' => '0',
                    'wa_on_invoice_overdue' => '0',
                    'wa_on_invoice_paid' => '0',
                ] as $key => $value) {
                    DB::table('settings')->updateOrInsert(
                        ['business_id' => $businessId, 'key' => $key],
                        ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('whatsapp_template_mappings')) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) config('whatsapp.ribbon_businesses', []))));

        $defaults = [
            'order_confirmed' => 'abaad_order_confirmed',
            'order_ready' => 'abaad_order_ready',
            'order_out_for_delivery' => 'abaad_order_out_for_delivery',
            'order_delivered' => 'abaad_order_delivered',
            'invoice_due_soon' => 'abaad_invoice_due_soon',
            'invoice_overdue' => 'abaad_invoice_overdue',
        ];

        foreach ($ids as $businessId) {
            DB::table('whatsapp_template_mappings')
                ->where('scope_type', 'business')
                ->where('business_id', $businessId)
                ->where('event_type', 'order_received')
                ->delete();

            foreach ($defaults as $event => $name) {
                $where = [
                    'scope_type' => 'business',
                    'business_id' => $businessId,
                    'event_type' => $event,
                ];
                $values = [
                    'template_name' => $name,
                    'language_code' => 'ar',
                    'enabled' => true,
                    'variable_mapping' => json_encode(['1' => 'business_name', '2' => 'order_number'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ];

                if (DB::table('whatsapp_template_mappings')->where($where)->exists()) {
                    DB::table('whatsapp_template_mappings')->where($where)->update($values);
                } else {
                    DB::table('whatsapp_template_mappings')->insert($where + $values + ['created_at' => now()]);
                }
            }
        }
    }
};
