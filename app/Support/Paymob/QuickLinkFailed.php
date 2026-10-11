<?php

namespace App\Support\Paymob;

use RuntimeException;

/** فشلُ طلبٍ إلى Paymob — ورسالتُه تُقرأ للتاجر ولا سرَّ فيها */
final class QuickLinkFailed extends RuntimeException {}
