<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = [];

    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }

    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class); }

    /**
     * ما له علينا الآن — من سنداته **المعتمَدة** وحدها.
     *
     * والمرفوضُ والملغى والمعلَّق ليست دَينًا: التعريفُ واحدٌ في
     * `SupplierInvoice::scopeOwed`، ولا يُعاد كتابتُه هنا فيفترق عنه.
     */
    public function outstanding(): float
    {
        return round((float) $this->invoices()->owed()->sum('total')
            - (float) $this->invoices()->owed()->sum('paid'), 3);
    }
}
