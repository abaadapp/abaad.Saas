<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعارٌ مدين — يزيد الذمّة ولا يُعيد كتابة الفاتورة.
 *
 * فاتورةٌ بخمسين سُدّدت كلُّها ثمّ تبيّن أنّ ثمنها خمسةٌ وخمسون: الفاتورةُ
 * تبقى بخمسين كما صدرت ودُفعت، ويصدر إشعارٌ مدينٌ بخمسةٍ برقمه وتاريخه يشير
 * إليها (م٥٠ و١٥٥ من اللائحة). والخمسةُ باقيةٌ على العميل.
 *
 * ولا يُعدَّل ولا يُحذف من النظام: مستندٌ ضريبيٌّ صدر — وتصحيحُه مستندٌ آخر.
 */
class CustomerDebitNote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'date',
        'items' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('الإشعار المدين لا يُعدَّل بعد صدوره — التصحيحُ مستندٌ جديد.');
        });

        static::deleting(function () {
            throw new \LogicException('الإشعار المدين لا يُحذف.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceRevision::class, 'revision_id');
    }
}
