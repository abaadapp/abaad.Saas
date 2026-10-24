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
     * طابعةُ إيصال البيع بين ملحقات صندوقٍ واحد — قاعدةٌ واحدة لا «أوّلُ صفّ».
     *
     * ═══ ولمَ لزمت ═══
     *
     * كانت الطابعةُ «أوّلَ طابعةٍ» في قائمةٍ لا ترتيبَ لها: الشاشةُ تأخذ أوّلَ
     * ما وصلها، والخادمُ يأخذ عرضَ الورق من أوّل ما يقرؤه — وPostgreSQL لا يَعِد
     * بترتيبٍ بلا `ORDER BY`. فموظفٌ أضاف الطابعةَ مرّتين (مرّةً «USB» ثمّ
     * «شبكة») يطبع على الأولى: حوارُ نظامٍ لا يجد طابعةً بدل الطباعة المباشرة،
     * و«طباعة تلقائية بعد البيع» المُشعَلةُ على الثانية لا تقع أبدًا. فيحذف
     * ويضيف، والقائمةُ تقرّر عنه.
     *
     * والترتيبُ الآن: النشطةُ وحدها، ثمّ ما عليه «طباعة تلقائية بعد البيع»
     * (هو ما سمّاه التاجرُ طابعةَ الإيصال)، ثمّ ما يطبع عليه التطبيقُ مباشرةً،
     * ثمّ الأقدم. وتُحسب هنا وحدها: الشاشةُ تقرأ العلامة `receipt` ولا تُعيد
     * الحساب (`HandleInertiaRequests`)، وعرضُ الورق منها (`PdfController`).
     *
     * @param  iterable<self>  $peripherals
     */
    public static function receiptAmong(iterable $peripherals): ?self
    {
        return collect($peripherals)
            ->filter(fn (self $p) => $p->active && $p->type === self::PRINTER)
            ->sort(fn (self $a, self $b) => [! $a->auto_print, ! $a->printsDirectly(), $a->id]
                <=> [! $b->auto_print, ! $b->printsDirectly(), $b->id])
            ->first();
    }
}
