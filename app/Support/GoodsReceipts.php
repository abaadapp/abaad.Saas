<?php

namespace App\Support;

use App\Models\BranchStock;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * استلامُ البضاعة — كتابةً ثمّ اعتمادًا.
 *
 * ═══ من يكتب ليس من يعتمد ═══
 *
 * كان الاستلامُ يزيد المخزونَ لحظةَ كتابته. وفي متجرٍ فيه موظّفان هذا بابٌ
 * مفتوح: كميّةٌ تُكتب أكبر ممّا وصل فتدخل الرفَّ ورقيًّا ولا توجد فيه،
 * ومتوسّطُ التكلفة يُرجَّح بها فيُفسد تسعيرَ كلّ بيعةٍ بعدها. ولا يكشفه
 * إلّا الجرد، بعد شهر.
 *
 * فصار الطريقان مفصولين:
 *
 *  • `record()` — تكتب ما وصل: ورقةٌ بحالة «بانتظار الاعتماد» وبنودُها،
 *    **ولا تمسّ رفًّا ولا أمرَ شراء**. ولو مسّت لما بقي للاعتماد معنى.
 *
 *  • `approve()` — تُحرّك المخزونَ ومتوسّطَ التكلفة و`received_quantity`
 *    وحالةَ الأمر. مرّةً واحدة، تحت قفل.
 *
 *  • `reject()` — تُوسم ولا تُمحى، ولا يتحرّك بها شيء.
 *
 * ═══ ولا ذمّةَ من هنا ═══
 *
 * الاستلامُ حركةُ بضاعة لا حدثٌ ماليّ: لا قيدَ له ولا ذمّةَ للمورّد. الذمّةُ
 * تنشأ باعتماد سند المورّد وحده — ولو نشأت هنا أيضًا لحُمّل المورّد مرّتين
 * عن شحنةٍ واحدة.
 */
final class GoodsReceipts
{
    public const PENDING = 'بانتظار الاعتماد';

    public const APPROVED = 'معتمد';

    public const REJECTED = 'مرفوض';

