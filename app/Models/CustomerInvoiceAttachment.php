<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مرفقُ فاتورةِ عميل — أمرُ شراءٍ أو عقدٌ أو طلبٌ موقَّع.
 *
 * وهو على القرص الخاصّ ويُقرأ ببابٍ يسأل عن متجره وعن صلاحية القارئ —
 * كأخواته في `FinancialAttachmentController`. أمرُ شراء وزارةٍ ليس مستندًا
 * يُفتح برابطٍ يُخمَّن.
 */
class CustomerInvoiceAttachment extends Model
{
    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
