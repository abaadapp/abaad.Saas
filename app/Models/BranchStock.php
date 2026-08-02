<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchStock extends Model
{
    protected $guarded = [];

    /**
     * يطبّق تغييرًا على رصيد فرع لمنتج (يُنشئ السجل عند الحاجة).
     * نقطة مركزية واحدة لكل حركات المخزون حتى يبقى مجموع الفروع = كمية المنتج.
     *
     * الفرق يُطبَّق كما هو ولو أنزل الرصيد تحت الصفر. كان هنا max(0, …)
     * يقصّ الناتج بصمت، فينكسر التوازن «مجموع الفروع = كمية المنتج» ولا
     * يظهر ذلك في أي تقرير: رصيد فرعٍ يقف عند صفر بينما خُصم منه فعليًا.
     * رصيدٌ سالب إشارةُ خللٍ يجب أن تُرى، لا أن تُخبَّأ.
     */
    /**
     * يضمن أن للمنتج توزيعًا على الفروع قبل أوّل خصم منه.
     *
     * منتجٌ بلا أي صفّ هنا يعني «لم يُوزَّع بعد»، وكميته كلها في
     * products.quantity. لو خُصم منه مباشرةً لأُنشئ صفٌّ يبدأ من صفر فيصير
     * سالبًا — رأينا −1 بعد أوّل بيعة — وينكسر التوازن. فيُنقل رصيده كاملًا
     * إلى الفرع العامل أولًا، ثم يُخصم منه.
     *
     * والرصيد القديم يُنسب إلى **الفرع الرئيسي** لا إلى الفرع الذي تجري عليه
     * العملية: بضاعةٌ لم تُوزَّع يومًا موجودة في المستودع الأصلي، لا في الفرع
     * الذي صادف أنه يستلم شحنة اليوم. نسبتُها للفرع المستلِم كانت تُظهر ستّ
     * عشرة قطعة في صلالة وإنما وصلها ستّ.
     *
     * @param  int  $quantity  الكمية الإجمالية قبل التغيير
     */
    public static function ensureAllocated(int $businessId, int $productId, int $quantity): void
    {
        if (static::where('product_id', $productId)->exists()) {
            return;
        }

        $mainBranch = Branch::where('business_id', $businessId)->orderBy('id')->value('id');

        if (! $mainBranch) {
            return; // نشاط بلا فروع — الكمية الإجمالية هي المتاحة أصلًا
        }

        static::create([
            'business_id' => $businessId,
            'branch_id' => $mainBranch,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);
    }

    public static function adjust(int $businessId, ?int $branchId, int $productId, int $delta): void
    {
        if (! $branchId || $delta === 0) {
            return;
        }
        $row = static::firstOrNew([
            'branch_id' => $branchId,
            'product_id' => $productId,
        ]);
        $row->business_id = $businessId;
        $row->quantity = (int) $row->quantity + $delta;
        $row->save();
    }
}
