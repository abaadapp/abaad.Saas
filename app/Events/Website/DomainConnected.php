<?php

namespace App\Events\Website;

use App\Models\WebsiteDomain;
use Illuminate\Foundation\Events\Dispatchable;

/** عنوانٌ رُبط بموقع — وما زال ينتظر أن يثبت أنّه يشير إلينا */
class DomainConnected
{
    use Dispatchable;

    public function __construct(public WebsiteDomain $domain) {}
}
