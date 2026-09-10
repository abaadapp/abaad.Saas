<?php

namespace App\Events\Website;

use App\Models\Website;
use App\Models\WebsiteVersion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * موقعٌ صار له وجهٌ جديد عند زوّاره.
 *
 * ولا مستمعَ له اليوم — وهو مقصود. الحدث يُعلَن من الموضع الوحيد الذي
 * يعرف أنّ النشر وقع (`Publisher::publish`)، فمن يحتاجه غدًا — إبطالُ
 * ذاكرةٍ وسيطة، أو إشعارٌ، أو سجلُّ تدقيق — يستمع إليه ولا يُقحم سطرَه في
 * المتحكّم. وإبطالُ الذاكرة الموزّعُ في المتحكّمات هو بالضبط ما يُنسى في
 * المسار الثالث فيبقى الزائر يرى موقعَ الأمس.
 */
class WebsitePublished
{
    use Dispatchable;

    public function __construct(
        public Website $website,
        public WebsiteVersion $version,
    ) {}
}
