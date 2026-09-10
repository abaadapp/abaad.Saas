<?php

namespace App\Events\Website;

use App\Models\WebsiteDomain;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * محاولةُ ربطٍ لم تنجح — ومعها سببُها.
 *
 * والسبب يُحمل في الحدث لا يُقرأ من الصفّ بعده: من يشعر التاجر يريد أن
 * يقول له ما الذي لم يصحّ، لا «حدث خطأ».
 */
class DomainFailed
{
    use Dispatchable;

    public function __construct(
        public WebsiteDomain $domain,
        public string $reason,
    ) {}
}
