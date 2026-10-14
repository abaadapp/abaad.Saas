<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * آخرُ طلبِ موقعٍ قرأه هذا الموظّف من هذا العميل — صفٌّ لكلّ قارئ.
 *
 * مؤشّرٌ في `crm_notes` لا في `crm_messages`: قراءةُ واتساب في `CrmRead`،
 * والحالتان لا تلتقيان. انظر `CrmWebsiteInquiries::markRead`.
 */
class CrmWebsiteInquiryRead extends Model
{
    protected $table = 'crm_website_inquiry_reads';

    protected $guarded = [];
}
