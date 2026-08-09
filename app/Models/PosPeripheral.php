<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جهاز ملحق بصندوق بيع: طابعة، ماسح، درج، شاشة عميل، ميزان.
 *
 * وليست كلّها سواءً في ما يستطيعه المتصفّح، وهذا مكتوبٌ في DRIVABLE أدناه لا
 * متروكٌ للظنّ: شاشةٌ تعرض زرًّا لا يفعل شيئًا أسوأ من شاشة لا تعرضه.
 */
class PosPeripheral extends Model
{
    protected $guarded = [];

    public const PRINTER = 'طابعة';

    public const SCANNER = 'ماسح باركود';

    public const DRAWER = 'درج نقدي';

    public const DISPLAY = 'شاشة عميل';

    public const SCALE = 'ميزان';

    /** الأنواع المسموحة — قائمة مغلقة يُتحقَّق منها في الطلب */
    public const TYPES = [self::PRINTER, self::SCANNER, self::DRAWER, self::DISPLAY, self::SCALE];

    public const CONNECTIONS = ['usb', 'network', 'bluetooth'];

    /**
     * ما تقوده نقطة البيع من المتصفّح فعلًا.
     *
     * الطابعة تُقاد بحوار الطباعة، والماسح يكتب كلوحة مفاتيح فيُلتقط في حقل
     * الباركود. أمّا الدرج والشاشة والميزان فلا يبلغها متصفّحٌ بلا وسيطٍ على
     * الجهاز — تُسجَّل هنا للجرد وللدعم الفنّي، ولا تُوعَد بما لا يقع.
     */
    public const DRIVABLE = [self::PRINTER, self::SCANNER];

    /** عرض الورق الحراري الشائع بالمليمتر */
    public const PAPER_WIDTHS = [58, 80];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'auto_print' => 'boolean',
            'paper_width' => 'integer',
            'port' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isDrivable(): bool
    {
        return in_array($this->type, self::DRIVABLE, true);
    }
}
