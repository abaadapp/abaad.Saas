<?php

namespace App\Events\Website;

use App\Models\WebsiteDomain;
use Illuminate\Foundation\Events\Dispatchable;

/** عنوانٌ صار يفتح الموقع فعلًا — تحقّقَ توجيهُه وجهزت شهادتُه */
class DomainActivated
{
    use Dispatchable;

    public function __construct(public WebsiteDomain $domain) {}
}
