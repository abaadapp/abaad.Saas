<?php

namespace App\Events\Website;

use App\Models\Website;
use App\Models\WebsiteVersion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * نسخةٌ قديمة كُتبت فوق المسوّدة — ولم تُنشر.
 *
 * والفرق بينه وبين `WebsitePublished` هو الفرق كلُّه: هذا يمسّ ما يراه
 * التاجر، وذاك يمسّ ما يراه زبونُه. فمن يبطل ذاكرةَ الموقع المنشور لا
 * يستمع إلى هذا.
 */
class WebsiteRestored
{
    use Dispatchable;

    public function __construct(
        public Website $website,
        public WebsiteVersion $version,
    ) {}
}
