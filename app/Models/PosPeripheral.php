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
     * الطابعة تُقاد بحوار الطباعة — ومباشرةً على الشبكة من تطبيق أبعاد (انظر
     * `mobile/PROTOCOL.md`) — والماسح يكتب كلوحة مفاتيح فيُلتقط في حقل
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
            'auto_print_website_confirm' => 'boolean',
            // طابعةُ إيصال البيع على صندوقها — واحدةٌ لكلّ صندوق (فهرسٌ فريدٌ جزئيّ)
            'is_receipt_printer' => 'boolean',
            // ويستلم «فاتورة عميل — نسخة داخلية» حين تُسدَّد — انظر `PrintJobs`
            'auto_print_customer_invoices' => 'boolean',
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

    /**
     * طابعةُ شبكةٍ لها عنوان — ما يطبع عليه تطبيقُ أبعاد مباشرةً.
     *
     * و`getAttribute` لا `$this->connection`: داخل النموذج يقرأ ذاك خاصيّةَ
     * Eloquent المحميّة (اسمَ اتّصال القاعدة) لا العمود — فيكون `null` أبدًا.
     */
    public function printsDirectly(): bool
    {
        return $this->getAttribute('connection') === 'network' && trim((string) $this->address) !== '';
    }

    /**
     * طابعةُ إيصال البيع بين ملحقات صندوقٍ واحد — بقاعدةٍ واحدة يقرؤها الكلّ.
     *
     * ═══ ولمَ لزمت ═══
     *
     * كانت «أوّلَ طابعةٍ» في قائمةٍ بلا ترتيب: الشاشةُ تأخذ أوّلَ ما وصلها،
     * والخادمُ يأخذ عرضَ الورق من أوّل ما يقرؤه — وPostgreSQL لا يَعِد بترتيبٍ
     * بلا `ORDER BY`. فطابعةٌ أُضيفت مرّتين يطبع الصندوقُ على أيّهما اتّفق.
     *
     * ═══ والقاعدة ═══
     *
     * 1. ما سمّاه التاجرُ «طابعة الإيصال» (`is_receipt_printer`) — إن كانت مفعّلة.
     * 2. وإلّا الطابعةُ المفعّلةُ الوحيدة.
     * 3. وإلّا — طابعتان أو أكثر ولم يُسمِّ — **الأقدم**: أقربُ ما يكون إلى «أوّل
     *    صفّ» الذي كان يطبع عليه، فلا تتبدّل طابعتُه بصمت. والالتباسُ يُعرض في
     *    الإعدادات ليُختار (`TillHealth::AMBIGUOUS_RECEIPT`).
     *
     * و«الطباعة التلقائية بعد البيع» لا تختار الطابعة: تقول هل تطبع طابعةُ
     * الإيصال وحدها بعد البيع. وتُحسب القاعدةُ هنا وحدها: الشاشةُ تقرأ العلامة
     * `receipt` (`HandleInertiaRequests`)، وعرضُ الورق منها (`PdfController`).
     *
     * @param  iterable<self>  $peripherals
     */
    public static function receiptAmong(iterable $peripherals): ?self
    {
        $printers = collect($peripherals)
            ->filter(fn (self $p) => $p->active && $p->type === self::PRINTER)
            ->sortBy('id')
            ->values();

        return $printers->first(fn (self $p) => $p->is_receipt_printer) ?? $printers->first();
    }
}