    /**
     * كتابةُ ما وصل — بلا أثرٍ على الرفّ.
     *
     * `$lines` خريطةُ «معرّف بند الأمر ⇽ الكمية».
     *
     * @param  array<int, int|float>  $lines
     * @param  array<string, mixed>  $data
     *
     * @throws ReceiveRefused
     */
    public static function record(PurchaseOrder $po, array $lines, array $data = [], ?User $by = null): GoodsReceiptNote
    {
        return DB::transaction(function () use ($po, $lines, $data, $by) {
            /*
             * والمتبقّي يُقرأ تحت قفل — ومن المعتمَد وحده.
             *
             * `remaining` مبنيٌّ على `received_quantity`، وهو لا يتحرّك إلّا
             * بالاعتماد. فورقتان معلّقتان على البند نفسه تقرآن المتبقّي
             * نفسَه — ولو اعتُمدتا معًا لتجاوز المستلَمُ المطلوب.
             *
             * فيُحسب المتبقّي هنا ناقصًا ما هو معلّقٌ في أوراقٍ أخرى.
             */
            $locked = PurchaseOrder::where('business_id', $po->business_id)
                ->lockForUpdate()->findOrFail($po->id);
            $items = $locked->items()->lockForUpdate()->get();

            if ($locked->status === 'مستلم') {
                throw new ReceiveRefused(__('أمر الشراء مستلم مسبقًا'), 'info');
            }

            $pending = self::pendingQuantities($locked->id);
            $noteItems = [];
            $over = [];

            foreach ($items as $item) {
                $qty = (int) ($lines[$item->id] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $room = (int) $item->remaining - (int) ($pending[$item->id] ?? 0);

                /*
                 * الزائد يُردّ ولا يُقصّ صامتًا: حصرُه في المتاح بلا قولٍ
                 * يترك من كتب الرقم يظنّ أنّ ما كتبه سُجّل.
                 */
                if ($qty > $room) {
                    $over[] = $item->name.' ('.__('المتاح').' '.max(0, $room).')';

                    continue;
                }

                $noteItems[] = [
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'quantity' => $qty,
                    'cost' => (float) $item->cost,
                ];
            }

            if ($over) {
                throw new ReceiveRefused(
                    __('الكمية المستلمة أكبر من المتاح: :items', ['items' => implode('، ', $over)]),
                );
            }

            if (! $noteItems) {
                throw new ReceiveRefused(__('لا كميةَ لاستلامها — اكتب ما وصلك من كل صنف.'));
            }

            $note = GoodsReceiptNote::create([
                'business_id' => $locked->business_id,
                'branch_id' => $locked->branch_id,
                'supplier_id' => $locked->supplier_id,
                'purchase_order_id' => $locked->id,
                'number' => GoodsReceiptNote::nextNumber($locked->business_id),
                'status' => self::PENDING,
                'received_at' => $data['received_at'] ?? now()->toDateString(),
                'receiver' => $data['receiver'] ?? $by?->name,
                'notes' => $data['notes'] ?? null,
                'attachment' => $data['attachment'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
                'submitted_by' => $by?->id,
            ]);

            foreach ($noteItems as $line) {
                $note->items()->create($line);
            }

            Activity::log('created', 'سجّل استلامًا بانتظار الاعتماد '.$note->number.' على '.$locked->number, [
                'subject_id' => $note->id, 'subject_type' => 'goods_receipt_note',
            ]);

            return $note->fresh('items');
        });
    }

    /**
     * ما هو معلّقٌ في أوراقٍ لم تُعتمد بعد — لكلّ بندٍ من الأمر.
     *
     * @return array<int, float>
     */
    public static function pendingQuantities(int $purchaseOrderId, ?int $exceptNoteId = null): array
    {
        return DB::table('goods_receipt_note_items as i')
            ->join('goods_receipt_notes as n', 'n.id', '=', 'i.goods_receipt_note_id')
            ->where('n.purchase_order_id', $purchaseOrderId)
            ->where('n.status', self::PENDING)
            ->when($exceptNoteId, fn ($q) => $q->where('n.id', '!=', $exceptNoteId))
            ->whereNotNull('i.purchase_order_item_id')
            ->groupBy('i.purchase_order_item_id')
            ->selectRaw('i.purchase_order_item_id as item_id, SUM(i.quantity) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->item_id => (float) $r->qty])
            ->all();
    }

    /**
     * الاعتماد — وهنا وحدَه يتحرّك الرفّ.
     *
     * ولا يتحرّك مرّتين: الورقةُ تُقفل وتُقرأ حالتُها من القاعدة داخل
     * المعاملة. فمديران يضغطان «اعتماد» في اللحظة نفسها — وهو ما يقع حين
     * يبطؤ الردّ — لا يُدخلان الشحنة مرّتين: الثاني يجدها معتمدةً فيخرج.
     *
     * @throws ReceiveRefused
     */
    public static function approve(GoodsReceiptNote $note, ?User $by = null): GoodsReceiptNote
    {
        return DB::transaction(function () use ($note, $by) {
            $locked = GoodsReceiptNote::where('business_id', $note->business_id)
                ->lockForUpdate()->findOrFail($note->id);

            if ($locked->status !== self::PENDING) {
                throw new ReceiveRefused(
                    $locked->status === self::APPROVED
                        ? __('هذا الاستلام معتمدٌ مسبقًا')
                        : __('هذا الاستلام مرفوض — لا يُعتمد'),
                    'info',
                );
            }

            $po = $locked->purchase_order_id
                ? PurchaseOrder::where('business_id', $locked->business_id)
                    ->lockForUpdate()->find($locked->purchase_order_id)
                : null;

            $orderItems = $po ? $po->items()->lockForUpdate()->get()->keyBy('id') : collect();

            foreach ($locked->items()->get() as $line) {
                $qty = (int) $line->quantity;

                if ($qty <= 0) {
                    continue;
                }

                /*
                 * والمتبقّي يُفحص ثانيةً عند الاعتماد لا عند الكتابة وحدها.
                 *
                 * ورقتان كُتبتا وكلٌّ منهما تسع المتبقّي: الأولى تُعتمد فيمتلئ
                 * الأمر، والثانية تبقى معلّقةً بكميّةٍ لم يعد لها موضع. فلو
                 * اعتُمدت لتجاوز المستلَمُ المطلوب ودخل الرفَّ ما لم يُطلب.
                 */
                $item = $line->purchase_order_item_id
                    ? ($orderItems[$line->purchase_order_item_id] ?? null)
                    : null;

                if ($item && $qty > (int) $item->remaining) {
                    throw new ReceiveRefused(__('الكمية المستلمة أكبر من المتبقّي: :items', [
                        'items' => $line->name.' ('.__('المتبقّي').' '.$item->remaining.')',
                    ]));
                }

                if ($line->product_id) {
                    self::shelve($locked->business_id, $locked->branch_id, (int) $line->product_id, $qty, (float) $line->cost, $by);
                }

                $item?->increment('received_quantity', $qty);
            }

            $locked->update([
                'status' => self::APPROVED,
                'approved_at' => now(),
                'approved_by' => $by?->id,
            ]);

            /*
             * وحالةُ الأمر تُقرأ من بنوده بعد تحديثها لا تُفترض: بنودٌ قُرئت
             * قبل الزيادة تُبقي أمرًا اكتمل استلامُه «مستلمًا جزئيًّا» أبدًا.
             */
            if ($po) {
                $outstanding = $po->items()->whereColumn('received_quantity', '<', 'quantity')->exists();
                $po->update([
                    'status' => $outstanding ? 'مستلم جزئيًا' : 'مستلم',
                    // تاريخُ الاكتمال لا تاريخُ أوّل دفعة: لكلّ دفعةٍ تاريخُها في إشعارها
                    'received_at' => $outstanding ? null : now(),
                ]);
            }

            Activity::log('updated', 'اعتمد الاستلام '.$locked->number, [
                'subject_id' => $locked->id, 'subject_type' => 'goods_receipt_note',
            ]);

            return $locked->fresh('items');
        });
    }

    /**
     * الرفض — بسببٍ مكتوب، ولا يتحرّك به شيء.
     *
     * والورقةُ تبقى: مستندُ استلامٍ رُفض يُقرأ ويُسأل عنه، ومحوُه يجعل
     * الشحنةَ التي وصلت ورُدّت كأنّها لم تصل.
     *
     * ولا تُعاد إلى «بانتظار الاعتماد»: ورقةٌ رُفضت تبقى على رفضها، ومن
     * أراد التصحيح يكتب ورقةً جديدة — فيبقى في السجلّ ما كُتب أوّلًا وما
     * صُحّح، لا آخرُ نسخةٍ وحدها.
     *
     * @throws ReceiveRefused
     */
    public static function reject(GoodsReceiptNote $note, string $reason, ?User $by = null): GoodsReceiptNote
    {
        return DB::transaction(function () use ($note, $reason, $by) {
            $locked = GoodsReceiptNote::where('business_id', $note->business_id)
                ->lockForUpdate()->findOrFail($note->id);

            if ($locked->status !== self::PENDING) {
                throw new ReceiveRefused(
                    $locked->status === self::APPROVED
                        ? __('هذا الاستلام معتمَد — دخلت بضاعتُه الرفّ، ولا يُرفض بعدها')
                        : __('هذا الاستلام مرفوضٌ مسبقًا'),
                    'info',
                );
            }

            $locked->update([
                'status' => self::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $by?->id,
                'rejection_reason' => $reason,
            ]);

            Activity::log('updated', 'رفض الاستلام '.$locked->number.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'goods_receipt_note',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * وضعُ الكمية على الرفّ — ومتوسّطٌ مرجّح للتكلفة.
     *
     * منقولةٌ كما كانت من `PurchaseOrderController::receive`: مئةُ قطعةٍ
     * اشتُريت بأربعة ثمّ عشرٌ بستّة تجعل المئة والعشر كلَّها بستّة لو كُتبت
     * التكلفةُ فوق القديمة — فتقفز قيمة المخزون بمئتين لم تُدفع، وينقص
     * الربح المحسوب على كلّ بيعةٍ قادمة.
     */
    private static function shelve(int $bid, ?int $branchId, int $productId, int $qty, float $cost, ?User $by): void
    {
        $product = Product::where('business_id', $bid)->find($productId);

        if (! $product) {
            return;
        }

        BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);
        $onHand = (int) $product->quantity;
        $product->increment('quantity', $qty);
        BranchStock::adjust($bid, $branchId, $product->id, $qty);

        // ورصيدٌ صفرٌ أو سالب بدايةٌ جديدة: لا معنى لمتوسّطٍ على لا شيء
        $newCost = $onHand > 0
            ? (($onHand * (float) $product->cost) + ($qty * $cost)) / ($onHand + $qty)
            : $cost;
        $product->update(['cost' => round($newCost, 3)]);

        InventoryMovement::create([
            'business_id' => $bid,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'type' => 'إضافة كمية',
            'quantity' => '+'.$qty,
            'employee_name' => $by?->name ?? auth()->user()?->name,
        ]);
    }
}
