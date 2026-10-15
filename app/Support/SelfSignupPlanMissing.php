<?php

namespace App\Support;

use RuntimeException;

/**
 * لا باقةَ أساسيّةً مؤكَّدة — فلا يُفتح متجرٌ بالتسجيل الذاتيّ.
 *
 * تُرمى من `Signup::plan` قبل أن يُكتب شيء، ويقرؤها `RegisterController`
 * فيردّ المسجِّلَ برسالةٍ تقول إنّ الباب متوقّف — لا متجرَ على باقةٍ خاطئة.
 */
final class SelfSignupPlanMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No plan carries the self-signup code «'.\App\Models\Plan::CODE_BASIC.'».');
    }
}
